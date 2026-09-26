<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller;

use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Model\Category as CategoryModel;
use App\Plugin\Blog\Model\Post;
use App\Plugin\Blog\Support\FrontController;
use App\Plugin\Blog\Support\Paginate;
use App\Plugin\Blog\Support\Present;
use App\Plugin\Blog\Support\Url;
use Kernel\Annotation\Interceptor;

/**
 * 分类总览（移动端「分类」Tab 落点）+ 分类详情文章流。
 */
#[Interceptor([Waf::class, UserVisitor::class])]
class Category extends FrontController
{
    public function index(): string
    {
        $rows = CategoryModel::query()->orderBy('weight')->orderBy('id')->get()->toArray();
        $tree = $this->tree($rows, 0, $this->visibleCounts());

        return $this->blogRender('CategoryList.html', [
            'title' => lang('分类'),
            'canonical' => Url::categoryIndex(),
            'active' => 'category',
            'tab' => 'category',
        ], [
            'tree' => $tree,
        ]);
    }

    public function detail(string $slug = ''): string
    {
        $slug = mb_strtolower(trim($slug));
        /** @var CategoryModel|null $category */
        $category = $slug === '' ? null : CategoryModel::query()->where('slug', $slug)->first();
        if (!$category) {
            return $this->notFound();
        }

        $counts = $this->visibleCounts();
        $children = [];
        foreach (CategoryModel::query()->where('parent_id', $category->id)->orderBy('weight')->orderBy('id')->get() as $child) {
            $children[] = [
                'name' => (string)$child->name,
                'slug' => (string)$child->slug,
                'url' => Url::category((string)$child->slug),
                'post_count' => (int)($counts[(int)$child->id] ?? 0),
            ];
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = Settings::int('posts_per_page', 1, 50);
        $query = Post::query()->where('type', Post::TYPE_POST)->visible()
            ->where('category_id', (int)$category->id)
            ->with(['category:id,name,slug', 'tags:id,name,slug']);
        $total = (clone $query)->count();
        $posts = [];
        foreach ($query->orderByDesc('top')->orderByDesc('publish_time')
                     ->offset(($page - 1) * $perPage)->limit($perPage)->get() as $post) {
            $posts[] = Present::card($post);
        }

        $pagination = Paginate::build($page, $perPage, $total, function (int $n) use ($slug) {
            return Url::category($slug, $n);
        });

        return $this->blogRender('Category.html', [
            'title' => (string)$category->name,
            'description' => (string)$category->description !== '' ? (string)$category->description : Settings::str('blog_description'),
            'canonical' => Url::category($slug, $page),
            'active' => 'category',
            'is_sub' => true,
        ], [
            'category' => [
                'name' => (string)$category->name,
                'slug' => (string)$category->slug,
                'description' => (string)$category->description,
                'cover' => (string)$category->cover,
                'post_count' => (int)($counts[(int)$category->id] ?? 0),
            ],
            'children' => $children,
            'posts' => $posts,
            'pagination' => $pagination,
        ]);
    }

    private function tree(array $rows, int $parentId, array $counts = []): array
    {
        $branch = [];
        foreach ($rows as $row) {
            if ((int)$row['parent_id'] !== $parentId) {
                continue;
            }
            $children = $this->tree($rows, (int)$row['id'], $counts);
            //父级把子级的数量卷上来：只做导航分类的父级本身常常一篇都没有，
            //显示 0 会让人以为整棵子树是空的
            $own = (int)($counts[(int)$row['id']] ?? 0);
            $total = $own;
            foreach ($children as $child) {
                $total += (int)$child['post_count'];
            }
            $branch[] = [
                'name' => (string)$row['name'],
                'slug' => (string)$row['slug'],
                'url' => Url::category((string)$row['slug']),
                'description' => (string)$row['description'],
                'post_count' => $total,
                'own_count' => $own,
                'children' => $children,
            ];
        }
        return $branch;
    }

    /**
     * 各分类的「前台可见」文章数（一次 GROUP BY 取回）。
     * 不能直接用去范式的 post_count：那一列只按 status 统计，
     * 定时发布（status=1 但 publish_time 在未来）的文章会被算进去，
     * 于是分类页显示有 N 篇、点进去却是空的。口径必须和 scopeVisible 一致。
     */
    private function visibleCounts(): array
    {
        //别用 pluck()：它会用自己的 select 覆盖 selectRaw 的别名，SQL 会报 Unknown column
        $rows = Post::query()
            ->where('type', Post::TYPE_POST)
            ->visible()
            ->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) AS aggregate')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row->category_id] = (int)$row->aggregate;
        }
        return $counts;
    }
}
