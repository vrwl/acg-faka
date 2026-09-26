<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Spool;

use App\Plugin\WebsiteMonitor\Core\Runtime;

/**
 * 跨进程互斥租约。
 *
 * 用 fopen($f, 'x')（O_CREAT|O_EXCL）而不是 flock：'x' 在所有文件系统上都是原子的，
 * 包括网络挂载；flock 在 NFS / SMB 上的行为不可靠，而本项目的站点目录正好挂在网络盘上。
 *
 * 过期锁用 rename 抢占后删除，避免持有者崩溃导致死锁。
 */
final class Lease
{
    public static function tryAcquire(string $name, int $ttl = 20): bool
    {
        $file = self::path($name);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($file, 'x');
        if ($fp !== false) {
            @fwrite($fp, getmypid() . '|' . time());
            @fclose($fp);
            return true;
        }

        //已有锁：只有明显过期才抢
        $mtime = (int)@filemtime($file);
        if ($mtime > 0 && time() - $mtime > $ttl) {
            //先 rename 再删：两个进程同时发现锁过期时，只有一个能 rename 成功
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            if (@rename($file, $tmp)) {
                @unlink($tmp);
                return self::tryAcquire($name, $ttl);
            }
        }
        return false;
    }

    public static function release(string $name): void
    {
        @unlink(self::path($name));
    }

    /**
     * 刷新持有中的租约（长任务用，防止被别人当成过期锁抢走）
     */
    public static function touch(string $name): void
    {
        @touch(self::path($name));
    }

    public static function heldSince(string $name): int
    {
        return (int)@filemtime(self::path($name));
    }

    private static function path(string $name): string
    {
        return Runtime::dir() . '/' . preg_replace('/[^a-z0-9_.-]/i', '', $name) . '.lock';
    }
}
