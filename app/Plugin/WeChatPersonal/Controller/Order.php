<?php
declare (strict_types=1);

namespace App\Plugin\WeChatPersonal\Controller;

use App\Controller\Base\View\UserPlugin;
use App\Plugin\WeChatPersonal\Core\Settings;
use App\Plugin\WeChatPersonal\Model\WeChatPersonal;
use App\Util\Client;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;

class Order extends UserPlugin
{
    /**
     * 收银台。模板里的字段名（config.wx_url / config.phone …）保持 1.x 原样，
     * 只是取值来源换成了这张订单所属的收款配置档。
     *
     * @throws JSONException|ViewException|\ReflectionException|\SmartyException
     */
    public function pay(string $tradeNo): string
    {
        $order = WeChatPersonal::query()->where("trade_no", $tradeNo)->first();

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

        $template = match ($order->type) {
            "reward" => "Reward.html",
            "qrcode" => "Qrcode.html",
            "phone" => "Phone.html",
            default => "Qrcode.html"
        };

        $configId = (int)$order->pay_config_id;
        $profile = Settings::profile($configId);

        //模板要的就这几个键，逐个按「配置档 → 通用插件老配置」的顺序取
        $config = [
            'wx_url' => Settings::qrUrl($configId, $profile, 'qrcode', 'wx_url'),
            'reward_url' => Settings::field($profile, 'reward_url'),
            'phone' => Settings::field($profile, 'phone'),
            'real_name' => Settings::field($profile, 'real_name'),
        ];

        return $this->render("请扫码支付", $template, [
            'order' => $order->toArray(),
            'config' => $config,
            'expire' => $expire,
            'remain' => $remain,
            'state' => $state,
        ], true);
    }
}
