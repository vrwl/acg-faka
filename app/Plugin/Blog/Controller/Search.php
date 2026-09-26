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
 * 搜索：无 q = 搜索首页（热门标签 + 本地历史由前端渲染）；有 q = SSR 结果页 + 命中高亮。
 * 只搜标题与摘要（LIKE），不搜全文防慢查询。
 */
#[Interceptor([Waf::class, UserVisitor::class])]
class Search extends FrontController
{
    public function index(): string
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $q = mb_substr($q, 0, 60);

        $hotTags = [];
        foreach (TagModel::query()->where('post_count', '>', 0)->orderByDesc('post_count')->limit(12)->get() as $tag) {
            $hotTags[] = ['name' => (string)$tag->name, 'url' => Url::tag((string)$tag->slug)];
        }

        $posts = [];
        $pagination = null;
        $total = 0;
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = Settings::int('posts_per_page', 1, 50);
            $query = Post::query()->where('type', Post::TYPE_POST)->visible()
                ->where(function ($builder) use ($like) {
                    $builder->where('title', 'like', $like)
                        ->orWhere('summary', 'like', $like);
                })
                ->with(['category:id,name,slug', 'tags:id,name,slug']);
            $total = (clone $query)->count();
            foreach ($query->orderByDesc('publish_time')
                         ->offset(($page - 1) * $perPage)->limit($perPage)->get() as $post) {
                $card = Present::card($post);
                $card['title_hl'] = $this->highlight($card['title'], $q);
                $card['summary_hl'] = $this->highlight($card['summary'], $q);
                $posts[] = $card;
            }
            $pagination = Paginate::build($page, $perPage, $total, function (int $n) use ($q) {
                $url = Url::search($q);
                return $n > 1 ? $url . (str_contains($url, '?') ? '&' : '?') . 'page=' . $n : $url;
            });
        }

        return $this->blogRender('Search.html', [
            'title' => $q === '' ? lang('搜索') : $q . ' - ' . lang('搜索'),
            'canonical' => Url::search($q),
            'active' => 'search',
            'tab' => 'search',
            'noindex' => $q !== '',
        ], [
            'q' => $q,
            'posts' => $posts,
            'total' => $total,
            'pagination' => $pagination,
            'hot_tags' => $hotTags,
        ]);
    }

    /** 命中词高亮（先转义再包 mark，防注入） */
    private function highlight(string $text, string $q): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        if ($q === '') {
            return $escaped;
        }
        $quoted = preg_quote(htmlspecialchars($q, ENT_QUOTES, 'UTF-8'), '/');
        return preg_replace('/(' . $quoted . ')/iu', '<mark>$1</mark>', $escaped) ?? $escaped;
    }
}
