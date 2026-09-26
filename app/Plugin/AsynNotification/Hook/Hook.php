<?php
declare (strict_types=1);

namespace App\Plugin\AsynNotification\Hook;

use App\Model\Commodity;
use App\Model\Order;
use App\Model\Pay;
use App\Model\User;
use App\Util\Http;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Kernel\Annotation\Plugin;
use Kernel\Plugin\Entity\Tab;

class Hook
{

    #[Plugin(state: Plugin::START)]
    public function installDb(): void
    {
        //判断字段是否存在，不存在则创建字段

        if (!Manager::schema()->hasColumn("commodity", "asyn_request_status")) {
            Manager::schema()->table("commodity", function (Blueprint $blueprint) {
                $blueprint->tinyInteger("asyn_request_status", false, true)
                    ->nullable(true)
                    ->default(0)
                    ->comment("api请求状态:0=关闭,1=启用");
            });
        }

        if (!Manager::schema()->hasColumn("commodity", "asyn_request_type")) {
            Manager::schema()->table("commodity", function (Blueprint $blueprint) {
                $blueprint->tinyInteger("asyn_request_type", false, true)
                    ->nullable(true)
                    ->default(0)
                    ->comment("api请求方式:0=POST,1=GET,2=JSON");
            });
        }

        //请求方法
        if (!Manager::schema()->hasColumn("commodity", "asyn_request_template")) {
            Manager::schema()->table("commodity", function (Blueprint $blueprint) {
                $blueprint->text("asyn_request_template")->nullable(true)->default(null);
            });
        }

        if (!Manager::schema()->hasColumn("commodity", "asyn_request_url")) {
            Manager::schema()->table("commodity", function (Blueprint $blueprint) {
                $blueprint->string("asyn_request_url", 64)
                    ->nullable(true)
                    ->default(null);
            });
        }
    }


    #[\Kernel\Annotation\Hook(point: \App\Consts\Hook::HACK_SUBMIT_TAB)]
    public function submit(): array
    {
        $code = <<<JS
                {
                    name: util.icon("fa-duotone fa-regular fa-webhook") + " API通知",
                    form: [
                        {
                            title: "状态",
                            name: "asyn_request_status",
                            type: "switch",
                            text: "启用",
                            tips: "启用API通知"
                        },
                        {
                            title: "请求方式",
                            name: "asyn_request_type",
                            type: "radio",
                            dict:  [
                                {id : 0, name : "POST"},
                                {id : 1, name : "GET"},
                                {id : 2, name : "JSON"}
                            ],
                            change : (form , val) => {
                                if (val == 1){
                                    form.hide("asyn_request_template");
                                }else{
                                    form.show("asyn_request_template");
                                }
                            },
                            complete: (form , val) => {
                                  form.triggerOtherPopupChange("asyn_request_type", val); 
                            }
                        },
                        {
                            title: "API地址",
                            name: "asyn_request_url",
                            type: "input",
                            placeholder: "API地址"
                        }, 
                        {
                            title: "内容模版",
                            name: "asyn_request_template",
                            type: "textarea",
                            placeholder: "内容模版",
                            height : 200,
                            tips : "各种变量请查看插件文档"
                        },
                    ]
                }
JS;
        return (new Tab("/admin/api/commodity/save", $code))->toArray();
    }


    /**
     * @param string $template
     * @param Commodity $commodity
     * @param Order $order
     * @param Pay $pay
     * @param User|null $user
     * @return string
     */
    public function buildTemplate(
        string    $template,
        Commodity $commodity,
        Order     $order,
        Pay       $pay,
        ?User     $user,
    ): string
    {

        preg_match_all('/\[([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)\]/', $template, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $full = $match[0]; // "[commodity.name]"
            $objName = $match[1]; // "commodity"
            $field = $match[2]; // "name"

            // 找对应对象
            $obj = match ($objName) {
                'commodity' => $commodity,
                'order' => $order,
                'pay' => $pay,
                'user' => $user,
                default => null,
            };

            $value = $obj?->{$field} ?? '';

            $template = str_replace($full, $value, $template);
        }

        return str_replace("&amp;", "&", $template);
    }


    #[\Kernel\Annotation\Hook(point: \App\Consts\Hook::USER_API_ORDER_PAY_AFTER)]
    public function notification(Commodity $commodity, Order $order, Pay $pay): void
    {
        if ($commodity?->asyn_request_status != 1 || empty($commodity?->asyn_request_url)) {
            return;
        }

        $config = \App\Util\Plugin::getConfig("AsynNotification");

        $user = null;
        if ($order?->owner > 0) {
            $user = User::query()->find($order?->owner);
        }

        $template = $this->buildTemplate($commodity->asyn_request_template, $commodity, $order, $pay, $user);
        $url = $this->buildTemplate($commodity->asyn_request_url, $commodity, $order, $pay, $user);


        $http = Http::make(["timeout" => 3.14]);
        $response = null;

        switch ($commodity->asyn_request_type) {
            case 0:
                $response = $http->post($url, [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded'
                    ],
                    "body" => $template
                ]);
                break;
            case 1:
                $response = $http->get($url);
                break;
            case 2:
                $response = $http->post([
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => $template
                ]);
                break;
        }


        if (!$response) {
            return;
        }

        if ($config['log'] == 1) {
            \App\Util\Plugin::log("AsynNotification", "[{$commodity->name}]交易完成，订单号：{$order->trade_no}，API通知地址：{$url}，通知内容：{$template}，通知完成，结果：{$response->getBody()->getContents()}");
        }
    }
}















