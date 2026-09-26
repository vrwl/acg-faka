<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $post_count
 * @property string $create_time
 */
class Tag extends Model
{
    protected $table = 'blog_tag';
    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'post_count' => 'integer',
    ];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'blog_post_tag', 'tag_id', 'post_id');
    }
}
