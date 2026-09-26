<?php
declare(strict_types=1);

namespace App\Plugin\ThirdDockManage\Hook;

/**
 * 向线程管理器（ThreadManager）注册常驻同步任务。
 * 未安装 ThreadManager 时本钩子不会触发，插件按原 run.php / cron 方式运行。
 */
class TaskRegister
{
    /** 同步商品默认周期（秒），对应原 cron 建议频率：每小时 */
    private const SYNC_GOOD_INTERVAL = 3600;

    /** 同步订单默认周期（秒） */
    private const SYNC_ORDER_INTERVAL = 300;

    // 0x7A100 = \App\Plugin\ThreadManager\Consts\Hook::TASK_REGISTER
    // 必须写字面量，不能引用常量：ThreadManager 未安装时会导致本插件无法启用
    #[\Kernel\Annotation\Hook(point: 0x7A100)]
    public function register(): array
    {
        try {
            $goodInterval = $this->interval('sync_good_interval', self::SYNC_GOOD_INTERVAL);
            $orderInterval = $this->interval('sync_order_interval', self::SYNC_ORDER_INTERVAL);
            if ($goodInterval < 1800) {
                $goodHung = 3600;
            } else {
                $goodHung = $goodInterval * 2;
            }

            return [
                \App\Plugin\ThreadManager\Task\Task::make('sync-good', \App\Plugin\ThirdDockManage\Task\SyncGoodTask::class)
                    ->interval($goodInterval)
                    ->bootFull()
                    ->restartAlways()
                    ->hungAfter($goodHung),

                \App\Plugin\ThreadManager\Task\Task::make('sync-order', \App\Plugin\ThirdDockManage\Task\SyncOrderTask::class)
                    ->interval($orderInterval)
                    ->bootFull()
                    ->restartAlways()
                    ->hungAfter(max(1800, $orderInterval * 6)),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 读取任务间隔配置（秒），未配置、非法或读取失败时回退默认值
     */
    private function interval(string $key, int $default): int
    {
        try {
            $value = (int)(\App\Util\Plugin::getConfig('ThirdDockManage')[$key] ?? 0);
        } catch (\Throwable $e) {
            $value = 0;
        }
        return $value > 0 ? $value : $default;
    }
}
