<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

use App\Util\Plugin;

/**
 * 插件配置的唯一读取入口：默认值 + 类型规范化。
 *
 * Config/Config.php 里的值全是字符串，这里负责变成 bool/int/list 并补默认。
 * 守护进程每轮任务开始时调 refresh()，避免读到陈旧配置。
 *
 * 注意两个同名易混概念，它们是**独立**的：
 *   waf_mode     observe | protect | strict   —— 防火墙姿态（拦不拦）
 *   collect_mode full | lean | off            —— 采集姿态（写多细），存在 state.php 而非这里
 */
final class Settings
{
    public const PLUGIN = 'WebsiteMonitor';

    /**
     * 内置 IP 库地址。作者自维护、每天更新的镜像。
     *
     * **不要把它写进 DEFAULTS，也不要出现在 Config/Submit.js 里** —— 前者会让配置弹窗
     * 把地址原样填进输入框展示给每一个站长，后者更糟（前端 JS 谁都能直接看）。
     * 配置留空时才在服务端解析成这个地址，站长要用自己的镜像就自己填。
     */
    private const GEO_DEFAULT_URL = 'https://downloadgeoip.acged.cc/GeoLite2-City.mmdb';

    /**
     * 实际使用的 IP 库地址：站长填了就用站长的，没填用内置的。
     */
    public static function geoUrl(): string
    {
        $url = trim((string)self::get('geo_db_url'));
        return $url !== '' ? $url : self::GEO_DEFAULT_URL;
    }

    /**
     * 站长填的地址如果就是内置源，存空值。
     *
     * 早期版本把内置地址当默认值渲染进输入框，站长一保存就落库了；不归一化的话
     * 那个地址会永远显示在配置弹窗里，本次改动对他们就等于没生效。
     */
    public static function normalizeGeoUrl(string $url): string
    {
        $url = trim($url);
        return strcasecmp($url, self::GEO_DEFAULT_URL) === 0 ? '' : $url;
    }

    /**
     * 把内置源从要外露的文本里抹掉。
     *
     * cURL 的报错原文长这样：`cURL error 77: … for https://内置地址` —— 这些消息会进插件日志、
     * 进面板的状态条、还会随告警发出去，等于把内置镜像挂出来。站长自己填的地址不动，那是他自己的。
     */
    public static function maskGeoUrl(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $host = (string)parse_url(self::GEO_DEFAULT_URL, PHP_URL_HOST);
        $text = str_replace(self::GEO_DEFAULT_URL, '[内置源]', $text);
        if ($host !== '') {
            $text = str_replace($host, '[内置源]', $text);
        }
        return $text;
    }

    /** 防火墙姿态 */
    public const WAF_OBSERVE = 'observe';
    public const WAF_PROTECT = 'protect';
    public const WAF_STRICT = 'strict';

    /** 告警级别（与通知中心 Settings::LEVEL_* 对齐） */
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARN = 'warn';
    public const LEVEL_CRITICAL = 'critical';

    private static ?array $cache = null;

    /**
     * 全部默认值（键名即 Submit.js 里的字段名）
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [
            'STATUS' => '0',

            /* ── 采集 ─────────────────────────────────── */
            'collect_enabled' => '1',
            'ignore_routes' => "/plugin/WebsiteMonitor/\n/admin/api/app/",
            'count_admin' => '1',
            'count_spider_in_pv' => '0',
            'session_timeout' => '1800',
            'online_window' => '300',
            'web_flush_interval' => '15',

            /* ── 存储与保留 ───────────────────────────── */
            'raw_retention_days' => '30',
            'raw_sample' => '100',
            'flood_qps' => '2000',
            'raw_partition' => '0',
            'session_retention_days' => '30',
            'visitor_retention_days' => '180',
            'attack_retention_days' => '14',
            'agg_retention_days' => '400',

            /* ── 防火墙 ───────────────────────────────── */
            'waf_enabled' => '1',
            'waf_mode' => self::WAF_OBSERVE,
            'emergency_off' => '0',
            'trust_admin' => '1',
            'always_allow' => '',
            'waf_scan_path' => '1',
            'waf_scan_query' => '1',
            'waf_scan_body' => '1',
            'waf_scan_cookie' => '1',
            'waf_scan_header' => '1',
            'waf_scan_files' => '1',
            'waf_body_max_kb' => '64',
            'waf_score_threshold' => '10',
            'waf_rules' => '{}',
            'waf_exclude_paths' => '',
            'waf_exclude_fields' => "content\nnotice\ndescription\nleave_message\nremark\ntpl_*",
            'ua_block_tools' => '1',
            'ua_block_empty' => '0',
            'ua_block_fake_spider' => '1',
            'method_allow' => 'GET,POST,HEAD,OPTIONS',
            'host_guard' => '0',
            'host_allow' => '',

            /* ── CC 防御 ──────────────────────────────── */
            'cc_enabled' => '1',
            'cc_driver' => 'auto',
            'cc_burst_limit' => '60',
            'cc_burst_window' => '10',
            'cc_sustain_limit' => '600',
            'cc_sustain_window' => '600',
            'cc_path_limit' => '30',
            'cc_path_window' => '10',
            'cc_global_limit' => '3000',
            'cc_global_window' => '10',
            'cc_ban_seconds' => '300',
            'cc_exempt_spider' => '1',
            'cc_exempt_member' => '1',
            'cc_exempt_paths' => "/user/api/order/callback\n/pay/",
            'overload_enabled' => '1',
            'overload_factor' => '3',

            /* ── 封禁梯度 ─────────────────────────────── */
            'ban_ladder' => '300,1800,7200,86400,604800',
            'ban_permanent_after' => '6',
            'ban_reset_hours' => '72',

            /* ── 登录防护 ─────────────────────────────── */
            'login_guard_enabled' => '1',
            'login_fail_limit' => '8',
            'login_fail_window' => '10',
            'login_ban_seconds' => '1800',
            'admin_login_fail_limit' => '3',
            'admin_login_ban_seconds' => '3600',

            /* ── 访问控制 ─────────────────────────────── */
            'acl_enabled' => '1',
            'region_mode' => 'off',
            'region_scope' => 'country',
            'region_confirm' => '0',
            'acl_version' => '1',

            /* ── 地理位置 ─────────────────────────────── */
            'geo_enabled' => '1',
            //留空 = 用内置源（见 GEO_DEFAULT_URL）。默认值故意不写在这里，
            //否则配置弹窗会把内置地址原样渲染到输入框里给每个站长看。
            'geo_db_url' => '',
            'geo_auto_update' => '1',
            'geo_update_cron' => '0 4 * * 1',
            'geo_cache_ttl' => '2592000',
            'geo_lang' => 'zh-CN',

            /* ── 通知 ─────────────────────────────────── */
            'notify_enabled' => '1',
            'notify_min_level' => self::LEVEL_WARN,
            'notify_cooldown' => '30',
            'notify_attack_burst' => '50',
            'notify_attack_window' => '10',
            'notify_ban' => '1',
            'notify_cc' => '1',
            'notify_overload' => '1',
            'notify_geo_fail' => '1',
            'notify_geo_ok' => '0',
            'notify_admin_new_country' => '1',
            'notify_digest' => '0',

            /* ── 拦截页 ───────────────────────────────── */
            'block_status_code' => '403',
            'block_page_title' => '',
            'block_page_contact' => '',

            /* ── 取证 ─────────────────────────────────── */
            'log_evidence' => '1',
            'evidence_max_bytes' => '2048',

            /* ── 高级 ─────────────────────────────────── */
            'log_level' => 'info',
            'broadcast' => '1',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function all(bool $fresh = false): array
    {
        if ($fresh || self::$cache === null) {
            $raw = [];
            try {
                $raw = Plugin::getConfig(self::PLUGIN, !$fresh);
            } catch (\Throwable $e) {
                $raw = [];
            }
            $merged = self::defaults();
            foreach ($raw as $k => $v) {
                if (is_scalar($v) || $v === null) {
                    $merged[(string)$k] = (string)$v;
                }
            }
            self::$cache = $merged;
        }
        return self::$cache;
    }

    public static function refresh(): void
    {
        self::$cache = null;
    }

    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = trim(self::get($key));
        return $v === '' ? $default : (int)$v;
    }

    /**
     * 有下限的整数（阈值类配置被填成 0 或负数会让防护失效，这里兜底）
     */
    public static function intMin(string $key, int $min, int $default): int
    {
        $v = self::int($key, $default);
        return $v < $min ? $default : $v;
    }

    /**
     * 多行文本 => 去空行、去 # 注释的数组
     * @return string[]
     */
    public static function lines(string $key): array
    {
        $raw = self::get($key);
        if (trim($raw) === '') {
            return [];
        }
        //配置保存路径会 urlencode 换行，这里都还原
        $raw = str_replace(['%0A', '%0D', "\r"], ["\n", '', ''], $raw);
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * 逗号分隔 => 数组
     * @return string[]
     */
    public static function csv(string $key): array
    {
        $parts = preg_split('/[\s,;，；]+/u', self::get($key)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
    }

    public static function enabled(): bool
    {
        return (string)(self::all()['STATUS'] ?? '0') === '1';
    }

    /**
     * 防火墙姿态（非法值一律退回最安全的观察模式）
     */
    public static function wafMode(): string
    {
        $m = self::get('waf_mode', self::WAF_OBSERVE);
        return in_array($m, [self::WAF_OBSERVE, self::WAF_PROTECT, self::WAF_STRICT], true) ? $m : self::WAF_OBSERVE;
    }

    /** 是否真的执行拦截（观察模式只记录） */
    public static function enforcing(): bool
    {
        return self::wafMode() !== self::WAF_OBSERVE;
    }

    /**
     * 封禁时长梯度（秒），保证升序且至少一级
     * @return int[]
     */
    public static function banLadder(): array
    {
        $out = [];
        foreach (self::csv('ban_ladder') as $s) {
            $n = (int)$s;
            if ($n > 0) {
                $out[] = $n;
            }
        }
        if ($out === []) {
            $out = [300, 1800, 7200, 86400, 604800];
        }
        sort($out);
        return $out;
    }

    /**
     * 写单个配置项（会让 opcache 与插件缓存失效）
     */
    public static function put(string $key, string $value): void
    {
        try {
            Plugin::setConfig(self::PLUGIN, $key, $value, false);
            self::refresh();
        } catch (\Throwable $e) {
            Log::exception('Settings::put', $e, ['key' => $key]);
        }
    }

    /**
     * 规则/名单版本号 +1 —— 热路径靠它判断编译产物是否过期
     */
    public static function bumpAclVersion(): int
    {
        $next = self::int('acl_version', 1) + 1;
        self::put('acl_version', (string)$next);
        return $next;
    }
}
