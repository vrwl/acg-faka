<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Task;

use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Rollup\Daily;
use App\Plugin\WebsiteMonitor\Core\Rollup\Dimension;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * 每天 00:07 封上一天的账，并（可选）推送日报。
 *
 * 挑 00:07 而不是 00:00：让上一天最后几分钟的 spool 有时间被 IngestTask 消化完，
 * 也错开整点那一堆定时任务的高峰。
 */
class DailyTask
{
    public function run(object $ctx): void
    {
        try {
            Settings::refresh();
            if (!Settings::enabled()) {
                return;
            }
            State::reset();

            $yesterday = (int)date('Ymd', time() - 86400);

            Dimension::rebuildDay($yesterday);
            if (method_exists($ctx, 'heartbeat')) {
                $ctx->heartbeat();
            }
            Dimension::rebuildPageUv($yesterday);
            Dimension::pruneUnresolved($yesterday);
            $summary = Daily::rebuild($yesterday);

            Log::info('日报已生成', ['day' => $yesterday, 'pv' => (int)($summary['pv'] ?? 0), 'uv' => (int)($summary['uv'] ?? 0)]);

            //广播给其它插件（返回值忽略）
            try {
                hook(0x7C106, array_merge(['day' => $yesterday], $summary));
            } catch (\Throwable $e) {
            }

            if (Settings::bool('notify_digest') && class_exists('\App\Plugin\WebsiteMonitor\Module\Notify\Digest')) {
                \App\Plugin\WebsiteMonitor\Module\Notify\Digest::send($yesterday, $summary);
            }
        } catch (\Throwable $e) {
            Log::exception('DailyTask::run', $e);
            if (method_exists($ctx, 'error')) {
                $ctx->error('日报失败：' . $e->getMessage());
            }
        }
    }
}
