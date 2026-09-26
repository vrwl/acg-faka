<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Consts;

/**
 * wm_agg.dim 维度枚举 —— 面板所有 TOP-N 排行的支点。
 *
 * 写入分工（很重要，改动前先读懂）：
 *  - PV_INCREMENTAL 里的维度：pv 列由 IngestTask 增量累加（pv = pv + VALUES(pv)）
 *  - SESSION_BASED 里的维度：visits/uv/bounce 由 RollupTask 从 wm_session 全量重算
 *    （col = VALUES(col) 覆盖写），因此重跑多少次结果都一样，永不重复计数。
 * 两条写路径改的是不同列，互不干扰。
 */
interface Dim
{
    public const PAGE = 1;
    public const REFERER = 2;
    public const REF_HOST = 3;
    public const ENGINE = 4;
    public const LANDING = 5;
    public const EXIT = 6;
    public const BROWSER = 7;
    public const OS = 8;
    public const DEVICE = 9;
    public const SPIDER = 10;
    public const REGION = 11;
    public const HOUR = 12;
    public const LANG = 13;
    public const UTM_SOURCE = 14;
    public const UTM_MEDIUM = 15;
    public const UTM_CAMPAIGN = 16;
    public const KEYWORD = 17;
    public const STATUS = 18;
    public const REF_TYPE = 19;

    /** IngestTask 增量维护 pv 的维度 */
    public const PV_INCREMENTAL = [
        self::PAGE, self::REFERER, self::HOUR, self::STATUS,
        self::SPIDER, self::KEYWORD, self::LANG,
    ];

    /** RollupTask 从 wm_session 全量重算 visits/uv/bounce 的维度 */
    public const SESSION_BASED = [
        self::REF_HOST, self::ENGINE, self::LANDING, self::EXIT,
        self::BROWSER, self::OS, self::DEVICE, self::REGION, self::REF_TYPE,
        self::UTM_SOURCE, self::UTM_MEDIUM, self::UTM_CAMPAIGN,
    ];

    /** 维度 => [名称, key_id 指向的字典表] */
    public const META = [
        self::PAGE => ['页面', 'wm_page'],
        self::REFERER => ['来源网址', 'wm_referer'],
        self::REF_HOST => ['来源域名', 'wm_dict'],
        //既装搜索引擎也装社交平台（百度、Telegram…）。面板要单看搜索引擎时，
        //按会话的 ref_type=2 过滤即可；混在一起存反而更好用——站长真正关心的是「流量从哪个平台来」。
        self::ENGINE => ['来源平台', 'wm_dict'],
        self::LANDING => ['着陆页', 'wm_page'],
        self::EXIT => ['退出页', 'wm_page'],
        self::BROWSER => ['浏览器', 'wm_dict'],
        self::OS => ['操作系统', 'wm_dict'],
        self::DEVICE => ['设备', ''],
        self::SPIDER => ['蜘蛛', 'wm_spider'],
        self::REGION => ['地区', 'wm_region'],
        self::HOUR => ['时段', ''],
        self::LANG => ['语言', 'wm_dict'],
        self::UTM_SOURCE => ['UTM 来源', 'wm_dict'],
        self::UTM_MEDIUM => ['UTM 媒介', 'wm_dict'],
        self::UTM_CAMPAIGN => ['UTM 活动', 'wm_dict'],
        self::KEYWORD => ['搜索词', 'wm_dict'],
        self::STATUS => ['状态码', ''],
        self::REF_TYPE => ['来源类型', ''],
    ];
}
