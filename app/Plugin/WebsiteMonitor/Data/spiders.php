<?php
declare(strict_types=1);

use App\Plugin\WebsiteMonitor\Consts\Kind;

/**
 * 内置蜘蛛签名种子。安装/升级时按 code 合并入库（builtin=1 的行会被更新，
 * 站长手工新增或改过的不会被覆盖）。
 *
 * pattern  UA 转小写后的匹配关键词，多个用 | 分隔，会被编译进一条合并正则。
 *          写最有辨识度的那一段，别写太宽泛的词（比如别单独写 "bot"）。
 * verify   反查域名后缀，逗号分隔。填了就会做 PTR + 正向确认双向校验，
 *          伪装成该蜘蛛的 IP 会被判定为伪蜘蛛并计入攻击。留空表示无法验证。
 */
return [
    /* ── 搜索引擎 ─────────────────────────────────────────── */
    ['code' => 'google', 'name' => 'Google', 'grp' => Kind::SP_SEARCH, 'sort' => 1,
        'pattern' => 'googlebot|google-inspectiontool|storebot-google|googleother',
        'verify' => '.googlebot.com,.google.com,.googleusercontent.com', 'icon' => 'google'],
    ['code' => 'bing', 'name' => 'Bing', 'grp' => Kind::SP_SEARCH, 'sort' => 2,
        'pattern' => 'bingbot|adidxbot|bingpreview', 'verify' => '.search.msn.com', 'icon' => 'bing'],
    ['code' => 'baidu', 'name' => '百度', 'grp' => Kind::SP_SEARCH, 'sort' => 3,
        'pattern' => 'baiduspider', 'verify' => '.baidu.com,.baidu.jp', 'icon' => 'baidu'],
    ['code' => 'sogou', 'name' => '搜狗', 'grp' => Kind::SP_SEARCH, 'sort' => 4,
        'pattern' => 'sogou web spider|sogou inst spider|sogou pic spider', 'verify' => '.sogou.com', 'icon' => 'sogou'],
    ['code' => 'so360', 'name' => '360 搜索', 'grp' => Kind::SP_SEARCH, 'sort' => 5,
        'pattern' => '360spider|haosouspider|360se', 'verify' => '.360.cn,.so.com', 'icon' => '360'],
    ['code' => 'shenma', 'name' => '神马搜索', 'grp' => Kind::SP_SEARCH, 'sort' => 6,
        'pattern' => 'yisouspider', 'verify' => '.sm.cn', 'icon' => 'shenma'],
    ['code' => 'yandex', 'name' => 'Yandex', 'grp' => Kind::SP_SEARCH, 'sort' => 7,
        'pattern' => 'yandexbot|yandexmobilebot|yandeximages', 'verify' => '.yandex.ru,.yandex.net,.yandex.com', 'icon' => 'yandex'],
    ['code' => 'petal', 'name' => '华为花瓣', 'grp' => Kind::SP_SEARCH, 'sort' => 8,
        'pattern' => 'petalbot|aspiegelbot', 'verify' => '.petalsearch.com,.aspiegel.com', 'icon' => 'petal'],
    ['code' => 'apple', 'name' => 'Applebot', 'grp' => Kind::SP_SEARCH, 'sort' => 9,
        'pattern' => 'applebot', 'verify' => '.applebot.apple.com', 'icon' => 'apple'],
    ['code' => 'duckduckgo', 'name' => 'DuckDuckGo', 'grp' => Kind::SP_SEARCH, 'sort' => 10,
        'pattern' => 'duckduckbot|duckduckgo-favicons-bot', 'verify' => '.duckduckgo.com', 'icon' => 'duckduckgo'],
    ['code' => 'naver', 'name' => 'Naver', 'grp' => Kind::SP_SEARCH, 'sort' => 11,
        'pattern' => 'naver.me/bot|yeti/', 'verify' => '.naver.com', 'icon' => 'naver'],
    ['code' => 'seznam', 'name' => 'Seznam', 'grp' => Kind::SP_SEARCH, 'sort' => 12,
        'pattern' => 'seznambot', 'verify' => '.seznam.cz', 'icon' => 'seznam'],
    ['code' => 'coccoc', 'name' => 'Cốc Cốc', 'grp' => Kind::SP_SEARCH, 'sort' => 13,
        'pattern' => 'coccocbot', 'verify' => '.coccoc.com', 'icon' => ''],

    /* ── SEO 工具 ─────────────────────────────────────────── */
    ['code' => 'semrush', 'name' => 'Semrush', 'grp' => Kind::SP_SEO, 'sort' => 30,
        'pattern' => 'semrushbot', 'verify' => '', 'icon' => ''],
    ['code' => 'ahrefs', 'name' => 'Ahrefs', 'grp' => Kind::SP_SEO, 'sort' => 31,
        'pattern' => 'ahrefsbot|ahrefssiteaudit', 'verify' => '', 'icon' => ''],
    ['code' => 'mj12', 'name' => 'Majestic', 'grp' => Kind::SP_SEO, 'sort' => 32,
        'pattern' => 'mj12bot', 'verify' => '', 'icon' => ''],
    ['code' => 'dotbot', 'name' => 'Moz DotBot', 'grp' => Kind::SP_SEO, 'sort' => 33,
        'pattern' => 'dotbot|rogerbot', 'verify' => '', 'icon' => ''],
    ['code' => 'dataforseo', 'name' => 'DataForSEO', 'grp' => Kind::SP_SEO, 'sort' => 34,
        'pattern' => 'dataforseobot', 'verify' => '', 'icon' => ''],
    ['code' => 'blex', 'name' => 'BLEXBot', 'grp' => Kind::SP_SEO, 'sort' => 35,
        'pattern' => 'blexbot', 'verify' => '', 'icon' => ''],
    ['code' => 'screamingfrog', 'name' => 'Screaming Frog', 'grp' => Kind::SP_SEO, 'sort' => 36,
        'pattern' => 'screaming frog seo spider', 'verify' => '', 'icon' => ''],
    ['code' => 'serpstat', 'name' => 'Serpstat', 'grp' => Kind::SP_SEO, 'sort' => 37,
        'pattern' => 'serpstatbot', 'verify' => '', 'icon' => ''],

    /* ── AI / 大模型 ──────────────────────────────────────── */
    ['code' => 'openai', 'name' => 'OpenAI', 'grp' => Kind::SP_AI, 'sort' => 50,
        'pattern' => 'gptbot|oai-searchbot|chatgpt-user', 'verify' => '', 'icon' => 'openai'],
    ['code' => 'anthropic', 'name' => 'Anthropic', 'grp' => Kind::SP_AI, 'sort' => 51,
        'pattern' => 'claudebot|claude-web|anthropic-ai|claude-searchbot|claude-user', 'verify' => '', 'icon' => 'anthropic'],
    ['code' => 'commoncrawl', 'name' => 'Common Crawl', 'grp' => Kind::SP_AI, 'sort' => 52,
        'pattern' => 'ccbot', 'verify' => '', 'icon' => ''],
    ['code' => 'perplexity', 'name' => 'Perplexity', 'grp' => Kind::SP_AI, 'sort' => 53,
        'pattern' => 'perplexitybot|perplexity-user', 'verify' => '', 'icon' => ''],
    ['code' => 'google_extended', 'name' => 'Google AI', 'grp' => Kind::SP_AI, 'sort' => 54,
        'pattern' => 'google-extended', 'verify' => '', 'icon' => 'google'],
    ['code' => 'bytespider', 'name' => '字节跳动', 'grp' => Kind::SP_AI, 'sort' => 55,
        'pattern' => 'bytespider|toutiaospider', 'verify' => '', 'icon' => 'bytedance'],
    ['code' => 'amazonbot', 'name' => 'Amazon', 'grp' => Kind::SP_AI, 'sort' => 56,
        'pattern' => 'amazonbot', 'verify' => '', 'icon' => 'amazon'],
    ['code' => 'meta_ai', 'name' => 'Meta AI', 'grp' => Kind::SP_AI, 'sort' => 57,
        'pattern' => 'meta-externalagent|facebookbot|meta-externalfetcher', 'verify' => '', 'icon' => 'meta'],
    ['code' => 'mistral', 'name' => 'Mistral', 'grp' => Kind::SP_AI, 'sort' => 58,
        'pattern' => 'mistralai-user', 'verify' => '', 'icon' => ''],
    ['code' => 'cohere', 'name' => 'Cohere', 'grp' => Kind::SP_AI, 'sort' => 59,
        'pattern' => 'cohere-ai|cohere-training-data-crawler', 'verify' => '', 'icon' => ''],
    ['code' => 'timpi', 'name' => 'Timpi', 'grp' => Kind::SP_AI, 'sort' => 60,
        'pattern' => 'timpibot', 'verify' => '', 'icon' => ''],

    /* ── 社交 / 预览抓取 ──────────────────────────────────── */
    ['code' => 'facebook', 'name' => 'Facebook', 'grp' => Kind::SP_SOCIAL, 'sort' => 70,
        'pattern' => 'facebookexternalhit', 'verify' => '', 'icon' => 'facebook'],
    ['code' => 'twitter', 'name' => 'X / Twitter', 'grp' => Kind::SP_SOCIAL, 'sort' => 71,
        'pattern' => 'twitterbot', 'verify' => '', 'icon' => 'twitter'],
    ['code' => 'telegram', 'name' => 'Telegram', 'grp' => Kind::SP_SOCIAL, 'sort' => 72,
        'pattern' => 'telegrambot', 'verify' => '', 'icon' => 'telegram'],
    ['code' => 'whatsapp', 'name' => 'WhatsApp', 'grp' => Kind::SP_SOCIAL, 'sort' => 73,
        'pattern' => 'whatsapp', 'verify' => '', 'icon' => 'whatsapp'],
    ['code' => 'linkedin', 'name' => 'LinkedIn', 'grp' => Kind::SP_SOCIAL, 'sort' => 74,
        'pattern' => 'linkedinbot', 'verify' => '', 'icon' => 'linkedin'],
    ['code' => 'discord', 'name' => 'Discord', 'grp' => Kind::SP_SOCIAL, 'sort' => 75,
        'pattern' => 'discordbot', 'verify' => '', 'icon' => 'discord'],
    ['code' => 'slack', 'name' => 'Slack', 'grp' => Kind::SP_SOCIAL, 'sort' => 76,
        'pattern' => 'slackbot|slack-imgproxy', 'verify' => '', 'icon' => 'slack'],
    ['code' => 'pinterest', 'name' => 'Pinterest', 'grp' => Kind::SP_SOCIAL, 'sort' => 77,
        'pattern' => 'pinterest', 'verify' => '', 'icon' => 'pinterest'],
    ['code' => 'embedly', 'name' => 'Embedly', 'grp' => Kind::SP_SOCIAL, 'sort' => 78,
        'pattern' => 'embedly', 'verify' => '', 'icon' => ''],
    ['code' => 'qqbot', 'name' => 'QQ / 微信', 'grp' => Kind::SP_SOCIAL, 'sort' => 79,
        'pattern' => 'qqbrowserbot|wechat-bot|mqqbrowser bot', 'verify' => '', 'icon' => 'qq'],

    /* ── 监控 / 拨测 ──────────────────────────────────────── */
    ['code' => 'uptimerobot', 'name' => 'UptimeRobot', 'grp' => Kind::SP_MONITOR, 'sort' => 90,
        'pattern' => 'uptimerobot', 'verify' => '', 'icon' => ''],
    ['code' => 'pingdom', 'name' => 'Pingdom', 'grp' => Kind::SP_MONITOR, 'sort' => 91,
        'pattern' => 'pingdom', 'verify' => '', 'icon' => ''],
    ['code' => 'statuscake', 'name' => 'StatusCake', 'grp' => Kind::SP_MONITOR, 'sort' => 92,
        'pattern' => 'statuscake', 'verify' => '', 'icon' => ''],
    ['code' => 'betteruptime', 'name' => 'Better Uptime', 'grp' => Kind::SP_MONITOR, 'sort' => 93,
        'pattern' => 'betteruptime|better uptime', 'verify' => '', 'icon' => ''],
    ['code' => 'cloudmonitor', 'name' => '云拨测', 'grp' => Kind::SP_MONITOR, 'sort' => 94,
        'pattern' => 'aliyun-monitor|tencent-cloud-monitor|cloudmonitor', 'verify' => '', 'icon' => ''],
    ['code' => 'googlepagespeed', 'name' => 'PageSpeed', 'grp' => Kind::SP_MONITOR, 'sort' => 95,
        'pattern' => 'chrome-lighthouse|google page speed', 'verify' => '', 'icon' => 'google'],

    /* ── 安全扫描（默认计入攻击维度）───────────────────────── */
    ['code' => 'censys', 'name' => 'Censys', 'grp' => Kind::SP_SCANNER, 'sort' => 110,
        'pattern' => 'censysinspect', 'verify' => '', 'icon' => ''],
    ['code' => 'zgrab', 'name' => 'zgrab', 'grp' => Kind::SP_SCANNER, 'sort' => 111,
        'pattern' => 'zgrab', 'verify' => '', 'icon' => ''],
    ['code' => 'nuclei', 'name' => 'Nuclei', 'grp' => Kind::SP_SCANNER, 'sort' => 112,
        'pattern' => 'nuclei', 'verify' => '', 'icon' => ''],
    ['code' => 'masscan', 'name' => 'masscan', 'grp' => Kind::SP_SCANNER, 'sort' => 113,
        'pattern' => 'masscan', 'verify' => '', 'icon' => ''],
    ['code' => 'l9explore', 'name' => 'l9explore', 'grp' => Kind::SP_SCANNER, 'sort' => 114,
        'pattern' => 'l9explore|l9tcpid', 'verify' => '', 'icon' => ''],
    ['code' => 'expanse', 'name' => 'Expanse', 'grp' => Kind::SP_SCANNER, 'sort' => 115,
        'pattern' => 'expanse, a palo alto networks company', 'verify' => '', 'icon' => ''],
    ['code' => 'internetmeasurement', 'name' => 'InternetMeasurement', 'grp' => Kind::SP_SCANNER, 'sort' => 116,
        'pattern' => 'internetmeasurement', 'verify' => '', 'icon' => ''],
];
