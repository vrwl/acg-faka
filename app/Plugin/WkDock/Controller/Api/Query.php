<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Controller\Api;

use App\Controller\Base\API\UserPlugin;
use App\Interceptor\Waf;
use App\Model\Commodity;
use App\Model\Order as OrderModel;
use App\Plugin\WkDock\Model\Goods;
use App\Plugin\WkDock\Model\Sites;
use App\Plugin\WkDock\Traits\Help;
use App\Util\Client;
use App\Util\Throttle;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

/**
 * 前台API：网课查询
 */
#[Interceptor(Waf::class, Interceptor::TYPE_API)]
class Query extends UserPlugin
{
    use Help;

    /**
     * 判断商品是否为网课商品
     * @return array
     */
    public function check(): array
    {
        $commodityId = (int)$this->request->post("commodity_id");
        $isOc = 0;
        if ($commodityId > 0) {
            $commodity = Commodity::query()->find($commodityId, ['id', 'wk_g_id']);
            if ($commodity && (int)$commodity->wk_g_id > 0) {
                $good = Goods::query()->find((int)$commodity->wk_g_id, ['id', 'site_id', 'status']);
                if ($good && $good->status == 1) {
                    $isOc = 1;
                }
            }
        }
        return $this->json(200, 'success', ['is_oc' => $isOc]);
    }

    /**
     * 批量标注哪些订单属于已付款的网课订单
     * 查单接口（/user/api/index/query）的出参不含对接字段，前端无法自行判断，
     * 故用本接口按订单号批量询问，只回传命中的单号，前端据此决定是否显示「查询进度」入口
     * @return array
     * @throws JSONException
     */
    public function mark(): array
    {
        $tradeNos = array_values(array_unique(array_filter(array_map(
            'trim',
            explode(',', (string)$this->request->post('trade_no'))
        ))));
        if (count($tradeNos) === 0) {
            return $this->json(200, 'success', ['list' => []]);
        }

        //免登录接口，限流防止按订单号批量探测
        if (Throttle::tooMany('wkmark:ip:' . Client::getAddress(), 60, 600)) {
            throw new JSONException('请求过于频繁，请稍后再试');
        }

        //判定口径与会员中心订单表一致：已付款 + 对接状态大于 0（0 表示无需对接）
        $list = OrderModel::query()
            ->whereIn('trade_no', array_slice($tradeNos, 0, 50))
            ->where('status', 1)
            ->where('wk_status', '>', 0)
            ->pluck('trade_no')
            ->unique()
            ->values()
            ->all();

        return $this->json(200, 'success', ['list' => $list]);
    }

    /**
     * 查询学生可选课程
     * @return array
     * @throws JSONException
     */
    public function courses(): array
    {
        $commodityId = (int)$this->request->post("commodity_id");
        $school = trim((string)$this->request->post("school"));
        $user = trim((string)$this->request->post("user"));
        $pass = trim((string)$this->request->post("pass"));

        if ($commodityId <= 0) {
            throw new JSONException("参数错误");
        }
        if ($school === '' || $user === '' || $pass === '') {
            throw new JSONException("请填写学校全称、学生账号和密码");
        }

        $commodity = Commodity::query()->find($commodityId, ['id', 'wk_g_id']);
        if (!$commodity || (int)$commodity->wk_g_id <= 0) {
            throw new JSONException("该商品不是网课商品");
        }
        $good = Goods::query()->find((int)$commodity->wk_g_id);
        if (!$good) {
            throw new JSONException("网课商品数据异常");
        }
        $site = Sites::query()->where('status', 1)->find($good->site_id);
        if (!$site) {
            throw new JSONException("网课站点不可用，请联系站长");
        }

        //免登录接口：每次调用都会用站长的 uid+key 打一次上游，必须限流，
        //否则会被当成「学生账号密码爆破器」使用，被上游风控封的是站长的对接账号
        if (Throttle::tooMany('wkcourse:ip:' . Client::getAddress(), 20, 600)) {
            throw new JSONException('请求过于频繁，请稍后再试');
        }

        $res = $this->queryCourses($site, (string)$good->cid, $school, $user, $pass);
        if ($res['code'] !== 1) {
            throw new JSONException($res['msg'] ?: '查询课程失败，请检查信息');
        }

        return $this->json(200, 'success', ['list' => $res['data']]);
    }
}
