<?php
declare(strict_types=1);

namespace App\View\User\Theme\MountFuji;

/**
 * 富士山主题的服务端助手。
 */
final class Support
{
    /**
     * 本主题自带 CSS/JS 的完整 URL（含版本号）。
     *
     * 为什么不走 css()/js()：那两个助手拼的 ?v= 一律是 APP_VERSION。模板是能
     * 脱离核心单独升级的，模板换了新 HTML 而 APP_VERSION 没变时，浏览器会继续
     * 吃上一版的 _theme.css / _theme.js —— 新加的样式和脚本全部失效（表现为
     * 新区块没样式、新按钮点了没反应）。这里改用主题自己的 Config INFO VERSION，
     * 每次发版都会换 URL。
     *
     * 也不能用 Helper::themeUrl()：它只解析 user_theme / user_mobile_theme，
     * 本主题是会员中心专用，那条路径会指到商城主题的目录上。$root 传核心解析
     * 好的 $static，永远是当前页真正在用的主题根。
     *
     * DEBUG 下和 css()/js() 一样退回未压缩源文件，并追加时间戳强制不缓存。
     *
     * @param string $root 模板变量 $static，形如 /app/View/User/Theme/MountFuji
     * @param string $kind Css | Js
     */
    public static function asset(string $root, string $kind): string
    {
        $debug = defined('DEBUG') && DEBUG;

        $file = match ($kind) {
            'Css' => 'Assets/Css/' . ($debug ? 'Theme.css' : '_theme.css'),
            'Js' => 'Assets/Js/' . ($debug ? 'Theme.js' : '_theme.js'),
            default => '',
        };

        if ($file === '') {
            return '';
        }

        $version = (string)(Config::INFO['VERSION'] ?? '1.0.0');

        return rtrim($root, '/') . '/' . $file . '?v=' . $version . ($debug ? '.' . time() : '');
    }
}
