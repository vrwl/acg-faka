<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

/**
 * 「响应冲刷后再干活」。
 *
 * register_shutdown_function + fastcgi_finish_request()：先把响应交还给用户，
 * 之后本进程才在后台执行注册进来的收尾回调（写 spool、必要时接力入库）。
 * 用户永远不会为统计埋单。
 *
 * 本插件自带一份而不复用通知中心的同名类 —— 通知中心是可选依赖，不能假设它在。
 */
final class Deferred
{
    private static bool $armed = false;
    private static bool $ran = false;

    /** @var array<int,callable> */
    private static array $callbacks = [];

    public static function arm(): void
    {
        if (self::$armed || Runtime::isCli()) {
            return;
        }
        self::$armed = true;
        register_shutdown_function([self::class, 'run']);
    }

    public static function onShutdown(callable $callback): void
    {
        self::$callbacks[] = $callback;
        self::arm();
    }

    public static function run(): void
    {
        if (self::$ran) {
            return;
        }
        self::$ran = true;

        try {
            self::flushResponse();
        } catch (\Throwable $e) {
        }

        foreach (self::$callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                Log::exception('Deferred::callback', $e);
            }
        }
        self::$callbacks = [];
    }

    /**
     * 把响应交还给客户端，然后本进程继续在后台工作
     */
    private static function flushResponse(): void
    {
        if (function_exists('session_write_close')) {
            @session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            return;
        }
        //退而求其次：尽量把缓冲冲出去
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
}
