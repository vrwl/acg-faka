<?php

namespace App\Plugin\ThirdDockManage\Command;

use Amp\Delayed;
use Amp\Emitter;
use Amp\Loop;
use App\Model\Commodity;
use App\Plugin\ThirdDockManage\Model\Logs;
use App\Model\Order;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Util\Plugin;
use Carbon\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Amp\asyncCall;
use function Amp\call;

require __DIR__ . '/../vendor/autoload.php';

#[AsCommand(
    name: 'sync:order',
    description: '同步订单.',
    hidden: false,
)]
class SyncOrder extends Command
{
    use Help;

    public function __construct()
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("同步订单开始:".Carbon::now('PRC')->toDateTimeString());

        $lock = $this->acquireSyncLock('sync_order');
        if (!$lock) {
            $output->writeln('上一次同步订单仍在执行，本次跳过');
            return Command::SUCCESS;
        }

        $lists = $this->pendingOrders();
        $output->writeln("待同步订单数量：".$lists->count());
        if ($lists->count() > 0) {
            $all_num = $lists->count();
            $size = $all_num / 6;
            if ($size <= 1) {
                $size = 1;
            } else {
                $size = ceil($size);
            }
            $chunks = $lists->chunk($size);
            Loop::run(function () use ($chunks, $output) {
                try {
                    $emitter = new Emitter();
                    $iterator = $emitter->iterate();

                    $generator = [];
                    foreach ($chunks as $array) {
                        $generator[] = function (Emitter $emitter) use ($array) {
                            foreach ($array as $v) {
                                yield $emitter->emit($this->alone($v->trade_no, $v));
                            }
                        };
                    }
                    foreach ($generator as $g) {
                        asyncCall($g, $emitter);
                    }
                    while (yield $iterator->advance()) {
                        $res = $iterator->getCurrent();
                        if (filled($res)) {
                            $output->writeln('已处理订单：'.$iterator->getCurrent());
                        }
//                        yield new Delayed(500);
                    }
                    $emitter->complete();
                } catch (\Exception $exception) {
                    Plugin::log("ThirdDockManage", "同步订单状态错误：".(string) $exception);
                }
            });
        }

        $this->releaseSyncLock($lock);

        $output->writeln("同步订单结束:".Carbon::now('PRC')->toDateTimeString());
        return Command::SUCCESS;
    }

    /**
     * 待同步的对接订单（CLI 与线程管理器任务共用）
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function pendingOrders()
    {
        return Order::query()->whereIn('dock_status', [2, 3])->whereIn('dock_order_status', [0, 1])
            ->where('create_time', '<', Carbon::now('PRC')->subRealMinute()->toDateTimeString())->get();
    }

    public function alone($trade_no, Order $order = null)
    {
        if (blank($order)) {
            $order = Order::query()->where('trade_no', $trade_no)->first();
        }
        if (filled($order)) {
            try {
                if (!in_array($order->dock_status, [2, 3])) {//未对接
                    return $trade_no;
                }
                if ($order->dock_order_status > 1) {//已完成
                    return $trade_no;
                }
                $dock_logs = Logs::query()->where('order_id', $order->id)->orderByDesc('id')->first();
                $site = Sites::query()->find($dock_logs->site_id);
                if (!$site) {
                    return $trade_no;
                }

                $class = "\App\Plugin\\{$dock_logs->site_type}\Hook\Main";
                $dock = new $class();
                $res = $dock->queryOrder($order->toArray(), $site->toArray());
                if ($res['status_code'] == 200) {
                    $success = $res['success'];
                    $error = $res['error'];
                    $all_num = $res['all_num'];
                    $dock_num = $res['dock_num'];

                    $order_update = [];
                    $current_num = $success + $error;
                    $order_update['dock_content'] = json_encode($res['data']);
                    $order_update['secret'] = implode("\n", $res['data']);
                    if ($current_num == $dock_num) {//结束
                        $order_update['delivery_status'] = 1;
                        if ($dock_num != $all_num) {//部分失败
                            $order_update['dock_order_status'] = 4;
                        } elseif ($error == 0) {//已完成
                            $order_update['dock_order_status'] = 2;
                        } elseif ($success > 0) {//部分失败
                            $order_update['dock_order_status'] = 4;
                        } else {//失败
                            $order_update['dock_order_status'] = 3;
                            unset($order_update['delivery_status']);
                        }
                        //todo:自动退款
                    }
                    if (count($order_update) > 0) {
                        \App\Plugin\ThirdDockManage\Model\Order::query()->where('id', $order->id)->update($order_update);
                        $commodity = Commodity::query()->find($order->commodity_id);
                        //通知
                        $this->sendEmail('over', $commodity, $order);
			hook(131073,  $commodity, $order);//0x20001
                        //查询构造器更新不会同步 $order 内存模型，先补齐再广播，保证订阅方拿到最新 secret
                        foreach ($order_update as $ko => $item1) {
                            $order->$ko = $item1;
                        }
                        //对接单送达终态才广播，轮询中的部分更新不打扰下游（口径同 Admin\Api\Order::manualDelivery；钩子异常不影响同步）
                        try {
                            if ((int)($order_update['delivery_status'] ?? 0) === 1) {
                                $ebOverwrite = false;
                                hook(\App\Consts\Hook::ORDER_MANUAL_DELIVERY_AFTER, $order, $ebOverwrite);
                            }
                        } catch (\Throwable $e) {
                        }
                    }
                }
            } catch (\Exception $e) {
                Plugin::log("ThirdDockManage", "同步订单状态错误：{$e->getMessage()}");
            }
        }

        return $trade_no;
    }
}
