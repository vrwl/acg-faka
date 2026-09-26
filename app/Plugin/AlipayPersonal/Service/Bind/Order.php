<?php
declare (strict_types=1);

namespace App\Plugin\AlipayPersonal\Service\Bind;

use App\Plugin\AlipayPersonal\Core\Log;
use App\Plugin\AlipayPersonal\Core\Schema;
use App\Plugin\AlipayPersonal\Core\Settings;
use App\Plugin\AlipayPersonal\Model\AlipayPersonal;
use App\Util\Date;
use App\Util\Http;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Exception\JSONException;

class Order implements \App\Plugin\AlipayPersonal\Service\Order
{
    /**
     * 下单。$config 是支付接口绑定的那套收款配置档，$configId 是它的 id。
     *
     * @throws JSONException
     */
    public function create(string $tradeNo, float $amount, string $type, string $returnUrl, string $notificationUrl, array $config = [], int $configId = 0): array
    {
        Schema::ensureOnce();

        if ($amount <= 0) {
            throw new JSONException("下单金额必须大于0");
        }

        //收款信息缺失就别让买家进收银台了：扫不到码的空白页比明确报错更难排查
        $this->assertPayable($type, $config, $configId);

        $expire = Settings::expireSeconds();

        $order = DB::transaction(function () use ($type, $amount, $tradeNo, $returnUrl, $notificationUrl, $configId, $expire) {
            //金额占位按配置档分组：不同配置是不同的收款账号，各收各的钱，
            //金额撞车只在同一个账号内才有歧义。
            $payAmount = AlipayPersonal::available($type, $amount, $expire, $configId);

            $order = new AlipayPersonal();
            $order->trade_no = $tradeNo;
            $order->amount = $amount;
            $order->pay_amount = $payAmount;
            $order->return_url = $returnUrl;
            $order->notification_url = $notificationUrl;
            $order->type = $type;
            $order->status = 0;
            $order->pay_config_id = $configId;
            $order->create_time = Date::current();
            $order->save();
            return $order;
        });

        return ['trade_no' => $order->trade_no, 'url' => '/plugin/AlipayPersonal/order/pay?tradeNo=' . $order->trade_no];
    }

    /**
     * 下单前确认这套配置真的能收这种款
     * @throws JSONException
     */
    private function assertPayable(string $type, array $config, int $configId): void
    {
        if (Settings::token($config) === '') {
            throw new JSONException("该支付接口未配置挂机 Token，请站长在支付插件的配置里填写");
        }

        if (Settings::qrUrl($configId, $config, 'qrcode', 'alipay_url') === '') {
            //分清是没传图还是图识别不出来——这两种情况站长要做的事完全不同
            $hasImage = trim((string)($config['qrcode'] ?? '')) !== '' || Settings::globalValue('qrcode') !== '';
            throw new JSONException($hasImage
                ? "支付宝收款码图片无法识别，请换一张更清晰的图（裁掉多余背景只留二维码），或在支付接口配置里直接填写「收款链接」"
                : "该支付接口未上传支付宝收款码，请在支付插件的配置里上传");
        }
    }

    /**
     * 挂机端上报到账。
     *
     * ——这个方法的入参与返回值是挂机端 App 的协议，绝不能改：
     *   收 type / amount / sign，返回 "success" 或错误说明。
     *
     * 多设备是这样区分的：每套配置档有自己的 Token，挂机 App 填哪个 Token 就代表哪台设备。
     * 服务端拿所有配置档的 Token 逐个验签，签得通的那套就是来源设备，然后只在该设备的订单里匹配。
     * App 侧因此完全不用改，站长只需在第二台手机上填第二套配置的 Token。
     */
    public function callback(array $data): string
    {
        try {
            Schema::ensureOnce();

            $sign = (string)($data['sign'] ?? '');
            if ($sign === '') {
                return "签名验证失败";
            }

            $resolved = $this->resolveDevice($data, $sign);
            if ($resolved === null) {
                Log::warn('挂机端上报验签失败', ['type' => (string)($data['type'] ?? ''), 'amount' => (string)($data['amount'] ?? '')]);
                return "签名验证失败";
            }
            [$configId, $token] = $resolved;

            $type = (string)($data['type'] ?? '');
            $amount = (string)($data['amount'] ?? '');

            if ($type === '' || $amount === '' || !is_numeric($amount)) {
                return '金额或类型不能为空';
            }

            $expire = Settings::expireSeconds();

            //提高事务隔离级别，防并发下两笔上报吃到同一张订单
            DB::connection()->getPdo()->exec("set session transaction isolation level serializable");
            $order = DB::transaction(function () use ($type, $amount, $expire, $configId) {
                $query = AlipayPersonal::query()
                    ->where("status", 0)
                    ->where("type", $type)
                    ->where("pay_amount", $amount)
                    ->where("create_time", ">", date("Y-m-d H:i:s", time() - $expire));

                //只认这台设备自己的订单；0 是 1.x 老订单（那时还没有配置档概念），一并放行
                $query->where(function ($q) use ($configId) {
                    $q->where('pay_config_id', $configId)->orWhere('pay_config_id', 0);
                });

                $order = $query->orderBy('create_time')->first();

                if (!$order) {
                    throw new JSONException("订单不存在");
                }

                $order->status = 1;
                $order->pay_time = Date::current();
                $order->save();
                return $order;
            });

            Log::info('挂机端到账已核销', ['trade_no' => $order->trade_no, 'type' => $type, 'amount' => $amount, 'config_id' => $configId]);

            $this->notifyShop($order, $token);
            return "success";
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * 用各套配置的 Token 逐个验签，定位上报来自哪台设备。
     *
     * @return array{0:int,1:string}|null [配置档id, token]
     */
    private function resolveDevice(array $data, string $sign): ?array
    {
        $tried = [];

        foreach (Settings::profiles() as $configId => $profile) {
            $token = Settings::token($profile);
            if ($token === '' || isset($tried[$token])) {
                continue;
            }
            $tried[$token] = true;
            if (hash_equals(Str::generateSignature($data, $token), $sign)) {
                return [$configId, $token];
            }
        }

        //不再回落到通用插件全局 app_key：那是随源码公开分发的出厂默认值，等于没有密钥，
        //可被任何人用来伪造「已支付」回调。没有任何一套支付接口配置的密钥能验通即拒绝，宁可不收也不误判已支付。
        return null;
    }

    /**
     * 通知商城发货。签名必须用这张订单所属配置的 Token——
     * 商城侧的验签（Pay\AlipayPersonalPay\Impl\Signature）就是按支付接口的配置档取 key 的，
     * 两边取不到同一个 key 就会「钱收了、货不发」。
     */
    private function notifyShop(AlipayPersonal $order, string $token): void
    {
        $post = [
            "trade_no" => $order->trade_no,
            "amount" => $order->amount,
            "status" => $order->status,
            "pay_time" => $order->pay_time
        ];
        $post['sign'] = Str::generateSignature($post, $token);

        try {
            $response = Http::make(['timeout' => 10, 'connect_timeout' => 5])->post($order->notification_url, [
                "form_params" => $post,
                "verify" => false
            ]);
            $body = (string)$response->getBody()->getContents();
            if (stripos($body, 'success') === false) {
                Log::warn('商城回调未返回 success', ['trade_no' => $order->trade_no, 'response' => mb_substr($body, 0, 200)]);
            }
        } catch (\Throwable $e) {
            Log::warn('商城回调发送失败', ['trade_no' => $order->trade_no, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }
}
