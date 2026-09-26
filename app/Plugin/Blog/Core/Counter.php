<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

use App\Plugin\Blog\Model\Comment;
use App\Plugin\Blog\Model\Post;

/**
 * 去范式计数的重算（统一口径，全部 COUNT 重算式——拒绝 ±1，防漂移）。
 */
final class Counter
{
    /** 分类已发布文章数（不含子类） */
    public static function recalcCategory(array $categoryIds): void
    {
        foreach (array_unique(array_map('intval', $categoryIds)) as $categoryId) {
            if ($categoryId <= 0) {
                continue;
            }
            $count = Db::table(Db::POST)
                ->where('category_id', $categoryId)
                ->where('type', Post::TYPE_POST)
                ->where('status', Post::STATUS_PUBLISHED)
                ->count();
            Db::table(Db::CATEGORY)->where('id', $categoryId)->update(['post_count' => $count]);
        }
    }

    /** 文章已通过评论数（含楼中楼） */
    public static function recalcComments(array $postIds): void
    {
        foreach (array_unique(array_map('intval', $postIds)) as $postId) {
            if ($postId <= 0) {
                continue;
            }
            $count = Db::table(Db::COMMENT)
                ->where('post_id', $postId)
                ->where('status', Comment::STATUS_APPROVED)
                ->count();
            Db::table(Db::POST)->where('id', $postId)->update(['comments_count' => $count]);
        }
    }

    /** 全量重算（设置页维护动作） */
    public static function recalcAll(): array
    {
        $categoryIds = Db::table(Db::CATEGORY)->pluck('id')->all();
        self::recalcCategory(array_map('intval', $categoryIds));

        $tagIds = Db::table(Db::TAG)->pluck('id')->all();
        TagService::recalcCount(array_map('intval', $tagIds));

        $postIds = Db::table(Db::POST)->pluck('id')->all();
        self::recalcComments(array_map('intval', $postIds));

        return [
            'categories' => count($categoryIds),
            'tags' => count($tagIds),
            'posts' => count($postIds),
        ];
    }
}
