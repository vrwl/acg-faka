<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller;

use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Plugin\Blog\Core\PostService;
use App\Plugin\Blog\Model\Post as PostModel;
use App\Plugin\Blog\Support\FrontController;
use App\Plugin\Blog\Support\Present;
use App\Plugin\Blog\Support\Url;
use Kernel\Annotation\Interceptor;

/**
 * 独立页面（关于等，type=page）。
 */
#[Interceptor([Waf::class, UserVisitor::class])]
class Page extends FrontController
{
    public function detail(string $slug = ''): string
    {
        $slug = mb_strtolower(trim($slug));
        /** @var PostModel|null $post */
        $post = $slug === '' ? null : PostModel::query()
            ->where('type', PostModel::TYPE_PAGE)
            ->where('slug', $slug)
            ->visible()
            ->first();
        if (!$post) {
            return $this->notFound();
        }

        PostService::lazyRender($post);
        $detail = Present::detail($post);

        return $this->blogRender('Page.html', [
            'title' => $detail['seo_title'] !== '' ? $detail['seo_title'] : $detail['title'],
            'description' => $detail['seo_description'] !== '' ? $detail['seo_description'] : mb_substr($detail['summary'], 0, 150),
            'keywords' => $detail['seo_keywords'],
            'canonical' => Url::page($slug),
            'og_type' => 'article',
            'og_image' => $detail['cover'] !== '' ? Url::absolute($detail['cover']) : '',
            'active' => $slug === 'about' ? 'about' : '',
            'is_sub' => true,
            'body_class' => 'blog-page-static',
        ], [
            'post' => $detail,
        ]);
    }
}
