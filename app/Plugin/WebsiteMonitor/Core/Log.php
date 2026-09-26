<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

/**
 * 插件日志：写到框架约定的 app/Plugin/WebsiteMonitor/runtime.log（后台「日志」按钮读这里），
 * 分级、超过 4MB 自动轮转、敏感字段脱敏。
 *
 * 采集与防护跑在每一个请求上，日志必须绝对安静：任何异常都吞掉，绝不影响业务。
 */
final class Log
{
    public const DEBUG = 0;
    public const INFO = 1;
    public const WARN = 2;
    public const ERROR = 3;

    private const NAMES = [self::DEBUG => 'DEBUG', self::INFO => 'INFO', self::WARN => 'WARN', self::ERROR => 'ERROR'];
    private const MAX_BYTES = 4 * 1024 * 1024;

    public static function path(): string
    {
        return BASE_PATH . '/app/Plugin/' . Settings::PLUGIN . '/runtime.log';
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write(self::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write(self::INFO, $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::write(self::WARN, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write(self::ERROR, $message, $context);
    }

    /**
     * 记录异常（附文件与行号）
     */
    public static function exception(string $where, \Throwable $e, array $context = []): void
    {
        $context['exception'] = get_class($e);
        $context['at'] = basename($e->getFile()) . ':' . $e->getLine();
        self::write(self::ERROR, $where . '：' . $e->getMessage(), $context);
    }

    public static function write(int $level, string $message, array $context = []): void
    {
        try {
            if ($level < self::threshold()) {
                return;
            }
            $file = self::path();
            if (is_file($file) && filesize($file) > self::MAX_BYTES) {
                @rename($file, $file . '.1');
            }
            $line = '[' . date('Y-m-d H:i:s') . '][' . (self::NAMES[$level] ?? 'INFO') . ']';
            $line .= (PHP_SAPI === 'cli' ? '[daemon]' : '[web]') . ' ' . $message;
            if ($context !== []) {
                $safe = function_exists('maskSensitive') ? maskSensitive($context) : $context;
                $line .= ' ' . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // 日志本身绝不能影响业务
        }
    }

    private static function threshold(): int
    {
        try {
            $name = strtolower(Settings::get('log_level', 'info'));
        } catch (\Throwable $e) {
            $name = 'info';
        }
        return match ($name) {
            'debug' => self::DEBUG,
            'warn', 'warning' => self::WARN,
            'error' => self::ERROR,
            default => self::INFO,
        };
    }
}
