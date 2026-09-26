<?php
declare(strict_types=1);

/**
 * 来源识别数据表：搜索引擎、社交平台、广告参数。
 *
 * search 里的 `q` 是该引擎在 URL 里放关键词的参数名。绝大多数主流引擎早就
 * 对 https 来源加密了搜索词（Google 从 2011 年就开始），所以关键词报表只会
 * 有零星数据 —— 这是行业现状，不是采集出了问题，面板上会如实说明。
 */
return [
    'search' => [
        'google' => ['name' => 'Google', 'host' => 'google.', 'q' => 'q'],
        'baidu' => ['name' => '百度', 'host' => 'baidu.com', 'q' => 'wd,word,kw'],
        'bing' => ['name' => 'Bing', 'host' => 'bing.com', 'q' => 'q'],
        'sogou' => ['name' => '搜狗', 'host' => 'sogou.com', 'q' => 'query,keyword'],
        'so360' => ['name' => '360 搜索', 'host' => 'so.com', 'q' => 'q'],
        'shenma' => ['name' => '神马搜索', 'host' => 'sm.cn,yz.m.sm.cn', 'q' => 'q'],
        'yandex' => ['name' => 'Yandex', 'host' => 'yandex.', 'q' => 'text'],
        'duckduckgo' => ['name' => 'DuckDuckGo', 'host' => 'duckduckgo.com', 'q' => 'q'],
        'yahoo' => ['name' => 'Yahoo', 'host' => 'yahoo.', 'q' => 'p'],
        'naver' => ['name' => 'Naver', 'host' => 'naver.com', 'q' => 'query'],
        'daum' => ['name' => 'Daum', 'host' => 'daum.net', 'q' => 'q'],
        'ecosia' => ['name' => 'Ecosia', 'host' => 'ecosia.org', 'q' => 'q'],
        'brave' => ['name' => 'Brave Search', 'host' => 'search.brave.com', 'q' => 'q'],
        'startpage' => ['name' => 'Startpage', 'host' => 'startpage.com', 'q' => 'query'],
        'toutiao' => ['name' => '头条搜索', 'host' => 'toutiao.com,so.toutiao.com', 'q' => 'keyword'],
        'quark' => ['name' => '夸克', 'host' => 'quark.sm.cn', 'q' => 'q'],
        'chatgpt' => ['name' => 'ChatGPT', 'host' => 'chatgpt.com,chat.openai.com', 'q' => ''],
        'perplexity' => ['name' => 'Perplexity', 'host' => 'perplexity.ai', 'q' => ''],
        'claude' => ['name' => 'Claude', 'host' => 'claude.ai', 'q' => ''],
    ],

    'social' => [
        'wechat' => ['name' => '微信', 'host' => 'weixin.qq.com,mp.weixin.qq.com,wx.qq.com'],
        'qq' => ['name' => 'QQ', 'host' => 'qq.com,url.cn'],
        'weibo' => ['name' => '微博', 'host' => 'weibo.com,weibo.cn,t.cn'],
        'douyin' => ['name' => '抖音', 'host' => 'douyin.com,iesdouyin.com'],
        'xiaohongshu' => ['name' => '小红书', 'host' => 'xiaohongshu.com,xhslink.com'],
        'bilibili' => ['name' => '哔哩哔哩', 'host' => 'bilibili.com,b23.tv'],
        'zhihu' => ['name' => '知乎', 'host' => 'zhihu.com,zhihu.com'],
        'tieba' => ['name' => '贴吧', 'host' => 'tieba.baidu.com'],
        'telegram' => ['name' => 'Telegram', 'host' => 't.me,telegram.org,telegram.me'],
        'twitter' => ['name' => 'X / Twitter', 'host' => 'twitter.com,x.com,t.co'],
        'facebook' => ['name' => 'Facebook', 'host' => 'facebook.com,fb.com,fb.me,l.facebook.com'],
        'instagram' => ['name' => 'Instagram', 'host' => 'instagram.com'],
        'youtube' => ['name' => 'YouTube', 'host' => 'youtube.com,youtu.be'],
        'reddit' => ['name' => 'Reddit', 'host' => 'reddit.com,redd.it'],
        'linkedin' => ['name' => 'LinkedIn', 'host' => 'linkedin.com,lnkd.in'],
        'discord' => ['name' => 'Discord', 'host' => 'discord.com,discord.gg'],
        'tiktok' => ['name' => 'TikTok', 'host' => 'tiktok.com'],
        'line' => ['name' => 'LINE', 'host' => 'line.me'],
        'whatsapp' => ['name' => 'WhatsApp', 'host' => 'whatsapp.com,wa.me'],
        'pinterest' => ['name' => 'Pinterest', 'host' => 'pinterest.com,pin.it'],
    ],

    /** 命中任一参数即判定为广告来源 */
    'ad_params' => ['gclid', 'fbclid', 'msclkid', 'yclid', 'ttclid', 'dclid', 'wbraid', 'gbraid', 'qhclickid', 'bd_vid'],

    /** UTM 与常见渠道参数 */
    'utm' => ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'],
];
