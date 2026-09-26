<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller\Api;

use App\Controller\Base\API\UserPlugin;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Plugin\Blog\Core\Db;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Model\Post as PostModel;
use App\Plugin\Blog\Model\PostLike;
use Illuminate\Database\Capsule\Manager;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

/**
 * 文章点赞（登录用户，复合主键去重，toggle 语义）。
 */
#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Like extends UserPlugin
{
    public function toggle(): array
    {
        if (!Settings::bool('like_enabled')) {
            throw new JSONException("点赞功能已关闭");
        }
        $user = $this->getUser();
        $postId = (int)$this->request->post('post_id');

        /** @var PostModel|null $post */
        $post = PostModel::query()->visible()->find($postId);
        if (!$post) {
            throw new JSONException("文章不存在");
        }

        $liked = false;
        Manager::connection()->transaction(function () use ($post, $user, &$liked) {
            $exists = PostLike::query()->where('post_id', $post->id)->where('user_id', $user->id)->exists();
            if ($exists) {
                Db::table(Db::LIKE)->where('post_id', $post->id)->where('user_id', $user->id)->delete();
                PostModel::query()->where('id', $post->id)->where('likes', '>', 0)->decrement('likes');
                $liked = false;
            } else {
                Db::table(Db::LIKE)->insert([
                    'post_id' => (int)$post->id,
                    'user_id' => (int)$user->id,
                    'create_time' => Db::now(),
                ]);
                PostModel::query()->where('id', $post->id)->increment('likes');
                $liked = true;
            }
        });

        $likes = (int)(PostModel::query()->find($postId)->likes ?? 0);
        return $this->json(data: ['liked' => $liked ? 1 : 0, 'likes' => $likes]);
    }
}
