<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Collect;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * 访客身份：区分 UV / 访问次数 / PV。
 *
 * **绝不调用 session_start()**。Kernel\Util\Session 的 get/set 每次都要 open + flock + close
 * 一个会话文件，单次 0.3–1ms，放在每个请求上不可接受。这里用两个自有 Cookie：
 *
 *   wm_vid  16位hex + 8位HMAC签名     730 天    独立访客（UV）
 *   wm_sid  16位hex.起始时间.末次时间  会话级    一次访问（visits / 跳出率 / 停留时长）
 *
 * 值全部落在 [0-9a-z.] 内，天然绕开内核 WAF 的 cookie 规则，也不会被 HTMLPurifier 改写。
 */
final class Identity
{
    public const COOKIE_VID = 'wm_vid';
    public const COOKIE_SID = 'wm_sid';

    private const VID_TTL = 63072000;

    /** sid 里的时间戳每 60 秒才回写一次，避免每个请求都发一个 Set-Cookie 头 */
    private const SID_REWRITE = 60;

    private static string $vid = '';
    private static string $sid = '';
    private static int $flags = 0;
    private static int $sessionStart = 0;
    private static bool $resolved = false;

    /**
     * 解析（或签发）访客与会话标识。
     *
     * @param int $now 当前时间戳
     * @param int $timeout 会话超时秒数
     */
    public static function resolve(int $now, int $timeout): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;

        $secret = (string)(State::get()['secret'] ?? '');

        // ── 访客 id
        $vid = '';
        $raw = (string)($_COOKIE[self::COOKIE_VID] ?? '');
        if (strlen($raw) === 24 && ctype_xdigit($raw)) {
            $candidate = substr($raw, 0, 16);
            if (hash_equals(self::sign($candidate, $secret), substr($raw, 16, 8))) {
                $vid = $candidate;
            }
        }
        if ($vid === '') {
            $vid = self::randomId();
            self::$flags |= Kind::F_NEW_VISITOR;
            self::writeCookie(self::COOKIE_VID, $vid . self::sign($vid, $secret), $now + self::VID_TTL);
        }
        self::$vid = $vid;

        // ── 会话 id
        $sid = '';
        $start = 0;
        $last = 0;
        $rawSid = (string)($_COOKIE[self::COOKIE_SID] ?? '');
        if ($rawSid !== '' && substr_count($rawSid, '.') === 2) {
            [$id, $s, $l] = explode('.', $rawSid, 3);
            if (strlen($id) === 16 && ctype_xdigit($id)) {
                $sid = $id;
                $start = (int)base_convert($s, 36, 10);
                $last = (int)base_convert($l, 36, 10);
            }
        }

        //超时、跨自然日、或本来就没有 → 开一次新会话
        $expired = $sid === ''
            || $last <= 0
            || ($now - $last) > $timeout
            || date('Ymd', $last) !== date('Ymd', $now);

        if ($expired) {
            $sid = self::randomId();
            $start = $now;
            $last = $now;
            self::$flags |= Kind::F_NEW_SESSION;
            self::writeCookie(self::COOKIE_SID, $sid . '.' . base_convert((string)$start, 10, 36) . '.' . base_convert((string)$last, 10, 36), 0);
        } elseif ($now - $last >= self::SID_REWRITE) {
            self::writeCookie(self::COOKIE_SID, $sid . '.' . base_convert((string)$start, 10, 36) . '.' . base_convert((string)$now, 10, 36), 0);
        }

        self::$sid = $sid;
        self::$sessionStart = $start;
    }

    /**
     * 不吃 Cookie 的客户端（爬虫、curl、隐私模式）用指纹兜底。
     *
     * 盐里带当天日期是**故意**的：指纹不跨天关联，对隐私更友好，
     * 也让指纹型访客的基数天然有界，不会把 wm_visitor 撑爆。
     */
    public static function fingerprint(int $now, string $ip, string $ua, string $lang = ''): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;

        $secret = (string)(State::get()['secret'] ?? '');
        $seed = $ip . "\x1f" . $ua . "\x1f" . $lang . "\x1f" . date('Ymd', $now) . "\x1f" . $secret;
        $vid = substr(md5($seed), 0, 16);

        self::$vid = $vid;
        //指纹型访客没有真正的会话概念，用「小时」当会话窗口
        self::$sid = substr(md5($seed . '|' . (int)floor($now / 3600)), 0, 16);
        self::$sessionStart = (int)(floor($now / 3600) * 3600);
        self::$flags |= Kind::F_FINGERPRINT;
    }

    public static function vid(): string
    {
        return self::$vid;
    }

    public static function sid(): string
    {
        return self::$sid;
    }

    public static function flags(): int
    {
        return self::$flags;
    }

    public static function sessionStart(): int
    {
        return self::$sessionStart;
    }

    public static function reset(): void
    {
        self::$vid = '';
        self::$sid = '';
        self::$flags = 0;
        self::$sessionStart = 0;
        self::$resolved = false;
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    private static function sign(string $vid, string $secret): string
    {
        return substr(hash_hmac('sha1', $vid, $secret), 0, 8);
    }

    private static function randomId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return substr(md5(uniqid('wm', true)), 0, 16);
        }
    }

    /**
     * KERNEL_INIT 在内核发安全响应头之前触发，此刻 headers_sent() 必为 false。
     * 即便如此也做一次检查 —— 别的插件可能在更早的位置输出了内容。
     */
    private static function writeCookie(string $name, string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        try {
            $https = (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) === 'on')
                || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
                || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            @setcookie($name, $value, [
                'expires' => $expires,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $https,
            ]);
            //同一请求内后续代码（以及本类自己）能立刻读到
            $_COOKIE[$name] = $value;
        } catch (\Throwable $e) {
        }
    }
}
