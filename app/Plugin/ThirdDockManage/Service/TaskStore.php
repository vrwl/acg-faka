<?php
declare(strict_types=1);

namespace App\Plugin\ThirdDockManage\Service;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

/**
 * 商品采集批次任务数据的存取层。
 * 批次数据带有处理进度，需要在 Web 轮询与 CLI 之间共享，并在流程结束后删除。
 * 历史实现落盘为 Db/task.php，但批次键为随机号，无法触发读取式过期清理，文件会无限膨胀直至 PHP 加载溢出；
 * 现改为一个批次一行入库，配合"用完即删 + 采集入口清扫 + 过期兜底"三层清理。
 */
class TaskStore
{
    private const TABLE = 'third_dock_sync_tasks';

    /** 批次兜底时长（小时）：正常流程结束后批次即被删除，此值仅覆盖异常中断的残留 */
    private const TTL_HOURS = 6;

    private static bool $booted = false;

    public static function saveSite(string $batch, int $siteId, array $site): void
    {
        self::upsert($batch, $siteId, 'site_data', self::encode($site));
    }

    public static function saveTask(string $batch, int $siteId, array $task): void
    {
        //array_diff 后的数组键不连续，需重建索引，避免 JSON 编码成对象
        foreach (['step', 'all_ids', 'deal_ids'] as $list) {
            if (isset($task[$list]) && is_array($task[$list])) {
                $task[$list] = array_values($task[$list]);
            }
        }
        self::upsert($batch, $siteId, 'task_data', self::encode($task));
    }

    public static function getSite(string $batch): ?array
    {
        return self::decodeColumn($batch, 'site_data');
    }

    public static function getTask(string $batch): ?array
    {
        return self::decodeColumn($batch, 'task_data');
    }

    /**
     * 批次生命周期结束（采集完成、或任务数据已被取走），整行删除
     */
    public static function forget(string $batch): void
    {
        DB::table(self::TABLE)->where('batch', $batch)->delete();
    }

    /**
     * 清扫超过兜底时长的残留批次（挂在每次采集入口，覆盖进程被杀、轮询中断等场景）
     */
    public static function cleanup(): void
    {
        self::ensureTable();
        DB::table(self::TABLE)->where('expire_at', '<', Carbon::now())->delete();
    }

    private static function upsert(string $batch, int $siteId, string $column, string $json): void
    {
        self::ensureTable();
        $now = Carbon::now();
        $expire = $now->copy()->addHours(self::TTL_HOURS);
        $query = DB::table(self::TABLE)->where('batch', $batch);
        if ($query->exists()) {
            $query->update([
                $column => $json,
                'expire_at' => $expire,
                'updated_at' => $now,
            ]);
        } else {
            DB::table(self::TABLE)->insert([
                'batch' => $batch,
                'site_id' => $siteId,
                $column => $json,
                'expire_at' => $expire,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private static function decodeColumn(string $batch, string $column): ?array
    {
        self::ensureTable();
        $value = DB::table(self::TABLE)->where('batch', $batch)->value($column);
        if ($value === null) {
            return null;
        }
        $data = json_decode((string)$value, true);
        if (!is_array($data)) {
            //数据损坏时删除坏行，避免批次永久卡死
            self::forget($batch);
            return null;
        }
        return $data;
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 确保存储表存在（每进程只检查一次）。
     * 兜底场景：新代码部署后、插件 Start/Upgrade 钩子触发前，CLI 任务先行执行。
     */
    public static function ensureTable(): void
    {
        if (self::$booted) {
            return;
        }
        if (! DB::schema()->hasTable(self::TABLE)) {
            DB::schema()->create(self::TABLE, function (Blueprint $table) {
                $table->string('batch', 80)->primary()->comment('采集批次号');
                $table->unsignedInteger('site_id')->default(0)->comment('站点ID');
                $table->longText('site_data')->nullable()->comment('站点快照');
                $table->longText('task_data')->nullable()->comment('采集任务数据');
                $table->timestamp('expire_at')->nullable()->comment('兜底过期时间');
                $table->timestamps();
                $table->index('expire_at');
            });
        }
        self::$booted = true;
    }
}
