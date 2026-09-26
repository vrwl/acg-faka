<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

use Illuminate\Database\Capsule\Manager;

/**
 * Slug 归一化与唯一化。
 * 全小写入库（utf8mb4_unicode_ci 下 "Post-A"/"post-a" 会撞唯一键，入库前必须归一）；
 * 允许中文等任意语言字符（typecho 同款体验），字符集 [\p{L}\p{N}\-_.~]。
 */
final class Slug
{
    public const PATTERN = '/^[\p{L}\p{N}\-_.~]+$/u';
    public const MAX_LEN = 180; //191 唯一索引留出 -NN 后缀余量

    /** 归一化：小写、空白与非法字符 → 连字符、合并/去首尾连字符、截长 */
    public static function normalize(string $raw): string
    {
        $slug = mb_strtolower(trim($raw));
        $slug = preg_replace('/[^\p{L}\p{N}\-_.~]+/u', '-', $slug) ?? '';
        $slug = preg_replace('/-{2,}/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if (mb_strlen($slug) > self::MAX_LEN) {
            $slug = rtrim(mb_substr($slug, 0, self::MAX_LEN), '-.');
        }
        return $slug;
    }

    /** 由标题生成（标题也归一不出东西时退回时间串） */
    public static function fromTitle(string $title): string
    {
        $slug = self::normalize($title);
        return $slug !== '' ? $slug : 'post-' . date('YmdHis');
    }

    /** 桥接/入参校验用 */
    public static function valid(string $slug): bool
    {
        return $slug !== ''
            && mb_strlen($slug) <= 200
            && (bool)preg_match(self::PATTERN, $slug);
    }

    /**
     * 表内唯一化：撞库追 -2/-3…；极端情况下退回随机后缀。
     * 注意这是"尽力查重"，真正的兜底是唯一索引 + 保存处 catch QueryException 重试。
     */
    public static function unique(string $slug, string $table, int $excludeId = 0): string
    {
        $slug = self::normalize($slug);
        if ($slug === '') {
            $slug = 'item-' . date('YmdHis');
        }
        $candidate = $slug;
        for ($i = 2; $i <= 50; $i++) {
            $query = Manager::table($table)->where('slug', $candidate);
            if ($excludeId > 0) {
                $query->where('id', '<>', $excludeId);
            }
            if (!$query->exists()) {
                return $candidate;
            }
            $candidate = $slug . '-' . $i;
        }
        return $slug . '-' . substr(md5((string)mt_rand()), 0, 6);
    }
}
