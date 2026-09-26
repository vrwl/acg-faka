<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Controller\Api;

use App\Controller\Base\API\UserPlugin;
use App\Interceptor\UserVisitor;
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
 * 前台API：网课订单进度查询与操作（查进度/补刷/暂停/改密）
 */
#[Interceptor([Waf::class, UserVisitor::class], Interceptor::TYPE_API)]
class Order extends UserPlugin
{
    use Help;

    /**
     * 允许的前台操作：补刷 / 暂停 / 改密
     */
    private const ACTIONS = ['budan', 'zt', 'xgmm'];

    /**
     * 查询账号在上游平台的课程进度（返回全部记录，不按订单过滤）
     * @return array
     * @throws JSONException
     */
    public function list(): array
    {
        $order = $this->loadOrder();
        $site = $this->resolveSite($order);
        $account = $this->getAccount($order);

        $res = $this->queryOrders($site, $account);
        if ($res['code'] !== 1) {
            throw new JSONException('查询失败，请稍后再试');
        }

        $user = $this->getUser();
        return $this->json(200, 'success', [
            'account' => $account,
            'is_owner' => (bool)($user && (int)$user->id === (int)$order->owner),
            'list' => $res['data'],
        ]);
    }

    /**
     * 执行补刷 / 暂停 / 改密
     * @return array
     * @throws JSONException
     */
    public function action(): array
    {
        $act = trim((string)$this->request->post('act'));
        $yid = trim((string)$this->request->post('yid'));
        if (!in_array($act, self::ACTIONS, true) || $yid === '') {
            throw new JSONException('参数错误');
        }

        $order = $this->loadOrder();
        $site = $this->resolveSite($order);
        $account = $this->getAccount($order);

        //校验记录确实属于该账号，避免伪造 yid 操作他人订单
        $query = $this->queryOrders($site, $account);
        if ($query['code'] !== 1) {
            throw new JSONException('查询失败，请稍后再试');
        }
        $exists = false;
        foreach ($query['data'] as $item) {
            if ($item['yid'] === $yid) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            throw new JSONException('记录不存在，请刷新后重试');
        }

        $newPassword = '';
        if ($act === 'xgmm') {
            $newPassword = trim((string)$this->request->post('new_password'));
            if ($newPassword === '') {
                throw new JSONException('请输入新密码');
            }
            if (mb_strlen($newPassword) > 64) {
                throw new JSONException('新密码过长');
            }
            $result = $this->changePassword($site, $yid, $newPassword);
        } elseif ($act === 'budan') {
            $result = $this->supplement($site, $yid);
        } else {
            $result = $this->pauseOrder($site, $yid);
        }

        //日志不落明文新密码
        $params = ['id' => $yid];
        if ($act === 'xgmm') {
            $params['xgmm'] = '******';
        }
        $this->writeLog((int)$order->id, (int)$site->id, $act, $params, ['result' => $result], "订单{$order->trade_no}前台{$act}");

        if ($result['code'] !== 1) {
            throw new JSONException('操作失败，请稍后再试');
        }

        $message = '操作成功';
        if ($act === 'xgmm') {
            //改密成功后回写订单中的学生密码，后续查进度/交单使用新密码
            $widget = json_decode((string)$order->widget, true) ?: [];
            if (isset($widget['pass'])) {
                $widget['pass']['value'] = $newPassword;
            } else {
                $widget['pass'] = ['value' => $newPassword, 'cn' => '密码'];
            }
            $order->widget = json_encode($widget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $order->save();
            $message = '密码修改成功';
        }

        return $this->json(200, 'success', ['message' => $message]);
    }

    /**
     * 取订单并校验查看权限：本人订单直接放行，否则需提供查单密码（与卡密查询同一套）
     * @return OrderModel
     * @throws JSONException
     */
    private function loadOrder(): OrderModel
    {
        $tradeNo = trim((string)$this->request->post('trade_no'));
        if ($tradeNo === '') {
            throw new JSONException('参数错误');
        }

        $ip = Client::getAddress();
        if (Throttle::tooMany("wkorder:ip:{$ip}", 60, 600)
            || Throttle::tooMany("wkorder:no:{$tradeNo}:{$ip}", 20, 600)) {
            throw new JSONException('请求过于频繁，请稍后再试');
        }

        $order = OrderModel::query()->where('trade_no', $tradeNo)->first();
        if (!$order) {
            throw new JSONException('未查询到相关信息');
        }

        $user = $this->getUser();
        $isOwner = $user && (int)$user->id === (int)$order->owner;
        //非本人订单：查单密码为空表示无需密码（与卡密查询同一口径），否则必须匹配
        if (!$isOwner && (string)$order->password !== ''
            && !hash_equals((string)$order->password, (string)$this->request->post('password'))) {
            throw new JSONException('密码错误');
        }

        if ($order->status != 1) {
            throw new JSONException('订单还未支付');
        }

        return $order;
    }

    /**
     * 由订单的对接商品反推可用站点（不接受前端传入站点ID）
     * @param OrderModel $order
     * @return Sites
     * @throws JSONException
     */
    private function resolveSite(OrderModel $order): Sites
    {
        $commodity = Commodity::query()->find((int)$order->commodity_id, ['id', 'wk_g_id']);
        $gId = (int)($commodity->wk_g_id ?? 0);
        if ($gId <= 0) {
            throw new JSONException('该订单不是网课订单');
        }
        $good = Goods::query()->find($gId);
        if (!$good) {
            throw new JSONException('网课商品数据异常');
        }
        $site = Sites::query()->find((int)$good->site_id);
        if (!$site || $site->status != 1) {
            throw new JSONException('网课站点暂不可用，请联系站长');
        }
        return $site;
    }

    /**
     * 取订单中的学生账号
     * @param OrderModel $order
     * @return string
     * @throws JSONException
     */
    private function getAccount(OrderModel $order): string
    {
        $widget = json_decode((string)$order->widget, true) ?: [];
        $account = trim((string)($widget['user']['value'] ?? ''));
        if ($account === '') {
            throw new JSONException('订单缺少学生账号信息');
        }
        return $account;
    }
}
