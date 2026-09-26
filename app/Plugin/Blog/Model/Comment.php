<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $post_id
 * @property int $root_id 顶楼评论 id，0=自身是顶楼
 * @property int $parent_id 直接回复目标评论 id
 * @property int $user_id 0=管理员快捷回复
 * @property string $user_name 用户名快照
 * @property int $is_admin
 * @property string $reply_user_name 被回复者名快照
 * @property string $content 纯文本
 * @property int $status 0=待审 1=通过 2=垃圾
 * @property string $create_ip
 * @property string $ua
 * @property string $create_time
 */
class Comment extends Model
{
    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_SPAM = 2;

    protected $table = 'blog_comment';
    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'post_id' => 'integer',
        'root_id' => 'integer',
        'parent_id' => 'integer',
        'user_id' => 'integer',
        'is_admin' => 'integer',
        'status' => 'integer',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Model\User::class, 'user_id');
    }
}
