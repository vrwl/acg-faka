<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Rollup;

use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;

/**
 * 天表组装。同样是「覆盖写」，可以随时重跑。
 *
 * 分两步：
 *   1. PV 类：从小时桶求和（快，而且小时桶本身就是精确的）
 *   2. 去重类（UV / 独立 IP / 会话 / 跳出）：从 wm_session 精确算
 *      —— 绝不能把小时级 UV 加起来，同一个访客跨小时会被重复计数。
 */
final class Daily
{
    /**
     * @return array<string,int> 当天的汇总结果
     */
    public static function rebuild(int $day): array
    {
        $from = (int)strtotime((string)$day . ' 00:00:00');
        $to = $from + 86400;

        self::sumFromHours($day, $from, $to);
        self::exactFromSessions($day);

        try {
            $row = Db::table(Db::STAT_DAY)->where('day', $day)->first();
            return $row ? (array)$row : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function sumFromHours(int $day, int $from, int $to): void
    {
        $dayTable = Db::physical(Db::STAT_DAY);
        $hourTable = Db::physical(Db::STAT_HOUR);
        $sql = "INSERT INTO `{$dayTable}`
                  (`day`, `pv`, `uv`, `ip_n`, `sessions`, `api_pv`, `admin_pv`, `spider_pv`,
                   `err4`, `err5`, `attack`, `blocked`, `ms_sum`, `ms_max`, `sample_pct`)
                SELECT ?, COALESCE(SUM(`pv`),0), 0, 0, 0,
                       COALESCE(SUM(`api_pv`),0), COALESCE(SUM(`admin_pv`),0), COALESCE(SUM(`spider_pv`),0),
                       COALESCE(SUM(`err4`),0), COALESCE(SUM(`err5`),0),
                       COALESCE(SUM(`attack`),0), COALESCE(SUM(`blocked`),0),
                       COALESCE(SUM(`ms_sum`),0), COALESCE(MAX(`ms_max`),0), 100
                FROM `{$hourTable}` WHERE `t` >= ? AND `t` < ?
                ON DUPLICATE KEY UPDATE
                  `pv` = VALUES(`pv`), `api_pv` = VALUES(`api_pv`), `admin_pv` = VALUES(`admin_pv`),
                  `spider_pv` = VALUES(`spider_pv`), `err4` = VALUES(`err4`), `err5` = VALUES(`err5`),
                  `attack` = VALUES(`attack`), `blocked` = VALUES(`blocked`),
                  `ms_sum` = VALUES(`ms_sum`), `ms_max` = VALUES(`ms_max`)";
        try {
            Db::conn()->statement($sql, [$day, $from, $to]);
        } catch (\Throwable $e) {
            Log::exception('Daily::sumFromHours', $e, ['day' => $day]);
        }
    }

    /**
     * 去重指标从会话表精确算。MySQL 5.7 没有窗口函数，用相关子查询（同表多次扫描，
     * 但都命中 idx_day_vid 覆盖索引，一天几十万行也在几十毫秒量级）。
     */
    private static function exactFromSessions(int $day): void
    {
        $dayTable = Db::physical(Db::STAT_DAY);
        $sessionTable = Db::physical(Db::SESSION);

        //先保证有这一行（可能当天完全没有 PV 但有会话）
        try {
            Db::insertIgnore(Db::STAT_DAY, [['day' => $day]]);
        } catch (\Throwable $e) {
        }

        $sql = "UPDATE `{$dayTable}` d SET
                  d.`uv` = (SELECT COUNT(DISTINCT s.`vid`) FROM `{$sessionTable}` s WHERE s.`day` = d.`day` AND s.`spider_id` = 0),
                  d.`ip_n` = (SELECT COUNT(DISTINCT s.`ip`) FROM `{$sessionTable}` s WHERE s.`day` = d.`day` AND s.`spider_id` = 0 AND s.`ip` <> ''),
                  d.`sessions` = (SELECT COUNT(*) FROM `{$sessionTable}` s WHERE s.`day` = d.`day` AND s.`spider_id` = 0),
                  d.`bounce` = (SELECT COUNT(*) FROM `{$sessionTable}` s WHERE s.`day` = d.`day` AND s.`spider_id` = 0 AND s.`is_bounce` = 1),
                  d.`new_visitors` = (SELECT COUNT(DISTINCT s.`vid`) FROM `{$sessionTable}` s WHERE s.`day` = d.`day` AND s.`spider_id` = 0 AND s.`is_new` = 1),
                  d.`stay_sum` = (SELECT COALESCE(SUM(GREATEST(0, s.`end_ts` - s.`start_ts`)),0) FROM `{$sessionTable}` s WHERE s.`day` = d.`day` AND s.`spider_id` = 0)
                WHERE d.`day` = ?";
        try {
            Db::conn()->statement($sql, [$day]);
            //回访 = 总 UV - 新访客
            Db::conn()->statement(
                "UPDATE `{$dayTable}` SET `return_visitors` = GREATEST(0, `uv` - `new_visitors`) WHERE `day` = ?",
                [$day]
            );
        } catch (\Throwable $e) {
            Log::exception('Daily::exactFromSessions', $e, ['day' => $day]);
        }
    }

    /**
     * 重建一个日期区间（后台「重建汇总」按钮）
     *
     * @return array{days:int,ms:int}
     */
    public static function rebuildRange(int $fromDay, int $toDay, ?callable $heartbeat = null): array
    {
        $started = microtime(true);
        $days = 0;
        $cursor = (int)strtotime((string)$fromDay);
        $end = (int)strtotime((string)$toDay);
        //最多 400 天，防止误填把守护进程跑死
        while ($cursor <= $end && $days < 400) {
            $day = (int)date('Ymd', $cursor);
            Dimension::rebuildDay($day);
            Dimension::rebuildPageUv($day);
            Dimension::pruneUnresolved($day);
            self::rebuild($day);
            $days++;
            $cursor += 86400;
            if ($heartbeat !== null) {
                try {
                    $heartbeat();
                } catch (\Throwable $e) {
                }
            }
        }
        return ['days' => $days, 'ms' => (int)round((microtime(true) - $started) * 1000)];
    }
}
