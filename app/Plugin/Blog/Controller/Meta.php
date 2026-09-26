<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller;

use App\Controller\Base\API\UserPlugin;
use App\Interceptor\Waf;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Support\FrontController;
use App\Plugin\Blog\Support\Url;
use Kernel\Annotation\Interceptor;

/**
 * PWA manifest（动态输出；只做 manifest 不做 Service Worker——博客无离线刚需）。
 */
#[Interceptor([Waf::class], \Kernel\Annotation\Interceptor::TYPE_API)]
class Meta extends UserPlugin
{
    public function manifest(): array
    {
        $shopName = (string)(\App\Model\Config::list()['shop_name'] ?? '');
        $name = FrontController::blogName($shopName);
        $dark = Settings::str('dark_mode') === 'dark';
        return [
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'start_url' => Url::index(),
            'scope' => Settings::bool('pretty_links') ? '/blog' : '/plugin/Blog',
            'display' => 'standalone',
            'background_color' => $dark ? '#0f1115' : '#f6f7fb',
            'theme_color' => $dark ? '#0f1115' : '#f6f7fb',
            'icons' => [
                ['src' => '/favicon.ico', 'sizes' => '48x48 96x96 192x192', 'type' => 'image/x-icon'],
            ],
        ];
    }
}
