<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Ingest;

use App\Plugin\WebsiteMonitor\Consts\Dim;
use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;

/**
 * 计数入库：去重集合 → 分钟/小时桶 → 维度 PV。
 *
 * UV 的精确性完全依赖 wm_seen 的主键去重：
 * 批量 INSERT IGNORE 之后，**真正插入的行数**就是该时间桶内新增的独立访客数。
 * 这个数字和整批数据在同一个事务里，天然幂等 —— 重放同一批不会多算。
 */
final class Counters
{
    /** 分钟/小时桶里所有累加列的 upsert 表达式 */
    private const SUM_COLUMNS = [
        'pv', 'uv', 'ip_n', 'sessions', 'api_pv', 'admin_pv', 'spider_pv',
        'err4', 'err5', 'attack', 'blocked', 'ms_sum',
    ];

    /**
     * @param array<string,mixed> $folded
     * @param array<string,array<string,int>> $ids Dictionary::resolve 的结果
     */
    public static function bump(array $folded, array $ids): void
    {
        $uvByMinute = self::countSeen((array)$folded['seen']);
        self::buckets((array)$folded['minutes'], $uvByMinute, (array)($folded['new_sessions'] ?? []));
        self::aggregates((array)$folded['agg'], $ids);
    }

    /**
     * 去重写入，并统计每个分钟/小时桶新增了多少独立访客与独立 IP。
     *
     * @param array<int,array<string,mixed>> $seen
     * @return array<string,array<int,int>> 形如 ['vid_min' => [minute => n], ...]
     */
    private static function countSeen(array $seen): array
    {
        $out = ['vid_min' => [], 'ip_min' => [], 'vid_hour' => [], 'ip_hour' => []];
        if ($seen === []) {
            return $out;
        }

        //按 (桶类型, 时间) 分组：每组单独一次 INSERT IGNORE，才能拿到该组的新增行数
        $groups = [];
        foreach ($seen as $item) {
            $groups[$item['b'] . ':' . $item['t']][] = $item;
        }

        foreach ($groups as $key => $rows) {
            [$bucketType, $time] = array_map('intval', explode(':', (string)$key, 2));
            try {
                $inserted = Db::insertIgnore(Db::SEEN, $rows);
            } catch (\Throwable $e) {
                Log::exception('Counters::countSeen', $e);
                continue;
            }
            if ($inserted <= 0) {
                continue;
            }
            $slot = match ($bucketType) {
                Kind::SEEN_VID_MIN => 'vid_min',
                Kind::SEEN_IP_MIN => 'ip_min',
                Kind::SEEN_VID_HOUR => 'vid_hour',
                Kind::SEEN_IP_HOUR => 'ip_hour',
                default => null,
            };
            if ($slot !== null) {
                $out[$slot][$time] = ($out[$slot][$time] ?? 0) + $inserted;
            }
        }
        return $out;
    }

    /**
     * @param array<int,array<string,int>> $minutes
     * @param array<string,array<int,int>> $uv
     * @param array<int,int> $newSessions 分钟 => 本批新开的会话数（由 Folder 按 F_NEW_SESSION 数出）
     */
    private static function buckets(array $minutes, array $uv, array $newSessions): void
    {
        if ($minutes === []) {
            return;
        }

        $minuteRows = [];
        $hourRows = [];
        foreach ($minutes as $minute => $bucket) {
            $minute = (int)$minute;
            $hour = $minute - ($minute % 3600);

            $row = [
                't' => $minute,
                'pv' => (int)$bucket['pv'],
                'uv' => (int)($uv['vid_min'][$minute] ?? 0),
                'ip_n' => (int)($uv['ip_min'][$minute] ?? 0),
                'sessions' => (int)($newSessions[$minute] ?? 0),
                'api_pv' => (int)$bucket['api_pv'],
                'admin_pv' => (int)$bucket['admin_pv'],
                'spider_pv' => (int)$bucket['spider_pv'],
                'err4' => (int)$bucket['err4'],
                'err5' => (int)$bucket['err5'],
                'attack' => (int)$bucket['attack'],
                'blocked' => (int)$bucket['blocked'],
                'ms_sum' => (int)$bucket['ms_sum'],
                'ms_max' => (int)$bucket['ms_max'],
            ];
            $minuteRows[] = $row;

            //小时桶：同一小时内的多个分钟先在内存里合并，少写几行
            if (!isset($hourRows[$hour])) {
                $hourRows[$hour] = $row;
                $hourRows[$hour]['t'] = $hour;
                $hourRows[$hour]['uv'] = 0;
                $hourRows[$hour]['ip_n'] = 0;
                continue;
            }
            foreach (self::SUM_COLUMNS as $column) {
                if ($column === 'uv' || $column === 'ip_n') {
                    continue;
                }
                $hourRows[$hour][$column] += $row[$column];
            }
            if ($row['ms_max'] > $hourRows[$hour]['ms_max']) {
                $hourRows[$hour]['ms_max'] = $row['ms_max'];
            }
        }

        //小时级的 UV / IP 要用小时桶的去重结果，不能把分钟级的加起来（那样会重复计数）
        foreach ($hourRows as $hour => $row) {
            $hourRows[$hour]['uv'] = (int)($uv['vid_hour'][$hour] ?? 0);
            $hourRows[$hour]['ip_n'] = (int)($uv['ip_hour'][$hour] ?? 0);
        }

        $expr = [];
        foreach (self::SUM_COLUMNS as $column) {
            $expr[$column] = '`' . $column . '` + VALUES(`' . $column . '`)';
        }
        $expr['ms_max'] = 'GREATEST(`ms_max`, VALUES(`ms_max`))';

        try {
            Db::upsertMany(Db::STAT_MIN, $minuteRows, $expr);
            Db::upsertMany(Db::STAT_HOUR, array_values($hourRows), $expr);
        } catch (\Throwable $e) {
            Log::exception('Counters::buckets', $e);
        }
    }

    /**
     * 维度 PV 增量。
     *
     * 只动 pv / ms_sum 两列 —— visits / uv / bounce 由 RollupTask 从 wm_session
     * 全量重算并覆盖写。两条写路径改不同的列，互不干扰，各自都幂等。
     *
     * @param array<int,array<int,array<string,array<string,int>>>> $agg
     * @param array<string,array<string,int>> $ids
     */
    private static function aggregates(array $agg, array $ids): void
    {
        if ($agg === []) {
            return;
        }
        $rows = [];
        foreach ($agg as $day => $dims) {
            foreach ($dims as $dim => $refs) {
                foreach ($refs as $ref => $metrics) {
                    $keyId = self::resolveRef((string)$ref, $ids);
                    if ($keyId === null) {
                        continue;
                    }
                    $rows[] = [
                        'day' => (int)$day,
                        'dim' => (int)$dim,
                        'key_id' => $keyId,
                        'pv' => (int)$metrics['pv'],
                        'visits' => 0,
                        'uv' => 0,
                        'bounce' => 0,
                        'ms_sum' => (int)$metrics['ms_sum'],
                        'stay_sum' => 0,
                    ];
                }
            }
        }
        if ($rows === []) {
            return;
        }
        try {
            Db::upsertMany(Db::AGG, $rows, [
                'pv' => '`pv` + VALUES(`pv`)',
                'ms_sum' => '`ms_sum` + VALUES(`ms_sum`)',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Counters::aggregates', $e);
        }
    }

    /**
     * 把折叠时留下的引用解析成 key_id。
     *
     * 引用格式：
     *   int:<n>            直接就是数字（时段、状态码、设备、蜘蛛…）
     *   page:<hash>        页面字典
     *   ref:<hash>         来源字典
     *   dict:<type>:<code> 小维度字典
     *
     * @param array<string,array<string,int>> $ids
     */
    private static function resolveRef(string $ref, array $ids): ?int
    {
        if (str_starts_with($ref, 'int:')) {
            return (int)substr($ref, 4);
        }
        if (str_starts_with($ref, 'page:')) {
            $id = $ids['pages'][substr($ref, 5)] ?? null;
            return $id === null ? null : (int)$id;
        }
        if (str_starts_with($ref, 'ref:')) {
            $id = $ids['referers'][substr($ref, 4)] ?? null;
            return $id === null ? null : (int)$id;
        }
        if (str_starts_with($ref, 'dict:')) {
            $id = $ids['dicts'][substr($ref, 5)] ?? null;
            return $id === null ? null : (int)$id;
        }
        return null;
    }

    /**
     * 维度枚举 → 是否由 IngestTask 增量维护 pv
     */
    public static function isIncremental(int $dim): bool
    {
        return in_array($dim, Dim::PV_INCREMENTAL, true);
    }
}
