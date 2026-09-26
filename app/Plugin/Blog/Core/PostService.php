<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

use App\Plugin\Blog\Core\Markdown\Renderer;
use App\Plugin\Blog\Model\Post;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;
use Kernel\Exception\JSONException;

/**
 * 文章/独立页面的保存管线与状态流转。
 * 管线：字段校验 → slug 唯一化 → Markdown 渲染入库 → 标签 pivot 同步 → 计数重算 → (事务提交后)广播。
 */
final class PostService
{
    /**
     * @param array $input 规范化输入：
     *   id?, type(post|page), title, slug?, category_id, commodity_id, tags(名称数组), cover, summary,
     *   seo_title, seo_keywords, seo_description, status, top, allow_comment,
     *   publish_time(''=自动), weight, template, content_md, autosave(bool),
     *   author_id, author_name
     * @throws JSONException
     */
    public static function save(array $input): Post
    {
        $id = (int)($input['id'] ?? 0);
        $autosave = !empty($input['autosave']);
        $type = ($input['type'] ?? Post::TYPE_POST) === Post::TYPE_PAGE ? Post::TYPE_PAGE : Post::TYPE_POST;

        /** @var Post|null $post */
        $post = $id > 0 ? Post::query()->find($id) : null;
        if ($id > 0 && !$post) {
            throw new JSONException("文章不存在或已被删除");
        }
        if ($post && $post->type !== $type) {
            $type = $post->type; //类型创建后不可变
        }

        //自动保存只允许作用于草稿（防止半成品覆盖已发布内容）
        if ($autosave && $post && (int)$post->status !== Post::STATUS_DRAFT) {
            throw new JSONException("已发布内容不接受自动保存，请手动更新");
        }

        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            if ($autosave) {
                $title = '未命名草稿';
            } else {
                throw new JSONException("标题不能为空");
            }
        }
        $title = mb_substr($title, 0, 200);

        $status = (int)($input['status'] ?? Post::STATUS_DRAFT);
        if (!in_array($status, [Post::STATUS_DRAFT, Post::STATUS_PUBLISHED, Post::STATUS_HIDDEN], true)) {
            $status = Post::STATUS_DRAFT;
        }
        if ($autosave) {
            $status = Post::STATUS_DRAFT;
        }

        $contentMd = (string)($input['content_md'] ?? '');
        if ($status === Post::STATUS_PUBLISHED && trim($contentMd) === '') {
            throw new JSONException("正文为空，无法发布");
        }

        //发布时间：空=首次发布时定为当下；给定值须合法（可为未来=定时发布）
        $publishTime = trim((string)($input['publish_time'] ?? ''));
        if ($publishTime !== '') {
            $ts = strtotime($publishTime);
            if ($ts === false) {
                throw new JSONException("发布时间格式不正确");
            }
            $publishTime = date('Y-m-d H:i:s', $ts);
        }

        $now = Db::now();
        $isNew = $post === null;
        if ($isNew) {
            $post = new Post();
            $post->type = $type;
            $post->views = 0;
            $post->likes = 0;
            $post->comments_count = 0;
            $post->create_time = $now;
            $post->author_id = (int)($input['author_id'] ?? 0);
            $post->author_name = mb_substr((string)($input['author_name'] ?? ''), 0, 32);
        }

        $oldStatus = $isNew ? null : (int)$post->getOriginal('status');
        $oldCategoryId = $isNew ? 0 : (int)$post->getOriginal('category_id');
        $oldPublishTime = $isNew ? null : $post->getOriginal('publish_time');

        //slug：显式传入 → 归一唯一化；新建未传 → 由标题生成；更新未传 → 保持不变
        $slugInput = trim((string)($input['slug'] ?? ''));
        if ($slugInput !== '') {
            $post->slug = Slug::unique($slugInput, Db::POST, (int)($post->id ?? 0));
        } elseif ($isNew || (string)$post->slug === '') {
            $post->slug = Slug::unique(Slug::fromTitle($title), Db::POST, (int)($post->id ?? 0));
        }

        $post->title = $title;
        $post->category_id = $type === Post::TYPE_PAGE ? 0 : max(0, (int)($input['category_id'] ?? 0));
        $post->commodity_id = max(0, (int)($input['commodity_id'] ?? 0));
        $post->cover = mb_substr(trim((string)($input['cover'] ?? '')), 0, 255);
        $post->summary = mb_substr(trim((string)($input['summary'] ?? '')), 0, 500);
        $post->seo_title = mb_substr(trim((string)($input['seo_title'] ?? '')), 0, 200);
        $post->seo_keywords = mb_substr(trim((string)($input['seo_keywords'] ?? '')), 0, 255);
        $post->seo_description = mb_substr(trim((string)($input['seo_description'] ?? '')), 0, 500);
        $post->status = $status;
        $post->top = !empty($input['top']) ? 1 : 0;
        $post->allow_comment = !empty($input['allow_comment']) ? 1 : 0;
        $post->weight = max(0, (int)($input['weight'] ?? 0));
        $post->template = mb_substr(trim((string)($input['template'] ?? '')), 0, 32);

        //渲染管线（保存时渲染入库，前台直出）
        $rendered = Renderer::render($contentMd);
        $post->content_md = $contentMd;
        $post->content_html = $rendered['html'];
        $post->toc = json_encode($rendered['toc'], JSON_UNESCAPED_UNICODE);
        $post->render_version = Renderer::VERSION;

        if ($publishTime !== '') {
            $post->publish_time = $publishTime;
        } elseif ($status === Post::STATUS_PUBLISHED && !$post->publish_time) {
            $post->publish_time = $now;
        }
        $post->update_time = $now;

        $tagNames = is_array($input['tags'] ?? null) ? $input['tags'] : [];
        $isFirstPublish = $status === Post::STATUS_PUBLISHED
            && $oldStatus !== Post::STATUS_PUBLISHED
            && $oldPublishTime === null;
        $becamePublished = $status === Post::STATUS_PUBLISHED && $oldStatus !== Post::STATUS_PUBLISHED;
        $updatedWhilePublished = !$isNew && $status === Post::STATUS_PUBLISHED && $oldStatus === Post::STATUS_PUBLISHED;

        Manager::connection()->transaction(function () use ($post, $type, $tagNames, $oldCategoryId) {
            try {
                $post->save();
            } catch (QueryException $e) {
                //slug 唯一键并发兜底：追随机后缀重试一次
                if (str_contains($e->getMessage(), 'uk_slug') || str_contains($e->getMessage(), 'Duplicate')) {
                    $post->slug = $post->slug . '-' . substr(md5((string)mt_rand()), 0, 6);
                    $post->save();
                } else {
                    throw $e;
                }
            }

            if ($type === Post::TYPE_POST) {
                $tagIds = TagService::ensure($tagNames);
                $affectedTags = TagService::sync((int)$post->id, $tagIds);
                TagService::recalcCount($affectedTags);
            }
            Counter::recalcCategory([$oldCategoryId, (int)$post->category_id]);
        });

        //事务提交后广播（订阅者可能做慢 IO）
        if ($type === Post::TYPE_POST) {
            if ($becamePublished) {
                hook(\App\Plugin\Blog\Consts\Hook::POST_PUBLISHED, $post, $isFirstPublish);
            } elseif ($updatedWhilePublished) {
                hook(\App\Plugin\Blog\Consts\Hook::POST_UPDATED, $post);
            }
        }

        return $post;
    }

    /**
     * 批量状态流转（列表页快捷操作）。
     */
    public static function setStatus(array $ids, int $status): int
    {
        if (!in_array($status, [Post::STATUS_DRAFT, Post::STATUS_PUBLISHED, Post::STATUS_HIDDEN], true)) {
            throw new JSONException("状态不合法");
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $posts = Post::query()->whereIn('id', $ids)->get();
        $events = [];
        $count = 0;

        Manager::connection()->transaction(function () use ($posts, $status, &$events, &$count) {
            $categoryIds = [];
            $tagIds = [];
            foreach ($posts as $post) {
                /** @var Post $post */
                $old = (int)$post->status;
                if ($old === $status) {
                    continue;
                }
                if ($status === Post::STATUS_PUBLISHED && trim((string)$post->content_md) === '') {
                    continue; //空文没法发布，静默跳过
                }
                $post->status = $status;
                if ($status === Post::STATUS_PUBLISHED && !$post->publish_time) {
                    $post->publish_time = Db::now();
                }
                $post->update_time = Db::now();
                $post->save();
                $count++;

                $categoryIds[] = (int)$post->category_id;
                foreach (Db::table(Db::POST_TAG)->where('post_id', (int)$post->id)->pluck('tag_id')->all() as $tid) {
                    $tagIds[] = (int)$tid;
                }
                if ($status === Post::STATUS_PUBLISHED && $old !== Post::STATUS_PUBLISHED) {
                    $events[] = [$post, $old !== Post::STATUS_HIDDEN && $post->getOriginal('publish_time') === null];
                }
            }
            Counter::recalcCategory($categoryIds);
            TagService::recalcCount($tagIds);
        });

        foreach ($events as [$post, $isFirst]) {
            hook(\App\Plugin\Blog\Consts\Hook::POST_PUBLISHED, $post, (bool)$isFirst);
        }
        return $count;
    }

    /**
     * 删除（连带评论/点赞/标签关联），返回删除数。
     */
    public static function delete(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $count = 0;
        Manager::connection()->transaction(function () use ($ids, &$count) {
            $posts = Post::query()->whereIn('id', $ids)->lockForUpdate()->get();
            $categoryIds = [];
            $tagIds = [];
            foreach ($posts as $post) {
                $categoryIds[] = (int)$post->category_id;
            }
            foreach (Db::table(Db::POST_TAG)->whereIn('post_id', $ids)->pluck('tag_id')->all() as $tid) {
                $tagIds[] = (int)$tid;
            }

            Db::table(Db::COMMENT)->whereIn('post_id', $ids)->delete();
            Db::table(Db::LIKE)->whereIn('post_id', $ids)->delete();
            Db::table(Db::POST_TAG)->whereIn('post_id', $ids)->delete();
            $count = Post::query()->whereIn('id', $ids)->delete();

            Counter::recalcCategory($categoryIds);
            TagService::recalcCount($tagIds);
        });
        return (int)$count;
    }

    /**
     * 懒重渲染：渲染器升级后（或种子数据），首次读取时补渲染。
     */
    public static function lazyRender(Post $post): Post
    {
        $needs = (int)$post->render_version < Renderer::VERSION
            || (trim((string)$post->content_html) === '' && trim((string)$post->content_md) !== '');
        if (!$needs) {
            return $post;
        }
        try {
            $rendered = Renderer::render((string)$post->content_md);
            $post->content_html = $rendered['html'];
            $post->toc = json_encode($rendered['toc'], JSON_UNESCAPED_UNICODE);
            $post->render_version = Renderer::VERSION;
            $post->save();
        } catch (\Throwable $e) {
            Log::error('lazyRender #' . $post->id . ': ' . $e->getMessage());
        }
        return $post;
    }

    /** 阅读时长估算（分钟，CJK 按字、拉丁按词，300/分钟） */
    public static function readingMinutes(string $markdown): int
    {
        $plain = preg_replace('/```[\s\S]*?```/u', ' code ', $markdown) ?? $markdown;
        $cjk = preg_match_all('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}]/u', $plain);
        $words = str_word_count(preg_replace('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}]/u', ' ', $plain) ?? '');
        $units = (int)$cjk + (int)$words;
        return max(1, (int)ceil($units / 300));
    }
}
