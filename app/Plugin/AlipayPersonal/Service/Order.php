<?php
declare (strict_types=1);

namespace App\Plugin\AlipayPersonal\Service;

use Kernel\Annotation\Bind;

#[Bind(class: \App\Plugin\AlipayPersonal\Service\Bind\Order::class)]
interface Order
{
    /**
     * @param string $tradeNo
     * @param float $amount
     * @param string $type
     * @param string $returnUrl
     * @param string $notificationUrl
     * @param array $config 支付接口绑定的收款配置档（2.0 起一套配置=一个收款账号）
     * @param int $configId 配置档 id，落进订单用于回调时区分设备
     * @return array
     */
    public function create(string $tradeNo, float $amount, string $type, string $returnUrl, string $notificationUrl, array $config = [], int $configId = 0): array;


    /**
     * @param array $data
     * @return string
     */
    public function callback(array $data): string;
}