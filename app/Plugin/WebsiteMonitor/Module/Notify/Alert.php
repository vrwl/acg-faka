<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Module\Notify;

use App\Plugin\WebsiteMonitor\Core\Kv;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Settings;

/**
 * 告警发射器。
 *
 * 三重防刷屏，缺一不可 —— 一次 CC 攻击能在一分钟内触发上千次判定，
 * 没有节流的话站长的手机会被炸穿，然后他就会把通知整个关掉，等于白做：
 *
 *   1. 本地冷却：同一个去重键在冷却期内只发一次，期间的次数累计成 suppressed，
 *      冷却结束时一次性带出「期间又发生了 N 次」
 *   2. 通知中心的 dedupe_key 唯一索引：即使本地冷却失效，也进不了第二条
 *   3. 级别门槛：notify_min_level 以下的直接不发
 */
final class Alert
{
    /** 冷却状态在 KV 里的键前缀 */
    private const COOLDOWN = 'alert_cd:';

    private const RANK = ['info' => 0, 'warn' => 1, 'critical' => 2];

    /**
     * @param string $event 事件标识，如 wm.cc_attack
     * @param string $level info | warn | critical
     * @param array{
     *   name?:string, scope?:string, scope_text?:string, count?:int, threshold?:int,
     *   window?:int, evidence?:array, actions?:string[], extra?:array<string,scalar>,
     *   dedupe?:string, first_seen?:int, meta?:array, telegram?:bool
     * } $options
     * @return bool 是否真的发出去了
     */
    public static function raise(
        string $event,
        string $level,
        string $title,
        string $summary,
        array $options = []
    ): bool {
        try {
            if (!Settings::bool('notify_enabled')) {
                return false;
            }
            $level = isset(self::RANK[$level]) ? $level : Settings::LEVEL_WARN;

            //级别门槛
            $min = Settings::get('notify_min_level', Settings::LEVEL_WARN);
            if ((self::RANK[$level] ?? 0) < (self::RANK[$min] ?? 1)) {
                return false;
            }

            //本地冷却
            $dedupe = (string)($options['dedupe'] ?? ($event . ':' . ($options['scope'] ?? '')));
            $cooldown = Settings::intMin('notify_cooldown', 1, 30) * 60;
            $state = self::cooldownState($dedupe, $cooldown);
            if ($state === null) {
                //还在冷却期内，只累计次数
                return false;
            }

            $spec = [
                'event' => $event,
                'level' => $level,
                'title' => $title,
                'summary' => $summary,
                'name' => (string)($options['name'] ?? $title),
                'scope' => (string)($options['scope'] ?? ''),
                'scope_text' => (string)($options['scope_text'] ?? ''),
                'count' => (int)($options['count'] ?? 0),
                'threshold' => (int)($options['threshold'] ?? 0),
                'window' => (int)($options['window'] ?? 0),
                'evidence' => (array)($options['evidence'] ?? []),
                'actions' => (array)($options['actions'] ?? []),
                'extra' => (array)($options['extra'] ?? []),
                'suppressed' => $state['suppressed'],
                'first_seen' => (int)($options['first_seen'] ?? $state['first_seen']),
                'last_seen' => time(),
                'dedupe' => $dedupe,
                'meta' => (array)($options['meta'] ?? []),
                'telegram' => $options['telegram'] ?? true,
            ];

            return Bridge::send($spec);
        } catch (\Throwable $e) {
            //告警本身出错绝不能影响业务
            Log::exception('Alert::raise', $e, ['event' => $event]);
            return false;
        }
    }

    /**
     * 冷却判定。返回 null 表示还在冷却期（本次不发，只累计）。
     *
     * @return array{suppressed:int,first_seen:int}|null
     */
    private static function cooldownState(string $dedupe, int $cooldown): ?array
    {
        $key = self::COOLDOWN . md5($dedupe);
        $now = time();
        $state = Kv::get($key);

        if (!is_array($state) || !isset($state['until'])) {
            //第一次：立刻发，并开始冷却
            Kv::set($key, ['until' => $now + $cooldown, 'suppressed' => 0, 'first_seen' => $now], $cooldown * 3);
            return ['suppressed' => 0, 'first_seen' => $now];
        }

        if ((int)$state['until'] > $now) {
            //冷却中：累计被压掉的次数
            $state['suppressed'] = (int)($state['suppressed'] ?? 0) + 1;
            Kv::set($key, $state, $cooldown * 3);
            return null;
        }

        //冷却结束：把期间累计的次数一起带出去
        $suppressed = (int)($state['suppressed'] ?? 0);
        $firstSeen = (int)($state['first_seen'] ?? $now);
        Kv::set($key, ['until' => $now + $cooldown, 'suppressed' => 0, 'first_seen' => $now], $cooldown * 3);
        return ['suppressed' => $suppressed, 'first_seen' => $firstSeen];
    }

    /**
     * IP 被自动封禁
     *
     * @param array<string,mixed> $context
     */
    public static function ipBanned(string $ip, int $seconds, string $rule, array $context = []): bool
    {
        if (!Settings::bool('notify_ban')) {
            return false;
        }
        $duration = $seconds <= 0 ? lang('永久') : self::duration($seconds);
        //封得越久说明问题越严重，级别跟着抬
        $level = ($seconds <= 0 || $seconds >= 7200) ? Settings::LEVEL_CRITICAL : Settings::LEVEL_WARN;

        return self::raise(
            'wm.ip_banned',
            $level,
            Lang::t('已自动封禁 IP :ip（:d）', ['ip' => $ip, 'd' => $duration]),
            Lang::t('该 IP 触发了安全规则「:r」，已被自动封禁 :d。', ['r' => $rule, 'd' => $duration]),
            array_merge([
                'name' => lang('自动封禁'),
                'scope' => 'ip:' . $ip,
                'scope_text' => Lang::t('IP :ip', ['ip' => $ip]) . (isset($context['geo']) ? '（' . $context['geo'] . '）' : ''),
                'dedupe' => 'wm:ban:' . $ip . ':' . ($context['level'] ?? 0),
                'actions' => [
                    lang('到「网站监控 → 规则」可查看封禁详情、延长为永久或立即解封'),
                    lang('若确认是误伤，请把该 IP 加入白名单，规则不会再拦它'),
                ],
                'extra' => array_filter([
                    lang('归属地') => (string)($context['geo'] ?? ''),
                    lang('触发规则') => $rule,
                    lang('封禁时长') => $duration,
                    lang('累计触发') => (string)($context['hits'] ?? ''),
                ]),
            ], $context['options'] ?? [])
        );
    }

    /**
     * 同一 IP 短时间内被拦大量请求
     */
    public static function attackBurst(string $ip, int $count, int $threshold, int $windowMinutes, array $context = []): bool
    {
        return self::raise(
            'wm.attack_burst',
            $count >= $threshold * 3 ? Settings::LEVEL_CRITICAL : Settings::LEVEL_WARN,
            Lang::t('IP :ip 在 :w 分钟内被拦截 :n 次', ['ip' => $ip, 'w' => (string)$windowMinutes, 'n' => (string)$count]),
            lang('这通常意味着有人在用自动化工具持续探测本站。防火墙已按规则处置，无需手动干预。'),
            [
                'name' => lang('攻击突发'),
                'scope' => 'ip:' . $ip,
                'scope_text' => Lang::t('IP :ip', ['ip' => $ip]) . (isset($context['geo']) ? '（' . $context['geo'] . '）' : ''),
                'count' => $count,
                'threshold' => $threshold,
                'window' => $windowMinutes,
                'evidence' => (array)($context['evidence'] ?? []),
                'actions' => [
                    lang('到「网站监控 → 安全」可看到完整的攻击记录与命中规则'),
                    lang('如需彻底阻断，可把该 IP 或其所在网段加入黑名单'),
                ],
                'extra' => array_filter([lang('归属地') => (string)($context['geo'] ?? '')]),
                'dedupe' => 'wm:atk:' . $ip . ':' . date('YmdH'),
            ]
        );
    }

    /**
     * 站点整体过载
     */
    public static function overload(int $qps, int $threshold): bool
    {
        if (!Settings::bool('notify_overload')) {
            return false;
        }
        return self::raise(
            'wm.overload',
            Settings::LEVEL_CRITICAL,
            lang('站点进入过载保护'),
            Lang::t('全站请求速率达到 :q/秒，已超过阈值 :t。白名单、已验证蜘蛛与已登录会员仍可正常访问，其余请求的限频已收紧。', [
                'q' => (string)$qps,
                't' => (string)$threshold,
            ]),
            [
                'name' => lang('过载保护'),
                'scope' => 'global',
                'scope_text' => lang('全站'),
                'count' => $qps,
                'threshold' => $threshold,
                'actions' => [
                    lang('到「网站监控 → 实时」查看流量来源，确认是真实访问高峰还是攻击'),
                    lang('若是攻击，可在「规则」里按地区或网段临时收紧'),
                    lang('若是正常高峰，请到设置里调高 CC 阈值'),
                ],
                'dedupe' => 'wm:ovl:' . (int)floor(time() / 600),
            ]
        );
    }

    /**
     * 后台在陌生国家登录
     */
    public static function adminNewCountry(string $email, string $ip, string $geo): bool
    {
        if (!Settings::bool('notify_admin_new_country')) {
            return false;
        }
        return self::raise(
            'wm.admin_new_country',
            Settings::LEVEL_CRITICAL,
            Lang::t('后台在陌生地区登录：:geo', ['geo' => $geo]),
            Lang::t('管理员账号 :e 从 :ip（:geo）登录了后台，此前从未在该国家/地区登录过。如果不是你本人，请立即修改密码。', [
                'e' => $email, 'ip' => $ip, 'geo' => $geo,
            ]),
            [
                'name' => lang('异地登录'),
                'scope' => 'manage:' . $email,
                'scope_text' => $email,
                'actions' => [
                    lang('如果不是本人操作：立即修改后台密码，并检查「管理日志」里的近期操作'),
                    lang('如果是本人：无需处理，本地区已被记住，下次不再提醒'),
                ],
                'extra' => [lang('登录 IP') => $ip, lang('归属地') => $geo],
                'dedupe' => 'wm:anc:' . md5($email . '|' . $geo),
            ]
        );
    }

    private static function duration(int $seconds): string
    {
        if ($seconds >= 86400) {
            return Lang::t(':n 天', ['n' => (string)round($seconds / 86400, 1)]);
        }
        if ($seconds >= 3600) {
            return Lang::t(':n 小时', ['n' => (string)round($seconds / 3600, 1)]);
        }
        return Lang::t(':n 分钟', ['n' => (string)max(1, (int)round($seconds / 60))]);
    }
}
