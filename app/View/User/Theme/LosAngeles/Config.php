<?php
declare(strict_types=1);

namespace App\View\User\Theme\LosAngeles;

use App\Consts\Render;

/**
 * 洛杉矶(Los Angeles) —— 超大型综合商城模板
 *
 * 设计语言：落日海岸 Sunset
 *   珊瑚橙 #FF5A36 → 琥珀 #FFB020 的落日渐变为主轴，
 *   洋红 #FF3B77 与太平洋蓝 #0B5FFF 为双辅助色；
 *   浅色是暖白 #FFFCFA 的海岸午后，深色是深靛 #0C1020 的太平洋夜。
 *
 * 形态：PC 与手机两套完全独立的模板 / 样式 / 脚本，
 *      由 THEME 映射到的薄路由按 Client::isMobile() 分流，
 *      手机端是原生 APP 外壳（顶栏 + 底部 TabBar + 转场 + 下拉刷新 + 半屏 Sheet），
 *      不是把桌面 DOM 用媒体查询压小。
 */
interface Config
{
    const INFO = [
        "NAME" => "洛杉矶",
        "AUTHOR" => "荔枝",
        "VERSION" => "1.0.3",
        "WEB_SITE" => "#",
        'icon' => 'https://tencent.3rd.mcycdn.com//resource/20260911/2e9e6b0771610027.png',
        "DESCRIPTION" => "超大型综合商城模板 · 落日海岸设计语言 · 电脑端与手机端完全分离（手机端为原生 APP 交互）· 明暗三态 · 多语言",
        "RENDER" => Render::ENGINE_SMARTY,
        //后台配置面板的图标选择器数据源（Submit.js 从 values.info 读回）
        "ICON_NAMES" => Icons::NAMES
    ];

    /**
     * 静态兜底表单。
     * 实际后台渲染的是 Submit.js（存在时整体替换本常量），
     * 这里保留一份最小可用集合，保证 Submit.js 缺失时仍可配置核心项。
     */
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
            "title" => "主题强调色",
            "name" => "accent",
            "type" => "radio",
            "dict" => [
                ["id" => "sunset", "name" => "落日珊瑚（默认）"],
                ["id" => "magenta", "name" => "霓虹洋红"],
                ["id" => "pacific", "name" => "太平洋蓝"],
                ["id" => "palm", "name" => "棕榈翠"],
                ["id" => "noir", "name" => "午夜石墨"]
            ],
            "default" => "sunset"
        ],
        [
            "title" => "商品卡密度",
            "name" => "density",
            "type" => "radio",
            "dict" => [
                ["id" => "cozy", "name" => "舒适"],
                ["id" => "compact", "name" => "紧凑（每屏更多商品）"]
            ],
            "default" => "cozy"
        ],
        [
            "title" => "显示销量",
            "name" => "show_sold",
            "type" => "switch",
            "text" => "显示|隐藏",
            "default" => "1"
        ],
        [
            "title" => "显示库存",
            "name" => "show_stock",
            "type" => "switch",
            "text" => "显示|隐藏",
            "default" => "1"
        ],
        [
            "title" => "显示所属店铺",
            "name" => "show_owner",
            "type" => "switch",
            "text" => "显示|隐藏",
            "default" => "1"
        ],
        [
            "title" => "未登录时展示会员价对比",
            "name" => "show_member_price",
            "type" => "switch",
            "text" => "显示|隐藏",
            "default" => "1"
        ],
        [
            "title" => "秒杀专区",
            "name" => "seckill_on",
            "type" => "switch",
            "text" => "开启|关闭",
            "default" => "1"
        ],
        [
            "title" => "顶部滚动公告",
            "name" => "topbar_notice",
            "type" => "input",
            "placeholder" => "留空则不显示顶部细条公告"
        ],
        [
            "title" => "首页楼层",
            "name" => "floors",
            "type" => "input",
            "placeholder" => 'JSON 数组，如 [{"type":"seckill","on":1},{"type":"recommend","on":1},{"type":"category","on":1},{"type":"waterfall","on":1}]'
        ],
        [
            "title" => "轮播图",
            "name" => "banners",
            "type" => "input",
            "placeholder" => 'JSON 数组，如 [{"image":"/x.jpg","url":"/item/1","title":"标题","sub":"副标题"}]'
        ],
        [
            "title" => "金刚区快捷入口",
            "name" => "shortcuts",
            "type" => "input",
            "placeholder" => 'JSON 数组，如 [{"name":"订单查询","icon":"receipt","url":"/user/index/query","audience":"all"}]'
        ],
        [
            "title" => "手机端底部导航",
            "name" => "tabbar",
            "type" => "input",
            "placeholder" => 'JSON 数组（最多 5 项），如 [{"name":"首页","icon":"home","url":"/"}]'
        ],
        [
            "title" => "页脚栏目",
            "name" => "footer_groups",
            "type" => "input",
            "placeholder" => 'JSON 数组，如 [{"title":"帮助中心","links":[{"name":"订单查询","url":"/user/index/query"}]}]'
        ],
        [
            "title" => "页脚版权 / 备案号",
            "name" => "footer_note",
            "type" => "input",
            "placeholder" => "显示在页脚最底部，可留空"
        ]
    ];

    const THEME = [
        //商城（Client::isMobile() 决定走 Pc/ 还是 App/，由下列薄路由内部分流）
        "INDEX" => "Index/Index.html",
        "ITEM" => "Index/Item.html",
        "QUERY" => "Index/Query.html",
        "CLOSED" => "Index/Closed.html",
        //认证
        "LOGIN" => "Authentication/Login.html",
        "REGISTER" => "Authentication/Register.html",
        "FORGET_EMAIL" => "Authentication/ForgetEmail.html",
        "FORGET_PHONE" => "Authentication/ForgetPhone.html",
        //会员中心
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
        //分销
        "AGENT_MEMBER" => "Agent/Member.html",
        "AGENT_PROMOTE" => "Agent/Promote.html"
    ];
}
