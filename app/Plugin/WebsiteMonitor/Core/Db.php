<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;

/**
 * 数据访问助手：表名常量、前缀处理、原生 upsert、死锁识别。
 * 全部走查询构造器（Manager::table），不用 Eloquent Model —— 表结构简单，少一层抽象。
 *
 * 表名是**逻辑名**（不含前缀），Capsule 会自动补 acg_；写原生 SQL 时用 physical()。
 */
final class Db
{
    /* 明细与会话 */
    public const ACCESS = 'wm_access';
    public const SESSION = 'wm_session';
    public const VISITOR = 'wm_visitor';
    public const ONLINE = 'wm_online';
    public const SEEN = 'wm_seen';

    /* 汇总 */
    public const STAT_MIN = 'wm_stat_min';
    public const STAT_HOUR = 'wm_stat_hour';
    public const STAT_DAY = 'wm_stat_day';
    public const AGG = 'wm_agg';

    /* 字典 */
    public const PAGE = 'wm_page';
    public const REFERER = 'wm_referer';
    public const UA = 'wm_ua';
    public const DICT = 'wm_dict';
    public const REGION = 'wm_region';

    /* 安全 */
    public const RULE = 'wm_rule';
    public const BAN = 'wm_ban';
    public const ATTACK = 'wm_attack';
    public const IP = 'wm_ip';

    /* 蜘蛛 */
    public const SPIDER = 'wm_spider';
    public const SPIDER_IP = 'wm_spider_ip';

    /* 状态 */
    public const KV = 'wm_kv';
    public const INGEST = 'wm_ingest';

    public static function conn(): Connection
    {
        return Manager::connection();
    }

    public static function table(string $name): Builder
    {
        return Manager::table($name);
    }

    /**
     * 带前缀的物理表名（原生 SQL 用）
     */
    public static function physical(string $name): string
    {
        return self::conn()->getTablePrefix() . $name;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** 今天的 day 值（20260821 形式，用作按天裁剪/分区键） */
    public static function day(?int $ts = null): int
    {
        return (int)date('Ymd', $ts ?? time());
    }

    /**
     * MySQL 1213 死锁：InnoDB 已回滚整个事务，调用方必须把异常继续抛出去，
     * 否则外层会在一个已回滚的事务里继续走并"成功"提交空结果。
     */
    public static function isDeadlock(\Throwable $e): bool
    {
        if (!$e instanceof QueryException) {
            return false;
        }
        $info = $e->errorInfo ?? null;
        $code = is_array($info) ? (int)($info[1] ?? 0) : 0;
        return $code === 1213 || str_contains($e->getMessage(), 'Deadlock');
    }

    /**
     * 原生 INSERT ... ON DUPLICATE KEY UPDATE（Illuminate 7.30 没有 upsert()）
     *
     * @param string $table 逻辑表名
     * @param array<string,mixed> $insert 列 => 值
     * @param array<string,string> $updateExpr 列 => SQL 表达式，如 'cnt + VALUES(`cnt`)'
     */
    public static function upsert(string $table, array $insert, array $updateExpr): void
    {
        self::upsertMany($table, [$insert], $updateExpr);
    }

    /**
     * 多行版本。所有行必须有**相同的列集合与顺序**。
     *
     * MySQL 5.7 兼容：用 VALUES(`col`) 引用待插入值，不用 8.0 的 `AS new` 别名语法。
     *
     * @param string $table 逻辑表名
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,string> $updateExpr 列 => SQL 表达式
     * @param int $chunk 每条 SQL 最多几行（占位符总数受 max_allowed_packet 与 65535 参数上限约束）
     */
    public static function upsertMany(string $table, array $rows, array $updateExpr, int $chunk = 200): void
    {
        if ($rows === []) {
            return;
        }
        $columns = array_keys(reset($rows));
        if ($columns === []) {
            return;
        }
        $cols = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $columns));
        $one = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $updates = [];
        foreach ($updateExpr as $col => $expr) {
            $updates[] = '`' . $col . '` = ' . $expr;
        }
        //没有给更新表达式时退化成 INSERT IGNORE 语义：用任一列自赋值
        if ($updates === []) {
            $updates[] = '`' . $columns[0] . '` = `' . $columns[0] . '`';
        }

        $physical = self::physical($table);
        foreach (array_chunk($rows, max(1, $chunk), false) as $part) {
            $bind = [];
            foreach ($part as $row) {
                foreach ($columns as $c) {
                    $bind[] = $row[$c] ?? null;
                }
            }
            $sql = 'INSERT INTO `' . $physical . '` (' . $cols . ') VALUES '
                . implode(', ', array_fill(0, count($part), $one))
                . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
            self::conn()->statement($sql, $bind);
        }
    }

    /**
     * 批量 INSERT IGNORE，返回**真正插入**的行数。
     *
     * 这个返回值是 UV 精确统计的基石：往 wm_seen 里塞一批 (桶,时间,标识)，
     * 冲突的会被忽略，返回值就是该时间桶内真实新增的独立访客/IP 数。
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function insertIgnore(string $table, array $rows, int $chunk = 500): int
    {
        if ($rows === []) {
            return 0;
        }
        $columns = array_keys(reset($rows));
        if ($columns === []) {
            return 0;
        }
        $cols = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $columns));
        $one = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $physical = self::physical($table);

        $affected = 0;
        foreach (array_chunk($rows, max(1, $chunk), false) as $part) {
            $bind = [];
            foreach ($part as $row) {
                foreach ($columns as $c) {
                    $bind[] = $row[$c] ?? null;
                }
            }
            $sql = 'INSERT IGNORE INTO `' . $physical . '` (' . $cols . ') VALUES '
                . implode(', ', array_fill(0, count($part), $one));
            $affected += (int)self::conn()->affectingStatement($sql, $bind);
        }
        return $affected;
    }

    /**
     * 分块删除：一次删太多会造成长事务与主从延迟
     *
     * @return int 实际删除行数
     */
    public static function pruneChunked(string $table, string $column, mixed $lessThan, int $chunk = 2000, int $rounds = 5): int
    {
        $total = 0;
        for ($i = 0; $i < $rounds; $i++) {
            try {
                $n = (int)self::table($table)->where($column, '<', $lessThan)->limit($chunk)->delete();
            } catch (\Throwable $e) {
                Log::exception('Db::pruneChunked', $e, ['table' => $table]);
                break;
            }
            $total += $n;
            if ($n < $chunk) {
                break;
            }
            usleep(50000);
        }
        return $total;
    }

    /**
     * 安全的 JSON 编码（只保留可序列化的标量/数组）
     */
    public static function json(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * @return array<string,mixed>
     */
    public static function jsonDecode(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
