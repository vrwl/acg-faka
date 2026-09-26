<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller\Api;

use App\Controller\Base\API\UserPlugin;
use App\Interceptor\UserSession;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Plugin\Blog\Core\CommentService;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Model\Comment as CommentModel;
use App\Plugin\Blog\Model\Post as PostModel;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

/**
 * 前台评论：list 游客可读（另见自己的待审），create 需登录（内容 base64 通道）。
 */
#[Interceptor([Waf::class, UserVisitor::class], Interceptor::TYPE_API)]
class Comment extends UserPlugin
{
    private const PER_PAGE = 10;

    public function list(): array
    {
        //博客设为「需登录可见」时，这个接口也必须闭合：
        //页面、文章流、搜索建议都挡住了游客，唯独评论列表放行的话，
        //未登录的人照样能读到评论正文、昵称和头像，还能拿它当文章存在性探测器
        if (!Settings::bool('guest_visible') && !$this->getUser()) {
            //显式标记，前端才不会把它误判成普通业务报错（JSONException 的 code 恒为 0）
            return $this->json(0, '请登录后访问博客', ['need_login' => 1]);
        }

        $postId = (int)$this->request->post('post_id');
        $page = max(1, (int)$this->request->post('page'));
        $me = $this->getUser();
        $meId = $me ? (int)$me->id : 0;

        /** @var PostModel|null $post */
        $post = PostModel::query()->visible()->find($postId);
        if (!$post) {
            throw new JSONException("文章不存在");
        }

        $visibility = function ($query) use ($meId) {
            $query->where('status', CommentModel::STATUS_APPROVED);
            if ($meId > 0) {
                $query->orWhere(function ($q) use ($meId) {
                    $q->where('status', CommentModel::STATUS_PENDING)->where('user_id', $meId);
                });
            }
        };

        $rootsQuery = CommentModel::query()->where('post_id', $postId)->where('root_id', 0)
            ->where($visibility)->with('user:id,avatar');
        $total = (clone $rootsQuery)->count();
        $roots = $rootsQuery->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        $rootIds = $roots->pluck('id')->all();
        $childrenMap = [];
        if ($rootIds) {
            $children = CommentModel::query()->where('post_id', $postId)
                ->whereIn('root_id', $rootIds)
                ->where($visibility)->with('user:id,avatar')
                ->orderBy('id')->get();
            foreach ($children as $child) {
                $childrenMap[(int)$child->root_id][] = $this->view($child, $meId);
            }
        }

        $list = [];
        foreach ($roots as $root) {
            $item = $this->view($root, $meId);
            $item['children'] = $childrenMap[(int)$root->id] ?? [];
            $list[] = $item;
        }

        return $this->json(data: [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pages' => (int)ceil($total / self::PER_PAGE),
            'count_approved' => (int)$post->comments_count,
        ]);
    }

    #[Interceptor(UserSession::class, Interceptor::TYPE_API)]
    public function create(): array
    {
        $user = $this->getUser();
        $postId = (int)$this->request->post('post_id');
        $parentId = max(0, (int)$this->request->post('parent_id'));

        $b64 = (string)($this->request->unsafePost('content_b64') ?? '');
        $content = base64_decode($b64, true);
        if ($content === false || !mb_check_encoding($content, 'UTF-8')) {
            throw new JSONException("内容编码不正确，请刷新后重试");
        }

        /** @var PostModel|null $post */
        $post = PostModel::query()->visible()->find($postId);
        if (!$post) {
            throw new JSONException("文章不存在");
        }

        $comment = CommentService::create(
            $post,
            $user,
            $content,
            $parentId,
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        $view = $this->view($comment, (int)$user->id);
        $view['children'] = [];
        return $this->json(200, $comment->status == CommentModel::STATUS_PENDING ? '已提交，审核通过后对所有人可见' : '评论成功', [
            'comment' => $view,
            'pending' => $comment->status == CommentModel::STATUS_PENDING ? 1 : 0,
        ]);
    }

    private function view(CommentModel $comment, int $meId): array
    {
        $authorIds = array_map('intval', Settings::list('author_uids'));
        return [
            'id' => (int)$comment->id,
            'root_id' => (int)$comment->root_id,
            'parent_id' => (int)$comment->parent_id,
            'name' => (string)$comment->user_name,
            'avatar' => $comment->user ? (string)$comment->user->avatar : '',
            'is_admin' => (int)$comment->is_admin,
            'is_author' => in_array((int)$comment->user_id, $authorIds, true) ? 1 : 0,
            'reply_to' => (string)$comment->reply_user_name,
            'content' => (string)$comment->content,
            'time' => (string)$comment->create_time,
            'own' => $meId > 0 && (int)$comment->user_id === $meId ? 1 : 0,
            'pending' => (int)$comment->status === CommentModel::STATUS_PENDING ? 1 : 0,
        ];
    }
}
