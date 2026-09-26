<?php
namespace App\Plugin\ThirdDockManage\Controller\Api;

use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\Category;
use App\Model\ManageLog;
use App\Plugin\ThirdDockManage\Model\Commodity;
use App\Plugin\ThirdDockManage\Model\Goods;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Service\Query;
use App\Util\Plugin;
use Carbon\Carbon;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Site extends Manage
{
    use Help;

    #[Inject]
    private Query $query;

    /**
     * @return array
     * @throws JSONException
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(Sites::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setOrderBy(...$this->query->getOrderBy($map, "id", "desc"));
        $data = $this->query->get($get);

        $ext = $this->getExt();
        $ext_name = [];
        foreach ($ext as $k => $item) {
            $ext_name[$k] = $item['short_name'];
        }
        foreach ($data['list'] as &$datum) {
            $datum['type_name'] = $ext_name[$datum['type']] ?? '';
            $datum['remark'] = $datum['remark'] ?? '';
        }

        return $this->json(data: $data);
    }

    public function class(): array
    {
        $id = (int)$_GET['id'];
        if (filled($id)) {
            $site = Sites::query()->find($id);
            if ($site) {
                $class = "\App\Plugin\\{$site->type}\Hook\Main";
                $dock = new $class();
                $plugin_status = $dock->checkStatus();
                if ($plugin_status) {
                    $class = $dock->getClass($site->account, $site->password, $site->toArray());
                    if ($class['status_code'] == 200) {
                        return $this->json(200, 'success', [
                            'list' => $class['data'],
                            'total' => count($class['data'])
                        ]);
                    }
                }

            }
        }
        return $this->json(200, null, []);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $map = $_POST;
        if (empty($map['type'])) {
            throw new JSONException("类型不能为空");
        } else {
            $type = $map['type'];
        }
        if (!$map['name']) {
            throw new JSONException("名称不能为空");
        }
        if (!$map['domain']) {
            throw new JSONException("地址不能为空");
        }
        if (!$map['account']) {
            throw new JSONException("账号/商户ID不能为空");
        }
        if (!$map['password']) {
            throw new JSONException("密码/秘钥不能为空");
        }
        if (empty($map['remark'])) {
            $map['remark'] = null;
        }
        $map['domain'] = rtrim($map['domain'], "/");

        $class = "\App\Plugin\\{$type}\Hook\Main";
        $dock = new $class();
        $plugin_status = $dock->checkStatus();
        $error_msg = '';
        if ($plugin_status) {
            $balance_query = $dock->getBalance($map['account'], $map['password'], $map);
            if ($balance_query['status_code'] == 200) {
                $map['status'] = 1;
                $map['balance'] = $balance_query['data']['balance'];
                if (isset($balance_query['data']['remark']) && filled($balance_query['data']['remark'])) {
                    $map['remark'] = $map['remark'] . $balance_query['data']['remark'];
                }
            } else {
                $map['status'] = 0;
                $map['balance'] = 0;
                $error_msg = $balance_query['message'] ?? '';
            }
        } else {
            $map['status'] = 0;
            $map['balance'] = 0;
        }

        $save = new Save(Sites::class);
        $save->setMap($map);
        $state = $this->query->save($save);
        if (!$state) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        if (filled($error_msg)) {
//            throw new JSONException($error_msg);
            return $this->json(200, '新增站点成功，但连接失败：'.$error_msg);
        } else {
            ManageLog::log($this->getManage(), "[修改/新增]第三方对接站点");
            return $this->json(200, '（＾∀＾）保存成功');
        }
    }

    public function addClass()
    {
        $site_id = (int)$_POST['site_id'];
        $class_ids = $_POST['class_ids'];
        if (filled($site_id) && count($class_ids) > 0) {
            $site = Sites::query()->find($site_id);
            if ($site) {
                $class_data = Plugin::getCache($site->type, 'class_data', $site->domain);
                if ($class_data) {
                    $class_array = [];
                    foreach ($class_data as $class_datum) {
                        $class_array[$class_datum['id']] = $class_datum;
                    }
                    $exist_names = Category::query()->pluck('name')->toArray();
                    $error_num = $success_num = 0;
                    $insert_data = [];
                    foreach ($class_ids as $class_id) {
                        if (isset($class_array[$class_id])) {
                            $temp = $class_array[$class_id];
                            if (in_array($temp['name'], $exist_names)) {
                                $error_num++;
                                continue;
                            }
                            if (strlen($temp['img_url']) >= 255) {
                                $temp['img_url'] = '';
                            }
                            //todo:图标传图床
                            $insert_data[] = [
                                'name' => $temp['name'],
                                'icon' => $temp['img_url'],
                                'sort' => 1,
                                'status' => 1,
                                'hide' => 0,
                                'create_time' => Carbon::now()->toDateTimeString()
                            ];
                            $success_num++;
                        } else {
                            $error_num++;
                        }
                    }
                    Category::query()->insert($insert_data);
                    return $this->json(200, '（＾∀＾）新增成功'.$success_num.'个;重复或失败'.$error_num.'个');
                }
            }
        }
        throw new JSONException('失败，系统异常');
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function connect(): array
    {
        $id = (int)$_POST['id'];
        $site = Sites::query()->find($id);

        if (!$site) {
            throw new JSONException("未找到该站点");
        }

        $class = "\App\Plugin\\{$site->type}\Hook\Main";
        $dock = new $class();
        $plugin_status = $dock->checkStatus();
        if ($plugin_status) {
            $balance_query = $dock->getBalance($site->account, $site->password, $site->toArray());
            if ($balance_query['status_code'] == 200) {
                $site->balance = (float)$balance_query['data']['balance'];
                $site->status = 1;
                $site->save();
                return $this->json(200, 'success');
            } else {
                return $this->json(500, '连接失败');
            }
        } else {
            return $this->json(500, '插件关闭');
        }
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $delete = new Delete(Sites::class, $_POST['list']);
        $count = $this->query->delete($delete);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        $site_ids = $_POST['list'];
        $good_id_query = Goods::query()->whereIn('site_id', $site_ids)->select(['id']);
//        $good_ids = Goods::query()->whereIn('site_id', $site_ids)->pluck('id')->toArray();
        Commodity::query()->whereIn('dock_g_id', $good_id_query)->update(['dock_g_id' => 0, 'status' => 0]);
        Goods::query()->whereIn('site_id', $site_ids)->delete();

        ManageLog::log($this->getManage(), "[第三方对接管理]删除操作，共计：" . count($_POST['list']));
        return $this->json(200, '（＾∀＾）移除成功');
    }
}
