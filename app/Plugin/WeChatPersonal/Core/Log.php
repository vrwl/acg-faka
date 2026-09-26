<?php
declare(strict_types=1);

namespace App\Plugin\WeChatPersonal\Core;

/**
 * 插件运行日志，写在插件目录 runtime.log（后台「日志」按钮同源）。
 */
final class Log
{
    private const MAX = 2097152;

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        try {
            $file = BASE_PATH . '/app/Plugin/WeChatPersonal/runtime.log';
            if (is_file($file) && (int)@filesize($file) > self::MAX) {
                @file_put_contents($file, '');
            }
            $ctx = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents($file, sprintf("[%s] %s %s%s\n", date('Y-m-d H:i:s'), $level, $message, $ctx), FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
        }
    }
}
