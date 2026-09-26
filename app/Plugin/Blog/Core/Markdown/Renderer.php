<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core\Markdown;

use App\Plugin\Blog\Core\Settings;

/**
 * Markdown 渲染管线入口（教程向扩展）：
 *   Parsedown(+提示框语法) → 表格对齐 style→align 归一 → HTMLPurifier 白名单消毒 → DOM 装饰(锚点/TOC/任务列表/懒加载/外链)
 *
 * 升级解析规则 / 白名单 / 装饰逻辑时把 VERSION +1 —— blog_post.render_version 小于它的文章会被懒重渲染。
 *
 * 提示框语法（VitePress 风格）：
 *   :::tip│info│warning│danger│note
 *   内容（支持完整 Markdown，含代码块；空行允许）
 *   :::
 */
class Renderer extends Parsedown
{
    public const VERSION = 2;   //v2: 表格包裹 .blog-tablewrap

    private const ADMON_KINDS = ['tip', 'info', 'warning', 'danger', 'note'];

    public function __construct()
    {
        $this->BlockTypes[':'][] = 'Admonition';
    }

    /**
     * @param string $markdown 原始 Markdown
     * @param array|null $options 显式选项（测试/特殊场景用），null=读插件 Settings：
     *        allow_iframe / external_nofollow / lazyload_img => bool
     * @return array{html:string, toc:array}
     */
    public static function render(string $markdown, ?array $options = null): array
    {
        if ($options === null) {
            $options = [
                'allow_iframe' => Settings::bool('allow_iframe'),
                'external_nofollow' => Settings::bool('external_nofollow'),
                'lazyload_img' => Settings::bool('lazyload_img'),
            ];
        }

        $parser = new static();
        $parser->setBreaksEnabled(false);
        $parser->setMarkupEscaped(false);
        //safeMode 关闭：作者是管理员，允许写原生 HTML；安全完全由 Sanitizer 白名单兜底
        $parser->setSafeMode(false);

        $html = $parser->text($markdown);

        //Parsedown 的表格对齐用 style="text-align: x"，而白名单会剥 style —— 先归一成 align 属性
        $html = preg_replace(
            '/<(th|td)([^>]*?)\s+style="text-align:\s*(left|center|right);?"/i',
            '<$1$2 align="$3"',
            $html
        ) ?? $html;

        $html = Sanitizer::purify($html, (bool)($options['allow_iframe'] ?? false));

        return Decorator::decorate($html, [
            'external_nofollow' => (bool)($options['external_nofollow'] ?? true),
            'lazyload_img' => (bool)($options['lazyload_img'] ?? true),
        ]);
    }

    /* ---------------- 提示框块 ---------------- */

    protected function blockAdmonition($Line)
    {
        if (preg_match('/^:::[ ]*(' . implode('|', self::ADMON_KINDS) . ')[ ]*$/i', (string)$Line['text'], $matches)) {
            $kind = strtolower($matches[1]);
            return [
                'char' => ':',
                'element' => [
                    'name' => 'div',
                    'attributes' => ['class' => 'blog-admon blog-admon--' . $kind],
                    'handler' => 'lines',
                    'text' => [],
                ],
            ];
        }
        return null;
    }

    protected function blockAdmonitionContinue($Line, array $Block)
    {
        if (isset($Block['complete'])) {
            return null;
        }
        //允许提示框内出现空行（照 blockFencedCode 的 interrupted 处理）
        if (isset($Block['interrupted'])) {
            $Block['element']['text'][] = '';
            unset($Block['interrupted']);
        }
        if (preg_match('/^:::[ ]*$/', (string)$Line['text'])) {
            $Block['complete'] = true;
            return $Block;
        }
        $Block['element']['text'][] = $Line['body'];
        return $Block;
    }

    protected function blockAdmonitionComplete($Block)
    {
        //未闭合的提示框到文档末尾自动闭合
        return $Block;
    }
}
