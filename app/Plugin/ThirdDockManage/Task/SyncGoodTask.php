<?php
declare(strict_types=1);

namespace App\Plugin\ThirdDockManage\Task;

use App\Plugin\ThirdDockManage\Command\SyncGood;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Util\Plugin;
use Swoole\Coroutine;

/**
 * 线程管理器任务：同步商品
 * 与 Command\SyncGood（run.php / cron）共用业务逻辑与 flock 互斥锁，同一时间只应启用一种方式
 */
class SyncGoodTask
{
    use Help;

    public function run(object $ctx): void
    {
        $_SERVER['third_dock_mode'] = true;

        $lock = $this->acquireSyncLock('sync_good');
        if (!$lock) {
            $ctx->warn('上一次同步商品仍在执行，本轮跳过');
            return;
        }

        try {
            $worker = new SyncGood();
            $config = $ctx->config();
            $sites = $worker->querySites((string)$ctx->arg('site', ''), (string)$ctx->arg('exclude', ''));
            $ctx->log('开启站点共有：' . count($sites));
            if (count($sites) == 0) {
                return;
            }

            $enhance = (int)($config['collection_enhance'] ?? 0);
            if ($enhance > 0 && self::inCoroutine()) {
                //采集增强模式：按站点并发（对齐旧 CLI 增强模式语义），并发受连接池上限约束
                $poolMax = max(1, (int)(Plugin::getConfig('ThreadManager', false)['pool_max'] ?? 6));
                $concurrency = max(1, min($enhance, $poolMax - 1, 16));
                $ctx->log('已启动采集增强模式，并发：' . $concurrency);

                $channel = new Coroutine\Channel($concurrency);
                $wg = new Coroutine\WaitGroup();
                foreach ($sites as $site) {
                    $channel->push(1);
                    $wg->add();
                    go(function () use ($channel, $wg, $worker, $site, $ctx) {
                        try {
                            $over = $worker->aloneSiteData($site->toArray());
                            if (filled($over)) {
                                $ctx->log('已处理站点：' . $over);
                            }
                        } catch (\Throwable $e) {
                            Plugin::log('ThirdDockManage', '线程任务采集站点[' . ($site->id ?? '?') . ']失败：' . $e->getMessage());
                        } finally {
                            $channel->pop();
                            $wg->done();
                        }
                    });
                }
                $wg->wait();
            } else {
                foreach ($sites as $site) {
                    $over = $worker->aloneSiteData($site->toArray());
                    if (filled($over)) {
                        $ctx->log('已处理站点：' . $over);
                    }
                }
            }

            $worker->touchLastSyncTime();
            $worker->cloneRules($sites, $config);
        } finally {
            $this->releaseSyncLock($lock);
        }
    }
}
