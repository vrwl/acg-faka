<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Cc;

use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;

/**
 * 站点整体过载保护。
 *
 * 热路径**绝不读全局计数**：那要读 16 个分片文件，正常请求付不起这个钱。
 * 改成两步：
 *   写入：每个请求给自己所在的分片 +1（一次定长文件读写，和单 IP 计数同成本）
 *   判定：1/64 采样触发一次聚合；有守护进程时由它每 5 秒算一次，更平滑
 *   热路径：只看一个 OVERLOAD 标记文件在不在（一次 stat）
 *
 * 进出用迟滞回差（降到阈值 60% 以下才解除），避免在临界点反复抖动。
 */
final class Overload
{
    /** 全站计数分片数：分散锁竞争，同时聚合成本可控 */
    private const SHARDS = 16;

    /** 采样触发聚合的概率倒数 */
    private const SAMPLE = 64;

    /** 解除过载的回差系数 */
    private const HYSTERESIS = 0.6;

    private static ?bool $active = null;

    /**
     * 记一次全站请求（分片，恒定成本）
     */
    public static function hit(string $ip, int $now, int $window): void
    {
        try {
            $shard = crc32($ip) % self::SHARDS;
            FileCounter::hit('g:' . $shard, $now, $window, $window * 6);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 热路径查询：只有一次 stat，结果进程内缓存
     */
    public static function active(): bool
    {
        if (self::$active !== null) {
            return self::$active;
        }
        $file = self::file();
        if (!is_file($file)) {
            return self::$active = false;
        }
        $until = (int)@file_get_contents($file);
        if ($until > 0 && $until < time()) {
            @unlink($file);
            return self::$active = false;
        }
        return self::$active = true;
    }

    /**
     * 采样触发的机会性聚合（守护进程不在时的兜底）
     */
    public static function maybeEvaluate(int $now, int $limit, int $window): void
    {
        if ((mt_rand() % self::SAMPLE) !== 0) {
            return;
        }
        self::evaluate($now, $limit, $window);
    }

    /**
     * 聚合 16 个分片并决定进出过载模式
     *
     * @return array{qps:int,total:int,on:bool}
     */
    public static function evaluate(int $now, int $limit, int $window): array
    {
        $total = 0;
        try {
            for ($shard = 0; $shard < self::SHARDS; $shard++) {
                $total += FileCounter::peek('g:' . $shard, $now, $window, $window * 6)['burst'];
            }
        } catch (\Throwable $e) {
            return ['qps' => 0, 'total' => 0, 'on' => self::active()];
        }

        $qps = $window > 0 ? (int)round($total / $window) : $total;
        $wasOn = self::active();

        if (!$wasOn && $total > $limit) {
            self::enter($now, $qps, $limit);
            return ['qps' => $qps, 'total' => $total, 'on' => true];
        }
        if ($wasOn && $total < $limit * self::HYSTERESIS) {
            self::leave($qps, $limit);
            return ['qps' => $qps, 'total' => $total, 'on' => false];
        }
        if ($wasOn) {
            //还在过载中，把标记的有效期往后推
            @file_put_contents(self::file(), (string)($now + 120));
        }
        return ['qps' => $qps, 'total' => $total, 'on' => $wasOn];
    }

    public static function reset(): void
    {
        self::$active = null;
    }

    /**
     * 当前全站速率（面板展示）
     *
     * @return array{qps:int,total:int,on:bool}
     */
    public static function stats(int $now, int $window): array
    {
        $total = 0;
        for ($shard = 0; $shard < self::SHARDS; $shard++) {
            $total += FileCounter::peek('g:' . $shard, $now, $window, $window * 6)['burst'];
        }
        return [
            'qps' => $window > 0 ? (int)round($total / $window) : $total,
            'total' => $total,
            'on' => self::active(),
        ];
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    private static function enter(int $now, int $qps, int $limit): void
    {
        @file_put_contents(self::file(), (string)($now + 120));
        self::$active = true;
        Log::warn('站点进入过载保护', ['qps' => $qps, 'threshold' => $limit]);

        try {
            hook(0x7C105, ['on' => true, 'qps' => $qps, 'threshold' => $limit]);
        } catch (\Throwable $e) {
        }
        if (class_exists('\App\Plugin\WebsiteMonitor\Module\Notify\Alert')) {
            try {
                \App\Plugin\WebsiteMonitor\Module\Notify\Alert::overload($qps, $limit);
            } catch (\Throwable $e) {
            }
        }
    }

    private static function leave(int $qps, int $limit): void
    {
        @unlink(self::file());
        self::$active = false;
        Log::info('站点退出过载保护', ['qps' => $qps]);
        try {
            hook(0x7C105, ['on' => false, 'qps' => $qps, 'threshold' => $limit]);
        } catch (\Throwable $e) {
        }
    }

    private static function file(): string
    {
        return Runtime::dir() . '/' . Runtime::OVERLOAD_FILE;
    }
}
