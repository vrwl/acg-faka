<?php
declare (strict_types=1);

return [
    [
        "title" => "选择平台",
        "name" => "server",
        "type" => "select",
        "dict" => [
            ["id" => 'netease', "name" => "网易云音乐(建议配COOKIE)"],
            ["id" => 'tencent', "name" => "QQ音乐(需要COOKIE)"],
            ["id" => 'kugou', "name" => "酷狗音乐(免费曲目)"],
        ],
        "default" => 'netease',
        "placeholder" => "歌单所属的音乐平台",
    ],
    [
        "title" => "歌单ID",
        "name" => "playId",
        "type" => "input",
        "placeholder" => "平台歌单ID，如网易云歌单链接中 playlist?id= 后面的数字",
    ],
    [
        "title" => "自定义曲目",
        "name" => "custom",
        "type" => "textarea",
        "placeholder" => "每行一首，格式：歌名|歌手|音频URL|封面URL|歌词URL(可留空)。自定义曲目会排在歌单前面；只填这里、歌单ID留空也可以使用",
    ],
    [
        "title" => "COOKIE",
        "name" => "cookie",
        "type" => "textarea",
        "placeholder" => "选中平台的COOKIE，网易云必填(登录网页版后F12复制)，其它平台可留空",
    ],
    [
        "title" => "外部解析API",
        "name" => "api",
        "type" => "input",
        "placeholder" => "留空使用内置解析。如需外部Meting API可填地址",
    ],
    [
        "title" => "默认音质",
        "name" => "bitrate",
        "type" => "select",
        "dict" => [
            ["id" => '128', "name" => "流畅 128K"],
            ["id" => '192', "name" => "清晰 192K"],
            ["id" => '320', "name" => "高品 320K"],
        ],
        "default" => '320',
        "placeholder" => "请选择音质",
    ],
    [
        "title" => "自动播放",
        "name" => "autoplay",
        "type" => "switch",
        "text" => "开启",
    ],
    [
        "title" => "说明",
        "name" => "mp_tip_autoplay",
        "type" => "explain",
        "placeholder" => "浏览器策略限制：访客与页面产生过交互后才允许出声。开启后播放器会在受限时进入「轻触任意处继续播放」状态，跨页面浏览会自动断点续播。",
    ],
    [
        "title" => "默认循环",
        "name" => "order",
        "type" => "select",
        "dict" => [
            ["id" => 'list', "name" => "列表循环"],
            ["id" => 'single', "name" => "单曲循环"],
            ["id" => 'random', "name" => "随机播放"],
        ],
        "default" => 'list',
        "placeholder" => "访客也可以在播放器里自行切换",
    ],
    [
        "title" => "默认音量",
        "name" => "volume",
        "type" => "input",
        "default" => '0.7',
        "placeholder" => "0 ~ 1 之间，默认 0.7",
    ],
    [
        "title" => "歌词",
        "name" => "lrcType",
        "type" => "switch",
        "text" => "开启",
    ],
    [
        "title" => "歌词特效模式",
        "name" => "stage",
        "type" => "switch",
        "text" => "开启",
    ],
    [
        "title" => "说明",
        "name" => "mp_tip_stage",
        "type" => "explain",
        "placeholder" => "舞台级歌词特效：底部巨幕逐字入场并随进度渐染（卡拉OK填充）、两侧竖排词影漂移、屏幕四周氛围光晕与周界流光、上升光尘，配色跟随封面自动流转。特效层不响应鼠标、不影响页面操作；访客可在播放器面板里自行关闭；移动端自动精简。需先开启歌词。",
    ],
    [
        "title" => "停靠位置",
        "name" => "position",
        "type" => "select",
        "dict" => [
            ["id" => 'left', "name" => "左下角"],
            ["id" => 'right', "name" => "右下角"],
        ],
        "default" => 'left',
        "placeholder" => "播放器悬浮停靠的位置",
    ],
    [
        "title" => "玻璃色调",
        "name" => "tint",
        "type" => "select",
        "dict" => [
            ["id" => 'auto', "name" => "跟随站点明暗"],
            ["id" => 'light', "name" => "浅色玻璃"],
            ["id" => 'dark', "name" => "深色玻璃"],
        ],
        "default" => 'auto',
        "placeholder" => "液态玻璃的明暗基调",
    ],
    [
        "title" => "初始形态",
        "name" => "startState",
        "type" => "select",
        "dict" => [
            ["id" => 'dock', "name" => "悬浮胶囊"],
            ["id" => 'mini', "name" => "迷你圆珠"],
        ],
        "default" => 'dock',
        "placeholder" => "访客首次进入时播放器的形态（手机端固定默认迷你圆珠且可拖动；之后记住访客自己的选择）",
    ],
];
