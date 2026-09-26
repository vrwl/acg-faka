<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller;

use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Plugin\Blog\Core\Db;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Model\Post;
use App\Plugin\Blog\Model\Tag as TagModel;
use App\Plugin\Blog\Support\FrontController;
use App\Plugin\Blog\Support\Paginate;
use App\Plugin\Blog\Support\Present;
use App\Plugin\Blog\Support\Url;
use Kernel\Annotation\Interceptor;

/**
 * 博客首页（文章流 + 置顶横滑卡组）。
 */
#[Interceptor([Waf::class, UserVisitor::class])]
class Index extends FrontController
{
    public function index(): string
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = Settings::int('posts_per_page', 1, 50);

        $base = Post::query()
            ->where('type', Post::TYPE_POST)
            ->visible()
            ->with(['category:id,name,slug', 'tags:id,name,slug']);

        //置顶横滑卡组（仅第一页展示；正文流排除置顶避免重复）
        $tops = [];
        if ($page === 1) {
            foreach ((clone $base)->where('top', 1)->orderByDesc('publish_time')->limit(5)->get() as $post) {
                $tops[] = Present::card($post);
            }
        }

        $flow = (clone $base)->where('top', 0);
        $total = (clone $flow)->count();
        $posts = [];
        foreach ($flow->orderByDesc('publish_time')->orderByDesc('id')
                     ->offset(($page - 1) * $perPage)->limit($perPage)->get() as $post) {
            $posts[] = Present::card($post);
        }

        $pagination = Paginate::build($page, $perPage, $total, function (int $n) {
            return Url::index($n);
        });

        //侧栏：热门文章按浏览量取，标签云按文章数取（都只在有数据时渲染）
        $hotPosts = [];
        foreach ((clone $base)->orderByDesc('views')->orderByDesc('id')->limit(6)->get() as $post) {
            $hotPosts[] = [
                'title' => (string)$post->title,
                'url' => Url::post((string)$post->slug),
                'views' => (int)$post->views,
            ];
        }

        //标签数量走实时统计，理由同分类：去范式的 post_count 只按 status 算，
        //定时发布的文章会被计入，导致「标签显示 5 篇、点进去只有 4 篇」
        //注意别用 pluck()：它会用自己的 select 覆盖掉 selectRaw 里的别名，
        //导致 SQL 报 Unknown column 'aggregate'
        $tagRowsRaw = Db::table(Db::POST_TAG)
            ->join(Db::POST, Db::POST . '.id', '=', Db::POST_TAG . '.post_id')
            ->where(Db::POST . '.type', Post::TYPE_POST)
            ->where(Db::POST . '.status', Post::STATUS_PUBLISHED)
            ->where(Db::POST . '.publish_time', '<=', date('Y-m-d H:i:s'))
            ->groupBy(Db::POST_TAG . '.tag_id')
            //selectRaw 是原始 SQL，不会像 join/where 那样自动补 acg_ 前缀，
            //所以这里不能写表限定名；tag_id 在这个 join 里本来就不歧义
            ->selectRaw('tag_id, COUNT(*) AS aggregate')
            ->get();

        $hotTags = [];
        if (count($tagRowsRaw) > 0) {
            $ordered = [];
            foreach ($tagRowsRaw as $row) {
                $ordered[(int)$row->tag_id] = (int)$row->aggregate;
            }
            arsort($ordered);
            $ordered = array_slice($ordered, 0, 16, true);
            $tagRows = TagModel::query()->whereIn('id', array_keys($ordered))->get()->keyBy('id');
            foreach ($ordered as $tagId => $count) {
                $tag = $tagRows->get($tagId);
                if (!$tag) {
                    continue;
                }
                $hotTags[] = [
                    'name' => (string)$tag->name,
                    'url' => Url::tag((string)$tag->slug),
                    'count' => $count,
                ];
            }
        }

        return $this->blogRender('Index.html', [
            'title' => '',        //空 = 基座用「博客名 - 副题」模式
            'canonical' => Url::index($page),
            'active' => 'home',
            'tab' => 'home',
            'jsonld' => [
                '@context' => 'https://schema.org',
                '@type' => 'Blog',
                'name' => $this->siteBlogName(),
                'description' => Settings::str('blog_description'),
                'url' => Url::absolute(Url::index()),
            ],
        ], [
            'tops' => $tops,
            'posts' => $posts,
            'pagination' => $pagination,
            'hot_posts' => $hotPosts,
            'hot_tags' => $hotTags,
        ]);
    }
}
