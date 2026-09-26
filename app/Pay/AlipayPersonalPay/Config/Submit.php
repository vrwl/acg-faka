<?php
declare (strict_types=1);

/**
 * 静态兜底表单：新内核优先用同目录的 Submit.js（带挂机端配置指引），
 * 这份给不认 Submit.js 的老内核用，字段名完全一致。
 *
 * 一套配置 = 一个支付宝收款账号 = 一台挂机手机。
 */
return [
    [
        "title" => "挂机 Token",
        "name" => "app_key",
        "type" => "input",
        "placeholder" => "32 位字母+数字，填进手机 App 的 TOKEN 一栏",
        "tips" => "手机挂机端用它给上报签名，本站据此认出是哪台设备收的款。每套配置请用不同的 Token。"
    ],
    [
        "title" => "收款链接（可选）",
        "name" => "manual_url",
        "type" => "input",
        "placeholder" => "https://qr.alipay.com/...",
        "tips" => "只有当收款码图片识别不出来时才需要填。填了就以它为准，不再解析图片。"
    ],
    [
        "title" => "支付宝收款码",
        "name" => "qrcode",
        "type" => "image",
        "placeholder" => "上传支付宝收款码图片",
        "width" => 100
    ]
];
