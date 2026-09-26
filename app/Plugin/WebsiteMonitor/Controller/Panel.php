<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Controller;

use App\Controller\Base\View\ManagePlugin;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;

/**
 * 监控面板视图。路由：/plugin/WebsiteMonitor/panel/index
 *
 * render() 第四个参数必须是 true —— 它让框架用「路由解析出来的插件名」
 * 而不是「钩子派发时的当前插件名」去找模板目录。传 false 会在某些
 * 钩子上下文里找错目录。
 */
#[Interceptor(ManageSession::class, Interceptor::TYPE_VIEW)]
class Panel extends ManagePlugin
{
    public function index(): string
    {
        return $this->render(lang('网站监控统计'), 'Panel/Index.html', [], true);
    }
}
