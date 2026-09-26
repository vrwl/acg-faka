<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Acl;

use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Ip;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Settings;

/**
 * 自动封禁。
 *
 * 存储是**双写**的，各司其职：
 *   一 IP 一文件（runtime/.../ban/xx/md5）  热路径查询，1 次 is_file，惰性过期
 *   wm_ban 表                              后台展示、跨进程持久化、重启后恢复
 *
 * 为什么不像人工规则那样编译进 rules.php：封禁是秒级写入，每封一个 IP 就重写整份
 * 快照并失效 opcache，等于在被攻击时帮攻击者放大攻击。
 */
final class Ban
{
    /** @var array<string,array<string,mixed>|null> */
    private static array $mem = [];

    /**
     * 热路径查询：绝大多数请求止步于一次 is_file。
     *
     * @return array{expire:int,level:int,rule:string,at:int}|null
     */
    public static function get(string $ip): ?array
    {
        if (array_key_exists($ip, self::$mem)) {
            return self::$mem[$ip];
        }
        $file = self::path($ip);
        if (!is_file($file)) {
            return self::$mem[$ip] = null;
        }
        $raw = (string)@file_get_contents($file);
        [$expire, $level, $rule, $at] = array_pad(explode('|', $raw, 4), 4, '0');
        $expire = (int)$expire;
        if ($expire !== 0 && $expire < time()) {
            //惰性过期：读到就删，不需要任何定时任务参与
            @unlink($file);
            return self::$mem[$ip] = null;
        }
        return self::$mem[$ip] = [
            'expire' => $expire,
            'level' => (int)$level,
            'rule' => (string)$rule,
            'at' => (int)$at,
        ];
    }

    public static function isBanned(string $ip): bool
    {
        return self::get($ip) !== null;
    }

    /**
     * 自动封禁的统一闸门。
     *
     * 所有**自动**触发的封禁都必须先过这一关 —— 靠每个调用方各自记得检查是靠不住的，
     * 漏一处就可能把站长自己关在门外（实测踩过：用 Googlebot 的 UA 做测试，
     * 反查失败判定为伪蜘蛛，直接把测试机的 IP 封了 24 小时，而且是在观察模式下）。
     *
     * @return string|null 需要跳过时返回原因；null 表示可以封
     */
    public static function blockedByPolicy(string $ip): ?string
    {
        //观察模式：一律只记录不封
        if (!\App\Plugin\WebsiteMonitor\Core\Settings::enforcing()) {
            return 'observe_mode';
        }
        //内网与保留地址：这类 IP 要么是自己人，要么是代理没配好，封了只会误伤
        if (Ip::isPrivate($ip)) {
            return 'private_ip';
        }
        //白名单与管理员常用 IP
        try {
            if (\App\Plugin\WebsiteMonitor\Api\Guard::isAllowed($ip)) {
                return 'whitelisted';
            }
            if (AdminGuard::isRememberedAdminIp($ip)) {
                return 'admin_ip';
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /**
     * 自动封禁入口。与 add() 的区别是它会先过 blockedByPolicy()。
     * **所有自动触发的封禁都应该走这里**，不要直接调 add()。
     */
    public static function auto(string $ip, int $seconds, string $reason, string $rule): bool
    {
        $normalized = Ip::normalize($ip);
        if ($normalized === null) {
            return false;
        }
        $skip = self::blockedByPolicy($normalized);
        if ($skip !== null) {
            Log::info('自动封禁已跳过', ['ip' => $normalized, 'rule' => $rule, 'why' => $skip]);
            return false;
        }
        return self::add($normalized, $seconds, $reason, $rule, 1);
    }

    /**
     * 按梯度升级封禁：同一个 IP 反复触发会被越封越久，
     * 超过 ban_permanent_after 次转永久；ban_reset_hours 内没有新违规则梯度归零。
     *
     * @param int[] $ladder
     * @return array{seconds:int,level:int,permanent:bool}
     */
    public static function escalate(
        string $ip,
        string $rule,
        array $ladder,
        int $permanentAfter = 6,
        int $resetHours = 72,
        string $reason = '',
        int $source = 1
    ): array {
        $now = time();
        $level = 0;
        $hits = 0;

        //自动升级同样要过策略闸门
        if ($source === 1) {
            $skip = self::blockedByPolicy($ip);
            if ($skip !== null) {
                Log::info('自动封禁已跳过', ['ip' => $ip, 'rule' => $rule, 'why' => $skip]);
                return ['seconds' => 0, 'level' => 0, 'permanent' => false];
            }
        }

        try {
            $row = Db::table(Db::BAN)->where('ip', $ip)->first();
            if ($row) {
                $lastAt = (int)$row->last_at;
                $level = ($resetHours > 0 && $now - $lastAt > $resetHours * 3600) ? 0 : (int)$row->level;
                $hits = (int)$row->hits;
            }
        } catch (\Throwable $e) {
            Log::exception('Ban::escalate::read', $e, ['ip' => $ip]);
        }

        $nextLevel = $level + 1;
        $permanent = $permanentAfter > 0 && $nextLevel >= $permanentAfter;
        $seconds = $permanent ? 0 : (int)($ladder[min($level, count($ladder) - 1)] ?? 300);

        self::write($ip, $seconds, $reason !== '' ? $reason : $rule, $rule, $nextLevel, $hits + 1, $source);

        if ($permanent) {
            //转永久后交给人工规则托管，这样它会进编译产物并在后台的黑名单里可见
            Store::add(
                \App\Plugin\WebsiteMonitor\Consts\Kind::RULE_IP_DENY,
                $ip,
                Lang::t('自动封禁升级为永久（规则 :r）', ['r' => $rule]),
                0,
                'auto'
            );
        }

        return ['seconds' => $seconds, 'level' => $nextLevel, 'permanent' => $permanent];
    }

    /**
     * 直接封禁指定时长（对外 API、后台按钮、通知中心联动都走这里）
     *
     * @param int $seconds 0 = 永久
     */
    public static function add(
        string $ip,
        int $seconds,
        string $reason = '',
        string $rule = 'manual',
        int $source = 0
    ): bool {
        $normalized = Ip::normalize($ip);
        if ($normalized === null) {
            return false;
        }
        self::write($normalized, $seconds, $reason, $rule, 0, 1, $source);
        if ($seconds <= 0) {
            Store::add(
                \App\Plugin\WebsiteMonitor\Consts\Kind::RULE_IP_DENY,
                $normalized,
                $reason !== '' ? $reason : lang('永久封禁'),
                0,
                $source === 0 ? 'manual' : 'auto'
            );
        }
        return true;
    }

    /**
     * 解封：文件、数据库、以及可能存在的永久黑名单规则一起清掉
     */
    public static function release(string $ip, string $by = 'manual'): bool
    {
        $normalized = Ip::normalize($ip);
        if ($normalized === null) {
            return false;
        }
        try {
            @unlink(self::path($normalized));
            unset(self::$mem[$normalized]);
            Db::table(Db::BAN)->where('ip', $normalized)->delete();
            //永久封禁会同时落一条黑名单规则，解封时一并撤掉，否则解了个寂寞
            Store::removeByValue(\App\Plugin\WebsiteMonitor\Consts\Kind::RULE_IP_DENY, $normalized);
            Log::info('已解封 IP', ['ip' => $normalized, 'by' => $by]);
            try {
                hook(0x7C103, ['ip' => $normalized, 'by' => $by, 'reason' => '']);
            } catch (\Throwable $e) {
            }
            return true;
        } catch (\Throwable $e) {
            Log::exception('Ban::release', $e, ['ip' => $normalized]);
            return false;
        }
    }

    /**
     * 分页列表（后台用）
     *
     * @return array{list:array<int,array<string,mixed>>,total:int}
     */
    public static function paginate(string $keyword = '', int $page = 1, int $limit = 20): array
    {
        try {
            $q = Db::table(Db::BAN);
            if (trim($keyword) !== '') {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($keyword)) . '%';
                $q->where(static function ($sub) use ($like): void {
                    $sub->where('ip', 'like', $like)->orWhere('reason', 'like', $like)->orWhere('rule', 'like', $like);
                });
            }
            $total = (int)$q->count();
            $rows = $q->orderByDesc('create_at')->forPage(max(1, $page), max(1, min(200, $limit)))->get();

            $now = time();
            $list = [];
            foreach ($rows as $row) {
                $expire = (int)$row->expire_at;
                $list[] = [
                    'ip' => (string)$row->ip,
                    'reason' => (string)$row->reason,
                    'rule' => (string)$row->rule,
                    'level' => (int)$row->level,
                    'hits' => (int)$row->hits,
                    'create_at' => (int)$row->create_at,
                    'expire_at' => $expire,
                    'left' => $expire === 0 ? -1 : max(0, $expire - $now),
                    'permanent' => $expire === 0,
                    'expired' => $expire > 0 && $expire < $now,
                    'source' => (int)$row->source,
                    'region_id' => (int)$row->region_id,
                ];
            }
            return ['list' => $list, 'total' => $total];
        } catch (\Throwable $e) {
            Log::exception('Ban::paginate', $e);
            return ['list' => [], 'total' => 0];
        }
    }

    public static function activeCount(): int
    {
        try {
            return (int)Db::table(Db::BAN)
                ->where(static function ($q): void {
                    $q->where('expire_at', 0)->orWhere('expire_at', '>=', time());
                })->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 清理过期封禁（SweepTask 调）。文件靠惰性过期，这里主要清表与残留文件。
     */
    public static function pruneExpired(): int
    {
        $n = 0;
        try {
            $rows = Db::table(Db::BAN)
                ->where('expire_at', '>', 0)->where('expire_at', '<', time())
                ->limit(2000)->get(['ip']);
            foreach ($rows as $row) {
                @unlink(self::path((string)$row->ip));
                $n++;
            }
            Db::table(Db::BAN)->where('expire_at', '>', 0)->where('expire_at', '<', time())->limit(2000)->delete();
        } catch (\Throwable $e) {
            Log::exception('Ban::pruneExpired', $e);
        }
        return $n;
    }

    /**
     * 从数据库重建全部封禁文件。
     * 用在：站长手工清了 runtime 目录、或换了服务器之后。
     */
    public static function rebuildFiles(): int
    {
        $n = 0;
        try {
            $rows = Db::table(Db::BAN)
                ->where(static function ($q): void {
                    $q->where('expire_at', 0)->orWhere('expire_at', '>=', time());
                })->get();
            foreach ($rows as $row) {
                self::writeFile((string)$row->ip, (int)$row->expire_at, (int)$row->level, (string)$row->rule, (int)$row->create_at);
                $n++;
            }
        } catch (\Throwable $e) {
            Log::exception('Ban::rebuildFiles', $e);
        }
        return $n;
    }

    /**
     * 清空全部自动封禁
     */
    public static function clearAll(): int
    {
        try {
            $n = (int)Db::table(Db::BAN)->count();
            Db::conn()->statement('TRUNCATE TABLE `' . Db::physical(Db::BAN) . '`');
            Runtime::clearDir(Runtime::banDir());
            self::$mem = [];
            return $n;
        } catch (\Throwable $e) {
            Log::exception('Ban::clearAll', $e);
            return 0;
        }
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    private static function write(
        string $ip,
        int $seconds,
        string $reason,
        string $rule,
        int $level,
        int $hits,
        int $source
    ): void {
        $now = time();
        $expire = $seconds > 0 ? $now + $seconds : 0;

        self::writeFile($ip, $expire, $level, $rule, $now);

        try {
            Db::upsertMany(Db::BAN, [[
                'ip' => $ip,
                'start_hex' => (string)Ip::toHex($ip),
                'reason' => mb_substr($reason, 0, 120),
                'rule' => mb_substr($rule, 0, 48),
                'level' => $level,
                'hits' => $hits,
                'create_at' => $now,
                'expire_at' => $expire,
                'last_at' => $now,
                'region_id' => 0,
                'source' => $source,
            ]], [
                'reason' => 'VALUES(`reason`)',
                'rule' => 'VALUES(`rule`)',
                'level' => 'VALUES(`level`)',
                'hits' => '`hits` + 1',
                'expire_at' => 'VALUES(`expire_at`)',
                'last_at' => 'VALUES(`last_at`)',
                'source' => 'VALUES(`source`)',
            ]);
        } catch (\Throwable $e) {
            Log::exception('Ban::write', $e, ['ip' => $ip]);
        }

        Log::warn('已封禁 IP', ['ip' => $ip, 'seconds' => $seconds, 'rule' => $rule, 'level' => $level]);

        try {
            hook(0x7C102, [
                'ip' => $ip,
                'seconds' => $seconds,
                'reason' => $reason,
                'rule' => $rule,
                'level' => $level,
                'source' => $source,
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function writeFile(string $ip, int $expire, int $level, string $rule, int $at): void
    {
        try {
            $file = self::path($ip);
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($file, $expire . '|' . $level . '|' . mb_substr($rule, 0, 48) . '|' . $at, LOCK_EX);
            self::$mem[$ip] = ['expire' => $expire, 'level' => $level, 'rule' => $rule, 'at' => $at];
        } catch (\Throwable $e) {
        }
    }

    private static function path(string $ip): string
    {
        $hash = md5($ip);
        return Runtime::banDir() . '/' . substr($hash, 0, 2) . '/' . $hash;
    }
}
