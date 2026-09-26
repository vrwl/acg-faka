<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Cc;

use App\Plugin\WebsiteMonitor\Core\Runtime;

/**
 * 无锁的滑动窗口计数器：**每个 IP 一个定长文件**。
 *
 * 为什么不用 App\Util\Throttle：它底层是 Kernel\Cache + flock。全站共用的计数键
 * 会让所有 php-fpm 进程抢同一把锁 —— 攻击流量越大锁竞争越狠，防御手段本身
 * 先变成瓶颈。一 IP 一文件天然分散，攻击者想制造锁竞争就得先控制同一个 IP。
 *
 * 文件恒为 492 字节，两个环形桶：
 *   sec[60]  1 秒粒度 → 覆盖最近 60 秒（突发判定）
 *   min[60]  10 秒粒度 → 覆盖最近 600 秒（持续判定）
 *
 * 写入不加锁：≤4KB 的 write 在本地文件系统上不会跨块撕裂，
 * 偶尔丢一次计数对阈值判定毫无影响，换来的是零锁竞争。
 */
final class FileCounter
{
    private const VERSION = 1;
    private const BUCKETS = 60;
    private const SEC_SPAN = 1;
    private const MIN_SPAN = 10;
    private const SIZE = 492;

    /** 头部：magic(4) + last(4) = 8 字节，尾部 flags(4) */
    private const HEAD = 8;
    private const SEC_OFFSET = 8;
    private const MIN_OFFSET = 248;
    private const FLAG_OFFSET = 488;

    /**
     * 记一次，返回两个窗口的当前总量。
     *
     * @return array{burst:int,sustain:int}
     */
    public static function hit(string $key, int $now, int $burstWindow, int $sustainWindow): array
    {
        try {
            $file = self::path($key);
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $raw = @file_get_contents($file);
            if (!is_string($raw) || strlen($raw) !== self::SIZE) {
                $raw = self::blank();
            }

            $head = unpack('Vver/Vlast', substr($raw, 0, self::HEAD));
            $last = (int)($head['last'] ?? 0);
            $sec = array_values((array)unpack('V' . self::BUCKETS, substr($raw, self::SEC_OFFSET, 240)));
            $min = array_values((array)unpack('V' . self::BUCKETS, substr($raw, self::MIN_OFFSET, 240)));

            //把从上次写入到现在跨过的桶清零。最多清 60 个，与间隔多久无关。
            self::rotate($sec, $last, $now, self::SEC_SPAN);
            self::rotate($min, $last, $now, self::MIN_SPAN);

            $sec[self::slot($now, self::SEC_SPAN)]++;
            $min[self::slot($now, self::MIN_SPAN)]++;

            $packed = pack('VV', self::VERSION, $now)
                . pack('V*', ...$sec)
                . pack('V*', ...$min)
                . substr($raw, self::FLAG_OFFSET, 4);
            @file_put_contents($file, $packed);

            return [
                'burst' => self::sum($sec, $now, self::SEC_SPAN, $burstWindow),
                'sustain' => self::sum($min, $now, self::MIN_SPAN, $sustainWindow),
            ];
        } catch (\Throwable $e) {
            //计数器故障一律放行，绝不能因为写不了文件就把访客拦在门外
            return ['burst' => 0, 'sustain' => 0];
        }
    }

    /**
     * 只读不写（用于全站分片的聚合）
     *
     * @return array{burst:int,sustain:int}
     */
    public static function peek(string $key, int $now, int $burstWindow, int $sustainWindow): array
    {
        try {
            $raw = @file_get_contents(self::path($key));
            if (!is_string($raw) || strlen($raw) !== self::SIZE) {
                return ['burst' => 0, 'sustain' => 0];
            }
            $head = unpack('Vver/Vlast', substr($raw, 0, self::HEAD));
            $last = (int)($head['last'] ?? 0);
            $sec = array_values((array)unpack('V' . self::BUCKETS, substr($raw, self::SEC_OFFSET, 240)));
            $min = array_values((array)unpack('V' . self::BUCKETS, substr($raw, self::MIN_OFFSET, 240)));
            self::rotate($sec, $last, $now, self::SEC_SPAN);
            self::rotate($min, $last, $now, self::MIN_SPAN);
            return [
                'burst' => self::sum($sec, $now, self::SEC_SPAN, $burstWindow),
                'sustain' => self::sum($min, $now, self::MIN_SPAN, $sustainWindow),
            ];
        } catch (\Throwable $e) {
            return ['burst' => 0, 'sustain' => 0];
        }
    }

    public static function forget(string $key): void
    {
        @unlink(self::path($key));
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    /**
     * 清掉从 $last 到 $now 之间跨过的桶
     *
     * @param array<int,int> $buckets
     */
    private static function rotate(array &$buckets, int $last, int $now, int $span): void
    {
        if ($last <= 0 || $now <= $last) {
            return;
        }
        $lastSlot = intdiv($last, $span);
        $nowSlot = intdiv($now, $span);
        $gap = min(self::BUCKETS, $nowSlot - $lastSlot);
        for ($i = 1; $i <= $gap; $i++) {
            $buckets[($lastSlot + $i) % self::BUCKETS] = 0;
        }
    }

    /**
     * @param array<int,int> $buckets
     */
    private static function sum(array $buckets, int $now, int $span, int $window): int
    {
        $slots = min(self::BUCKETS, max(1, (int)ceil($window / $span)));
        $current = intdiv($now, $span);
        $total = 0;
        for ($i = 0; $i < $slots; $i++) {
            $total += (int)($buckets[($current - $i + self::BUCKETS * 2) % self::BUCKETS] ?? 0);
        }
        return $total;
    }

    private static function slot(int $now, int $span): int
    {
        return intdiv($now, $span) % self::BUCKETS;
    }

    private static function blank(): string
    {
        return pack('VV', self::VERSION, 0)
            . str_repeat("\x00", 240)
            . str_repeat("\x00", 240)
            . "\x00\x00\x00\x00";
    }

    private static function path(string $key): string
    {
        $hash = md5($key);
        return Runtime::ccDir() . '/' . substr($hash, 0, 2) . '/' . $hash;
    }
}
