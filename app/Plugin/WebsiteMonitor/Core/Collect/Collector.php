<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Collect;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Deferred;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Spool\Line;
use App\Plugin\WebsiteMonitor\Core\Spool\Writer;
use App\Plugin\WebsiteMonitor\Core\State;
use App\Plugin\WebsiteMonitor\Core\Waf\Surface;
use App\Util\Client;

final class Collector
{
    private static bool $on = false;
    private static bool $flushed = false;

    private static float $t0 = 0.0;
    private static string $ip = '';
    private static string $route = '';
    private static string $ua = '';
    private static string $ref = '';
    private static string $lang = '';
    private static string $host = '';
    private static int $kind = Kind::REQ_OTHER;
    private static int $method = Kind::M_GET;
    private static int $status = 0;
    private static int $spider = 0;
    private static int $dev = Kind::DEV_OTHER;
    private static int $flags = 0;
    private static int $uid = 0;

    private static int $atkKind = 0;
    private static string $atkRule = '';
    private static int $atkAct = 0;
    private static int $atkScore = 0;
    private static string $atkLevel = '';
    private static string $reqId = '';
    private static string $evidence = '';

    public static function active(): bool
    {
        return self::$on;
    }

    public static function ip(): string
    {
        return self::$ip;
    }

    public static function route(): string
    {
        return self::$route;
    }

    public static function ua(): string
    {
        return self::$ua;
    }

    public static function spiderId(): int
    {
        return self::$spider;
    }

    public static function begin(string $ip): void
    {
        if (self::$on) {
            return;
        }
        $state = State::get();
        if (($state['collect_mode'] ?? State::MODE_FULL) === State::MODE_OFF) {
            return;
        }

        $route = Surface::route();

        if (Classify::ignored($route, (array)($state['ignore'] ?? []))) {
            return;
        }

        self::$on = true;
        self::$t0 = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        self::$ip = $ip;
        self::$route = $route;
        self::$ua = Surface::ua();
        self::$ref = Surface::referer();
        self::$lang = Classify::lang(Surface::acceptLang());
        self::$host = Surface::host();
        self::$kind = Classify::route($route);
        self::$method = Classify::method(Surface::method());
        self::$dev = Classify::device(self::$ua);

        if (self::$kind === Kind::REQ_ADMIN && ($state['count_admin'] ?? true) === false) {
            self::$on = false;
            return;
        }

        self::$spider = Spider::match(self::$ua);
        if (self::$spider > 0) {
            self::$flags |= Kind::F_SPIDER;

            Identity::fingerprint((int)self::$t0, $ip, self::$ua, self::$lang);
        } else {
            Identity::resolve((int)self::$t0, (int)($state['session_timeout'] ?? 1800));
        }
        self::$flags |= Identity::flags();

        if (isset($_COOKIE['ACG-SHOP'])) {
            self::$flags |= Kind::F_MEMBER;
        }

        Deferred::onShutdown([self::class, 'flush']);
    }

    public static function outcome(int $kind, int $status): void
    {
        if (!self::$on) {
            return;
        }

        if ($kind !== Kind::REQ_OTHER && self::$kind !== Kind::REQ_ADMIN) {
            self::$kind = $kind;
        }
        if ($status > 0) {
            self::$status = $status;
        }
    }

    public static function setUid(int $uid): void
    {
        if (self::$on && $uid > 0) {
            self::$uid = $uid;
            self::$flags |= Kind::F_MEMBER;
        }
    }

    public static function markAttack(
        int $atkKind,
        string $rule,
        int $act = Kind::ACT_LOG,
        int $score = 0,
        string $level = 'warn',
        string $reqId = '',
        string $evidence = ''
    ): void {
        if (!self::$on) {
            self::$on = true;
            self::$t0 = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
            self::$ip = self::$ip !== '' ? self::$ip : Client::getAddress();
            self::$route = Surface::route();
            self::$ua = Surface::ua();
            self::$kind = Kind::REQ_WAF;
            self::$method = Classify::method(Surface::method());
            Deferred::onShutdown([self::class, 'flush']);
        }
        self::$flags |= Kind::F_ATTACK;
        self::$atkKind = $atkKind;
        self::$atkRule = $rule;
        self::$atkAct = $act;
        self::$atkScore = $score;
        self::$atkLevel = $level;
        if ($reqId !== '') {
            self::$reqId = $reqId;
        }
        if ($evidence !== '') {
            self::$evidence = \App\Plugin\WebsiteMonitor\Core\Redact::blob($evidence);
        }
        if ($act >= Kind::ACT_BLOCK) {
            self::$flags |= Kind::F_DENIED;
            self::$kind = Kind::REQ_WAF;
        }
    }

    public static function markRuleHit(): void
    {
        self::$flags |= Kind::F_RULE_HIT;
    }

    public static function markAdminExempt(): void
    {
        self::$flags |= Kind::F_ADMIN_EXEMPT;
    }

    public static function flush(): void
    {
        if (!self::$on || self::$flushed) {
            return;
        }
        self::$flushed = true;

        try {
            $state = State::get();
            $lean = ($state['collect_mode'] ?? State::MODE_FULL) === State::MODE_LEAN;
            $now = (int)self::$t0;
            $ms = (int)min(65535, max(0, (microtime(true) - self::$t0) * 1000));

            $status = self::$status > 0 ? self::$status : (int)(http_response_code() ?: 200);

            $sample = (int)($state['sample'] ?? 100);
            $sampled = $sample >= 100 || mt_rand(1, 100) <= $sample;

            if ($sampled || (self::$flags & Kind::F_ATTACK) !== 0) {
                self::$flags |= Kind::F_SAMPLED;
            }

            $row = [
                'ts' => $now,
                'ms' => $ms,
                'status' => $status,
                'kind' => self::$kind,
                'method' => self::$method,
                'vid' => Identity::vid(),
                'sid' => Identity::sid(),
                'uid' => self::$uid,
                'flags' => self::$flags,
                'ip' => self::$ip,
                'path' => self::$route,
                'query' => self::queryString(),
                'ref' => mb_substr(self::$ref, 0, 500),
                'uahash' => self::$ua === '' ? '' : substr(md5(self::$ua), 0, 12),

                'ua' => ((self::$flags & Kind::F_NEW_SESSION) !== 0 || $sampled) ? mb_substr(self::$ua, 0, 500) : '',
                'lang' => self::$lang,
                'spider' => self::$spider,
                'host' => self::$host,
                'dev' => self::$dev,
                'atk_kind' => self::$atkKind,
                'atk_rule' => self::$atkRule,
                'atk_act' => self::$atkAct,
                'atk_score' => self::$atkScore,
                'atk_level' => self::$atkLevel,
                'req_id' => self::$reqId,

                'evidence' => self::$evidence,
            ];

            Writer::append(Line::pack($row, $lean));
            self::maybeRelay();
        } catch (\Throwable $e) {
            Log::exception('Collector::flush', $e);
        }
    }

    private static function maybeRelay(): void
    {
        if (State::daemonAlive()) {
            return;
        }
        if (!class_exists('\App\Plugin\WebsiteMonitor\Core\Ingest\Relay')) {
            return;
        }
        try {
            \App\Plugin\WebsiteMonitor\Core\Ingest\Relay::run();
        } catch (\Throwable $e) {
            Log::exception('Collector::maybeRelay', $e);
        }
    }

    private static function queryString(): string
    {
        $req = Surface::request();
        if ($req === null) {
            return '';
        }
        $all = $req->unsafeGet();
        if (!is_array($all)) {
            return '';
        }
        unset($all['s'], $all['_PARAMETER'], $all['_route']);
        if ($all === []) {
            return '';
        }
        $parts = [];
        foreach ($all as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $parts[] = $key . '=' . \App\Plugin\WebsiteMonitor\Core\Redact::value((string)$key, mb_substr((string)$value, 0, 64));
            if (count($parts) >= 12) {
                break;
            }
        }
        return mb_substr(implode('&', $parts), 0, 180);
    }

    public static function reset(): void
    {
        self::$on = false;
        self::$flushed = false;
        self::$flags = 0;
        self::$status = 0;
        self::$spider = 0;
        self::$uid = 0;
        self::$atkKind = 0;
        self::$atkRule = '';
        self::$atkAct = 0;
        self::$atkScore = 0;
        self::$atkLevel = '';
        self::$reqId = '';
        self::$evidence = '';
        Identity::reset();
        Surface::reset();
    }
}
