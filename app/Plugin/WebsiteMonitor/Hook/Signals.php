<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Hook;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Acl\AdminGuard;
use App\Plugin\WebsiteMonitor\Core\Acl\Ban;
use App\Plugin\WebsiteMonitor\Core\Collect\Collector;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Geo\Locator;
use App\Plugin\WebsiteMonitor\Core\Kv;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;
use App\Util\Client;
use App\Util\Throttle;
use Kernel\Annotation\Hook;

/**
 * 安全信号：登录爆破、内核 WAF 拦截、后台异地登录。
 *
 * 这些点位都是低频事件，用 App\Util\Throttle（文件滑动计数）就够了 ——
 * 不需要动用 CC 那套无锁定长文件的重武器。
 *
 * 全部写十六进制字面量：0x23 / 0x61 / 0x62 是 3.5.8 才加的常量，
 * 老核心上引用不存在的常量会让属性求值抛 Error，把插件卡在半启用态。
 */
class Signals
{
    /**
     * 内核 WAF 拦截（app/Interceptor/Waf.php 命中规则时触发）。
     *
     * 这是与内核防火墙的互补点：内核只拦当次请求，我们把它累计成封禁 ——
     * 一个 IP 反复触发内核 WAF，本身就说明它在试探。
     */
    #[Hook(point: 0x289)]
    public function wafIntercept(array $message = []): void
    {
        try {
            if (!self::enabled()) {
                return;
            }
            $ip = self::ip();
            if ($ip === '' || self::exempt($ip)) {
                return;
            }

            Collector::markAttack(
                Kind::ATK_WAF,
                'core.waf',
                Kind::ACT_BLOCK,
                6,
                'warn',
                '',
                Db::json(['rule' => mb_substr(self::messageText($message), 0, 200)])
            );

            //5 次 / 10 分钟就升级为封禁
            if (Throttle::tooMany('wm:kwaf:' . md5($ip), 5, 600)) {
                self::ban($ip, 'core.waf', lang('反复触发站点防火墙规则'));
            }
        } catch (\Throwable $e) {
            Log::exception('Signals::wafIntercept', $e);
        }
    }

    /**
     * 会员登录失败（0x23 = USER_API_AUTH_LOGIN_FAIL）
     */
    #[Hook(point: 0x23)]
    public function loginFail(string $account = '', string $reason = ''): void
    {
        try {
            if (!self::enabled() || !Settings::bool('login_guard_enabled')) {
                return;
            }
            $ip = self::ip();
            if ($ip === '' || self::exempt($ip)) {
                return;
            }

            $limit = Settings::intMin('login_fail_limit', 3, 8);
            $window = Settings::intMin('login_fail_window', 1, 10) * 60;

            Collector::markAttack(
                Kind::ATK_LOGIN_BRUTE,
                'brute.login',
                Kind::ACT_LOG,
                4,
                'warn',
                '',
                Db::json(['account' => mb_substr($account, 0, 64), 'reason' => $reason])
            );

            //同一 IP 与同一账号分开计数：撞库是换账号打同一个 IP，
            //定向爆破是换 IP 打同一个账号，两种都要抓
            $byIp = Throttle::tooMany('wm:lf:ip:' . md5($ip), $limit, $window);
            $byAccount = $account !== ''
                && Throttle::tooMany('wm:lf:ac:' . md5(mb_strtolower($account)), max(3, (int)($limit * 0.7)), $window);

            if ($byIp || $byAccount) {
                self::ban(
                    $ip,
                    'brute.login',
                    $byAccount ? lang('针对单一账号的密码爆破') : lang('会员登录连续失败'),
                    Settings::intMin('login_ban_seconds', 60, 1800)
                );
            }
        } catch (\Throwable $e) {
            Log::exception('Signals::loginFail', $e);
        }
    }

    /**
     * 后台登录失败（0x62 = ADMIN_API_AUTH_LOGIN_FAIL）。
     * 后台的阈值要狠得多 —— 正常人不会连错三次以上。
     */
    #[Hook(point: 0x62)]
    public function adminLoginFail(string $email = '', string $reason = ''): void
    {
        try {
            if (!self::enabled() || !Settings::bool('login_guard_enabled')) {
                return;
            }
            $ip = self::ip();
            if ($ip === '') {
                return;
            }

            Collector::markAttack(
                Kind::ATK_LOGIN_BRUTE,
                'brute.admin',
                Kind::ACT_LOG,
                8,
                'critical',
                '',
                Db::json(['email' => mb_substr($email, 0, 64), 'reason' => $reason])
            );

            $limit = Settings::intMin('admin_login_fail_limit', 2, 3);
            if (!Throttle::tooMany('wm:alf:' . md5($ip), $limit, 600)) {
                return;
            }

            //后台爆破就算命中了管理员常用 IP 也要封 —— 那正说明凭据可能已经泄露
            self::ban(
                $ip,
                'brute.admin',
                lang('后台登录连续失败，疑似密码爆破'),
                Settings::intMin('admin_login_ban_seconds', 300, 3600),
                true
            );

            if (class_exists('\App\Plugin\WebsiteMonitor\Module\Notify\Alert')) {
                \App\Plugin\WebsiteMonitor\Module\Notify\Alert::raise(
                    'wm.admin_brute',
                    Settings::LEVEL_CRITICAL,
                    Lang::t('后台正在被爆破：:ip', ['ip' => $ip]),
                    Lang::t('该 IP 连续 :n 次登录后台失败，已被自动封禁。若你没有在尝试登录，请立即检查后台密码强度并开启两步验证。', ['n' => (string)$limit]),
                    [
                        'name' => lang('后台爆破'),
                        'scope' => 'ip:' . $ip,
                        'scope_text' => Lang::t('IP :ip', ['ip' => $ip]) . '（' . Locator::text(Locator::lookup($ip)) . '）',
                        'count' => $limit,
                        'threshold' => $limit,
                        'extra' => [lang('尝试的账号') => mb_substr($email, 0, 64)],
                        'actions' => [
                            lang('确认后台密码足够复杂，并考虑开启两步验证'),
                            lang('用「后台守护」类插件把后台入口改成隐藏地址'),
                        ],
                        'dedupe' => 'wm:ab:' . md5($ip) . ':' . date('YmdH'),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::exception('Signals::adminLoginFail', $e);
        }
    }

    /**
     * 后台登录成功（0x61 = ADMIN_API_AUTH_LOGIN_AFTER）。
     *
     * 做两件事：把这个 IP 记进滚动白名单（防锁死第 4 道保险），
     * 以及在国家变化时告警。
     */
    #[Hook(point: 0x61)]
    public function adminLoginAfter(object $manage = null): void
    {
        try {
            if (!self::enabled()) {
                return;
            }
            $ip = self::ip();
            if ($ip === '') {
                return;
            }

            //登录成功的 IP 一定是站长自己的，记住它
            AdminGuard::rememberAdminIp($ip);
            Throttle::clear('wm:alf:' . md5($ip));

            if (!Settings::bool('notify_admin_new_country')) {
                return;
            }
            $geo = Locator::lookup($ip);
            $country = (string)($geo['country'] ?? '');
            if ($country === '') {
                return;
            }

            $email = (string)($manage->email ?? '');
            $key = 'admin_country:' . md5($email !== '' ? $email : 'default');
            $known = (array)Kv::get($key, []);
            if (in_array($country, $known, true)) {
                return;
            }

            $known[] = $country;
            //只记最近 5 个国家，避免无限增长
            Kv::set($key, array_slice($known, -5), 31536000);

            //首次登录时库里本来就空，不该报警
            if (count($known) <= 1) {
                return;
            }
            if (class_exists('\App\Plugin\WebsiteMonitor\Module\Notify\Alert')) {
                \App\Plugin\WebsiteMonitor\Module\Notify\Alert::adminNewCountry($email, $ip, Locator::text($geo));
            }
        } catch (\Throwable $e) {
            Log::exception('Signals::adminLoginAfter', $e);
        }
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    private static function enabled(): bool
    {
        try {
            return Settings::enabled() && !\App\Plugin\WebsiteMonitor\Core\Runtime::disabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function ip(): string
    {
        $ip = Collector::ip();
        return $ip !== '' ? $ip : Client::getAddress();
    }

    /**
     * 白名单与管理员常用 IP 不参与这些计数
     */
    private static function exempt(string $ip): bool
    {
        try {
            return \App\Plugin\WebsiteMonitor\Api\Guard::isAllowed($ip)
                || AdminGuard::isRememberedAdminIp($ip);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function ban(string $ip, string $rule, string $reason, int $seconds = 0, bool $force = false): void
    {
        //观察模式下只记录不封
        if (!Settings::enforcing()) {
            Log::info('[观察模式] 本可封禁', ['ip' => $ip, 'rule' => $rule, 'reason' => $reason]);
            return;
        }
        if (!$force && self::exempt($ip)) {
            return;
        }
        try {
            $thresholds = (array)(State::rules()['thresholds'] ?? []);
            if ($seconds > 0) {
                Ban::add($ip, $seconds, $reason, $rule, 1);
            } else {
                Ban::escalate(
                    $ip,
                    $rule,
                    (array)($thresholds['ladder'] ?? [300, 1800, 7200, 86400, 604800]),
                    (int)($thresholds['ban_permanent_after'] ?? 6),
                    (int)($thresholds['ban_reset_hours'] ?? 72),
                    $reason,
                    1
                );
            }
            if (class_exists('\App\Plugin\WebsiteMonitor\Module\Notify\Alert')) {
                \App\Plugin\WebsiteMonitor\Module\Notify\Alert::ipBanned(
                    $ip,
                    $seconds,
                    $rule,
                    ['geo' => Locator::text(Locator::lookup($ip))]
                );
            }
        } catch (\Throwable $e) {
            Log::exception('Signals::ban', $e, ['ip' => $ip]);
        }
    }

    /**
     * @param array<mixed> $message
     */
    private static function messageText(array $message): string
    {
        foreach ($message as $item) {
            if (is_string($item) && trim($item) !== '') {
                return $item;
            }
        }
        return json_encode($message, JSON_UNESCAPED_UNICODE) ?: '';
    }
}
