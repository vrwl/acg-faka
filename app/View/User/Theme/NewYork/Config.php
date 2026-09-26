<?php
declare(strict_types=1);

namespace App\View\User\Theme\NewYork;

use App\Consts\Render;

interface Config
{
    const INFO = [
        "NAME" => "纽约",
        "AUTHOR" => "荔枝",
        "VERSION" => "2.0.6",
        "WEB_SITE" => "#",
        "DESCRIPTION" => "为手机购物与会员经营设计的纽约主题",
        'icon' => 'https://tencent.3rd.mcycdn.com//resource/icon/NewYork.png',
        "RENDER" => Render::ENGINE_SMARTY
    ];

    const SUBMIT = [
        [
            "title" => "色彩模式",
            "name" => "theme_mode",
            "type" => "radio",
            "dict" => [
                ["id" => "auto", "name" => "跟随系统"],
                ["id" => "light", "name" => "固定白天"],
                ["id" => "dark", "name" => "固定黑夜"]
            ],
            "default" => "auto"
        ],
        [
            "title" => "销量显示",
            "name" => "show_sold",
            "type" => "switch",
            "placeholder" => "显示|隐藏",
            "default" => "1"
        ],
        [
            "title" => "商城副标题",
            "name" => "store_subtitle",
            "type" => "input",
            "placeholder" => "显示在店铺名称下方，不填则用默认文案",
            "default" => "欢迎光临我们"
        ],
        [
            "title" => "默认弹窗公告",
            "name" => "notice_popup",
            "type" => "switch",
            "placeholder" => "开启|关闭",
            "default" => "0",
            "tips" => "开启后，访客打开网站时自动弹出店铺公告。访客点公告右上角的 ❌ 关闭后，30 分钟内不再自动弹出；没点 ❌（点空白处关掉、直接刷新或离开）下次打开还会弹。顶栏「公告」按钮随时可以手动打开，订单查询页不会弹。公告内容在「网站设置 → 店铺公告」里填写。"
        ]
    ];

    const THEME = [
        "INDEX" => "Index/Index.html",
        "ITEM" => "Index/Item.html",
        "QUERY" => "Index/Query.html",
        "CLOSED" => "Index/Closed.html",
        "LOGIN" => "Authentication/Login.html",
        "REGISTER" => "Authentication/Register.html",
        "FORGET_EMAIL" => "Authentication/ForgetEmail.html",
        "FORGET_PHONE" => "Authentication/ForgetPhone.html",
        "DASHBOARD" => "Dashboard/Index.html",
        "RECHARGE" => "User/Recharge.html",
        "BILL" => "User/Bill.html",
        "BUSINESS" => "User/Business.html",
        "CATEGORY" => "User/Category.html",
        "COMMODITY" => "User/Commodity.html",
        "CARD" => "User/Card.html",
        "COUPON" => "User/Coupon.html",
        "CASH" => "User/Cash.html",
        "CASH_RECORD" => "User/CashRecord.html",
        "PERSONAL" => "User/Personal.html",
        "EMAIL" => "User/Email.html",
        "PHONE" => "User/Phone.html",
        "PASSWORD" => "User/Password.html",
        "ORDER" => "User/Order.html",
        "PURCHASE_RECORD" => "User/PurchaseRecord.html",
        "MESSAGE" => "User/Message.html",
        "TICKET" => "User/Ticket.html",
        "TICKET_CREATE" => "User/TicketCreate.html",
        "TICKET_DETAIL" => "User/TicketDetail.html",
        "AGENT_MEMBER" => "Agent/Member.html",
        "AGENT_PROMOTE" => "Agent/Promote.html"
    ];
}
