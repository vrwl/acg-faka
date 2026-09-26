<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Consts;

/**
 * 各类枚举值。这些数字会落库（wm_access.kind、wm_attack.kind、wm_rule.type…），
 * 只允许新增，不允许改动已有含义。
 */
interface Kind
{
    /* ── 请求类型 wm_access.kind ─────────────────────────── */
    public const REQ_PAGE = 0;
    public const REQ_API = 1;
    public const REQ_ADMIN = 2;
    public const REQ_OTHER = 3;
    public const REQ_404 = 4;
    public const REQ_WAF = 5;

    /* ── HTTP 方法 wm_access.method ──────────────────────── */
    public const M_GET = 1;
    public const M_POST = 2;
    public const M_HEAD = 3;
    public const M_OPTIONS = 4;
    public const M_PUT = 5;
    public const M_DELETE = 6;
    public const M_OTHER = 9;

    /* ── 设备 wm_access.dev（与 App\Util\Client::getDeviceTypeByUa 对齐）── */
    public const DEV_PC = 0;
    public const DEV_ANDROID = 1;
    public const DEV_IPHONE = 2;
    public const DEV_IPAD = 3;
    public const DEV_OTHER = 9;

    /* ── 采集行标记 wm_access.flags（位掩码）────────────── */
    public const F_NEW_VISITOR = 1;
    public const F_NEW_SESSION = 2;
    public const F_SPIDER = 4;
    public const F_ATTACK = 8;
    public const F_RULE_HIT = 16;
    public const F_DENIED = 32;
    public const F_SAMPLED = 64;
    public const F_MEMBER = 128;
    public const F_FINGERPRINT = 256;
    public const F_ADMIN_EXEMPT = 512;

    /* ── 攻击类型 wm_attack.kind ─────────────────────────── */
    public const ATK_WAF = 1;
    public const ATK_404_FLOOD = 2;
    public const ATK_CC = 3;
    public const ATK_LOGIN_BRUTE = 4;
    public const ATK_SCANNER = 5;
    public const ATK_FAKE_SPIDER = 6;
    public const ATK_BLACKLIST = 7;
    public const ATK_REGION = 8;
    public const ATK_SENSITIVE = 9;
    public const ATK_METHOD = 10;
    public const ATK_UPLOAD = 11;
    public const ATK_PROTOCOL = 12;

    /* ── 处置动作 wm_attack.act ──────────────────────────── */
    public const ACT_LOG = 0;
    public const ACT_SCORE = 1;
    public const ACT_BLOCK = 2;
    public const ACT_BAN = 3;
    public const ACT_THROTTLE = 4;

    /* ── 规则类型 wm_rule.type ───────────────────────────── */
    public const RULE_IP_DENY = 1;
    public const RULE_IP_ALLOW = 2;
    public const RULE_REGION_DENY = 3;
    public const RULE_REGION_ALLOW = 4;
    public const RULE_UA_DENY = 5;
    public const RULE_PATH_EXEMPT = 6;

    /* ── 来源类型 wm_referer.type ────────────────────────── */
    public const REF_DIRECT = 0;
    public const REF_INTERNAL = 1;
    public const REF_SEARCH = 2;
    public const REF_SOCIAL = 3;
    public const REF_LINK = 4;
    public const REF_AD = 5;

    /* ── wm_dict.type ────────────────────────────────────── */
    public const D_BROWSER = 1;
    public const D_OS = 2;
    public const D_ENGINE = 3;
    public const D_LANG = 4;
    public const D_SOCIAL = 5;
    public const D_UTM_SOURCE = 6;
    public const D_UTM_MEDIUM = 7;
    public const D_UTM_CAMPAIGN = 8;
    public const D_REF_HOST = 9;
    public const D_KEYWORD = 10;

    /* ── wm_seen.b 去重桶 ────────────────────────────────── */
    public const SEEN_VID_MIN = 1;
    public const SEEN_VID_HOUR = 2;
    public const SEEN_IP_MIN = 3;
    public const SEEN_IP_HOUR = 4;
    public const SEEN_DIM_DAY = 5;
    public const SEEN_ATTACK_EVIDENCE = 6;

    /* ── 蜘蛛分组 wm_spider.grp ──────────────────────────── */
    public const SP_SEARCH = 1;
    public const SP_SEO = 2;
    public const SP_AI = 3;
    public const SP_SOCIAL = 4;
    public const SP_MONITOR = 5;
    public const SP_SCANNER = 6;
    public const SP_OTHER = 9;

    /* ── 蜘蛛反查状态 wm_spider_ip.verified ─────────────── */
    public const SPV_PENDING = 0;
    public const SPV_REAL = 1;
    public const SPV_FAKE = 2;
    public const SPV_SKIP = 3;
}
