<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Query;

use App\Plugin\WebsiteMonitor\Core\Collect\Classify;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Referer\Parser as RefererParser;

/**
 * 访客与会话查询，含单次访问的完整足迹。
 *
 * 「足迹」是这个面板最有说服力的东西：把一次访问从入口到退出的每一跳、每页停留多久
 * 摊开给站长看，比任何汇总数字都直观。
 */
final class Visitor
{
    /**
     * 会话列表
     *
     * @param array<string,mixed> $filter
     * @return array{list:array<int,array<string,mixed>>,total:int}
     */
    public static function sessions(Range $range, array $filter = [], int $page = 1, int $limit = 20): array
    {
        try {
            $q = Db::table(Db::SESSION . ' as s')
                ->where('s.start_ts', '>=', $range->from)
                ->where('s.start_ts', '<=', $range->to);

            if (($filter['spider'] ?? '') === '1') {
                $q->where('s.spider_id', '>', 0);
            } elseif (($filter['spider'] ?? '') !== 'all') {
                $q->where('s.spider_id', 0);
            }
            if (isset($filter['is_new']) && $filter['is_new'] !== '') {
                $q->where('s.is_new', (int)$filter['is_new']);
            }
            if (isset($filter['dev']) && $filter['dev'] !== '') {
                $q->where('s.dev', (int)$filter['dev']);
            }
            if (isset($filter['ref_type']) && $filter['ref_type'] !== '') {
                $q->where('s.ref_type', (int)$filter['ref_type']);
            }
            if (!empty($filter['member'])) {
                $q->where('s.uid', '>', 0);
            }
            if (!empty($filter['ip'])) {
                $q->where('s.ip', (string)$filter['ip']);
            }
            if (!empty($filter['vid'])) {
                $q->where('s.vid', (string)$filter['vid']);
            }
            if (!empty($filter['min_pv'])) {
                $q->where('s.pv', '>=', (int)$filter['min_pv']);
            }

            $total = (int)$q->count();
            $rows = (clone $q)
                ->leftJoin(Db::PAGE . ' as ep', 'ep.id', '=', 's.entry_page_id')
                ->leftJoin(Db::PAGE . ' as xp', 'xp.id', '=', 's.exit_page_id')
                ->leftJoin(Db::REFERER . ' as r', 'r.id', '=', 's.ref_id')
                ->leftJoin(Db::REGION . ' as g', 'g.id', '=', 's.region_id')
                ->leftJoin(Db::UA . ' as u', 'u.id', '=', 's.ua_id')
                ->leftJoin(Db::SPIDER . ' as sp', 'sp.id', '=', 's.spider_id')
                ->orderByDesc('s.start_ts')
                ->forPage(max(1, $page), max(1, min(100, $limit)))
                ->get([
                    's.sid', 's.vid', 's.uid', 's.ip', 's.pv', 's.start_ts', 's.end_ts',
                    's.is_bounce', 's.is_new', 's.ref_type', 's.dev', 's.spider_id',
                    'ep.path as entry', 'xp.path as exitp', 'r.host as ref_host', 'r.url as ref_url',
                    'u.ua as ua', 'sp.name as spider_name',
                    'g.country_name as country_name', 'g.province as province', 'g.city as city', 'g.country as country',
                ]);

            $list = [];
            foreach ($rows as $row) {
                $list[] = [
                    'sid' => (string)$row->sid,
                    'vid' => (string)$row->vid,
                    'uid' => (int)$row->uid,
                    'ip' => (string)$row->ip,
                    'pv' => (int)$row->pv,
                    'start_ts' => (int)$row->start_ts,
                    'start' => date('m-d H:i:s', (int)$row->start_ts),
                    'stay' => max(0, (int)$row->end_ts - (int)$row->start_ts),
                    'bounce' => (int)$row->is_bounce === 1,
                    'is_new' => (int)$row->is_new === 1,
                    'entry' => (string)($row->entry ?? ''),
                    'exit' => (string)($row->exitp ?? ''),
                    'ref_type' => (int)$row->ref_type,
                    'ref_type_text' => RefererParser::typeText((int)$row->ref_type),
                    'ref_host' => (string)($row->ref_host ?? ''),
                    'ref_url' => (string)($row->ref_url ?? ''),
                    'dev' => (int)$row->dev,
                    'dev_text' => Classify::deviceText((int)$row->dev),
                    'spider' => (string)($row->spider_name ?? ''),
                    'ua' => mb_substr((string)($row->ua ?? ''), 0, 160),
                    'region' => self::regionText($row),
                    'country' => (string)($row->country ?? ''),
                ];
            }
            return ['list' => $list, 'total' => $total];
        } catch (\Throwable $e) {
            Log::exception('Visitor::sessions', $e);
            return ['list' => [], 'total' => 0];
        }
    }

    /**
     * 一次访问的完整足迹：每一跳、每页停留多久
     *
     * @return array<int,array<string,mixed>>
     */
    public static function trail(string $sid, int $limit = 200): array
    {
        try {
            $rows = Db::table(Db::ACCESS . ' as a')
                ->leftJoin(Db::PAGE . ' as p', 'p.id', '=', 'a.page_id')
                ->where('a.sid', $sid)
                ->orderBy('a.ts')->orderBy('a.id')
                ->limit($limit)
                ->get(['a.id', 'a.ts', 'a.ms', 'a.kind', 'a.method', 'a.status', 'a.query', 'a.flags', 'p.path as path']);

            $out = [];
            $prev = null;
            foreach ($rows as $row) {
                $item = [
                    'ts' => (int)$row->ts,
                    'time' => date('H:i:s', (int)$row->ts),
                    'path' => (string)($row->path ?? ''),
                    'query' => (string)($row->query ?? ''),
                    'method' => Classify::methodName((int)$row->method),
                    'status' => (int)$row->status,
                    'ms' => (int)$row->ms,
                    'kind' => (int)$row->kind,
                    'kind_text' => Classify::kindText((int)$row->kind),
                    'flags' => (int)$row->flags,
                    'dwell' => 0,
                ];
                if ($prev !== null) {
                    //上一跳的停留时长 = 到下一跳的时间差
                    $out[count($out) - 1]['dwell'] = max(0, $item['ts'] - $prev);
                }
                $prev = $item['ts'];
                $out[] = $item;
            }
            return $out;
        } catch (\Throwable $e) {
            Log::exception('Visitor::trail', $e);
            return [];
        }
    }

    /**
     * 访客终身档案
     *
     * @return array<string,mixed>
     */
    public static function profile(string $vid): array
    {
        try {
            $row = Db::table(Db::VISITOR . ' as v')
                ->leftJoin(Db::REGION . ' as g', 'g.id', '=', 'v.region_id')
                ->leftJoin(Db::UA . ' as u', 'u.id', '=', 'v.ua_id')
                ->where('v.vid', $vid)
                ->first([
                    'v.*', 'u.ua as ua',
                    'g.country_name as country_name', 'g.province as province', 'g.city as city', 'g.country as country',
                ]);
            if (!$row) {
                return [];
            }

            $sessions = Db::table(Db::SESSION . ' as s')
                ->leftJoin(Db::PAGE . ' as ep', 'ep.id', '=', 's.entry_page_id')
                ->where('s.vid', $vid)
                ->orderByDesc('s.start_ts')->limit(30)
                ->get(['s.sid', 's.start_ts', 's.end_ts', 's.pv', 's.is_bounce', 's.ref_type', 'ep.path as entry']);

            $list = [];
            foreach ($sessions as $s) {
                $list[] = [
                    'sid' => (string)$s->sid,
                    'start' => date('Y-m-d H:i:s', (int)$s->start_ts),
                    'start_ts' => (int)$s->start_ts,
                    'stay' => max(0, (int)$s->end_ts - (int)$s->start_ts),
                    'pv' => (int)$s->pv,
                    'bounce' => (int)$s->is_bounce === 1,
                    'entry' => (string)($s->entry ?? ''),
                    'ref_type_text' => RefererParser::typeText((int)$s->ref_type),
                ];
            }

            return [
                'vid' => $vid,
                'first_ts' => (int)$row->first_ts,
                'last_ts' => (int)$row->last_ts,
                'pv' => (int)$row->pv,
                'sessions' => (int)$row->sessions,
                'uid' => (int)$row->uid,
                'ip' => (string)$row->ip,
                'ua' => (string)($row->ua ?? ''),
                'id_kind' => (int)$row->id_kind,
                'note' => (string)$row->note,
                'tag' => (int)$row->tag,
                'region' => self::regionText($row),
                'country' => (string)($row->country ?? ''),
                'session_list' => $list,
            ];
        } catch (\Throwable $e) {
            Log::exception('Visitor::profile', $e);
            return [];
        }
    }

    /**
     * 给访客打标 / 加备注
     */
    public static function tag(string $vid, int $tag, string $note = ''): bool
    {
        try {
            $update = ['tag' => max(0, min(3, $tag))];
            if ($note !== '') {
                $update['note'] = mb_substr($note, 0, 120);
            }
            return Db::table(Db::VISITOR)->where('vid', $vid)->update($update) >= 0;
        } catch (\Throwable $e) {
            return false;
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
