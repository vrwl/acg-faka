<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Geo;

use App\Plugin\WebsiteMonitor\Core\Ip;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Lib\MaxMind\InvalidDatabaseException;
use App\Plugin\WebsiteMonitor\Lib\MaxMind\Reader;

/**
 * 归属地查询门面。
 *
 * 三级缓存：进程内 → 持久缓存（Redis / 文件，按命中网络折叠）→ 实查 MMDB。
 *
 * **最大的优化是根本不查**：只有在「地区规则开着」或「本次请求已判定为攻击」时
 * 才会调到这里。没配地区规则的站点，正常流量永远不碰那个 62 MB 的文件。
 */
final class Locator
{
    public const DB_NAME = 'GeoLite2-City.mmdb';

    /** 只解需要的键：City 记录带八种语言的地名，全解慢十倍 */
    private const WANT = [
        'country' => ['iso_code' => true, 'names' => ['zh-CN' => true, 'en' => true]],
        'registered_country' => ['iso_code' => true, 'names' => ['zh-CN' => true, 'en' => true]],
        'continent' => ['code' => true],
        'subdivisions' => ['iso_code' => true, 'names' => ['zh-CN' => true, 'en' => true]],
        'city' => ['names' => ['zh-CN' => true, 'en' => true]],
        'location' => ['time_zone' => true],
    ];

    private static ?Reader $reader = null;
    private static bool $tried = false;
    private static ?string $error = null;

    public static function dbPath(): string
    {
        return Runtime::geoDir() . '/' . self::DB_NAME;
    }

    public static function available(): bool
    {
        return self::reader() !== null;
    }

    public static function error(): ?string
    {
        self::reader();
        return self::$error;
    }

    /**
     * 查询单个 IP。**永不抛异常** —— 查不到就返回 ok=false，调用方一律 fail-open。
     *
     * @return array{ok:bool,ip:string,country:?string,country_name:?string,province:?string,
     *               province_iso:?string,city:?string,continent:?string,tz:?string,
     *               is_lan:bool,reason:?string}
     */
    public static function lookup(string $ip): array
    {
        $miss = static fn(string $reason, bool $lan = false): array => [
            'ok' => false, 'ip' => $ip, 'country' => null, 'country_name' => null,
            'province' => null, 'province_iso' => null, 'city' => null,
            'continent' => null, 'tz' => null, 'is_lan' => $lan, 'reason' => $reason,
        ];

        $normalized = Ip::normalize($ip);
        if ($normalized === null) {
            return $miss('invalid_ip');
        }
        if (Ip::isPrivate($normalized)) {
            return $miss('lan', true);
        }

        $reader = self::reader();
        if ($reader === null) {
            return $miss('db_missing');
        }

        $packed = Ip::pack($normalized);
        if ($packed === null) {
            return $miss('invalid_ip');
        }

        //先用一个粗粒度的键探一次缓存（大部分网络都比 /24 粗或正好是 /24）
        $probe = bin2hex(Ip::maskPacked($packed, 120));
        $cached = Cache::get($probe);
        if ($cached !== null) {
            $cached['ip'] = $normalized;
            $cached['is_lan'] = false;
            $cached['reason'] = null;
            return $cached;
        }

        try {
            $found = $reader->get($normalized, self::WANT);
        } catch (InvalidDatabaseException $e) {
            //库坏了：关掉读取器，别让后续每个请求都撞同一个异常
            self::$reader = null;
            self::$error = $e->getMessage();
            Log::exception('Locator::lookup', $e, ['ip' => $normalized]);
            return $miss('db_broken');
        } catch (\Throwable $e) {
            Log::exception('Locator::lookup', $e, ['ip' => $normalized]);
            return $miss('error');
        }

        if ($found === null) {
            //库里没有这个网段，也缓存下来，免得反复查
            Cache::put($probe, self::blank());
            return $miss('not_found');
        }

        $geo = self::shape($found['data']);

        //按实际命中的网络缓存：一条 /16 的记录能覆盖六万多个 IP
        $prefix = max(8, min(128, (int)$found['prefix']));
        $network = bin2hex(Ip::maskPacked($packed, $prefix));
        Cache::put($network, $geo);
        //探针键也写一份，下次同一个 /24 直接命中
        if ($network !== $probe) {
            Cache::put($probe, $geo);
        }

        $geo['ip'] = $normalized;
        $geo['is_lan'] = false;
        $geo['reason'] = null;
        return $geo;
    }

    /**
     * 批量查询。走同一份缓存，比循环调 lookup 省掉重复的规范化开销。
     *
     * @param string[] $ips
     * @return array<string,array<string,mixed>>
     */
    public static function lookupMany(array $ips): array
    {
        $out = [];
        foreach (array_unique($ips) as $ip) {
            $out[(string)$ip] = self::lookup((string)$ip);
        }
        return $out;
    }

    /**
     * 人类可读的一行归属地，如「中国 · 浙江 · 杭州」
     *
     * @param array<string,mixed> $geo
     */
    public static function text(array $geo): string
    {
        if (($geo['ok'] ?? false) !== true) {
            return ($geo['is_lan'] ?? false) ? lang('内网') : lang('未知');
        }
        $parts = array_values(array_filter([
            (string)($geo['country_name'] ?? $geo['country'] ?? ''),
            (string)($geo['province'] ?? ''),
            (string)($geo['city'] ?? ''),
        ], static fn(string $s): bool => trim($s) !== ''));
        return $parts === [] ? lang('未知') : implode(' · ', $parts);
    }

    /**
     * IP 库信息（面板展示）
     *
     * @return array<string,mixed>
     */
    public static function info(): array
    {
        $path = self::dbPath();
        $exists = is_file($path);
        $out = [
            'ready' => false,
            'exists' => $exists,
            'path' => $path,
            'size' => $exists ? (int)@filesize($path) : 0,
            'mtime' => $exists ? (int)@filemtime($path) : 0,
            'error' => null,
            'meta' => null,
            'cache' => Cache::stats(),
        ];
        $reader = self::reader();
        if ($reader === null) {
            $out['error'] = self::$error ?? lang('IP 库尚未下载');
            return $out;
        }
        $out['ready'] = true;
        $out['meta'] = $reader->metadata()->toArray();
        return $out;
    }

    /**
     * 换库之后调：丢掉旧读取器与全部归属缓存
     */
    public static function reload(): void
    {
        if (self::$reader !== null) {
            self::$reader->close();
        }
        self::$reader = null;
        self::$tried = false;
        self::$error = null;
        Cache::flush();
    }

    /**
     * 冒烟测试：新库换上去之前必须过。
     * 光校验元数据不够 —— 元数据完好但搜索树被截断的文件是存在的。
     *
     * @return array{ok:bool,msg:string,samples:array<string,string>}
     */
    public static function smokeTest(string $file): array
    {
        $samples = [];
        try {
            $reader = new Reader($file);
            $meta = $reader->metadata();
            if (!$meta->isCity() && !$meta->isCountry()) {
                $reader->close();
                return ['ok' => false, 'msg' => Lang::t('不是 City 或 Country 类型的库：:t', ['t' => $meta->databaseType]), 'samples' => []];
            }

            //这两个 IP 的归属地十几年没变过，拿来当基准很稳
            $expect = ['8.8.8.8' => 'US', '1.2.4.8' => 'CN'];
            foreach ($expect as $ip => $want) {
                $found = $reader->get($ip, self::WANT);
                $got = (string)($found['data']['country']['iso_code']
                    ?? $found['data']['registered_country']['iso_code'] ?? '');
                $samples[$ip] = $got;
                if ($got !== $want) {
                    $reader->close();
                    return [
                        'ok' => false,
                        'msg' => Lang::t('冒烟查询不通过：:ip 应属于 :want，实得 :got', ['ip' => $ip, 'want' => $want, 'got' => $got === '' ? '空' : $got]),
                        'samples' => $samples,
                    ];
                }
            }
            $reader->close();
            return ['ok' => true, 'msg' => lang('校验通过'), 'samples' => $samples];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage(), 'samples' => $samples];
        }
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    private static function reader(): ?Reader
    {
        if (self::$tried) {
            return self::$reader;
        }
        self::$tried = true;

        if (!Settings::bool('geo_enabled')) {
            self::$error = lang('地理定位功能已关闭');
            return self::$reader = null;
        }
        $path = self::dbPath();
        if (!is_file($path)) {
            self::$error = lang('IP 库尚未下载');
            return self::$reader = null;
        }
        try {
            return self::$reader = new Reader($path);
        } catch (\Throwable $e) {
            self::$error = $e->getMessage();
            Log::exception('Locator::reader', $e);
            return self::$reader = null;
        }
    }

    /**
     * MMDB 原始结构 → 本插件的扁平结构
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function shape(array $data): array
    {
        $lang = Settings::get('geo_lang', 'zh-CN');
        $pick = static function (array $names) use ($lang): string {
            return (string)($names[$lang] ?? $names['zh-CN'] ?? $names['en'] ?? '');
        };

        $country = (array)($data['country'] ?? []);
        if (($country['iso_code'] ?? '') === '') {
            //有些 IP 只有注册国（比如卫星链路、匿名代理），拿它兜底
            $country = (array)($data['registered_country'] ?? []);
        }
        $subdivision = (array)(($data['subdivisions'] ?? [])[0] ?? []);

        return [
            'ok' => true,
            'country' => strtoupper((string)($country['iso_code'] ?? '')),
            'country_name' => $pick((array)($country['names'] ?? [])),
            'province' => $pick((array)($subdivision['names'] ?? [])),
            'province_iso' => (string)($subdivision['iso_code'] ?? ''),
            'city' => $pick((array)(($data['city'] ?? [])['names'] ?? [])),
            'continent' => (string)(($data['continent'] ?? [])['code'] ?? ''),
            'tz' => (string)(($data['location'] ?? [])['time_zone'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function blank(): array
    {
        return [
            'ok' => true, 'country' => '', 'country_name' => '', 'province' => '',
            'province_iso' => '', 'city' => '', 'continent' => '', 'tz' => '',
        ];
    }
}
