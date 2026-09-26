<?php
declare (strict_types=1);

namespace App\Plugin\AlipayPersonal\Controller;

use App\Controller\Base\View\UserPlugin;
use App\Plugin\AlipayPersonal\Core\Settings;
use App\Plugin\AlipayPersonal\Model\AlipayPersonal;
use App\Util\Client;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;

class Order extends UserPlugin
{
    /**
     * 收银台。模板里的字段名（config.alipay_url）保持 1.x 原样，
     * 只是取值来源换成了这张订单所属的收款配置档。
     *
     * @throws JSONException|ViewException|\ReflectionException|\SmartyException
     */
    public function pay(string $tradeNo): string
    {
        $order = AlipayPersonal::query()->where("trade_no", $tradeNo)->first();

        if (!$order) {
            Client::redirect("/", "订单不存在");
        }

        $expire = Settings::expireSeconds();
        $remain = max(0, strtotime((string)$order->create_time) + $expire - time());

        //已支付/已过期不再直接跳走，交给页面渲染成对应终态——
        //买家看到"支付成功"的凭据页，比被瞬间弹回商户更踏实。
        $state = 'pending';
        if ((int)$order->status === 1) {
            $state = 'paid';
        } elseif ($remain <= 0) {
            $state = 'expired';
        }

        $configId = (int)$order->pay_config_id;
        $profile = Settings::profile($configId);

        //模板只用到这一个键，按「配置档 → 通用插件老配置」的顺序取
        $config = [
            'alipay_url' => Settings::qrUrl($configId, $profile, 'qrcode', 'alipay_url'),
        ];

        return $this->render("请扫码支付", "Qrcode.html", [
            'order' => $order->toArray(),
            'config' => $config,
            'expire' => $expire,
            'remain' => $remain,
            'state' => $state,
        ], true);
    }
}
