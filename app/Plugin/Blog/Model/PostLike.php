<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * 文章点赞（仅登录用户，复合主键去重）。
 *
 * @property int $post_id
 * @property int $user_id
 * @property string $create_time
 */
class PostLike extends Model
{
    protected $table = 'blog_like';
    public $timestamps = false;
    protected $primaryKey = null;
    public $incrementing = false;

    protected $casts = [
        'post_id' => 'integer',
        'user_id' => 'integer',
    ];
}
