<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Task;

use App\Plugin\WebsiteMonitor\Core\Acl\Compiler;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Rollup\Daily;
use App\Plugin\WebsiteMonitor\Core\Rollup\Dimension;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * 每 60 秒重算「当天」的维度聚合与天表。
 *
 * 全量重算而不是增量，是为了幂等：赋值用 `col = VALUES(col)` 覆盖，
 * 跑一次和跑一百次结果一样，永远不会因为重试而多算。
 * 代价是每分钟扫一遍当天的会话表 —— 有 idx_day_vid 覆盖索引，十万级会话也就几十毫秒。
 */
class RollupTask
{
    public function run(object $ctx): void
    {
        try {
            Settings::refresh();
            if (!Settings::enabled()) {
                return;
            }
            State::reset();

            $today = (int)date('Ymd');
            Dimension::rebuildDay($today);
            if (method_exists($ctx, 'heartbeat')) {
                $ctx->heartbeat();
            }
            Dimension::rebuildPageUv($today);
            Dimension::pruneUnresolved($today);
            Daily::rebuild($today);

            //跨过午夜的那一轮，把昨天也收个尾（可能还有迟到的数据刚入库）
            $lastDone = (int)\App\Plugin\WebsiteMonitor\Core\Kv::int('rollup_day', 0);
            if ($lastDone !== 0 && $lastDone !== $today) {
                if (method_exists($ctx, 'heartbeat')) {
                    $ctx->heartbeat();
                }
                Dimension::rebuildDay($lastDone);
                Dimension::rebuildPageUv($lastDone);
                Dimension::pruneUnresolved($lastDone);
                Daily::rebuild($lastDone);
            }
            \App\Plugin\WebsiteMonitor\Core\Kv::set('rollup_day', $today);

            //规则编译产物过期时在这里重建：守护进程里做，网页请求就永远不用付这个钱
            Compiler::rebuildIfStale();
        } catch (\Throwable $e) {
            Log::exception('RollupTask::run', $e);
            if (method_exists($ctx, 'error')) {
                $ctx->error('汇总失败：' . $e->getMessage());
            }
        }
    }
}
