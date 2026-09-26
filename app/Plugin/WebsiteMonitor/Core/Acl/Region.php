<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Acl;

use App\Plugin\WebsiteMonitor\Core\Ip;
use App\Plugin\WebsiteMonitor\Core\Log;

/**
 * 地区黑白名单。
 *
 * 地区码统一三级点分：`CN` / `CN.GD` / `CN.GD.深圳市`。
 * 匹配时从最精确到最粗（城市 → 省 → 国家），先命中先算。
 *
 * 两条安全底线（硬编码，不可配置）：
 *  1. 地理库不可用时整体跳过 —— fail-open，绝不因为缺库把所有人拦在门外。
 *  2. 地区规则永不作用于后台路由 —— 站长出差到境外也必须进得去后台。
 */
final class Region
{
    /** 后台路由前缀：地区规则对这些路径一律不生效 */
    private const ADMIN_PREFIXES = ['/admin/', '/plugin/'];

    /**
     * 规范化站长输入的地区码。非法返回 null。
     */
    public static function normalizeCode(string $value): ?string
    {
        $value = trim(str_replace(['-', '_', '/', '，'], ['.', '.', '.', '.'], $value));
        if ($value === '') {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode('.', $value)), static fn(string $s): bool => $s !== ''));
        if ($parts === []) {
            return null;
        }
        $country = strtoupper($parts[0]);
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return null;
        }
        $out = [$country];
        if (isset($parts[1]) && $parts[1] !== '') {
            //省级：ISO 细分码用大写，中文名保持原样
            $out[] = preg_match('/^[A-Za-z0-9]{1,4}$/', $parts[1]) ? strtoupper($parts[1]) : mb_substr($parts[1], 0, 48);
        }
        if (isset($parts[2]) && $parts[2] !== '') {
            $out[] = mb_substr($parts[2], 0, 48);
        }
        return implode('.', $out);
    }

    /**
     * 从地理查询结果生成三级候选码（由细到粗）
     *
     * @param array<string,mixed> $geo Locator::lookup() 的结果
     * @return string[]
     */
    public static function codesFor(array $geo): array
    {
        $country = strtoupper((string)($geo['country'] ?? ''));
        if ($country === '') {
            return [];
        }
        $province = trim((string)($geo['province'] ?? ''));
        $city = trim((string)($geo['city'] ?? ''));

        $codes = [];
        if ($province !== '' && $city !== '') {
            $codes[] = $country . '.' . $province . '.' . $city;
        }
        if ($province !== '') {
            $codes[] = $country . '.' . $province;
        }
        $codes[] = $country;
        return $codes;
    }

    /**
     * 热路径判定：这个 IP 是否该被地区规则拦下。
     *
     * @param array<string,mixed> $config rules.php 里的 region 段
     * @return string|null 命中的地区码；null = 放行
     */
    public static function deny(string $ip, array $config, string $route = ''): ?string
    {
        $mode = (string)($config['mode'] ?? 'off');
        if ($mode === 'off') {
            return null;
        }
        //后台路由永不受地区限制
        if ($route !== '' && self::isAdminRoute($route)) {
            return null;
        }
        //内网 / 保留地址不查地理，也不拦
        if (Ip::isPrivate($ip)) {
            return null;
        }

        $geo = self::lookup($ip);
        if ($geo === null || ($geo['ok'] ?? false) !== true) {
            //查不到归属地：fail-open
            return null;
        }
        $codes = self::codesFor($geo);
        if ($codes === []) {
            return null;
        }

        if ($mode === 'blacklist') {
            $deny = $config['deny'] ?? [];
            foreach ($codes as $code) {
                if (isset($deny[$code])) {
                    return $code;
                }
            }
            return null;
        }

        if ($mode === 'whitelist') {
            $allow = $config['allow'] ?? [];
            foreach ($codes as $code) {
                if (isset($allow[$code])) {
                    return null;
                }
            }
            //白名单模式下，不在名单里就是拦
            return $codes[count($codes) - 1];
        }

        return null;
    }

    /**
     * 保存配置时的自锁检测：这条规则会不会把站长自己挡在门外。
     *
     * @param array<string,mixed> $map 正在保存的配置
     * @return string|null 会被锁死时返回站长所在地的可读描述
     */
    public static function wouldLockOut(string $ip, string $mode, array $map): ?string
    {
        $geo = self::lookup($ip);
        if ($geo === null || ($geo['ok'] ?? false) !== true) {
            return null;
        }
        $codes = self::codesFor($geo);
        if ($codes === []) {
            return null;
        }
        $where = trim(implode(' · ', array_values(array_filter([
            (string)($geo['country_name'] ?? $geo['country'] ?? ''),
            (string)($geo['province'] ?? ''),
            (string)($geo['city'] ?? ''),
        ]))));

        //保存时黑白名单还没入库（它们在面板里管理），这里读当前库里的状态
        $current = self::load();
        if ($mode === 'blacklist') {
            foreach ($codes as $code) {
                if (isset($current['deny'][$code])) {
                    return $where;
                }
            }
            return null;
        }
        if ($mode === 'whitelist') {
            foreach ($codes as $code) {
                if (isset($current['allow'][$code])) {
                    return null;
                }
            }
            return $where;
        }
        return null;
    }

    /**
     * 从库里读出地区规则（Compiler 与自锁检测共用）
     *
     * @return array{deny:array<string,int>,allow:array<string,int>}
     */
    public static function load(): array
    {
        $out = ['deny' => [], 'allow' => []];
        try {
            $rows = \App\Plugin\WebsiteMonitor\Core\Db::table(\App\Plugin\WebsiteMonitor\Core\Db::RULE)
                ->whereIn('type', Store::REGION_TYPES)
                ->where('status', 1)
                ->where(static function ($q): void {
                    $q->where('expire_at', 0)->orWhere('expire_at', '>=', time());
                })
                ->get();
            foreach ($rows as $row) {
                $side = (int)$row->type === \App\Plugin\WebsiteMonitor\Consts\Kind::RULE_REGION_DENY ? 'deny' : 'allow';
                $out[$side][(string)$row->value] = (int)$row->id;
            }
        } catch (\Throwable $e) {
            Log::exception('Region::load', $e);
        }
        return $out;
    }

    public static function isAdminRoute(string $route): bool
    {
        $route = strtolower($route);
        foreach (self::ADMIN_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 地理查询。地理子系统还没就绪时返回 null（调用方一律 fail-open）。
     *
     * @return array<string,mixed>|null
     */
    private static function lookup(string $ip): ?array
    {
        if (!class_exists('\App\Plugin\WebsiteMonitor\Core\Geo\Locator')) {
            return null;
        }
        try {
            return \App\Plugin\WebsiteMonitor\Core\Geo\Locator::lookup($ip);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
