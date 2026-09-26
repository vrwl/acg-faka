<?php
declare(strict_types=1);

namespace App\View\User\Theme\MountFuji;

use App\Consts\Render;


interface Config
{
    /**
     * 介绍信息
     */
    const INFO = [
        "NAME" => "富士山",
        "AUTHOR" => "荔枝",
        "VERSION" => "2.0.5",
        "WEB_SITE" => "#",
        "DESCRIPTION" => "富士山模版，会员中心专用，极致的简约优化",
        'icon' => 'https://tencent.3rd.mcycdn.com//resource/icon/MountFuji.png',
        "RENDER" => Render::ENGINE_SMARTY
    ];

    /**
     * 配置信息
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
        ]
    ];

    /**
     * 模板文件重定向，不需要修改的直接删除
     */
    const THEME = [
        "DASHBOARD" => "Dashboard/Index.html", //会员-个人主页
        "RECHARGE" => "User/Recharge.html", //会员-充值中心
        "BILL" => "User/Bill.html", //会员-我的账单
        "BUSINESS" => "User/Business.html", //会员-我的店铺
        "CATEGORY" => "User/Category.html", //会员-商品分类
        "COMMODITY" => "User/Commodity.html", //会员-我的商品
        "CARD" => "User/Card.html", //会员-卡密管理
        "COUPON" => "User/Coupon.html", //会员-优惠卷管理
        "CASH" => "User/Cash.html", //会员-硬币兑现
        "CASH_RECORD" => "User/CashRecord.html", //会员-兑现记录
        "PERSONAL" => "User/Personal.html", //会员-个人资料
        "EMAIL" => "User/Email.html", //会员-邮箱
        "PHONE" => "User/Phone.html", //会员-手机
        "PASSWORD" => "User/Password.html", //会员-密码设置
        "ORDER" => "User/Order.html", //会员-密码设置
        "PURCHASE_RECORD" => "User/PurchaseRecord.html", //会员-购买记录
        "TICKET" => "User/Ticket.html", //会员-我的工单
        "TICKET_CREATE" => "User/TicketCreate.html", //会员-创建工单
        "TICKET_DETAIL" => "User/TicketDetail.html", //会员-工单详情
        "MESSAGE" => "User/Message.html", //会员-消息中心
        "AGENT_PROMOTE" => "Agent/Promote.html", //推广代理-推广中心
        "AGENT_MEMBER" => "Agent/Member.html", //推广代理-我的下级
    ];
}
