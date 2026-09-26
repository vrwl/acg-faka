<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * 薄 pivot 模型：blog_post_tag（复合主键，无自增 id，只用于直查/批删）。
 *
 * @property int $post_id
 * @property int $tag_id
 */
class PostTag extends Model
{
    protected $table = 'blog_post_tag';
    public $timestamps = false;
    protected $primaryKey = null;
    public $incrementing = false;

    protected $casts = [
        'post_id' => 'integer',
        'tag_id' => 'integer',
    ];
}
