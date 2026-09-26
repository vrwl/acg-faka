<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Hook;

use App\Plugin\Blog\Core\Log;
use App\Plugin\Blog\Core\Schema;
use App\Plugin\Blog\Core\Seed;
use App\Plugin\Blog\Core\Settings;
use Kernel\Annotation\Plugin;
use Kernel\Exception\JSONException;

/**
 * 生命周期（#[Plugin(state:)] 是现场反射，改动即时生效，不吃 hook 缓存）。
 * INSTALL/START/UPGRADE 全走幂等 bootstrap；UNINSTALL 平台从不调用（清理 SQL 见 Wiki）。
 */
class Lifecycle
{
    #[Plugin(state: Plugin::INSTALL)]
    public function install(): void
    {
        //安装失败只记日志不阻断（商店安装流程里抛异常体验差），START 时还有一次严格补救
        $this->bootstrap(false, 'install');
    }

    #[Plugin(state: Plugin::START)]
    public function start(): void
    {
        //启用失败必须让站长看到（抛 JSONException 会拒绝启用）
        $this->bootstrap(true, 'start');
    }

    #[Plugin(state: Plugin::UPGRADE)]
    public function upgrade(): void
    {
        $this->bootstrap(false, 'upgrade');
    }

    #[Plugin(state: Plugin::STOP)]
    public function stop(): void
    {
        //数据全部保留；停用后 /plugin/Blog/* 自动跳首页、/blog 短链随 hook 卸载自然失效
        Log::info('插件已停用');
    }

    /**
     * 任何入口写配置（后台设置页/通用弹窗/MCP）都先经过这里：校验失败拒绝保存。
     */
    #[Plugin(state: Plugin::SAVE_CONFIG)]
    public function saveConfig(string $pluginName = '', array $map = []): void
    {
        Settings::validate(is_array($map) ? $map : []);
        Settings::refresh();
    }

    private function bootstrap(bool $strict, string $stage): void
    {
        try {
            Schema::ensure();

            $purifierDir = BASE_PATH . '/runtime/blog-purifier';
            if (!is_dir($purifierDir)) {
                @mkdir($purifierDir, 0777, true);
            }

            Seed::ensure();

            if (method_exists(\Kernel\Util\Lang::class, 'scanExtensionPacks')) {
                \Kernel\Util\Lang::scanExtensionPacks();
            }

            Log::info("bootstrap({$stage}) 完成 (schema v" . Schema::VERSION . ")");
        } catch (\Throwable $e) {
            Log::error("bootstrap({$stage}) 失败: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
            if ($strict) {
                throw new JSONException("次元博客初始化失败：" . $e->getMessage());
            }
        }
    }
}
