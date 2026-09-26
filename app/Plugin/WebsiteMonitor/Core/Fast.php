<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

/**
 * Redis 可选加速层。**任何功能都不许依赖它** —— Redis 插件没装、扩展没装、
 * 连接抖动，全部静默降级，站点该怎么跑还怎么跑。
 *
 * 为什么不直接用 Redis 插件的共享连接：那条连接设了
 *   $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP)
 * 序列化器会把 incr / hIncrBy 这类服务端自增的语义搅乱（写进去的是 "i:5;"，
 * 自增就炸了；读回来又要猜是不是序列化过的）。所以这里只借它的连接参数，
 * 自己建一条 SERIALIZER_NONE 的连接，值全部当裸字符串处理。
 */
final class Fast
{
    /** @var \Redis|null */
    private static $redis = null;
    private static bool $tried = false;
    private static string $prefix = '';

    public static function available(): bool
    {
        return self::redis() !== null;
    }

    /**
     * @return \Redis|null
     */
    public static function redis()
    {
        if (self::$tried) {
            return self::$redis;
        }
        self::$tried = true;

        try {
            if (!Runtime::redisAvailable()) {
                return self::$redis = null;
            }
            $config = \App\Util\Plugin::getConfig('Redis', false);
            $redis = new \Redis();
            $ok = @$redis->connect(
                (string)($config['host'] ?? '127.0.0.1'),
                (int)($config['port'] ?? 6379),
                1.0
            );
            if ($ok === false) {
                return self::$redis = null;
            }
            $auth = (string)($config['auth'] ?? '');
            if ($auth !== '' && @$redis->auth($auth) === false) {
                return self::$redis = null;
            }
            //关键：不要序列化器，我们只存裸字符串与整数
            @$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
            @$redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);

            //按数据库名做前缀，同一台 Redis 上多个站点不会互相踩
            self::$prefix = 'wm:' . substr(md5(BASE_PATH), 0, 8) . ':';
            return self::$redis = $redis;
        } catch (\Throwable $e) {
            return self::$redis = null;
        }
    }

    public static function get(string $key): ?string
    {
        $redis = self::redis();
        if ($redis === null) {
            return null;
        }
        try {
            $value = $redis->get(self::$prefix . $key);
            return is_string($value) ? $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function set(string $key, string $value, int $ttl = 0): bool
    {
        $redis = self::redis();
        if ($redis === null) {
            return false;
        }
        try {
            return $ttl > 0
                ? (bool)$redis->setex(self::$prefix . $key, $ttl, $value)
                : (bool)$redis->set(self::$prefix . $key, $value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function delete(string $key): void
    {
        $redis = self::redis();
        if ($redis === null) {
            return;
        }
        try {
            $redis->del(self::$prefix . $key);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 自增并设过期。CC 计数器用 —— 服务端原子操作，多进程天然安全。
     *
     * @return int 自增后的值；Redis 不可用返回 -1
     */
    public static function incr(string $key, int $by = 1, int $ttl = 0): int
    {
        $redis = self::redis();
        if ($redis === null) {
            return -1;
        }
        try {
            $full = self::$prefix . $key;
            $value = (int)$redis->incrBy($full, $by);
            //只有第一次写入时才设过期，避免每次自增都把窗口往后推
            if ($ttl > 0 && $value === $by) {
                $redis->expire($full, $ttl);
            }
            return $value;
        } catch (\Throwable $e) {
            return -1;
        }
    }

    /**
     * 批量取（CC 滑动窗口要一次读一串秒桶）
     *
     * @param string[] $keys
     * @return array<string,int>
     */
    public static function mgetInt(array $keys): array
    {
        $redis = self::redis();
        if ($redis === null || $keys === []) {
            return [];
        }
        try {
            $full = array_map(static fn(string $k): string => self::$prefix . $k, $keys);
            $values = $redis->mGet($full);
            if (!is_array($values)) {
                return [];
            }
            $out = [];
            foreach ($keys as $i => $key) {
                $out[$key] = isset($values[$i]) && $values[$i] !== false ? (int)$values[$i] : 0;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 按前缀删除。用 SCAN 而不是 KEYS —— KEYS 会阻塞整个 Redis。
     */
    public static function deleteByPrefix(string $prefix): int
    {
        $redis = self::redis();
        if ($redis === null) {
            return 0;
        }
        try {
            $pattern = self::$prefix . $prefix . '*';
            $deleted = 0;
            $cursor = null;
            //phpredis 的 scan 用引用传 cursor，返回 false 表示结束
            while (($keys = $redis->scan($cursor, $pattern, 500)) !== false) {
                if ($keys !== []) {
                    $deleted += (int)$redis->del($keys);
                }
                if ((int)$cursor === 0) {
                    break;
                }
            }
            return $deleted;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function reset(): void
    {
        try {
            if (self::$redis !== null) {
                self::$redis->close();
            }
        } catch (\Throwable $e) {
        }
        self::$redis = null;
        self::$tried = false;
    }
}
