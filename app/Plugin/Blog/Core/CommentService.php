<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

use App\Plugin\Blog\Model\Comment;
use App\Plugin\Blog\Model\Post;
use Illuminate\Database\Capsule\Manager;
use Kernel\Exception\JSONException;

/**
 * 评论：创建（限流/审核）、管理员快捷回复、批量审核、连带删除、计数重算。
 * 评论存纯文本，输出侧转义 + nl2br；嵌套用 root_id(顶楼) + parent_id(直接回复) 双字段。
 */
final class CommentService
{
    /**
     * 前台用户发表评论。
     * @throws JSONException
     */
    public static function create(Post $post, \App\Model\User $user, string $content, int $parentId, string $ip, string $ua): Comment
    {
        if (!Settings::bool('comment_enabled')) {
            throw new JSONException("评论功能已关闭");
        }
        if ((int)$post->allow_comment !== 1) {
            throw new JSONException("这篇文章已关闭评论");
        }

        $content = trim($content);
        if ($content === '') {
            throw new JSONException("评论内容不能为空");
        }
        $maxlen = Settings::int('comment_maxlen', 50, 5000);
        if (mb_strlen($content) > $maxlen) {
            throw new JSONException("评论内容过长，最多 " . $maxlen . " 字");
        }

        //频率限制：同一用户在窗口期内只允许一条。
        //提示里给「还需等待多少秒」，而不是固定的间隔值——用户等了 50 秒还被告知「请 60 秒后再试」很挫败
        $interval = Settings::int('comment_interval', 0, 3600);
        if ($interval > 0) {
            $last = Comment::query()
                ->where('user_id', (int)$user->id)
                ->orderByDesc('id')
                ->value('create_time');
            if ($last) {
                $wait = $interval - (time() - strtotime((string)$last));
                if ($wait > 0) {
                    throw new JSONException("评论太频繁了，请 " . $wait . " 秒后再试");
                }
            }
        }
        //同文重复内容拦截（10 分钟窗口）
        $dupe = Comment::query()
            ->where('user_id', (int)$user->id)
            ->where('post_id', (int)$post->id)
            ->where('content', $content)
            ->where('create_time', '>', date('Y-m-d H:i:s', time() - 600))
            ->exists();
        if ($dupe) {
            throw new JSONException("请不要重复发表相同内容");
        }

        $rootId = 0;
        $replyUserName = '';
        if ($parentId > 0) {
            /** @var Comment|null $parent */
            $parent = Comment::query()->find($parentId);
            if (!$parent || (int)$parent->post_id !== (int)$post->id) {
                throw new JSONException("要回复的评论不存在");
            }
            //只允许回复可见评论（或自己的待审评论）
            if ((int)$parent->status !== Comment::STATUS_APPROVED && (int)$parent->user_id !== (int)$user->id) {
                throw new JSONException("要回复的评论不存在");
            }
            $rootId = (int)$parent->root_id > 0 ? (int)$parent->root_id : (int)$parent->id;
            $replyUserName = (string)$parent->user_name;
        }

        $comment = new Comment();
        $comment->post_id = (int)$post->id;
        $comment->root_id = $rootId;
        $comment->parent_id = $parentId > 0 ? $parentId : 0;
        $comment->user_id = (int)$user->id;
        $comment->user_name = mb_substr((string)$user->username, 0, 32);
        $comment->is_admin = 0;
        $comment->reply_user_name = mb_substr($replyUserName, 0, 32);
        $comment->content = $content;
        $comment->status = Settings::bool('comment_audit') ? Comment::STATUS_PENDING : Comment::STATUS_APPROVED;
        $comment->create_ip = mb_substr($ip, 0, 64);
        $comment->ua = mb_substr($ua, 0, 255);
        $comment->create_time = Db::now();

        Manager::connection()->transaction(function () use ($comment, $post) {
            $comment->save();
            if ((int)$comment->status === Comment::STATUS_APPROVED) {
                Counter::recalcComments([(int)$post->id]);
            }
        });

        hook(\App\Plugin\Blog\Consts\Hook::COMMENT_CREATED, $comment, $post);
        return $comment;
    }

    /**
     * 管理员快捷回复：插入已通过的管理员评论；被回复评论若在待审则顺带通过（回复即认可）。
     * @throws JSONException
     */
    public static function adminReply(int $targetId, string $adminName, string $content): Comment
    {
        $content = trim($content);
        if ($content === '') {
            throw new JSONException("回复内容不能为空");
        }
        if (mb_strlen($content) > 5000) {
            throw new JSONException("回复内容过长");
        }

        /** @var Comment|null $target */
        $target = Comment::query()->find($targetId);
        if (!$target) {
            throw new JSONException("要回复的评论不存在");
        }
        /** @var Post|null $post */
        $post = Post::query()->find((int)$target->post_id);
        if (!$post) {
            throw new JSONException("评论所属文章不存在");
        }

        $reply = new Comment();
        $reply->post_id = (int)$post->id;
        $reply->root_id = (int)$target->root_id > 0 ? (int)$target->root_id : (int)$target->id;
        $reply->parent_id = (int)$target->id;
        $reply->user_id = 0;
        $reply->user_name = mb_substr($adminName !== '' ? $adminName : '管理员', 0, 32);
        $reply->is_admin = 1;
        $reply->reply_user_name = (string)$target->user_name;
        $reply->content = $content;
        $reply->status = Comment::STATUS_APPROVED;
        $reply->create_ip = '';
        $reply->ua = '';
        $reply->create_time = Db::now();

        $targetApproved = false;
        Manager::connection()->transaction(function () use ($reply, $target, $post, &$targetApproved) {
            $reply->save();
            if ((int)$target->status !== Comment::STATUS_APPROVED) {
                $target->status = Comment::STATUS_APPROVED;
                $target->save();
                $targetApproved = true;
            }
            Counter::recalcComments([(int)$post->id]);
        });

        hook(\App\Plugin\Blog\Consts\Hook::COMMENT_CREATED, $reply, $post);
        if ($targetApproved) {
            hook(\App\Plugin\Blog\Consts\Hook::COMMENT_APPROVED, $target, $post);
        }
        return $reply;
    }

    /**
     * 批量审核（status: 1=通过 2=垃圾 0=退回待审）。
     */
    public static function audit(array $ids, int $status): int
    {
        if (!in_array($status, [Comment::STATUS_PENDING, Comment::STATUS_APPROVED, Comment::STATUS_SPAM], true)) {
            throw new JSONException("审核状态不合法");
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }

        $comments = Comment::query()->whereIn('id', $ids)->get();
        $approvedEvents = [];
        $count = 0;

        Manager::connection()->transaction(function () use ($comments, $status, &$approvedEvents, &$count) {
            $postIds = [];
            foreach ($comments as $comment) {
                /** @var Comment $comment */
                $old = (int)$comment->status;
                if ($old === $status) {
                    continue;
                }
                $comment->status = $status;
                $comment->save();
                $count++;
                $postIds[] = (int)$comment->post_id;
                if ($status === Comment::STATUS_APPROVED) {
                    $approvedEvents[] = $comment;
                }
            }
            Counter::recalcComments($postIds);
        });

        if ($approvedEvents) {
            $posts = Post::query()->whereIn('id', array_unique(array_map(function (Comment $c) {
                return (int)$c->post_id;
            }, $approvedEvents)))->get()->keyBy('id');
            foreach ($approvedEvents as $comment) {
                $post = $posts->get((int)$comment->post_id);
                if ($post) {
                    hook(\App\Plugin\Blog\Consts\Hook::COMMENT_APPROVED, $comment, $post);
                }
            }
        }
        return $count;
    }

    /**
     * 删除（顶楼被删时连带全部楼中楼，防孤儿评论）。
     */
    public static function delete(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $count = 0;
        Manager::connection()->transaction(function () use ($ids, &$count) {
            $postIds = Db::table(Db::COMMENT)->whereIn('id', $ids)->pluck('post_id')->all();
            //顶楼 id 集合（root_id=0 的本体）→ 连带其全部子孙
            $rootIds = Db::table(Db::COMMENT)->whereIn('id', $ids)->where('root_id', 0)->pluck('id')->all();
            $count = Db::table(Db::COMMENT)
                ->where(function ($query) use ($ids, $rootIds) {
                    $query->whereIn('id', $ids);
                    if ($rootIds) {
                        $query->orWhereIn('root_id', $rootIds);
                    }
                })
                ->delete();
            Counter::recalcComments(array_map('intval', $postIds));
        });
        return (int)$count;
    }
}
