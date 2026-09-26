<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller;

use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Plugin\Blog\Core\PostService;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Model\Post as PostModel;
use App\Plugin\Blog\Model\PostLike;
use App\Plugin\Blog\Support\FrontController;
use App\Plugin\Blog\Support\Present;
use App\Plugin\Blog\Support\Url;
use Kernel\Annotation\Interceptor;

/**
 * 文章详情。
 */
#[Interceptor([Waf::class, UserVisitor::class])]
class Post extends FrontController
{
    public function detail(string $slug = ''): string
    {
        $slug = mb_strtolower(trim($slug));
        /** @var PostModel|null $post */
        $post = $slug === '' ? null : PostModel::query()
            ->where('type', PostModel::TYPE_POST)
            ->where('slug', $slug)
            ->visible()
            ->with(['category:id,name,slug', 'tags:id,name,slug'])
            ->first();
        if (!$post) {
            return $this->notFound();
        }

        PostService::lazyRender($post);
        $this->countView($post);

        $detail = Present::detail($post);

        //上一篇 / 下一篇（按发布时间轴）
        $prev = PostModel::query()->where('type', PostModel::TYPE_POST)->visible()
            ->where('publish_time', '<', $post->publish_time)
            ->orderByDesc('publish_time')->first(['id', 'title', 'slug', 'cover']);
        $next = PostModel::query()->where('type', PostModel::TYPE_POST)->visible()
            ->where('publish_time', '>', $post->publish_time)
            ->orderBy('publish_time')->first(['id', 'title', 'slug', 'cover']);

        //相关文章：同分类或同标签，兜底最新
        $tagIds = $post->tags->pluck('id')->all();
        $relatedQuery = PostModel::query()->where('type', PostModel::TYPE_POST)->visible()
            ->where('id', '<>', $post->id)
            ->with(['category:id,name,slug'])
            ->where(function ($query) use ($post, $tagIds) {
                $applied = false;
                if ((int)$post->category_id > 0) {
                    $query->where('category_id', (int)$post->category_id);
                    $applied = true;
                }
                if ($tagIds) {
                    $method = $applied ? 'orWhereHas' : 'whereHas';
                    $query->{$method}('tags', function ($q) use ($tagIds) {
                        $q->whereIn('blog_tag.id', $tagIds);
                    });
                    $applied = true;
                }
                if (!$applied) {
                    $query->whereRaw('1=1');
                }
            });
        $related = [];
        foreach ($relatedQuery->orderByDesc('publish_time')->limit(3)->get() as $item) {
            $related[] = Present::card($item);
        }
        if (!$related) {
            foreach (PostModel::query()->where('type', PostModel::TYPE_POST)->visible()
                         ->where('id', '<>', $post->id)->with(['category:id,name,slug'])
                         ->orderByDesc('publish_time')->limit(3)->get() as $item) {
                $related[] = Present::card($item);
            }
        }

        $user = $this->getUser();
        $liked = false;
        if ($user && Settings::bool('like_enabled')) {
            $liked = PostLike::query()->where('post_id', $post->id)->where('user_id', $user->id)->exists();
        }

        $blogName = $this->siteBlogName();
        $jsonld = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $detail['title'],
            'description' => $detail['seo_description'] !== '' ? $detail['seo_description'] : $detail['summary'],
            'datePublished' => $detail['publish_iso'],
            'dateModified' => $detail['update_iso'],
            'mainEntityOfPage' => Url::absolute($detail['url']),
            'author' => ['@type' => 'Person', 'name' => $detail['author_name'] ?: $blogName],
        ];
        if ($detail['cover'] !== '') {
            $jsonld['image'] = Url::absolute($detail['cover']);
        }
        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_values(array_filter([
                ['@type' => 'ListItem', 'position' => 1, 'name' => $blogName, 'item' => Url::absolute(Url::index())],
                $detail['category'] ? ['@type' => 'ListItem', 'position' => 2, 'name' => $detail['category']['name'], 'item' => Url::absolute($detail['category']['url'])] : null,
                ['@type' => 'ListItem', 'position' => $detail['category'] ? 3 : 2, 'name' => $detail['title'], 'item' => Url::absolute($detail['url'])],
            ])),
        ];

        return $this->blogRender('Post.html', [
            'title' => $detail['seo_title'] !== '' ? $detail['seo_title'] : $detail['title'],
            'description' => $detail['seo_description'] !== '' ? $detail['seo_description'] : mb_substr($detail['summary'], 0, 150),
            'keywords' => $detail['seo_keywords'] !== '' ? $detail['seo_keywords'] : implode(',', array_map(fn($t) => $t['name'], $detail['tags'])),
            'canonical' => $detail['url'],
            'og_type' => 'article',
            'og_image' => $detail['cover'] !== '' ? Url::absolute($detail['cover']) : '',
            'published_iso' => $detail['publish_iso'],
            'modified_iso' => $detail['update_iso'],
            'jsonld' => [$jsonld, $breadcrumb],
            'active' => 'home',
            'is_sub' => true,
            'body_class' => 'blog-page-post',
        ], [
            'post' => $detail,
            'prev' => $prev ? ['title' => $prev->title, 'url' => Url::post((string)$prev->slug), 'cover' => (string)$prev->cover] : null,
            'next' => $next ? ['title' => $next->title, 'url' => Url::post((string)$next->slug), 'cover' => (string)$next->cover] : null,
            'related' => $related,
            'liked' => $liked ? 1 : 0,
        ]);
    }

    /** 浏览量（cookie 窗口去重） */
    private function countView(PostModel $post): void
    {
        try {
            $minutes = Settings::int('view_dedupe_minutes', 0, 1440);
            $cookie = 'blog_v_' . $post->id;
            if ($minutes > 0 && isset($_COOKIE[$cookie])) {
                return;
            }
            PostModel::query()->where('id', $post->id)->increment('views');
            $post->views = (int)$post->views + 1;
            if ($minutes > 0 && !headers_sent()) {
                setcookie($cookie, '1', ['expires' => time() + $minutes * 60, 'path' => '/', 'samesite' => 'Lax']);
            }
        } catch (\Throwable $e) {
            //浏览量不能影响页面
        }
    }
}
