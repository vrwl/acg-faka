<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller;

use App\Controller\Base\View\ManagePlugin;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;

/**
 * 后台页面出口（只渲染 HTML，数据全部走 /plugin/Blog/admin/* API）。
 * render 第 4 参必须 true（控制器路由场景用 $currentControllerPluginName 定位模板根）。
 */
#[Interceptor(ManageSession::class, Interceptor::TYPE_VIEW)]
class Panel extends ManagePlugin
{
    public function posts(): string
    {
        return $this->render(lang('博客文章'), 'Panel/Posts.html', [], true);
    }

    public function write(): string
    {
        return $this->render(lang('写文章'), 'Panel/Write.html', [], true);
    }

    public function pages(): string
    {
        return $this->render(lang('独立页面'), 'Panel/Pages.html', [], true);
    }

    public function categories(): string
    {
        return $this->render(lang('博客分类'), 'Panel/Categories.html', [], true);
    }

    public function tags(): string
    {
        return $this->render(lang('博客标签'), 'Panel/Tags.html', [], true);
    }

    public function comments(): string
    {
        return $this->render(lang('博客评论'), 'Panel/Comments.html', [], true);
    }

    public function settings(): string
    {
        return $this->render(lang('博客设置'), 'Panel/Settings.html', [], true);
    }
}
