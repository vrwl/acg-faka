<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Support;

use App\Plugin\Blog\Core\Settings;

/**
 * 前台链接唯一生成点，三种形态：
 *   独立域名   → 博客在根路径，/post/x（前缀为空）
 *   pretty_links=1 → /blog/post/x 短链
 *   pretty_links=0 → /plugin/Blog/post/detail?slug=x 原生路径
 * 模板与 JS 一律经这里（或注入的 $blog.urls），永远不手拼 URL。
 */
final class Url
{
    public static function prettyOn(): bool
    {
        return Settings::bool('pretty_links');
    }

    /** 独立域名下即便站长关了短链也必须走短链形态：那个域名下根本没有商城路由可言 */
    private static function pretty(): bool
    {
        return Domain::active() || self::prettyOn();
    }

    /** 短链前缀：独立域名为空（博客即站点根），否则 /blog */
    private static function root(): string
    {
        return Domain::active() ? '' : '/blog';
    }

    /** 导航 active 高亮用的路由前缀 */
    public static function matchPrefix(): string
    {
        return self::prettyOn() ? '/blog' : '/plugin/Blog';
    }

    public static function index(int $page = 1): string
    {
        $base = self::pretty() ? (self::root() ?: '/') : '/plugin/Blog/index/index';
        return $page > 1 ? $base . '?page=' . $page : $base;
    }

    public static function post(string $slug): string
    {
        return self::pretty()
            ? self::root() . '/post/' . rawurlencode($slug)
            : '/plugin/Blog/post/detail?slug=' . rawurlencode($slug);
    }

    public static function categoryIndex(): string
    {
        return self::pretty() ? self::root() . '/category' : '/plugin/Blog/category/index';
    }

    public static function category(string $slug, int $page = 1): string
    {
        $base = self::pretty()
            ? self::root() . '/category/' . rawurlencode($slug)
            : '/plugin/Blog/category/detail?slug=' . rawurlencode($slug);
        return $page > 1 ? $base . (str_contains($base, '?') ? '&' : '?') . 'page=' . $page : $base;
    }

    public static function tagIndex(): string
    {
        return self::pretty() ? self::root() . '/tag' : '/plugin/Blog/tag/index';
    }

    public static function tag(string $slug, int $page = 1): string
    {
        $base = self::pretty()
            ? self::root() . '/tag/' . rawurlencode($slug)
            : '/plugin/Blog/tag/detail?slug=' . rawurlencode($slug);
        return $page > 1 ? $base . (str_contains($base, '?') ? '&' : '?') . 'page=' . $page : $base;
    }

    public static function archive(): string
    {
        return self::pretty() ? self::root() . '/archive' : '/plugin/Blog/archive/index';
    }

    public static function search(string $q = ''): string
    {
        $base = self::pretty() ? self::root() . '/search' : '/plugin/Blog/search/index';
        return $q !== '' ? $base . '?q=' . rawurlencode($q) : $base;
    }

    public static function page(string $slug): string
    {
        return self::pretty()
            ? self::root() . '/page/' . rawurlencode($slug)
            : '/plugin/Blog/page/detail?slug=' . rawurlencode($slug);
    }

    public static function feed(): string
    {
        return self::pretty() ? self::root() . '/feed' : '/plugin/Blog/feed/index';
    }

    public static function sitemap(): string
    {
        return self::pretty() ? self::root() . '/sitemap.xml' : '/plugin/Blog/feed/sitemap';
    }

    /** 站点绝对地址前缀（RSS/sitemap/OG 用），不带尾斜杠 */
    public static function origin(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }

    public static function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return self::origin() . $path;
    }
}
