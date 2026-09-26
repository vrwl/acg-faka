<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Query;

use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;

/**
 * 概览卡片：本期数字 + 上期同比 + 迷你趋势。
 *
 * 全部读预聚合表，不碰明细，所以无论站点多大都是毫秒级。
 */
final class Overview
{
    /**
     * @return array<string,mixed>
     */
    public static function build(Range $range): array
    {
        $current = self::totals($range);
        $previous = self::totals($range->previous());

        return [
            'range' => $range->key,
            'range_text' => $range->label(),
            'from' => $range->from,
            'to' => $range->to,
            'current' => $current,
            'previous' => $previous,
            'delta' => self::delta($current, $previous),
            'spark' => self::spark($range),
        ];
    }

    /**
     * @return array<string,int|float>
     */
    public static function totals(Range $range): array
    {
        $empty = [
            'pv' => 0, 'uv' => 0, 'ip' => 0, 'visits' => 0, 'api_pv' => 0, 'admin_pv' => 0,
            'spider_pv' => 0, 'err4' => 0, 'err5' => 0, 'attack' => 0, 'blocked' => 0,
            'bounce' => 0, 'new_visitors' => 0, 'return_visitors' => 0,
            'bounce_rate' => 0.0, 'avg_ms' => 0, 'avg_stay' => 0, 'pv_per_visit' => 0.0,
        ];
        try {
            if ($range->useDayTable()) {
                $row = Db::table(Db::STAT_DAY)
                    ->whereBetween('day', [$range->fromDay, $range->toDay])
                    ->selectRaw('SUM(pv) pv, SUM(uv) uv, SUM(ip_n) ip, SUM(sessions) visits,
                                 SUM(api_pv) api_pv, SUM(admin_pv) admin_pv, SUM(spider_pv) spider_pv,
                                 SUM(err4) err4, SUM(err5) err5, SUM(attack) attack, SUM(blocked) blocked,
                                 SUM(bounce) bounce, SUM(new_visitors) new_visitors,
                                 SUM(return_visitors) return_visitors, SUM(ms_sum) ms_sum, SUM(stay_sum) stay_sum')
                    ->first();
            } else {
                //当天/短区间用小时桶，能反映「刚刚发生的事」
                $table = $range->grain === 'min' ? Db::STAT_MIN : Db::STAT_HOUR;
                $align = $range->grain === 'min' ? 60 : 3600;
                $row = Db::table($table)
                    ->where('t', '>=', $range->from - ($range->from % $align))
                    ->where('t', '<=', $range->to)
                    ->selectRaw('SUM(pv) pv, SUM(uv) uv, SUM(ip_n) ip, SUM(sessions) visits,
                                 SUM(api_pv) api_pv, SUM(admin_pv) admin_pv, SUM(spider_pv) spider_pv,
                                 SUM(err4) err4, SUM(err5) err5, SUM(attack) attack, SUM(blocked) blocked,
                                 SUM(ms_sum) ms_sum')
                    ->first();
            }
            if (!$row) {
                return $empty;
            }

            $out = $empty;
            foreach (['pv', 'uv', 'ip', 'visits', 'api_pv', 'admin_pv', 'spider_pv',
                         'err4', 'err5', 'attack', 'blocked', 'bounce', 'new_visitors', 'return_visitors'] as $key) {
                $out[$key] = (int)($row->{$key} ?? 0);
            }
            $pv = max(1, $out['pv'] + $out['api_pv'] + $out['admin_pv']);
            $out['avg_ms'] = (int)round(((int)($row->ms_sum ?? 0)) / $pv);

            //单天区间才用天表里精确去重的 UV；跨天求和会高估，面板上只展示访问次数
            if (!$range->useDayTable()) {
                $out['bounce'] = self::sessionMetric($range, 'bounce');
                $out['new_visitors'] = self::sessionMetric($range, 'new');
                $out['visits'] = self::sessionMetric($range, 'visits');
                $out['uv'] = self::sessionMetric($range, 'uv');
                $out['return_visitors'] = max(0, $out['uv'] - $out['new_visitors']);
                $out['avg_stay'] = self::sessionMetric($range, 'stay');
            } else {
                $stay = (int)($row->stay_sum ?? 0);
                $out['avg_stay'] = $out['visits'] > 0 ? (int)round($stay / $out['visits']) : 0;
            }

            $out['bounce_rate'] = $out['visits'] > 0 ? round($out['bounce'] / $out['visits'] * 100, 1) : 0.0;
            $out['pv_per_visit'] = $out['visits'] > 0 ? round($out['pv'] / $out['visits'], 1) : 0.0;
            return $out;
        } catch (\Throwable $e) {
            Log::exception('Overview::totals', $e);
            return $empty;
        }
    }

    /**
     * 跨天的去重指标必须从会话表现算 —— 把每天的 UV 加起来会把回头客算很多次。
     */
    private static function sessionMetric(Range $range, string $metric): int
    {
        try {
            $q = Db::table(Db::SESSION)
                ->where('start_ts', '>=', $range->from)
                ->where('start_ts', '<=', $range->to)
                ->where('spider_id', 0);
            return match ($metric) {
                'uv' => (int)$q->distinct()->count('vid'),
                'visits' => (int)$q->count(),
                'bounce' => (int)$q->where('is_bounce', 1)->count(),
                'new' => (int)$q->where('is_new', 1)->distinct()->count('vid'),
                'stay' => (function () use ($q): int {
                    $row = $q->selectRaw('COUNT(*) n, SUM(GREATEST(0, end_ts - start_ts)) s')->first();
                    $n = (int)($row->n ?? 0);
                    return $n > 0 ? (int)round(((int)($row->s ?? 0)) / $n) : 0;
                })(),
                default => 0,
            };
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param array<string,int|float> $current
     * @param array<string,int|float> $previous
     * @return array<string,float>
     */
    private static function delta(array $current, array $previous): array
    {
        $out = [];
        foreach ($current as $key => $value) {
            $old = (float)($previous[$key] ?? 0);
            if ($old <= 0) {
                //从 0 涨上来没有百分比可言，用 null 让前端显示「新增」而不是 +∞
                $out[$key] = $value > 0 ? 100.0 : 0.0;
                continue;
            }
            $out[$key] = round((((float)$value) - $old) / $old * 100, 1);
        }
        return $out;
    }

    /**
     * 卡片上的迷你趋势线（固定 40 个点）
     *
     * @return array<string,array<int,int>>
     */
    private static function spark(Range $range): array
    {
        try {
            $points = 40;
            $span = max(60, (int)floor(($range->to - $range->from) / $points));
            $table = $span < 3600 ? Db::STAT_MIN : ($span < 86400 ? Db::STAT_HOUR : Db::STAT_DAY);

            if ($table === Db::STAT_DAY) {
                $rows = Db::table(Db::STAT_DAY)
                    ->whereBetween('day', [$range->fromDay, $range->toDay])
                    ->orderBy('day')->get(['day', 'pv', 'uv', 'attack']);
                $pv = [];
                $uv = [];
                $attack = [];
                foreach ($rows as $row) {
                    $pv[] = (int)$row->pv;
                    $uv[] = (int)$row->uv;
                    $attack[] = (int)$row->attack;
                }
                return ['pv' => $pv, 'uv' => $uv, 'attack' => $attack];
            }

            $align = $table === Db::STAT_MIN ? 60 : 3600;
            $rows = Db::table($table)
                ->where('t', '>=', $range->from - ($range->from % $align))
                ->where('t', '<=', $range->to)
                ->orderBy('t')->get(['t', 'pv', 'uv', 'attack']);

            $pv = [];
            $uv = [];
            $attack = [];
            foreach ($rows as $row) {
                $pv[] = (int)$row->pv;
                $uv[] = (int)$row->uv;
                $attack[] = (int)$row->attack;
            }
            return [
                'pv' => self::downsample($pv, $points),
                'uv' => self::downsample($uv, $points),
                'attack' => self::downsample($attack, $points),
            ];
        } catch (\Throwable $e) {
            return ['pv' => [], 'uv' => [], 'attack' => []];
        }
    }

    /**
     * @param array<int,int> $values
     * @return array<int,int>
     */
    private static function downsample(array $values, int $points): array
    {
        $n = count($values);
        if ($n <= $points) {
            return $values;
        }
        $bucket = (int)ceil($n / $points);
        $out = [];
        for ($i = 0; $i < $n; $i += $bucket) {
            $out[] = (int)array_sum(array_slice($values, $i, $bucket));
        }
        return $out;
    }
}
