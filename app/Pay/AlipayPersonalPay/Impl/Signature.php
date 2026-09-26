<?php
declare(strict_types=1);

namespace App\Pay\AlipayPersonalPay\Impl;

use App\Util\Plugin;
use App\Util\Str;

/**
 * Class Signature
 * @package App\Pay\Kvmpay\Impl
 */
class Signature implements \App\Pay\Signature
{

    /**
     * @param mixed $str
     * @param string $local
     * @return bool
     */
    public static function safetyEquals(mixed $str, string $local): bool
    {
        if (!is_string($str) || $str === '') {
            return false;
        }

        return hash_equals($local, $str);
    }



    /**
     * 验签用的通信密钥：优先用本支付接口选中的那套配置，没填就回落到「支付宝个人挂机版」插件的全局配置。
     * 回落是为了让升级前就在用的站点零配置继续工作。
     *
     * 2.0 起每套配置就是一台独立的挂机设备：下单时订单会记住自己属于哪套配置，
     * 挂机端上报时按 Token 反查设备，通知商城也用同一套 Token 签名——两边取到的 key 必然一致。
     *
     * @param array $config
     * @return string
     */
    private static function resolveAppKey(array $config): string
    {
        //只认本支付接口(pay_config)自己配置的通信密钥；不再回落到「支付宝个人挂机版」插件 Config.php 里
        //那枚随源码公开分发的出厂默认值（人人可得，等于没密钥，可被用来伪造回调）。为空由 verification() 直接拒收。
        return trim((string)($config['app_key'] ?? ''));
    }

    /**
     * @inheritDoc
     */
    public function verification(array $data, array $config): bool
    {
        $appKey = self::resolveAppKey($config);

        //密钥为空时绝不能继续：Str::generateSignature 用空串照样能算出一个签名，
        //而那个签名攻击者自己也能算，等于验签形同虚设。宁可拒收也不放行。
        if ($appKey === '') {
            return false;
        }

        $generateSignature = Str::generateSignature($data, $appKey);
        if (!self::safetyEquals($data['sign'], $generateSignature)) {
            return false;
        }
        return true;
    }

}