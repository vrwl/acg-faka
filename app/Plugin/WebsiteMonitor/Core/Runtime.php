<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

/**
 * 运行时目录与可选依赖探测。
 *
 * 线程管理器、通知中心、Redis 都是**可选依赖**：这里只读它们的配置与运行态文件，
 * 绝不 use / 引用它们的类 —— 它们可能根本没装。
 */
final class Runtime
{
    /** 紧急停用开关：这个文件存在时，插件一步都不做 */
    public const DISABLED_FILE = 'DISABLED';

    /** 站点整体过载标记 */
    public const OVERLOAD_FILE = 'OVERLOAD';

    private static ?array $tmCache = null;
    private static float $tmAt = 0.0;
    private static ?array $ncCache = null;
    private static float $ncAt = 0.0;

    public static function dir(): string
    {
        return BASE_PATH . '/runtime/plugin/' . Settings::PLUGIN;
    }

    public static function spool(): string
    {
        return self::dir() . '/spool';
    }

    public static function seal(): string
    {
        return self::dir() . '/spool/seal';
    }

    public static function banDir(): string
    {
        return self::dir() . '/ban';
    }

    public static function ccDir(): string
    {
        return self::dir() . '/cc';
    }

    public static function geoDir(): string
    {
        return self::dir() . '/geo';
    }

    public static function spiderDir(): string
    {
        return self::dir() . '/spider';
    }

    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * 紧急停用：站长在服务器上建一个空文件就能立刻停掉全部防护。
     * 后台进不去时这是唯一的自救手段，所以拦截页正文里会打印这个完整路径。
     */
    public static function disabled(): bool
    {
        return is_file(self::dir() . '/' . self::DISABLED_FILE);
    }

    public static function ensureDirs(): void
    {
        $dirs = [
            self::dir(), self::spool(), self::seal(),
            self::banDir(), self::ccDir(), self::geoDir(), self::spiderDir(),
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
        //geo 目录里是几十 MB 的 IP 库，双保险挡住直接下载
        $guard = self::geoDir() . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Deny from all\nRequire all denied\n");
        }
        $index = self::geoDir() . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }

    /**
     * 线程管理器状态（进程内缓存 5 秒）
     *
     * @return array{installed:bool,enabled:bool,running:bool,task_alive:bool,ts:float}
     */
    public static function threadManager(): array
    {
        if (self::$tmCache !== null && (microtime(true) - self::$tmAt) < 5.0) {
            return self::$tmCache;
        }
        self::$tmAt = microtime(true);

        $out = ['installed' => false, 'enabled' => false, 'running' => false, 'task_alive' => false, 'ts' => 0.0];
        $dir = BASE_PATH . '/app/Plugin/ThreadManager';
        $out['installed'] = is_dir($dir);
        if ($out['installed']) {
            try {
                $cfg = \App\Util\Plugin::getConfig('ThreadManager', false);
                $out['enabled'] = (string)($cfg['STATUS'] ?? '0') === '1';
            } catch (\Throwable $e) {
            }
            $status = BASE_PATH . '/runtime/plugin/ThreadManager/status.json';
            if ($out['enabled'] && is_file($status)) {
                try {
                    $data = json_decode((string)@file_get_contents($status), true);
                    if (is_array($data)) {
                        $out['ts'] = (float)($data['ts'] ?? 0);
                        //守护进程每 250ms 刷一次状态文件，超过 3 秒没动静就当它挂了
                        $out['running'] = $out['ts'] > 0 && (microtime(true) - $out['ts']) < 3.0;
                        foreach ((array)($data['tasks'] ?? []) as $task) {
                            $id = (string)($task['id'] ?? '');
                            if (str_starts_with($id, Settings::PLUGIN . ':')) {
                                $out['task_alive'] = true;
                                break;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
        }
        self::$tmCache = $out;
        return $out;
    }

    /**
     * 通知中心状态（进程内缓存 5 秒）
     *
     * @return array{installed:bool,enabled:bool,api:bool}
     */
    public static function notificationCenter(): array
    {
        if (self::$ncCache !== null && (microtime(true) - self::$ncAt) < 5.0) {
            return self::$ncCache;
        }
        self::$ncAt = microtime(true);

        $out = ['installed' => false, 'enabled' => false, 'api' => false];
        $dir = BASE_PATH . '/app/Plugin/NotificationCenter';
        $out['installed'] = is_dir($dir);
        if ($out['installed']) {
            try {
                $cfg = \App\Util\Plugin::getConfig('NotificationCenter', false);
                $out['enabled'] = (string)($cfg['STATUS'] ?? '0') === '1';
            } catch (\Throwable $e) {
            }
            //新版通知中心提供了对外门面；老版本只有内部 Notifier，两者都支持
            $out['api'] = $out['enabled'] && (
                class_exists('\App\Plugin\NotificationCenter\Api\Notify')
                || class_exists('\App\Plugin\NotificationCenter\Core\Notifier')
            );
        }
        self::$ncCache = $out;
        return $out;
    }

    /**
     * Redis 是否可用。
     *
     * 注意：不能直接用 Redis 插件的共享连接 —— 它设了 OPT_SERIALIZER = SERIALIZER_PHP，
     * 与 incr / hIncrBy 的计数语义冲突。我们只借它的配置，自建一条不序列化的连接（见 Fast.php）。
     */
    public static function redisAvailable(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $ok = false;
        try {
            if (!extension_loaded('redis') || !is_dir(BASE_PATH . '/app/Plugin/Redis')) {
                return $ok;
            }
            $cfg = \App\Util\Plugin::getConfig('Redis', false);
            $ok = (string)($cfg['STATUS'] ?? '0') === '1';
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * 没装线程管理器时给站长的一句话提示
     */
    public static function installHint(): string
    {
        $tm = self::threadManager();
        if (!$tm['installed']) {
            return lang('未安装「线程管理器」插件：统计将由网页请求接力入库，延迟约 15 秒，且蜘蛛反查与 IP 库自动更新不可用。');
        }
        if (!$tm['enabled']) {
            return lang('「线程管理器」已安装但未启用，请到插件列表启用它。');
        }
        if (!$tm['running']) {
            return lang('「线程管理器」守护进程未运行，请到它的面板启动，或在服务器执行 ./service.sh start。');
        }
        if (!$tm['task_alive']) {
            return lang('本插件的后台任务尚未注册，请到「线程管理器」面板点一次「重载注册表」。');
        }
        return '';
    }

    /**
     * 删除目录下的全部文件（不递归删目录本身）
     */
    public static function clearDir(string $dir, int $olderThan = 0): int
    {
        $n = 0;
        try {
            $items = @scandir($dir);
            if ($items === false) {
                return 0;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . '/' . $item;
                if (is_dir($path)) {
                    $n += self::clearDir($path, $olderThan);
                    @rmdir($path);
                    continue;
                }
                if ($olderThan > 0 && (int)@filemtime($path) > $olderThan) {
                    continue;
                }
                if (@unlink($path)) {
                    $n++;
                }
            }
        } catch (\Throwable $e) {
        }
        return $n;
    }
}
