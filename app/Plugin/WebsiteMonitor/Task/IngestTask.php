<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Task;

use App\Plugin\WebsiteMonitor\Core\Ingest\Batch;
use App\Plugin\WebsiteMonitor\Core\Ingest\Dictionary;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Schema;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * 每 2 秒一轮，把 spool 里的行搬进数据库。**全插件唯一的写库入口。**
 *
 * 参数 $ctx 必须写成 object 而不是 ThreadManager 的具体类型 ——
 * 线程管理器没安装时那个类不存在，写死类型会让本插件连加载都加载不了。
 */
class IngestTask
{
    /** 单轮预算：不能占满整个 2 秒间隔，要给别的任务留出调度空间 */
    private const BUDGET_SEC = 1.5;

    public function run(object $ctx): void
    {
        try {
            Settings::refresh();
            if (!Settings::enabled()) {
                return;
            }
            State::reset();
            Schema::ensureOnce();

            //告诉网页端「我还活着」，它就不会去接力了
            State::heartbeat();

            $batch = new Batch();
            $result = $batch->drain(
                microtime(true) + self::BUDGET_SEC,
                100000,
                static function () use ($ctx): void {
                    if (method_exists($ctx, 'heartbeat')) {
                        $ctx->heartbeat();
                    }
                }
            );

            if ($result['lines'] > 0) {
                //按实际速率自适应升降级（洪水时切精简行，回落后恢复）
                State::adaptMode($batch->rate());
                if (method_exists($ctx, 'debug')) {
                    $ctx->debug('入库完成', $result);
                }
            }

            //守护进程长期驻留，字典缓存偶尔清一次，免得内存只涨不落
            if (mt_rand(1, 600) === 1) {
                Dictionary::flushCache();
            }
        } catch (\Throwable $e) {
            Log::exception('IngestTask::run', $e);
            if (method_exists($ctx, 'error')) {
                $ctx->error('入库失败：' . $e->getMessage());
            }
        }
    }
}
