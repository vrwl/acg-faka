<?php
declare (strict_types=1);

return [
    'version' => '2.0.1',
    'name' => '支付宝-接口(个人挂机版)',
    'author' => '荔枝',
    'website' => '#',
    'icon' => 'https://tencent.3rd.mcycdn.com//resource/icon/AlipayPersonal.png',
    'description' => '依赖通用扩展：支付宝(个人挂机版)。收款账号在本页「配置」里管理，一套配置=一个支付宝收款账号=一台挂机手机，支持多套；挂机端 App 无需升级',
    'options' => [
        "qrcode" => '二维码'
    ],
    'callback' => [
        \App\Consts\Pay::IS_SIGN => true,
        \App\Consts\Pay::IS_STATUS => true,
        \App\Consts\Pay::FIELD_STATUS_KEY => 'status',
        \App\Consts\Pay::FIELD_STATUS_VALUE => 1,
        \App\Consts\Pay::FIELD_ORDER_KEY => 'trade_no',
        \App\Consts\Pay::FIELD_AMOUNT_KEY => 'amount',
        \App\Consts\Pay::FIELD_RESPONSE => 'success'
    ]
];