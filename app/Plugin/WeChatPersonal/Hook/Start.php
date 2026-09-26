<?php
declare (strict_types=1);

namespace App\Plugin\WeChatPersonal\Hook;

use App\Plugin\WeChatPersonal\Core\Log;
use App\Plugin\WeChatPersonal\Core\Schema;
use Kernel\Annotation\Plugin;

/**
 * 生命周期：建表 + 老配置迁移，全部幂等。
 */
class Start
{
    #[Plugin(state: Plugin::START)]
    public function start(): void
    {
        $this->bootstrap('start');
    }

    #[Plugin(state: Plugin::INSTALL)]
    public function install(): void
    {
        $this->bootstrap('install');
    }

    #[Plugin(state: Plugin::UPGRADE)]
    public function upgrade(): void
    {
        $this->bootstrap('upgrade');
    }

    private function bootstrap(string $reason): void
    {
        try {
            Schema::ensure();
            Schema::migrateLegacyProfile();
            Log::info('插件初始化完成', ['reason' => $reason]);
        } catch (\Throwable $e) {
            Log::warn('插件初始化失败', ['reason' => $reason, 'error' => $e->getMessage()]);
        }
    }
}
