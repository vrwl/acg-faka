<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Ingest;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;

/**
 * 会话、访客、在线的合并入库。
 *
 * 一次访问（会话）会跨多个批次陆续到达，所以这里全是 upsert：
 * start_ts 取更早的、end_ts 取更晚的、pv 累加、入口页只在第一次写、出口页每次覆盖。
 */
final class Sessions
{
    /**
     * @param array<string,mixed> $folded
     * @param array<string,array<string,int>> $ids
     */
    public static function merge(array $folded, array $ids): void
    {
        self::sessions((array)$folded['sessions'], $ids);
        self::visitors((array)$folded['visitors'], $ids);
        self::online((array)$folded['online'], $ids);
    }

    /**
     * @param array<string,array<string,mixed>> $sessions
     * @param array<string,array<string,int>> $ids
     */
    private static function sessions(array $sessions, array $ids): void
    {
        if ($sessions === []) {
            return;
        }
        $rows = [];
        foreach ($sessions as $session) {
            $engineCode = (string)$session['engine_code'];
            $rows[] = [
                'sid' => (string)$session['sid'],
                'vid' => (string)$session['vid'],
                'day' => (int)$session['day'],
                'start_ts' => (int)$session['start_ts'],
                'end_ts' => (int)$session['end_ts'],
                'entry_rank' => (int)($session['entry_rank'] ?? $session['start_ts']),
                'pv' => (int)$session['pv'],
                'entry_page_id' => self::pageId($session['entry_ref'], $ids),
                'exit_page_id' => self::pageId($session['exit_ref'], $ids),
                'ref_id' => self::refId($session['ref_ref'], $ids),
                'ref_type' => (int)$session['ref_type'],
                'engine_id' => self::dictId(Kind::D_ENGINE, $engineCode, $ids),
                'utm_source_id' => self::dictId(Kind::D_UTM_SOURCE, (string)$session['utm_source'], $ids),
                'utm_medium_id' => self::dictId(Kind::D_UTM_MEDIUM, (string)$session['utm_medium'], $ids),
                'utm_campaign_id' => self::dictId(Kind::D_UTM_CAMPAIGN, (string)$session['utm_campaign'], $ids),
                'ip' => (string)$session['ip'],
                'region_id' => self::regionId((string)$session['ip'], $ids),
                'ua_id' => self::uaId($session['ua_ref'], $ids),
                'browser_id' => self::dictId(Kind::D_BROWSER, (string)$session['browser_code'], $ids),
                'os_id' => self::dictId(Kind::D_OS, (string)$session['os_code'], $ids),
                'dev' => (int)$session['dev'],
                'spider_id' => (int)$session['spider_id'],
                'uid' => (int)$session['uid'],
                //跳出 = 整个会话只看了一个页面。pv 累加后由下面的表达式重算
                'is_bounce' => 1,
                'is_new' => (int)$session['is_new'],
            ];
        }
        try {
            //两条规则决定了这组表达式的写法：
            //  1. MySQL 的 ON DUPLICATE KEY UPDATE 按左到右求值，后面的表达式会看到**已更新**的列值，
            //     所以凡是依赖旧值的都必须排在 start_ts / end_ts / pv 的赋值之前。
            //  2. 会话的「首次属性」（入口页、来源、搜索引擎、UTM、是否新访客）按 entry_rank 取胜者，
            //     不看谁先入库 —— spool 是分片写的，同一会话的请求会以任意顺序被消费。
            //     entry_rank=0 表示这条带着新会话标记、确定是首个请求；否则退化成秒级时间戳比较。
            //     这样判定与入库顺序无关，天然幂等，重放同一批结果不变。
            $earlier = static fn(string $col): string =>
                'IF(VALUES(`entry_rank`) < `entry_rank`, VALUES(`' . $col . '`), `' . $col . '`)';

            Db::upsertMany(Db::SESSION, $rows, [
                'entry_page_id' => $earlier('entry_page_id'),
                'ref_id' => $earlier('ref_id'),
                'ref_type' => $earlier('ref_type'),
                'engine_id' => $earlier('engine_id'),
                'utm_source_id' => $earlier('utm_source_id'),
                'utm_medium_id' => $earlier('utm_medium_id'),
                'utm_campaign_id' => $earlier('utm_campaign_id'),
                'is_new' => $earlier('is_new'),
                //出口页跟着更晚的那次走
                'exit_page_id' => 'IF(VALUES(`end_ts`) >= `end_ts`, VALUES(`exit_page_id`), `exit_page_id`)',
                //会话内浏览超过 1 页就不算跳出（此处 pv 还是旧值，所以要加上本批的增量）
                'is_bounce' => 'IF(`pv` + VALUES(`pv`) > 1, 0, 1)',
                //entry_rank 必须排在用到它的表达式之后，否则上面那批比较拿到的是新值
                'entry_rank' => 'LEAST(`entry_rank`, VALUES(`entry_rank`))',
                'start_ts' => 'LEAST(`start_ts`, VALUES(`start_ts`))',
                'end_ts' => 'GREATEST(`end_ts`, VALUES(`end_ts`))',
                'pv' => '`pv` + VALUES(`pv`)',
                'uid' => 'IF(VALUES(`uid`) > 0, VALUES(`uid`), `uid`)',
                'ua_id' => 'IF(VALUES(`ua_id`) > 0, VALUES(`ua_id`), `ua_id`)',
                'region_id' => 'IF(VALUES(`region_id`) > 0, VALUES(`region_id`), `region_id`)',
                'browser_id' => 'IF(VALUES(`browser_id`) > 0, VALUES(`browser_id`), `browser_id`)',
                'os_id' => 'IF(VALUES(`os_id`) > 0, VALUES(`os_id`), `os_id`)',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Sessions::sessions', $e);
        }
    }

    /**
     * @param array<string,array<string,mixed>> $visitors
     * @param array<string,array<string,int>> $ids
     */
    private static function visitors(array $visitors, array $ids): void
    {
        if ($visitors === []) {
            return;
        }
        $rows = [];
        foreach ($visitors as $visitor) {
            $rows[] = [
                'vid' => (string)$visitor['vid'],
                'first_ts' => (int)$visitor['first_ts'],
                'last_ts' => (int)$visitor['last_ts'],
                'pv' => (int)$visitor['pv'],
                'sessions' => (int)$visitor['sessions'],
                'uid' => (int)$visitor['uid'],
                'ip' => (string)$visitor['ip'],
                'region_id' => self::regionId((string)$visitor['ip'], $ids),
                'ua_id' => self::uaId($visitor['ua_ref'], $ids),
                'id_kind' => (int)$visitor['id_kind'],
                'note' => '',
                'tag' => 0,
            ];
        }
        try {
            Db::upsertMany(Db::VISITOR, $rows, [
                //ip 要在 last_ts 更新之前判，否则比较的是已经被抬高过的值
                'ip' => 'IF(VALUES(`last_ts`) >= `last_ts`, VALUES(`ip`), `ip`)',
                'first_ts' => 'LEAST(`first_ts`, VALUES(`first_ts`))',
                'last_ts' => 'GREATEST(`last_ts`, VALUES(`last_ts`))',
                'pv' => '`pv` + VALUES(`pv`)',
                'sessions' => '`sessions` + VALUES(`sessions`)',
                'uid' => 'IF(VALUES(`uid`) > 0, VALUES(`uid`), `uid`)',
                'ua_id' => 'IF(VALUES(`ua_id`) > 0, VALUES(`ua_id`), `ua_id`)',
                'region_id' => 'IF(VALUES(`region_id`) > 0, VALUES(`region_id`), `region_id`)',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Sessions::visitors', $e);
        }
    }

    /**
     * @param array<string,array<string,mixed>> $online
     * @param array<string,array<string,int>> $ids
     */
    private static function online(array $online, array $ids): void
    {
        if ($online === []) {
            return;
        }
        $rows = [];
        foreach ($online as $item) {
            $rows[] = [
                'vid' => (string)$item['vid'],
                'sid' => (string)$item['sid'],
                'uid' => (int)$item['uid'],
                'ip' => (string)$item['ip'],
                'region_id' => self::regionId((string)$item['ip'], $ids),
                'ua_id' => self::uaId($item['ua_ref'], $ids),
                'spider_id' => (int)$item['spider_id'],
                'first_at' => (int)$item['first_at'],
                'last_at' => (int)$item['last_at'],
                'pv' => (int)$item['pv'],
                'page_id' => self::pageId($item['page_ref'], $ids),
                'entry_page_id' => self::pageId($item['entry_ref'], $ids),
            ];
        }
        try {
            Db::upsertMany(Db::ONLINE, $rows, [
                //当前页要在 last_at 更新之前判
                'page_id' => 'IF(VALUES(`last_at`) >= `last_at`, VALUES(`page_id`), `page_id`)',
                'sid' => 'VALUES(`sid`)',
                'uid' => 'IF(VALUES(`uid`) > 0, VALUES(`uid`), `uid`)',
                'ip' => 'VALUES(`ip`)',
                'ua_id' => 'IF(VALUES(`ua_id`) > 0, VALUES(`ua_id`), `ua_id`)',
                'region_id' => 'IF(VALUES(`region_id`) > 0, VALUES(`region_id`), `region_id`)',
                'first_at' => 'LEAST(`first_at`, VALUES(`first_at`))',
                'last_at' => 'GREATEST(`last_at`, VALUES(`last_at`))',
                'pv' => '`pv` + VALUES(`pv`)',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Sessions::online', $e);
        }
    }

    /**
     * IP 画像批量更新
     *
     * @param array<string,array<string,mixed>> $ips
     * @param array<string,array<string,int>> $ids
     */
    public static function ipProfiles(array $ips, array $ids = []): void
    {
        if ($ips === []) {
            return;
        }
        $rows = [];
        foreach ($ips as $ip => $profile) {
            $rows[] = [
                'ip' => (string)$ip,
                'first_at' => (int)$profile['first_at'],
                'last_at' => (int)$profile['last_at'],
                'req' => (int)$profile['req'],
                'atk' => (int)$profile['atk'],
                'ban_cnt' => 0,
                'last_rule' => mb_substr((string)$profile['last_rule'], 0, 48),
                'region_id' => self::regionId((string)$ip, $ids),
                'spider_id' => (int)$profile['spider_id'],
                'ua_id' => 0,
                'note' => '',
            ];
        }
        try {
            Db::upsertMany(Db::IP, $rows, [
                'first_at' => 'LEAST(`first_at`, VALUES(`first_at`))',
                'last_at' => 'GREATEST(`last_at`, VALUES(`last_at`))',
                'req' => '`req` + VALUES(`req`)',
                'atk' => '`atk` + VALUES(`atk`)',
                'last_rule' => 'IF(VALUES(`last_rule`) = \'\', `last_rule`, VALUES(`last_rule`))',
                'spider_id' => 'IF(VALUES(`spider_id`) > 0, VALUES(`spider_id`), `spider_id`)',
                'region_id' => 'IF(VALUES(`region_id`) > 0, VALUES(`region_id`), `region_id`)',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Sessions::ipProfiles', $e);
        }
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    /**
     * IP → 地区字典 ID。查不到就是 0（IP 库没就绪时的正常情况），upsert 表达式会保住已有值。
     *
     * @param array<string,array<string,int>> $ids
     */
    private static function regionId(string $ip, array $ids): int
    {
        return (int)(($ids['ip_region'] ?? [])[$ip] ?? 0);
    }

    private static function pageId(mixed $ref, array $ids): int
    {
        $ref = (string)$ref;
        return $ref === '' ? 0 : (int)($ids['pages'][$ref] ?? 0);
    }

    private static function refId(mixed $ref, array $ids): int
    {
        $ref = (string)$ref;
        return $ref === '' ? 0 : (int)($ids['referers'][$ref] ?? 0);
    }

    private static function uaId(mixed $ref, array $ids): int
    {
        $ref = (string)$ref;
        return $ref === '' ? 0 : (int)($ids['uas'][$ref] ?? 0);
    }

    private static function dictId(int $type, string $code, array $ids): int
    {
        $code = trim($code);
        return $code === '' ? 0 : (int)($ids['dicts'][$type . ':' . $code] ?? 0);
    }
}
