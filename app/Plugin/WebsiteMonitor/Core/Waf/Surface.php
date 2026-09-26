<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Waf;

use App\Plugin\WebsiteMonitor\Core\Collect\Classify;
use Kernel\Consts\Base;
use Kernel\Context\Interface\Request;
use Kernel\Util\Context;

/**
 * 请求原始面的唯一取值入口。**全插件禁止直接读 $_GET / $_POST / $_SERVER。**
 *
 * 原因（这是整个防火墙成立的前提）：内核在 kernel/Kernel.php:52 就 new 了 Request，
 * 而 Kernel\Context\Abstract\Request 的构造函数会把
 *   $_POST / $_GET / $_REQUEST / $_SERVER
 * 全部过一遍 HTMLPurifier + htmlspecialchars(strip_tags())。等 KERNEL_INIT 触发时，
 * 攻击载荷早被洗干净了 —— 拿这些超全局做 WAF 匹配等于自欺欺人：
 * 规则永远不命中，同时合法参数里的 & < 被改写反而制造误报。
 *
 * 真正原始的值只在这几处（都在净化之前拷贝完成，见 kernel/Context/Request.php:12-29）：
 *   unsafeGet() / unsafePost() / unsafeJson() / raw() / header() / $_COOKIE / $_FILES
 *
 * 唯二的例外是 REQUEST_METHOD 与 REMOTE_ADDR：枚举值和 IP 字面量，purify 对它们无害。
 */
final class Surface
{
    public const PATH = 'path';
    public const QUERY = 'query';
    public const BODY = 'body';
    public const COOKIE = 'cookie';
    public const HEADER = 'header';
    public const FILES = 'files';

    private const MAX_PATH = 2048;
    private const MAX_QUERY = 8192;
    private const MAX_COOKIE = 4096;
    private const MAX_HEADER = 2048;

    /** @var array<string,string|null> 各面的展平结果，懒加载 */
    private static array $flat = [];

    private static ?Request $request = null;
    private static bool $requestResolved = false;
    private static ?string $route = null;

    public static function request(): ?Request
    {
        if (self::$requestResolved) {
            return self::$request;
        }
        self::$requestResolved = true;
        try {
            $req = Context::get(Request::class);
            self::$request = $req instanceof Request ? $req : null;
        } catch (\Throwable $e) {
            self::$request = null;
        }
        return self::$request;
    }

    /**
     * 归一化后的路由（小写、去查询串、折叠斜杠）。
     *
     * 注意 Base::ROUTE 是内核在净化**之前**用 $routePath 局部变量算出来的，所以是原始值；
     * 这里仍优先用 unsafeGet('s')，让意图更明确、也不依赖内核的实现细节。
     */
    public static function route(): string
    {
        if (self::$route !== null) {
            return self::$route;
        }
        $raw = '';
        $req = self::request();
        if ($req !== null) {
            $value = $req->unsafeGet('s');
            if (is_string($value)) {
                $raw = $value;
            }
        }
        if ($raw === '') {
            try {
                $raw = (string)Context::get(Base::ROUTE);
            } catch (\Throwable $e) {
                $raw = '';
            }
        }
        return self::$route = Classify::path($raw);
    }

    /**
     * 用于扫描器检测的路径：比 route() 多做一层 urldecode，对抗 %2e%2e 这类双编码。
     */
    public static function decodedPath(): string
    {
        $path = self::route();
        $decoded = rawurldecode($path);
        return substr($decoded === $path ? $path : strtolower(str_replace('\\', '/', $decoded)), 0, self::MAX_PATH);
    }

    public static function method(): string
    {
        //枚举值，purify 无害
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /**
     * User-Agent 原文。header() 里的值是在净化前从 $_SERVER 拷贝的，所以是原始的。
     */
    public static function ua(): string
    {
        return self::header('UserAgent');
    }

    public static function referer(): string
    {
        return self::header('Referer');
    }

    public static function acceptLang(): string
    {
        return self::header('AcceptLanguage');
    }

    public static function host(): string
    {
        $host = self::header('Host');
        if ($host === '') {
            return '';
        }
        $host = strtolower(trim($host));
        //去端口
        if (str_contains($host, ':') && !str_contains($host, ']')) {
            $host = explode(':', $host, 2)[0];
        }
        return $host;
    }

    public static function header(string $name): string
    {
        $req = self::request();
        if ($req === null) {
            return '';
        }
        try {
            $value = $req->header($name);
            return is_string($value) ? $value : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function isAdminRoute(): bool
    {
        $route = self::route();
        return str_starts_with($route, '/admin/')
            || str_starts_with($route, '/plugin/')
            || $route === '/admin';
    }

    public static function isJsonWanted(): bool
    {
        $route = self::route();
        if (str_starts_with($route, '/user/api/') || str_starts_with($route, '/admin/api/')) {
            return true;
        }
        if (preg_match('#^/plugin/[^/]+/(admin|api)/#', $route) === 1) {
            return true;
        }
        return strcasecmp(self::header('XRequestedWith'), 'XMLHttpRequest') === 0
            || str_contains(strtolower(self::header('Accept')), 'application/json')
            || str_contains(strtolower(self::header('ContentType')), 'application/json');
    }

    /* ───────────────────── WAF 检测面 ───────────────────── */

    /**
     * 取某个面的展平文本。懒加载 + 进程内缓存：没被规则用到的面永远不会被构造。
     *
     * @param string[] $excludeFields 要跳过的字段名（富文本字段，误报大户）
     */
    public static function flat(string $surface, array $excludeFields = [], int $bodyMaxBytes = 65536): string
    {
        if (isset(self::$flat[$surface])) {
            return (string)self::$flat[$surface];
        }
        $value = match ($surface) {
            self::PATH => self::decodedPath(),
            self::QUERY => self::flattenQuery(),
            self::BODY => self::flattenBody($excludeFields, $bodyMaxBytes),
            self::COOKIE => self::flattenCookie(),
            self::HEADER => self::flattenHeader(),
            self::FILES => self::flattenFiles(),
            default => '',
        };
        return self::$flat[$surface] = $value;
    }

    private static function flattenQuery(): string
    {
        $req = self::request();
        if ($req === null) {
            return '';
        }
        $all = $req->unsafeGet();
        if (!is_array($all)) {
            return '';
        }
        //路由本身在 PATH 面已经查过，这里去掉避免重复匹配
        unset($all['s'], $all['_PARAMETER'], $all['_route']);
        return self::flatten($all, self::MAX_QUERY);
    }

    /**
     * @param string[] $excludeFields
     */
    private static function flattenBody(array $excludeFields, int $maxBytes): string
    {
        $req = self::request();
        if ($req === null) {
            return '';
        }
        $parts = [];
        $post = $req->unsafePost();
        if (is_array($post) && $post !== []) {
            $parts[] = self::flatten(self::stripFields($post, $excludeFields), $maxBytes);
        }
        $json = $req->unsafeJson();
        if (is_array($json) && $json !== []) {
            $parts[] = self::flatten(self::stripFields($json, $excludeFields), $maxBytes);
        }
        if ($parts === []) {
            //multipart 时 php://input 是空的（PHP 语言限制），这属于正常情况
            $raw = $req->raw();
            if (is_string($raw) && $raw !== '') {
                $parts[] = substr($raw, 0, $maxBytes);
            }
        }
        return substr(implode("\n", $parts), 0, $maxBytes);
    }

    private static function flattenCookie(): string
    {
        //$_COOKIE 全程未被净化
        $cookies = $_COOKIE;
        //自己的 cookie 与框架会话不参与匹配
        unset(
            $cookies['ACG-SHOP'],
            $cookies['MANAGE_USER'],
            $cookies[\App\Plugin\WebsiteMonitor\Core\Collect\Identity::COOKIE_VID],
            $cookies[\App\Plugin\WebsiteMonitor\Core\Collect\Identity::COOKIE_SID]
        );
        return self::flatten($cookies, self::MAX_COOKIE);
    }

    private static function flattenHeader(): string
    {
        $names = ['UserAgent', 'Referer', 'XForwardedFor', 'Accept', 'ContentType', 'Origin', 'XRealIp'];
        $parts = [];
        foreach ($names as $name) {
            $value = self::header($name);
            if ($value !== '') {
                $parts[] = $name . '=' . $value;
            }
        }
        return substr(implode('&', $parts), 0, self::MAX_HEADER);
    }

    private static function flattenFiles(): string
    {
        if ($_FILES === []) {
            return '';
        }
        $parts = [];
        foreach ($_FILES as $field => $file) {
            $names = (array)($file['name'] ?? []);
            foreach ((is_array($file['name'] ?? null) ? $names : [$file['name'] ?? '']) as $name) {
                if (is_string($name) && $name !== '') {
                    $parts[] = $field . '=' . $name;
                }
            }
        }
        return substr(implode('&', $parts), 0, self::MAX_HEADER);
    }

    /**
     * 递归展平成 k=v&k=v，并对每个值补一份 urldecode 后的副本。
     *
     * 补 decode 是必需的：PHP 已经解码过一次，攻击者用 %2527 就能绕过只匹配一次解码的规则。
     *
     * @param array<mixed> $data
     */
    private static function flatten(array $data, int $maxBytes, string $prefix = '', int $depth = 0): string
    {
        if ($depth > 6) {
            return '';
        }
        $parts = [];
        $size = 0;
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (is_array($value)) {
                $nested = self::flatten($value, $maxBytes - $size, $name, $depth + 1);
                if ($nested !== '') {
                    $parts[] = $nested;
                    $size += strlen($nested);
                }
            } elseif (is_scalar($value) || $value === null) {
                $text = (string)$value;
                $piece = $name . '=' . $text;
                $decoded = rawurldecode($text);
                if ($decoded !== $text) {
                    $piece .= '&' . $name . '=' . $decoded;
                }
                $parts[] = $piece;
                $size += strlen($piece);
            }
            if ($size >= $maxBytes) {
                break;
            }
        }
        return substr(implode('&', $parts), 0, max(0, $maxBytes));
    }

    /**
     * 去掉排除字段。支持 `tpl_*` 这种前缀通配。
     *
     * @param array<mixed> $data
     * @param string[] $excludeFields
     * @return array<mixed>
     */
    private static function stripFields(array $data, array $excludeFields): array
    {
        if ($excludeFields === []) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            $name = strtolower((string)$key);
            $skip = false;
            foreach ($excludeFields as $pattern) {
                if ($pattern === '') {
                    continue;
                }
                if (str_ends_with($pattern, '*')) {
                    if (str_starts_with($name, rtrim($pattern, '*'))) {
                        $skip = true;
                        break;
                    }
                } elseif ($name === $pattern) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $out[$key] = is_array($value) ? self::stripFields($value, $excludeFields) : $value;
            }
        }
        return $out;
    }

    public static function reset(): void
    {
        self::$flat = [];
        self::$request = null;
        self::$requestResolved = false;
        self::$route = null;
    }
}
