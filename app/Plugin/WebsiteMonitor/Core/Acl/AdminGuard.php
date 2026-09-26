<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Acl;

use App\Plugin\WebsiteMonitor\Core\Ip;
use App\Plugin\WebsiteMonitor\Core\Kv;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Settings;

/**
 * 防锁死。一个会拦人的插件，最大的风险不是拦不住坏人，而是把站长自己关在门外。
 *
 * 五道保险（缺一不可，任何一道都能单独救回站点）：
 *   1. runtime/plugin/WebsiteMonitor/DISABLED 空文件 → 整个插件一步不做（见 Runtime::disabled）
 *   2. emergency_off 配置键 → 后台还进得去时的一键停用
 *   3. always_allow 永久放行清单 → 安装时自动写入站长当时的 IP
 *   4. 管理员会话豁免 + 自动滚动白名单（本类）
 *   5. 地区规则自锁检测 + 后台路由永不受地区限制（见 Region）
 */
final class AdminGuard
{
    /** 后台会话 cookie 名（与 App\Consts\Manage::SESSION 一致） */
    private const COOKIE = 'MANAGE_USER';

    /** 管理员 IP 滚动白名单的有效期 */
    private const REMEMBER_TTL = 2592000;

    /** 一个管理员最多记住几个 IP，防止无限增长 */
    private const REMEMBER_MAX = 10;

    private static ?bool $isAdmin = null;

    /**
     * 是否在永久放行清单里。热路径第 4 步，纯哈希查。
     *
     * @param array<string,mixed> $rules rules.php
     */
    public static function alwaysAllowed(string $packed, array $rules): bool
    {
        $table = $rules['always_allow'] ?? [];
        if (Matcher::isEmpty($table)) {
            return false;
        }
        return Matcher::matchPacked($packed, $table) !== null;
    }

    /**
     * 当前请求是不是已登录的管理员。
     *
     * 分两级：
     *   便宜前置 —— 没有 MANAGE_USER cookie 就绝无可能是管理员，零成本返回 false；
     *   贵的校验 —— 只在**即将拦人**时才做（两次查询），所以正常请求永远不会付这个钱。
     */
    public static function isLoggedInAdmin(): bool
    {
        if (self::$isAdmin !== null) {
            return self::$isAdmin;
        }
        if (!isset($_COOKIE[self::COOKIE]) || !is_string($_COOKIE[self::COOKIE]) || $_COOKIE[self::COOKIE] === '') {
            return self::$isAdmin = false;
        }
        try {
            //touch=false：只验证身份，不刷新会话的最后活跃时间，避免我们的检查影响会话过期逻辑
            $resolved = \App\Service\ManageSessionManager::authenticate((string)$_COOKIE[self::COOKIE], false);
            return self::$isAdmin = ($resolved !== null && !empty($resolved['manage']));
        } catch (\Throwable $e) {
            //验证本身出错时按「不是管理员」处理，但绝不因此报错中断请求
            return self::$isAdmin = false;
        }
    }

    /**
     * 便宜判断：有没有后台 cookie。用来决定要不要付上面那个贵的校验。
     */
    public static function hasAdminCookie(): bool
    {
        return isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) && $_COOKIE[self::COOKIE] !== '';
    }

    /**
     * 记住管理员用过的 IP（30 天滚动白名单）。
     * 下次即使换了网络环境，只要 30 天内从这个 IP 登录过后台，就不会被自己的规则拦住。
     */
    public static function rememberAdminIp(string $ip): void
    {
        try {
            $normalized = Ip::normalize($ip);
            if ($normalized === null || Ip::isPrivate($normalized)) {
                return;
            }
            $key = 'admin_ip:' . md5($normalized);
            if (Kv::has($key)) {
                return;
            }
            Kv::set($key, ['ip' => $normalized, 'at' => time()], self::REMEMBER_TTL);

            //超出上限就把最旧的挤掉
            $all = Kv::byPrefix('admin_ip:', self::REMEMBER_MAX * 3);
            if (count($all) > self::REMEMBER_MAX) {
                uasort($all, static fn($a, $b): int => (int)($a['at'] ?? 0) <=> (int)($b['at'] ?? 0));
                $drop = count($all) - self::REMEMBER_MAX;
                foreach (array_keys($all) as $k) {
                    if ($drop-- <= 0) {
                        break;
                    }
                    Kv::delete($k);
                }
            }
            Log::info('已记住管理员 IP', ['ip' => $normalized]);
        } catch (\Throwable $e) {
            Log::exception('AdminGuard::rememberAdminIp', $e);
        }
    }

    /**
     * 该 IP 是否在管理员滚动白名单里。
     * 只在慢路径调（要查 KV 表），热路径先用 always_allow 挡掉绝大多数情况。
     */
    public static function isRememberedAdminIp(string $ip): bool
    {
        try {
            $normalized = Ip::normalize($ip);
            if ($normalized === null) {
                return false;
            }
            return Kv::has('admin_ip:' . md5($normalized));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 整体紧急停用（第 1、2 道保险）
     */
    public static function emergencyOff(): bool
    {
        if (Runtime::disabled()) {
            return true;
        }
        try {
            return Settings::bool('emergency_off');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 站长自救说明 —— 会原样打印在拦截页上。
     * 后台都进不去的时候，这行字是唯一的出路，所以必须是完整可复制的绝对路径。
     */
    public static function rescueHint(): string
    {
        return Runtime::dir() . '/' . Runtime::DISABLED_FILE;
    }

    /**
     * 给后台用：当前管理员会被自己的哪条规则拦住（保存前的体检）
     *
     * @param array<string,mixed> $rules
     * @return string|null 命中的说明；null = 安全
     */
    public static function selfCheck(string $ip, array $rules): ?string
    {
        $packed = Ip::pack($ip);
        if ($packed === null) {
            return null;
        }
        if (self::alwaysAllowed($packed, $rules)) {
            return null;
        }
        if (Matcher::matchPacked($packed, $rules['acl']['allow'] ?? []) !== null) {
            return null;
        }
        $denyId = Matcher::matchPacked($packed, $rules['acl']['deny'] ?? []);
        if ($denyId !== null) {
            return Lang::t('你的 IP 命中了 IP 黑名单（规则 #:id）', ['id' => (string)$denyId]);
        }
        if (Ban::isBanned($ip)) {
            return lang('你的 IP 目前处于自动封禁状态');
        }
        $region = Region::deny($ip, $rules['region'] ?? []);
        if ($region !== null) {
            return Lang::t('你所在的地区（:code）命中了地区规则', ['code' => $region]);
        }
        return null;
    }

    /**
     * 重置进程内缓存（守护任务里跨请求复用进程时必须调）
     */
    public static function reset(): void
    {
        self::$isAdmin = null;
    }
}
