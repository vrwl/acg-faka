<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Query;

use App\Plugin\WebsiteMonitor\Consts\Dim;
use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Collect\Classify;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Referer\Parser as RefererParser;

/**
 * 维度 TOP-N。面板上所有排行榜（页面、来源、地域、浏览器、蜘蛛…）都走这一条路径。
 *
 * 全部命中 wm_agg 的 idx_day_dim_pv / idx_day_dim_visits，
 * 扫描行数 = 天数 × 该维度基数，永远是毫秒级。
 */
final class Dimensions
{
    /** key_id 指向哪张字典表 */
    private const DICT_TABLE = [
        Dim::PAGE => Db::PAGE,
        Dim::LANDING => Db::PAGE,
        Dim::EXIT => Db::PAGE,
        Dim::REFERER => Db::REFERER,
        Dim::SPIDER => Db::SPIDER,
        Dim::REGION => Db::REGION,
    ];

    /** 这些维度的 key_id 是枚举值，不查字典 */
    private const ENUM_DIMS = [Dim::DEVICE, Dim::HOUR, Dim::STATUS, Dim::REF_TYPE];

    /**
     * @param int $sort 0 = 按 pv，1 = 按 visits
     * @return array<int,array<string,mixed>>
     */
    public static function top(int $dim, int $fromDay, int $toDay, int $limit = 20, int $sort = 0): array
    {
        try {
            $orderBy = $sort === 1 ? 'visits' : 'pv';
            $rows = Db::table(Db::AGG)
                ->whereBetween('day', [$fromDay, $toDay])
                ->where('dim', $dim)
                ->selectRaw('key_id, SUM(pv) pv, SUM(visits) visits, SUM(uv) uv, SUM(bounce) bounce,
                             SUM(ms_sum) ms_sum, SUM(stay_sum) stay_sum')
                ->groupBy('key_id')
                ->orderByDesc($orderBy === 'visits' ? 'visits' : 'pv')
                ->limit(max(1, min(200, $limit)))
                ->get();

            $items = [];
            $keyIds = [];
            foreach ($rows as $row) {
                $keyIds[] = (int)$row->key_id;
                $items[] = [
                    'key_id' => (int)$row->key_id,
                    'pv' => (int)$row->pv,
                    'visits' => (int)$row->visits,
                    'uv' => (int)$row->uv,
                    'bounce' => (int)$row->bounce,
                    'bounce_rate' => (int)$row->visits > 0 ? round((int)$row->bounce / (int)$row->visits * 100, 1) : 0.0,
                    'avg_ms' => (int)$row->pv > 0 ? (int)round((int)$row->ms_sum / (int)$row->pv) : 0,
                    'avg_stay' => (int)$row->visits > 0 ? (int)round((int)$row->stay_sum / (int)$row->visits) : 0,
                ];
            }
            if ($items === []) {
                return [];
            }

            $labels = self::labels($dim, $keyIds);
            $total = 0;
            foreach ($items as $item) {
                $total += $item[$sort === 1 ? 'visits' : 'pv'];
            }
            foreach ($items as $i => $item) {
                $label = $labels[$item['key_id']] ?? null;
                $items[$i]['name'] = $label['name'] ?? ('#' . $item['key_id']);
                $items[$i]['extra'] = $label['extra'] ?? '';
                $value = $item[$sort === 1 ? 'visits' : 'pv'];
                $items[$i]['percent'] = $total > 0 ? round($value / $total * 100, 1) : 0.0;
            }
            return $items;
        } catch (\Throwable $e) {
            Log::exception('Dimensions::top', $e, ['dim' => $dim]);
            return [];
        }
    }

    /**
     * key_id => 展示名
     *
     * @param int[] $keyIds
     * @return array<int,array{name:string,extra:string}>
     */
    private static function labels(int $dim, array $keyIds): array
    {
        $keyIds = array_values(array_unique(array_filter($keyIds, static fn(int $n): bool => $n >= 0)));
        if ($keyIds === []) {
            return [];
        }

        //枚举维度：不查库，直接翻译
        if (in_array($dim, self::ENUM_DIMS, true)) {
            $out = [];
            foreach ($keyIds as $id) {
                $out[$id] = ['name' => self::enumLabel($dim, $id), 'extra' => ''];
            }
            return $out;
        }

        try {
            //字典维度
            $table = self::DICT_TABLE[$dim] ?? Db::DICT;
            $rows = Db::table($table)->whereIn('id', $keyIds)->get();
            $out = [];
            foreach ($rows as $row) {
                $id = (int)$row->id;
                if ($table === Db::PAGE) {
                    $out[$id] = ['name' => (string)$row->path, 'extra' => (string)$row->title];
                } elseif ($table === Db::REFERER) {
                    $out[$id] = ['name' => (string)$row->url, 'extra' => (string)$row->host];
                } elseif ($table === Db::SPIDER) {
                    $out[$id] = ['name' => (string)$row->name, 'extra' => (string)$row->code];
                } elseif ($table === Db::REGION) {
                    $parts = array_values(array_filter([
                        (string)$row->country_name, (string)$row->province, (string)$row->city,
                    ], static fn(string $s): bool => trim($s) !== ''));
                    $out[$id] = [
                        'name' => $parts === [] ? lang('未知') : implode(' · ', $parts),
                        'extra' => (string)$row->country,
                    ];
                } else {
                    $out[$id] = ['name' => (string)$row->name ?: (string)$row->code, 'extra' => (string)$row->code];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            Log::exception('Dimensions::labels', $e, ['dim' => $dim]);
            return [];
        }
    }

    private static function enumLabel(int $dim, int $value): string
    {
        return match ($dim) {
            Dim::DEVICE => Classify::deviceText($value),
            Dim::HOUR => sprintf('%02d:00', $value),
            Dim::STATUS => (string)$value,
            Dim::REF_TYPE => RefererParser::typeText($value),
            default => (string)$value,
        };
    }

    /**
     * 来源分析整页
     *
     * @return array<string,mixed>
     */
    public static function sources(Range $range, int $limit = 20): array
    {
        return [
            'types' => self::top(Dim::REF_TYPE, $range->fromDay, $range->toDay, 10, 1),
            'platforms' => self::top(Dim::ENGINE, $range->fromDay, $range->toDay, $limit, 1),
            'hosts' => self::top(Dim::REF_HOST, $range->fromDay, $range->toDay, $limit, 1),
            'urls' => self::top(Dim::REFERER, $range->fromDay, $range->toDay, $limit),
            'keywords' => self::top(Dim::KEYWORD, $range->fromDay, $range->toDay, $limit),
            'utm' => [
                'source' => self::top(Dim::UTM_SOURCE, $range->fromDay, $range->toDay, 10, 1),
                'medium' => self::top(Dim::UTM_MEDIUM, $range->fromDay, $range->toDay, 10, 1),
                'campaign' => self::top(Dim::UTM_CAMPAIGN, $range->fromDay, $range->toDay, 10, 1),
            ],
        ];
    }

    /**
     * 页面分析整页
     *
     * @return array<string,mixed>
     */
    public static function pages(Range $range, int $limit = 30): array
    {
        return [
            'pages' => self::top(Dim::PAGE, $range->fromDay, $range->toDay, $limit),
            'landing' => self::top(Dim::LANDING, $range->fromDay, $range->toDay, $limit, 1),
            'exit' => self::top(Dim::EXIT, $range->fromDay, $range->toDay, $limit, 1),
            'status' => self::top(Dim::STATUS, $range->fromDay, $range->toDay, 12),
            'notfound' => self::notFound($range, $limit),
            'slow' => self::slowPages($range, 15),
        ];
    }

    /**
     * 环境分析（浏览器 / 系统 / 设备 / 语言）
     *
     * @return array<string,mixed>
     */
    public static function environment(Range $range, int $limit = 12): array
    {
        return [
            'browser' => self::top(Dim::BROWSER, $range->fromDay, $range->toDay, $limit, 1),
            'os' => self::top(Dim::OS, $range->fromDay, $range->toDay, $limit, 1),
            'device' => self::top(Dim::DEVICE, $range->fromDay, $range->toDay, 8, 1),
            'lang' => self::top(Dim::LANG, $range->fromDay, $range->toDay, $limit),
        ];
    }

    /**
     * 地域分析
     *
     * @return array<string,mixed>
     */
    public static function regions(Range $range, int $limit = 60): array
    {
        $rows = self::top(Dim::REGION, $range->fromDay, $range->toDay, $limit, 1);

        //按国家汇总，供世界地图用
        $byCountry = [];
        foreach ($rows as $row) {
            $code = (string)($row['extra'] ?? '');
            if ($code === '') {
                continue;
            }
            if (!isset($byCountry[$code])) {
                $byCountry[$code] = ['code' => $code, 'name' => explode(' · ', (string)$row['name'])[0], 'visits' => 0, 'uv' => 0];
            }
            $byCountry[$code]['visits'] += (int)$row['visits'];
            $byCountry[$code]['uv'] += (int)$row['uv'];
        }
        usort($byCountry, static fn(array $a, array $b): int => $b['visits'] <=> $a['visits']);

        return [
            'detail' => $rows,
            'countries' => array_values($byCountry),
            'max' => $byCountry === [] ? 0 : (int)$byCountry[0]['visits'],
        ];
    }

    /**
     * 蜘蛛分析
     *
     * @return array<string,mixed>
     */
    public static function spiders(Range $range, int $limit = 30): array
    {
        $rows = self::top(Dim::SPIDER, $range->fromDay, $range->toDay, $limit);
        $groups = [];
        try {
            foreach (Db::table(Db::SPIDER)->get(['id', 'grp', 'icon']) as $row) {
                $groups[(int)$row->id] = ['grp' => (int)$row->grp, 'icon' => (string)$row->icon];
            }
        } catch (\Throwable $e) {
        }
        foreach ($rows as $i => $row) {
            $meta = $groups[(int)$row['key_id']] ?? ['grp' => Kind::SP_OTHER, 'icon' => ''];
            $rows[$i]['group'] = $meta['grp'];
            $rows[$i]['group_text'] = self::spiderGroupText((int)$meta['grp']);
            $rows[$i]['icon'] = $meta['icon'];
        }
        return ['list' => $rows, 'fake' => self::fakeSpiders(20)];
    }

    public static function spiderGroupText(int $group): string
    {
        return match ($group) {
            Kind::SP_SEARCH => lang('搜索引擎'),
            Kind::SP_SEO => lang('SEO 工具'),
            Kind::SP_AI => lang('AI 抓取'),
            Kind::SP_SOCIAL => lang('社交预览'),
            Kind::SP_MONITOR => lang('监控拨测'),
            Kind::SP_SCANNER => lang('安全扫描'),
            default => lang('其它'),
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function fakeSpiders(int $limit): array
    {
        try {
            $rows = Db::table(Db::SPIDER_IP . ' as si')
                ->leftJoin(Db::SPIDER . ' as s', 's.id', '=', 'si.spider_id')
                ->where('si.verified', Kind::SPV_FAKE)
                ->orderByDesc('si.checked_at')
                ->limit($limit)
                ->get(['si.ip', 'si.ptr', 'si.checked_at', 's.name as spider_name']);
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'ip' => (string)$row->ip,
                    'claimed' => (string)($row->spider_name ?? ''),
                    'ptr' => (string)$row->ptr,
                    'checked_at' => (int)$row->checked_at,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 404 页面 —— 直接指向死链，站长最需要的一张表
     *
     * @return array<int,array<string,mixed>>
     */
    private static function notFound(Range $range, int $limit): array
    {
        try {
            $rows = Db::table(Db::ACCESS . ' as a')
                ->leftJoin(Db::PAGE . ' as p', 'p.id', '=', 'a.page_id')
                ->where('a.ts', '>=', $range->from)->where('a.ts', '<=', $range->to)
                ->where('a.status', 404)
                ->selectRaw('p.path as path, COUNT(*) n, MAX(a.ts) last_ts')
                ->groupBy('a.page_id', 'p.path')
                ->orderByDesc('n')->limit($limit)->get();
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'path' => (string)($row->path ?? ''),
                    'count' => (int)$row->n,
                    'last_ts' => (int)$row->last_ts,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 慢页面
     *
     * @return array<int,array<string,mixed>>
     */
    private static function slowPages(Range $range, int $limit): array
    {
        try {
            $rows = Db::table(Db::AGG . ' as g')
                ->join(Db::PAGE . ' as p', 'p.id', '=', 'g.key_id')
                ->whereBetween('g.day', [$range->fromDay, $range->toDay])
                ->where('g.dim', Dim::PAGE)
                ->selectRaw('p.path as path, SUM(g.pv) pv, SUM(g.ms_sum) ms_sum')
                ->groupBy('g.key_id', 'p.path')
                ->havingRaw('SUM(g.pv) >= 5')
                ->orderByRaw('SUM(g.ms_sum) / GREATEST(1, SUM(g.pv)) DESC')
                ->limit($limit)->get();
            $out = [];
            foreach ($rows as $row) {
                $pv = max(1, (int)$row->pv);
                $out[] = [
                    'path' => (string)$row->path,
                    'pv' => (int)$row->pv,
                    'avg_ms' => (int)round((int)$row->ms_sum / $pv),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
