<?php
declare(strict_types=1);

namespace App\Plugin\AlipayPersonal\Core;

use App\Model\PayConfig as PayConfigModel;
use App\Util\Date;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * 表结构与配置迁移，幂等（START / UPGRADE 都会跑）。
 */
final class Schema
{
    public const TABLE = 'alipay_personal';

    /** 每个进程只跑一次，别在热路径反复打 information_schema */
    private static bool $done = false;

    public static function ensureOnce(): void
    {
        if (self::$done) {
            return;
        }
        self::ensure();
        self::$done = true;
    }

    public static function ensure(): void
    {
        $schema = Manager::schema();

        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function (Blueprint $t) {
                $t->increments('id')->unsigned()->comment('主键');
                $t->char('trade_no', 32)->nullable(false)->unique()->comment('订单号');
                $t->decimal('amount', 10, 2);
                $t->decimal('pay_amount', 10, 2)->index();
                $t->string('return_url', 255)->nullable();
                $t->string('notification_url', 255)->nullable();
                $t->string('type', 10)->nullable(false)->index();
                $t->tinyInteger('status')->nullable(false)->unsigned()->index();
                $t->dateTime('create_time')->nullable(false);
                $t->dateTime('pay_time')->nullable();
                $t->unsignedInteger('pay_config_id')->default(0)->index()->comment('订单归属的收款配置档，0=老订单/全局');
            });
            return;
        }

        //1.x → 2.0：补上归属列。老订单留 0，回调时仍能匹配（见 Bind\Order::callback）
        if (!$schema->hasColumn(self::TABLE, 'pay_config_id')) {
            $schema->table(self::TABLE, function (Blueprint $t) {
                $t->unsignedInteger('pay_config_id')->default(0)->index()->comment('订单归属的收款配置档，0=老订单/全局');
            });
        }
    }

    /**
     * 把通用插件里的老收款配置搬进支付插件的默认配置档。
     *
     * 只在配置档还没有任何收款信息时做一次：升级完站长什么都不用动，
     * 打开支付插件配置就能看到原来那套 Token 与收款码。
     */
    public static function migrateLegacyProfile(): void
    {
        try {
            $legacy = Settings::global();
            $carry = [];
            foreach (['app_key', 'qrcode', 'alipay_url'] as $key) {
                $v = trim((string)($legacy[$key] ?? ''));
                if ($v !== '') {
                    $carry[$key] = $v;
                }
            }
            if ($carry === []) {
                return;
            }

            $row = PayConfigModel::query()
                ->where('handle', Settings::HANDLE)
                ->orderBy('sort')->orderBy('id')
                ->first();

            if (!$row) {
                $row = new PayConfigModel();
                $row->handle = Settings::HANDLE;
                $row->name = '默认配置';
                $row->sort = 0;
                $row->config = '{}';
                $row->create_time = Date::current();
            }

            $cfg = json_decode((string)$row->config, true);
            $cfg = is_array($cfg) ? $cfg : [];

            //已经有收款信息就不覆盖——站长可能已经手工配过了
            if (trim((string)($cfg['qrcode'] ?? '')) !== '' || trim((string)($cfg['app_key'] ?? '')) !== '') {
                return;
            }

            $row->config = json_encode(array_merge($cfg, $carry), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $row->update_time = Date::current();
            $row->save();
            \App\Util\PayProfile::flush(Settings::HANDLE, (int)$row->id);
            Log::info('已把通用插件里的收款配置迁入默认配置档', ['config_id' => (int)$row->id, 'keys' => array_keys($carry)]);
        } catch (\Throwable $e) {
            Log::warn('老配置迁移失败', ['error' => $e->getMessage()]);
        }
    }
}
