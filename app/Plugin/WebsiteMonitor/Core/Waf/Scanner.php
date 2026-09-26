<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Waf;

use App\Plugin\WebsiteMonitor\Api\Decision;
use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Acl\Ban;
use App\Plugin\WebsiteMonitor\Core\Collect\Collector;
use App\Plugin\WebsiteMonitor\Core\Fast;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;
use App\Util\Throttle;

/**
 * 扫描器检测：单次探测只是可疑，**连续探测多个不同的敏感路径才是确凿的扫描行为**。
 *
 * 这个区分很重要：正常访客偶尔踩一个 404（旧书签、错链）不该被封；
 * 而在五分钟内挨个试 .env、wp-login、phpmyadmin 的，一定是扫描器。
 *
 * 计数用 App\Util\Throttle（文件滑动窗口）—— 这条路径频率很低（只有命中敏感词
 * 或 404 才走），用不着上 CC 那套无锁定长文件的重武器。
 */
final class Scanner
{
    /** 记住某个 IP 探测过哪些不同的敏感路径，用于「探测了几种」的判定 */
    private const DISTINCT_TTL = 300;

    /**
     * 命中敏感路径关键词
     */
    public static function onSensitive(Decision $decision, string $needle): void
    {
        try {
            $rules = State::rules();
            $meta = (array)(($rules['meta'] ?? [])['scan.probe_burst'] ?? []);
            if (!($meta['enabled'] ?? true)) {
                return;
            }
            $threshold = max(2, (int)($meta['threshold'] ?? 3));
            $window = max(60, (int)($meta['window'] ?? 300));

            $distinct = self::countDistinct($decision->ip, $needle, $window);
            if ($distinct < $threshold) {
                return;
            }

            //在窗口内摸了 N 种不同的敏感路径 —— 这是扫描器，不是手滑
            $decision->hit(
                'scan.probe_burst',
                Kind::ATK_SCANNER,
                RuleSet::actionOf((string)($meta['action'] ?? 'ban')),
                (string)($meta['level'] ?? 'critical'),
                (int)($meta['score'] ?? 12)
            );
            $decision->publicReason = lang('检测到自动化扫描行为');
            $decision->evidence['distinct_probes'] = $distinct;
        } catch (\Throwable $e) {
            Log::exception('Scanner::onSensitive', $e);
        }
    }

    /**
     * 404 回调（由 Hook\Collect 在 HTTP_NOT_FOUND 时调）。
     *
     * 注意时机：这个点位在控制器解析失败之后才触发，KERNEL_INIT 的判决早就结束了，
     * 所以这里不能再改判决，只能自己完成计数与封禁。
     */
    public static function onNotFound(string $route): void
    {
        try {
            if (!Settings::enabled() || !Settings::bool('waf_enabled')) {
                return;
            }
            $ip = Collector::ip();
            if ($ip === '') {
                return;
            }

            $rules = State::rules();
            $meta = (array)(($rules['meta'] ?? [])['scan.notfound_burst'] ?? []);
            if (!($meta['enabled'] ?? true)) {
                return;
            }
            $threshold = max(10, (int)($meta['threshold'] ?? 40));
            $window = max(60, (int)($meta['window'] ?? 300));

            //敏感路径的 404 权重更高：一次顶五次普通 404
            $needle = self::matchNeedle($route, (array)($rules['scan_needles'] ?? []));
            $weight = $needle !== null ? 5 : 1;

            $key = 'wm:404:' . md5($ip);
            $over = false;
            for ($i = 0; $i < $weight; $i++) {
                $over = Throttle::tooMany($key, $threshold, $window) || $over;
            }
            if (!$over) {
                return;
            }

            $thresholds = (array)($rules['thresholds'] ?? []);
            $observe = ($thresholds['waf_mode'] ?? Settings::WAF_OBSERVE) === Settings::WAF_OBSERVE;

            Collector::markAttack(
                Kind::ATK_404_FLOOD,
                'scan.notfound_burst',
                $observe ? Kind::ACT_LOG : Kind::ACT_BAN,
                (int)($meta['score'] ?? 8),
                (string)($meta['level'] ?? 'warn'),
                '',
                \App\Plugin\WebsiteMonitor\Core\Db::json(['route' => mb_substr($route, 0, 255), 'needle' => $needle])
            );

            if ($observe) {
                return;
            }
            Ban::escalate(
                $ip,
                'scan.notfound_burst',
                (array)($thresholds['ladder'] ?? [300, 1800, 7200, 86400, 604800]),
                (int)($thresholds['ban_permanent_after'] ?? 6),
                (int)($thresholds['ban_reset_hours'] ?? 72),
                lang('短时间内产生大量 404，疑似目录扫描'),
                1
            );
        } catch (\Throwable $e) {
            Log::exception('Scanner::onNotFound', $e);
        }
    }

    /**
     * 这个 IP 在窗口内探测过多少种**不同**的敏感路径。
     *
     * 用「不同种类」而不是「总次数」：反复刷同一个 404 可能只是个坏链接，
     * 挨个试不同的后台入口才是扫描。
     */
    private static function countDistinct(string $ip, string $needle, int $window): int
    {
        $tag = substr(md5($needle), 0, 4);
        $now = time();

        //Redis 有就用集合：天然去重，SCARD 直接给出种类数
        $redis = Fast::redis();
        if ($redis !== null) {
            try {
                $key = 'probe:' . md5($ip);
                $n = Fast::incr($key . ':' . $tag, 1, $window);
                if ($n === 1) {
                    return max(1, Fast::incr($key . ':n', 1, $window));
                }
                //已经见过这一种，总数不变：读一次当前值（incr 0 即读取并保持）
                return max(1, Fast::incr($key . ':n', 0, $window));
            } catch (\Throwable $e) {
                //连接抖动就走文件
            }
        }

        //文件兜底：一 IP 一个小文件，内容是「时间戳:种类标记」的列表。
        //一次读一次写、不加锁 —— 极端并发下最多少记一次，对启发式判定毫无影响。
        try {
            $file = self::probePath($ip);
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $seen = [];
            $raw = @file_get_contents($file);
            if (is_string($raw) && $raw !== '') {
                foreach (explode(',', $raw) as $item) {
                    [$ts, $mark] = array_pad(explode(':', $item, 2), 2, '');
                    //过期的直接丢掉，文件自然保持很小
                    if ($mark !== '' && (int)$ts > $now - $window) {
                        $seen[$mark] = (int)$ts;
                    }
                }
            }
            $seen[$tag] = $now;
            //只留最近 32 种，防止被人用随机路径撑爆文件
            if (count($seen) > 32) {
                arsort($seen);
                $seen = array_slice($seen, 0, 32, true);
            }
            $parts = [];
            foreach ($seen as $mark => $ts) {
                $parts[] = $ts . ':' . $mark;
            }
            @file_put_contents($file, implode(',', $parts));
            return count($seen);
        } catch (\Throwable $e) {
            return 1;
        }
    }

    private static function probePath(string $ip): string
    {
        $hash = md5($ip);
        return \App\Plugin\WebsiteMonitor\Core\Runtime::ccDir() . '/probe/' . substr($hash, 0, 2) . '/' . $hash;
    }

    /**
     * @param string[] $needles
     */
    private static function matchNeedle(string $route, array $needles): ?string
    {
        $route = strtolower($route);
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($route, (string)$needle)) {
                return (string)$needle;
            }
        }
        return null;
    }
}
