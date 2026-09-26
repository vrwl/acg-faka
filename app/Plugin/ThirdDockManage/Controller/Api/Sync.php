<?php
namespace App\Plugin\ThirdDockManage\Controller\Api;

use Amp\Loop;
use App\Controller\Base\API\Manage;
use App\Interceptor\Waf;
use App\Model\Commodity;
use App\Model\Order;
use App\Plugin\ThirdDockManage\Model\Goods;
use App\Plugin\ThirdDockManage\Model\Logs;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Util\Ini;
use App\Util\Plugin;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use function Amp\asyncCall;

require __DIR__ . '/../../vendor/autoload.php';

#[Interceptor(Waf::class, Interceptor::TYPE_API)]
class Sync extends Manage
{
    use Help;

    private array $plugin_config;

    public function __construct()
    {
        $this->plugin_config = Plugin::getConfig('ThirdDockManage');
        $token = $_GET['token'] ?? null;
        if (filled($token)) {
            $plugin_token = $this->plugin_config['token'] ?? null;
            if (blank($plugin_token) || $plugin_token != $token) {
                throw new JSONException("认证失败");
            }
        } else {
            throw new JSONException("认证失败");
        }
    }

    public function alone($trade_no = '', Order $order = null)
    {
        if (blank($trade_no)) {
            $trade_no = $_GET['trade_no'] ?? '';
        }
        if (filled($trade_no)) {
            if (blank($order)) {
                $order = Order::query()->where('trade_no', $trade_no)->first();
            }
        }
        if (filled($order)) {
            try {
                if (!in_array($order->dock_status, [2, 3])) {//未对接
                    return;
                }
                if ($order->dock_order_status > 1) {//已完成
                    return;
                }
                $dock_logs = Logs::query()->where('order_id', $order->id)->orderByDesc('id')->first();
                $site = Sites::query()->find($dock_logs->site_id)->toArray();

                $class = "\App\Plugin\\{$dock_logs->site_type}\Hook\Main";
                $dock = new $class();
                $res = $dock->queryOrder($order->toArray(), $site);
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
                        if ($commodity) {
                            //通知
                            $this->sendEmail('over', $commodity, $order);
                        }
                    }
                }
            } catch (\Exception $e) {
                Plugin::log("ThirdDockManage", "同步订单状态错误：{$e->getMessage()}");
            }
        }
    }

    /**
     * 同步订单
     * @return array
     */
    public function order(): array
    {
        $lists = Order::query()->whereIn('dock_status', [2, 3])->whereIn('dock_order_status', [0, 1])
            ->where('create_time', '<', Carbon::now('PRC')->subMinutes(3)->toDateTimeString())->get();
        if ($lists->count() > 0) {
            foreach ($lists as $list) {
                asyncCall(function () use ($list) {
                    $this->alone($list->trade_no, $list);
                });
            }
            Loop::run();
        }

        return $this->json(200, '同步订单结束');
    }

    /**
     * 同步商品
     * @return array
     * @throws JSONException
     */
    public function good(): array
    {
        $site_id = $_GET['site_id'] ?? null;
        if (filled($site_id)) {
            $sites = Sites::query()->where('status', 1)->where('id', $site_id)->get();
        } else {
            $sites = Sites::query()->where('status', 1)->get();
        }
        foreach ($sites as $site) {
            \Amp\asyncCall(function () use ($site) {
                $class = "\App\Plugin\\{$site->type}\Hook\Main";
                $dock = new $class();
                $plugin_status = $dock->checkStatus();
                if ($plugin_status) {
                    $goods = $dock->getAllGoods($site->account, $site->password, $site->toArray());
                    if ($goods['status_code'] == 200) {
                        Goods::query()->where('site_id', $site->id)->update(['status' => 2]);
                        foreach ($goods['data'] as $datum) {
                            if ($datum['stock'] < 0) {
                                $datum['stock'] = 0;
                            }
                            if (is_array($datum['attach'])) {
                                $datum['attach'] = json_encode($datum['attach']);
                            }
                            $price_temp = explode('.', $datum['price']);
                            if (strlen(head($price_temp)) > 8) {
                                $price_temp[0] = substr(head($price_temp), -8);
                                $datum['price'] = implode('.', $price_temp);
                            }

                            DB::beginTransaction();
                            try {
                                $n_good = Goods::query()->where('site_id', $datum['site_id'])
                                    ->where('c_id', $datum['c_id'])->first();
                                if ($n_good) {//编辑
                                    $n_good->update($datum);
                                    $changes = $n_good->getChanges();
                                    $plugin_config = Plugin::getConfig('ThirdDockManage');
                                    if (count($changes) > 0) {
                                        $commodity_lists = Commodity::query()->where('dock_g_id', $n_good->id)->get();
                                        foreach ($commodity_lists as $commodity) {
                                            $mode = $commodity->dock_mode;
                                            $mode_value = $this->changeStr($commodity->dock_mode_value);
                                            $lucky_decimal = $commodity->dock_lucky_decimal;

                                            $dock_attach = unserialize($commodity->dock_attach ?? '');
                                            if ($dock_attach === false) {
                                                $dock_attach = [];
                                            }
                                            $dock_attach['use_upload'] = $dock_attach['use_upload'] ?? 2;
                                            $dock_attach['content_replace'] = $dock_attach['content_replace'] ?? '';

                                            $commodity_update = [];
                                            //同步详情
                                            if ($commodity->dock_sync_content == 1 && isset($changes['content'])) {
                                                if ($plugin_config['try_fix_good_detail']) {
                                                    $commodity_update['description'] = '<div>'.($n_good->content ?? '').'</div>';
                                                } else {
                                                    $commodity_update['description'] = $n_good->content ?? '';
                                                }
                                                //详情文本替换
                                                if (!empty($dock_attach['content_replace'])) {
                                                    $replaces = explode('$$$', $dock_attach['content_replace']);
                                                    foreach ($replaces as $replace) {
                                                        $replace_temp = explode('##', $replace);
                                                        if (count($replace_temp) == 2) {
                                                            if (!str_starts_with($replace_temp[0], '/')) {
                                                                $replace_temp[0] = '/' . preg_quote($replace_temp[0], '/'). '/';
                                                            }
                                                            $commodity_update['description'] = preg_replace($replace_temp[0], $replace_temp[1], $commodity_update['description']);
                                                        }
                                                    }
                                                }
                                                if ($dock_attach['use_upload'] == 1 || $dock_attach['use_upload'] == 3 || ($dock_attach['use_upload'] == 2 && ($plugin_config['upload'] == 1 || $plugin_config['save_pic'] == 1))) {//图床
                                                    preg_match_all('/<img[\s\S]+?src=[\'\"](.+?)[\'\"][\s\S\>]?/', $commodity_update['description'], $match);
                                                    $list = array_values(array_unique((array)$match[1]));
                                                    if (count($list) > 0) {
                                                        $domain = $site->domain;
                                                        foreach ($list as $e) {
                                                            $res_url = $e;
                                                            if (!$this->startsWith($e, 'http')) {
                                                                $res_url = $domain . $e;
                                                            }
                                                            if ($dock_attach['use_upload'] == 3 || ($dock_attach['use_upload'] == 2 && $plugin_config['save_pic'] == 1)) {//存本地
                                                                $res_url_temp = $this->downloadImage($res_url);
                                                                if ($res_url_temp !== false) {
                                                                    $res_url = '/assets/cache/images/' . $res_url_temp;
                                                                }
                                                            } else {
                                                                $res_url = $this->uploadImage($res_url, $plugin_config);
                                                            }
                                                            $commodity_update['description'] = str_replace($e, $res_url, $commodity_update['description']);
                                                        }
                                                    }
                                                }
                                            }

                                            //同步价格
                                            if ($commodity->dock_sync_price == 1 && isset($changes['price'])) {
                                                $commodity_update['factory_price'] = $n_good->price;
                                                $current_price = $this->calcPrice($n_good->price, $mode, $mode_value, $lucky_decimal);
                                                $commodity_update['price'] = $commodity_update['user_price'] = $current_price;
                                            }
                                            //同步标题
                                            if ($commodity->dock_sync_title == 1 && isset($changes['name'])) {
                                                $commodity_update['name'] = $n_good->name;;
                                            }
                                            //同步封面图
                                            if ($commodity->dock_sync_title == 1 && isset($changes['img'])) {
                                                $img = $n_good->img;
                                                //图床
                                                if (strlen($img) > 250 || $dock_attach['use_upload'] == 3 || ($dock_attach['use_upload'] == 2 && $plugin_config['save_pic'] == 1)) {
                                                    $res_url_temp = $this->downloadImage($img);
                                                    if ($res_url_temp !== false) {
                                                        $img = '/assets/cache/images/'.$res_url_temp;
                                                    }
                                                } elseif ($dock_attach['use_upload'] == 1 || ($dock_attach['use_upload'] == 2 && $plugin_config['upload'] == 1)) {
                                                    $img = $this->uploadImage($img, $plugin_config);
                                                }
                                                $commodity_update['cover'] = $img;
                                            }
                                            //同步控件
                                            if (isset($changes['attach'])) {
                                                if (!is_array($n_good->attach)) {
                                                    $attach_temp = json_decode($n_good->attach, true);
                                                } else {
                                                    $attach_temp = $n_good->attach;
                                                }
                                                if (isset($attach_temp['attach']) && isset($attach_temp['config'])) {
                                                    $commodity_update['widget'] = json_encode($attach_temp['attach']);
                                                    if (filled($attach_temp['config'])) {
                                                        $temp_config = Ini::toArray($attach_temp['config']);
                                                        if (isset($temp_config['category_factory'])) {
                                                            foreach ($temp_config['category_factory'] as $k => $v) {
                                                                $temp_config['category'][$k] = $this->calcPrice($v, $mode, $mode_value, $lucky_decimal);
                                                            }
                                                        }
                                                        $commodity_update['config'] = Ini::toConfig($temp_config);
                                                    }
                                                } else {
                                                    $commodity_update['widget'] = $n_good->attach;
                                                }
                                            }
                                            //同步状态
//                                            if ($n_good->status != 1) {
                                            $commodity_update['status'] = $n_good->status;
//                                            }
                                            if (count($commodity_update) > 0) {
//                                                $commodity->update($commodity_update);
                                                \App\Plugin\ThirdDockManage\Model\Commodity::query()->where('id', $commodity->id)
                                                    ->update($commodity_update);
                                            }
                                        }
                                    }
                                    $n_good->save();
                                } else {//创建
                                    Goods::query()->create($datum);
                                }
                                DB::commit();
                            } catch (\Exception $e) {
                                Plugin::log('ThirdDockManage', $e->getMessage());
                                DB::rollBack();
                            }
                        }
                    }
                }
            });
        }
        Loop::run();
        Plugin::setCache('ThirdDockManage', 'sync_good', 'last_sync_time', Carbon::now('PRC')->toDateTimeString());

        return $this->json(200, '同步商品结束');
    }
}
