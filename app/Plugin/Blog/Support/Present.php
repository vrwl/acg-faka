<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Support;

use App\Plugin\Blog\Core\PostService;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Model\Post;

/**
 * 模型 → 前台视图数组（卡片/详情统一在这里塑形，模板与 JS 共用同一契约）。
 */
final class Present
{
    /** 列表卡片契约 */
    public static function card(Post $post): array
    {
        $category = null;
        if ($post->relationLoaded('category') && $post->category) {
            $category = [
                'name' => (string)$post->category->name,
                'slug' => (string)$post->category->slug,
                'url' => Url::category((string)$post->category->slug),
            ];
        }
        $tags = [];
        if ($post->relationLoaded('tags')) {
            foreach ($post->tags as $tag) {
                $tags[] = [
                    'name' => (string)$tag->name,
                    'slug' => (string)$tag->slug,
                    'url' => Url::tag((string)$tag->slug),
                ];
            }
        }

        return [
            'id' => (int)$post->id,
            'slug' => (string)$post->slug,
            'url' => $post->type === Post::TYPE_PAGE ? Url::page((string)$post->slug) : Url::post((string)$post->slug),
            'title' => (string)$post->title,
            'summary' => self::summary($post),
            'cover' => (string)$post->cover,
            'top' => (int)$post->top,
            'views' => (int)$post->views,
            'likes' => (int)$post->likes,
            'comments_count' => (int)$post->comments_count,
            'publish_time' => $post->publish_time ? date('Y-m-d H:i', strtotime((string)$post->publish_time)) : '',
            'publish_date' => $post->publish_time ? date('Y-m-d', strtotime((string)$post->publish_time)) : '',
            'publish_iso' => $post->publish_time ? date('c', strtotime((string)$post->publish_time)) : '',
            'reading' => PostService::readingMinutes((string)$post->content_md),
            'category' => $category,
            'tags' => $tags,
        ];
    }

    /** 详情页契约（在卡片基础上追加正文与 SEO） */
    public static function detail(Post $post): array
    {
        $data = self::card($post);
        $data['content_html'] = new \App\Util\Html((string)$post->content_html);
        $data['toc'] = json_decode((string)$post->toc, true) ?: [];
        $data['allow_comment'] = (int)$post->allow_comment;
        $data['author_name'] = (string)$post->author_name;
        $data['update_iso'] = $post->update_time ? date('c', strtotime((string)$post->update_time)) : $data['publish_iso'];
        $data['seo_title'] = (string)$post->seo_title;
        $data['seo_keywords'] = (string)$post->seo_keywords;
        $data['seo_description'] = (string)$post->seo_description;
        //独立域名下不挂商品卡：它指向商城的 /item/{id}，在那个域名下既打不开、也正是要藏起来的东西
        $data['commodity'] = Domain::active() ? null : \App\Plugin\Blog\Core\CommodityLink::forPost($post);
        return $data;
    }

    /** 摘要：手工优先，否则从 Markdown 源提纯截取 */
    public static function summary(Post $post): string
    {
        $manual = trim((string)$post->summary);
        if ($manual !== '') {
            return $manual;
        }
        return self::plainExcerpt((string)$post->content_md, Settings::int('summary_auto_len', 50, 500));
    }

    /** Markdown → 纯文本摘录 */
    public static function plainExcerpt(string $markdown, int $length): string
    {
        $text = $markdown;
        $text = preg_replace('/```[\s\S]*?```/u', ' ', $text) ?? $text;          //代码块
        $text = preg_replace('/^:::[a-z]*\s*$/miu', ' ', $text) ?? $text;         //提示框围栏
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', ' ', $text) ?? $text;     //图片
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;   //链接留文字
        $text = strip_tags($text);
        $text = preg_replace('/^#{1,6}\s+/mu', '', $text) ?? $text;               //标题井号
        $text = preg_replace('/[*_`>~|#-]{1,}/u', ' ', $text) ?? $text;           //其余记号
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        if (mb_strlen($text) > $length) {
            $text = rtrim(mb_substr($text, 0, $length), '，。,.;；:： ') . '…';
        }
        return $text;
    }
}
