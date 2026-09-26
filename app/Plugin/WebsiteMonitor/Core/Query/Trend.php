<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Query;

use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;

/**
 * 趋势曲线与时段热力。粒度由 Range 自适应决定，返回的点数始终控制在几十到几百。
 */
final class Trend
{
    /**
     * @param bool $compare 是否附带上一周期做同比
     * @return array<string,mixed>
     */
    public static function build(Range $range, bool $compare = true): array
    {
        $current = self::series($range);
        $out = [
            'grain' => $range->grain,
            'axis' => $current['axis'],
            'series' => $current['series'],
        ];
        if ($compare) {
            $prev = self::series($range->previous());
            //上一周期的点数可能不一致，对齐到当前长度，方便前端画虚线
            $out['previous'] = self::align($prev['series'], count($current['axis']));
        }
        $out['heatmap'] = self::heatmap($range);
        return $out;
    }

    /**
     * @return array{axis:array<int,string>,series:array<string,array<int,int|float>>}
     */
    public static function series(Range $range): array
    {
        $axis = [];
        $series = [
            'pv' => [], 'uv' => [], 'ip' => [], 'visits' => [],
            'api' => [], 'attack' => [], 'err4' => [], 'err5' => [], 'ms' => [],
        ];

        try {
            if ($range->grain === 'day') {
                $rows = Db::table(Db::STAT_DAY)
                    ->whereBetween('day', [$range->fromDay, $range->toDay])
                    ->orderBy('day')->get();
                $byKey = [];
                foreach ($rows as $row) {
                    $byKey[(int)$row->day] = $row;
                }
                for ($ts = $range->from; $ts <= $range->to; $ts += 86400) {
                    $day = (int)date('Ymd', $ts);
                    $row = $byKey[$day] ?? null;
                    $axis[] = date('m-d', $ts);
                    self::push($series, $row);
                }
                return ['axis' => $axis, 'series' => $series];
            }

            $align = $range->grain === 'min' ? 60 : 3600;
            $table = $range->grain === 'min' ? Db::STAT_MIN : Db::STAT_HOUR;
            $start = $range->from - ($range->from % $align);

            $rows = Db::table($table)
                ->where('t', '>=', $start)->where('t', '<=', $range->to)
                ->orderBy('t')->get();
            $byKey = [];
            foreach ($rows as $row) {
                $byKey[(int)$row->t] = $row;
            }
            $format = $range->grain === 'min' ? 'H:i' : 'm-d H:00';
            for ($ts = $start; $ts <= $range->to; $ts += $align) {
                $row = $byKey[$ts] ?? null;
                $axis[] = date($format, $ts);
                self::push($series, $row);
            }
        } catch (\Throwable $e) {
            Log::exception('Trend::series', $e);
        }

        return ['axis' => $axis, 'series' => $series];
    }

    /**
     * 24 小时 × 7 星期 的访问热力：一眼看出「什么时候上新最好」
     *
     * @return array{cells:array<int,array<int,int>>,max:int}
     */
    public static function heatmap(Range $range): array
    {
        $cells = [];
        $max = 0;
        try {
            //从小时桶算，跨天区间也很快（一天 24 行）
            $rows = Db::table(Db::STAT_HOUR)
                ->where('t', '>=', $range->from)->where('t', '<=', $range->to)
                ->get(['t', 'pv']);
            $grid = [];
            foreach ($rows as $row) {
                $t = (int)$row->t;
                $hour = (int)date('G', $t);
                //周一排最前，符合国内习惯
                $week = ((int)date('N', $t)) - 1;
                $pv = (int)$row->pv;
                $grid[$week][$hour] = ($grid[$week][$hour] ?? 0) + $pv;
                if ($grid[$week][$hour] > $max) {
                    $max = $grid[$week][$hour];
                }
            }
            for ($week = 0; $week < 7; $week++) {
                for ($hour = 0; $hour < 24; $hour++) {
                    $cells[] = [$hour, $week, (int)($grid[$week][$hour] ?? 0)];
                }
            }
        } catch (\Throwable $e) {
            Log::exception('Trend::heatmap', $e);
        }
        return ['cells' => $cells, 'max' => $max];
    }

    /**
     * @param array<string,array<int,int|float>> $series
     */
    private static function push(array &$series, ?object $row): void
    {
        $pv = (int)($row->pv ?? 0);
        $api = (int)($row->api_pv ?? 0);
        $admin = (int)($row->admin_pv ?? 0);
        $series['pv'][] = $pv;
        $series['uv'][] = (int)($row->uv ?? 0);
        $series['ip'][] = (int)($row->ip_n ?? 0);
        $series['visits'][] = (int)($row->sessions ?? 0);
        $series['api'][] = $api;
        $series['attack'][] = (int)($row->attack ?? 0);
        $series['err4'][] = (int)($row->err4 ?? 0);
        $series['err5'][] = (int)($row->err5 ?? 0);
        $total = max(1, $pv + $api + $admin);
        $series['ms'][] = (int)round(((int)($row->ms_sum ?? 0)) / $total);
    }

    /**
     * @param array<string,array<int,int|float>> $series
     * @return array<string,array<int,int|float>>
     */
    private static function align(array $series, int $length): array
    {
        foreach ($series as $key => $values) {
            $n = count($values);
            if ($n === $length) {
                continue;
            }
            if ($n > $length) {
                $series[$key] = array_slice($values, $n - $length);
                continue;
            }
            $series[$key] = array_merge(array_fill(0, $length - $n, 0), $values);
        }
        return $series;
    }
}
