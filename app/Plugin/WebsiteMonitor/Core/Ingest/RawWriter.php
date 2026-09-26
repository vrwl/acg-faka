<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Ingest;

use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;

/**
 * 原始明细入库（wm_access）。
 *
 * 这是唯一允许采样丢弃的地方 —— 聚合表的数字永远是全量精确的，
 * 站长可以接受「只看到 10% 的明细」，绝不能接受「PV 少算了」。
 */
final class RawWriter
{
    private const CHUNK = 300;

    /**
     * @param array<int,array<string,mixed>> $access
     * @param array<string,array<string,int>> $ids
     * @return int 实际写入行数
     */
    public static function insert(array $access, array $ids): int
    {
        if ($access === []) {
            return 0;
        }
        $rows = [];
        foreach ($access as $item) {
            $pageRef = (string)$item['page_ref'];
            $refRef = (string)$item['ref_ref'];
            $uaRef = (string)$item['ua_ref'];
            $rows[] = [
                'day' => (int)$item['day'],
                'ts' => (int)$item['ts'],
                'ms' => min(65535, (int)$item['ms']),
                'vid' => (string)$item['vid'],
                'sid' => (string)$item['sid'],
                'uid' => (int)$item['uid'],
                'ip' => (string)$item['ip'],
                'kind' => (int)$item['kind'],
                'method' => (int)$item['method'],
                'status' => (int)$item['status'],
                'page_id' => $pageRef === '' ? 0 : (int)($ids['pages'][$pageRef] ?? 0),
                'query' => (string)$item['query'],
                'ref_id' => $refRef === '' ? 0 : (int)($ids['referers'][$refRef] ?? 0),
                'ua_id' => $uaRef === '' ? 0 : (int)($ids['uas'][$uaRef] ?? 0),
                'spider_id' => (int)$item['spider_id'],
                'region_id' => (int)($ids['ip_region'][(string)$item['ip']] ?? 0),
                'dev' => (int)$item['dev'],
                'flags' => (int)$item['flags'],
            ];
        }

        $written = 0;
        try {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                Db::table(Db::ACCESS)->insert($chunk);
                $written += count($chunk);
            }
        } catch (\Throwable $e) {
            Log::exception('RawWriter::insert', $e);
        }
        return $written;
    }

    /**
     * 某一天的明细行数（自动降采样判断用）
     */
    public static function countForDay(int $day): int
    {
        try {
            return (int)Db::table(Db::ACCESS)->where('day', $day)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
