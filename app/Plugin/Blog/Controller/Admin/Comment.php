<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller\Admin;

use App\Controller\Base\API\ManagePlugin;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use App\Plugin\Blog\Core\CommentService;
use App\Plugin\Blog\Model\Comment as CommentModel;
use App\Service\Query;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

/**
 * 评论审核台 API。回复内容走 base64 通道（评论里可能贴 SQL/命令，同样会被 WAF 误杀）。
 */
#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Comment extends ManagePlugin
{
    #[Inject]
    private Query $query;

    public function data(): array
    {
        $get = new Get(CommentModel::class);
        $get->setPaginate((int)$this->request->post('page'), (int)$this->request->post('limit'));
        $get->setOrderBy('id');
        $get->setWhere($_POST);
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with([
                'user:id,username,avatar',
                'post:id,title,slug,type',
            ]);
        });

        //带出父评论摘要（回复上下文）
        $parentIds = [];
        foreach ($data['list'] as $row) {
            if (!empty($row['parent_id'])) {
                $parentIds[] = (int)$row['parent_id'];
            }
        }
        $parents = [];
        if ($parentIds) {
            foreach (CommentModel::query()->whereIn('id', array_unique($parentIds))->get(['id', 'user_name', 'content']) as $parent) {
                $parents[(int)$parent->id] = [
                    'user_name' => (string)$parent->user_name,
                    'content' => mb_substr((string)$parent->content, 0, 80),
                ];
            }
        }
        foreach ($data['list'] as &$row) {
            $row['parent'] = $parents[(int)($row['parent_id'] ?? 0)] ?? null;
        }

        return $this->json(data: $data);
    }

    public function audit(): array
    {
        $list = $this->request->post('list');
        $status = (int)$this->request->post('status');
        $count = CommentService::audit(is_array($list) ? $list : [], $status);
        $label = [0 => '退回待审', 1 => '通过', 2 => '标记垃圾'][$status] ?? (string)$status;
        ManageLog::log($this->getManage(), "[次元博客]评论{$label}：{$count} 条");
        return $this->json(200, '已处理', ['count' => $count]);
    }

    public function del(): array
    {
        $list = $this->request->post('list');
        $count = CommentService::delete(is_array($list) ? $list : []);
        ManageLog::log($this->getManage(), "[次元博客]删除评论：{$count} 条（含楼中楼）");
        return $this->json(200, '已删除', ['count' => $count]);
    }

    public function reply(): array
    {
        $targetId = (int)$this->request->post('id');
        $b64 = (string)($this->request->unsafePost('content_b64') ?? '');
        $content = base64_decode($b64, true);
        if ($content === false || !mb_check_encoding($content, 'UTF-8')) {
            throw new JSONException("回复内容编码不正确");
        }

        $manage = $this->getManage();
        $reply = CommentService::adminReply($targetId, (string)($manage->nickname ?: '管理员'), $content);

        ManageLog::log($manage, "[次元博客]回复了评论 #{$targetId}");
        return $this->json(200, '已回复', ['id' => (int)$reply->id]);
    }
}
