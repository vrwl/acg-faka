<?php
declare(strict_types=1);

namespace App\Pay\WeChatPersonalPay\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use App\Plugin\WeChatPersonal\Service\Order;
use Kernel\Container\Di;
use Kernel\Exception\JSONException;

/**
 * Class Pay
 * @package App\Pay\Kvmpay\Impl
 */
class Pay extends Base implements \App\Pay\Pay
{
    /**
     * @return PayEntity
     * @throws \ReflectionException|JSONException
     */
    public function trade(): PayEntity
    {
        /**
         * @var Order $order
         */
        $order = Di::inst()->make(Order::class);
        //把本支付接口绑定的那套收款配置传下去：2.0 起一套配置=一个微信收款账号=一台挂机手机
        $trade = $order->create($this->tradeNo, $this->amount, $this->code, $this->returnUrl, $this->callbackUrl, $this->config, $this->payConfigId());
        $payEntity = new PayEntity();
        $payEntity->setType(self::TYPE_REDIRECT);
        $payEntity->setUrl($trade['url']);
        return $payEntity;
    }

    /**
     * 当前支付接口绑定的配置档 id。Base 只给了配置内容没给 id，
     * 这里按内容反查一次——订单要记住自己属于哪台设备，回调才分得清。
     */
    private function payConfigId(): int
    {
        try {
            foreach (\App\Util\PayProfile::list('WeChatPersonalPay') as $profile) {
                $raw = \App\Util\PayProfile::raw('WeChatPersonalPay', (int)$profile['id']);
                if ($raw !== null && $raw == $this->config) {
                    return (int)$profile['id'];
                }
            }
        } catch (\Throwable $e) {
        }
        return 0;
    }
}