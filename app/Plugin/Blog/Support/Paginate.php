<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Support;

/**
 * SSR 分页数据（真链接，SEO/无 JS 兜底；JS 增强为无限滚动后隐藏）。
 */
final class Paginate
{
    /**
     * @param callable $urlFor fn(int $page): string
     */
    public static function build(int $page, int $perPage, int $total, callable $urlFor): array
    {
        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $pages));

        $window = [];
        $push = function (int $n) use (&$window, $page, $urlFor) {
            $window[] = ['n' => $n, 'url' => $urlFor($n), 'current' => $n === $page, 'gap' => false];
        };
        $gap = function () use (&$window) {
            $window[] = ['n' => 0, 'url' => '', 'current' => false, 'gap' => true];
        };

        if ($pages <= 7) {
            for ($i = 1; $i <= $pages; $i++) {
                $push($i);
            }
        } else {
            $push(1);
            $start = max(2, $page - 1);
            $end = min($pages - 1, $page + 1);
            if ($start > 2) {
                $gap();
            }
            for ($i = $start; $i <= $end; $i++) {
                $push($i);
            }
            if ($end < $pages - 1) {
                $gap();
            }
            $push($pages);
        }

        return [
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'has_prev' => $page > 1,
            'has_next' => $page < $pages,
            'prev_url' => $page > 1 ? $urlFor($page - 1) : '',
            'next_url' => $page < $pages ? $urlFor($page + 1) : '',
            'window' => $window,
        ];
    }
}
