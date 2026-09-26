<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Cc;

use App\Plugin\WebsiteMonitor\Core\Fast;

/**
 * Redis 版滑动窗口。
 *
 * 用「一秒一个 key + 读窗口内的一串 key」而不是 ZSET：
 * ZSET 要为每个请求存一个成员，一次 CC 攻击就能把内存吃穿；
 * 秒桶只有窗口长度那么多 key，且靠 EXPIRE 自动回收，内存恒定。
 *
 * 写是一次 INCR（服务端原子，多进程天然安全），读是一次 MGET。
 */
final class RedisCounter
{
    /** 持续窗口用 10 秒一个桶，避免读 600 个 key */
    private const SUSTAIN_SPAN = 10;

    /**
     * @return array{burst:int,sustain:int}
     */
    public static function hit(string $key, int $now, int $burstWindow, int $sustainWindow): array
    {
        $prefix = 'cc:' . md5($key);

        //写：两个粒度各 +1，过期时间给足一个窗口的余量
        Fast::incr($prefix . ':s:' . $now, 1, $burstWindow + 10);
        Fast::incr($prefix . ':m:' . intdiv($now, self::SUSTAIN_SPAN), 1, $sustainWindow + 60);

        return [
            'burst' => self::sum($prefix . ':s:', $now, 1, $burstWindow),
            'sustain' => self::sum($prefix . ':m:', intdiv($now, self::SUSTAIN_SPAN), 1, (int)ceil($sustainWindow / self::SUSTAIN_SPAN)),
        ];
    }

    /**
     * @return array{burst:int,sustain:int}
     */
    public static function peek(string $key, int $now, int $burstWindow, int $sustainWindow): array
    {
        $prefix = 'cc:' . md5($key);
        return [
            'burst' => self::sum($prefix . ':s:', $now, 1, $burstWindow),
            'sustain' => self::sum($prefix . ':m:', intdiv($now, self::SUSTAIN_SPAN), 1, (int)ceil($sustainWindow / self::SUSTAIN_SPAN)),
        ];
    }

    public static function forget(string $key): void
    {
        Fast::deleteByPrefix('cc:' . md5($key));
    }

    /**
     * 一次 MGET 取回窗口内的全部桶
     */
    private static function sum(string $prefix, int $current, int $step, int $slots): int
    {
        $slots = max(1, min(120, $slots));
        $keys = [];
        for ($i = 0; $i < $slots; $i++) {
            $keys[] = $prefix . ($current - $i * $step);
        }
        $values = Fast::mgetInt($keys);
        return $values === [] ? 0 : array_sum($values);
    }
}
