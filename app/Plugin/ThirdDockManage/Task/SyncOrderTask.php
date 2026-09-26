<?php
declare(strict_types=1);

namespace App\Plugin\ThirdDockManage\Task;

use App\Plugin\ThirdDockManage\Command\SyncOrder;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Util\Plugin;
use Swoole\Coroutine;

/**
 * 线程管理器任务：同步订单状态
 * 与 Command\SyncOrder（run.php / cron）共用业务逻辑与 flock 互斥锁，同一时间只应启用一种方式
 */
class SyncOrderTask
{
    use Help;

    public function run(object $ctx): void
    {
        $_SERVER['third_dock_mode'] = true;

        $lock = $this->acquireSyncLock('sync_order');
        if (!$lock) {
            $ctx->warn('上一次同步订单仍在执行，本轮跳过');
            return;
        }

        try {
            $worker = new SyncOrder();
            $lists = $worker->pendingOrders();
            if (count($lists) == 0) {
                return;
            }
            $ctx->log('待同步订单数量：' . count($lists));

            if (self::inCoroutine()) {
                //连接为协程级借出：父协程已占一条，子协程各占一条，并发给连接池留出余量
                $poolMax = max(1, (int)(Plugin::getConfig('ThreadManager', false)['pool_max'] ?? 6));
                $concurrency = max(1, min(6, $poolMax - 1, count($lists)));
                $chunks = $lists->split($concurrency);

                $wg = new Coroutine\WaitGroup();
                foreach ($chunks as $chunk) {
                    $wg->add();
                    go(function () use ($wg, $worker, $chunk, $ctx) {
                        try {
                            foreach ($chunk as $order) {
                                $worker->alone($order->trade_no);
                                $ctx->log('已处理订单：' . $order->trade_no);
                            }
                        } catch (\Throwable $e) {
                            Plugin::log('ThirdDockManage', '线程任务同步订单错误：' . $e->getMessage());
                        } finally {
                            $wg->done();
                        }
                    });
                }
                $wg->wait();
            } else {
                foreach ($lists as $order) {
                    $worker->alone($order->trade_no);
                }
            }
        } finally {
            $this->releaseSyncLock($lock);
        }
    }
}
