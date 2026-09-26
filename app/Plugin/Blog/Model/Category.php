<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $parent_id
 * @property string $description
 * @property string $cover
 * @property int $weight
 * @property int $post_count
 * @property string $create_time
 */
class Category extends Model
{
    public const MAX_DEPTH = 3;

    protected $table = 'blog_category';
    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'parent_id' => 'integer',
        'weight' => 'integer',
        'post_count' => 'integer',
    ];

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'category_id');
    }
}
