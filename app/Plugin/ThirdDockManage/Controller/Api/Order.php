<?php
namespace App\Plugin\ThirdDockManage\Controller\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\Bill;
use App\Model\Commodity;
use App\Model\User;
use App\Plugin\ThirdDockManage\Model\Goods;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Util\Plugin;
use App\Util\Str;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Order extends Manage
{
    use Help;

    /**
     * 再次提交订单
     * @return array
     * @throws JSONException
     */
    public function reSubmit(): array
    {
        $trade_no = $_POST['trade_no'];
        if (filled($trade_no)) {
            $order = \App\Model\Order::query()->where('trade_no', $trade_no)->where('dock_status', 4)->first();
            if ($order) {
                $commodity = Commodity::query()->find($order->commodity_id);
                $good = Goods::query()->find($commodity->dock_g_id);
                $site = Sites::query()->find($good->site_id);
                $class = "\App\Plugin\\{$site->type}\Hook\Main";
                $dock = new $class();
                $plugin_status = $dock->checkStatus();
                if ($plugin_status) {
                    $res = $dock->submitOrder($site->toArray(), $good->toArray(), $commodity->toArray(), $order->toArray());
                    $order_update = [];
                    if ($res['status_code'] == 200) {
                        $order_update['dock_content'] = json_encode($res['data']);
                        $order_update['secret'] = implode("\n", $res['data']);
                        $order_update['dock_order_status'] = 1;
                        if ($res['multi'] && count($res['data']) < $order->card_num) {
                            $order_update['dock_status'] = 3;
                            $msg = "已部分对接";
                        } else {
                            $order_update['dock_status'] = 2;
                            $msg = "已对接";
                        }
                    } else {
                        $order_update['dock_status'] = 4;
                        $order_update['dock_order_status'] = 0;
                        $msg = "对接失败";
                    }
                    //卡密处理
                    if ($res['camilo'] === true) {
                        if (filled($res['data'])) {
                            $order_update['delivery_status'] = 1;//订单完成
                            if ($order_update['dock_status'] == 3) {
                                $order_update['dock_order_status'] = 4;//部分失败
                            }
                            if ($order_update['dock_status'] == 2) {//已完成
                                $order_update['dock_order_status'] = 2;
                            }
                        } else {
                            $order_update['dock_order_status'] = 3;//订单失败
                        }
                    }
                    \App\Plugin\ThirdDockManage\Model\Order::query()->where('id', $order->id)
                        ->update($order_update);

                    //查询构造器更新不会同步 $order 内存模型，先补齐再广播，保证订阅方拿到最新 secret
                    foreach ($order_update as $ko => $item1) {
                        $order->$ko = $item1;
                    }
                    //卡密型对接完成即发货完成，走核心手动发货点位广播（口径同 Admin\Api\Order::manualDelivery；钩子异常不影响业务）
                    try {
                        if ((int)($order_update['delivery_status'] ?? 0) === 1) {
                            $ebOverwrite = false;
                            hook(\App\Consts\Hook::ORDER_MANUAL_DELIVERY_AFTER, $order, $ebOverwrite);
                        }
                    } catch (\Throwable $e) {
                    }

//                    if ($order_update['dock_order_status'] == 3) {
//                        $this->sendEmail('over', $commodity, $order);
//                    } else {
//                        if ($res['camilo'] === true) {
//                            $this->sendEmail('camilo', $commodity, $order);
//                        } else {
//                            //邮件提醒
//                            $this->sendEmail('new_manage', $commodity, $order, $msg);
//                            $this->sendEmail('new_user', $commodity, $order, $msg);
//                        }
//                    }

                    return $this->json($res['status_code'], $res['message'] ?? '尝试再次提交成功', []);
                }
            }
        }
//            return $this->json(200, null, []);
        throw new JSONException("参数错误或系统异常");
    }

    /**
     * 修改状态
     * @return array
     * @throws JSONException
     */
    public function changeStatus(): array
    {
        $id = $_POST['id'];
        $trade_no = $_POST['trade_no'];
        $dock_order_status = $_POST['dock_order_status'];
        $change_content = $_POST['change_content'];

        if (blank($id) || blank($trade_no) || !is_numeric($dock_order_status)) {
            throw new JSONException("参数错误或订单状态不能为空");
        }
        $order = \App\Model\Order::query()->where('trade_no', $trade_no)->find($id);
        if (!$order) {
            throw new JSONException("订单不存在");
        }
        if ($order->dock_order_status == $dock_order_status) {
            return $this->json(200, "状态修改成功");
        } else {
            $hasExistingDelivery = (int)$order->delivery_status === 1
                || trim((string)$order->secret) !== '';
            DB::beginTransaction();
            try {
                $order->dock_order_status = $dock_order_status;

                if ($change_content == 1 && !in_array($dock_order_status, [5, 6])) {
                    $dock_order_status_map = [
                        0 => '待处理',
                        1 => '正在处理',
                        2 => '已完成',
                        3 => '失败',
                        4 => '部分失败',
                        5 => '已部分退款',
                        6 => '已退款',
                    ];
                    $dock_content = is_array($order->dock_content) ? $order->dock_content : json_decode($order->dock_content, true);
                    foreach ($dock_content as $k => $item) {
                        $temp = explode('-', $item);
                        foreach ($temp as $k2 => $v) {
                            if ($this->contains($v, '状态')) {
                                $temp[$k2] = '状态：'.$dock_order_status_map[$dock_order_status];
                                break;
                            }
                        }
                        $dock_content[$k] = implode('-', $temp);
                    }
                    $order->dock_content = json_encode($dock_content);
                    $order->secret = implode("\n", $dock_content);
                }
                $order->save();
                DB::commit();
            } catch (\Exception $e) {
                Plugin::log('ThirdDockManage', $e->getMessage());
                DB::rollBack();
                throw new JSONException("状态修改失败");
            }

            //人工改写了发货内容，走核心手动发货点位广播（口径同 Admin\Api\Order::manualDelivery；事务已提交，钩子异常不影响本次修改）
            try {
                if ($change_content == 1 && !in_array($dock_order_status, [5, 6])) {
                    hook(\App\Consts\Hook::ORDER_MANUAL_DELIVERY_AFTER, $order, $hasExistingDelivery);
                }
            } catch (\Throwable $e) {
            }
        }

        return $this->json(200, "状态修改成功");
    }

    /**
     * 退款
     * @return array
     * @throws JSONException
     */
    public function refund(): array
    {
        $id = $_POST['id'];
        $trade_no = $_POST['trade_no'];
        $refund = $_POST['refund'];
        if (blank($id) || blank($trade_no)) {
            throw new JSONException("参数错误");
        }
        if ($refund <= 0) {
            throw new JSONException("退款金额不能小于等于0");
        }
        $order = \App\Model\Order::query()->where('trade_no', $trade_no)->find($id);
        if (!$order) {
            throw new JSONException("订单不存在");
        }
        if ($order->status != 1) {
            throw new JSONException("该订单未支付");
        }
        if (in_array($order->dock_status, [2, 3]) && !in_array($order->dock_order_status, [3, 4])) {
            throw new JSONException("该订单还在处理中或已退款");
        }
        DB::beginTransaction();
        try {
            if ($refund >= $order->amount) {
                $order->dock_order_status = 6;//已退款
            } else {
                $order->dock_order_status = 5;//已部分退款
            }
            $order->save();

            $user = User::query()->find($order->owner);
            if ($user instanceof User) {
                Bill::create($user, $refund, 1, "订单退款[{$order->trade_no}]", 0, false);
            }

            DB::commit();
        } catch (\Exception $e) {
            Plugin::log('ThirdDockManage', $e->getMessage());
            DB::rollBack();
            throw new JSONException("退款失败");
        }

        return $this->json(200, "退款成功");
    }

    /**
     * 生成新订单号
     * @return array
     * @throws JSONException
     */
    public function changeNumber(): array
    {
        $order_id = $_POST['order_id'];
        $order = \App\Model\Order::query()->find($order_id);
        if ($order) {
            if ($order->dock_status == 1 || $order->dock_status == 4) {
                $order->trade_no = Str::generateTradeNo();
                $order->save();

                return $this->json(200, "生成新订单号成功");
            } else {
                throw new JSONException("订单当前状态不建议生成新订单号");
            }
        } else {
            throw new JSONException("订单不存在");
        }
    }
}
