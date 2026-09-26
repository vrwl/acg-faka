<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Hook;

use App\Plugin\WebsiteMonitor\Api\Decision;
use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Acl\AdminGuard;
use App\Plugin\WebsiteMonitor\Core\Acl\Ban;
use App\Plugin\WebsiteMonitor\Core\Acl\Matcher;
use App\Plugin\WebsiteMonitor\Core\Acl\Region;
use App\Plugin\WebsiteMonitor\Core\Collect\Collector;
use App\Plugin\WebsiteMonitor\Core\Collect\Spider;
use App\Plugin\WebsiteMonitor\Core\Ip;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;
use App\Plugin\WebsiteMonitor\Core\Waf\Responder;
use App\Plugin\WebsiteMonitor\Core\Waf\Surface;
use App\Util\Client;
use Kernel\Annotation\Hook;

/**
 * KERNEL_INIT (0x30) —— 全站唯一的防火墙与采集入口。
 *
 * 这是插件能拿到的最早时机：DB 已连、Request 已就绪，但路由未解析、拦截器未跑、
 * 控制器未实例化。在这里拦下的请求，连一次业务查询都不会产生。
 *
 * 判决顺序严格按「成本从低到高」排列，正常请求（99.9%）在第 7 步之前就返回了。
 * 全流程预算 ≈ 20µs、**零 SQL**。任何一步抛异常都 fail-open —— 防护本身绝不能
 * 把站点带崩。
 */
class Guard
{
    #[Hook(point: \App\Consts\Hook::KERNEL_INIT)]
    public function guard(): void
    {
        try {
            $this->run();
        } catch (\Throwable $e) {
            //fail-open：宁可漏防一次，也不能让站点 500
            try {
                Log::exception('Guard::run', $e);
            } catch (\Throwable $ignored) {
            }
        }
    }

    private function run(): void
    {
        // ── 0. 紧急停用（1 次 stat）
        //    后台都进不去时，站长在服务器建个空文件就能立刻停掉全部防护
        if (Runtime::disabled()) {
            return;
        }

        // ── 1. 插件开关（opcache 数组读，无 I/O）
        if (!Settings::enabled() || Settings::bool('emergency_off')) {
            return;
        }

        // ── 2. 编译产物（opcache require）
        $state = State::get();
        $rules = State::rules();
        $thresholds = (array)($rules['thresholds'] ?? []);

        // ── 3. 客户端 IP
        //    内核第 52 行 new Request() 时已调过一次 getAddress()，
        //    Client 内部的 mode / trusted_proxies 静态缓存此刻是热的，这次调用几乎零成本
        $ip = Client::getAddress();
        if ($ip === '') {
            return;
        }
        $packed = Ip::pack($ip);

        //先开采集：即使后面被拦，这条记录也要留痕
        Collector::begin($ip);

        if ($packed === null) {
            return;
        }

        $aclEnabled = (bool)($thresholds['acl_enabled'] ?? true);
        $route = Surface::route();

        // ── 4. 永久放行 / 白名单（哈希查，O(1)）
        if (AdminGuard::alwaysAllowed($packed, $rules)) {
            return;
        }
        if ($aclEnabled && Matcher::matchPacked($packed, (array)($rules['acl']['allow'] ?? [])) !== null) {
            return;
        }

        $decision = Decision::make($ip, $route, Surface::ua(), Surface::method());
        $decision->observeOnly = ($thresholds['waf_mode'] ?? Settings::WAF_OBSERVE) === Settings::WAF_OBSERVE;
        $decision->status = (int)($thresholds['block_status_code'] ?? 403);

        // ── 5. 自动封禁（1 次 is_file，惰性过期）
        if ($ban = Ban::get($ip)) {
            $decision->hit('acl.ban:' . $ban['rule'], Kind::ATK_BLACKLIST, Decision::BLOCK, 'warn');
            $decision->publicReason = lang('你的 IP 已被本站安全策略暂时封禁');
            $this->finish($decision, $thresholds);
            return;
        }

        // ── 6. 人工黑名单
        if ($aclEnabled) {
            $denyId = Matcher::matchPacked($packed, (array)($rules['acl']['deny'] ?? []));
            if ($denyId !== null) {
                $decision->hit('acl.deny:' . $denyId, Kind::ATK_BLACKLIST, Decision::BLOCK, 'warn');
                $decision->publicReason = lang('你的 IP 不在本站允许的访问范围内');
                $this->finish($decision, $thresholds);
                return;
            }
        }

        // ── 7. 地区规则（只有开启时才会触发 GeoIP 查询）
        if ($aclEnabled && ($rules['region']['mode'] ?? 'off') !== 'off') {
            $code = Region::deny($ip, (array)$rules['region'], $route);
            if ($code !== null) {
                $decision->hit('acl.region:' . $code, Kind::ATK_REGION, Decision::BLOCK, 'warn');
                $decision->publicReason = lang('你所在的地区暂时无法访问本站');
                $this->finish($decision, $thresholds);
                return;
            }
        }

        // ── 8. 蜘蛛判定（只做 UA 子串匹配 + 状态文件读，绝不做 DNS）
        $spider = Collector::spiderId();
        $spiderVerified = false;
        if ($spider > 0) {
            if (Spider::isScanner($spider)) {
                //安全扫描器不是正经爬虫
                $decision->hit('bot.scanner', Kind::ATK_SCANNER, Decision::BLOCK, 'warn', 8);
            } elseif (Spider::verifiable($spider)) {
                $verdict = Spider::verifyState($ip);
                if ($verdict === Kind::SPV_REAL) {
                    $spiderVerified = true;
                } elseif ($verdict === Kind::SPV_FAKE) {
                    if (($thresholds['ua_block_fake_spider'] ?? true)) {
                        $decision->hit('ua.fake_spider', Kind::ATK_FAKE_SPIDER, Decision::BLOCK, 'warn', 8);
                    }
                } else {
                    //待验证：本次按疑似蜘蛛处理（豁免 CC，WAF 照常），交给守护任务去反查
                    Spider::queueVerify($ip, $spider);
                }
            }
        }

        // ── 9. WAF 规则（P7；未就绪时整段跳过）
        if (!$decision->blocking()
            && ($thresholds['waf_enabled'] ?? true)
            && class_exists('\App\Plugin\WebsiteMonitor\Core\Waf\Engine')) {
            \App\Plugin\WebsiteMonitor\Core\Waf\Engine::inspect($decision, $rules);
        }

        // ── 10. CC 限频（白名单、已验证蜘蛛、豁免路径都不计数）
        if (!$decision->blocking()
            && ($thresholds['cc_enabled'] ?? true)
            && class_exists('\App\Plugin\WebsiteMonitor\Core\Cc\Limiter')) {
            $exempt = ($spiderVerified && ($thresholds['cc_exempt_spider'] ?? true))
                || $this->ccExemptPath($route, $thresholds);
            if (!$exempt) {
                \App\Plugin\WebsiteMonitor\Core\Cc\Limiter::inspect($decision, $thresholds);
            }
        }

        if ($decision->action === Decision::PASS) {
            return;
        }

        // ── 11. 让其它插件否决或加严（传对象，返回 null 不短路整条链）
        try {
            $ret = hook(0x7C100, $decision);
            if ($ret === true) {
                $decision->hardAllow('hook', 'bool-return');
            } elseif ($ret === false) {
                $decision->escalate(Decision::BLOCK, 'bool-return', 'hook');
            }
        } catch (\Throwable $e) {
            Log::exception('Guard::hook', $e);
        }

        $this->finish($decision, $thresholds);
    }

    /**
     * 收尾：管理员豁免 → 记录 → 封禁 → 拦截
     */
    private function finish(Decision $decision, array $thresholds): void
    {
        if ($decision->action === Decision::PASS) {
            return;
        }

        // ── 管理员豁免（防锁死第 4 道）
        //    先看有没有后台 cookie：没有就绝无可能是管理员，零成本跳过；
        //    有才付那两次查询的钱 —— 所以正常访客永远不会为这个检查买单。
        if ($decision->blocking()
            && ($thresholds['trust_admin'] ?? true)
            && AdminGuard::hasAdminCookie()
            && AdminGuard::isLoggedInAdmin()) {
            AdminGuard::rememberAdminIp($decision->ip);
            Collector::markAdminExempt();
            Collector::markAttack(
                $decision->ruleKind,
                (string)$decision->ruleId,
                Kind::ACT_LOG,
                $decision->score,
                $decision->level,
                $decision->requestId()
            );
            //管理员被自己的规则拦到，说明规则有问题，值得记一笔
            Log::warn('管理员命中了拦截规则，已豁免', [
                'ip' => $decision->ip,
                'rule' => $decision->ruleId,
                'route' => $decision->route,
            ]);
            return;
        }

        $observe = $decision->observeOnly;

        //记录（观察模式下 act 降级为「仅记录」，blocked=0）
        Collector::markAttack(
            $decision->ruleKind,
            (string)$decision->ruleId,
            $observe ? Kind::ACT_LOG : $decision->actKind(),
            $decision->score,
            $decision->level,
            $decision->requestId(),
            $decision->evidence === [] ? '' : \App\Plugin\WebsiteMonitor\Core\Db::json($decision->evidence)
        );

        if ($observe) {
            //观察模式：判决照算、照记，但一律放行
            return;
        }

        //封禁
        if ($decision->action === Decision::BAN) {
            try {
                Ban::escalate(
                    $decision->ip,
                    (string)$decision->ruleId,
                    (array)($thresholds['ladder'] ?? [300, 1800, 7200, 86400, 604800]),
                    (int)($thresholds['ban_permanent_after'] ?? 6),
                    (int)($thresholds['ban_reset_hours'] ?? 72),
                    $decision->publicReason,
                    1
                );
            } catch (\Throwable $e) {
                Log::exception('Guard::ban', $e, ['ip' => $decision->ip]);
            }
        }

        //广播给其它插件
        try {
            hook(0x7C101, $decision->toArray());
        } catch (\Throwable $e) {
        }

        if ($decision->action === Decision::THROTTLE) {
            $decision->status = 429;
        }

        Responder::deny($decision, $thresholds);
    }

    /**
     * @param array<string,mixed> $thresholds
     */
    private function ccExemptPath(string $route, array $thresholds): bool
    {
        foreach ((array)($thresholds['cc_exempt_paths'] ?? []) as $prefix) {
            if ($prefix !== '' && str_starts_with($route, (string)$prefix)) {
                return true;
            }
        }
        return false;
    }
}
