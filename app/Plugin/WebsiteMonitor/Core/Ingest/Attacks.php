<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Ingest;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;

/**
 * 攻击取证入库。
 *
 * 证据行有**限流**：同一个 (IP, 类型) 每分钟最多落一条。
 * 计数照常累加到 wm_stat_min.attack —— 数字是准的，只是不给每一次攻击都存一份证据。
 * 否则一次 CC 攻击就能把 wm_attack 撑到几百万行，取证表反而变成新的攻击面。
 */
final class Attacks
{
    /**
     * @param array<int,array<string,mixed>> $attacks
     * @param array<string,array<string,int>> $ids
     */
    public static function insert(array $attacks, array $ids): void
    {
        if ($attacks === []) {
            return;
        }

        //证据限流：按 (分钟, IP, **具体规则**) 抢占 wm_seen 的坑位，抢到的才落证据行。
        //
        //按「规则」而不是按「类型」去重是有讲究的：一次扫描会同时命中 SQL 注入、XSS、
        //恶意 UA 等好几条规则，它们的 kind 都是 ATK_WAF。按 kind 去重会把它们压成一条，
        //站长事后排查时只能看到「有人攻击过」，看不到到底试了哪些手法。
        //按规则去重则每条规则每分钟留一份证据，上限依然可控。
        $index = [];
        foreach ($attacks as $i => $attack) {
            $minute = (int)$attack['ts'] - ((int)$attack['ts'] % 60);
            $key = substr(md5($attack['ip'] . '|' . $attack['rule']), 0, 16);
            $index[$minute . ':' . $key][] = $i;
        }

        $allowed = [];
        try {
            //逐条 INSERT IGNORE 才能知道具体哪一条抢到了坑位
            foreach ($index as $group => $rowIndexes) {
                [$minute, $key] = explode(':', (string)$group, 2);
                $inserted = Db::insertIgnore(Db::SEEN, [[
                    'b' => Kind::SEEN_ATTACK_EVIDENCE,
                    't' => (int)$minute,
                    'k' => $key,
                ]]);
                if ($inserted > 0) {
                    //本组只留第一条作为证据代表
                    $allowed[$rowIndexes[0]] = true;
                }
            }
        } catch (\Throwable $e) {
            Log::exception('Attacks::claim', $e);
            //抢坑失败就全放行，宁可多存也别丢证据
            $allowed = array_fill_keys(array_keys($attacks), true);
        }

        $rows = [];
        foreach ($attacks as $i => $attack) {
            if (!isset($allowed[$i])) {
                continue;
            }
            $uaRef = (string)$attack['uahash'];
            $rows[] = [
                'ts' => (int)$attack['ts'],
                'day' => (int)$attack['day'],
                'ip' => (string)$attack['ip'],
                'vid' => (string)$attack['vid'],
                'kind' => (int)$attack['kind'],
                'rule' => mb_substr((string)$attack['rule'], 0, 48),
                'level' => mb_substr((string)$attack['level'], 0, 12),
                'score' => (int)$attack['score'],
                'act' => (int)$attack['act'],
                'blocked' => (int)$attack['blocked'],
                'method' => (int)$attack['method'],
                'status' => (int)$attack['status'],
                'path' => mb_substr((string)$attack['path'], 0, 500),
                'ua_id' => $uaRef === '' ? 0 : (int)($ids['uas'][$uaRef] ?? 0),
                'region_id' => (int)($ids['ip_region'][(string)$attack['ip']] ?? 0),
                'ref' => mb_substr((string)$attack['ref'], 0, 255),
                'req_id' => mb_substr((string)$attack['req_id'], 0, 24),
                'evidence' => (string)$attack['evidence'],
            ];
        }
        if ($rows === []) {
            return;
        }
        try {
            foreach (array_chunk($rows, 200) as $chunk) {
                Db::table(Db::ATTACK)->insert($chunk);
            }
        } catch (\Throwable $e) {
            Log::exception('Attacks::insert', $e);
        }
    }

    /**
     * 攻击列表（面板用）
     *
     * @param array<string,mixed> $filter
     * @return array{list:array<int,array<string,mixed>>,total:int}
     */
    public static function paginate(array $filter, int $page = 1, int $limit = 20): array
    {
        try {
            $q = Db::table(Db::ATTACK);
            if (!empty($filter['kind'])) {
                $q->where('kind', (int)$filter['kind']);
            }
            if (!empty($filter['level'])) {
                $q->where('level', (string)$filter['level']);
            }
            if (isset($filter['blocked']) && $filter['blocked'] !== '') {
                $q->where('blocked', (int)$filter['blocked']);
            }
            if (!empty($filter['ip'])) {
                $q->where('ip', (string)$filter['ip']);
            }
            if (!empty($filter['rule'])) {
                $q->where('rule', 'like', str_replace(['%', '_'], ['\%', '\_'], (string)$filter['rule']) . '%');
            }
            if (!empty($filter['req_id'])) {
                $q->where('req_id', (string)$filter['req_id']);
            }
            if (!empty($filter['from'])) {
                $q->where('ts', '>=', (int)$filter['from']);
            }
            if (!empty($filter['to'])) {
                $q->where('ts', '<=', (int)$filter['to']);
            }
            if (!empty($filter['keyword'])) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$filter['keyword']) . '%';
                $q->where(static function ($sub) use ($like): void {
                    $sub->where('path', 'like', $like)->orWhere('ip', 'like', $like)->orWhere('rule', 'like', $like);
                });
            }

            $total = (int)$q->count();
            $rows = $q->orderByDesc('id')->forPage(max(1, $page), max(1, min(200, $limit)))->get();

            $uaIds = [];
            foreach ($rows as $row) {
                if ((int)$row->ua_id > 0) {
                    $uaIds[(int)$row->ua_id] = true;
                }
            }
            $uas = [];
            if ($uaIds !== []) {
                foreach (Db::table(Db::UA)->whereIn('id', array_keys($uaIds))->get(['id', 'ua']) as $ua) {
                    $uas[(int)$ua->id] = (string)$ua->ua;
                }
            }

            $list = [];
            foreach ($rows as $row) {
                $list[] = [
                    'id' => (int)$row->id,
                    'ts' => (int)$row->ts,
                    'time' => date('Y-m-d H:i:s', (int)$row->ts),
                    'ip' => (string)$row->ip,
                    'kind' => (int)$row->kind,
                    'kind_text' => self::kindText((int)$row->kind),
                    'rule' => (string)$row->rule,
                    'level' => (string)$row->level,
                    'score' => (int)$row->score,
                    'act' => (int)$row->act,
                    'act_text' => self::actText((int)$row->act),
                    'blocked' => (int)$row->blocked === 1,
                    'method' => (int)$row->method,
                    'status' => (int)$row->status,
                    'path' => (string)$row->path,
                    'ua' => $uas[(int)$row->ua_id] ?? '',
                    'ref' => (string)$row->ref,
                    'req_id' => (string)$row->req_id,
                    'evidence' => Db::jsonDecode((string)$row->evidence),
                ];
            }
            return ['list' => $list, 'total' => $total];
        } catch (\Throwable $e) {
            Log::exception('Attacks::paginate', $e);
            return ['list' => [], 'total' => 0];
        }
    }

    /**
     * 攻击源 TOP N
     *
     * @return array<int,array<string,mixed>>
     */
    public static function topSources(int $from, int $to, int $limit = 20): array
    {
        try {
            $rows = Db::table(Db::ATTACK)
                ->where('ts', '>=', $from)->where('ts', '<=', $to)
                ->selectRaw('`ip`, COUNT(*) AS n, SUM(`blocked`) AS blocked, MAX(`ts`) AS last_ts')
                ->groupBy('ip')->orderByDesc('n')->limit($limit)->get();
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'ip' => (string)$row->ip,
                    'count' => (int)$row->n,
                    'blocked' => (int)$row->blocked,
                    'last_ts' => (int)$row->last_ts,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::exception('Attacks::topSources', $e);
            return [];
        }
    }

    /**
     * 按类型统计
     *
     * @return array<int,array{kind:int,kind_text:string,count:int}>
     */
    public static function byKind(int $from, int $to): array
    {
        try {
            $rows = Db::table(Db::ATTACK)
                ->where('ts', '>=', $from)->where('ts', '<=', $to)
                ->selectRaw('`kind`, COUNT(*) AS n')
                ->groupBy('kind')->orderByDesc('n')->get();
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'kind' => (int)$row->kind,
                    'kind_text' => self::kindText((int)$row->kind),
                    'count' => (int)$row->n,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function kindText(int $kind): string
    {
        return match ($kind) {
            Kind::ATK_WAF => lang('WAF 规则'),
            Kind::ATK_404_FLOOD => lang('404 洪水'),
            Kind::ATK_CC => lang('CC 攻击'),
            Kind::ATK_LOGIN_BRUTE => lang('登录爆破'),
            Kind::ATK_SCANNER => lang('扫描器'),
            Kind::ATK_FAKE_SPIDER => lang('伪装蜘蛛'),
            Kind::ATK_BLACKLIST => lang('黑名单'),
            Kind::ATK_REGION => lang('地区限制'),
            Kind::ATK_SENSITIVE => lang('敏感路径'),
            Kind::ATK_METHOD => lang('异常方法'),
            Kind::ATK_UPLOAD => lang('上传滥用'),
            Kind::ATK_PROTOCOL => lang('协议异常'),
            default => lang('其它'),
        };
    }

    public static function actText(int $act): string
    {
        return match ($act) {
            Kind::ACT_SCORE => lang('计分'),
            Kind::ACT_BLOCK => lang('拦截'),
            Kind::ACT_BAN => lang('封禁'),
            Kind::ACT_THROTTLE => lang('限速'),
            default => lang('仅记录'),
        };
    }
}
