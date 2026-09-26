<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Query\Builder;

/**
 * 表名常量 + 查询构造器捷径。
 * 表名一律写逻辑名（acg_ 等前缀由 Capsule 按 config/database.php 自动加）。
 */
final class Db
{
    public const POST = 'blog_post';
    public const CATEGORY = 'blog_category';
    public const TAG = 'blog_tag';
    public const POST_TAG = 'blog_post_tag';
    public const COMMENT = 'blog_comment';
    public const LIKE = 'blog_like';

    public static function table(string $name): Builder
    {
        return Manager::table($name);
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
