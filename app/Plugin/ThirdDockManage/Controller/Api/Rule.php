<?php

namespace App\Plugin\ThirdDockManage\Controller\Api;

use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\Category;
use App\Plugin\ThirdDockManage\Model\Rules;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Service\Query;
use App\Util\Plugin;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Rule extends Manage
{
    use Help;

    #[Inject]
    private Query $query;

    public function save()
    {
        $data = $this->request->post(flags: Filter::NORMAL);
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $info = Rules::query()->find($id, ['id']);
            if (!$info) {
                throw new JSONException("数据异常，请刷新重试");
            }
        }

        $conditions = 0;
        //site_ids
        $site_ids = $data['site_ids'] ?? '';
        if (filled($site_ids) && is_string($site_ids)) {
            $site_id_array = explode(',', $site_ids);
            $site_num = Sites::query()->whereIn('id', $site_id_array)->count();
            if (count($site_id_array) != $site_num) {
                throw new JSONException("站点数据异常，请刷新重试");
            }
            $conditions++;
        } else {
            $site_ids = '';
        }
        //categories
        $categories = $data['categories'] ?? '';
        if (filled($categories) && is_string($categories)) {
            $category_array = explode(',', $categories);
            $category_num = Category::query()->whereIn('name', $category_array)->count();
            if (count($category_array) != $category_num) {
                throw new JSONException("分类数据异常，请刷新重试");
            }
            $categories = implode('@#@', $category_array);
        } else {
            $categories = '';
        }
        //categories_ext
        $categories_ext = $data['categories_ext'] ?? '';
        if (filled($categories_ext)) {
            $categories_ext_array = preg_split('/[\r\n]+/s', trim($categories_ext));
            if (filled($categories)) {
                $categories = $categories .'@#@'.implode('@#@', $categories_ext_array);
            } else {
                $categories = implode('@#@', $categories_ext_array);
            }
        }
        if (filled($categories)) {
            $conditions++;
        }
        //good_names
        $good_names = $data['good_names'] ?? '';
        if (filled($good_names)) {
            $good_names_array = preg_split('/[\r\n]+/s', trim($good_names));
            $good_names = implode('@#@', $good_names_array);
            $conditions++;
        } else {
            $good_names = '';
        }
        if ($conditions <= 0) {
            throw new JSONException("条件需至少填写一种");
        }
        $status = (int) ($data['status'] ?? 0);
        $this->switchCheck($status, '规则状态');
        $auto_class = (int) ($data['auto_class'] ?? 0);
        $this->switchCheck($auto_class, '自动对应分类');
        $sort = (int) ($data['sort'] ?? 0);

        $settings = [];
        $settings['cover'] = (int) ($data['settings_cover'] ?? 0);
        $settings['exclude_good_names'] = trim($data['settings_exclude_good_names'] ?? '');
        $settings['category_id'] = (int) ($data['settings_category_id'] ?? 0);
        if (!empty($settings['category_id'])) {
            $c_info = Category::query()->find($settings['category_id'], ['id']);
            if (!$c_info) {
                throw new JSONException("指定分类数据异常，请刷新重试");
            }
        } elseif ($auto_class == 0) {
            throw new JSONException("未开启自动对应分类，指定分类不能为空");
        }
        $settings['mode'] = (int) ($data['settings_mode'] ?? 0);
        $this->switchCheck($settings['mode'], '加价模式', [0, 1, 2]);
        $settings['mode_value'] = $data['settings_mode_value'] ?? '';
        if (blank($settings['mode_value'])) {
            throw new JSONException("加价数量不能为空");
        } elseif ($settings['mode'] == 2) {
            $settings['mode_value'] = $this->changeStr($settings['mode_value']);
        }
        $settings['lucky_decimal'] = (float) ($data['settings_lucky_decimal'] ?? 0);
        $settings['sync_now'] = (int) ($data['settings_sync_now'] ?? 0);
        $this->switchCheck($settings['sync_now'], '实时同步');
        $settings['sync_price'] = (int) ($data['settings_sync_price'] ?? 0);
        $this->switchCheck($settings['sync_price'], '同步价格');
        $settings['sync_content'] = (int) ($data['settings_sync_content'] ?? 0);
        $this->switchCheck($settings['sync_content'], '同步详情及参数');
        $settings['sync_title'] = (int) ($data['settings_sync_title'] ?? 0);
        $this->switchCheck($settings['sync_title'], '同步标题及封面图');
        $settings['only_user'] = (int) ($data['settings_only_user'] ?? 0);
        $this->switchCheck($settings['only_user'], '强制登录');
        $settings['use_upload'] = (int) ($data['settings_use_upload'] ?? 2);
        $this->switchCheck($settings['use_upload'], '使用图床', [0, 1, 2, 3]);
        $settings['content_replace'] = $data['settings_content_replace'] ?? '';
        $settings['show_stock'] = (int) ($data['settings_show_stock'] ?? 0);
        $this->switchCheck($settings['show_stock'], '展示库存', [0, 1, 2]);
        $settings['api_status'] = (int) ($data['settings_api_status'] ?? 0);
        $this->switchCheck($settings['api_status'], 'API对接');

        DB::beginTransaction();
        try {
            $param = [
                'site_ids' => $site_ids,
                'categories' => $categories,
                'good_names' => $good_names,
                'status' => $status,
                'auto_class' => $auto_class,
                'sort' => $sort,
                'settings' => serialize($settings)
            ];
            if ($id > 0) {
                Rules::query()->where('id', $id)->update($param);
            } else {
                Rules::query()->create($param);
            }

            DB::commit();
            return $this->json(200, '（＾∀＾）操作成功');
        } catch (\Exception $e) {
            Plugin::log('ThirdDockManage', $e->getMessage());
            DB::rollBack();
            throw new JSONException("操作失败，请查看插件日志");
        }
    }

    public function exchange()
    {
        $ids = $_POST['ids'] ?? [];
        if (blank($ids)) {
            throw new JSONException("请勾选至少一个规则");
        } else {
            $info = Rules::query()->whereIn('id', $ids)->get(['id', 'status']);
            if (count($info) != count($ids)) {
                throw new JSONException("数据异常，请刷新页面重试！");
            }
            foreach ($info as $item) {
                if ($item->status == 0) {
                    $item->status = 1;
                } else {
                    $item->status = 0;
                }
                $item->save();
            }
            return $this->json(200, '（＾∀＾）操作成功');
        }
    }

    public function del()
    {
        $ids = $_POST['ids'] ?? [];
        if (blank($ids)) {
            throw new JSONException("请勾选至少一个规则");
        } else {
            Rules::query()->whereIn('id', $ids)->delete();
            return $this->json(200, '（＾∀＾）操作成功');
        }
    }

    public function data(): array
    {
        $map = $_POST;
        $get = new Get(Rules::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setOrderBy(...$this->query->getOrderBy($map, "sort", "asc"));
        $data = $this->query->get($get);

        $sites = Sites::query()->pluck('name', 'id')->toArray();
        $categories = Category::query()->pluck('name')->toArray();
        foreach ($data['list'] as &$datum) {
            $site_ids = $datum['site_ids'];
            $site_string = $new_site_ids = [];
            if (filled($site_ids)) {
                $site_array = explode(',', $site_ids);
                foreach ($site_array as $item) {
                    if (isset($sites[$item])) {
                        $site_string[] = $sites[$item];
                        $new_site_ids[] = $item;
                    }
                }
            }
            $datum['site_ids'] = $new_site_ids;
            $datum['site_ids_string'] = implode(',', $site_string);

            $category = $datum['categories'];
            $category_string = $c_categories = $c_categories_ext = [];
            if (filled($category)) {
                $category_array = explode('@#@', $category);
                foreach ($category_array as $item) {
                    $category_string[] = $item;
                    if (in_array($item, $categories)) {
                        $c_categories[] = $item;
                    } else {
                        $c_categories_ext[] = $item;
                    }
                }
            }
            $datum['categories_string'] = implode(',', $category_string);
            $datum['categories'] = $c_categories;
            $datum['categories_ext'] = implode(PHP_EOL, $c_categories_ext);

            $good_names = $datum['good_names'];
            $good_names_string = $c_good_names = [];
            if (filled($good_names)) {
                $good_names_string = $c_good_names = explode('@#@', $good_names);
            }
            $datum['good_names'] = implode(PHP_EOL, $c_good_names);
            $datum['good_names_string'] = implode(',', $good_names_string);

            $settings = $datum['settings'];
            if (filled($settings)) {
                $setting_data = unserialize($settings);
                if ($setting_data === false) {
                    $setting_data = [];
                }
                foreach ($setting_data as $k => $setting_datum) {
                   $datum['settings_'.$k] = $setting_datum;
                }
            }
            unset($datum['settings']);
            $datum['c_site_ids'] = $datum['site_ids'];
            unset($datum['site_ids']);
            $datum['c_categories'] = $datum['categories'];
            unset($datum['categories']);
            if (isset($datum['settings_mode_value'])) {
                $datum['settings_mode_value'] = $this->changeStr($datum['settings_mode_value']);
            }
        }

        return $this->json(data: $data);
    }

    /**
     * @param $data
     * @param $name
     * @param int[] $range
     * @throws JSONException
     */
    private function switchCheck($data, $name, array $range = [0, 1])
    {
        if (!in_array($data, $range)) {
            throw new JSONException($name."异常");
        }
    }
}

