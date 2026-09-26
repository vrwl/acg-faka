<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller;

use App\Controller\Base\View\UserPlugin;
use App\Interceptor\Waf;
use App\Plugin\Blog\Core\Rss;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Support\FrontController;
use Kernel\Annotation\Interceptor;

/**
 * RSS 2.0 与 sitemap.xml（带 Last-Modified/304 与短缓存）。
 */
#[Interceptor([Waf::class])]
class Feed extends UserPlugin
{
    public function index(): string
    {
        if (!Settings::bool('feed_enabled') || !Settings::bool('guest_visible')) {
            http_response_code(404);
            return 'Feed disabled';
        }
        $this->cacheHeaders(Rss::lastModified());
        header('Content-Type: application/rss+xml; charset=utf-8');
        $shopName = (string)(\App\Model\Config::list()['shop_name'] ?? '');
        return Rss::feed(
            FrontController::blogName($shopName),
            Settings::str('blog_description') ?: FrontController::blogName($shopName)
        );
    }

    public function sitemap(): string
    {
        if (!Settings::bool('guest_visible')) {
            http_response_code(404);
            return 'Not available';
        }
        $this->cacheHeaders(Rss::lastModified());
        header('Content-Type: application/xml; charset=utf-8');
        return Rss::sitemap();
    }

    /** Last-Modified + 10 分钟公共缓存；命中 If-Modified-Since 直接 304 */
    private function cacheHeaders(int $lastModified): void
    {
        $since = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if ($since !== '' && strtotime($since) >= $lastModified) {
            http_response_code(304);
            header('Cache-Control: public, max-age=600');
            exit;
        }
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        header('Cache-Control: public, max-age=600');
    }
}
