<?php
namespace App\Plugin\ThirdDockManage\Hook;

use App\Controller\Base\View\ManagePlugin;
use App\Model\Order;
use App\Model\Pay;
use App\Model\Commodity;
use App\Plugin\ThirdDockManage\Model\Goods;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Service\TaskStore;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Util\Ini;
use App\Util\Plugin;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Kernel\Annotation\Hook;
use Kernel\Annotation\Post;
use Kernel\Consts\Base;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;
use Kernel\Plugin\Entity\Column;
use Kernel\Plugin\Entity\Search;
use Kernel\Plugin\Entity\Stock;
use Kernel\Plugin\Entity\Tab;
use Kernel\Util\Context;


class Main extends ManagePlugin
{
    use Help;

    /**
     * 更新或安装时，安装数据库支持
     * @return void
     */
    private function InstallDB(): void
    {
        //站点
        if (! Manager::schema()->hasTable('third_dock_sites')) {
            Manager::schema()->create('third_dock_sites', function (Blueprint $table) {
                $table->increments('id');
                $table->string('type', 50)->default('')->comment('类型');
                $table->string('name', 100)->default('')->comment('名称');
                $table->tinyInteger('status')->default(0)->comment('状态');
                $table->string('domain')->default('')->comment('域名/地址');
                $table->string('account')->default('')->comment('账号');
                $table->string('password')->default('')->comment('密码');
                $table->decimal('balance', 15, 6)->default(0)->comment('余额');
                $table->unsignedTinyInteger('pay_way')->default(1)->comment('支付方式（1:余额、2:点数）');
                $table->text('remark')->nullable()->comment('备注');

                $table->timestamps();
            });
        }
        $siteDeleted = Manager::schema()->hasColumn("third_dock_sites", "deleted_at");
        if (!$siteDeleted) {
            Manager::schema()->table("third_dock_sites", function (Blueprint $table) {
                $table->softDeletes();
            });
        }
        //商品
        if (! Manager::schema()->hasTable('third_dock_goods')) {
            Manager::schema()->create('third_dock_goods', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('site_id')->default(0)->comment('对接站点ID');
                $table->string('c_id')->default('')->comment('商品ID');
                $table->string('name')->default('')->comment('商品名称');
                $table->text('img')->nullable()->comment('商品图片');
                $table->string('category')->default('')->comment('商品分类ID/名称');
                $table->decimal('price', 15, 6)->default(0)->comment('价格');
                $table->longText('content')->nullable()->comment('描述');
                $table->tinyInteger('status')->default(0)->comment('状态');
                $table->unsignedInteger('stock')->default(0)->comment('库存');
                $table->unsignedInteger('min_num')->default(0)->comment('最小购买数量');
                $table->unsignedInteger('max_num')->default(0)->comment('最大购买数量');
                $table->longText('attach')->nullable()->comment('附加信息');

                $table->timestamps();
            });
        }
        //商品扩展
        $textArray = ['g_id', 'mode', 'mode_value', 'lucky_decimal', 'sync_price', 'sync_content', 'sync_now', 'sync_title'];
        foreach ($textArray as $v) {
            if (!Manager::schema()->hasColumn("commodity", 'dock_'.$v)) {
                Manager::schema()->table("commodity", function (Blueprint $table) use ($v){
                    switch ($v) {
                        case 'g_id':
                            $table->unsignedBigInteger('dock_g_id')->default(0)->comment('对接商品ID');
                            break;
                        case 'mode':
                            $table->unsignedTinyInteger('dock_mode')->default(0)->comment('加价模式');
                            break;
                        case 'mode_value':
                            $table->unsignedFloat('dock_mode_value', 8, 4)->default(0)->comment('商品加价');
                            break;
                        case 'lucky_decimal':
                            $table->unsignedFloat('dock_lucky_decimal')->default(0)->comment('吉利小数');
                            break;
                        case 'sync_price':
                            $table->unsignedTinyInteger('dock_sync_price')->default(0)->comment('同步价格');
                            break;
                        case 'sync_content':
                            $table->unsignedTinyInteger('dock_sync_content')->default(0)->comment('同步详情及参数');
                            break;
                        case 'sync_now':
                            $table->unsignedTinyInteger('dock_sync_now')->default(0)->comment('实时同步');
                            break;
                        case 'sync_title':
                            $table->unsignedTinyInteger('dock_sync_title')->default(0)->comment('同步标题及封面图');
                            break;
                    }
                });
            }
        }
        //订单扩展
        $dockStatus = Manager::schema()->hasColumn("order", "dock_status");
        if (!$dockStatus) {
            Manager::schema()->table("order", function (Blueprint $table) {
                $table->unsignedTinyInteger('dock_status')->default(0)->comment('对接状态，0：无需对接，1：未对接，2：已对接，3：部分对接，4：对接出错');
            });
        }
        $dockContent = Manager::schema()->hasColumn("order", "dock_content");
        if (!$dockContent) {
            Manager::schema()->table("order", function (Blueprint $table) {
                $table->text('dock_content')->nullable()->comment('对接内容（订单号等）');
            });
        }
        $dockOrderStatus = Manager::schema()->hasColumn("order", "dock_order_status");
        if (!$dockOrderStatus) {
            Manager::schema()->table("order", function (Blueprint $table) {
                $table->unsignedTinyInteger('dock_order_status')->nullable()->comment('对接的订单状态(0：待处理，1：正在处理，2：已完成，3：失败，4: 部分失败，5：已部分退款，6：已退款)');
            });
        }
        //日志
        if (! Manager::schema()->hasTable('third_dock_logs')) {
            Manager::schema()->create('third_dock_logs', function (Blueprint $table) {
                $table->id();

                $table->unsignedInteger('order_id')->default(0)->comment('订单ID');
                $table->string('trade_no', 26)->default('')->comment('订单号');
                $table->unsignedInteger('site_id')->default(0)->comment('站点ID');
                $table->string('site_type', 50)->default('')->comment('站点类型');
                $table->text('uri')->nullable()->comment('请求地址');
                $table->string('method', 20)->nullable()->comment('请求方式');
                $table->text('parameter')->nullable()->comment('请求参数');
                $table->text('result')->nullable()->comment('结果');
                $table->text('remark')->nullable()->comment('备注');

                $table->timestamps();
            });
        }
        //规则
        if (! Manager::schema()->hasTable('third_dock_rules')) {
            Manager::schema()->create('third_dock_rules', function (Blueprint $table) {
                $table->id();

                $table->text('site_ids')->nullable()->comment('站点IDs');
                $table->text('categories')->nullable()->comment('分类名');
                $table->text('good_names')->nullable()->comment('商品名');
                $table->tinyInteger('status')->default(0)->comment('规则状态');
                $table->tinyInteger('auto_class')->default(0)->comment('自动对应分类');
                $table->unsignedInteger('sort')->default(0)->comment('序号');
                $table->text('settings')->nullable()->comment('对应生成的设置');

                $table->timestamps();
            });
        }
        //采集批次任务表
        TaskStore::ensureTable();
    }

    /**
     * 批次任务数据已迁移至 third_dock_sync_tasks 表，清理历史 Db/task.php 残留
     * @return void
     */
    private function removeTaskCacheFiles(): void
    {
        Plugin::clearCache('ThirdDockManage', 'task');
        foreach (Sites::withTrashed()->pluck('type')->unique() as $type) {
            if (filled($type)) {
                Plugin::clearCache((string)$type, 'task');
            }
        }
    }

    /**
     * 更新或安装时，安装数据库支持
     * @return void
     */
    private function UpdateDB(): void
    {
//        //用户表
//        $balance = Commodity::query()->getConnection()->getPdo()->query("show columns from acg_user where Field = 'balance'")->fetch();
//        if ($this->startsWith($balance['Type'], 'decimal(14,2)')) {
//            Commodity::query()->getConnection()->getPdo()->query("alter table acg_user modify balance decimal(15, 6) unsigned default 0.000000 not null comment '余额'")->execute();
//        }
//        $coin = Commodity::query()->getConnection()->getPdo()->query("show columns from acg_user where Field = 'coin'")->fetch();
//        if ($this->startsWith($coin['Type'], 'decimal(14,2)')) {
//            Commodity::query()->getConnection()->getPdo()->query("alter table acg_user modify coin decimal(15, 6) unsigned default 0.000000 not null comment '硬币，可提现的币';")->execute();
//        }
//        $recharge = Commodity::query()->getConnection()->getPdo()->query("show columns from acg_user where Field = 'recharge'")->fetch();
//        if ($this->startsWith($recharge['Type'], 'decimal(14,2)')) {
//            Commodity::query()->getConnection()->getPdo()->query("alter table acg_user modify recharge decimal(15, 6) unsigned default 0.000000 not null comment '累计充值';")->execute();
//        }
//        $total_coin = Commodity::query()->getConnection()->getPdo()->query("show columns from acg_user where Field = 'total_coin'")->fetch();
//        if ($this->startsWith($total_coin['Type'], 'decimal(14,2)')) {
//            Commodity::query()->getConnection()->getPdo()->query("alter table acg_user modify total_coin decimal(15, 6) unsigned default 0.000000 not null comment '累计获得的硬币';")->execute();
//        }
//        //商品表
//        $factory_price = Commodity::query()->getConnection()->getPdo()->query("show columns from acg_commodity where Field = 'factory_price'")->fetch();
//        if ($this->startsWith($factory_price['Type'], 'decimal(10,2)')) {
//            Commodity::query()->getConnection()->getPdo()->query("alter table acg_commodity modify factory_price decimal(15, 6) unsigned default 0.000000 not null comment '成本单价'")->execute();
//        }
//        $price = Commodity::query()->getConnection()->getPdo()->query("show columns from acg_commodity where Field = 'price'")->fetch();
//        if ($this->startsWith($price['Type'], 'decimal(10,2)')) {
//            Commodity::query()->getConnection()->getPdo()->query("alter table acg_commodity modify price decimal(15, 6) unsigned default 0.000000 not null comment '商品单价(未登录)'")->execute();
//        }
//        $user_price = Commodity::query()->getConnection()->getPdo()->query("show columns from acg_commodity where Field = 'user_price'")->fetch();
//        if ($this->startsWith($user_price['Type'], 'decimal(10,2)')) {
//            Commodity::query()->getConnection()->getPdo()->query("alter table acg_commodity modify user_price decimal(15, 6) unsigned default 0.000000 not null comment '商品单价(会员价)'")->execute();
//        }

        $database_config = config('database');
        $prefix = $database_config['prefix'];

        $commodity = Commodity::query()->getConnection()->getPdo()->query("show columns from ".$prefix."commodity where Field = 'dock_mode_value'")->fetch();
        if (isset($commodity['Type']) && $this->startsWith($commodity['Type'], 'double')) {
            Commodity::query()->getConnection()->getPdo()->query("alter table ".$prefix."commodity modify dock_mode_value varchar(255) default '' not null comment '商品加价'")->execute();
        }

        $dockStatus = Manager::schema()->hasColumn("commodity", "dock_attach");
        if (!$dockStatus) {
            Manager::schema()->table("commodity", function (Blueprint $table) {
                $table->text('dock_attach')->nullable()->comment('附加配置');
            });
        }

//        $commodityId = Manager::schema()->hasColumn("third_dock_goods", "commodity_id");
//        if (!$commodityId) {
//            Manager::schema()->table("third_dock_goods", function (Blueprint $table) {
//                $table->unsignedBigInteger('commodity_id')->default(0)->comment('主商品ID');
//            });
//        }
    }

    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::START)]
    public function Start(): void
    {
        $this->InstallDB();
        $this->UpdateDB();
        $this->getExt();
        $this->delViewRuntime();
        $this->removeTaskCacheFiles();
    }

    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::INSTALL)]
    public function Install(): void
    {
        $this->InstallDB();
    }

    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::UPGRADE)]
    public function Update(): void
    {
        Plugin::clearCache('ThirdDockManage', 'ext');
        $this->InstallDB();
        $this->UpdateDB();
        $this->delViewRuntime();
        $this->removeTaskCacheFiles();
    }

    /**
     * 菜单
     * @throws ViewException
     */
    #[Hook(point: \App\Consts\Hook::ADMIN_VIEW_MENU)]
    public function menu()
    {
        echo $this->render(null, "Menu.html");
    }

    #[Hook(point: \App\Consts\Hook::HACK_SUBMIT_TAB)]
    public function commodityTab(): ?array
    {
        $route = Context::get(Base::ROUTE);
        if (!str_starts_with($route, "/admin")) {
            return null;
        }

        $code = <<<JS
{
    name: util.icon("fa-duotone fa-regular fa-plug") + " 第三方对接设置",
    form: [
        {title: "对接商品", name: "dock_g_id", type: "input", placeholder: "不清楚的请勿修改", tips:"不清楚的请勿修改"},
        {title: "加价模式", name: "dock_mode", type: "radio", dict: [{"id":0,"name":"普通金额加价"},{"id":1,"name":"百分比加价"},{"id":2,"name":"阶梯复杂加价"}], default: 0},
        {title: "加价数量", name: "dock_mode_value", type: "input", placeholder: "金额/百分比(小数代替)/阶梯格式", tips:"阶梯格式：50@%@0.1|60@+@5  表示成本价小于等于50的，按百分比0.1加价；成本价小于等于60的按普通金额5加价；多个条件以第一个符合条件优先"},
        {title: "吉利小数", name: "dock_lucky_decimal", type: "input", placeholder: "0.6(小数格式，想要几位这里就小数后几位)", tips:"加价后的价格会以增加的方式达到这个小数"},
        {title: "同步价格", name: "dock_sync_price", type: "switch", text:"是", default: 0, tips: "获取最新价格，注意：不会修改批发价格！"},
        {title: "同步详情及参数", name: "dock_sync_content", type: "switch", text:"是", default: 0, tips: "获取最新详情、参数"},
        {title: "同步标题及封面图", name: "dock_sync_title", type: "switch", text:"是", default: 0, tips: "获取最新标题、封面图"},
        {title: "实时同步", name: "dock_sync_now", type: "switch", text:"是", default: 0, tips: "点击商品的时候会实时获取最新数据，如果开启，货源站速度的快慢会影响这边商品展示的速度！"},
    ]
}
JS;

        return (new Tab("/admin/api/commodity/save", $code))->toArray();
    }

    /**
     * 下单刷新商品
     * @throws JSONException
     */
    #[Hook(point: \App\Consts\Hook::USER_API_INDEX_COMMODITY_DETAIL_INFO)]
    public function goodDetail(&$array)
    {
        $commodity = Commodity::query()->find($array['id'], ['id', 'dock_g_id', 'dock_mode', 'dock_mode_value', 'dock_lucky_decimal',
            'dock_sync_price', 'dock_sync_content', 'dock_sync_now', 'dock_attach']);
        $g_id = $commodity->dock_g_id ?? null;
        $change_price = false;
        $plugin_config = Plugin::getConfig('ThirdDockManage');
        if (!empty($g_id) && $g_id > 0) {
            $dock_attach = unserialize($commodity->dock_attach ?? '');
            if ($dock_attach === false) {
                $dock_attach = [];
            }

            $good = Goods::query()->find($g_id, ['id', 'site_id', 'c_id', 'content', 'price', 'name', 'stock', 'status']);
            $status = $good->status;
            $stock = $good->stock;
            if ($commodity->dock_sync_now == 1) {
                $site = Sites::query()->find($good->site_id);

                $class = "\App\Plugin\\{$site->type}\Hook\Main";
                $dock = new $class();
                $plugin_status = $dock->checkStatus();
                if ($plugin_status) {
                    $info_all = $dock->getGood($good->c_id, $site->account, $site->password, $site->toArray());
                    if ($info_all['status_code'] == 200) {
                        $info = $info_all['data'];
                        $info = array_filter($info);
                        if ($info['stock'] < 0) {
                            $info['stock'] = 0;
                        }
                        $status = $info['status'];
                        $stock = $info['stock'];

                        $mode = $commodity->dock_mode;
                        $mode_value = $this->changeStr($commodity->dock_mode_value);
                        $lucky_decimal = $commodity->dock_lucky_decimal;

                        $dock_attach['use_upload'] = $dock_attach['use_upload'] ?? 2;
                        $dock_attach['content_replace'] = $dock_attach['content_replace'] ?? '';

                        $commodity_update = [];
                        if ($commodity->dock_sync_content == 1 && isset($info['content']) && filled($info['content']) && $info['content'] != $good->content) {
                            if ($plugin_config['try_fix_good_detail']) {
                                $commodity_update['description'] = '<div>'.$info['content'].'</div>';
                            } else {
                                $commodity_update['description'] = $info['content'];
                            }
                            if (filled($dock_attach['content_replace'])) {//文本替换
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
                            //图床
                            if ($dock_attach['use_upload'] == 1 || $dock_attach['use_upload'] == 3 || ($dock_attach['use_upload'] == 2 && ($plugin_config['upload'] == 1 || $plugin_config['save_pic'] == 1))) {
                                preg_match_all('/<img[\s\S]+?src=[\'\"](.+?)[\'\"][\s\S>]?/', $commodity_update['description'], $match);
                                $list = array_values(array_unique((array)$match[1]));
                                if (count($list) > 0) {
                                    $domain = $site->domain;
                                    foreach ($list as $e) {
                                        $res_url = $e;
                                        if (!$this->startsWith($e, 'http')) {
                                            $res_url = $domain . $e;
                                        }
                                        //图床
                                        if ($dock_attach['use_upload'] == 3 || ($dock_attach['use_upload'] == 2 && $plugin_config['save_pic'] == 1)) {//存本地
                                            $res_url_temp = $this->downloadImage($res_url);
                                            if ($res_url_temp !== false) {
                                                $res_url = '/assets/cache/images/'.$res_url_temp;
                                            }
                                        } else {
                                            $res_url = $this->uploadImage($res_url, $plugin_config);
                                        }
                                        $commodity_update['description'] = str_replace($e, $res_url, $commodity_update['description']);
                                    }
//                                $info['content'] = $commodity_update['description'];
                                }
                            }
                        }
                        if ($commodity->dock_sync_price == 1 && isset($info['price']) && filled($info['price'])) {
                            $commodity_update['factory_price'] = $info['price'];
                            $current_price = $this->calcPrice($info['price'], $mode, $mode_value, $lucky_decimal);
                            $commodity_update['price'] = $commodity_update['user_price'] = $current_price;
                        }
                        if ($commodity->dock_sync_title == 1) {
                            if (isset($info['name']) && filled($info['name']) && $info['name'] != $good->name) {
                                $commodity_update['name'] = $info['name'];
                            }
                            if (isset($info['img']) && filled($info['img']) && $info['img'] != $good->img) {
                                $img = $good->img;
                                //图床
                                if (strlen($img) > 250 || $dock_attach['use_upload'] == 3 || ($dock_attach['use_upload'] == 2 && $plugin_config['save_pic'] == 1)) {
                                    $res_url_temp = $this->downloadImage($img);
                                    if ($res_url_temp !== false) {
                                        $img = '/assets/cache/images/'.$res_url_temp;
                                    }
                                } elseif ($dock_attach['use_upload'] == 1 || ($dock_attach['use_upload'] == 2 && $plugin_config['upload'] == 1)) {
                                    $img = $this->uploadImage($info['img'], $plugin_config);
                                }
                                $commodity_update['cover'] = $img;
                            }
                        }
                        //默认同步控件
                        if (isset($info['attach']) && filled($info['attach'])) {
                            if (!is_array($info['attach'])) {
                                $attach_temp = json_decode($info['attach'], true);
                            } else {
                                $attach_temp = $info['attach'];
                            }
                            if (isset($attach_temp['attach']) && isset($attach_temp['config'])) {
                                $commodity_update['widget'] = json_encode($attach_temp['attach'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                                $commodity_update['widget'] = $info['attach'];
                            }
                        }
//                    if ($info['status'] != 1) {
                        $commodity_update['status'] = $info['status'];
//                    }
                        if (count($commodity_update) > 0) {
                            foreach ($commodity_update as $k => $item) {
                                $array[$k] = $item;
                            }
                            \App\Plugin\ThirdDockManage\Model\Commodity::query()->where('id', $array['id'])->update($commodity_update);
                            //实时同步改写了商品（价格/状态等），广播给事件订阅方
                            $ebAction = 'sync';
                            $ebBefore = null;
                            hook(\App\Consts\Hook::COMMODITY_CHANGE_AFTER, [(int)$array['id']], $ebAction, $ebBefore);
                        }
                        Goods::query()->updateOrCreate([
                            'id' => $g_id,
                        ], $info);
                        if (isset($commodity_update['price']) && $array['status'] == 1) {
                            $change_price = true;
                        }
//                        if (isset($commodity_update['price']) && $array['status'] == 1) {
//                            //价格变动，重新计算售价
//                            $new_array = $this->againPrice($array['id']);
//                            $array = array_merge($array, $new_array);
//                        }
                        if ($array['status'] != 1) {
                            throw new JSONException("该商品暂未上架");
                        }
//                    if ($info['stock'] == 0) {
//                        throw new JSONException("库存不足");
//                    }
                    } else {
                        throw new JSONException("商品信息异常，请联系站长或稍后再试");
                    }
                }
            }

            //库存处理
            $dock_attach['show_stock'] = $dock_attach['show_stock'] ?? 0;
            if ($dock_attach['show_stock'] == 2 || ($dock_attach['show_stock'] == 0 && ($plugin_config['show_stock'] ?? 0) == 1)) {
                if ($stock > 0 && $status == 1) {
                    $array['delivery_way'] = 0;
                    $array['stock'] = $stock;
                    $array['stock_state'] = $this->shop->getStockState($stock);
                }
            }
        }
        //价格处理
        if ($change_price) {
            if ($this->getUser()) {
                try {
                    $commodity_model = Commodity::query()->find($array['id']);
                    if ($commodity_model) {
                        //主程序详情接口本身不重算会员价，这里与列表页口径对齐
                        $trade_amount = $this->order->valuation(commodity: $commodity_model, group: $this->getUserGroup());
                        $array['price'] = $trade_amount;
                        $array['user_price'] = $trade_amount;
                    }
                } catch (\Throwable $e) {
                    Plugin::log('ThirdDockManage', '商品[' . $array['id'] . ']会员价计算失败，已按原价展示：' . $e->getMessage());
                }
            }
        }
        if (isset($array['widget']) && is_string($array['widget'])) {
            $array['widget'] = json_decode($array['widget'], true);
        }
        if (filled($array['widget'])) {
            foreach ($array['widget'] as &$v) {
                $v = array_map('strval', $v);
            }
        }
    }

    #[Hook(point: \App\Consts\Hook::USER_API_INDEX_COMMODITY_LIST)]
    public function goods(&$data)
    {
        if (filled($data)) {
            $ids = [];
            foreach ($data as $datum) {
                $ids[] = $datum['id'];
            }
            if (count($ids) > 0) {
                $plugin_config = Plugin::getConfig('ThirdDockManage');
                $g_ids = $g_id_data_flip = $g_attach = [];
                $commodity_data = Commodity::query()->whereIn('id', $ids)->get(['id', 'dock_g_id', 'dock_attach']);
                foreach ($commodity_data as $commodity_datum) {
                    if (!empty($commodity_datum->dock_g_id)) {
                        $g_ids[] = $commodity_datum->dock_g_id;
                        $g_id_data_flip[$commodity_datum->dock_g_id] = $commodity_datum->id;
                        if (!empty($commodity_datum->dock_attach)) {
                            $g_attach[$commodity_datum->dock_g_id] = unserialize($commodity_datum->dock_attach ?? '');
                        }
                    }
                }
                $lists = Goods::query()->whereIn('id', $g_ids)->get(['id', 'stock', 'status'])->toArray();
                $good_arr = [];
                foreach ($lists as $list) {
                    $list = (array) $list;
                    $good_arr[$g_id_data_flip[$list['id']]] = $list;
                }
                foreach ($data as $k => $datum) {
                    if (isset($good_arr[$datum['id']])) {
                        $good_data = $good_arr[$datum['id']];
                        $status = $good_data['status'];
                        $stock = $good_data['stock'];
                        $c_attach = $g_attach[$good_data['id']] ?? [];
                        $c_attach['show_stock'] = $c_attach['show_stock'] ?? 0;
                        //库存处理
                        if ($c_attach['show_stock'] == 2 || ($c_attach['show_stock'] == 0 && ($plugin_config['show_stock'] ?? 0) == 1)) {
                            if ($stock > 0 && $status == 1) {
                                $data[$k]['delivery_way'] = 0;
                                if ($datum['inventory_hidden'] == 1) {
                                    $data[$k]['stock'] = match (true) {
                                        $stock <= 0 => "已售罄",
                                        $stock <= 5 => "所剩无几",
                                        $stock <= 20 => "数量有限",
                                        $stock <= 100 => "现货充足",
                                        default => "库存爆棚"
                                    };
                                } else {
                                    $data[$k]['stock'] = $stock;
                                }
                                $data[$k]['stock_state'] = $this->shop->getStockState($stock);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * 下单对接
     * @param Commodity $commodity
     * @param Order $order
     * @param Pay $pay
     * @return void
     * @throws JSONException
     */
    #[Hook(point: \App\Consts\Hook::USER_API_ORDER_PAY_AFTER)]
    public function Order(Commodity $commodity, Order $order, Pay $pay): void
    {
        $g_id = $commodity->dock_g_id;
        if (!empty($g_id) && $g_id > 0) {
            $good = Goods::query()->find($g_id);
            if ($good && $good->status == 1) {
                $site = Sites::query()->find($good->site_id);

                $class = "\App\Plugin\\{$site->type}\Hook\Main";
                $dock = new $class();
                $plugin_status = $dock->checkStatus();
                if ($plugin_status) {
                    $plugin_config = Plugin::getConfig('ThirdDockManage');

                    $order_update = [];
                    $order_array = $order->toArray();
                    if (filled($order_array['widget'])) {
                        $filter_link = [];
                        if (isset($plugin_config['filter_link']) && filled($plugin_config['filter_link'])) {
                            $filter_link = explode(',', $plugin_config['filter_link']);
                        }
                        $widget = json_decode($order_array['widget'], true);
                        foreach ($widget as $k => $v) {
                            $v['value'] = $widget[$k]['value'] = urldecode($v['value']);
                            if (count($filter_link) > 0) {
                                if (in_array($k, $filter_link)) {
                                    preg_match_all('#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $v['value'], $match);
                                    if (isset($match[0]) && count($match[0]) > 0) {
                                        $change = false;
                                        foreach ($match[0] as $m) {
                                            if ($this->contains($m, ['xiaohongshu', 'v.douyin', 'v.kuaishou'])) {
                                                $widget[$k]['value'] = $m;
                                                $change = true;
                                                break;
                                            }
                                        }
                                        if (!$change) {
                                            foreach ($match[0] as $m) {
                                                if ($this->contains($m, ['douyin', 'kuaishou'])) {
                                                    $widget[$k]['value'] = $m;
                                                    $change = true;
                                                    break;
                                                }
                                            }
                                        }
                                        if (!$change) {
                                            $widget[$k]['value'] = head($match[0]);
                                        }
                                    }
                                }
                            }
                        }
                        $order_array['widget'] = $order_update['widget'] = json_encode($widget);
                    }
                    $res = $dock->submitOrder($site->toArray(), $good->toArray(), $commodity->toArray(), $order_array);
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
                    if (count($order_update) > 0) {
                        foreach ($order_update as $ko => $item1) {
                            $order->$ko = $item1;
                        }
                        $order->save();
                    }
//                    \App\Plugin\ThirdDockManage\Model\Order::query()->where('id', $order->id)->update($order_update);

                    if (in_array($order_update['dock_order_status'], [2, 3, 4])) {
                        if ($order_update['dock_order_status'] == 2 && $res['camilo'] === true) {
                            $this->sendEmail('camilo', $commodity, $order);
                        } else {
                            $this->sendEmail('over', $commodity, $order);
                        }
                    } else {
                        $this->sendEmail('new_user', $commodity, $order);//邮件提醒
                    }
                    $this->sendEmail('new_manage', $commodity, $order, $msg);
                    //卡密型对接完成即发货完成，走核心手动发货点位广播（口径同 Admin\Api\Order::manualDelivery；钩子异常不影响对接结果）
                    try {
                        if ((int)($order_update['delivery_status'] ?? 0) === 1) {
                            $ebOverwrite = false;
                            hook(\App\Consts\Hook::ORDER_MANUAL_DELIVERY_AFTER, $order, $ebOverwrite);
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
        }
    }

    /**
     * @return array|null
     */
    #[Hook(point: \App\Consts\Hook::HACK_ROUTE_TABLE_COLUMNS)]
    public function orderTableColumnStatus(): ?array
    {
        $route = Context::get(Base::ROUTE);
        if (!str_starts_with($route, "/admin")) {
            return null;
        }

        $code = <<<JS
{
    field: 'dock_status', title: '对接状态', formatter: function (val, item) {
        let dock_status = '';
        switch(item.dock_status) {
            case 0:
                dock_status = '无需对接';
                break;
            case 1:
                dock_status = '未对接';
                break;
            case 2:
                dock_status = '已对接';
                break;
            case 3:
                dock_status = '部分对接';
                break;
            case 4:
                dock_status = '对接出错';
                break;
        }
        return '<span class="a-badge a-badge-success">'+dock_status+'</span>';
    }
}
JS;

        return (new Column("/admin/api/order/data", $code, "widget", "after"))->toArray();
    }

    /**
     * @return array|null
     */
    #[Hook(point: \App\Consts\Hook::HACK_ROUTE_TABLE_COLUMNS)]
    public function orderTableColumnOrderStatus(): ?array
    {
        $route = Context::get(Base::ROUTE);
        if (!str_starts_with($route, "/admin")) {
            return null;
        }

        $code = <<<JS
{
    field: 'dock_order_status', title: '对接订单状态', formatter: function (val, item) {
        let dock_order_status = '';
        switch(item.dock_order_status) {
            case 0:
                dock_order_status = '待处理';
                break;
            case 1:
                dock_order_status = '正在处理';
                break;
            case 2:
                dock_order_status = '已完成';
                break;
            case 3:
                dock_order_status = '失败';
                break;
            case 4:
                dock_order_status = '部分失败';
                break;
            case 5:
                dock_order_status = '已部分退款';
                break;
            case 6:
                dock_order_status = '已退款';
                break;
        }
        return '<span class="a-badge a-badge-danger">'+dock_order_status+'</span>';
    }
}
JS;

        return (new Column("/admin/api/order/data", $code, "dock_status", "after"))->toArray();
    }

    /**
     * @return array|null
     */
    #[Hook(point: \App\Consts\Hook::HACK_ROUTE_TABLE_COLUMNS)]
    public function orderTableColumnOperate(): ?array
    {
        $route = Context::get(Base::ROUTE);
        if (!str_starts_with($route, "/admin")) {
            return null;
        }

        $code = <<<JS
{
    field: 'dock_operate',
    title: '对接操作',
    type: 'button',
    buttons: [
        {
            icon: 'fa-duotone fa-regular fa-edit',
            class: 'text-primary',
            title: '修改状态',
            click: (event, value, row, index) => {
                let trade_no = row.trade_no ? row.trade_no : '';
                row.change_content = 0;
                component.popup({
                    submit: '/plugin/ThirdDockManage/api/order/changeStatus',
                    tab: [
                        {
                            name: '修改对接的订单状态',
                            form: [
                                {title: "订单号", name: "trade_no", type: "input", hide: true},
                                {title: "对接的订单状态", name: "dock_order_status", type: "select", dict: [
                                   {"id": 0, "name": "待处理"},
                                   {"id": 1, "name": "正在处理"},
                                   {"id": 2, "name": "已完成"},
                                   {"id": 3, "name": "失败"},
                                   {"id": 4, "name": "部分失败"},
                                   {"id": 5, "name": "已部分退款"},
                                   {"id": 6, "name": "已退款"}
                               ], required: true, placeholder: "请选择"},
                               {title: "改变订单内容", name: "change_content", type: "switch", text: "是"}
                            ]
                        }
                    ],
                    row,
                    autoPosition: true,
                    height: "auto",
                    width: "420px",
                    done: () => {
                        table.refresh(true);
                    }
                });
            }
        },
        {
            icon: 'fa-duotone fa-regular fa-angle-double-up',
            class: 'text-info',
            title: '再次提交',
            show: _ => _?.dock_status === 4,
            click: (event, value, row, index) => {
                let trade_no = row.trade_no ? row.trade_no : '';
                message.ask('您确认尝试再次提交订单？', () => {
                    util.post('/plugin/ThirdDockManage/api/order/reSubmit', {trade_no: trade_no}, res => {
                        message.success('尝试再次提交成功,详细可查看对接日志');
                        table.refresh(true);
                    });
                });
            }
        },
        {
            icon: 'fa-duotone fa-regular fa-eye',
            class: 'text-success',
            title: '查看',
            show: _ => _?.dock_status === 2 || _?.dock_status === 3,
            click: (event, value, row, index) => {
                let content = row.secret;
                if (row.dock_order_status === 5) {
                    content += ("\\n" + '已部分退款');
                }
                if (row.dock_order_status === 6) {
                    content += ("\\n" + '已退款');
                }
    
                layer.open({
                    type: 1,
                    title: "对接单详情",
                    area: util.isPc() ? ['420px', '420px'] : ["100%", "100%"],
                    content: '<textarea class="layui-input" style="padding: 15px;height: 100%;">' + content + '</textarea>'
                });
            }
        },
        {
            icon: 'fa-duotone fa-regular fa-yen-sign',
            class: 'text-danger',
            title: '退款',
            show: _ => _?.status === 1 && _?.dock_order_status !== 5 && _?.dock_order_status !== 6,
            click: (event, value, row, index) => {
                let trade_no = row.trade_no ? row.trade_no : '';
                row.refund = row.amount;
                message.ask('您正在进行退款操作，是否继续？', () => {
                    component.popup({
                        submit: '/plugin/ThirdDockManage/api/order/refund',
                        tab: [
                            {
                                name: '退款',
                                form: [
                                    {title: "订单号", name: "trade_no", type: "input", hide: true},
                                    {title: "退款金额", name: "refund", type: "input", placeholder: "", required: true},
                                    {title: "对接单详情", name: "secret", type: "textarea"},
                                    {title: "订单总额", name: "amount", type: "input"}
                                ]
                            }
                        ],
                        row,
                        autoPosition: true,
                        height: "auto",
                        width: "420px",
                        done: () => {
                            table.refresh(true);
                        }
                    });
                });
            }
        },
        {
            icon: 'fa-duotone fa-regular fa-trash-can',
            class: 'text-badge-light-warning',
            title: '生成新订单号',
            show: _ => _?.dock_status === 1 || _?.dock_status === 4,
            click: (event, value, row, index) => {
                message.ask('生成新的订单号后，旧订单号将查询不了，可能会造成订单无法同步状态等，是否继续？', () => {
                    let loaderIndex = layer.load(2, {shade: ['0.3', '#fff']});
                    util.post('/plugin/ThirdDockManage/api/order/changeNumber', {order_id: row.id}, res => {
                        layer.close(loaderIndex);
                        message.success(res.msg);
                        table.refresh(true);
                    });
                });
            }
        }
    ]
}
JS;

        return (new Column("/admin/api/order/data", $code, "dock_order_status", "after"))->toArray();
    }

    #[Hook(point: \App\Consts\Hook::HACK_ROUTE_TABLE_SEARCH)]
    public function orderSearchDockStatus(): ?array
    {
        $route = Context::get(Base::ROUTE);
        if (!str_starts_with($route, "/admin")) {
            return null;
        }

        $code = <<<JS
{
   title: "对接状态", name: "equal-dock_status", type: "select", dict: [
       {"id":0,"name":"无需对接"},
       {"id":1,"name":"未对接"},
       {"id":2,"name":"已对接"},
       {"id":3,"name":"部分对接"},
       {"id":4,"name":"对接出错"}
   ]
}
JS;

        return (new Search("/admin/api/order/data", $code, "between-create_time", "after"))->toArray();
    }

    #[Hook(point: \App\Consts\Hook::HACK_ROUTE_TABLE_SEARCH)]
    public function orderSearchOrderStatus(): ?array
    {
        $route = Context::get(Base::ROUTE);
        if (!str_starts_with($route, "/admin")) {
            return null;
        }

        $code = <<<JS
{
   title: "对接订单状态", name: "equal-dock_order_status", type: "select", dict: [
       {"id": 0, "name": "待处理"},
       {"id": 1, "name": "正在处理"},
       {"id": 2, "name": "已完成"},
       {"id": 3, "name": "失败"},
       {"id": 4, "name": "部分失败"},
       {"id": 5, "name": "已部分退款"},
       {"id": 6, "name": "已退款"}
   ]
}
JS;

        return (new Search("/admin/api/order/data", $code, "equal-dock_status", "after"))->toArray();
    }

    #[Hook(point: \App\Consts\Hook::USER_API_INDEX_QUERY_SECRET)]
    public function indexQuerySecret($order)
    {
        $secret = $order->secret;
        $secret .= "\n--------------订单补充---------------\n";

        $dock_order_status_map = [
            0 => '待处理',
            1 => '正在处理',
            2 => '已完成',
            3 => '失败',
            4 => '部分失败',
            5 => '已部分退款',
            6 => '已退款',
        ];
        $secret .= '订单状态：'.$dock_order_status_map[$order->dock_order_status];
        $order->secret = $secret;
    }

    #[Hook(point: \App\Consts\Hook::USER_API_PURCHASE_RECORD_LIST)]
    public function purchaseRecordList(&$data)
    {
        $dock_order_status_map = [
            0 => '待处理',
            1 => '正在处理',
            2 => '已完成',
            3 => '失败',
            4 => '部分失败',
            5 => '已部分退款',
            6 => '已退款',
        ];
        if (count($data['list']) > 0) {
            foreach ($data['list'] as $k => $datum) {
                $secret = $datum['secret'];
                $secret .= "\n--------------订单补充---------------\n";
                $secret .= '订单状态：'.$dock_order_status_map[$datum['dock_order_status']];
                $data['list'][$k]['secret'] = $secret;
            }
        }
    }

    #[Hook(point: \App\Consts\Hook::SERVICE_SHOP_GET_ITEM_STOCK)]
    public function getItemStock($commodity, $race, $sku)
    {
        $g_id = $commodity->dock_g_id ?? null;
        if (!empty($g_id) && $g_id > 0) {
            $good = Goods::query()->find($g_id, ['id', 'stock', 'status']);
            if ($good) {
                if ($good->status == 1) {
                    $stock = $good->stock;
                    if (empty($stock)) {
                        $stock = 999;
                    }

                    return new Stock($stock);
                }
            }
        }
        return null;
    }
}
