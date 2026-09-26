<?php
declare(strict_types=1);

namespace App\Plugin\AlipayPersonal\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $trade_no
 * @property float $amount
 * @property float $pay_amount
 * @property string $return_url
 * @property string $notification_url
 * @property string $type
 * @property int $status
 * @property string $create_time
 * @property string $pay_time
 * @property int $pay_config_id 订单归属的收款配置档，0=1.x 老订单
 */
class AlipayPersonal extends Model
{

    /**
     * @var string
     */
    protected $table = "alipay_personal";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'amount' => 'float', 'pay_amount' => 'float', 'status' => 'integer', 'pay_config_id' => 'integer'];

    /**
     * 找一个当前没被占用的收款金额。
     *
     * 金额是这套方案唯一的订单标识（挂机端只报得出金额），所以同一个收款账号里
     * 同时段不能有两笔相同金额。按配置档分组：不同配置是不同的支付宝账号，
     * 各收各的钱，金额相同也不会混淆——这正是多配置能提升并发的原因。
     *
     * 注意：两套配置若填了同一个收款码，就会共用一个真实账号却各自分配金额，
     * 有撞车风险。配置文档里要求每套配置对应不同的收款账号。
     *
     * @param string $type 支付方式（qrcode/phone/reward）
     * @param float $amount 原始金额
     * @param int $time 订单有效期（秒）
     * @param int $configId 收款配置档
     * @return float 实际要收的金额
     */
    public static function available(string $type, float $amount, int $time = 300, int $configId = 0): float
    {
        $scope = function ($query) use ($configId) {
            return $query->where("pay_config_id", $configId);
        };

        $pending = $scope(self::query())
            ->where("pay_amount", $amount)->where("type", $type)->where("status", 0)
            ->where("create_time", ">", date("Y-m-d H:i:s", time() - $time))->first();

        //刚支付完的也要避开：挂机端的到账通知可能比订单状态慢几秒，
        //这段时间内放出同一个金额会让下一笔上报认错单。
        $justPaid = $scope(self::query())
            ->where("pay_amount", $amount)->where("type", $type)->where("status", 1)
            ->where("pay_time", ">", date("Y-m-d H:i:s", time() - $time))->first();

        if ($pending || $justPaid) {
            return self::available($type, round($amount + 0.01, 2), $time, $configId);
        }
        return $amount;
    }

    /**
     * 干掉无用订单
     */
    public static function clear(int $time = 300): void
    {
        self::query()->where("status", 0)->where("create_time", "<", date("Y-m-d H:i:s", time() - $time))->delete();
    }
}