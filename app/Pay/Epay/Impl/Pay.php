<?php
declare(strict_types=1);

namespace App\Pay\Epay\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use App\Util\Client;
use App\Util\Http;
use Kernel\Exception\JSONException;

/**
 * Class Pay
 * @package App\Pay\Kvmpay\Impl
 */
class Pay extends Base implements \App\Pay\Pay
{

    /**
     * @return PayEntity
     * @throws JSONException
     */
    public function trade(): PayEntity
    {

        if (!$this->config['url'] ||
            !$this->config['pid'] ||
            !isset($this->config['version']) ||
            ($this->config['version'] == 1 && (!$this->config['private_key'] || !$this->config['platform_public_key'])) ||
            ($this->config['version'] == 0 && !$this->config['key'])) {
            throw new JSONException("重要参数缺失，请检查插件配置文件！");
        }

        $title = isset($this->config['order_title']) ? str_replace('${trade_no}', $this->tradeNo, $this->config['order_title']) : "商品订单号:{$this->tradeNo}";

        $param = [
            'pid' => $this->config['pid'],
            'name' => $title,
            'type' => $this->code,
            'money' => $this->amount,
            'out_trade_no' => $this->tradeNo,
            'notify_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'sitename' => $this->tradeNo,
            'clientip' => $this->clientIp,
            'device' => $this->device()
        ];


        $url = trim($this->config['url'], "/");

        if ($this->config['version'] == 1) {
            $param['method'] = 'jump';
            $param['timestamp'] = time();
            $param['sign'] = Signature::rsa($param, $this->config['private_key']);
            $param['sign_type'] = "RSA";
            $url .= "/api/pay/create";
            $successCode = 0;
        } elseif ($this->config['version'] == 0) {
            $param['sign'] = Signature::generateSignature($param, $this->config['key']);
            $param['sign_type'] = "MD5";
            $url .= "/mapi.php";
            $successCode = 1;
        } else {
            throw new JSONException("支付接口出错，下单失败！");
        }

        //下单事务里锁着商品行，网关卡住不能一直等
        try {
            $response = Http::make()->post($url, [
                "form_params" => $param,
                "connect_timeout" => 10,
                "timeout" => 20,
                "http_errors" => false
            ]);
        } catch (\Throwable $e) {
            $this->log("{$this->tradeNo} 下单失败，网关连接异常：" . $e->getMessage());
            throw new JSONException("支付网关连接失败，请稍后重试");
        }

        $body = (string)$response->getBody();
        $excerpt = "(HTTP " . $response->getStatusCode() . ") " . mb_substr(preg_replace('/\s+/', ' ', $body), 0, 300);
        //有的网关输出带 BOM
        $json = json_decode(trim(preg_replace('/^\xEF\xBB\xBF/', '', $body)), true);

        if (!is_array($json)) {
            $this->log("{$this->tradeNo} 下单失败，网关响应不是JSON：{$excerpt}");
            throw new JSONException("支付接口出错，请查看插件日志");
        }

        if (!isset($json['code']) || $json['code'] != $successCode) {
            $this->log("{$this->tradeNo} 下单被网关拒绝：{$excerpt}");
            $message = trim(strip_tags($this->field($json, 'msg')));
            throw new JSONException($message !== '' ? mb_substr($message, 0, 128) : "支付接口出错，请查看插件日志");
        }

        $payEntity = new PayEntity();

        if ($this->config['version'] == 1) {
            //method=jump 只会返回跳转地址
            $jumpUrl = $this->field($json, 'pay_info');
        } else {
            //payurl、qrcode、urlscheme 三者只会返回其一
            $qrcode = $this->field($json, 'qrcode');
            if ($qrcode !== '' && $this->hasCashier()) {
                $payEntity->setUrl($qrcode);
                $option = ['returnUrl' => $this->returnUrl];
                $notice = mb_substr(trim((string)($this->config['cashier_notice'] ?? '')), 0, 500);
                if ($notice !== '') {
                    $option['notice'] = $notice;
                }
                $payEntity->setOption($option);
                $payEntity->setType(self::TYPE_LOCAL_RENDER);
                return $payEntity;
            }
            //urlscheme 是小程序跳转链接，前端直接跳；没有本地收银台的通道（usdt、站长自填的编码）二维码链接也直接跳过去
            $jumpUrl = $this->field($json, 'payurl') ?: $this->field($json, 'urlscheme') ?: $qrcode;
        }

        if (!$this->isJumpUrl($jumpUrl)) {
            $this->log("{$this->tradeNo} 网关没有返回可用的支付地址：{$excerpt}");
            throw new JSONException("支付接口出错，请查看插件日志");
        }

        $payEntity->setUrl($jumpUrl);
        $payEntity->setType(self::TYPE_REDIRECT);
        return $payEntity;
    }

    /**
     * mapi 是服务端下单，网关看不到买家的浏览器，只能靠 device 决定给什么支付页。
     * 微信/QQ/支付宝里打开的要报对，不然拿到的是 App 内打不开的 H5 或二维码
     * @return string
     */
    private function device(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        if (stripos($ua, 'MicroMessenger') !== false) {
            return 'wechat';
        }

        if (stripos($ua, 'AlipayClient') !== false) {
            return 'alipay';
        }

        if (preg_match('#\bQQ/#i', $ua) === 1) {
            return 'qq';
        }

        return Client::isMobile() ? 'mobile' : 'pc';
    }

    /**
     * 该通道有没有本地收银台（View/{code}.html）
     * @return bool
     */
    private function hasCashier(): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $this->code) === 1 && is_file(dirname(__DIR__) . "/View/{$this->code}.html");
    }

    /**
     * 只认带协议头的地址（http(s)，或 weixin:// 这类唤起 App 的），挡掉 javascript: 之类
     * @param string $url
     * @return bool
     */
    private function isJumpUrl(string $url): bool
    {
        return preg_match('#^([a-z][a-z0-9+.-]*)://#i', $url, $matches) === 1
            && !in_array(strtolower($matches[1]), ['javascript', 'vbscript', 'data'], true);
    }

    /**
     * @param array $json
     * @param string $key
     * @return string
     */
    private function field(array $json, string $key): string
    {
        return is_string($json[$key] ?? null) ? trim($json[$key]) : '';
    }
}