<?php
declare(strict_types=1);

namespace App\View\User\Theme\LosAngeles;

/**
 * 洛杉矶主题的内联 SVG 图标集。
 *
 * 为什么不用图标字体：字体要多一次跨域请求、会闪一下（FOUT）、
 * 而且不同主题装的 FontAwesome 版本不一致（站内 fa4/fa6 混用已经踩过坑）。
 * 内联 SVG 用 currentColor + 1em 尺寸，跟着文字颜色和字号走，
 * 描边宽度统一 1.75，端点与拐角全圆角 —— 这是本主题视觉语言的一部分。
 *
 * 用法：
 *   模板  #{\App\View\User\Theme\LosAngeles\Icons::svg('home','la-ico')}
 *   PHP   Icons::svg('flash')
 *   后台  Config::INFO['ICON_NAMES']（竖线分隔）供图标选择器使用
 */
final class Icons
{
    private const VIEWBOX = '0 0 24 24';

    /**
     * 名称 => path/几何体。全部按 24×24 网格绘制。
     */
    private const PATHS = [
        //—— 导航骨架
        'home' => '<path d="M3.6 10.4 12 3.8l8.4 6.6"/><path d="M5.6 9v9.4a1.6 1.6 0 0 0 1.6 1.6h9.6a1.6 1.6 0 0 0 1.6-1.6V9"/><path d="M9.8 20v-5.2h4.4V20"/>',
        'grid' => '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
        'flash' => '<path d="M13.4 2.5 4.9 13.1a.6.6 0 0 0 .47.98h5.06l-1.3 7.42 8.52-10.6a.6.6 0 0 0-.47-.98h-5.06z"/>',
        'receipt' => '<path d="M5.5 3.2h13v18l-2.6-1.7-2.6 1.7-2.6-1.7-2.6 1.7z"/><path d="M9 8.2h6"/><path d="M9 12.4h6"/>',
        'user' => '<circle cx="12" cy="8" r="3.6"/><path d="M4.6 20.3a7.6 7.6 0 0 1 14.8 0"/>',
        'search' => '<circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.6 15.6 4.1 4.1"/>',
        'bag' => '<path d="M5.4 7.6h13.2l-1 12.1a1.6 1.6 0 0 1-1.6 1.5H8a1.6 1.6 0 0 1-1.6-1.5z"/><path d="M8.8 10V6.6a3.2 3.2 0 0 1 6.4 0V10"/>',
        'store' => '<path d="M4 9.4 5.3 4h13.4L20 9.4a3 3 0 0 1-5.3 2.5 3 3 0 0 1-5.4 0A3 3 0 0 1 4 9.4z"/><path d="M5.6 11.6V20h12.8v-8.4"/><path d="M9.8 20v-4.6h4.4V20"/>',

        //—— 方向与操作
        'left' => '<path d="m14.6 5.4-6.6 6.6 6.6 6.6"/>',
        'right' => '<path d="m9.4 5.4 6.6 6.6-6.6 6.6"/>',
        'up' => '<path d="m5.4 14.6 6.6-6.6 6.6 6.6"/>',
        'down' => '<path d="m5.4 9.4 6.6 6.6 6.6-6.6"/>',
        'close' => '<path d="m6 6 12 12"/><path d="m18 6-12 12"/>',
        'menu' => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h12"/>',
        'filter' => '<path d="M3.6 5.4h16.8l-6.5 7.6v6.1l-3.8 1.9v-8z"/>',
        'plus' => '<path d="M12 5.6v12.8"/><path d="M5.6 12h12.8"/>',
        'minus' => '<path d="M5.6 12h12.8"/>',
        'check' => '<path d="m4.8 12.6 4.8 4.8 9.6-10.8"/>',
        'refresh' => '<path d="M20 12a8 8 0 1 1-2.6-5.9"/><path d="M20.2 4v4.4h-4.4"/>',
        'external' => '<path d="M14 4.6h5.4V10"/><path d="m19.4 4.6-8 8"/><path d="M18 14.4v3.9a2 2 0 0 1-2 2H5.7a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h3.9"/>',
        'more' => '<circle cx="5.4" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="18.6" cy="12" r="1.4"/>',

        //—— 主题 / 语言
        'sun' => '<circle cx="12" cy="12" r="4.1"/><path d="M12 2.6v2.2"/><path d="M12 19.2v2.2"/><path d="M4.35 4.35 5.9 5.9"/><path d="m18.1 18.1 1.55 1.55"/><path d="M2.6 12h2.2"/><path d="M19.2 12h2.2"/><path d="m4.35 19.65 1.55-1.55"/><path d="m18.1 5.9 1.55-1.55"/>',
        'moon' => '<path d="M20.4 13.6A8.6 8.6 0 0 1 10.4 3.6a8.6 8.6 0 1 0 10 10z"/>',
        'auto' => '<circle cx="12" cy="12" r="8.4"/><path d="M12 3.6v16.8a8.4 8.4 0 0 0 0-16.8z" fill="currentColor" stroke="none"/>',
        'globe' => '<circle cx="12" cy="12" r="8.4"/><path d="M3.6 12h16.8"/><path d="M12 3.6a13 13 0 0 1 0 16.8 13 13 0 0 1 0-16.8z"/>',

        //—— 账户 / 钱包
        'wallet' => '<path d="M3.8 7.6a2 2 0 0 1 2-2h11.3a1.6 1.6 0 0 1 1.6 1.6v1.4"/><rect x="3.8" y="7.6" width="16.4" height="12" rx="2.4"/><circle cx="16.4" cy="13.6" r="1.3"/>',
        'coin' => '<ellipse cx="12" cy="6.6" rx="7.4" ry="3.1"/><path d="M4.6 6.6v10.8c0 1.7 3.3 3.1 7.4 3.1s7.4-1.4 7.4-3.1V6.6"/><path d="M4.6 12c0 1.7 3.3 3.1 7.4 3.1s7.4-1.4 7.4-3.1"/>',
        'card' => '<rect x="2.8" y="5.4" width="18.4" height="13.2" rx="2.4"/><path d="M2.8 10h18.4"/><path d="M6.6 14.6h3.4"/>',
        'gift' => '<rect x="3.4" y="9.4" width="17.2" height="4" rx="1.2"/><path d="M4.8 13.4v5.4a1.8 1.8 0 0 0 1.8 1.8h10.8a1.8 1.8 0 0 0 1.8-1.8v-5.4"/><path d="M12 9.4v11.2"/><path d="M12 9.4S10.6 3.4 7.9 3.4a2.4 2.4 0 0 0 0 6z"/><path d="M12 9.4s1.4-6 4.1-6a2.4 2.4 0 0 1 0 6z"/>',
        'crown' => '<path d="m3.4 7.2 3.5 3.1 3.6-5.7 3.6 5.7 3.5-3.1-1.4 10.4H4.8z"/><path d="M4.8 20.4h14.4"/>',
        'chart' => '<path d="M4 20h16"/><rect x="5.6" y="12" width="3.4" height="5.6" rx="1"/><rect x="10.6" y="7.6" width="3.4" height="10" rx="1"/><rect x="15.6" y="10" width="3.4" height="7.6" rx="1"/>',
        'users' => '<circle cx="9.2" cy="8.4" r="3.2"/><path d="M3.2 19.4a6 6 0 0 1 12 0"/><path d="M16 5.6a3.2 3.2 0 0 1 0 5.9"/><path d="M17.4 13.9a6 6 0 0 1 3.4 5.5"/>',
        'megaphone' => '<path d="M4 10.2v3.6a1.8 1.8 0 0 0 1.8 1.8h1.4l9.6 4.2V6L7.2 10.2z"/><path d="M19.4 9.4a3.4 3.4 0 0 1 0 5.2"/><path d="M7.2 15.6v2.6a2 2 0 0 0 2 2h.6"/>',

        //—— 消息 / 服务
        'bell' => '<path d="M6.6 10.2a5.4 5.4 0 0 1 10.8 0c0 4.4 1.8 5.8 1.8 5.8H4.8s1.8-1.4 1.8-5.8z"/><path d="M10.2 19.2a2 2 0 0 0 3.6 0"/>',
        'message' => '<path d="M20.4 12.6a7.4 7.4 0 0 1-7.4 7.4H8l-4.4 2.2 1.2-4.1A7.4 7.4 0 1 1 20.4 12.6z"/>',
        'headset' => '<path d="M4.6 14.4v-2.6a7.4 7.4 0 0 1 14.8 0v2.6"/><rect x="2.9" y="13.2" width="3.8" height="5.4" rx="1.6"/><rect x="17.3" y="13.2" width="3.8" height="5.4" rx="1.6"/><path d="M19.4 18.6v.6a2.4 2.4 0 0 1-2.4 2.4H13"/>',
        'mail' => '<rect x="3" y="5.4" width="18" height="13.2" rx="2.4"/><path d="m3.8 7.4 7.2 5a1.8 1.8 0 0 0 2 0l7.2-5"/>',
        'phone' => '<rect x="6.4" y="2.6" width="11.2" height="18.8" rx="2.6"/><path d="M10.8 18.2h2.4"/>',

        //—— 安全
        'shield' => '<path d="M12 3.2 4.8 6v6c0 4.3 3 7.5 7.2 8.8 4.2-1.3 7.2-4.5 7.2-8.8V6z"/><path d="m9.2 12 2 2 3.6-4"/>',
        'trash' => '<path d="M4 7h16"/><path d="M9.5 7V4.8h5V7"/><path d="M6.5 7l.8 12.2h9.4L17.5 7"/><path d="M10 11v5"/><path d="M14 11v5"/>',
        'unlock' => '<rect x="4.5" y="10.5" width="15" height="10" rx="2.2"/><path d="M8 10.5V7.8a4 4 0 0 1 7.6-1.6"/>',
        'upload' => '<path d="M12 16V4"/><path d="m7.5 8.5 4.5-4.5 4.5 4.5"/><path d="M4.5 16v2.5A1.5 1.5 0 0 0 6 20h12a1.5 1.5 0 0 0 1.5-1.5V16"/>',
        'lock' => '<rect x="4.8" y="10.2" width="14.4" height="10.2" rx="2.4"/><path d="M8.2 10.2V7.6a3.8 3.8 0 0 1 7.6 0v2.6"/>',
        'key' => '<circle cx="8" cy="12" r="4.2"/><path d="M12.2 12h8"/><path d="M17.4 12v3.2"/><path d="M20.2 12v2.2"/>',
        'edit' => '<path d="M14.4 4.8 19.2 9.6 9.6 19.2H4.8v-4.8z"/><path d="m12.4 6.8 4.8 4.8"/>',

        //—— 商品 / 物流
        'tag' => '<path d="M11.2 3.2H20v8.8l-8.5 8.5a1.7 1.7 0 0 1-2.4 0l-6.4-6.4a1.7 1.7 0 0 1 0-2.4z"/><circle cx="16.2" cy="7.8" r="1.5"/>',
        'truck' => '<path d="M2.8 6.6h10.6v10H2.8z"/><path d="M13.4 10h3.7l3.1 3.1v3.5h-6.8z"/><circle cx="7" cy="18.4" r="1.9"/><circle cx="17" cy="18.4" r="1.9"/>',
        'clock' => '<circle cx="12" cy="12" r="8.4"/><path d="M12 7.2V12l3.2 2"/>',
        'fire' => '<path d="M12 21.2c3.6 0 6.2-2.4 6.2-5.8 0-4.6-4.4-6-3.6-11.6-3 1.2-5.4 4-5.4 6.6 0 1.2.4 2 .4 2S8 11.6 7 10c-1 1.4-1.2 3.2-1.2 5.4 0 3.4 2.6 5.8 6.2 5.8z"/>',
        'box' => '<path d="m12 3.2 8 4v9.6l-8 4-8-4V7.2z"/><path d="m4 7.2 8 4 8-4"/><path d="M12 11.2v9.6"/>',
        'image' => '<rect x="3.2" y="4.6" width="17.6" height="14.8" rx="2.4"/><circle cx="8.6" cy="9.8" r="1.8"/><path d="m3.6 17.4 4.8-4.4a2 2 0 0 1 2.7 0l6.1 5.6"/>',

        //—— 其它
        'copy' => '<rect x="8.6" y="8.6" width="11.8" height="11.8" rx="2.2"/><path d="M15.4 5.6a2.2 2.2 0 0 0-2.2-2.2H5.8a2.2 2.2 0 0 0-2.2 2.2v7.4a2.2 2.2 0 0 0 2.2 2.2"/>',
        'download' => '<path d="M12 3.6v11.2"/><path d="m7.6 10.6 4.4 4.4 4.4-4.4"/><path d="M4.4 18.2v.8a1.6 1.6 0 0 0 1.6 1.6h12a1.6 1.6 0 0 0 1.6-1.6v-.8"/>',
        'share' => '<circle cx="17.4" cy="6" r="2.6"/><circle cx="6.6" cy="12" r="2.6"/><circle cx="17.4" cy="18" r="2.6"/><path d="m9 10.8 6-3.6"/><path d="m9 13.2 6 3.6"/>',
        'settings' => '<circle cx="12" cy="12" r="3.1"/><path d="M19.2 14.2a1.6 1.6 0 0 0 .3 1.8l.1.1a1.9 1.9 0 1 1-2.7 2.7l-.1-.1a1.6 1.6 0 0 0-2.7 1.1v.3a1.9 1.9 0 1 1-3.8 0v-.2a1.6 1.6 0 0 0-2.8-1.1l-.1.1a1.9 1.9 0 1 1-2.7-2.7l.1-.1a1.6 1.6 0 0 0-1.1-2.7h-.3a1.9 1.9 0 1 1 0-3.8h.2a1.6 1.6 0 0 0 1.1-2.8l-.1-.1a1.9 1.9 0 1 1 2.7-2.7l.1.1a1.6 1.6 0 0 0 2.7-1.1v-.3a1.9 1.9 0 1 1 3.8 0v.2a1.6 1.6 0 0 0 2.8 1.1l.1-.1a1.9 1.9 0 1 1 2.7 2.7l-.1.1a1.6 1.6 0 0 0 1.1 2.7h.3a1.9 1.9 0 1 1 0 3.8h-.2a1.6 1.6 0 0 0-1.4 1z"/>',
        'logout' => '<path d="M9.4 20.4H6a2 2 0 0 1-2-2V5.6a2 2 0 0 1 2-2h3.4"/><path d="m14.6 16.4 4.4-4.4-4.4-4.4"/><path d="M19 12H9.2"/>',
        'heart' => '<path d="M12 20.4S3.6 15.6 3.6 9.7a4.5 4.5 0 0 1 8.4-2.3 4.5 4.5 0 0 1 8.4 2.3c0 5.9-8.4 10.7-8.4 10.7z"/>',
        'star' => '<path d="m12 3.6 2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9-4.3-4.1 5.9-.8z"/>',
        'sparkle' => '<path d="m12 3.2 1.9 5.3 5.3 1.9-5.3 1.9L12 17.6l-1.9-5.3L4.8 10.4l5.3-1.9z"/><path d="m18.6 15.2.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/>'
    ];

    /**
     * 竖线分隔的图标名清单，给后台配置面板的图标选择器。
     * 注意：INFO 里不能塞数组以外的复杂结构，字符串最稳。
     */
    const NAMES = 'home|grid|flash|receipt|user|search|bag|store|wallet|coin|card|gift|crown|chart|users|megaphone|bell|message|headset|mail|phone|shield|lock|key|edit|tag|truck|clock|fire|box|image|copy|download|share|settings|logout|heart|star|sparkle|globe|refresh|external|filter';

    /**
     * 渲染一个图标。名称不存在时返回空串（不抛异常：图标缺失不该让整页 500）。
     *
     * @param string $name 图标名
     * @param string $class 附加 class
     * @param int $stroke 描边宽度（×10，默认 175 = 1.75）
     */
    public static function svg(string $name, string $class = '', int $stroke = 175): string
    {
        $body = self::PATHS[$name] ?? '';
        if ($body === '') {
            return '';
        }
        $cls = trim('la-i ' . $class);
        $w = number_format($stroke / 100, 2, '.', '');
        return '<svg class="' . htmlspecialchars($cls, ENT_QUOTES) . '" viewBox="' . self::VIEWBOX . '" fill="none" stroke="currentColor" stroke-width="' . $w . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }

    /**
     * 图标是否存在（模板里做兜底判断用）。
     */
    public static function has(string $name): bool
    {
        return isset(self::PATHS[$name]);
    }

    /**
     * 全部图标名（数组形式），Support / Submit.js 校验用。
     */
    public static function names(): array
    {
        return array_keys(self::PATHS);
    }
}
