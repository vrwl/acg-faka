<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller\Admin;

use App\Controller\Base\API\ManagePlugin;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use App\Plugin\Blog\Core\Markdown\Renderer;
use App\Plugin\Blog\Core\PostService;
use App\Plugin\Blog\Core\Slug;
use App\Plugin\Blog\Model\Post as PostModel;
use App\Service\Query;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

/**
 * 文章 + 独立页面 后台 API（equal-type 区分）。
 * 正文一律 base64 通道（content_md_b64 + unsafePost）：绕开 $_POST 全局清洗与 WAF 对教程内容的误杀。
 */
#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Post extends ManagePlugin
{
    #[Inject]
    private Query $query;

    public function data(): array
    {
        $map = $_POST;
        $tagId = (int)($map['tag_id'] ?? 0);
        unset($map['tag_id']);
        //类型过滤：页面表格用 URL ?type=page（随分页请求保留），默认文章
        $type = (string)($_GET['type'] ?? ($map['equal-type'] ?? 'post'));
        $map['equal-type'] = $type === 'page' ? 'page' : 'post';

        $get = new Get(PostModel::class);
        $get->setPaginate((int)$this->request->post('page'), (int)$this->request->post('limit'));
        $get->setOrderBy('id');
        $get->setColumn(
            'id', 'type', 'title', 'slug', 'category_id', 'commodity_id', 'cover', 'status', 'top',
            'allow_comment', 'weight', 'views', 'likes', 'comments_count',
            'author_name', 'publish_time', 'create_time', 'update_time'
        );
        $get->setWhere($map);

        $data = $this->query->get($get, function (Builder $builder) use ($tagId) {
            $builder->with(['category:id,name', 'tags:id,name']);
            if ($tagId > 0) {
                $builder->whereHas('tags', function ($query) use ($tagId) {
                    $query->where('blog_tag.id', $tagId);
                });
            }
            return $builder;
        });

        //派生字段：定时中（已发布但发布时间在未来）
        $now = date('Y-m-d H:i:s');
        foreach ($data['list'] as &$row) {
            $row['scheduled'] = ((int)$row['status'] === PostModel::STATUS_PUBLISHED
                && !empty($row['publish_time']) && $row['publish_time'] > $now) ? 1 : 0;
        }

        return $this->json(data: $data);
    }

    public function get(): array
    {
        $id = (int)$this->request->post('id');
        /** @var PostModel|null $post */
        $post = PostModel::query()->with(['tags:id,name'])->find($id);
        if (!$post) {
            throw new JSONException("文章不存在");
        }
        $data = $post->toArray();
        //正文用 base64 回传，避免 JSON 里的长文被中间层二次处理
        $data['content_md_b64'] = base64_encode((string)$post->content_md);
        unset($data['content_md'], $data['content_html'], $data['toc']);
        $data['tag_names'] = array_map(function ($tag) {
            return $tag['name'];
        }, $data['tags'] ?? []);
        $data['commodity'] = \App\Plugin\Blog\Core\CommodityLink::forPost($post);
        return $this->json(data: $data);
    }

    /**
     * 保存（新建/更新/自动保存共用）。
     */
    public function save(): array
    {
        $contentMd = $this->decodeContent((string)($this->request->unsafePost('content_md_b64') ?? ''));

        $tags = $this->request->post('tags', Filter::NORMAL);
        if (!is_array($tags)) {
            $tags = [];
        }

        $cover = trim((string)$this->request->post('cover', Filter::NORMAL));
        if ($cover !== '' && !$this->isValidCover($cover)) {
            throw new JSONException("封面图地址不合法");
        }

        $manage = $this->getManage();
        $autosave = (string)$this->request->post('autosave') === '1';

        $post = PostService::save([
            'id' => (int)$this->request->post('id'),
            'type' => (string)$this->request->post('type', Filter::NORMAL),
            'title' => (string)$this->request->post('title', Filter::NORMAL),
            'slug' => (string)$this->request->post('slug', Filter::NORMAL),
            'category_id' => (int)$this->request->post('category_id'),
            'commodity_id' => (int)$this->request->post('commodity_id'),
            'tags' => $tags,
            'cover' => $cover,
            'summary' => (string)$this->request->post('summary', Filter::NORMAL),
            'seo_title' => (string)$this->request->post('seo_title', Filter::NORMAL),
            'seo_keywords' => (string)$this->request->post('seo_keywords', Filter::NORMAL),
            'seo_description' => (string)$this->request->post('seo_description', Filter::NORMAL),
            'status' => (int)$this->request->post('status'),
            'top' => (int)$this->request->post('top'),
            'allow_comment' => (int)$this->request->post('allow_comment'),
            'publish_time' => (string)$this->request->post('publish_time', Filter::NORMAL),
            'weight' => (int)$this->request->post('weight'),
            'template' => (string)$this->request->post('template', Filter::NORMAL),
            'content_md' => $contentMd,
            'autosave' => $autosave,
            'author_id' => (int)$manage->id,
            'author_name' => (string)($manage->nickname ?: '管理员'),
        ]);

        if (!$autosave) {
            ManageLog::log($manage, "[次元博客]保存了《{$post->title}》(#{$post->id})");
        }

        return $this->json(200, $autosave ? null : '（＾∀＾）已保存', [
            'id' => (int)$post->id,
            'slug' => (string)$post->slug,
            'status' => (int)$post->status,
            'publish_time' => (string)$post->publish_time,
            'update_time' => (string)$post->update_time,
        ]);
    }

    /**
     * 服务端预览（Parsedown 定稿效果，与前台 100% 一致）。
     */
    public function preview(): array
    {
        $contentMd = $this->decodeContent((string)($this->request->unsafePost('content_md_b64') ?? ''));
        $rendered = Renderer::render($contentMd);
        return $this->json(data: [
            'html' => $rendered['html'],
            'toc' => $rendered['toc'],
            'reading' => PostService::readingMinutes($contentMd),
        ]);
    }

    public function setStatus(): array
    {
        $list = $this->request->post('list');
        $status = (int)$this->request->post('status');
        $count = PostService::setStatus(is_array($list) ? $list : [], $status);
        ManageLog::log($this->getManage(), "[次元博客]批量修改文章状态为 {$status}：{$count} 篇");
        return $this->json(200, '状态已更新', ['count' => $count]);
    }

    public function setTop(): array
    {
        $id = (int)$this->request->post('id');
        $top = (int)$this->request->post('top') === 1 ? 1 : 0;
        /** @var PostModel|null $post */
        $post = PostModel::query()->find($id);
        if (!$post) {
            throw new JSONException("文章不存在");
        }
        $post->top = $top;
        $post->update_time = date('Y-m-d H:i:s');
        $post->save();
        return $this->json(200, $top ? '已置顶' : '已取消置顶');
    }

    public function del(): array
    {
        $list = $this->request->post('list');
        $count = PostService::delete(is_array($list) ? $list : []);
        ManageLog::log($this->getManage(), "[次元博客]删除文章：{$count} 篇");
        return $this->json(200, '已删除', ['count' => $count]);
    }

    /**
     * 商品搜索（写作页「绑定商品」的下拉建议）。只出上架商品，按销量/排序靠前的在前。
     */
    public function commoditySearch(): array
    {
        $q = trim((string)$this->request->post('q', Filter::NORMAL));
        $query = \App\Model\Commodity::query()
            ->where('status', 1)
            ->orderByDesc('sort')
            ->orderByDesc('id')
            ->limit(20);
        if ($q !== '') {
            if (ctype_digit($q)) {
                $query->where(function ($builder) use ($q) {
                    $builder->where('id', (int)$q)->orWhere('name', 'like', '%' . $q . '%');
                });
            } else {
                $query->where('name', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%');
            }
        }

        $list = [];
        foreach ($query->get(['id', 'name', 'cover', 'price']) as $commodity) {
            $list[] = [
                'id' => (int)$commodity->id,
                'name' => (string)$commodity->name,
                'cover' => (string)$commodity->cover,
                'price' => (string)$commodity->price,
            ];
        }
        return $this->json(data: ['list' => $list]);
    }

    public function slugCheck(): array
    {
        $slug = Slug::normalize((string)$this->request->post('slug', Filter::NORMAL));
        $id = (int)$this->request->post('id');
        if ($slug === '') {
            return $this->json(data: ['available' => false, 'slug' => '', 'suggest' => '']);
        }
        $query = PostModel::query()->where('slug', $slug);
        if ($id > 0) {
            $query->where('id', '<>', $id);
        }
        $exists = $query->exists();
        return $this->json(data: [
            'available' => !$exists,
            'slug' => $slug,
            'suggest' => $exists ? Slug::unique($slug, \App\Plugin\Blog\Core\Db::POST, $id) : $slug,
        ]);
    }

    /**
     * @throws JSONException
     */
    private function decodeContent(string $b64): string
    {
        if ($b64 === '') {
            return '';
        }
        $decoded = base64_decode($b64, true);
        if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
            throw new JSONException("正文编码不正确，请刷新页面后重试");
        }
        return $decoded;
    }

    private function isValidCover(string $cover): bool
    {
        if (preg_match('#^/assets/cache/(?:general|user|\d+)/image/[A-Za-z0-9._/-]+$#i', $cover)) {
            return true;
        }
        return (bool)filter_var($cover, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $cover);
    }
}
