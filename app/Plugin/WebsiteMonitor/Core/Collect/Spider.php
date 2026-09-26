<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Collect;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * 热路径上的蜘蛛识别。
 *
 * 铁律：**这里绝不做 DNS**。gethostbyaddr() 没有超时参数，一次慢 PTR 就能给请求
 * 加上几秒；攻击者只要伪造一个 Googlebot 的 UA 就能把站点拖死。
 * 正反查交给守护进程的 SpiderVerifyTask 异步做，这里只读它留下的结果文件。
 */
final class Spider
{
    /** 反查结果文件的有效期由写入方决定，这里只认文件内容里的过期戳 */
    private static array $mem = [];

    /**
     * UA 匹配，返回 spider_id；0 表示不是爬虫。
     * 整个函数就一次 strtolower + 一次 preg_match。
     */
    public static function match(string $ua): int
    {
        if ($ua === '') {
            return 0;
        }
        $s = State::spiders();
        $re = (string)($s['re'] ?? '');
        if ($re === '') {
            return 0;
        }
        //不加 u 修饰符：UA 里经常有非法 UTF-8，加了会让 preg_match 直接返回 false
        if (!preg_match($re, strtolower($ua), $m)) {
            return 0;
        }
        $hit = (string)($m[1] ?? '');
        if ($hit !== '') {
            return (int)($s['map'][$hit] ?? SpiderRepo::UNKNOWN);
        }
        //只命中泛化特征 = 没被签名覆盖的爬虫
        return SpiderRepo::UNKNOWN;
    }

    /** 该蜘蛛是否属于安全扫描器（这类不算正经爬虫，计入攻击维度） */
    public static function isScanner(int $spiderId): bool
    {
        return $spiderId > 0 && isset(State::spiders()['scan'][$spiderId]);
    }

    /** 该蜘蛛是否支持反查验证（官方公布了 PTR 域名后缀） */
    public static function verifiable(int $spiderId): bool
    {
        return $spiderId > 0 && !empty(State::spiders()['verify'][$spiderId]);
    }

    /**
     * 读取该 IP 的反查结论。热路径只做一次 is_file + 一次小文件读。
     *
     * @return int Kind::SPV_*（PENDING / REAL / FAKE / SKIP）
     */
    public static function verifyState(string $ip): int
    {
        if (isset(self::$mem[$ip])) {
            return self::$mem[$ip];
        }
        $file = self::statePath($ip);
        if (!is_file($file)) {
            return self::$mem[$ip] = Kind::SPV_PENDING;
        }
        $raw = (string)@file_get_contents($file);
        [$verdict, $expire] = array_pad(explode('|', $raw, 2), 2, '0');
        if ((int)$expire > 0 && (int)$expire < time()) {
            @unlink($file);
            return self::$mem[$ip] = Kind::SPV_PENDING;
        }
        return self::$mem[$ip] = (int)$verdict;
    }

    /**
     * 记下「这个 IP 声称自己是某蜘蛛，待验证」，由守护任务消费。
     * 只写一个空文件，无锁无内容，成本约等于一次 touch。
     */
    public static function queueVerify(string $ip, int $spiderId): void
    {
        try {
            //内网与保留地址不排队：它们永远不可能有搜索引擎的反解记录，
            //排进去只会被判成伪蜘蛛，白白误伤局域网测试与代理没配好的站点。
            if (\App\Plugin\WebsiteMonitor\Core\Ip::isPrivate($ip)) {
                return;
            }
            $dir = Runtime::spiderDir() . '/pending';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $file = $dir . '/' . md5($ip);
            if (is_file($file)) {
                return;
            }
            @file_put_contents($file, $ip . '|' . $spiderId);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 写入反查结论（守护任务调）
     */
    public static function writeState(string $ip, int $verdict, int $ttl): void
    {
        try {
            $file = self::statePath($ip);
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($file, $verdict . '|' . (time() + $ttl));
            self::$mem[$ip] = $verdict;
            @unlink(Runtime::spiderDir() . '/pending/' . md5($ip));
        } catch (\Throwable $e) {
        }
    }

    /**
     * 待验证队列（守护任务消费）
     *
     * @return array<int,array{ip:string,spider_id:int,file:string}>
     */
    public static function pending(int $limit = 30): array
    {
        $out = [];
        $dir = Runtime::spiderDir() . '/pending';
        if (!is_dir($dir)) {
            return $out;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return $out;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || count($out) >= $limit) {
                continue;
            }
            $file = $dir . '/' . $item;
            $raw = (string)@file_get_contents($file);
            [$ip, $spiderId] = array_pad(explode('|', $raw, 2), 2, '0');
            if ($ip === '') {
                @unlink($file);
                continue;
            }
            $out[] = ['ip' => $ip, 'spider_id' => (int)$spiderId, 'file' => $file];
        }
        return $out;
    }

    private static function statePath(string $ip): string
    {
        $hash = md5($ip);
        return Runtime::spiderDir() . '/' . substr($hash, 0, 2) . '/' . $hash;
    }
}
