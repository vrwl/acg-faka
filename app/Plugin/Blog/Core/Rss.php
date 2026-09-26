<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

use App\Plugin\Blog\Model\Post;
use App\Plugin\Blog\Support\Present;
use App\Plugin\Blog\Support\Url;

/**
 * RSS 2.0 与 sitemap.xml 生成（DOMDocument，全字段自动转义）。
 */
final class Rss
{
    public static function feed(string $blogName, string $description): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $rss = $dom->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        if (Settings::bool('feed_fulltext')) {
            $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        }
        $dom->appendChild($rss);

        $channel = $dom->createElement('channel');
        $rss->appendChild($channel);

        $add = function (\DOMElement $parent, string $name, string $value) use ($dom) {
            $node = $dom->createElement($name);
            $node->appendChild($dom->createTextNode($value));
            $parent->appendChild($node);
            return $node;
        };

        $add($channel, 'title', $blogName);
        $add($channel, 'link', Url::absolute(Url::index()));
        $add($channel, 'description', $description);
        $add($channel, 'language', function_exists('lang_code') ? lang_code() : 'zh-CN');
        $add($channel, 'generator', '次元博客');

        $atomLink = $dom->createElement('atom:link');
        $atomLink->setAttribute('href', Url::absolute(Url::feed()));
        $atomLink->setAttribute('rel', 'self');
        $atomLink->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($atomLink);

        $posts = self::latestPosts(Settings::int('feed_items', 1, 100));
        if ($posts) {
            $add($channel, 'lastBuildDate', date(DATE_RSS, strtotime((string)$posts[0]->publish_time)));
        }

        $fulltext = Settings::bool('feed_fulltext');
        foreach ($posts as $post) {
            if ($fulltext) {
                PostService::lazyRender($post);
            }
            $item = $dom->createElement('item');
            $channel->appendChild($item);
            $add($item, 'title', (string)$post->title);
            $link = Url::absolute(Url::post((string)$post->slug));
            $add($item, 'link', $link);
            $guid = $add($item, 'guid', $link);
            $guid->setAttribute('isPermaLink', 'true');
            $add($item, 'pubDate', date(DATE_RSS, strtotime((string)$post->publish_time)));
            if ($post->relationLoaded('category') && $post->category) {
                $add($item, 'category', (string)$post->category->name);
            }
            $add($item, 'description', Present::summary($post));
            if ($fulltext) {
                $content = $dom->createElement('content:encoded');
                $content->appendChild($dom->createCDATASection((string)$post->content_html));
                $item->appendChild($content);
            }
        }

        return (string)$dom->saveXML();
    }

    public static function sitemap(): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $urlset = $dom->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $dom->appendChild($urlset);

        $push = function (string $loc, ?string $lastmod = null, string $freq = 'weekly', string $priority = '0.6') use ($dom, $urlset) {
            $url = $dom->createElement('url');
            $locNode = $dom->createElement('loc');
            $locNode->appendChild($dom->createTextNode(Url::absolute($loc)));
            $url->appendChild($locNode);
            if ($lastmod) {
                $url->appendChild($dom->createElement('lastmod', date('c', strtotime($lastmod))));
            }
            $url->appendChild($dom->createElement('changefreq', $freq));
            $url->appendChild($dom->createElement('priority', $priority));
            $urlset->appendChild($url);
        };

        $push(Url::index(), null, 'daily', '1.0');
        $push(Url::categoryIndex(), null, 'weekly', '0.5');
        $push(Url::archive(), null, 'weekly', '0.4');

        foreach (Post::query()->visible()->orderByDesc('publish_time')->limit(5000)
                     ->get(['type', 'slug', 'update_time', 'publish_time']) as $post) {
            $loc = $post->type === Post::TYPE_PAGE ? Url::page((string)$post->slug) : Url::post((string)$post->slug);
            $push($loc, (string)($post->update_time ?: $post->publish_time), 'monthly', '0.8');
        }

        foreach (\App\Plugin\Blog\Model\Category::query()->where('post_count', '>', 0)->get(['slug']) as $category) {
            $push(Url::category((string)$category->slug), null, 'weekly', '0.5');
        }

        return (string)$dom->saveXML();
    }

    /** 最新可见文章（feed 用，带分类） */
    public static function latestPosts(int $limit): array
    {
        return Post::query()->where('type', Post::TYPE_POST)->visible()
            ->with(['category:id,name,slug'])
            ->orderByDesc('publish_time')
            ->limit($limit)
            ->get()
            ->all();
    }

    /** 最新更新时间（Last-Modified/304 用） */
    public static function lastModified(): int
    {
        $latest = Post::query()->visible()->orderByDesc('update_time')->first(['update_time', 'publish_time']);
        if (!$latest) {
            return time();
        }
        return (int)strtotime((string)($latest->update_time ?: $latest->publish_time));
    }
}
