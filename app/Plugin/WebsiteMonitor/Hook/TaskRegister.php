<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Hook;

use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Task\DailyTask;
use App\Plugin\WebsiteMonitor\Task\GeoUpdateTask;
use App\Plugin\WebsiteMonitor\Task\IngestTask;
use App\Plugin\WebsiteMonitor\Task\RollupTask;
use App\Plugin\WebsiteMonitor\Task\SpiderVerifyTask;
use App\Plugin\WebsiteMonitor\Task\SweepTask;

/**
 * 向「线程管理器」注册后台任务。
 *
 * 0x7A100 = \App\Plugin\ThreadManager\Consts\Hook::TASK_REGISTER
 *
 * **必须写十六进制字面量**：内核在插件启用时会对注解属性求值，线程管理器没安装时
 * 那个常量不存在，求值会抛 Error，把本插件卡在「START 已执行、STATUS 未写入」的
 * 半启用状态 —— 到时候既不工作也停不掉，只能手工改配置文件。
 *
 * 同理，返回的 Task 对象也包在 try 里：线程管理器不在时整段返回空数组即可，
 * 本插件会自动退化成「网页请求接力」模式（见 Core\Ingest\Relay）。
 */
class TaskRegister
{
    #[\Kernel\Annotation\Hook(point: 0x7A100)]
    public function register(): array
    {
        try {
            if (!Settings::enabled()) {
                return [];
            }
            $taskClass = '\App\Plugin\ThreadManager\Task\Task';
            if (!class_exists($taskClass)) {
                return [];
            }

            return [
                //spool 消费：唯一的写库入口，优先级最高
                $taskClass::make('ingest', IngestTask::class)
                    ->interval(2)->bootDb()->concurrency(1)
                    ->restartAlways()->priority(20)->hungAfter(120),

                //当天维度与天表的全量重算（幂等，随便重跑）
                $taskClass::make('rollup', RollupTask::class)
                    ->interval(60)->delay(5)->bootDb()
                    ->restartAlways()->hungAfter(600),

                //清理、分区维护、规则重编译
                $taskClass::make('sweep', SweepTask::class)
                    ->interval(300)->delay(20)->bootDb()
                    ->restartAlways()->hungAfter(900),

                //封上一天的账 + 日报
                $taskClass::make('daily', DailyTask::class)
                    ->cron('7 0 * * *')->bootDb()
                    ->restartAlways()->hungAfter(1800),

                //蜘蛛反查（绝不能放进网页请求，gethostbyaddr 没有超时参数）
                $taskClass::make('spider', SpiderVerifyTask::class)
                    ->interval(300)->delay(40)->bootDb()
                    ->restartOnFailure()->hungAfter(600),

                //IP 地理库更新。
                //刻意用 interval 而不是 cron：cron 任务只在到点那一刻醒一次，
                //站长点了「立即更新」之后要等到下个周期才被读到，按钮等于没用。
                //改成每分钟醒一次、由 Core\Geo\Schedule 判断该不该下载 ——
                //绝大多数轮次几微秒就返回了，代价可以忽略。
                $taskClass::make('geo', GeoUpdateTask::class)
                    ->interval(60)->delay(15)->bootDb()
                    ->restartOnFailure()->hungAfter(1800),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
