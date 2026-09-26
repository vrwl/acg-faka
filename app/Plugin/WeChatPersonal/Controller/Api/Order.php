<?php
declare (strict_types=1);

namespace App\Plugin\WeChatPersonal\Controller\Api;

use App\Controller\Base\API\UserPlugin;
use App\Plugin\WeChatPersonal\Core\Settings;
use App\Plugin\WeChatPersonal\Model\WeChatPersonal;
use Kernel\Container\Di;
use Kernel\Exception\JSONException;

class Order extends UserPlugin
{
    /**
     * @param string $tradeNo
     * @return array
     * @throws JSONException
     */
    public function state(string $tradeNo): array
    {
        $order = WeChatPersonal::query()->where("trade_no", $tradeNo)->first();
        if (!$order) {
            throw new JSONException("订单不存在");
        }

        //有效期跟着通用插件的配置走，别再写死 300——
        //收银台按配置渲染、这里按 300 判断的话，页面会被自己的轮询判成过期。
        $exptime = strtotime((string)$order->create_time) + Settings::expireSeconds();

        if ($exptime < time()) {
            throw new JSONException("订单已过期");
        }

        return $this->json(200, "success", ["status" => $order->status]);
    }


    /**
     * @return string
     * @throws \ReflectionException
     */
    public function notify(): string
    {
        /**
         * @var \App\Plugin\WeChatPersonal\Service\Order $order
         */
        $order = Di::inst()->make(\App\Plugin\WeChatPersonal\Service\Order::class);
        return $order->callback($this->request->post());
    }
}