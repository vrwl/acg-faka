<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core\Markdown;

use App\Plugin\Blog\Core\Slug;

/**
 * 消毒后的 DOM 装饰（在 Sanitizer 之后跑，注入的属性不会再被剥掉）：
 *  - h1~h3 注入锚点 id（h- 前缀，CJK 保留，重名追 -N）并收集 TOC；
 *  - 任务列表 li「[ ] / [x]」→ blog-task 类 + 视觉方框（不用 <input>，白名单更小）；
 *  - img 懒加载 loading=lazy + decoding=async（首图交给前端另行提升优先级）；
 *  - 站外链接 target=_blank + rel=noopener(+nofollow 按配置)。
 */
final class Decorator
{
    /**
     * @param array{external_nofollow?:bool, lazyload_img?:bool} $options
     * @return array{html:string, toc:array}
     */
    public static function decorate(string $html, array $options): array
    {
        if (trim($html) === '') {
            return ['html' => '', 'toc' => []];
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        //xml 编码前导保证 UTF-8 不被 loadHTML 当 ISO-8859-1 处理
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="__blog_root__">' . $html . '</div>',
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__blog_root__');
        if (!$loaded || $root === null) {
            //DOM 解析兜底：原样返回（消毒已完成，安全性不受影响）
            return ['html' => $html, 'toc' => []];
        }

        $toc = self::injectHeadingAnchors($dom, $root);
        self::convertTaskLists($dom, $root);
        self::wrapTables($dom, $root);
        self::decorateImages($root, (bool)($options['lazyload_img'] ?? true));
        self::decorateExternalLinks($root, (bool)($options['external_nofollow'] ?? true));

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return ['html' => $out, 'toc' => $toc];
    }

    /** @return array<int, array{level:int, text:string, anchor:string}> */
    private static function injectHeadingAnchors(\DOMDocument $dom, \DOMElement $root): array
    {
        $toc = [];
        $used = [];
        $xpath = new \DOMXPath($dom);
        //XPath 结果天然按文档序返回，h1/h2/h3 一次取齐
        $nodes = $xpath->query('.//h1 | .//h2 | .//h3', $root);
        if ($nodes === false) {
            return [];
        }
        foreach (iterator_to_array($nodes) as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $text = trim((string)$node->textContent);
            if ($text === '') {
                continue;
            }
            $base = Slug::normalize(mb_substr($text, 0, 64));
            if ($base === '') {
                $base = 'sec';
            }
            $anchor = 'h-' . $base;
            $i = 2;
            while (isset($used[$anchor])) {
                $anchor = 'h-' . $base . '-' . $i;
                $i++;
            }
            $used[$anchor] = true;
            $node->setAttribute('id', $anchor);
            $toc[] = [
                'level' => (int)substr($node->nodeName, 1),
                'text' => mb_substr($text, 0, 120),
                'anchor' => $anchor,
            ];
        }
        return $toc;
    }

    private static function convertTaskLists(\DOMDocument $dom, \DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('li')) as $li) {
            if (!$li instanceof \DOMElement) {
                continue;
            }
            $first = $li->firstChild;
            if (!$first instanceof \DOMText) {
                continue;
            }
            if (!preg_match('/^\[( |x|X)\]\s+/', $first->nodeValue ?? '', $matches)) {
                continue;
            }
            $done = strtolower($matches[1]) === 'x';
            $first->nodeValue = preg_replace('/^\[( |x|X)\]\s+/', '', $first->nodeValue ?? '') ?? '';

            $box = $dom->createElement('span');
            $box->setAttribute('class', 'blog-task__box');
            $box->setAttribute('aria-hidden', 'true');
            $li->insertBefore($box, $first);

            $class = 'blog-task' . ($done ? ' blog-task--done' : '');
            $existing = $li->getAttribute('class');
            $li->setAttribute('class', trim($existing . ' ' . $class));

            $parent = $li->parentNode;
            if ($parent instanceof \DOMElement && !str_contains($parent->getAttribute('class'), 'blog-tasklist')) {
                $parent->setAttribute('class', trim($parent->getAttribute('class') . ' blog-tasklist'));
            }
        }
    }

    /**
     * 给表格套一层横向滚动容器。
     * 不能直接在 <table> 上写 display:block + overflow-x:auto ——那样表格失去 table 布局，
     * 表头背景/行宽只跟随内容，撑不满容器（宽屏下就是「边框全宽、内容缩在左边」）。
     */
    private static function wrapTables(\DOMDocument $dom, \DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('table')) as $table) {
            if (!$table instanceof \DOMElement) {
                continue;
            }
            $parent = $table->parentNode;
            if ($parent instanceof \DOMElement && str_contains($parent->getAttribute('class'), 'blog-tablewrap')) {
                continue; //已包过（懒重渲染时不重复套）
            }
            $wrap = $dom->createElement('div');
            $wrap->setAttribute('class', 'blog-tablewrap');
            $parent->replaceChild($wrap, $table);
            $wrap->appendChild($table);
        }
    }

    private static function decorateImages(\DOMElement $root, bool $lazy): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('img')) as $img) {
            if (!$img instanceof \DOMElement) {
                continue;
            }
            if ($lazy && !$img->hasAttribute('loading')) {
                $img->setAttribute('loading', 'lazy');
            }
            $img->setAttribute('decoding', 'async');
        }
    }

    private static function decorateExternalLinks(\DOMElement $root, bool $nofollow): void
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        foreach (iterator_to_array($root->getElementsByTagName('a')) as $a) {
            if (!$a instanceof \DOMElement) {
                continue;
            }
            $href = (string)$a->getAttribute('href');
            if (!preg_match('#^https?://([^/]+)#i', $href, $matches)) {
                continue; //相对/站内路径或 mailto
            }
            $linkHost = strtolower($matches[1]);
            if ($host !== '' && $linkHost === $host) {
                continue; //绝对写法的本站链接
            }
            $a->setAttribute('target', '_blank');
            $rel = preg_split('/\s+/', (string)$a->getAttribute('rel'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $rel[] = 'noopener';
            if ($nofollow) {
                $rel[] = 'nofollow';
            }
            $a->setAttribute('rel', implode(' ', array_unique($rel)));
        }
    }
}
