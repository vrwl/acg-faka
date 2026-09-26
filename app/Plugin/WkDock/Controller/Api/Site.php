<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Controller\Api;

use App\Controller\Base\API\Manage;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\Commodity;
use App\Model\ManageLog;
use App\Plugin\WkDock\Model\Goods;
use App\Plugin\WkDock\Model\Sites;
use App\Plugin\WkDock\Traits\Help;
use App\Service\Query;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

/**
 * 后台API：网课对接站点
 */
#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Site extends Manage
{
    use Help;

    #[Inject]
    private Query $query;

    /**
     * 站点列表
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

        foreach ($data['list'] as &$datum) {
            $datum['remark'] = $datum['remark'] ?? '';
        }

        return $this->json(data: $data);
    }

    /**
     * 新增/修改站点
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $map = $_POST;
        if (empty($map['name'])) {
            throw new JSONException("名称不能为空");
        }
        if (empty($map['domain'])) {
            throw new JSONException("站点地址不能为空");
        }
        if (empty($map['account'])) {
            throw new JSONException("账号(uid)不能为空");
        }
        if (empty($map['password'])) {
            throw new JSONException("密钥(key)不能为空");
        }
        $map['domain'] = rtrim(trim($map['domain']), "/");
        if (!preg_match('/^https?:\/\//i', $map['domain'])) {
            throw new JSONException("站点地址必须以 http:// 或 https:// 开头");
        }

        //费率
        $rate = (float)($map['rate'] ?? 1);
        if ($rate <= 0) {
            $rate = 1;
        }
        $map['rate'] = $rate;

        //连接测试：必须连通才允许保存（getmoney 校验站点地址、账号与密钥）
        $probe = new Sites($map);
        $balance_query = $this->getBalance($probe);
        if ($balance_query['code'] != 200) {
            throw new JSONException("连接失败");
        }
        $map['status'] = 1;
        $map['balance'] = $balance_query['data']['balance'];

        $save = new Save(Sites::class);
        $save->setMap($map);
        $state = $this->query->save($save);
        if (!$state) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), "[网课对接][修改/新增]站点：{$map['name']}");
        return $this->json(200, '保存成功，连接正常');
    }

    /**
     * 测试站点连接并刷新余额
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

        $balance_query = $this->getBalance($site);
        if ($balance_query['code'] == 200) {
            $site->balance = (float)$balance_query['data']['balance'];
            $site->status = 1;
            $site->save();
            return $this->json(200, 'success', ['balance' => $site->balance]);
        }
        //连接失败时同步置为禁用：商品同步与支付交单都以 status=1 为准，
        //否则会出现「页面显示异常、实际仍在参与同步/交单」的不一致
        if ((int)$site->status !== 0) {
            $site->status = 0;
            $site->save();
        }
        return $this->json(500, '连接失败');
    }

    /**
     * 删除站点（软删除）并清理商品关联
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

        $siteIds = $_POST['list'];
        $goodIds = Goods::query()->whereIn('site_id', $siteIds)->pluck('id')->toArray();
        if (count($goodIds) > 0) {
            Commodity::query()->whereIn('wk_g_id', $goodIds)->update(['wk_g_id' => 0]);
        }

        ManageLog::log($this->getManage(), "[网课对接][删除]站点，共计：" . count($siteIds));
        return $this->json(200, '移除成功');
    }
}
