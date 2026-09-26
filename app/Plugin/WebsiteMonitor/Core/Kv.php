<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

/**
 * wm_kv：带 TTL 的小状态存储（熔断标记、任务游标、下载进度、通知冷却…）。
 *
 * 全部方法吞异常 —— 调用方多在守护任务或热路径边缘，不该因为一次 KV 读写失败而中断。
 */
final class Kv
{
    /**
     * @param mixed $value 会 json 编码；null 等同删除
     */
    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        try {
            Db::upsert(Db::KV, [
                'k' => mb_substr($key, 0, 96),
                'v' => Db::json($value),
                'expire_at' => $ttl > 0 ? time() + $ttl : 0,
            ], [
                'v' => 'VALUES(`v`)',
                'expire_at' => 'VALUES(`expire_at`)',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Kv::set', $e, ['k' => $key]);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $row = Db::table(Db::KV)->where('k', mb_substr($key, 0, 96))->first();
            if (!$row) {
                return $default;
            }
            $expire = (int)($row->expire_at ?? 0);
            if ($expire > 0 && $expire < time()) {
                return $default;
            }
            $decoded = json_decode((string)($row->v ?? ''), true);
            return $decoded === null ? $default : $decoded;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, $default);
        return is_numeric($v) ? (int)$v : $default;
    }

    public static function has(string $key): bool
    {
        return self::get($key, null) !== null;
    }

    /**
     * 只在不存在（或已过期）时写入，返回是否抢到。
     * 用于「同一时间窗内只做一次」的场景（通知冷却、周更去重）。
     */
    public static function add(string $key, mixed $value, int $ttl): bool
    {
        try {
            $k = mb_substr($key, 0, 96);
            $now = time();
            //先清掉过期行，再尝试插入；两步之间的竞争由主键兜底
            Db::table(Db::KV)->where('k', $k)->where('expire_at', '>', 0)->where('expire_at', '<', $now)->delete();
            $n = Db::insertIgnore(Db::KV, [[
                'k' => $k,
                'v' => Db::json($value),
                'expire_at' => $ttl > 0 ? $now + $ttl : 0,
            ]]);
            return $n > 0;
        } catch (\Throwable $e) {
            Log::exception('Kv::add', $e, ['k' => $key]);
            return false;
        }
    }

    /**
     * 原子自增，返回自增后的值（不存在则从 0 开始）。
     */
    public static function increment(string $key, int $by = 1, int $ttl = 0): int
    {
        try {
            $k = mb_substr($key, 0, 96);
            Db::upsert(Db::KV, [
                'k' => $k,
                'v' => (string)$by,
                'expire_at' => $ttl > 0 ? time() + $ttl : 0,
            ], [
                //v 是 json 文本列，这里存的是裸数字，CAST 后相加再写回
                'v' => 'CAST(CAST(`v` AS SIGNED) + ' . (int)$by . ' AS CHAR)',
            ]);
            return self::int($key, $by);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function delete(string $key): void
    {
        try {
            Db::table(Db::KV)->where('k', mb_substr($key, 0, 96))->delete();
        } catch (\Throwable $e) {
        }
    }

    /**
     * @return array<string,mixed> key => value
     */
    public static function byPrefix(string $prefix, int $limit = 500): array
    {
        try {
            $rows = Db::table(Db::KV)
                ->where('k', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefix) . '%')
                ->limit($limit)->get();
            $out = [];
            $now = time();
            foreach ($rows as $row) {
                $expire = (int)($row->expire_at ?? 0);
                if ($expire > 0 && $expire < $now) {
                    continue;
                }
                $out[(string)$row->k] = json_decode((string)($row->v ?? ''), true);
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function prune(): int
    {
        try {
            return (int)Db::table(Db::KV)->where('expire_at', '>', 0)->where('expire_at', '<', time())->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
