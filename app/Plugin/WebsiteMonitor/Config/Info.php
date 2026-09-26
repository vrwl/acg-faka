<?php
declare(strict_types=1);

use App\Consts\Plugin;

return [
    Plugin::NAME => '网站监控统计',
    Plugin::AUTHOR => '荔枝',
    Plugin::WEB_SITE => '#',
    Plugin::DESCRIPTION => '实时流量监控（PV / UV / IP / 蜘蛛）、来源与地区分析、IP 与地区黑白名单，'
        . '以及针对本程序深度定制的安全防火墙（SQL 注入 / CC 攻击 / 扫描爆破）。'
        . '每请求零阻塞查询，统计与防护互不拖累。'
        . '<a href="/plugin/WebsiteMonitor/panel/index" target="_blank">打开监控面板</a>',
        'icon' => 'https://tencent.3rd.mcycdn.com/resource/icon/WebsiteMonitor.png',
    Plugin::VERSION => '1.0.1',
];
