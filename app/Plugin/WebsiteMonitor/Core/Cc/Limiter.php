<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Cc;

use App\Plugin\WebsiteMonitor\Api\Decision;
use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Settings;

/**
 * CC 限频判定。三个维度各管一件事：
 *
 *   单 IP 全站     突发（默认 60 次 / 10 秒）与持续（600 次 / 10 分钟）双阈值
 *                  —— 只看突发会放过「慢速但不停歇」的刷子，只看持续会放过瞬时打击
 *   单 IP + 路径   30 次 / 10 秒 → 只限速不封禁
 *                  —— 轮询类接口（订单状态、验证码）天生高频，封了就是误伤
 *   全站分片       进入过载保护模式，收紧其余所有人的阈值
 *
 * 驱动选择：Redis 优先（服务端原子计数），否则用无锁定长文件。
 * Redis 抖动一律降级到文件，绝不让缓存故障变成站点故障。
 */
final class Limiter
{
    private static ?bool $useRedis = null;

    /**
     * @param array<string,mixed> $thresholds rules.php 里的阈值快照
     */
    public static function inspect(Decision $decision, array $thresholds): void
    {
        try {
            $now = time();
            $ip = $decision->ip;

            $burstLimit = (int)($thresholds['cc_burst_limit'] ?? 60);
            $burstWindow = (int)($thresholds['cc_burst_window'] ?? 10);
            $sustainLimit = (int)($thresholds['cc_sustain_limit'] ?? 600);
            $sustainWindow = (int)($thresholds['cc_sustain_window'] ?? 600);

            //过载模式下把其余所有人的阈值收紧（默认 1/3）
            $overloaded = ($thresholds['overload_enabled'] ?? true) && Overload::active();
            if ($overloaded) {
                $factor = max(2, (int)($thresholds['overload_factor'] ?? 3));
                $burstLimit = max(5, (int)($burstLimit / $factor));
                $sustainLimit = max(10, (int)($sustainLimit / $factor));
            }

            // ── 全站计数（分片写，恒定成本）
            if ($thresholds['overload_enabled'] ?? true) {
                $globalWindow = max(1, (int)($thresholds['cc_global_window'] ?? 10));
                Overload::hit($ip, $now, $globalWindow);
                Overload::maybeEvaluate($now, (int)($thresholds['cc_global_limit'] ?? 3000), $globalWindow);
            }

            // ── 单 IP 全站
            $counts = self::hit('ip:' . $ip, $now, $burstWindow, $sustainWindow);

            if ($counts['burst'] > $burstLimit) {
                self::trigger(
                    $decision,
                    'cc.burst',
                    Lang::t('单 IP 在 :w 秒内请求 :n 次，超过阈值 :t', [
                        'w' => (string)$burstWindow, 'n' => (string)$counts['burst'], 't' => (string)$burstLimit,
                    ]),
                    $counts['burst'],
                    $burstLimit,
                    $thresholds
                );
                return;
            }

            if ($counts['sustain'] > $sustainLimit) {
                self::trigger(
                    $decision,
                    'cc.sustain',
                    Lang::t('单 IP 在 :w 分钟内请求 :n 次，超过阈值 :t', [
                        'w' => (string)(int)round($sustainWindow / 60), 'n' => (string)$counts['sustain'], 't' => (string)$sustainLimit,
                    ]),
                    $counts['sustain'],
                    $sustainLimit,
                    $thresholds
                );
                return;
            }

            // ── 单 IP + 路径：只限速，不封禁
            $pathLimit = (int)($thresholds['cc_path_limit'] ?? 30);
            $pathWindow = (int)($thresholds['cc_path_window'] ?? 10);
            if ($pathLimit > 0) {
                $pathCounts = self::hit('p:' . $ip . ':' . crc32($decision->route), $now, $pathWindow, $pathWindow * 6);
                if ($pathCounts['burst'] > ($overloaded ? max(3, (int)($pathLimit / 3)) : $pathLimit)) {
                    $decision->hit('cc.path', Kind::ATK_CC, Decision::THROTTLE, 'warn', 4);
                    $decision->status = 429;
                    $decision->retryAfter = max(3, $pathWindow);
                    $decision->publicReason = lang('访问过于频繁，请稍后再试');
                    $decision->evidence['cc'] = ['scope' => 'path', 'count' => $pathCounts['burst'], 'limit' => $pathLimit];
                    return;
                }
            }

            // ── 过载模式下即使没超个人阈值，也给非白名单请求限个速
            if ($overloaded && $counts['burst'] > max(3, (int)($burstLimit / 2))) {
                $decision->hit('cc.overload', Kind::ATK_CC, Decision::THROTTLE, 'warn', 3);
                $decision->status = 429;
                $decision->retryAfter = 5;
                $decision->publicReason = lang('站点访问高峰，请稍后再试');
                $decision->evidence['cc'] = ['scope' => 'overload', 'count' => $counts['burst']];
            }
        } catch (\Throwable $e) {
            //限频器故障一律放行
            Log::exception('Limiter::inspect', $e);
        }
    }

    /**
     * @param array<string,mixed> $thresholds
     */
    private static function trigger(
        Decision $decision,
        string $rule,
        string $reason,
        int $count,
        int $limit,
        array $thresholds
    ): void {
        $decision->hit($rule, Kind::ATK_CC, Decision::BAN, 'critical', 10);
        $decision->status = 429;
        $decision->retryAfter = max(30, (int)($thresholds['cc_ban_seconds'] ?? 300));
        $decision->publicReason = lang('访问过于频繁，请稍后再试');
        $decision->evidence['cc'] = ['scope' => 'ip', 'count' => $count, 'limit' => $limit, 'why' => $reason];
    }

    /**
     * @return array{burst:int,sustain:int}
     */
    private static function hit(string $key, int $now, int $burstWindow, int $sustainWindow): array
    {
        if (self::redis()) {
            $counts = RedisCounter::hit($key, $now, $burstWindow, $sustainWindow);
            //Redis 突然不可用时会返回全 0，这时退回文件，避免限频静默失效
            if ($counts['burst'] > 0 || $counts['sustain'] > 0) {
                return $counts;
            }
            self::$useRedis = false;
        }
        return FileCounter::hit($key, $now, $burstWindow, $sustainWindow);
    }

    private static function redis(): bool
    {
        if (self::$useRedis !== null) {
            return self::$useRedis;
        }
        $driver = Settings::get('cc_driver', 'auto');
        if ($driver === 'file') {
            return self::$useRedis = false;
        }
        $available = Runtime::redisAvailable() && \App\Plugin\WebsiteMonitor\Core\Fast::available();
        if ($driver === 'redis') {
            return self::$useRedis = $available;
        }
        return self::$useRedis = $available;
    }

    /**
     * 解除某个 IP 的限频计数（手工解封时一并清掉，否则解封了还是被限）
     */
    public static function forget(string $ip): void
    {
        try {
            FileCounter::forget('ip:' . $ip);
            RedisCounter::forget('ip:' . $ip);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 当前某 IP 的计数（面板的规则测试器用）
     *
     * @return array{burst:int,sustain:int}
     */
    public static function peek(string $ip, array $thresholds): array
    {
        $now = time();
        $burstWindow = (int)($thresholds['cc_burst_window'] ?? 10);
        $sustainWindow = (int)($thresholds['cc_sustain_window'] ?? 600);
        return self::redis()
            ? RedisCounter::peek('ip:' . $ip, $now, $burstWindow, $sustainWindow)
            : FileCounter::peek('ip:' . $ip, $now, $burstWindow, $sustainWindow);
    }

    public static function reset(): void
    {
        self::$useRedis = null;
    }
}
