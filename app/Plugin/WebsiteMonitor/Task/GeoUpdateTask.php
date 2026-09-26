<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Task;

use App\Plugin\WebsiteMonitor\Core\Geo\Downloader;
use App\Plugin\WebsiteMonitor\Core\Geo\Locator;
use App\Plugin\WebsiteMonitor\Core\Geo\Schedule;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * IP 地理库周更。
 *
 * 两种触发方式：
 *   cron  按配置的周期自动跑（默认每周一 04:00）
 *   手动  面板点「立即更新」后写一个 KV 标记，本任务下一轮（≤60s）看到就执行
 *
 * 下载源支持条件请求，没变化时是一次 304，几乎不产生流量 ——
 * 所以哪怕被多触发几次也不心疼。
 */
class GeoUpdateTask
{
    public function run(object $ctx): void
    {
        try {
            Settings::refresh();
            if (!Settings::enabled()) {
                return;
            }
            State::reset();

            //任务每分钟醒一次，绝大多数时候在这里就返回了（几微秒的数组比较）。
            //调度判定放在 Schedule 里：手动触发 60 秒内生效，cron 到点照常跑，
            //而且守护进程在预定时刻恰好没运行时还有「太久没更新就补一次」的兜底。
            $due = Schedule::due(time());
            if (!$due['run']) {
                return;
            }
            $manual = (bool)$due['manual'];

            $heartbeat = static function () use ($ctx): void {
                if (method_exists($ctx, 'heartbeat')) {
                    $ctx->heartbeat();
                }
            };

            if (method_exists($ctx, 'log')) {
                $ctx->log($manual ? '收到手动更新请求，开始下载 IP 库' : ('开始更新 IP 库（' . $due['why'] . '）'));
            }

            $result = Downloader::update($heartbeat, $manual);

            if (!$result['ok']) {
                if (method_exists($ctx, 'warn')) {
                    $ctx->warn('IP 库更新失败：' . $result['msg']);
                }
                return;
            }

            if (method_exists($ctx, 'log')) {
                $ctx->log($result['msg'] . '（耗时 ' . $result['ms'] . ' ms）');
            }

            if ($result['changed'] && Settings::bool('notify_geo_ok')) {
                self::notifySuccess($result);
            }
        } catch (\Throwable $e) {
            Log::exception('GeoUpdateTask::run', $e);
            if (method_exists($ctx, 'error')) {
                $ctx->error('IP 库更新异常：' . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function notifySuccess(array $result): void
    {
        if (!class_exists('\App\Plugin\WebsiteMonitor\Module\Notify\Alert')) {
            return;
        }
        try {
            $info = Locator::info();
            $build = (string)(($info['meta'] ?? [])['build_date'] ?? '');
            \App\Plugin\WebsiteMonitor\Module\Notify\Alert::raise(
                'wm.geo_updated',
                Settings::LEVEL_INFO,
                lang('IP 地理库已更新'),
                Lang::t('新库大小 :n MB，构建日期 :d。', [
                    'n' => (string)round(((int)$result['size']) / 1048576, 1),
                    'd' => $build !== '' ? $build : lang('未知'),
                ]),
                [
                    'scope_text' => lang('IP 地理库'),
                    'dedupe' => 'wm:geo:ok:' . date('oW'),
                ]
            );
        } catch (\Throwable $e) {
            Log::exception('GeoUpdateTask::notifySuccess', $e);
        }
    }
}
