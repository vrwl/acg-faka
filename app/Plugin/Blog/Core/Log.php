<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

/**
 * 插件日志：写 app/Plugin/Blog/runtime.log（后台插件列表的「日志」按钮读它；打包时排除）。
 * 日志永远不能把业务打挂——全部异常吞掉。
 */
final class Log
{
    private const FILE = BASE_PATH . '/app/Plugin/Blog/runtime.log';
    private const MAX_SIZE = 2097152; //2MB，超了直接清空重来（避免在插件目录里滚动出第二个文件被误打包）

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    private static function write(string $level, string $message): void
    {
        try {
            if (is_file(self::FILE) && (int)@filesize(self::FILE) > self::MAX_SIZE) {
                @unlink(self::FILE);
            }
            @file_put_contents(
                self::FILE,
                sprintf("[%s][%s] %s\n", date('Y-m-d H:i:s'), $level, $message),
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            //ignore
        }
    }
}
