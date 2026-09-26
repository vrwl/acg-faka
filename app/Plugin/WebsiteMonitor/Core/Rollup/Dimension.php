<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Rollup;

use App\Plugin\WebsiteMonitor\Consts\Dim;
use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;

/**
 * 维度聚合的全量重算。
 *
 * **幂等的关键在赋值语义**：这里用 `col = VALUES(col)`（覆盖）而不是 `col = col + VALUES(col)`（累加），
 * 所以同一天跑 1 次和跑 100 次结果完全一样，重跑永远不会重复计数。
 *
 * 与 IngestTask 的分工：
 *   pv / ms_sum          IngestTask 增量累加（来自 wm_access 的每一行）
 *   visits / uv / bounce 这里从 wm_session 全量重算
 * 两条路径改的是不同的列，互不干扰。
 */
final class Dimension
{
    /**
     * 会话表里的列 => wm_agg 的维度。
     * 这些维度天然是「一次访问一个值」，所以能直接 GROUP BY 出访问次数与去重访客。
     */
    private const SESSION_DIMS = [
        Dim::LANDING => 'entry_page_id',
        Dim::EXIT => 'exit_page_id',
        Dim::ENGINE => 'engine_id',
        Dim::BROWSER => 'browser_id',
        Dim::OS => 'os_id',
        Dim::DEVICE => 'dev',
        Dim::REGION => 'region_id',
        Dim::REF_TYPE => 'ref_type',
        Dim::UTM_SOURCE => 'utm_source_id',
        Dim::UTM_MEDIUM => 'utm_medium_id',
        Dim::UTM_CAMPAIGN => 'utm_campaign_id',
    ];

    /**
     * 重算某一天的全部会话型维度。
     *
     * @return int 写入的行数
     */
    public static function rebuildDay(int $day): int
    {
        $total = 0;
        foreach (self::SESSION_DIMS as $dim => $column) {
            $total += self::rebuildDim($day, (int)$dim, $column);
        }
        $total += self::rebuildRefHost($day);
        return $total;
    }

    /**
     * 来源域名维度。
     *
     * 不能直接拿 wm_session.ref_id 当 key —— 那是「来源网址」的 id，
     * 会让「来源域名」和「来源网址」变成同一张表。这里 join 到 wm_referer 取 host，
     * 再映射到 wm_dict 里的域名条目。两侧都有索引（wm_referer 主键、wm_dict 的 type+code 唯一键）。
     */
    private static function rebuildRefHost(int $day): int
    {
        $agg = Db::physical(Db::AGG);
        $session = Db::physical(Db::SESSION);
        $referer = Db::physical(Db::REFERER);
        $dict = Db::physical(Db::DICT);

        $sql = "INSERT INTO `{$agg}` (`day`, `dim`, `key_id`, `pv`, `visits`, `uv`, `bounce`, `ms_sum`, `stay_sum`)
                SELECT s.`day`, ?, d.`id`,
                       0, COUNT(*), COUNT(DISTINCT s.`vid`), SUM(s.`is_bounce`), 0,
                       SUM(GREATEST(0, s.`end_ts` - s.`start_ts`))
                FROM `{$session}` s
                INNER JOIN `{$referer}` r ON r.`id` = s.`ref_id`
                INNER JOIN `{$dict}` d ON d.`type` = ? AND d.`code` = r.`host`
                WHERE s.`day` = ? AND s.`spider_id` = 0 AND s.`ref_id` > 0 AND r.`host` <> ''
                GROUP BY s.`day`, d.`id`
                ON DUPLICATE KEY UPDATE
                       `visits` = VALUES(`visits`),
                       `uv` = VALUES(`uv`),
                       `bounce` = VALUES(`bounce`),
                       `stay_sum` = VALUES(`stay_sum`)";
        try {
            return (int)Db::conn()->affectingStatement($sql, [Dim::REF_HOST, Kind::D_REF_HOST, $day]);
        } catch (\Throwable $e) {
            Log::exception('Dimension::rebuildRefHost', $e, ['day' => $day]);
            return 0;
        }
    }

    /**
     * @param string $column wm_session 里的列名（已在白名单内，不存在注入风险）
     */
    private static function rebuildDim(int $day, int $dim, string $column): int
    {
        //列名来自类常量白名单，不是用户输入
        if (!in_array($column, self::SESSION_DIMS, true)) {
            return 0;
        }
        $agg = Db::physical(Db::AGG);
        $session = Db::physical(Db::SESSION);

        $sql = "INSERT INTO `{$agg}` (`day`, `dim`, `key_id`, `pv`, `visits`, `uv`, `bounce`, `ms_sum`, `stay_sum`)
                SELECT s.`day`, ?, s.`{$column}`,
                       0,
                       COUNT(*),
                       COUNT(DISTINCT s.`vid`),
                       SUM(s.`is_bounce`),
                       0,
                       SUM(GREATEST(0, s.`end_ts` - s.`start_ts`))
                FROM `{$session}` s
                WHERE s.`day` = ? AND s.`spider_id` = 0
                GROUP BY s.`day`, s.`{$column}`
                ON DUPLICATE KEY UPDATE
                       `visits` = VALUES(`visits`),
                       `uv` = VALUES(`uv`),
                       `bounce` = VALUES(`bounce`),
                       `stay_sum` = VALUES(`stay_sum`)";

        try {
            return (int)Db::conn()->affectingStatement($sql, [$dim, $day]);
        } catch (\Throwable $e) {
            Log::exception('Dimension::rebuildDim', $e, ['dim' => $dim, 'day' => $day]);
            return 0;
        }
    }

    /**
     * 页面维度的 visits/uv 没法从会话表直接算（一次会话会看很多页），
     * 改从 wm_access 明细算。注意明细可能被采样过，所以这里只算 uv，不覆盖 visits。
     */
    public static function rebuildPageUv(int $day): int
    {
        $agg = Db::physical(Db::AGG);
        $access = Db::physical(Db::ACCESS);
        $sql = "INSERT INTO `{$agg}` (`day`, `dim`, `key_id`, `pv`, `visits`, `uv`, `bounce`, `ms_sum`, `stay_sum`)
                SELECT a.`day`, ?, a.`page_id`, 0, 0, COUNT(DISTINCT a.`vid`), 0, 0, 0
                FROM `{$access}` a
                WHERE a.`day` = ? AND a.`spider_id` = 0 AND a.`page_id` > 0
                GROUP BY a.`day`, a.`page_id`
                ON DUPLICATE KEY UPDATE `uv` = VALUES(`uv`)";
        try {
            return (int)Db::conn()->affectingStatement($sql, [Dim::PAGE, $day]);
        } catch (\Throwable $e) {
            Log::exception('Dimension::rebuildPageUv', $e, ['day' => $day]);
            return 0;
        }
    }

    /**
     * 清掉某天某维度里 key_id = 0 的噪音行（未能解析出字典 id 的那些）
     */
    public static function pruneUnresolved(int $day): int
    {
        try {
            return (int)Db::table(Db::AGG)
                ->where('day', $day)
                ->where('key_id', 0)
                ->whereIn('dim', array_keys(self::SESSION_DIMS))
                ->whereNotIn('dim', [Dim::DEVICE, Dim::REF_TYPE])
                ->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
