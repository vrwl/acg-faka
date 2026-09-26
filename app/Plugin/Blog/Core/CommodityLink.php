<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

use App\Model\Commodity;
use App\Plugin\Blog\Model\Post;
use App\Plugin\Blog\Support\Url;

/**
 * 文章 ↔ 商品 双向绑定。
 *  - 文章页顶部展示商品卡（引导下单）
 *  - 商品页「商品介绍」顶部展示相关文档卡（引导阅读）
 *
 * 商品侧注入走 USER_API_INDEX_COMMODITY_DETAIL_INFO(0x51)：该钩子按引用传 $item，
 * 直接改写 description 即可让全部主题自动生效，无需改任何模板。
 */
final class CommodityLink
{
    /** 商品详情页地址（内核对 /item/{id} 有伪静态改写） */
    public static function itemUrl(int $commodityId): string
    {
        return '/item/' . $commodityId;
    }

    /**
     * 取商品展示信息（文章页商品卡用）。商品不存在/已下架返回 null。
     */
    public static function forPost(Post $post): ?array
    {
        $id = (int)$post->commodity_id;
        if ($id <= 0) {
            return null;
        }
        try {
            /** @var Commodity|null $commodity */
            $commodity = Commodity::query()->find($id, ['id', 'name', 'cover', 'price', 'user_price', 'status', 'delivery_way', 'owner']);
            if (!$commodity || (int)$commodity->status !== 1) {
                return null;
            }
            return [
                'id' => (int)$commodity->id,
                'name' => \App\Util\Html::trusted((string)$commodity->name, (int)$commodity->owner === 0),
                'cover' => (string)$commodity->cover,
                'price' => self::money($commodity->price),
                'url' => self::itemUrl((int)$commodity->id),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 取某商品的相关文档（商品页注入用），最多 3 篇。
     */
    public static function postsForCommodity(int $commodityId, int $limit = 3): array
    {
        if ($commodityId <= 0) {
            return [];
        }
        $list = [];
        try {
            $posts = Post::query()
                ->where('type', Post::TYPE_POST)
                ->where('commodity_id', $commodityId)
                ->visible()
                ->orderByDesc('top')
                ->orderByDesc('publish_time')
                ->limit($limit)
                ->get(['id', 'title', 'slug', 'summary', 'cover', 'content_md', 'views', 'publish_time']);
            foreach ($posts as $post) {
                $list[] = [
                    'title' => (string)$post->title,
                    'url' => Url::post((string)$post->slug),
                    'summary' => \App\Plugin\Blog\Support\Present::summary($post),
                    'cover' => (string)$post->cover,
                    'views' => (int)$post->views,
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $list;
    }

    /**
     * 商品页注入的教程卡 HTML。
     * 自带作用域样式（商城主题不加载博客 CSS），选择器一律 #acg-blog-guide 前缀做特异性护甲，
     * 且用 :where() 兜底重置，避免被主题的全局规则改形。
     */
    public static function renderGuide(array $posts): string
    {
        if (!$posts) {
            return '';
        }

        $title = htmlspecialchars(lang('相关文档', 'tpl'), ENT_QUOTES);
        $more = htmlspecialchars(lang('阅读文档', 'tpl'), ENT_QUOTES);
        $items = '';
        foreach ($posts as $post) {
            $url = htmlspecialchars($post['url'], ENT_QUOTES);
            $name = htmlspecialchars($post['title'], ENT_QUOTES);
            $summary = htmlspecialchars(mb_substr((string)$post['summary'], 0, 70), ENT_QUOTES);
            $thumb = $post['cover'] !== ''
                ? '<span class="agd-thumb"><img src="' . htmlspecialchars($post['cover'], ENT_QUOTES) . '" alt="" loading="lazy"></span>'
                : '<span class="agd-thumb agd-thumb--empty"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H12v16H5.5A1.5 1.5 0 0 1 4 18.5z"/><path d="M20 5.5A1.5 1.5 0 0 0 18.5 4H12v16h6.5a1.5 1.5 0 0 0 1.5-1.5z"/></svg></span>';

            $items .= '<a class="agd-item" href="' . $url . '">'
                . $thumb
                . '<span class="agd-body"><b class="agd-name">' . $name . '</b>'
                . ($summary !== '' ? '<span class="agd-desc">' . $summary . '</span>' : '')
                . '</span>'
                . '<span class="agd-go">' . $more . '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></span>'
                . '</a>';
        }

        return <<<HTML
<div id="acg-blog-guide">
<style>
#acg-blog-guide :where(a,span,b,svg,img,div){box-sizing:border-box}
#acg-blog-guide{--agd-line:rgba(128,140,170,.22);--agd-ink:currentColor;margin:0 0 18px;padding:14px 16px;border:1px solid var(--agd-line);border-radius:14px;background:linear-gradient(135deg,rgba(120,150,255,.07),rgba(160,120,255,.05));font-size:14px;line-height:1.6}
#acg-blog-guide .agd-head{display:flex;align-items:center;gap:7px;margin:0 0 10px;font-weight:700;font-size:13px;letter-spacing:.02em;opacity:.85}
#acg-blog-guide .agd-head svg{flex:none;opacity:.75}
#acg-blog-guide .agd-item{display:flex;align-items:center;gap:12px;padding:10px;border-radius:11px;text-decoration:none;color:inherit;transition:background .16s ease,transform .16s ease}
#acg-blog-guide .agd-item+.agd-item{margin-top:4px}
#acg-blog-guide .agd-item:hover{background:rgba(128,140,170,.1)}
#acg-blog-guide .agd-item:active{transform:scale(.99)}
#acg-blog-guide .agd-thumb{flex:none;width:52px;height:52px;border-radius:10px;overflow:hidden;background:rgba(128,140,170,.14);display:flex;align-items:center;justify-content:center;opacity:.9}
#acg-blog-guide .agd-thumb img{width:100%;height:100%;object-fit:cover;display:block;border-radius:0;margin:0;max-width:none}
#acg-blog-guide .agd-body{flex:1;min-width:0}
#acg-blog-guide .agd-name{display:block;font-weight:650;font-size:14px;line-height:1.45;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#acg-blog-guide .agd-desc{display:block;margin-top:2px;font-size:12px;opacity:.6;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#acg-blog-guide .agd-go{flex:none;display:inline-flex;align-items:center;gap:3px;font-size:12.5px;font-weight:650;opacity:.7}
@media (max-width:520px){#acg-blog-guide .agd-go span{display:none}#acg-blog-guide .agd-desc{display:none}}
</style>
<div class="agd-head"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H12v16H5.5A1.5 1.5 0 0 1 4 18.5z"/><path d="M20 5.5A1.5 1.5 0 0 0 18.5 4H12v16h6.5a1.5 1.5 0 0 0 1.5-1.5z"/></svg>{$title}</div>
{$items}
</div>
HTML;
    }

    private static function money(mixed $value): string
    {
        $symbol = '';
        try {
            $config = \App\Model\Config::list();
            $symbol = (string)($config['currency_symbol'] ?? '');
        } catch (\Throwable $e) {
        }
        return $symbol . rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }
}
