<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core\Markdown;

/**
 * 正文 HTML 消毒（HTMLPurifier 白名单，先例：app/Service/Bind/Message.php::purifier）。
 * 关键决策：
 *  - 不放行 style 属性（内联颜色会打穿前台暗色主题）；表格对齐已在 Renderer 里归一成 align；
 *  - id 不放行——标题锚点由 Decorator 在消毒之后注入，作者原生 HTML 里的 id 一律剥掉（防 DOM clobbering）；
 *  - iframe 仅在 allow_iframe 开启时放行，且 src 锁 youtube-nocookie / bilibili player。
 */
final class Sanitizer
{
    private static ?\HTMLPurifier $purifier = null;
    private static string $fingerprint = '';

    public static function purify(string $html, bool $allowIframe): string
    {
        $fingerprint = $allowIframe ? 'iframe:1' : 'iframe:0';
        if (self::$purifier === null || self::$fingerprint !== $fingerprint) {
            self::$purifier = self::build($allowIframe);
            self::$fingerprint = $fingerprint;
        }
        return (string)self::$purifier->purify($html);
    }

    private static function build(bool $allowIframe): \HTMLPurifier
    {
        $config = \HTMLPurifier_Config::createDefault();

        $cacheDir = BASE_PATH . '/runtime/blog-purifier';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            $config->set('Cache.SerializerPath', $cacheDir);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        $config->set('Core.Encoding', 'UTF-8');

        $allowed = 'p,br,hr,strong,b,em,i,u,s,del,ins,sup,sub,mark,'
            . 'blockquote,ul,ol[start],li,h1,h2,h3,h4,h5,h6,'
            . 'pre,code[class],a[href|title|target|rel],'
            . 'img[src|alt|title|width|height],'
            . 'table,thead,tbody,tfoot,tr,th[align],td[align|colspan|rowspan],'
            . 'figure,figcaption,details[open],summary,'
            . 'div[class],span[class]';

        if ($allowIframe) {
            $allowed .= ',iframe[src|width|height|frameborder|allowfullscreen|loading]';
            $config->set('HTML.SafeIframe', true);
            $config->set('URI.SafeIframeRegexp', '%^https://(www\.youtube-nocookie\.com/embed/|player\.bilibili\.com/player\.html)%');
        }

        $config->set('HTML.Allowed', $allowed);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('AutoFormat.RemoveEmpty', false);

        //details/summary/figure/figcaption/mark 不在 HTMLPurifier 默认字典里，手动注册
        $config->set('HTML.DefinitionID', 'acg-blog-content');
        $config->set('HTML.DefinitionRev', 1);
        $definition = $config->maybeGetRawHTMLDefinition();
        if ($definition !== null) {
            $definition->addElement('figure', 'Block', 'Flow', 'Common');
            $definition->addElement('figcaption', 'Block', 'Flow', 'Common');
            $definition->addElement('mark', 'Inline', 'Inline', 'Common');
            $definition->addElement('details', 'Block', 'Flow', 'Common', ['open' => 'Bool']);
            $definition->addElement('summary', 'Block', 'Inline', 'Common');
        }

        return new \HTMLPurifier($config);
    }
}
