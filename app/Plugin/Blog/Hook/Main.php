<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Hook;

use App\Controller\Base\View\ManagePlugin;
use App\Plugin\Blog\Core\CommodityLink;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Support\Bridge;
use App\Plugin\Blog\Support\Url;
use Kernel\Annotation\Hook;

/**
 * 订阅点位一次定稿（改任何 #[Hook] 注解都必须后台「停用→启用」重建 runtime/plugin/hook 加密缓存，
 * 所以点位固定在这里，后续只改方法体/被委托的类）：
 *   0x3  = ADMIN_VIEW_MENU       后台侧栏菜单
 *   0x88 = USER_VIEW_HEADER_NAV  商城前台顶栏导航（数组收集：每个订阅者返回一个条目）
 *   0x57 = USER_VIEW_MENU        会员中心顶部导航
 *   0x48 = HTTP_NOT_FOUND        /blog 短链桥接（点位为 3.5.8+ 新增，写字面量兼容老核心）
 *   0x30 = KERNEL_INIT           独立域名接管（路由前，命中绑定域名则整站只剩博客）
 *   0x51 = USER_API_INDEX_COMMODITY_DETAIL_INFO  商品详情按引用传入，注入相关文档卡
 *
 * 继承 ManagePlugin 是为了在 adminMenu 里 render 插件自己的 Menu.html（ThirdDockManage 先例）。
 */
class Main extends ManagePlugin
{
    /**
     * 商城顶栏「博客」图标：翻开的书。
     * 1em 尺寸 + currentColor，跟着各主题的字号与文字颜色走；
     * vertical-align 对齐文字基线，免得比旁边的字体图标高半格。
     */
    private const NAV_SVG = '<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-.14em">'
    . '<path d="M12 7.2C10.5 5.9 8.4 5.2 5.8 5.2H3.6v12.6h2.2c2.6 0 4.7.7 6.2 2"/>'
    . '<path d="M12 7.2c1.5-1.3 3.6-2 6.2-2h2.2v12.6h-2.2c-2.6 0-4.7.7-6.2 2"/>'
    . '</svg>';

    /**
     * 后台侧栏：多级 accordion 菜单
     */
    #[Hook(point: 0x3)]
    public function adminMenu(): void
    {
        try {
            //hook 上下文：render 第 4 参保持默认 false（走 Plugin::$currentPluginName 定位模板）
            echo $this->render(null, 'Menu.html');
        } catch (\Throwable $e) {
            //菜单渲染失败不能拖垮整个后台
        }
    }

    /**
     * 商城前台顶栏导航条目。
     * ⚠ 老版 PHP 主题（Toka/Magic 等）直取 name/url/icon/target 四键且无 isset 守护，必须齐全；
     *   micon/match 是数组化模板的增量键，老主题自动忽略。
     */
    #[Hook(point: 0x88)]
    public function headerNav(): ?array
    {
        try {
            if (!Settings::bool('nav_inject_shop')) {
                return null;
            }
            return [
                'name' => Settings::navLabel(),
                'url' => Url::index(),
                //首选内联 SVG：各主题装的图标字体版本不一（FA4 的 `fa fa-xxx` / FA6 的
                //`fa-duotone fa-regular fa-xxx`），给哪一边的 class 都会在另一边渲染成空白。
                //SVG 用 currentColor + 1em，颜色字号自动跟随各主题
                'svg' => self::NAV_SVG,
                //兜底：主题没接 user_nav_icon() 时仍按 class 渲染
                'icon' => 'fa-duotone fa-regular fa-file-lines',
                'micon' => 'auto_stories',
                'target' => '_self',
                'match' => Url::matchPrefix(),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 会员中心顶部导航（Cartoon uc-nav 一级项标记）
     */
    #[Hook(point: 0x57)]
    public function ucMenu(): void
    {
        try {
            if (!Settings::bool('nav_inject_uc')) {
                return;
            }
            $url = htmlspecialchars(Url::index(), ENT_QUOTES);
            $match = htmlspecialchars(Url::matchPrefix(), ENT_QUOTES);
            echo '<li><a class="uc-nav__item" href="' . $url . '" data-match="' . $match . '">'
                . '<span class="material-icons-outlined">auto_stories</span>'
                . htmlspecialchars(Settings::navLabel(), ENT_QUOTES)
                . '</a></li>';
        } catch (\Throwable $e) {
            //ignore
        }
    }

    /**
     * 独立域名接管。必须挂在路由之前的 KERNEL_INIT：绑定域名下 / 是博客首页，
     * 而 / 在内核里会正常路由到商城首页、根本走不到 404 钩子。
     * 未绑定或非博客域名时原样返回，商城不受任何影响。
     */
    #[Hook(point: 0x30)]
    public function standaloneDomain(): void
    {
        Bridge::handleStandalone();
    }

    /**
     * /blog 优雅短链：内核 404 兜底钩子。接管的请求在 Bridge 内部输出并 exit，不会返回。
     */
    #[Hook(point: 0x48)]
    public function bridge(string $routePath = ''): void
    {
        Bridge::handle((string)$routePath);
    }

    /**
     * 商品详情：把绑定了该商品的教程卡片插到「商品介绍」最前面。
     * $item 是按引用传入的（见 kernel/Util/Plugin::hook 的 `mixed &...$args`），
     * 改写 description 即可让 16 套主题全部自动生效，无需改任何模板。
     * 点位 0x51 = USER_API_INDEX_COMMODITY_DETAIL_INFO，写字面量兼容老核心。
     */
    #[Hook(point: 0x51)]
    public function commodityDetail(mixed &$item): void
    {
        try {
            if (!is_array($item) || empty($item['id'])) {
                return;
            }
            if (!Settings::bool('item_inject')) {
                return;
            }
            $posts = CommodityLink::postsForCommodity((int)$item['id']);
            if (!$posts) {
                return;
            }
            $item['description'] = CommodityLink::renderGuide($posts) . (string)($item['description'] ?? '');
        } catch (\Throwable $e) {
            //商品页不能因为博客出问题而打不开
        }
    }
}
