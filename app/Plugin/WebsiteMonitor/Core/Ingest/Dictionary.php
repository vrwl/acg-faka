<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Ingest;

use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;

/**
 * 把折叠结果里的「引用」（页面哈希、字典码…）解析成真正的自增 id。
 *
 * 做法：先 INSERT IGNORE 一批，再一次 SELECT 把 id 全部捞回来。
 * 加一层进程内 LRU，守护进程长期运行时命中率极高 —— 一个站点的页面和 UA 就那么多，
 * 跑几分钟之后基本不用再查库了。
 */
final class Dictionary
{
    /** 每类缓存上限，超了丢一半（简单有效，不值得为它上真 LRU） */
    private const CACHE_MAX = 4000;

    /** @var array<string,array<string,int>> */
    private static array $cache = [];

    /**
     * 解析一批折叠结果，返回各类引用到 id 的映射。
     *
     * @param array<string,mixed> $folded
     * @return array{pages:array<string,int>,referers:array<string,int>,uas:array<string,int>,dicts:array<string,int>}
     */
    public static function resolve(array $folded): array
    {
        return [
            'pages' => self::pages((array)$folded['pages']),
            'referers' => self::referers((array)$folded['referers']),
            'uas' => self::uas((array)$folded['uas'], (array)$folded['dicts']),
            'dicts' => self::dicts((array)$folded['dicts']),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $pages
     * @return array<string,int> 哈希 => id
     */
    public static function pages(array $pages): array
    {
        if ($pages === []) {
            return [];
        }
        $rows = [];
        $pending = [];
        foreach ($pages as $hash => $page) {
            $cached = self::cached('page', (string)$hash);
            if ($cached !== null) {
                //已存在，只更新 last_ts（便宜，且让「最近抓取时间」这类展示保持新鲜）
                $rows[] = [
                    'h' => (string)$hash,
                    'path' => mb_substr((string)$page['path'], 0, 500),
                    'title' => '',
                    'kind' => (int)$page['kind'],
                    'first_ts' => (int)$page['first_ts'],
                    'last_ts' => (int)$page['last_ts'],
                ];
                continue;
            }
            $pending[(string)$hash] = true;
            $rows[] = [
                'h' => (string)$hash,
                'path' => mb_substr((string)$page['path'], 0, 500),
                'title' => '',
                'kind' => (int)$page['kind'],
                'first_ts' => (int)$page['first_ts'],
                'last_ts' => (int)$page['last_ts'],
            ];
        }

        try {
            Db::upsertMany(Db::PAGE, $rows, [
                //first_ts 取更早的，last_ts 取更晚的
                'first_ts' => 'LEAST(`first_ts`, VALUES(`first_ts`))',
                'last_ts' => 'GREATEST(`last_ts`, VALUES(`last_ts`))',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Dictionary::pages', $e);
        }

        return self::fetchIds(Db::PAGE, 'h', array_keys($pages), 'page');
    }

    /**
     * @param array<string,array<string,mixed>> $referers
     * @return array<string,int>
     */
    public static function referers(array $referers): array
    {
        if ($referers === []) {
            return [];
        }
        //搜索引擎码要先变成 wm_dict 的 id
        $engineIds = self::dicts([\App\Plugin\WebsiteMonitor\Consts\Kind::D_ENGINE => array_column($referers, 'engine_code', 'engine_code')]);

        $rows = [];
        foreach ($referers as $hash => $ref) {
            $engineCode = (string)$ref['engine_code'];
            $rows[] = [
                'h' => (string)$hash,
                'host' => mb_substr((string)$ref['host'], 0, 120),
                'url' => mb_substr((string)$ref['url'], 0, 500),
                'type' => (int)$ref['type'],
                'engine_id' => $engineCode === '' ? 0 : (int)($engineIds[\App\Plugin\WebsiteMonitor\Consts\Kind::D_ENGINE . ':' . $engineCode] ?? 0),
                'keyword' => mb_substr((string)$ref['keyword'], 0, 120),
                'first_ts' => (int)$ref['first_ts'],
                'last_ts' => (int)$ref['last_ts'],
            ];
        }
        try {
            Db::upsertMany(Db::REFERER, $rows, [
                'first_ts' => 'LEAST(`first_ts`, VALUES(`first_ts`))',
                'last_ts' => 'GREATEST(`last_ts`, VALUES(`last_ts`))',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Dictionary::referers', $e);
        }
        return self::fetchIds(Db::REFERER, 'h', array_keys($referers), 'referer');
    }

    /**
     * @param array<string,array<string,mixed>> $uas
     * @param array<int,array<string,string>> $dictSeeds
     * @return array<string,int>
     */
    public static function uas(array $uas, array $dictSeeds): array
    {
        if ($uas === []) {
            return [];
        }
        $dictIds = self::dicts($dictSeeds);
        $rows = [];
        foreach ($uas as $hash => $ua) {
            $browser = (string)($ua['browser_code'] ?? '');
            $os = (string)($ua['os_code'] ?? '');
            $rows[] = [
                'h' => (string)$hash,
                'ua' => mb_substr((string)$ua['ua'], 0, 500),
                'browser_id' => $browser === '' ? 0 : (int)($dictIds[\App\Plugin\WebsiteMonitor\Consts\Kind::D_BROWSER . ':' . $browser] ?? 0),
                'os_id' => $os === '' ? 0 : (int)($dictIds[\App\Plugin\WebsiteMonitor\Consts\Kind::D_OS . ':' . $os] ?? 0),
                'dev' => (int)($ua['dev'] ?? 0),
                'spider_id' => (int)($ua['spider_id'] ?? 0),
                'last_ts' => (int)($ua['last_ts'] ?? time()),
            ];
        }
        try {
            Db::upsertMany(Db::UA, $rows, [
                //UA 全文只在新会话时才带，所以已有非空值时别用空串覆盖它
                'ua' => 'IF(VALUES(`ua`) = \'\', `ua`, VALUES(`ua`))',
                'browser_id' => 'IF(VALUES(`browser_id`) = 0, `browser_id`, VALUES(`browser_id`))',
                'os_id' => 'IF(VALUES(`os_id`) = 0, `os_id`, VALUES(`os_id`))',
                'last_ts' => 'GREATEST(`last_ts`, VALUES(`last_ts`))',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Dictionary::uas', $e);
        }
        return self::fetchIds(Db::UA, 'h', array_keys($uas), 'ua');
    }

    /**
     * @param array<int,array<string,string>> $dicts type => (code => name)
     * @return array<string,int> "type:code" => id
     */
    public static function dicts(array $dicts): array
    {
        $rows = [];
        $wanted = [];
        foreach ($dicts as $type => $items) {
            foreach ((array)$items as $code => $name) {
                $code = trim((string)$code);
                if ($code === '') {
                    continue;
                }
                $rows[] = [
                    'type' => (int)$type,
                    'code' => mb_substr($code, 0, 64),
                    'name' => mb_substr((string)$name !== '' ? (string)$name : $code, 0, 80),
                ];
                $wanted[] = ['type' => (int)$type, 'code' => mb_substr($code, 0, 64)];
            }
        }
        if ($rows === []) {
            return [];
        }
        try {
            Db::upsertMany(Db::DICT, $rows, [
                'name' => 'IF(VALUES(`name`) = \'\', `name`, VALUES(`name`))',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Dictionary::dicts', $e);
        }

        $out = [];
        try {
            //按 type 分组查，避免拼一个巨大的 OR 条件
            $byType = [];
            foreach ($wanted as $item) {
                $byType[$item['type']][] = $item['code'];
            }
            foreach ($byType as $type => $codes) {
                foreach (array_chunk(array_values(array_unique($codes)), 500) as $chunk) {
                    $found = Db::table(Db::DICT)->where('type', $type)->whereIn('code', $chunk)->get(['id', 'code']);
                    foreach ($found as $row) {
                        $out[$type . ':' . $row->code] = (int)$row->id;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::exception('Dictionary::dicts::fetch', $e);
        }
        return $out;
    }

    /**
     * 地区字典：country/province/city 三元组 => id
     *
     * @param array<int,array{country:string,country_name:string,province:string,city:string,continent:string}> $regions
     * @return array<string,int> "country|province|city" => id
     */
    public static function regions(array $regions): array
    {
        if ($regions === []) {
            return [];
        }
        $rows = [];
        $keys = [];
        foreach ($regions as $region) {
            $country = mb_substr((string)($region['country'] ?? ''), 0, 2);
            $province = mb_substr((string)($region['province'] ?? ''), 0, 48);
            $city = mb_substr((string)($region['city'] ?? ''), 0, 48);
            $key = $country . '|' . $province . '|' . $city;
            if (isset($keys[$key])) {
                continue;
            }
            $keys[$key] = true;
            $rows[] = [
                'country' => $country,
                'country_name' => mb_substr((string)($region['country_name'] ?? ''), 0, 48),
                'province' => $province,
                'city' => $city,
                'continent' => mb_substr((string)($region['continent'] ?? ''), 0, 2),
            ];
        }
        try {
            Db::upsertMany(Db::REGION, $rows, [
                'country_name' => 'IF(VALUES(`country_name`) = \'\', `country_name`, VALUES(`country_name`))',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Dictionary::regions', $e);
        }

        $out = [];
        try {
            foreach (array_chunk($rows, 300) as $chunk) {
                $query = Db::table(Db::REGION);
                $query->where(static function ($q) use ($chunk): void {
                    foreach ($chunk as $row) {
                        $q->orWhere(static function ($sub) use ($row): void {
                            $sub->where('country', $row['country'])
                                ->where('province', $row['province'])
                                ->where('city', $row['city']);
                        });
                    }
                });
                foreach ($query->get(['id', 'country', 'province', 'city']) as $row) {
                    $out[$row->country . '|' . $row->province . '|' . $row->city] = (int)$row->id;
                }
            }
        } catch (\Throwable $e) {
            Log::exception('Dictionary::regions::fetch', $e);
        }
        return $out;
    }

    /**
     * @param string[] $hashes
     * @return array<string,int>
     */
    private static function fetchIds(string $table, string $column, array $hashes, string $cacheKey): array
    {
        $out = [];
        $missing = [];
        foreach ($hashes as $hash) {
            $cached = self::cached($cacheKey, (string)$hash);
            if ($cached !== null) {
                $out[(string)$hash] = $cached;
            } else {
                $missing[] = (string)$hash;
            }
        }
        if ($missing === []) {
            return $out;
        }
        try {
            foreach (array_chunk($missing, 500) as $chunk) {
                foreach (Db::table($table)->whereIn($column, $chunk)->get(['id', $column]) as $row) {
                    $id = (int)$row->id;
                    $key = (string)$row->{$column};
                    $out[$key] = $id;
                    self::remember($cacheKey, $key, $id);
                }
            }
        } catch (\Throwable $e) {
            Log::exception('Dictionary::fetchIds', $e, ['table' => $table]);
        }
        return $out;
    }

    private static function cached(string $bucket, string $key): ?int
    {
        return self::$cache[$bucket][$key] ?? null;
    }

    private static function remember(string $bucket, string $key, int $id): void
    {
        if (!isset(self::$cache[$bucket])) {
            self::$cache[$bucket] = [];
        }
        if (count(self::$cache[$bucket]) >= self::CACHE_MAX) {
            self::$cache[$bucket] = array_slice(self::$cache[$bucket], (int)(self::CACHE_MAX / 2), null, true);
        }
        self::$cache[$bucket][$key] = $id;
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
