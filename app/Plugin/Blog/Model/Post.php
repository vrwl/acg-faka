<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $type post|page
 * @property string $title
 * @property string $slug
 * @property int $category_id
 * @property int $commodity_id 绑定的商品 id，0=未绑定
 * @property string $cover
 * @property string $summary
 * @property string|null $content_md
 * @property string|null $content_html
 * @property string|null $toc
 * @property int $render_version
 * @property int $status 0=草稿 1=发布 2=隐藏
 * @property int $top
 * @property int $allow_comment
 * @property int $weight
 * @property string $template
 * @property int $views
 * @property int $likes
 * @property int $comments_count
 * @property string $seo_title
 * @property string $seo_keywords
 * @property string $seo_description
 * @property int $author_id
 * @property string $author_name
 * @property string|null $publish_time
 * @property string $create_time
 * @property string|null $update_time
 */
class Post extends Model
{
    public const TYPE_POST = 'post';
    public const TYPE_PAGE = 'page';

    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;
    public const STATUS_HIDDEN = 2;

    protected $table = 'blog_post';
    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'category_id' => 'integer',
        'commodity_id' => 'integer',
        'render_version' => 'integer',
        'status' => 'integer',
        'top' => 'integer',
        'allow_comment' => 'integer',
        'weight' => 'integer',
        'views' => 'integer',
        'likes' => 'integer',
        'comments_count' => 'integer',
        'author_id' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'blog_post_tag', 'post_id', 'tag_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }

    /** 前台可见口径：已发布且发布时间已到（定时文章到点自动可见） */
    public function scopeVisible($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where('publish_time', '<=', date('Y-m-d H:i:s'));
    }
}
