<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Query;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Cc\Overload;
use App\Plugin\WebsiteMonitor\Core\Collect\Classify;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Settings;

/**
 * 实时面板的数据源。
 *
 * 这是全站轮询最频繁的接口（3 秒一次），所以每一条查询都必须走索引：
 *   在线数     wm_online 的 idx_last
 *   分钟序列   wm_stat_min 的主键区间
 *   请求流     wm_access 的主键倒序 + id > 游标
 */
final class Realtime
{
    /** 请求流一次最多返回多少行 */
    private const STREAM_MAX = 60;

    /**
     * @param string $filter all | page | error | spider | attack
     * @return array<string,mixed>
     */
    public static function build(int $afterId = 0, string $filter = 'all'): array
    {
        $now = time();
        $window = Settings::intMin('online_window', 60, 300);

        return [
            'now' => $now,
            'online' => self::online($now, $window),
            'minutes' => self::minutes($now),
            'stream' => self::stream($afterId, $filter),
            'top' => self::top($now),
            'overload' => Overload::stats($now, max(1, Settings::intMin('cc_global_window', 1, 10))),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function online(int $now, int $window): array
    {
        try {
            $from = $now - $window;
            $row = Db::table(Db::ONLINE)->where('last_at', '>=', $from)
                ->selectRaw('COUNT(*) total, SUM(spider_id = 0) humans, SUM(spider_id > 0) bots, SUM(uid > 0) members')
                ->first();
            return [
                'total' => (int)($row->humans ?? 0),
                'all' => (int)($row->total ?? 0),
                'bots' => (int)($row->bots ?? 0),
                'members' => (int)($row->members ?? 0),
                'window' => $window,
            ];
        } catch (\Throwable $e) {
            return ['total' => 0, 'all' => 0, 'bots' => 0, 'members' => 0, 'window' => $window];
        }
    }

    /**
     * 最近 60 分钟的曲线
     *
     * @return array<string,array<int,mixed>>
     */
    public static function minutes(int $now, int $count = 60): array
    {
        $axis = [];
        $series = ['pv' => [], 'uv' => [], 'api' => [], 'attack' => [], 'ms' => []];
        try {
            $start = ($now - ($now % 60)) - ($count - 1) * 60;
            $rows = Db::table(Db::STAT_MIN)->where('t', '>=', $start)->orderBy('t')->get();
            $byMinute = [];
            foreach ($rows as $row) {
                $byMinute[(int)$row->t] = $row;
            }
            for ($i = 0; $i < $count; $i++) {
                $t = $start + $i * 60;
                $row = $byMinute[$t] ?? null;
                $axis[] = date('H:i', $t);
                $pv = (int)($row->pv ?? 0);
                $api = (int)($row->api_pv ?? 0);
                $admin = (int)($row->admin_pv ?? 0);
                $series['pv'][] = $pv;
                $series['uv'][] = (int)($row->uv ?? 0);
                $series['api'][] = $api;
                $series['attack'][] = (int)($row->attack ?? 0);
                $total = max(1, $pv + $api + $admin);
                $series['ms'][] = (int)round(((int)($row->ms_sum ?? 0)) / $total);
            }
        } catch (\Throwable $e) {
            Log::exception('Realtime::minutes', $e);
        }
        return ['axis' => $axis, 'series' => $series];
    }

    /**
     * 实时请求流。用 id 游标增量拉取，前端只追加新行。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function stream(int $afterId = 0, string $filter = 'all'): array
    {
        try {
            $q = Db::table(Db::ACCESS . ' as a')
                ->leftJoin(Db::PAGE . ' as p', 'p.id', '=', 'a.page_id')
                ->leftJoin(Db::UA . ' as u', 'u.id', '=', 'a.ua_id')
                ->leftJoin(Db::REGION . ' as r', 'r.id', '=', 'a.region_id')
                ->leftJoin(Db::SPIDER . ' as s', 's.id', '=', 'a.spider_id');

            switch ($filter) {
                case 'page':
                    $q->where('a.kind', Kind::REQ_PAGE);
                    break;
                case 'error':
                    $q->where('a.status', '>=', 400);
                    break;
                case 'spider':
                    $q->where('a.spider_id', '>', 0);
                    break;
                case 'attack':
                    $q->whereRaw('(a.flags & ' . Kind::F_ATTACK . ') > 0');
                    break;
            }

            if ($afterId > 0) {
                $q->where('a.id', '>', $afterId);
            }

            $rows = $q->orderByDesc('a.id')->limit(self::STREAM_MAX)->get([
                'a.id', 'a.ts', 'a.ms', 'a.ip', 'a.kind', 'a.method', 'a.status', 'a.query',
                'a.spider_id', 'a.dev', 'a.flags', 'a.vid',
                'p.path as path', 'u.ua as ua',
                'r.country as country', 'r.country_name as country_name', 'r.province as province', 'r.city as city',
                's.name as spider_name', 's.code as spider_code',
            ]);

            $out = [];
            foreach ($rows as $row) {
                $flags = (int)$row->flags;
                $out[] = [
                    'id' => (int)$row->id,
                    'ts' => (int)$row->ts,
                    'time' => date('H:i:s', (int)$row->ts),
                    'ip' => (string)$row->ip,
                    'vid' => (string)$row->vid,
                    'kind' => (int)$row->kind,
                    'method' => Classify::methodName((int)$row->method),
                    'status' => (int)$row->status,
                    'ms' => (int)$row->ms,
                    'path' => (string)($row->path ?? ''),
                    'query' => (string)($row->query ?? ''),
                    'ua' => mb_substr((string)($row->ua ?? ''), 0, 160),
                    'dev' => (int)$row->dev,
                    'spider' => (string)($row->spider_name ?? ''),
                    'spider_code' => (string)($row->spider_code ?? ''),
                    'region' => self::regionText($row),
                    'country' => (string)($row->country ?? ''),
                    'attack' => ($flags & Kind::F_ATTACK) !== 0,
                    'blocked' => ($flags & Kind::F_DENIED) !== 0,
                    'member' => ($flags & Kind::F_MEMBER) !== 0,
                    'new_visitor' => ($flags & Kind::F_NEW_VISITOR) !== 0,
                ];
            }
            //按 id 正序返回，前端直接 append
            return array_reverse($out);
        } catch (\Throwable $e) {
            Log::exception('Realtime::stream', $e);
            return [];
        }
    }

    /**
     * 实时 TOP：页面 / 来源 / 地域
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function top(int $now, int $limit = 5): array
    {
        $day = (int)date('Ymd', $now);
        return [
            'pages' => Dimensions::top(\App\Plugin\WebsiteMonitor\Consts\Dim::PAGE, $day, $day, $limit),
            'referers' => Dimensions::top(\App\Plugin\WebsiteMonitor\Consts\Dim::REF_HOST, $day, $day, $limit),
            'regions' => Dimensions::top(\App\Plugin\WebsiteMonitor\Consts\Dim::REGION, $day, $day, $limit),
        ];
    }

    /**
     * 当前在线的访客卡片
     *
     * @return array<int,array<string,mixed>>
     */
    public static function visitors(int $limit = 30): array
    {
        try {
            $window = Settings::intMin('online_window', 60, 300);
            $rows = Db::table(Db::ONLINE . ' as o')
                ->leftJoin(Db::PAGE . ' as p', 'p.id', '=', 'o.page_id')
                ->leftJoin(Db::REGION . ' as r', 'r.id', '=', 'o.region_id')
                ->leftJoin(Db::UA . ' as u', 'u.id', '=', 'o.ua_id')
                ->leftJoin(Db::SPIDER . ' as s', 's.id', '=', 'o.spider_id')
                ->where('o.last_at', '>=', time() - $window)
                ->orderByDesc('o.last_at')
                ->limit($limit)
                ->get([
                    'o.vid', 'o.ip', 'o.uid', 'o.pv', 'o.first_at', 'o.last_at', 'o.spider_id',
                    'p.path as path', 'u.ua as ua', 's.name as spider_name',
                    'r.country as country', 'r.country_name as country_name', 'r.province as province', 'r.city as city',
                ]);
            $out = [];
            $now = time();
            foreach ($rows as $row) {
                $out[] = [
                    'vid' => (string)$row->vid,
                    'ip' => (string)$row->ip,
                    'uid' => (int)$row->uid,
                    'pv' => (int)$row->pv,
                    'stay' => max(0, (int)$row->last_at - (int)$row->first_at),
                    'idle' => max(0, $now - (int)$row->last_at),
                    'path' => (string)($row->path ?? ''),
                    'ua' => mb_substr((string)($row->ua ?? ''), 0, 120),
                    'spider' => (string)($row->spider_name ?? ''),
                    'region' => self::regionText($row),
                    'country' => (string)($row->country ?? ''),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::exception('Realtime::visitors', $e);
            return [];
        }
    }

    private static function regionText(object $row): string
    {
        $parts = array_values(array_filter([
            (string)($row->country_name ?? ''),
            (string)($row->province ?? ''),
            (string)($row->city ?? ''),
        ], static fn(string $s): bool => trim($s) !== ''));
        return $parts === [] ? '' : implode(' · ', $parts);
    }
}
