<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller;

use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Plugin\Blog\Model\Post;
use App\Plugin\Blog\Support\FrontController;
use App\Plugin\Blog\Support\Url;
use Kernel\Annotation\Interceptor;

/**
 * 归档时间轴（年 → 月 → 文章条目）。
 */
#[Interceptor([Waf::class, UserVisitor::class])]
class Archive extends FrontController
{
    public function index(): string
    {
        $rows = Post::query()->where('type', Post::TYPE_POST)->visible()
            ->orderByDesc('publish_time')
            ->limit(2000)
            ->get(['id', 'title', 'slug', 'publish_time']);

        $years = [];
        $total = 0;
        foreach ($rows as $post) {
            $time = strtotime((string)$post->publish_time);
            $year = date('Y', $time);
            $month = date('m', $time);
            $years[$year]['year'] = $year;
            $years[$year]['months'][$month]['month'] = $month;
            $years[$year]['months'][$month]['posts'][] = [
                'title' => (string)$post->title,
                'url' => Url::post((string)$post->slug),
                'day' => date('m-d', $time),
            ];
            $total++;
        }
        //整理为顺序数组（年、月各自倒序）
        $archive = [];
        foreach ($years as $year) {
            $months = [];
            foreach ($year['months'] as $month) {
                $month['count'] = count($month['posts']);
                $months[] = $month;
            }
            $archive[] = ['year' => $year['year'], 'months' => $months, 'count' => array_sum(array_column($months, 'count'))];
        }

        return $this->blogRender('Archive.html', [
            'title' => lang('归档'),
            'canonical' => Url::archive(),
            'active' => 'archive',
            'is_sub' => true,
        ], [
            'archive' => $archive,
            'total' => $total,
        ]);
    }
}
