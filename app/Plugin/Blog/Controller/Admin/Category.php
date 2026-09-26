<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Controller\Admin;

use App\Controller\Base\API\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\ManageLog;
use App\Plugin\Blog\Core\Db;
use App\Plugin\Blog\Core\Slug;
use App\Plugin\Blog\Model\Category as CategoryModel;
use App\Plugin\Blog\Model\Post as PostModel;
use Illuminate\Database\Capsule\Manager;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

/**
 * 层级分类（邻接表 + 内存建树，量级小，一次全量返回树序展平列表）。
 */
#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Category extends ManagePlugin
{
    public function data(): array
    {
        $rows = CategoryModel::query()
            ->orderBy('weight')
            ->orderBy('id')
            ->get()
            ->toArray();

        $list = $this->flatten($this->buildTree($rows));
        return $this->json(data: ['list' => $list, 'total' => count($list)]);
    }

    public function save(): array
    {
        $id = (int)$this->request->post('id');
        $name = trim((string)$this->request->post('name', Filter::NORMAL));
        if ($name === '' || mb_strlen($name) > 64) {
            throw new JSONException("分类名称不能为空且不超过 64 字");
        }
        $parentId = max(0, (int)$this->request->post('parent_id'));

        /** @var CategoryModel|null $category */
        $category = $id > 0 ? CategoryModel::query()->find($id) : new CategoryModel();
        if ($id > 0 && !$category) {
            throw new JSONException("分类不存在");
        }

        if ($parentId > 0) {
            if ($parentId === $id) {
                throw new JSONException("父分类不能是自己");
            }
            $parent = CategoryModel::query()->find($parentId);
            if (!$parent) {
                throw new JSONException("父分类不存在");
            }
            //防环：父分类不能在自己的子树里
            if ($id > 0 && in_array($parentId, $this->descendantIds($id), true)) {
                throw new JSONException("不能把分类挂到自己的子分类下");
            }
            //层级限制（含新节点共 3 级）
            if ($this->depthOf($parentId) >= CategoryModel::MAX_DEPTH) {
                throw new JSONException("分类最多 " . CategoryModel::MAX_DEPTH . " 级");
            }
        }

        $slugInput = trim((string)$this->request->post('slug', Filter::NORMAL));
        if ($slugInput !== '') {
            $category->slug = Slug::unique($slugInput, Db::CATEGORY, $id);
        } elseif ($id === 0 || (string)$category->slug === '') {
            $category->slug = Slug::unique(Slug::fromTitle($name), Db::CATEGORY, $id);
        }

        $category->name = $name;
        $category->parent_id = $parentId;
        $category->description = mb_substr(trim((string)$this->request->post('description', Filter::NORMAL)), 0, 255);
        $category->cover = mb_substr(trim((string)$this->request->post('cover', Filter::NORMAL)), 0, 255);
        $category->weight = max(0, (int)$this->request->post('weight'));
        if ($id === 0) {
            $category->post_count = 0;
            $category->create_time = Db::now();
        }
        $category->save();

        ManageLog::log($this->getManage(), "[次元博客]保存分类「{$name}」(#{$category->id})");
        return $this->json(200, '（＾∀＾）保存成功', ['id' => (int)$category->id]);
    }

    public function del(): array
    {
        $id = (int)$this->request->post('id');
        /** @var CategoryModel|null $category */
        $category = CategoryModel::query()->find($id);
        if (!$category) {
            throw new JSONException("分类不存在");
        }
        if (CategoryModel::query()->where('parent_id', $id)->exists()) {
            throw new JSONException("该分类下还有子分类，请先处理子分类");
        }

        $postCount = 0;
        Manager::connection()->transaction(function () use ($id, $category, &$postCount) {
            $postCount = PostModel::query()->where('category_id', $id)->update(['category_id' => 0]);
            $category->delete();
        });

        ManageLog::log($this->getManage(), "[次元博客]删除分类「{$category->name}」，{$postCount} 篇文章归入未分类");
        return $this->json(200, '已删除', ['posts_moved' => $postCount]);
    }

    /* ---------------- 树工具 ---------------- */

    private function buildTree(array $rows, int $parentId = 0): array
    {
        $tree = [];
        foreach ($rows as $row) {
            if ((int)$row['parent_id'] === $parentId) {
                $row['children'] = $this->buildTree($rows, (int)$row['id']);
                $tree[] = $row;
            }
        }
        return $tree;
    }

    private function flatten(array $tree, int $depth = 0): array
    {
        $list = [];
        foreach ($tree as $node) {
            $children = $node['children'];
            unset($node['children']);
            $node['depth'] = $depth;
            $node['has_children'] = count($children) > 0 ? 1 : 0;
            $list[] = $node;
            foreach ($this->flatten($children, $depth + 1) as $child) {
                $list[] = $child;
            }
        }
        return $list;
    }

    private function descendantIds(int $id): array
    {
        $all = CategoryModel::query()->get(['id', 'parent_id'])->toArray();
        $ids = [];
        $stack = [$id];
        while ($stack) {
            $current = array_pop($stack);
            foreach ($all as $row) {
                if ((int)$row['parent_id'] === $current) {
                    $ids[] = (int)$row['id'];
                    $stack[] = (int)$row['id'];
                }
            }
        }
        return $ids;
    }

    private function depthOf(int $id): int
    {
        $depth = 0;
        $guard = 0;
        while ($id > 0 && $guard < 10) {
            $row = CategoryModel::query()->find($id, ['id', 'parent_id']);
            if (!$row) {
                break;
            }
            $depth++;
            $id = (int)$row->parent_id;
            $guard++;
        }
        return $depth;
    }
}
