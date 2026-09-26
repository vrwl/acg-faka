<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Api;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Acl\AdminGuard;
use App\Plugin\WebsiteMonitor\Core\Acl\Ban;
use App\Plugin\WebsiteMonitor\Core\Acl\Matcher;
use App\Plugin\WebsiteMonitor\Core\Acl\Region;
use App\Plugin\WebsiteMonitor\Core\Acl\Store;
use App\Plugin\WebsiteMonitor\Core\Cc\Limiter;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Geo\Locator;
use App\Plugin\WebsiteMonitor\Core\Ingest\Attacks;
use App\Plugin\WebsiteMonitor\Core\Ip;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Schema;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * 给其它插件用的公开接口。
 *
 * 契约（写进 Wiki，不会变）：
 *   · 全部 static，调用方不需要实例化任何东西
 *   · **永不向调用方抛异常** —— 内部一律 catch，失败返回 false / 空结构
 *   · 调用前先问 isAvailable()：本插件没装、没启用、表没建好时它返回 false
 *
 * 调用方模板：
 *
 *   $wm = '\App\Plugin\WebsiteMonitor\Api\Guard';
 *   if (class_exists($wm) && $wm::isAvailable()) {
 *       $wm::banIp($ip, 3600, '支付回调伪造', 'PayIntercept');
 *   }
 *
 * 注意用字符串类名 + class_exists，不要直接 use —— 本插件未安装时
 * 直接引用类会让你的插件在加载期就炸掉。
 */
final class Guard
{
    private static ?bool $available = null;
    private static float $checkedAt = 0.0;

    /* ═══════════════════════════ 可用性 ═══════════════════════════ */

    /**
     * 本插件是否可用（已安装 + 已启用 + 表结构就绪）。结果缓存 5 秒。
     */
    public static function isAvailable(): bool
    {
        if (self::$available !== null && (microtime(true) - self::$checkedAt) < 5.0) {
            return self::$available;
        }
        self::$checkedAt = microtime(true);
        try {
            Settings::refresh();
            return self::$available = (Settings::enabled() && Schema::ready());
        } catch (\Throwable $e) {
            return self::$available = false;
        }
    }

    /**
     * 版本与运行状态
     *
     * @return array<string,mixed>
     */
    public static function version(): array
    {
        try {
            $info = require BASE_PATH . '/app/Plugin/' . Settings::PLUGIN . '/Config/Info.php';
            return [
                'version' => (string)($info[\App\Consts\Plugin::VERSION] ?? '0'),
                'available' => self::isAvailable(),
                'waf_mode' => Settings::wafMode(),
                'enforcing' => Settings::enforcing(),
                'geo_ready' => Locator::available(),
            ];
        } catch (\Throwable $e) {
            return ['version' => '0', 'available' => false];
        }
    }

    /* ═══════════════════════════ 封禁 ═══════════════════════════ */

    /**
     * 封禁一个 IP。
     *
     * @param string $ip IPv4 / IPv6
     * @param int $seconds 封禁秒数，0 = 永久
     * @param string $reason 人话原因，会显示在后台与通知里
     * @param string $source 来源标记，建议填你的插件名
     * @return bool 是否真的生效（IP 非法、在白名单里、是管理员常用 IP 时返回 false）
     */
    public static function banIp(string $ip, int $seconds = 0, string $reason = '', string $source = 'api'): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        try {
            $normalized = Ip::normalize($ip);
            if ($normalized === null) {
                return false;
            }
            //白名单与管理员常用 IP 一律拒绝封禁 —— 这是防锁死的一部分，
            //不能因为某个插件调了 API 就把站长关在门外
            if (self::isAllowed($normalized) || AdminGuard::isRememberedAdminIp($normalized)) {
                Log::warn('拒绝封禁受保护的 IP', ['ip' => $normalized, 'source' => $source]);
                return false;
            }
            $ok = Ban::add($normalized, max(0, $seconds), $reason, 'api:' . mb_substr($source, 0, 24), 2);
            if ($ok && class_exists('\App\Plugin\WebsiteMonitor\Module\Notify\Alert')) {
                \App\Plugin\WebsiteMonitor\Module\Notify\Alert::ipBanned(
                    $normalized,
                    max(0, $seconds),
                    $source,
                    ['geo' => Locator::text(Locator::lookup($normalized))]
                );
            }
            return $ok;
        } catch (\Throwable $e) {
            Log::exception('Guard::banIp', $e, ['ip' => $ip]);
            return false;
        }
    }

    public static function unbanIp(string $ip): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        try {
            $normalized = Ip::normalize($ip);
            if ($normalized === null) {
                return false;
            }
            Limiter::forget($normalized);
            return Ban::release($normalized, 'api');
        } catch (\Throwable $e) {
            Log::exception('Guard::unbanIp', $e, ['ip' => $ip]);
            return false;
        }
    }

    /**
     * @return array{banned:bool,expire:int,left:int,level:int,rule:string}|null null = 未被封
     */
    public static function isBanned(string $ip): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }
        try {
            $normalized = Ip::normalize($ip);
            if ($normalized === null) {
                return null;
            }
            $ban = Ban::get($normalized);
            if ($ban === null) {
                return null;
            }
            return [
                'banned' => true,
                'expire' => (int)$ban['expire'],
                'left' => (int)$ban['expire'] === 0 ? -1 : max(0, (int)$ban['expire'] - time()),
                'level' => (int)$ban['level'],
                'rule' => (string)$ban['rule'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ═══════════════════════════ 名单 ═══════════════════════════ */

    /**
     * 加入白名单。$seconds > 0 时是临时放行。
     */
    public static function allowIp(string $ip, string $note = '', int $seconds = 0, string $source = 'api'): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        try {
            $result = Store::add(
                Kind::RULE_IP_ALLOW,
                $ip,
                $note,
                $seconds > 0 ? time() + $seconds : 0,
                'api:' . mb_substr($source, 0, 24)
            );
            return (bool)$result['ok'];
        } catch (\Throwable $e) {
            Log::exception('Guard::allowIp', $e, ['ip' => $ip]);
            return false;
        }
    }

    public static function disallowIp(string $ip): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        try {
            return Store::removeByValue(Kind::RULE_IP_ALLOW, $ip) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function isAllowed(string $ip): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        try {
            $packed = Ip::pack($ip);
            if ($packed === null) {
                return false;
            }
            $rules = State::rules();
            return AdminGuard::alwaysAllowed($packed, $rules)
                || Matcher::matchPacked($packed, (array)(($rules['acl'] ?? [])['allow'] ?? [])) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 加黑名单（永久，与 banIp 的区别是它进人工规则、会出现在后台黑名单列表里）
     */
    public static function denyIp(string $ip, string $note = '', string $source = 'api'): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        try {
            if (self::isAllowed($ip) || AdminGuard::isRememberedAdminIp($ip)) {
                return false;
            }
            return (bool)Store::add(Kind::RULE_IP_DENY, $ip, $note, 0, 'api:' . mb_substr($source, 0, 24))['ok'];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 地区封锁。$code 形如 CN / CN.GD / CN.GD.深圳市
     */
    public static function blockRegion(string $code, string $effect = 'deny', string $note = '', string $source = 'api'): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        try {
            $type = $effect === 'allow' ? Kind::RULE_REGION_ALLOW : Kind::RULE_REGION_DENY;
            return (bool)Store::add($type, $code, $note, 0, 'api:' . mb_substr($source, 0, 24))['ok'];
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function unblockRegion(string $code): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        try {
            $normalized = Region::normalizeCode($code) ?? $code;
            return Store::removeByValue(Kind::RULE_REGION_DENY, $normalized) > 0
                || Store::removeByValue(Kind::RULE_REGION_ALLOW, $normalized) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ═══════════════════════════ 地理 ═══════════════════════════ */

    /**
     * IP 归属地。
     *
     * @return array{ok:bool,ip:string,country:?string,country_name:?string,province:?string,
     *               city:?string,continent:?string,is_lan:bool,reason:?string,text:string}
     */
    public static function lookup(string $ip): array
    {
        try {
            $geo = Locator::lookup($ip);
            $geo['text'] = Locator::text($geo);
            return $geo;
        } catch (\Throwable $e) {
            return ['ok' => false, 'ip' => $ip, 'country' => null, 'country_name' => null,
                'province' => null, 'city' => null, 'continent' => null,
                'is_lan' => false, 'reason' => 'error', 'text' => ''];
        }
    }

    /**
     * @param string[] $ips
     * @return array<string,array<string,mixed>>
     */
    public static function lookupMany(array $ips): array
    {
        try {
            $out = Locator::lookupMany($ips);
            foreach ($out as $ip => $geo) {
                $out[$ip]['text'] = Locator::text($geo);
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ═══════════════════════════ 查询 ═══════════════════════════ */

    /**
     * 概览统计
     *
     * @param string $range 1h | 24h | 7d | 30d
     * @return array<string,mixed>
     */
    public static function stats(string $range = '24h'): array
    {
        $empty = [
            'pv' => 0, 'uv' => 0, 'ip' => 0, 'visits' => 0,
            'attack' => 0, 'blocked' => 0, 'bans' => 0, 'online' => 0,
            'range' => $range, 'available' => false,
        ];
        if (!self::isAvailable()) {
            return $empty;
        }
        try {
            $seconds = match ($range) {
                '1h' => 3600,
                '7d' => 604800,
                '30d' => 2592000,
                default => 86400,
            };
            $from = time() - $seconds;
            $table = $seconds <= 86400 ? Db::STAT_HOUR : Db::STAT_DAY;

            if ($table === Db::STAT_HOUR) {
                $row = Db::table(Db::STAT_HOUR)->where('t', '>=', $from - ($from % 3600))
                    ->selectRaw('SUM(pv) pv, SUM(uv) uv, SUM(ip_n) ip, SUM(sessions) visits, SUM(attack) attack, SUM(blocked) blocked')
                    ->first();
            } else {
                $row = Db::table(Db::STAT_DAY)->where('day', '>=', (int)date('Ymd', $from))
                    ->selectRaw('SUM(pv) pv, SUM(uv) uv, SUM(ip_n) ip, SUM(sessions) visits, SUM(attack) attack, SUM(blocked) blocked')
                    ->first();
            }

            return [
                'pv' => (int)($row->pv ?? 0),
                'uv' => (int)($row->uv ?? 0),
                'ip' => (int)($row->ip ?? 0),
                'visits' => (int)($row->visits ?? 0),
                'attack' => (int)($row->attack ?? 0),
                'blocked' => (int)($row->blocked ?? 0),
                'bans' => Ban::activeCount(),
                'online' => self::onlineCount(),
                'range' => $range,
                'available' => true,
            ];
        } catch (\Throwable $e) {
            Log::exception('Guard::stats', $e);
            return $empty;
        }
    }

    /**
     * 最近的攻击记录
     *
     * @param array<string,mixed> $filter ip / kind / level / rule / from / to
     * @return array<int,array<string,mixed>>
     */
    public static function recentAttacks(int $limit = 50, array $filter = []): array
    {
        if (!self::isAvailable()) {
            return [];
        }
        try {
            return Attacks::paginate($filter, 1, max(1, min(200, $limit)))['list'];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 单个 IP 的完整画像
     *
     * @return array<string,mixed>
     */
    public static function profile(string $ip): array
    {
        if (!self::isAvailable()) {
            return [];
        }
        try {
            $normalized = Ip::normalize($ip);
            if ($normalized === null) {
                return [];
            }
            $row = Db::table(Db::IP)->where('ip', $normalized)->first();
            $ban = self::isBanned($normalized);
            return [
                'ip' => $normalized,
                'geo' => self::lookup($normalized),
                'first_at' => (int)($row->first_at ?? 0),
                'last_at' => (int)($row->last_at ?? 0),
                'requests' => (int)($row->req ?? 0),
                'attacks' => (int)($row->atk ?? 0),
                'last_rule' => (string)($row->last_rule ?? ''),
                'spider_id' => (int)($row->spider_id ?? 0),
                'note' => (string)($row->note ?? ''),
                'banned' => $ban,
                'allowed' => self::isAllowed($normalized),
                'rule' => Store::findMatching($normalized),
                'recent' => self::recentAttacks(20, ['ip' => $normalized]),
            ];
        } catch (\Throwable $e) {
            Log::exception('Guard::profile', $e, ['ip' => $ip]);
            return [];
        }
    }

    /* ═══════════════════════════ 主动检查 ═══════════════════════════ */

    /**
     * 让其它插件对任意输入跑一遍 WAF 规则（不影响当前请求，也不会封禁任何人）。
     *
     * @param array{path?:string,query?:string,body?:string,ua?:string} $input
     * @return array{hit:bool,rule:?string,name:?string,level:?string,score:int}
     */
    public static function checkRequest(array $input): array
    {
        $miss = ['hit' => false, 'rule' => null, 'name' => null, 'level' => null, 'score' => 0];
        if (!self::isAvailable()) {
            return $miss;
        }
        try {
            $rules = State::rules();
            $single = (array)($rules['single'] ?? []);
            $meta = (array)($rules['meta'] ?? []);
            $subject = implode("\n", array_filter([
                (string)($input['path'] ?? ''),
                (string)($input['query'] ?? ''),
                (string)($input['body'] ?? ''),
                (string)($input['ua'] ?? ''),
            ]));
            if (trim($subject) === '') {
                return $miss;
            }
            foreach ($single as $id => $re) {
                if (!(($meta[$id]['enabled'] ?? true))) {
                    continue;
                }
                if (@preg_match((string)$re, $subject) === 1) {
                    return [
                        'hit' => true,
                        'rule' => (string)$id,
                        'name' => (string)($meta[$id]['name'] ?? $id),
                        'level' => (string)($meta[$id]['level'] ?? 'warn'),
                        'score' => (int)($meta[$id]['score'] ?? 0),
                    ];
                }
            }
            return $miss;
        } catch (\Throwable $e) {
            return $miss;
        }
    }

    /**
     * 当前请求的判决快照（KERNEL_INIT 已经算过，这里直接返回，零成本）
     *
     * @return array<string,mixed>|null
     */
    public static function currentDecision(): ?array
    {
        return self::$decision === null ? null : self::$decision->toArray();
    }

    /** @var Decision|null */
    private static ?Decision $decision = null;

    /**
     * 由 Hook\Guard 在判决完成后写入（内部用，不属于对外契约）
     */
    public static function rememberDecision(Decision $decision): void
    {
        self::$decision = $decision;
    }

    public static function onlineCount(int $window = 0): int
    {
        try {
            $window = $window > 0 ? $window : Settings::intMin('online_window', 60, 300);
            return (int)Db::table(Db::ONLINE)
                ->where('last_at', '>=', time() - $window)
                ->where('spider_id', 0)
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
