<?php
declare(strict_types=1);

namespace App\View\User\Theme\Seattle;

use App\Consts\Render;

interface Config
{
    const QUICK_ENTRY_TARGETS = [
        ["id" => "store", "name" => "商城首页"],
        ["id" => "query", "name" => "订单查询"],
        ["id" => "dashboard", "name" => "会员中心"],
        ["id" => "purchase", "name" => "购买记录"],
        ["id" => "recharge", "name" => "充值中心"],
        ["id" => "bill", "name" => "我的账单"],
        ["id" => "business", "name" => "我的店铺"],
        ["id" => "promote", "name" => "推广中心"],
        ["id" => "member", "name" => "我的下级"],
        ["id" => "login", "name" => "会员登录"],
        ["id" => "register", "name" => "会员注册"]
    ];

    //与 Assets/Css/Pages.css 的 .st-store-layout[data-st-rail="..."] 一一对应
    const CATEGORY_WIDTHS = [
        ["id" => "standard", "name" => "标准"],
        ["id" => "wide", "name" => "加宽"],
        ["id" => "wider", "name" => "最宽"]
    ];

    const QUICK_MENU_DEFAULT = '[{"name":"查询订单","icon":"receipt_long","audience":"all","link_type":"internal","target":"query","url":""},{"name":"会员中心","icon":"person","audience":"member","link_type":"internal","target":"dashboard","url":""},{"name":"会员登录","icon":"login","audience":"guest","link_type":"internal","target":"login","url":""}]';

    const INFO = [
        "NAME" => "西雅图",
        "AUTHOR" => "荔枝",
        "VERSION" => "2.0.8",
        "WEB_SITE" => "#",
        "DESCRIPTION" => "极致的简约，极致的体验",
        'icon' => 'https://tencent.3rd.mcycdn.com//resource/icon/Seattle.png',
        "RENDER" => Render::ENGINE_SMARTY,
        "MATERIAL_ICON_VERSION" => MaterialIcons::VERSION,
        "MATERIAL_ICON_NAMES" => MaterialIcons::NAMES
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
            "text" => "开启"
        ],
        [
            "title" => "分类栏宽度",
            "name" => "category_width",
            "type" => "radio",
            "dict" => self::CATEGORY_WIDTHS,
            "default" => "standard"
        ],
        [
            "title" => "快捷入口面板",
            "name" => "show_quick_menu",
            "type" => "switch",
            "placeholder" => "显示|隐藏",
            "default" => "1"
        ],
        [
            "title" => "购买提示面板",
            "name" => "show_buy_tips",
            "type" => "switch",
            "placeholder" => "显示|隐藏",
            "default" => "1"
        ],
        [
            "title" => "快捷入口标题",
            "name" => "quick_title",
            "type" => "input",
            "placeholder" => "显示在电脑端首页右侧",
            "default" => "快捷入口"
        ],
        [
            "title" => "快捷入口说明",
            "name" => "quick_subtitle",
            "type" => "input",
            "placeholder" => "显示在快捷入口标题下方",
            "default" => "常用服务"
        ],
        [
            "title" => "动态快捷菜单",
            "name" => "quick_menu",
            "type" => "custom",
            "default" => self::QUICK_MENU_DEFAULT
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
