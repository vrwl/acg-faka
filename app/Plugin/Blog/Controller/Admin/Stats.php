<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller\Admin;

use App\Controller\Base\API\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Plugin\Blog\Model\Comment as CommentModel;
use App\Plugin\Blog\Model\Post as PostModel;
use App\Plugin\Blog\Model\Category as CategoryModel;
use App\Plugin\Blog\Model\Tag as TagModel;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Stats extends ManagePlugin
{
    public function overview(): array
    {
        $now = date('Y-m-d H:i:s');
        $posts = PostModel::query()->where('type', PostModel::TYPE_POST);

        return $this->json(data: [
            'posts' => (clone $posts)->count(),
            'published' => (clone $posts)->where('status', PostModel::STATUS_PUBLISHED)->count(),
            'drafts' => (clone $posts)->where('status', PostModel::STATUS_DRAFT)->count(),
            'scheduled' => (clone $posts)->where('status', PostModel::STATUS_PUBLISHED)->where('publish_time', '>', $now)->count(),
            'pages' => PostModel::query()->where('type', PostModel::TYPE_PAGE)->count(),
            'views' => (int)PostModel::query()->sum('views'),
            'likes' => (int)PostModel::query()->sum('likes'),
            'comments_total' => CommentModel::query()->count(),
            'comments_pending' => CommentModel::query()->where('status', CommentModel::STATUS_PENDING)->count(),
            'categories' => CategoryModel::query()->count(),
            'tags' => TagModel::query()->count(),
        ]);
    }

    /** 侧栏菜单待审徽标（轻量端点） */
    public function pendingBadge(): array
    {
        return $this->json(data: [
            'count' => CommentModel::query()->where('status', CommentModel::STATUS_PENDING)->count(),
        ]);
    }
}
