<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Geo;

use App\Plugin\WebsiteMonitor\Core\Kv;
use App\Plugin\WebsiteMonitor\Core\Settings;

/**
 * IP 库更新的调度判定。
 *
 * 为什么不直接把任务注册成 cron：cron 任务只在到点那一刻醒一次，
 * 站长在面板点「立即更新」之后，那个标记要等到下周一才会被读到 —— 等于按钮没用。
 *
 * 改成每分钟醒一次、在这里自己判断该不该下载，代价是每分钟几微秒的数组比较，
 * 换来三件事：
 *   1. 手动触发 60 秒内生效
 *   2. cron 到点照常执行
 *   3. **兜底**：即使守护进程在预定时刻恰好没运行（重启、宕机），
 *      超过一个周期没更新也会自动补上 —— 纯 cron 会直接错过一整周
 */
final class Schedule
{
    /** 超过这么久没成功更新就强制补一次（默认 8 天，给周更留一天余量） */
    private const STALE_SECONDS = 691200;

    /** 同一分钟内不重复触发 */
    private const MARK_KEY = 'geo_last_tick';

    /**
     * 本轮该不该下载
     *
     * @return array{run:bool,manual:bool,why:string}
     */
    public static function due(int $now): array
    {
        //手动触发优先，且不受任何开关限制（站长明确点了按钮）
        if (Downloader::manualRequested()) {
            return ['run' => true, 'manual' => true, 'why' => 'manual'];
        }

        if (!Settings::bool('geo_enabled') || !Settings::bool('geo_auto_update')) {
            return ['run' => false, 'manual' => false, 'why' => 'disabled'];
        }

        $meta = (array)Kv::get('geo_meta', []);
        $lastOk = (int)($meta['last_ok'] ?? 0);

        //从来没成功过：立刻下，别让站长干等一周
        if ($lastOk === 0 && !is_file(Locator::dbPath())) {
            return ['run' => true, 'manual' => false, 'why' => 'first_run'];
        }

        //兜底：太久没更新了，不管 cron 怎么写都补一次
        if ($lastOk > 0 && ($now - $lastOk) > self::STALE_SECONDS) {
            return ['run' => true, 'manual' => false, 'why' => 'stale'];
        }

        //到点了吗
        if (!self::cronMatches(Settings::get('geo_update_cron', '0 4 * * 1'), $now)) {
            return ['run' => false, 'manual' => false, 'why' => 'not_due'];
        }
        //同一分钟只触发一次（任务每 60 秒醒一次，可能在同一分钟内醒两回）
        $minute = (int)floor($now / 60);
        if (Kv::int(self::MARK_KEY, 0) === $minute) {
            return ['run' => false, 'manual' => false, 'why' => 'already_ticked'];
        }
        Kv::set(self::MARK_KEY, $minute, 3600);

        return ['run' => true, 'manual' => false, 'why' => 'cron'];
    }

    /**
     * 5 段 cron 匹配：分 时 日 月 周。
     *
     * 支持 `*`、数字、`a-b` 区间、`a,b,c` 列表、`*​/n` 与 `a-b/n` 步长；
     * 星期 0 与 7 都当周日。用不着更复杂的语法 —— 这只是个「每周几点更新」的开关。
     */
    public static function cronMatches(string $expression, int $now): bool
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($fields) !== 5) {
            //表达式不合法时退回默认：每周一 04:00
            $fields = ['0', '4', '*', '*', '1'];
        }

        $values = [
            (int)date('i', $now),   //分
            (int)date('G', $now),   //时
            (int)date('j', $now),   //日
            (int)date('n', $now),   //月
            (int)date('w', $now),   //周（0=周日）
        ];
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];

        foreach ($fields as $i => $field) {
            if (!self::fieldMatches((string)$field, $values[$i], $ranges[$i][0], $ranges[$i][1], $i === 4)) {
                return false;
            }
        }
        return true;
    }

    private static function fieldMatches(string $field, int $value, int $min, int $max, bool $isWeekday): bool
    {
        $field = trim($field);
        if ($field === '' || $field === '*' || $field === '?') {
            return true;
        }

        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepRaw] = array_pad(explode('/', $part, 2), 2, '1');
                $step = max(1, (int)$stepRaw);
                $part = trim($part) === '' ? '*' : trim($part);
            }

            if ($part === '*') {
                $from = $min;
                $to = $max;
            } elseif (str_contains($part, '-')) {
                [$a, $b] = array_pad(explode('-', $part, 2), 2, '');
                $from = self::normalize((int)$a, $isWeekday);
                $to = self::normalize((int)$b, $isWeekday);
            } else {
                $from = $to = self::normalize((int)$part, $isWeekday);
            }

            if ($from > $to) {
                continue;
            }
            for ($v = $from; $v <= $to; $v += $step) {
                if ($v === $value) {
                    return true;
                }
            }
        }
        return false;
    }

    /** cron 里星期天可以写 7，PHP 的 date('w') 用 0 */
    private static function normalize(int $value, bool $isWeekday): int
    {
        return ($isWeekday && $value === 7) ? 0 : $value;
    }
}
