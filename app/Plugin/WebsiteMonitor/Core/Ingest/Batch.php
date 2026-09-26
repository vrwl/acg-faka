<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Ingest;

use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\Spool\Reader;

/**
 * 入库编排：spool 文件 → 各张表。**这是全插件唯一写统计表的地方。**
 *
 * 幂等性靠 wm_ingest 的主键：
 *   - 崩在 commit 之前 → 全部回滚（含标记行），文件还在，下轮重来，不会重复；
 *   - 崩在 commit 之后、删文件之前 → 下轮标记行冲突，直接丢文件，也不会重复；
 *   - 多进程并发 → seal 用 rename 摘取，一个文件只可能被一个进程拿到。
 */
final class Batch
{
    private int $lines = 0;
    private int $files = 0;
    private float $started;

    public function __construct()
    {
        $this->started = microtime(true);
    }

    /**
     * 消费队列，直到超时或没东西可吃。
     *
     * @param float $deadline 绝对时间戳，超过就收工
     * @param int $maxLines 本轮最多处理多少行
     * @param callable|null $heartbeat 每处理完一个文件调一次（守护进程防挂死）
     * @return array{files:int,lines:int,ms:int}
     */
    public function drain(float $deadline, int $maxLines = 100000, ?callable $heartbeat = null): array
    {
        try {
            $files = Reader::seal();
        } catch (\Throwable $e) {
            Log::exception('Batch::seal', $e);
            $files = [];
        }

        foreach ($files as $file) {
            if (microtime(true) > $deadline || $this->lines >= $maxLines) {
                break;
            }
            $this->ingestFile($file);
            $this->files++;
            if ($heartbeat !== null) {
                try {
                    $heartbeat();
                } catch (\Throwable $e) {
                }
            }
        }

        return [
            'files' => $this->files,
            'lines' => $this->lines,
            'ms' => (int)round((microtime(true) - $this->started) * 1000),
        ];
    }

    /**
     * 每秒处理了多少行（自适应降级用）
     */
    public function rate(): int
    {
        $elapsed = max(0.001, microtime(true) - $this->started);
        return (int)round($this->lines / $elapsed);
    }

    public function lines(): int
    {
        return $this->lines;
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    private function ingestFile(string $file): void
    {
        $name = basename($file);
        try {
            $rows = Reader::read($file);
        } catch (\Throwable $e) {
            Reader::requeue($file, 'read: ' . $e->getMessage());
            return;
        }
        if ($rows === []) {
            Reader::discard($file);
            Reader::clearFailMarker($file);
            return;
        }

        $conn = Db::conn();
        $conn->beginTransaction();
        try {
            // ── 幂等闸门：这个 seal 文件全局只能入库一次
            $claimed = Db::insertIgnore(Db::INGEST, [[
                'f' => mb_substr($name, 0, 96),
                'ts' => time(),
                'lines' => count($rows),
            ]]);
            if ($claimed === 0) {
                //已经入过库了（上次 commit 成功但没来得及删文件），直接丢掉
                $conn->rollBack();
                Reader::discard($file);
                Reader::clearFailMarker($file);
                Log::debug('spool 文件已入过库，跳过', ['file' => $name]);
                return;
            }

            $folded = Folder::fold($rows, Settings::bool('count_spider_in_pv'));
            $ids = Dictionary::resolve($folded);

            //地理信息回填（地理子系统就绪时才做）
            self::geo($folded, $ids);

            RawWriter::insert((array)$folded['access'], $ids);
            Counters::bump($folded, $ids);
            Sessions::merge($folded, $ids);
            Sessions::ipProfiles((array)$folded['ips'], $ids);
            Attacks::insert((array)$folded['attacks'], $ids);

            $conn->commit();
        } catch (\Throwable $e) {
            try {
                $conn->rollBack();
            } catch (\Throwable $ignored) {
            }
            Log::exception('Batch::ingestFile', $e, ['file' => $name]);
            Reader::requeue($file, $e->getMessage());
            return;
        }

        $this->lines += count($rows);
        Reader::discard($file);
        Reader::clearFailMarker($file);
    }

    /**
     * 给会话 / 在线 / 攻击补上归属地。地理库不可用时整段跳过（fail-open）。
     *
     * @param array<string,mixed> $folded
     * @param array<string,array<string,int>> $ids
     */
    private static function geo(array &$folded, array &$ids): void
    {
        if (!class_exists('\App\Plugin\WebsiteMonitor\Core\Geo\Locator')) {
            return;
        }
        try {
            if (!\App\Plugin\WebsiteMonitor\Core\Geo\Locator::available()) {
                return;
            }
            //本批出现过的 IP 去重后一次性查（三级缓存命中率极高）
            $ips = array_keys((array)$folded['ips']);
            if ($ips === []) {
                return;
            }
            $geo = \App\Plugin\WebsiteMonitor\Core\Geo\Locator::lookupMany($ips);

            $regions = [];
            foreach ($geo as $item) {
                $regions[] = self::regionOf($item);
            }
            $ids['regions'] = Dictionary::regions($regions);
            $ids['ip_region'] = [];
            foreach ($geo as $ip => $item) {
                $r = self::regionOf($item);
                $key = $r['country'] . '|' . $r['province'] . '|' . $r['city'];
                $ids['ip_region'][(string)$ip] = (int)($ids['regions'][$key] ?? 0);
            }
        } catch (\Throwable $e) {
            Log::exception('Batch::geo', $e);
        }
    }

    /**
     * 把一次归属查询折成地区字典的一行。
     *
     * 查不到的 IP 不能直接丢：丢了地域页的合计就跟 PV 对不上，站长会以为漏了数据。
     * 内网与未知各给一个占位地区，用 ISO 3166-1 的用户自定义段（XA–XZ），不会跟真国家码撞。
     *
     * @param array<string,mixed> $item
     * @return array{country:string,country_name:string,province:string,city:string,continent:string}
     */
    private static function regionOf(array $item): array
    {
        if (($item['ok'] ?? false) === true) {
            return [
                'country' => (string)($item['country'] ?? ''),
                'country_name' => (string)($item['country_name'] ?? ''),
                'province' => (string)($item['province'] ?? ''),
                'city' => (string)($item['city'] ?? ''),
                'continent' => (string)($item['continent'] ?? ''),
            ];
        }
        $lan = ($item['is_lan'] ?? false) === true;
        return [
            'country' => $lan ? 'XL' : 'XU',
            'country_name' => $lan ? lang('内网') : lang('未知'),
            'province' => '',
            'city' => '',
            'continent' => '',
        ];
    }
}
