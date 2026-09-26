<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller;

use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Model\Post;
use App\Plugin\Blog\Model\Tag as TagModel;
use App\Plugin\Blog\Support\FrontController;
use App\Plugin\Blog\Support\Paginate;
use App\Plugin\Blog\Support\Present;
use App\Plugin\Blog\Support\Url;
use Kernel\Annotation\Interceptor;

/**
 * 标签云 + 标签详情文章流。
 */
#[Interceptor([Waf::class, UserVisitor::class])]
class Tag extends FrontController
{
    public function index(): string
    {
        $tags = [];
        $max = 1;
        foreach (TagModel::query()->where('post_count', '>', 0)
                     ->orderByDesc('post_count')->limit(200)->get() as $tag) {
            $max = max($max, (int)$tag->post_count);
            $tags[] = [
                'name' => (string)$tag->name,
                'slug' => (string)$tag->slug,
                'url' => Url::tag((string)$tag->slug),
                'post_count' => (int)$tag->post_count,
            ];
        }
        //权重三档（字号档位，不做夸张字云）
        foreach ($tags as &$tag) {
            $ratio = $tag['post_count'] / $max;
            $tag['size'] = $ratio > 0.66 ? 3 : ($ratio > 0.33 ? 2 : 1);
        }

        return $this->blogRender('TagCloud.html', [
            'title' => lang('标签'),
            'canonical' => Url::tagIndex(),
            'active' => 'category',
            'is_sub' => true,
        ], [
            'tags' => $tags,
        ]);
    }

    public function detail(string $slug = ''): string
    {
        $slug = mb_strtolower(trim($slug));
        /** @var TagModel|null $tag */
        $tag = $slug === '' ? null : TagModel::query()->where('slug', $slug)->first();
        if (!$tag) {
            return $this->notFound();
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = Settings::int('posts_per_page', 1, 50);
        $query = Post::query()->where('type', Post::TYPE_POST)->visible()
            ->whereHas('tags', function ($q) use ($tag) {
                $q->where('blog_tag.id', (int)$tag->id);
            })
            ->with(['category:id,name,slug', 'tags:id,name,slug']);
        $total = (clone $query)->count();
        $posts = [];
        foreach ($query->orderByDesc('publish_time')
                     ->offset(($page - 1) * $perPage)->limit($perPage)->get() as $post) {
            $posts[] = Present::card($post);
        }

        $pagination = Paginate::build($page, $perPage, $total, function (int $n) use ($slug) {
            return Url::tag($slug, $n);
        });

        return $this->blogRender('Tag.html', [
            'title' => '#' . (string)$tag->name,
            'canonical' => Url::tag($slug, $page),
            'active' => 'category',
            'is_sub' => true,
        ], [
            'tag' => ['name' => (string)$tag->name, 'slug' => (string)$tag->slug, 'post_count' => (int)$tag->post_count],
            'posts' => $posts,
            'pagination' => $pagination,
        ]);
    }
}
