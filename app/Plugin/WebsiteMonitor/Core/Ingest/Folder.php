<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Ingest;

use App\Plugin\WebsiteMonitor\Consts\Dim;
use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Collect\Classify;
use App\Plugin\WebsiteMonitor\Core\Referer\Parser as RefererParser;
use App\Plugin\WebsiteMonitor\Core\Spool\Line;
use App\Plugin\WebsiteMonitor\Core\Ua\Parser as UaParser;

/**
 * 纯内存折叠：一批 spool 行 → 各张表要写的数据。
 *
 * 这里不碰数据库、不碰文件，只做聚合。好处是可以先把几千行压成几十条 upsert，
 * 再由后续步骤一次性写库 —— 这是整条流水线能扛住高流量的关键。
 *
 * 维度的 key 在这一步还是「引用」（页面哈希、字典码…），
 * 要等 Dictionary 解析出真正的自增 id 之后，Counters 才能拼出最终的 wm_agg 行。
 */
final class Folder
{
    /** 分钟桶累加的列 */
    private const BUCKET_COLUMNS = [
        'pv', 'uv', 'ip_n', 'sessions', 'api_pv', 'admin_pv', 'spider_pv',
        'err4', 'err5', 'attack', 'blocked', 'ms_sum', 'ms_max',
    ];

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param bool $countSpiderInPv 蜘蛛是否计入 PV/UV
     * @return array<string,mixed>
     */
    public static function fold(array $rows, bool $countSpiderInPv = false): array
    {
        $out = [
            'minutes' => [],
            'new_sessions' => [],
            'seen' => [],
            'pages' => [],
            'referers' => [],
            'uas' => [],
            'dicts' => [],
            'regions' => [],
            'sessions' => [],
            'visitors' => [],
            'online' => [],
            'attacks' => [],
            'ips' => [],
            'access' => [],
            'agg' => [],
            'count' => 0,
            'first_ts' => 0,
            'last_ts' => 0,
        ];

        $selfHosts = RefererParser::configuredHosts();

        foreach ($rows as $row) {
            $ts = (int)$row['ts'];
            if ($ts <= 0) {
                continue;
            }
            $out['count']++;
            if ($out['first_ts'] === 0 || $ts < $out['first_ts']) {
                $out['first_ts'] = $ts;
            }
            if ($ts > $out['last_ts']) {
                $out['last_ts'] = $ts;
            }

            $lean = (int)($row['v'] ?? Line::V_FULL) === Line::V_LEAN;
            $minute = $ts - ($ts % 60);
            $day = (int)date('Ymd', $ts);
            $hour = (int)date('G', $ts);

            $kind = (int)($row['kind'] ?? Kind::REQ_OTHER);
            $status = (int)($row['status'] ?? 200);
            $spider = (int)($row['spider'] ?? 0);
            $flags = (int)($row['flags'] ?? 0);
            $ip = (string)($row['ip'] ?? '');
            $isSpider = $spider > 0;
            $isAttack = ($flags & Kind::F_ATTACK) !== 0;
            $blocked = ($flags & Kind::F_DENIED) !== 0;

            //蜘蛛默认不计入 PV/UV（它们不是人），单独计到 spider_pv 一列
            $countable = !$isSpider || $countSpiderInPv;

            // ── 分钟桶
            $bucket = &self::bucket($out['minutes'], $minute);
            if ($countable && $kind === Kind::REQ_PAGE) {
                $bucket['pv']++;
            }
            if ($kind === Kind::REQ_API) {
                $bucket['api_pv']++;
            }
            if ($kind === Kind::REQ_ADMIN) {
                $bucket['admin_pv']++;
            }
            if ($isSpider) {
                $bucket['spider_pv']++;
            }
            if ($status >= 400 && $status < 500) {
                $bucket['err4']++;
            } elseif ($status >= 500) {
                $bucket['err5']++;
            }
            if ($isAttack) {
                $bucket['attack']++;
                if ($blocked) {
                    $bucket['blocked']++;
                }
            }
            $ms = (int)($row['ms'] ?? 0);
            $bucket['ms_sum'] += $ms;
            if ($ms > $bucket['ms_max']) {
                $bucket['ms_max'] = $ms;
            }
            unset($bucket);

            //「新开的会话数」只能看采集时打的 F_NEW_SESSION 标记。
            //早期版本是按本批出现过的会话去数，结果一个会话跨多少批就被算多少次
            //（实测 3 个会话被算成 24 个）。
            if ($countable && ($flags & Kind::F_NEW_SESSION) !== 0) {
                $out['new_sessions'][$minute] = ($out['new_sessions'][$minute] ?? 0) + 1;
            }

            // ── 去重集合：UV 与独立 IP 的精确来源
            $vid = (string)($row['vid'] ?? '');
            if ($countable && $vid !== '') {
                $out['seen'][] = ['b' => Kind::SEEN_VID_MIN, 't' => $minute, 'k' => $vid];
                $out['seen'][] = ['b' => Kind::SEEN_VID_HOUR, 't' => $ts - ($ts % 3600), 'k' => $vid];
            }
            if ($countable && $ip !== '') {
                $ipKey = substr(md5($ip), 0, 16);
                $out['seen'][] = ['b' => Kind::SEEN_IP_MIN, 't' => $minute, 'k' => $ipKey];
                $out['seen'][] = ['b' => Kind::SEEN_IP_HOUR, 't' => $ts - ($ts % 3600), 'k' => $ipKey];
            }

            // ── 攻击
            if ($isAttack) {
                $out['attacks'][] = [
                    'ts' => $ts,
                    'day' => $day,
                    'ip' => $ip,
                    'vid' => $vid,
                    'kind' => (int)($row['atk_kind'] ?? 0),
                    'rule' => (string)($row['atk_rule'] ?? ''),
                    'level' => (string)($row['atk_level'] ?? 'warn') ?: 'warn',
                    'score' => (int)($row['atk_score'] ?? 0),
                    'act' => (int)($row['atk_act'] ?? 0),
                    'blocked' => $blocked ? 1 : 0,
                    'method' => (int)($row['method'] ?? Kind::M_GET),
                    'status' => $status,
                    'path' => (string)($row['path'] ?? ''),
                    'ua_raw' => (string)($row['ua'] ?? ''),
                    'uahash' => (string)($row['uahash'] ?? ''),
                    'ref' => (string)($row['ref'] ?? ''),
                    'req_id' => (string)($row['req_id'] ?? ''),
                    'evidence' => (string)($row['evidence'] ?? ''),
                ];
            }

            // ── IP 画像
            if ($ip !== '') {
                if (!isset($out['ips'][$ip])) {
                    $out['ips'][$ip] = ['first_at' => $ts, 'last_at' => $ts, 'req' => 0, 'atk' => 0, 'spider_id' => $spider, 'last_rule' => ''];
                }
                $profile = &$out['ips'][$ip];
                $profile['req']++;
                if ($isAttack) {
                    $profile['atk']++;
                    $profile['last_rule'] = (string)($row['atk_rule'] ?? '');
                }
                if ($ts < $profile['first_at']) {
                    $profile['first_at'] = $ts;
                }
                if ($ts > $profile['last_at']) {
                    $profile['last_at'] = $ts;
                }
                unset($profile);
            }

            //精简行到此为止：PV / 攻击数 / TOP-IP 依然精确，只是没有页面与来源明细
            if ($lean) {
                continue;
            }

            // ── 页面字典
            $path = (string)($row['path'] ?? '/');
            $pageHash = substr(md5($path), 0, 16);
            if (!isset($out['pages'][$pageHash])) {
                $out['pages'][$pageHash] = ['h' => $pageHash, 'path' => $path, 'kind' => $kind, 'first_ts' => $ts, 'last_ts' => $ts];
            } elseif ($ts > $out['pages'][$pageHash]['last_ts']) {
                $out['pages'][$pageHash]['last_ts'] = $ts;
            }

            // ── UA 字典
            $uaHash = (string)($row['uahash'] ?? '');
            $uaRaw = (string)($row['ua'] ?? '');
            if ($uaHash !== '') {
                if (!isset($out['uas'][$uaHash])) {
                    $out['uas'][$uaHash] = ['h' => $uaHash, 'ua' => $uaRaw, 'dev' => (int)($row['dev'] ?? 0), 'spider_id' => $spider, 'last_ts' => $ts];
                } elseif ($uaRaw !== '' && $out['uas'][$uaHash]['ua'] === '') {
                    $out['uas'][$uaHash]['ua'] = $uaRaw;
                }
            }

            // ── 来源解析
            $refRaw = (string)($row['ref'] ?? '');
            $host = (string)($row['host'] ?? '');
            $query = self::parseQuery((string)($row['query'] ?? ''));
            $ref = RefererParser::parse($refRaw, $host !== '' ? $host : ($selfHosts[0] ?? ''), $query);
            $refHash = $ref['url'] === '' ? '' : substr(md5($ref['url']), 0, 16);
            if ($refHash !== '') {
                if (!isset($out['referers'][$refHash])) {
                    $out['referers'][$refHash] = [
                        'h' => $refHash,
                        'host' => $ref['host'],
                        'url' => $ref['url'],
                        'type' => $ref['type'],
                        'keyword' => $ref['keyword'],
                        'engine_code' => $ref['engine'],
                        'first_ts' => $ts,
                        'last_ts' => $ts,
                    ];
                } elseif ($ts > $out['referers'][$refHash]['last_ts']) {
                    $out['referers'][$refHash]['last_ts'] = $ts;
                }
            }

            // ── 小维度字典
            $browser = '';
            $os = '';
            if ($uaRaw !== '' && $spider === 0) {
                $parsed = UaParser::parse($uaRaw);
                $browser = $parsed['browser'];
                $os = $parsed['os'];
                if ($uaHash !== '') {
                    $out['uas'][$uaHash]['browser_code'] = $browser;
                    $out['uas'][$uaHash]['os_code'] = $os;
                }
            }
            self::dict($out['dicts'], Kind::D_BROWSER, $browser, $browser);
            self::dict($out['dicts'], Kind::D_OS, $os, $os);
            self::dict($out['dicts'], Kind::D_LANG, (string)($row['lang'] ?? ''), (string)($row['lang'] ?? ''));
            self::dict($out['dicts'], Kind::D_REF_HOST, $ref['host'], $ref['host']);
            self::dict($out['dicts'], Kind::D_ENGINE, $ref['engine'], $ref['engine_name']);
            self::dict($out['dicts'], Kind::D_KEYWORD, $ref['keyword'], $ref['keyword']);
            self::dict($out['dicts'], Kind::D_UTM_SOURCE, $ref['utm_source'], $ref['utm_source']);
            self::dict($out['dicts'], Kind::D_UTM_MEDIUM, $ref['utm_medium'], $ref['utm_medium']);
            self::dict($out['dicts'], Kind::D_UTM_CAMPAIGN, $ref['utm_campaign'], $ref['utm_campaign']);

            // ── 维度 PV 增量
            if ($countable) {
                self::agg($out['agg'], $day, Dim::PAGE, 'page:' . $pageHash, $ms);
                self::agg($out['agg'], $day, Dim::HOUR, 'int:' . $hour, $ms);
                self::agg($out['agg'], $day, Dim::STATUS, 'int:' . $status, $ms);
                if ($refHash !== '') {
                    self::agg($out['agg'], $day, Dim::REFERER, 'ref:' . $refHash, $ms);
                }
                if ($ref['keyword'] !== '') {
                    self::agg($out['agg'], $day, Dim::KEYWORD, 'dict:' . Kind::D_KEYWORD . ':' . $ref['keyword'], $ms);
                }
                if ((string)($row['lang'] ?? '') !== '') {
                    self::agg($out['agg'], $day, Dim::LANG, 'dict:' . Kind::D_LANG . ':' . $row['lang'], $ms);
                }
            }
            if ($isSpider) {
                self::agg($out['agg'], $day, Dim::SPIDER, 'int:' . $spider, $ms);
            }

            // ── 会话与访客（蜘蛛的会话只用于爬虫报表，不进 PV/UV 口径）
            $sid = (string)($row['sid'] ?? '');
            if ($sid !== '' && $vid !== '') {
                self::session($out['sessions'], $row, $sid, $vid, $ts, $day, $pageHash, $refHash, $ref, $browser, $os);
                self::visitor($out['visitors'], $vid, $row, $ts, $flags);
                self::online($out['online'], $vid, $sid, $row, $ts, $pageHash);
            }

            // ── 原始明细（仅采样命中的行）
            if (($flags & Kind::F_SAMPLED) !== 0) {
                $out['access'][] = [
                    'day' => $day,
                    'ts' => $ts,
                    'ms' => $ms,
                    'vid' => $vid,
                    'sid' => $sid,
                    'uid' => (int)($row['uid'] ?? 0),
                    'ip' => $ip,
                    'kind' => $kind,
                    'method' => (int)($row['method'] ?? Kind::M_GET),
                    'status' => $status,
                    'page_ref' => $pageHash,
                    'query' => mb_substr((string)($row['query'] ?? ''), 0, 180),
                    'ref_ref' => $refHash,
                    'ua_ref' => $uaHash,
                    'spider_id' => $spider,
                    'dev' => (int)($row['dev'] ?? 0),
                    'flags' => $flags,
                ];
            }
        }

        //同一个 (b,t,k) 在一批里可能出现很多次，先去重再交给 INSERT IGNORE，省掉大量无用绑定
        $out['seen'] = self::uniqueSeen($out['seen']);

        return $out;
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    /**
     * @param array<int,array<string,int>> $minutes
     * @return array<string,int>
     */
    private static function &bucket(array &$minutes, int $minute): array
    {
        if (!isset($minutes[$minute])) {
            $fresh = [];
            foreach (self::BUCKET_COLUMNS as $column) {
                $fresh[$column] = 0;
            }
            $minutes[$minute] = $fresh;
        }
        return $minutes[$minute];
    }

    /**
     * @param array<int,array<string,string>> $dicts
     */
    private static function dict(array &$dicts, int $type, string $code, string $name): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }
        $dicts[$type][mb_substr($code, 0, 64)] = mb_substr($name !== '' ? $name : $code, 0, 80);
    }

    /**
     * @param array<int,array<int,array<string,array<string,int>>>> $agg
     */
    private static function agg(array &$agg, int $day, int $dim, string $ref, int $ms): void
    {
        if (!isset($agg[$day][$dim][$ref])) {
            $agg[$day][$dim][$ref] = ['pv' => 0, 'ms_sum' => 0];
        }
        $agg[$day][$dim][$ref]['pv']++;
        $agg[$day][$dim][$ref]['ms_sum'] += $ms;
    }

    /**
     * @param array<string,array<string,mixed>> $sessions
     * @param array<string,mixed> $row
     * @param array<string,mixed> $ref
     */
    private static function session(
        array &$sessions,
        array $row,
        string $sid,
        string $vid,
        int $ts,
        int $day,
        string $pageHash,
        string $refHash,
        array $ref,
        string $browser,
        string $os
    ): void {
        //「首条请求」的排序键，越小越权威：
        //  0  = 采集时打了新会话标记，这一条百分百是本次访问的第一个请求
        //  ts = 没有标记，只能拿秒级时间戳凑合比
        // 光靠时间戳是不够的：同一秒内的多个请求会落到不同 spool 分片、以任意顺序入库，
        // 秒级精度根本分不出先后，结果就是把「从百度进站」记成「站内跳转」。
        $rank = ((int)($row['flags'] ?? 0) & Kind::F_NEW_SESSION) !== 0 ? 0 : $ts;

        if (!isset($sessions[$sid])) {
            $sessions[$sid] = [
                'sid' => $sid,
                'vid' => $vid,
                'day' => $day,
                'start_ts' => $ts,
                'end_ts' => $ts,
                'entry_rank' => $rank,
                'pv' => 0,
                'entry_ref' => $pageHash,
                'exit_ref' => $pageHash,
                'ref_ref' => $refHash,
                'ref_type' => (int)$ref['type'],
                'engine_code' => (string)$ref['engine'],
                'utm_source' => (string)$ref['utm_source'],
                'utm_medium' => (string)$ref['utm_medium'],
                'utm_campaign' => (string)$ref['utm_campaign'],
                'ip' => (string)($row['ip'] ?? ''),
                'ua_ref' => (string)($row['uahash'] ?? ''),
                'browser_code' => $browser,
                'os_code' => $os,
                'dev' => (int)($row['dev'] ?? 0),
                'spider_id' => (int)($row['spider'] ?? 0),
                'uid' => (int)($row['uid'] ?? 0),
                'is_new' => ((int)($row['flags'] ?? 0) & Kind::F_NEW_VISITOR) !== 0 ? 1 : 0,
            ];
        }
        $session = &$sessions[$sid];
        $session['pv']++;
        if ($ts < $session['start_ts']) {
            $session['start_ts'] = $ts;
        }
        if ($rank < $session['entry_rank']) {
            //更权威的首条：入口页、来源、搜索引擎、UTM、是否新访客都得跟着它走
            $session['entry_rank'] = $rank;
            $session['entry_ref'] = $pageHash;
            $session['ref_ref'] = $refHash;
            $session['ref_type'] = (int)$ref['type'];
            $session['engine_code'] = (string)$ref['engine'];
            $session['utm_source'] = (string)$ref['utm_source'];
            $session['utm_medium'] = (string)$ref['utm_medium'];
            $session['utm_campaign'] = (string)$ref['utm_campaign'];
            $session['is_new'] = ((int)($row['flags'] ?? 0) & Kind::F_NEW_VISITOR) !== 0 ? 1 : 0;
        }
        if ($ts >= $session['end_ts']) {
            $session['end_ts'] = $ts;
            $session['exit_ref'] = $pageHash;
        }
        if ((int)($row['uid'] ?? 0) > 0) {
            $session['uid'] = (int)$row['uid'];
        }
        unset($session);
    }

    /**
     * @param array<string,array<string,mixed>> $visitors
     * @param array<string,mixed> $row
     */
    private static function visitor(array &$visitors, string $vid, array $row, int $ts, int $flags): void
    {
        if (!isset($visitors[$vid])) {
            $visitors[$vid] = [
                'vid' => $vid,
                'first_ts' => $ts,
                'last_ts' => $ts,
                'pv' => 0,
                'sessions' => 0,
                'uid' => (int)($row['uid'] ?? 0),
                'ip' => (string)($row['ip'] ?? ''),
                'ua_ref' => (string)($row['uahash'] ?? ''),
                'id_kind' => ($flags & Kind::F_FINGERPRINT) !== 0 ? 1 : 0,
            ];
        }
        $visitor = &$visitors[$vid];
        $visitor['pv']++;
        if (($flags & Kind::F_NEW_SESSION) !== 0) {
            $visitor['sessions']++;
        }
        if ($ts < $visitor['first_ts']) {
            $visitor['first_ts'] = $ts;
        }
        if ($ts > $visitor['last_ts']) {
            $visitor['last_ts'] = $ts;
            $visitor['ip'] = (string)($row['ip'] ?? '');
        }
        if ((int)($row['uid'] ?? 0) > 0) {
            $visitor['uid'] = (int)$row['uid'];
        }
        unset($visitor);
    }

    /**
     * @param array<string,array<string,mixed>> $online
     * @param array<string,mixed> $row
     */
    private static function online(array &$online, string $vid, string $sid, array $row, int $ts, string $pageHash): void
    {
        if (!isset($online[$vid]) || $ts >= $online[$vid]['last_at']) {
            $online[$vid] = [
                'vid' => $vid,
                'sid' => $sid,
                'uid' => (int)($row['uid'] ?? 0),
                'ip' => (string)($row['ip'] ?? ''),
                'ua_ref' => (string)($row['uahash'] ?? ''),
                'spider_id' => (int)($row['spider'] ?? 0),
                'first_at' => $online[$vid]['first_at'] ?? $ts,
                'last_at' => $ts,
                'pv' => ($online[$vid]['pv'] ?? 0) + 1,
                'page_ref' => $pageHash,
                'entry_ref' => $online[$vid]['entry_ref'] ?? $pageHash,
            ];
            return;
        }
        $online[$vid]['pv']++;
    }

    /**
     * @param array<int,array<string,mixed>> $seen
     * @return array<int,array<string,mixed>>
     */
    private static function uniqueSeen(array $seen): array
    {
        $index = [];
        foreach ($seen as $item) {
            $index[$item['b'] . ':' . $item['t'] . ':' . $item['k']] = $item;
        }
        return array_values($index);
    }

    /**
     * @return array<string,string>
     */
    private static function parseQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }
        $out = [];
        parse_str($query, $out);
        $flat = [];
        foreach ($out as $key => $value) {
            if (is_scalar($value)) {
                $flat[(string)$key] = (string)$value;
            }
        }
        return $flat;
    }
}
