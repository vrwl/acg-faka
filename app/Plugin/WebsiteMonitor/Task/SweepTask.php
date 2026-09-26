<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Task;

use App\Plugin\WebsiteMonitor\Core\Acl\Ban;
use App\Plugin\WebsiteMonitor\Core\Acl\Compiler;
use App\Plugin\WebsiteMonitor\Core\Acl\Store;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Rollup\Prune;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * 每 5 分钟一轮的清道夫：过期数据清理、分区维护、编译产物刷新、CC 计数文件回收。
 */
class SweepTask
{
    public function run(object $ctx): void
    {
        try {
            Settings::refresh();
            if (!Settings::enabled()) {
                return;
            }
            State::reset();

            $stats = Prune::all();
            if (method_exists($ctx, 'heartbeat')) {
                $ctx->heartbeat();
            }

            $stats['ban'] = Ban::pruneExpired();
            $stats['rule'] = Store::pruneExpired();

            //分区模式：先备好明天的分区，再丢掉过期的（DROP PARTITION 是 O(1)）
            if (Settings::bool('raw_partition')) {
                Prune::ensureTomorrowPartition();
                $stats['partition'] = Prune::dropOldPartitions();
            }

            if (method_exists($ctx, 'heartbeat')) {
                $ctx->heartbeat();
            }

            //CC 计数文件与蜘蛛反查结果都是惰性过期的，这里只回收长期没人碰的残留
            $stats['cc_files'] = Runtime::clearDir(Runtime::ccDir(), time() - 3600);
            $stats['spider_files'] = Runtime::clearDir(Runtime::spiderDir() . '/pending', time() - 86400);

            if (Compiler::rebuildIfStale()) {
                $stats['recompiled'] = 1;
            }

            //网页接力用的租约时间戳文件，跑久了会积压
            @unlink(State::file() . '.relay');

            $stats = array_filter($stats, static fn($n): bool => (int)$n > 0);
            if ($stats !== []) {
                Log::info('清理完成', $stats);
                if (method_exists($ctx, 'debug')) {
                    $ctx->debug('清理完成', $stats);
                }
            }
        } catch (\Throwable $e) {
            Log::exception('SweepTask::run', $e);
            if (method_exists($ctx, 'error')) {
                $ctx->error('清理失败：' . $e->getMessage());
            }
        }
    }
}
