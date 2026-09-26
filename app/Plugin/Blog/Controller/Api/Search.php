<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller\Api;

use App\Controller\Base\API\UserPlugin;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Model\Post as PostModel;
use App\Plugin\Blog\Support\Url;
use Kernel\Annotation\Interceptor;
use Kernel\Waf\Filter;

/**
 * 搜索即时建议（标题前缀命中，轻量 5 条）。
 */
#[Interceptor([Waf::class, UserVisitor::class], Interceptor::TYPE_API)]
class Search extends UserPlugin
{
    public function suggest(): array
    {
        if (!Settings::bool('guest_visible') && !$this->getUser()) {
            return $this->json(data: ['list' => []]);
        }
        $q = mb_substr(trim((string)$this->request->post('q', Filter::NORMAL)), 0, 60);
        if (mb_strlen($q) < 1) {
            return $this->json(data: ['list' => []]);
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $list = [];
        foreach (PostModel::query()->where('type', PostModel::TYPE_POST)->visible()
                     ->where('title', 'like', $like)
                     ->orderByDesc('views')->limit(5)->get(['title', 'slug']) as $post) {
            $list[] = ['title' => (string)$post->title, 'url' => Url::post((string)$post->slug)];
        }
        return $this->json(data: ['list' => $list]);
    }
}
