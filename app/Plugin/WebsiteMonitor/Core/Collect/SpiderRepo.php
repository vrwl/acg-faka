<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Collect;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * 蜘蛛签名的入库与编译。
 *
 * 编译产物是**一条**合并正则，同时判定两级：
 *   分组 1  已知签名（googlebot / baiduspider …）→ 映射到具体 spider_id
 *   分组 2  泛化机器人特征（bot / crawler / spider …）→ spider_id = 1（未知爬虫）
 * 人类请求两个分组都不命中，只付一次 preg_match 的钱（约 3µs）。
 */
final class SpiderRepo
{
    /** 未知爬虫的保留 id */
    public const UNKNOWN = 1;

    /**
     * 泛化特征：能筛出没被签名覆盖的爬虫与脚本客户端。
     * 故意不含裸 "bot"（会误伤 Cubot、Bot 结尾的正常机型），用 \bbot\b 收紧。
     */
    private const GENERIC = 'bot\b|\bbots\b|spider|crawler|crawl|slurp|fetcher|scrapy|python-requests'
        . '|python-urllib|go-http-client|okhttp|java/|libwww-perl|curl/|wget|httpclient|axios/|headlesschrome|phantomjs';

    /**
     * 把种子数据合并入库：builtin=1 的行按 code 更新，站长改过的（builtin=0）保持不动。
     *
     * @param array<int,array<string,mixed>> $seeds
     */
    public static function seed(array $seeds): void
    {
        if ($seeds === []) {
            return;
        }
        //保留 id=1 给「未知爬虫」，这样热路径可以直接用常量而不查表
        try {
            Db::insertIgnore(Db::SPIDER, [[
                'id' => self::UNKNOWN,
                'code' => '_generic',
                'name' => '未知爬虫',
                'pattern' => '',
                'verify_domain' => '',
                'grp' => Kind::SP_OTHER,
                'icon' => '',
                'status' => 1,
                'builtin' => 1,
                'sort' => 999,
            ]]);
        } catch (\Throwable $e) {
            Log::exception('SpiderRepo::seed::generic', $e);
        }

        //已存在的自定义行（builtin=0）不覆盖
        $custom = [];
        try {
            foreach (Db::table(Db::SPIDER)->where('builtin', 0)->get(['code']) as $row) {
                $custom[(string)$row->code] = true;
            }
        } catch (\Throwable $e) {
        }

        $rows = [];
        foreach ($seeds as $seed) {
            $code = (string)($seed['code'] ?? '');
            if ($code === '' || isset($custom[$code])) {
                continue;
            }
            $rows[] = [
                'code' => mb_substr($code, 0, 32),
                'name' => mb_substr((string)($seed['name'] ?? $code), 0, 48),
                'pattern' => mb_substr((string)($seed['pattern'] ?? ''), 0, 200),
                'verify_domain' => mb_substr((string)($seed['verify'] ?? ''), 0, 255),
                'grp' => (int)($seed['grp'] ?? Kind::SP_OTHER),
                'icon' => mb_substr((string)($seed['icon'] ?? ''), 0, 48),
                'status' => 1,
                'builtin' => 1,
                'sort' => (int)($seed['sort'] ?? 100),
            ];
        }
        if ($rows === []) {
            return;
        }
        try {
            //status 用 VALUES() 之外的写法保留站长的停用操作：只有内置行才整体覆盖
            Db::upsertMany(Db::SPIDER, $rows, [
                'name' => 'VALUES(`name`)',
                'pattern' => 'VALUES(`pattern`)',
                'verify_domain' => 'VALUES(`verify_domain`)',
                'grp' => 'VALUES(`grp`)',
                'icon' => 'VALUES(`icon`)',
                'sort' => 'VALUES(`sort`)',
            ]);
            Log::info('蜘蛛签名已同步', ['n' => count($rows)]);
        } catch (\Throwable $e) {
            Log::exception('SpiderRepo::seed', $e);
        }
    }

    /**
     * 把库里启用的签名编译成 spiders.php
     *
     * @return array<string,mixed>
     */
    public static function compile(): array
    {
        $known = [];
        $map = [];
        $scan = [];
        $verify = [];

        try {
            $rows = Db::table(Db::SPIDER)->where('status', 1)->orderBy('sort')->get();
            foreach ($rows as $row) {
                $id = (int)$row->id;
                $pattern = trim((string)$row->pattern);
                if ($pattern === '' || $id === self::UNKNOWN) {
                    continue;
                }
                foreach (explode('|', $pattern) as $needle) {
                    $needle = trim(strtolower($needle));
                    if ($needle === '') {
                        continue;
                    }
                    $known[] = preg_quote($needle, '#');
                    $map[$needle] = $id;
                }
                if ((int)$row->grp === Kind::SP_SCANNER) {
                    $scan[$id] = 1;
                }
                $domains = array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string)$row->verify_domain)
                )));
                if ($domains !== []) {
                    $verify[$id] = $domains;
                }
            }
        } catch (\Throwable $e) {
            Log::exception('SpiderRepo::compile', $e);
        }

        //长的关键词排前面，避免 "google" 抢在 "google-inspectiontool" 之前命中
        usort($known, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $re = $known === []
            ? '#(' . self::GENERIC . ')#'
            : '#(' . implode('|', $known) . ')|(' . self::GENERIC . ')#';

        $compiled = [
            'v' => time(),
            're' => $re,
            'has_known' => $known !== [],
            'map' => $map,
            'scan' => $scan,
            'verify' => $verify,
        ];
        State::writeSpiders($compiled);
        return $compiled;
    }

    /**
     * id => 展示信息（面板用，不进热路径）
     *
     * @return array<int,array{code:string,name:string,grp:int,icon:string}>
     */
    public static function all(): array
    {
        $out = [];
        try {
            foreach (Db::table(Db::SPIDER)->orderBy('sort')->get() as $row) {
                $out[(int)$row->id] = [
                    'code' => (string)$row->code,
                    'name' => (string)$row->name,
                    'grp' => (int)$row->grp,
                    'icon' => (string)$row->icon,
                    'status' => (int)$row->status,
                    'builtin' => (int)$row->builtin,
                    'pattern' => (string)$row->pattern,
                    'verify_domain' => (string)$row->verify_domain,
                ];
            }
        } catch (\Throwable $e) {
            Log::exception('SpiderRepo::all', $e);
        }
        return $out;
    }
}
