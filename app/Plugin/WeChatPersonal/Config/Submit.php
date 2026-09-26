<?php
declare (strict_types=1);

/**
 * 通用插件只留全局引擎参数。
 * 收款账号（Token / 收款码 / 手机号）在「支付插件 → 微信支付-接口(个人挂机版) → 配置」里，
 * 一套配置对应一台挂机手机，支持多套。
 */
return [
    [
        "title" => "订单有效期",
        "name" => "expire_minutes",
        "type" => "input",
        "placeholder" => "分钟，默认 5",
        "default" => 5,
        "tips" => "买家要在这个时间内付款，挂机端也要在这个窗口内把到账报回来。可填 2 ~ 30。"
    ]
];
