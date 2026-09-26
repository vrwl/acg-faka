<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Hook;

use App\Controller\Base\View\ManagePlugin;
use App\Model\Commodity;
use App\Model\Order;
use App\Model\Pay;
use App\Plugin\WkDock\Model\Goods;
use App\Plugin\WkDock\Model\Sites;
use App\Plugin\WkDock\Traits\Help;
use App\Util\Plugin;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Kernel\Annotation\Hook;
use Kernel\Exception\ViewException;
use Kernel\Plugin\Entity\Tab;
use Kernel\Util\Context;
use Kernel\Consts\Base;

class Main extends ManagePlugin
{
    use Help;

    /**
     * 安装/更新数据库结构
     * @return void
     */
    private function InstallDB(): void
    {
        //站点表
        if (!Manager::schema()->hasTable('wk_sites')) {
            Manager::schema()->create('wk_sites', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 100)->default('')->comment('站点名称');
                $table->string('domain')->default('')->comment('站点地址');
                $table->string('account', 100)->default('')->comment('账号(uid)');
                $table->string('password')->default('')->comment('密钥(key)');
                $table->tinyInteger('status')->default(0)->comment('状态：0禁用 1启用');
                $table->decimal('balance', 15, 2)->default(0)->comment('余额');
                $table->decimal('rate', 8, 4)->default(1)->comment('价格倍率(成本×倍率)');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();
                $table->softDeletes();
            });
        }
        $siteDeleted = Manager::schema()->hasColumn('wk_sites', 'deleted_at');
        if (!$siteDeleted) {
            Manager::schema()->table('wk_sites', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        //商品池表（按 site_id + cid 去重）
        if (!Manager::schema()->hasTable('wk_goods')) {
            Manager::schema()->create('wk_goods', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('site_id')->default(0)->comment('站点ID');
                $table->string('cid', 100)->default('')->comment('平台商品ID');
                $table->string('name')->default('')->comment('商品/平台名称');
                $table->string('fenlei')->default('')->comment('分类');
                $table->decimal('price', 15, 2)->default(0)->comment('成本价');
                $table->longText('content')->nullable()->comment('详情');
                $table->tinyInteger('status')->default(0)->comment('平台状态：0下架 1上架');
                $table->timestamps();
                $table->unique(['site_id', 'cid']);
            });
        }

        //对接日志表
        if (!Manager::schema()->hasTable('wk_logs')) {
            Manager::schema()->create('wk_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('order_id')->default(0)->comment('订单ID');
                $table->string('trade_no', 26)->default('')->comment('订单号');
                $table->unsignedInteger('site_id')->default(0)->comment('站点ID');
                $table->text('uri')->nullable()->comment('请求地址');
                $table->text('parameter')->nullable()->comment('请求参数');
                $table->text('result')->nullable()->comment('返回结果');
                $table->text('remark')->nullable()->comment('备注');
                $table->timestamps();
            });
        }

        //commodity 扩展：关联商品池记录
        $wkGoodsId = Manager::schema()->hasColumn('commodity', 'wk_g_id');
        if (!$wkGoodsId) {
            Manager::schema()->table('commodity', function (Blueprint $table) {
                $table->unsignedBigInteger('wk_g_id')->default(0)->comment('网课对接商品ID');
            });
        }

        //order 扩展：对接状态与详情
        $wkStatus = Manager::schema()->hasColumn('order', 'wk_status');
        if (!$wkStatus) {
            Manager::schema()->table('order', function (Blueprint $table) {
                $table->unsignedTinyInteger('wk_status')->default(0)->comment('网课对接状态：0无需对接 1未对接 2已对接 3部分对接 4对接出错');
            });
        }
        $wkContent = Manager::schema()->hasColumn('order', 'wk_content');
        if (!$wkContent) {
            Manager::schema()->table('order', function (Blueprint $table) {
                $table->text('wk_content')->nullable()->comment('网课交单详情');
            });
        }
    }

    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::INSTALL)]
    public function Install(): void
    {
        $this->InstallDB();
    }

    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::START)]
    public function Start(): void
    {
        $this->InstallDB();
    }

    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::UPGRADE)]
    public function Update(): void
    {
        $this->InstallDB();
    }

    /**
     * 后台菜单
     * @return void
     * @throws ViewException
     */
    #[Hook(point: 0x3)]
    public function menu(): void
    {
        echo $this->render(null, "Menu.html");
    }

    /**
     * 商品编辑页「网课对接」页签
     * @return array|null
     */
    #[Hook(point: 0x9039)]
    public function commodityTab(): ?array
    {
        $route = Context::get(Base::ROUTE);
        if (!str_starts_with((string)$route, "/admin")) {
            return null;
        }

        //只读展示：这个页签不保存任何东西（核心的商品保存接口有字段白名单，wk_g_id 不在其中），
        //所以做成「看得懂的信息 + 明确说明不可改」，避免出现填了却不生效的假输入框
        $code = <<<'JS'
{
    name: util.icon("fa-duotone fa-regular fa-graduation-cap") + " 网课对接",
    form: [
        {
            title: "对接信息（只读）",
            name: "wk_link_info",
            type: "custom",
            complete: function (instance, el) {
                var assign = (instance && instance.opt && instance.opt.assign) || {};
                var commodityId = parseInt(assign.id, 10) || 0;
                var gId = parseInt(assign.wk_g_id, 10) || 0;
                var row = function (label, value) {
                    var line = jQuery("<div>").css({display: "flex", gap: "10px", lineHeight: "1.9", fontSize: "13px"});
                    jQuery("<span>").css({flex: "0 0 84px", opacity: ".6"}).text(label).appendTo(line);
                    jQuery("<span>").text(value || "—").appendTo(line);
                    return line;
                };
                var tip = function (text) {
                    return jQuery("<div>").css({marginTop: "10px", fontSize: "12px", opacity: ".65", lineHeight: "1.8"}).text(text);
                };
                el.empty();
                var card = jQuery("<div>").css({padding: "12px 14px", borderRadius: "10px", background: "rgba(127,127,127,.08)"}).appendTo(el);
                if (gId <= 0) {
                    card.append(jQuery("<div>").text("该商品未对接网课，买家下单后不会向网课平台交单。"));
                    el.append(tip("如需对接，请到「插件 → WkDock → 商品采集」勾选课程后生成商品。"));
                    return;
                }
                if (commodityId <= 0) {
                    card.append(jQuery("<div>").text("保存商品后才能查看对接信息。"));
                    return;
                }
                card.append(jQuery("<div>").text("加载中…"));
                util.post({
                    url: "/plugin/WkDock/api/good/link",
                    data: {id: commodityId},
                    loader: false,
                    done: function (res) {
                        var d = (res && res.data) || {};
                        card.empty();
                        if (parseInt(d.linked, 10) !== 1) {
                            card.append(jQuery("<div>").text("该商品未对接网课，买家下单后不会向网课平台交单。"));
                            return;
                        }
                        if (d.missing) {
                            card.append(row("对接状态", "对接记录已丢失"));
                            el.append(tip("对接的课程记录已被删除，买家下单后将无法交单。请到「插件 → WkDock → 商品采集」重新生成该商品，或删除此商品。"));
                            return;
                        }
                        card.append(row("上游站点", d.site_name ? d.site_name + (parseInt(d.site_status, 10) === 1 ? "（已启用）" : "（已禁用）") : "站点记录已丢失"));
                        card.append(row("对接课程", d.course_name));
                        card.append(row("平台商品ID", d.cid));
                        card.append(row("课程状态", parseInt(d.good_status, 10) === 1 ? "上架" : "下架"));
                        el.append(tip("此处仅供查看，不能修改。更换对接课程请到「插件 → WkDock → 商品采集」重新生成商品；删除课程或站点后，对接关系会自动解除。"));
                    },
                    error: function () {
                        card.empty();
                        card.append(jQuery("<div>").css({opacity: ".65"}).text("对接详情加载失败，请刷新页面重试。"));
                    }
                });
            }
        }
    ]
}
JS;

        return (new Tab("/admin/api/commodity/save", $code))->toArray();
    }

    /**
     * 订单支付成功后自动向网课平台交单
     * @param Commodity $commodity 商品
     * @param Order $order 订单
     * @param Pay $pay 支付记录
     * @return void
     */
    #[Hook(point: 0x18)]
    public function Order(Commodity $commodity, Order $order, Pay $pay): void
    {
        $gId = (int)($commodity->wk_g_id ?? 0);
        if ($gId <= 0) {
            return;
        }

        $good = Goods::query()->find($gId);
        if (!$good) {
            //商品池记录被删（或 wk_g_id 指错）时不能静默放过：否则买家付了钱看不到「进度」入口、
            //后台也没有任何标记，只能等客人投诉才发现
            $order->wk_status = 4;
            $order->wk_content = "对接商品不存在（wk_g_id={$gId}），可能已被删除";
            $order->save();
            Plugin::log('WkDock', "订单{$order->trade_no}交单失败：对接商品{$gId}不存在");
            return;
        }
        $site = Sites::withTrashed()->find($good->site_id);
        if (!$site || $site->status != 1) {
            $order->wk_status = 4;
            $order->wk_content = '对接站点不存在或已禁用';
            $order->save();
            Plugin::log('WkDock', "订单{$order->trade_no}交单失败：站点不存在或禁用");
            return;
        }

        //解析订单 widget 中的学校/账号/密码与下单课程
        $widget = json_decode((string)$order->widget, true) ?: [];
        $school = (string)($widget['school']['value'] ?? '');
        $user = (string)($widget['user']['value'] ?? '');
        $pass = (string)($widget['pass']['value'] ?? '');
        $courses = json_decode((string)($widget['courses']['value'] ?? ''), true);

        if (!is_array($courses) || count($courses) === 0 || $user === '' || $pass === '' || $school === '') {
            $order->wk_status = 4;
            $order->wk_content = '订单缺少学校/账号/密码或下单课程信息，无法交单';
            $order->save();
            Plugin::log('WkDock', "订单{$order->trade_no}交单失败：信息不完整");
            return;
        }

        //循环交单
        $success = 0;
        $failMsg = [];
        $kcids = [];
        $kcnames = [];
        foreach ($courses as $course) {
            $kcid = (string)($course['id'] ?? '');
            $kcname = (string)($course['name'] ?? '');
            if ($kcid === '' || $kcname === '') {
                continue;
            }
            $kcids[] = $kcid;
            $kcnames[] = $kcname;
        }
        if (count($kcids) === 0) {
            $order->wk_status = 4;
            $order->wk_content = '下单课程信息为空，无法交单';
            $order->save();
            Plugin::log('WkDock', "订单{$order->trade_no}交单失败：课程信息为空");
            return;
        }

        //进入交单循环前先落库「未对接(1)」：交单是在支付回调里同步串行做的（每门课最长等 30 秒），
        //中途被 nginx/网关掐断时不至于变成静默卡单——至少前台会出现「进度」入口、后台能按状态查到
        $order->wk_status = 1;
        $order->save();

        $total = count($kcids);
        foreach ($kcids as $i => $kcid) {
            $kcname = $kcnames[$i] ?? '';
            $res = $this->submitOrder($site, (string)$good->cid, $school, $user, $pass, $kcname, (string)$kcid);
            $this->writeLog((int)$order->id, (int)$site->id, 'add', [
                'platform' => (string)$good->cid,
                'school' => $school,
                'user' => $user,
                'kcname' => $kcname,
                'kcid' => $kcid,
            ], ['uri' => $site->domain . '/api.php?act=add', 'result' => $res], "订单{$order->trade_no}交单");
            if ($res['code'] === 1) {
                $success++;
            } else {
                $failMsg[] = "{$kcname}:{$res['msg']}";
            }
        }

        $detail = "已提交 {$success}/{$total} 门课程："
            . implode('、', $kcnames)
            . "（学校：{$school}）";

        if ($success == $total) {
            //全部成功
            $order->wk_status = 2;
            $order->wk_content = $detail;
            $order->status = 1;
            $order->delivery_status = 1;
            $order->secret = "课程已提交至平台处理\n{$detail}\n可联系客服查询进度";
            //回写课程名，便于后台查看
            $widget['kcname'] = ['value' => implode(',', $kcnames), 'cn' => '课程'];
            $order->widget = json_encode($widget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $order->save();
            Plugin::log('WkDock', "订单{$order->trade_no}交单成功：{$detail}");
        } elseif ($success > 0) {
            //部分成功
            $order->wk_status = 3;
            $order->wk_content = $detail . "\n失败详情：" . implode('；', $failMsg);
            $order->save();
            Plugin::log('WkDock', "订单{$order->trade_no}部分交单：{$detail}，失败：" . implode('；', $failMsg));
        } else {
            //全部失败
            $order->wk_status = 4;
            $order->wk_content = '交单失败：' . implode('；', $failMsg);
            $order->save();
            Plugin::log('WkDock', "订单{$order->trade_no}交单失败：" . implode('；', $failMsg));
        }
    }
}
