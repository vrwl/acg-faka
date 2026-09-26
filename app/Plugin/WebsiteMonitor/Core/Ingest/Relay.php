<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Ingest;

use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\Spool\Lease;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * 线程管理器缺席时的兜底：由网页请求「接力」消费 spool。
 *
 * 三条硬约束，缺一不可：
 *   1. 只在响应已经冲刷之后跑（调用方是 Collector::flush 的收尾回调）；
 *   2. 全站同一时刻最多一个请求在干活（文件租约互斥）；
 *   3. 硬性 300ms / 2000 行封顶 —— 宁可慢慢追，也不能让某个倒霉的访客替全站还债。
 *
 * 能力上会有折损（蜘蛛反查、IP 库自动更新做不了），面板会如实提示站长装线程管理器。
 */
final class Relay
{
    private const LEASE = 'ingest';
    private const LEASE_TTL = 20;
    private const BUDGET_SEC = 0.30;
    private const MAX_LINES = 2000;

    private static bool $ran = false;

    public static function run(): void
    {
        //一个请求最多接力一次
        if (self::$ran) {
            return;
        }
        self::$ran = true;

        //节流：没到间隔就不折腾，避免高并发下每个请求都去抢锁
        $interval = max(5, Settings::int('web_flush_interval', 15));
        $stamp = State::file() . '.relay';
        $last = (int)@filemtime($stamp);
        if ($last > 0 && time() - $last < $interval) {
            return;
        }

        if (!Lease::tryAcquire(self::LEASE, self::LEASE_TTL)) {
            return;
        }
        @touch($stamp);

        try {
            //顺便重新探测一次守护进程：它可能刚刚被拉起来了
            State::refreshDaemonFlag();

            $batch = new Batch();
            $result = $batch->drain(microtime(true) + self::BUDGET_SEC, self::MAX_LINES);
            if ($result['lines'] > 0) {
                Log::debug('网页接力入库', $result);
            }
        } catch (\Throwable $e) {
            Log::exception('Relay::run', $e);
        } finally {
            Lease::release(self::LEASE);
        }
    }

    public static function reset(): void
    {
        self::$ran = false;
    }
}
