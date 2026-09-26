<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Rollup;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Kv;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\Spool\Reader;

/**
 * 数据清理。全部分块删除 —— 一次删几十万行会造成长事务与主从延迟。
 */
final class Prune
{
    /** 单表单轮最多删多少行 */
    private const CHUNK = 2000;
    private const ROUNDS = 6;

    /**
     * @return array<string,int> 各表删除行数
     */
    public static function all(): array
    {
        $out = [];
        $now = time();

        $rawDays = Settings::intMin('raw_retention_days', 1, 30);
        $out['access'] = Db::pruneChunked(Db::ACCESS, 'day', (int)date('Ymd', $now - $rawDays * 86400), self::CHUNK, self::ROUNDS);

        $sessionDays = Settings::intMin('session_retention_days', 1, 30);
        $out['session'] = Db::pruneChunked(Db::SESSION, 'day', (int)date('Ymd', $now - $sessionDays * 86400), self::CHUNK, 3);

        $attackDays = Settings::intMin('attack_retention_days', 1, 14);
        $out['attack'] = Db::pruneChunked(Db::ATTACK, 'day', (int)date('Ymd', $now - $attackDays * 86400), self::CHUNK, 3);

        $aggDays = Settings::intMin('agg_retention_days', 30, 400);
        $out['agg'] = Db::pruneChunked(Db::AGG, 'day', (int)date('Ymd', $now - $aggDays * 86400), self::CHUNK, 2);

        $visitorDays = Settings::intMin('visitor_retention_days', 7, 180);
        $out['visitor'] = Db::pruneChunked(Db::VISITOR, 'last_ts', $now - $visitorDays * 86400, self::CHUNK, 2);

        $out['seen'] = self::seen($now);
        $out['online'] = self::online($now);
        $out['stat_min'] = Db::pruneChunked(Db::STAT_MIN, 't', $now - 3 * 86400, self::CHUNK, 2);
        $out['stat_hour'] = Db::pruneChunked(Db::STAT_HOUR, 't', $now - 180 * 86400, self::CHUNK, 1);
        $out['ingest'] = Db::pruneChunked(Db::INGEST, 'ts', $now - 2 * 86400, self::CHUNK, 2);
        $out['ua'] = Db::pruneChunked(Db::UA, 'last_ts', $now - 180 * 86400, self::CHUNK, 1);
        $out['kv'] = Kv::prune();
        $out['spider_ip'] = self::spiderIp($now);
        $out['bad_spool'] = self::badSpool($now);

        return array_filter($out, static fn(int $n): bool => $n > 0);
    }

    /**
     * 去重集合分桶保留：分钟桶只要 3 天，小时桶要 30 天（月报表要用），
     * 维度日桶 7 天，攻击证据坑位 2 天。
     */
    private static function seen(int $now): int
    {
        $rules = [
            Kind::SEEN_VID_MIN => 3 * 86400,
            Kind::SEEN_IP_MIN => 3 * 86400,
            Kind::SEEN_VID_HOUR => 30 * 86400,
            Kind::SEEN_IP_HOUR => 30 * 86400,
            Kind::SEEN_DIM_DAY => 7 * 86400,
            Kind::SEEN_ATTACK_EVIDENCE => 2 * 86400,
        ];
        $total = 0;
        foreach ($rules as $bucket => $keep) {
            try {
                for ($i = 0; $i < 3; $i++) {
                    $n = (int)Db::table(Db::SEEN)
                        ->where('b', $bucket)->where('t', '<', $now - $keep)
                        ->limit(self::CHUNK)->delete();
                    $total += $n;
                    if ($n < self::CHUNK) {
                        break;
                    }
                    usleep(30000);
                }
            } catch (\Throwable $e) {
                Log::exception('Prune::seen', $e, ['bucket' => $bucket]);
            }
        }
        return $total;
    }

    private static function online(int $now): int
    {
        $window = Settings::intMin('online_window', 60, 300);
        try {
            return (int)Db::table(Db::ONLINE)->where('last_at', '<', $now - $window * 3)->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function spiderIp(int $now): int
    {
        try {
            return (int)Db::table(Db::SPIDER_IP)
                ->where('expire_at', '>', 0)->where('expire_at', '<', $now)
                ->limit(self::CHUNK)->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 隔离区里的坏 spool 文件留 7 天，方便排查后自然消失
     */
    private static function badSpool(int $now): int
    {
        return Runtime::clearDir(Runtime::seal() . '/bad', $now - 7 * 86400);
    }

    /**
     * 分区模式下的清理：DROP PARTITION 是 O(1)，比分块 DELETE 快几个数量级
     */
    public static function dropOldPartitions(): int
    {
        if (!Settings::bool('raw_partition')) {
            return 0;
        }
        $dropped = 0;
        try {
            $table = Db::physical(Db::ACCESS);
            $keepFrom = (int)date('Ymd', time() - Settings::intMin('raw_retention_days', 1, 30) * 86400);
            $rows = Db::conn()->select(
                'SELECT PARTITION_NAME AS p FROM information_schema.PARTITIONS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
                [$table]
            );
            foreach ($rows as $row) {
                $name = (string)$row->p;
                if ($name === 'pmax' || !preg_match('/^p(\d{8})$/', $name, $m)) {
                    continue;
                }
                if ((int)$m[1] >= $keepFrom) {
                    continue;
                }
                Db::conn()->statement('ALTER TABLE `' . $table . '` DROP PARTITION `' . $name . '`');
                $dropped++;
            }
        } catch (\Throwable $e) {
            Log::exception('Prune::dropOldPartitions', $e);
        }
        return $dropped;
    }

    /**
     * 分区模式下提前建好明天的分区
     */
    public static function ensureTomorrowPartition(): void
    {
        if (!Settings::bool('raw_partition')) {
            return;
        }
        try {
            $table = Db::physical(Db::ACCESS);
            $tomorrow = (int)date('Ymd', time() + 86400);
            $exists = Db::conn()->select(
                'SELECT COUNT(*) AS n FROM information_schema.PARTITIONS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME = ?',
                [$table, 'p' . $tomorrow]
            );
            if ((int)($exists[0]->n ?? 0) > 0) {
                return;
            }
            //REORGANIZE 把 pmax 拆成「明天」+ 新的 pmax
            Db::conn()->statement(
                'ALTER TABLE `' . $table . '` REORGANIZE PARTITION `pmax` INTO ('
                . 'PARTITION p' . $tomorrow . ' VALUES LESS THAN (' . ($tomorrow + 1) . '), '
                . 'PARTITION pmax VALUES LESS THAN MAXVALUE)'
            );
        } catch (\Throwable $e) {
            Log::exception('Prune::ensureTomorrowPartition', $e);
        }
    }

    /**
     * 隔离文件计数（面板提示）
     */
    public static function badSpoolCount(): int
    {
        return Reader::badCount();
    }
}
