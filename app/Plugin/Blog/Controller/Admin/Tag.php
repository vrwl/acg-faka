<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller\Admin;

use App\Controller\Base\API\ManagePlugin;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use App\Plugin\Blog\Core\Db;
use App\Plugin\Blog\Core\Slug;
use App\Plugin\Blog\Model\Tag as TagModel;
use App\Service\Query;
use Illuminate\Database\Capsule\Manager;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Tag extends ManagePlugin
{
    #[Inject]
    private Query $query;

    public function data(): array
    {
        $get = new Get(TagModel::class);
        $get->setPaginate((int)$this->request->post('page'), (int)$this->request->post('limit'));
        $get->setOrderBy('post_count');
        $get->setWhere($_POST);
        $data = $this->query->get($get);
        return $this->json(data: $data);
    }

    public function save(): array
    {
        $id = (int)$this->request->post('id');
        $name = trim((string)$this->request->post('name', Filter::NORMAL));
        if ($name === '' || mb_strlen($name) > 64) {
            throw new JSONException("标签名称不能为空且不超过 64 字");
        }

        $exists = TagModel::query()->where('name', $name);
        if ($id > 0) {
            $exists->where('id', '<>', $id);
        }
        if ($exists->exists()) {
            throw new JSONException("已存在同名标签");
        }

        /** @var TagModel|null $tag */
        $tag = $id > 0 ? TagModel::query()->find($id) : new TagModel();
        if ($id > 0 && !$tag) {
            throw new JSONException("标签不存在");
        }

        $slugInput = trim((string)$this->request->post('slug', Filter::NORMAL));
        if ($slugInput !== '') {
            $tag->slug = Slug::unique($slugInput, Db::TAG, $id);
        } elseif ($id === 0 || (string)$tag->slug === '') {
            $tag->slug = Slug::unique(Slug::fromTitle($name), Db::TAG, $id);
        }

        $tag->name = $name;
        if ($id === 0) {
            $tag->post_count = 0;
            $tag->create_time = Db::now();
        }
        $tag->save();

        ManageLog::log($this->getManage(), "[次元博客]保存标签「{$name}」(#{$tag->id})");
        return $this->json(200, '（＾∀＾）保存成功', ['id' => (int)$tag->id]);
    }

    public function del(): array
    {
        $list = $this->request->post('list');
        $ids = array_values(array_filter(array_map('intval', is_array($list) ? $list : [])));
        if (!$ids) {
            throw new JSONException("请选择要删除的标签");
        }
        $count = 0;
        Manager::connection()->transaction(function () use ($ids, &$count) {
            Db::table(Db::POST_TAG)->whereIn('tag_id', $ids)->delete();
            $count = TagModel::query()->whereIn('id', $ids)->delete();
        });
        ManageLog::log($this->getManage(), "[次元博客]删除标签：{$count} 个");
        return $this->json(200, '已删除', ['count' => (int)$count]);
    }

    /**
     * select2 远程建议（写文章页标签输入）。
     */
    public function suggest(): array
    {
        $q = trim((string)$this->request->post('q', Filter::NORMAL));
        $query = TagModel::query()->orderByDesc('post_count')->limit(20);
        if ($q !== '') {
            $query->where('name', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%');
        }
        $items = [];
        foreach ($query->get(['id', 'name', 'post_count']) as $tag) {
            $items[] = ['id' => $tag->name, 'text' => $tag->name, 'count' => (int)$tag->post_count];
        }
        return $this->json(data: ['list' => $items]);
    }
}
