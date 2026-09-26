<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller\Api;

use App\Controller\Base\API\UserPlugin;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Model\Post as PostModel;
use App\Plugin\Blog\Support\Present;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

/**
 * 前台文章流数据源（无限滚动）。
 */
#[Interceptor([Waf::class, UserVisitor::class], Interceptor::TYPE_API)]
class Post extends UserPlugin
{
    public function list(): array
    {
        if (!Settings::bool('guest_visible') && !$this->getUser()) {
            //显式告诉前端「这是需要登录」，别让它靠 code 猜：
            //JSONException 的 code 恒为 0，与拦截器的登录失效码撞车
            return $this->json(0, '请登录后访问博客', ['need_login' => 1]);
        }

        $page = max(1, (int)$this->request->post('page'));
        $perPage = min(30, max(1, (int)($this->request->post('limit') ?: Settings::int('posts_per_page', 1, 50))));
        $category = mb_strtolower(trim((string)$this->request->post('category', Filter::NORMAL)));
        $tag = mb_strtolower(trim((string)$this->request->post('tag', Filter::NORMAL)));
        $q = mb_substr(trim((string)$this->request->post('q', Filter::NORMAL)), 0, 60);
        $excludeTop = (string)$this->request->post('exclude_top') === '1';

        $query = PostModel::query()->where('type', PostModel::TYPE_POST)->visible()
            ->with(['category:id,name,slug', 'tags:id,name,slug']);

        if ($category !== '') {
            $query->whereHas('category', function ($builder) use ($category) {
                $builder->where('slug', $category);
            });
        }
        if ($tag !== '') {
            $query->whereHas('tags', function ($builder) use ($tag) {
                $builder->where('blog_tag.slug', $tag);
            });
        }
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('title', 'like', $like)->orWhere('summary', 'like', $like);
            });
        }
        if ($excludeTop) {
            $query->where('top', 0);
        }

        $total = (clone $query)->count();
        $list = [];
        $ordered = $category !== '' ? $query->orderByDesc('top')->orderByDesc('publish_time') : $query->orderByDesc('publish_time');
        foreach ($ordered->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get() as $post) {
            $list[] = Present::card($post);
        }

        return $this->json(data: ['list' => $list, 'total' => $total, 'page' => $page]);
    }
}
