<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Geo;

use App\Plugin\WebsiteMonitor\Core\Ca;
use App\Plugin\WebsiteMonitor\Core\Kv;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Settings;
use GuzzleHttp\Client;

/**
 * IP 库下载与原子替换。
 *
 * 六个环节缺一不可：
 *   1. 条件请求（If-None-Match / If-Modified-Since）—— 没变化时 304 直接结束，零流量
 *   2. 流式落盘到 .tmp —— 62 MB 绝不能进内存
 *   3. 四重校验：大小、尾部 marker、元数据解析、冒烟查询
 *   4. 先备份再 rename —— 同分区 rename 是原子的，读者永远看不到半个文件
 *   5. 清空归属缓存 —— 新库的归属可能变了
 *   6. 失败退避 + 通知，但**绝不动现网的库**
 */
final class Downloader
{
    private const TMP_SUFFIX = '.tmp';
    private const BAK_SUFFIX = '.bak';

    /** 退避梯度（秒） */
    private const BACKOFF = [30, 300, 1800];
    private const MAX_FAILS = 4;

    /** 小于这个大小的一定不是完整的库（多半是错误页） */
    private const MIN_BYTES = 1048576;

    /**
     * @param callable|null $heartbeat 长下载期间定期调用，防止守护进程判定任务挂死
     * @return array{ok:bool,changed:bool,msg:string,size:int,ms:int}
     */
    public static function update(?callable $heartbeat = null, bool $force = false): array
    {
        $started = microtime(true);
        $result = static fn(bool $ok, bool $changed, string $msg, int $size = 0): array => [
            'ok' => $ok, 'changed' => $changed, 'msg' => $msg, 'size' => $size,
            'ms' => (int)round((microtime(true) - $started) * 1000),
        ];

        $url = Settings::geoUrl();
        if (!preg_match('#^https://#i', $url)) {
            return $result(false, false, lang('IP 库地址必须是 https 开头的完整下载地址'));
        }

        Runtime::ensureDirs();
        $target = Locator::dbPath();
        $tmp = $target . self::TMP_SUFFIX;
        $meta = (array)Kv::get('geo_meta', []);

        //退避：连续失败之后不要每轮都重试，免得把对方站点打爆
        if (!$force) {
            $fails = (int)($meta['fails'] ?? 0);
            $lastTry = (int)($meta['last_try'] ?? 0);
            if ($fails > 0 && $lastTry > 0) {
                $wait = self::BACKOFF[min($fails - 1, count(self::BACKOFF) - 1)];
                if (time() - $lastTry < $wait) {
                    return $result(false, false, Lang::t('上次下载失败，:s 秒后重试', ['s' => (string)($wait - (time() - $lastTry))]));
                }
            }
        }

        $meta['last_try'] = time();
        Kv::set('geo_meta', $meta);
        self::progress(0, 0, 'connecting');

        try {
            $headers = ['User-Agent' => 'WebsiteMonitor/1.0 (+acg-faka)'];
            //现网已有库时才带条件头：没有库就必须完整拉一份
            if (is_file($target)) {
                if (!empty($meta['etag'])) {
                    $headers['If-None-Match'] = (string)$meta['etag'];
                }
                if (!empty($meta['last_modified'])) {
                    $headers['If-Modified-Since'] = (string)$meta['last_modified'];
                }
            }

            @unlink($tmp);
            $downloaded = 0;
            $total = 0;

            //证书校验必须显式给路径：守护进程跑的 swoole-cli 没有 curl.cainfo，
            //会回落到编译时写死的 Debian 路径，在 CentOS 系服务器上直接报 cURL error 77。
            //绝不用 verify=false —— 这个文件会被写进服务器并用于封禁判断。
            $client = new Client([
                'timeout' => 900,
                'connect_timeout' => 15,
                'http_errors' => false,
                'verify' => Ca::verifyOption(),
            ]);
            $response = $client->request('GET', $url, [
                'headers' => $headers,
                'sink' => $tmp,
                'progress' => static function ($dlTotal, $dlNow) use (&$downloaded, &$total, $heartbeat): void {
                    $total = (int)$dlTotal;
                    //每 8 MB 汇报一次：太频繁会让心跳与进度写变成新的开销
                    if ((int)$dlNow - $downloaded >= 8388608) {
                        $downloaded = (int)$dlNow;
                        self::progress($downloaded, $total, 'downloading');
                        if ($heartbeat !== null) {
                            try {
                                $heartbeat();
                            } catch (\Throwable $e) {
                            }
                        }
                    }
                },
            ]);

            $status = $response->getStatusCode();

            if ($status === 304) {
                @unlink($tmp);
                $meta['fails'] = 0;
                $meta['last_ok'] = time();
                $meta['last_msg'] = lang('已是最新版本');
                Kv::set('geo_meta', $meta);
                self::progress(0, 0, 'idle');
                return $result(true, false, lang('已是最新版本，无需更新'));
            }

            if ($status !== 200) {
                @unlink($tmp);
                return self::fail($meta, Lang::t('下载失败，HTTP :s', ['s' => (string)$status]), $result);
            }

            $size = (int)@filesize($tmp);
            if ($size < self::MIN_BYTES) {
                @unlink($tmp);
                return self::fail($meta, Lang::t('下载到的文件只有 :n 字节，多半是错误页而不是 IP 库', ['n' => (string)$size]), $result);
            }

            //Content-Length 对得上才算完整（有些 CDN 不给这个头，给了就必须一致）
            $declared = (int)($response->getHeaderLine('Content-Length') ?: 0);
            if ($declared > 0 && $declared !== $size) {
                @unlink($tmp);
                return self::fail($meta, Lang::t('文件不完整：应为 :a 字节，实得 :b 字节', ['a' => (string)$declared, 'b' => (string)$size]), $result);
            }

            self::progress($size, $size, 'verifying');
            if ($heartbeat !== null) {
                try {
                    $heartbeat();
                } catch (\Throwable $e) {
                }
            }

            //元数据 + 冒烟查询：能挡住「下了半个文件」和「格式不对」两类问题
            $smoke = Locator::smokeTest($tmp);
            if (!$smoke['ok']) {
                @unlink($tmp);
                return self::fail($meta, Lang::t('新库校验未通过：:m', ['m' => $smoke['msg']]), $result);
            }

            //备份现网库，再原子替换。同分区 rename 期间读者不会看到中间态。
            if (is_file($target)) {
                @unlink($target . self::BAK_SUFFIX);
                @copy($target, $target . self::BAK_SUFFIX);
            }
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                return self::fail($meta, lang('替换 IP 库失败，请检查 runtime 目录的写权限'), $result);
            }

            //换库后必须重开读取器并清掉全部归属缓存
            Locator::reload();

            $meta = [
                'etag' => $response->getHeaderLine('ETag'),
                'last_modified' => $response->getHeaderLine('Last-Modified'),
                'size' => $size,
                'fails' => 0,
                'last_ok' => time(),
                'last_try' => time(),
                'last_msg' => lang('更新成功'),
                'samples' => $smoke['samples'],
            ];
            $info = Locator::info();
            if (is_array($info['meta'] ?? null)) {
                $meta['build_date'] = (string)($info['meta']['build_date'] ?? '');
                $meta['database_type'] = (string)($info['meta']['database_type'] ?? '');
            }
            Kv::set('geo_meta', $meta);
            self::progress(0, 0, 'idle');

            Log::info('IP 库已更新', ['size' => $size, 'build' => $meta['build_date'] ?? '']);
            return $result(true, true, Lang::t('更新成功，新库 :n MB', ['n' => (string)round($size / 1048576, 1)]), $size);
        } catch (\Throwable $e) {
            @unlink($tmp);
            $msg = $e->getMessage();
            //证书类错误光看 cURL 的原文没人看得懂，补一句能直接照做的
            if (stripos($msg, 'certificate') !== false || stripos($msg, 'cURL error 77') !== false
                || stripos($msg, 'cURL error 60') !== false) {
                $hint = Ca::hint();
                if ($hint !== '') {
                    $msg .= ' —— ' . $hint;
                }
            }
            return self::fail($meta, $msg, $result);
        }
    }

    /**
     * 手动触发（面板按钮）。有守护进程就交给它，没有就当场同步下。
     */
    public static function requestManual(): void
    {
        Kv::set('geo_force', 1, 3600);
    }

    public static function manualRequested(): bool
    {
        if (Kv::int('geo_force', 0) !== 1) {
            return false;
        }
        Kv::delete('geo_force');
        return true;
    }

    /**
     * @return array{stage:string,downloaded:int,total:int,percent:int,at:int}
     */
    public static function progressState(): array
    {
        $raw = (array)Kv::get('geo_progress', []);
        $total = (int)($raw['total'] ?? 0);
        $downloaded = (int)($raw['downloaded'] ?? 0);
        return [
            'stage' => (string)($raw['stage'] ?? 'idle'),
            'downloaded' => $downloaded,
            'total' => $total,
            'percent' => $total > 0 ? (int)min(100, round($downloaded / $total * 100)) : 0,
            'at' => (int)($raw['at'] ?? 0),
        ];
    }

    /**
     * 回滚到备份（换库后发现新库有问题时的最后手段）
     */
    public static function rollback(): bool
    {
        $target = Locator::dbPath();
        $backup = $target . self::BAK_SUFFIX;
        if (!is_file($backup)) {
            return false;
        }
        if (!@rename($backup, $target)) {
            return false;
        }
        Locator::reload();
        Log::warn('IP 库已回滚到上一个版本');
        return true;
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    private static function progress(int $downloaded, int $total, string $stage): void
    {
        Kv::set('geo_progress', [
            'stage' => $stage,
            'downloaded' => $downloaded,
            'total' => $total,
            'at' => time(),
        ], 3600);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function fail(array $meta, string $msg, callable $result): array
    {
        //所有失败路径都汇到这里，在这一处抹掉内置源就够了
        $msg = Settings::maskGeoUrl($msg);
        $meta['fails'] = (int)($meta['fails'] ?? 0) + 1;
        $meta['last_try'] = time();
        $meta['last_msg'] = $msg;
        Kv::set('geo_meta', $meta);
        self::progress(0, 0, 'failed');

        Log::warn('IP 库更新失败', ['msg' => $msg, 'fails' => $meta['fails']]);

        //连续失败到阈值才惊动站长，避免网络抖动就发一封
        if ((int)$meta['fails'] >= self::MAX_FAILS && Settings::bool('notify_geo_fail')) {
            self::notifyFailure($msg, (int)$meta['fails']);
        }
        return $result(false, false, $msg);
    }

    private static function notifyFailure(string $msg, int $fails): void
    {
        if (!class_exists('\App\Plugin\WebsiteMonitor\Module\Notify\Alert')) {
            return;
        }
        try {
            \App\Plugin\WebsiteMonitor\Module\Notify\Alert::raise(
                'wm.geo_update_fail',
                Settings::LEVEL_WARN,
                lang('IP 地理库更新连续失败'),
                Lang::t('已连续失败 :n 次。地区统计与地区封锁在库过期后会逐渐失准，请检查服务器出网或更换下载源。', ['n' => (string)$fails]),
                [
                    'scope_text' => lang('IP 地理库'),
                    'count' => $fails,
                    'threshold' => self::MAX_FAILS,
                    'evidence' => [['note' => $msg]],
                    'actions' => [
                        lang('到「网站监控 → 设置 → 地理位置」点「立即更新」手动重试'),
                        lang('检查服务器能否访问下载地址'),
                    ],
                    'dedupe' => 'wm:geo:fail:' . date('oW'),
                ]
            );
        } catch (\Throwable $e) {
            Log::exception('Downloader::notifyFailure', $e);
        }
    }
}
