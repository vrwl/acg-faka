<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Geo;

use App\Plugin\WebsiteMonitor\Core\Fast;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Settings;

/**
 * 归属地缓存。
 *
 * 关键设计：**按「实际命中的网络」缓存，而不是按单个 IP**。
 * MMDB 查询会告诉我们这条记录覆盖多长的前缀（比如某个 /24 整段都属于杭州），
 * 用掩码后的网络当 key，就能把千万级 IP 折叠成几千个网络 —— 缓存命中率立刻上去，
 * 磁盘上也不会堆出几百万个小文件。
 *
 * 两级后端：Redis（有就用）→ 文件（兜底）。都失败也只是慢一点，功能不受影响。
 */
final class Cache
{
    /** 进程内缓存上限 */
    private const MEM_MAX = 2000;

    /** @var array<string,array<string,mixed>> */
    private static array $mem = [];

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $network): ?array
    {
        if (isset(self::$mem[$network])) {
            return self::$mem[$network];
        }

        //Redis
        $packed = Fast::get('geo:' . $network);
        if (is_string($packed) && $packed !== '') {
            $decoded = self::unpack($packed);
            if ($decoded !== null) {
                return self::$mem[$network] = $decoded;
            }
        }

        //文件
        $file = self::path($network);
        if (is_file($file)) {
            $raw = (string)@file_get_contents($file);
            $decoded = self::unpack($raw);
            if ($decoded !== null) {
                //回填一层，下次直接命中 Redis
                self::warm($network, $raw);
                return self::$mem[$network] = $decoded;
            }
            @unlink($file);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $geo
     */
    public static function put(string $network, array $geo): void
    {
        self::remember($network, $geo);
        $packed = self::pack($geo);
        self::warm($network, $packed);
        try {
            $file = self::path($network);
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($file, $packed, LOCK_EX);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 换库之后必须清掉：新版本的归属可能变了
     */
    public static function flush(): int
    {
        self::$mem = [];
        Fast::deleteByPrefix('geo:');
        return Runtime::clearDir(self::dir());
    }

    public static function stats(): array
    {
        $files = 0;
        $bytes = 0;
        try {
            $items = @glob(self::dir() . '/*/*');
            if (is_array($items)) {
                $files = count($items);
                foreach (array_slice($items, 0, 2000) as $item) {
                    $bytes += (int)@filesize($item);
                }
            }
        } catch (\Throwable $e) {
        }
        return ['memory' => count(self::$mem), 'files' => $files, 'bytes' => $bytes, 'redis' => Fast::available()];
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    /**
     * 紧凑串而不是 JSON：一条记录大约 40 字节，比 JSON 省一半，解析也快。
     *
     * @param array<string,mixed> $geo
     */
    private static function pack(array $geo): string
    {
        return implode("\x1f", [
            (string)($geo['country'] ?? ''),
            (string)($geo['country_name'] ?? ''),
            (string)($geo['province'] ?? ''),
            (string)($geo['city'] ?? ''),
            (string)($geo['continent'] ?? ''),
            (string)($geo['province_iso'] ?? ''),
            (string)($geo['tz'] ?? ''),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function unpack(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        $parts = explode("\x1f", $raw);
        if (count($parts) < 5) {
            return null;
        }
        return [
            'ok' => true,
            'country' => $parts[0],
            'country_name' => $parts[1],
            'province' => $parts[2],
            'city' => $parts[3],
            'continent' => $parts[4],
            'province_iso' => $parts[5] ?? '',
            'tz' => $parts[6] ?? '',
        ];
    }

    private static function warm(string $network, string $packed): void
    {
        $ttl = Settings::intMin('geo_cache_ttl', 3600, 2592000);
        Fast::set('geo:' . $network, $packed, $ttl);
    }

    /**
     * @param array<string,mixed> $geo
     */
    private static function remember(string $network, array $geo): void
    {
        if (count(self::$mem) >= self::MEM_MAX) {
            self::$mem = array_slice(self::$mem, (int)(self::MEM_MAX / 2), null, true);
        }
        self::$mem[$network] = $geo;
    }

    private static function dir(): string
    {
        return Runtime::geoDir() . '/cache';
    }

    private static function path(string $network): string
    {
        $hash = md5($network);
        return self::dir() . '/' . substr($hash, 0, 2) . '/' . $hash;
    }
}
