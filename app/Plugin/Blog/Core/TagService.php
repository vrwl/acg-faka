<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

use App\Plugin\Blog\Model\Tag;
use Illuminate\Database\QueryException;

/**
 * 标签：按名称自动创建/复用、pivot 同步、计数重算。
 */
final class TagService
{
    /**
     * 名称数组 → 标签 id 数组（不存在的自动创建）。
     * uk_name 撞唯一键（并发/大小写）时回读复用。
     */
    public static function ensure(array $names): array
    {
        $ids = [];
        $seen = [];
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name === '' || mb_strlen($name) > 64) {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $tag = Tag::query()->where('name', $name)->first();
            if (!$tag) {
                try {
                    $tag = new Tag();
                    $tag->name = $name;
                    $tag->slug = Slug::unique(Slug::fromTitle($name), Db::TAG);
                    $tag->post_count = 0;
                    $tag->create_time = Db::now();
                    $tag->save();
                } catch (QueryException $e) {
                    $tag = Tag::query()->where('name', $name)->first();
                    if (!$tag) {
                        throw $e;
                    }
                }
            }
            $ids[] = (int)$tag->id;
        }
        return array_values(array_unique($ids));
    }

    /**
     * 同步某文章的标签关联（调用方包事务）。
     * @return array 受影响的标签 id（旧+新并集，供计数重算）
     */
    public static function sync(int $postId, array $tagIds): array
    {
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        $old = Db::table(Db::POST_TAG)->where('post_id', $postId)->pluck('tag_id')->all();
        $old = array_map('intval', $old);

        $remove = array_diff($old, $tagIds);
        $add = array_diff($tagIds, $old);

        if ($remove) {
            Db::table(Db::POST_TAG)->where('post_id', $postId)->whereIn('tag_id', $remove)->delete();
        }
        if ($add) {
            $rows = [];
            foreach ($add as $tagId) {
                $rows[] = ['post_id' => $postId, 'tag_id' => $tagId];
            }
            Db::table(Db::POST_TAG)->insert($rows);
        }
        return array_values(array_unique(array_merge($old, $tagIds)));
    }

    /** 重算标签的已发布文章计数（重算式，拒绝 ±1 防漂移） */
    public static function recalcCount(array $tagIds): void
    {
        foreach (array_unique(array_map('intval', $tagIds)) as $tagId) {
            if ($tagId <= 0) {
                continue;
            }
            $count = Db::table(Db::POST_TAG)
                ->join(Db::POST, Db::POST . '.id', '=', Db::POST_TAG . '.post_id')
                ->where(Db::POST_TAG . '.tag_id', $tagId)
                ->where(Db::POST . '.status', \App\Plugin\Blog\Model\Post::STATUS_PUBLISHED)
                ->count();
            Db::table(Db::TAG)->where('id', $tagId)->update(['post_count' => $count]);
        }
    }
}
