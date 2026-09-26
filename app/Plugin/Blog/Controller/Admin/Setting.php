<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller\Admin;

use App\Controller\Base\API\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use App\Plugin\Blog\Core\Counter;
use App\Plugin\Blog\Core\Markdown\Renderer;
use App\Plugin\Blog\Core\PostService;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Model\Post as PostModel;
use Kernel\Annotation\Interceptor;
use Kernel\Waf\Filter;

/**
 * 博客设置（独立设置页）+ 维护动作。
 * 保存走 App\Util\Plugin::setConfig（自动清 plugin.cache + Opcache 失效），
 * 校验统一复用 Settings::validate（与 SAVE_CONFIG 生命周期同一套规则）。
 */
#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Setting extends ManagePlugin
{
    public function get(): array
    {
        return $this->json(data: [
            'values' => Settings::all(),
            'defaults' => Settings::defaults(),
            'styles' => Settings::STYLES,
        ]);
    }

    public function save(): array
    {
        $map = [];
        foreach (array_keys(Settings::defaults()) as $key) {
            $value = $this->request->post($key, Filter::NORMAL);
            if ($value === null) {
                continue;
            }
            $map[$key] = is_scalar($value) ? trim((string)$value) : '';
        }

        Settings::validate($map);

        foreach ($map as $key => $value) {
            \App\Util\Plugin::setConfig(Settings::PLUGIN, $key, $value);
        }
        Settings::refresh();

        ManageLog::log($this->getManage(), '[次元博客]更新了博客设置');
        return $this->json(200, '（＾∀＾）设置已保存');
    }

    /**
     * 维护：分批重建渲染缓存（前端循环调用直到 remaining=0）。
     */
    public function rebuildRender(): array
    {
        $batch = PostModel::query()
            ->where(function ($query) {
                $query->where('render_version', '<', Renderer::VERSION)
                    ->orWhere(function ($q) {
                        $q->where(function ($qq) {
                            $qq->whereNull('content_html')->orWhere('content_html', '');
                        })->where('content_md', '<>', '');
                    });
            })
            ->orderBy('id')
            ->limit(30)
            ->get();

        $done = 0;
        foreach ($batch as $post) {
            PostService::lazyRender($post);
            $done++;
        }

        $remaining = PostModel::query()
            ->where('render_version', '<', Renderer::VERSION)
            ->count();

        return $this->json(data: ['done' => $done, 'remaining' => $remaining]);
    }

    /**
     * 维护：全量重算计数。
     */
    public function recalcCounts(): array
    {
        $result = Counter::recalcAll();
        ManageLog::log($this->getManage(), '[次元博客]重算了全部计数');
        return $this->json(200, '计数已重算', $result);
    }
}
