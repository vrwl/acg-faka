<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Hook;

use Kernel\Annotation\Hook;

/**
 * 后台左侧菜单入口。
 *
 * 视图钩子是 echo 输出而非 return —— 返回字符串会被派发器拼进结果串，
 * 这里跟着 ThreadManager / 通知中心的写法直接 echo。
 * 整个方法包在 try/catch 里：菜单钩子一旦抛异常，整个后台都会白屏。
 */
class Menu
{
    #[Hook(point: \App\Consts\Hook::ADMIN_VIEW_MENU)]
    public function ADMIN_VIEW_MENU(): void
    {
        try {
            $router = (string)getLocalRouter();
            $active = str_starts_with($router, '/plugin/WebsiteMonitor') ? 'active' : '';
            $title = lang('网站监控', 'tpl');
            echo <<<HTML
<div class="menu-item">
    <a class="menu-link {$active}" href="/plugin/WebsiteMonitor/panel/index">
        <span class="menu-icon"><span class="svg-icon svg-icon-2"><i class="fa-duotone fa-regular fa-chart-mixed"></i></span></span>
        <span class="menu-title">{$title}</span>
    </a>
</div>
HTML;
        } catch (\Throwable $e) {
        }
    }
}
