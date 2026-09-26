<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Hook;

use App\Controller\Base\View\UserPlugin;
use Kernel\Annotation\Hook;

/**
 * 前台钩子
 */
class UserHook extends UserPlugin
{
    /**
     * 单次请求内已注入脚本标记
     * 不同主题的页面会命中不同的钩子点，同一页面可能命中多个，避免重复加载脚本
     * @var bool
     */
    private static bool $injected = false;

    /**
     * 商城首页头部（商品详情页等）
     * @return void
     */
    #[Hook(point: 0x10001)]
    public function header(): void
    {
        $this->injectScripts();
    }

    /**
     * 全站公共头部（多数主题的通用 header）
     * @return void
     */
    #[Hook(point: 0x228)]
    public function globalHeader(): void
    {
        $this->injectScripts();
    }

    /**
     * 会员中心头部（订单列表所在页面）
     * @return void
     */
    #[Hook(point: 0x128)]
    public function memberHeader(): void
    {
        $this->injectScripts();
    }

    /**
     * 注入前台脚本，追加文件指纹：脚本改动后 URL 即变，绕开 nginx expires 7d 的强缓存
     * @return void
     */
    private function injectScripts(): void
    {
        if (self::$injected) {
            return;
        }
        self::$injected = true;
        echo '<script src="' . \Plugin('WkDock', 'View/wkdock.js') . '&_=' . $this->assetVersion('/app/Plugin/WkDock/View/wkdock.js') . '"></script>';
        echo '<script src="' . \Plugin('WkDock', 'View/wkorder.js') . '&_=' . $this->assetVersion('/app/Plugin/WkDock/View/wkorder.js') . '"></script>';
    }

    /**
     * 静态资源版本号：APP_VERSION + 文件指纹（mtime+size），取不到文件时只返回 APP_VERSION
     *
     * 插件自带实现，不依赖商城核心：原程序的 css()/js() 只挂 APP_VERSION，
     * 而 nginx 对 js/css 配了 expires 7d，脚本改了却不发版时浏览器会一直跑旧文件。
     * 这里把插件脚本自己的指纹拼进 URL，插件丢进原版程序即可用。
     *
     * @param string $resource 以 / 开头的站点根相对路径
     * @return string
     */
    private function assetVersion(string $resource): string
    {
        $file = BASE_PATH . ltrim($resource, '/');
        if (!is_file($file)) {
            return APP_VERSION;
        }
        $mtime = @filemtime($file);
        $size = @filesize($file);
        if ($mtime === false || $size === false) {
            return APP_VERSION;
        }
        return APP_VERSION . '.' . dechex($mtime) . '.' . dechex((int)$size);
    }
}
