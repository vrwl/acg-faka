<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Spool;

use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * spool 写入。这是整个采集链路上唯一一次磁盘 I/O，成本约 20–60µs。
 *
 * 为什么是文件而不是直接 INSERT：5000 rps 的攻击流量下，逐行 INSERT 会让监控本身
 * 变成 DoS 放大器（MySQL 先于站点倒下）。顺序追加 1.2MB/s 由 page cache 吸收，
 * 数据库那边由守护进程每 2 秒批量吃掉。
 *
 * 为什么分 8 片：同一分片上并发的 fpm 进程只有总数的 1/8，LOCK_EX 争用可忽略。
 */
final class Writer
{
    public const SHARDS = 8;

    private static ?string $path = null;
    private static int $count = 0;

    public static function append(string $line): void
    {
        try {
            if (self::$path === null) {
                $dir = Runtime::spool();
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                self::$path = $dir . '/s' . (getmypid() % self::SHARDS) . '.log';
            }
            @file_put_contents(self::$path, $line, FILE_APPEND | LOCK_EX);

            //每 256 次做一次体积兜底检查。正常情况下守护进程 2 秒就搬空了，永远摸不到这里；
            //只有它挂了或流量暴涨时才会触发降级。
            if ((++self::$count & 255) === 0) {
                self::checkSize();
            }
        } catch (\Throwable $e) {
            //采集绝不能影响业务：写不进去就算了
        }
    }

    private static function checkSize(): void
    {
        if (self::$path === null) {
            return;
        }
        $size = (int)@filesize(self::$path);
        $cap = (int)(State::get()['spool_cap'] ?? 33554432);
        if ($size > $cap) {
            State::markFlood();
        }
    }

    /**
     * 全部分片的积压情况（面板与自适应降级用）
     *
     * @return array{files:int,bytes:int,oldest:int}
     */
    public static function backlog(): array
    {
        $out = ['files' => 0, 'bytes' => 0, 'oldest' => 0];
        foreach ([Runtime::spool(), Runtime::seal()] as $dir) {
            $items = @glob($dir . '/*.log');
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $file) {
                $size = (int)@filesize($file);
                if ($size <= 0) {
                    continue;
                }
                $out['files']++;
                $out['bytes'] += $size;
                $mtime = (int)@filemtime($file);
                if ($mtime > 0 && ($out['oldest'] === 0 || $mtime < $out['oldest'])) {
                    $out['oldest'] = $mtime;
                }
            }
        }
        return $out;
    }

    /**
     * 重置进程内状态（守护进程里跨轮复用时用）
     */
    public static function reset(): void
    {
        self::$path = null;
        self::$count = 0;
    }
}
