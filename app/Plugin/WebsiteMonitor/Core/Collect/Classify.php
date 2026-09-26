<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Collect;

use App\Plugin\WebsiteMonitor\Consts\Kind;

/**
 * 请求分类：全是纯字符串判断，没有一次 I/O。
 */
final class Classify
{
    /**
     * 路由 → 请求类型
     */
    public static function route(string $route): int
    {
        $route = strtolower($route);
        if (str_starts_with($route, '/admin/api/') || str_starts_with($route, '/admin/')) {
            return Kind::REQ_ADMIN;
        }
        if (str_starts_with($route, '/user/api/') || str_starts_with($route, '/api/')) {
            return Kind::REQ_API;
        }
        //插件自己的后台接口算后台，前台接口算 API
        if (str_starts_with($route, '/plugin/')) {
            return str_contains($route, '/admin/') || str_contains($route, '/panel/')
                ? Kind::REQ_ADMIN
                : Kind::REQ_API;
        }
        if (str_starts_with($route, '/user/') || $route === '/' || $route === '') {
            return Kind::REQ_PAGE;
        }
        if (str_starts_with($route, '/item/') || str_starts_with($route, '/cat/')) {
            return Kind::REQ_PAGE;
        }
        return Kind::REQ_OTHER;
    }

    public static function method(string $method): int
    {
        return match (strtoupper($method)) {
            'GET' => Kind::M_GET,
            'POST' => Kind::M_POST,
            'HEAD' => Kind::M_HEAD,
            'OPTIONS' => Kind::M_OPTIONS,
            'PUT' => Kind::M_PUT,
            'DELETE' => Kind::M_DELETE,
            default => Kind::M_OTHER,
        };
    }

    public static function methodName(int $method): string
    {
        return match ($method) {
            Kind::M_GET => 'GET',
            Kind::M_POST => 'POST',
            Kind::M_HEAD => 'HEAD',
            Kind::M_OPTIONS => 'OPTIONS',
            Kind::M_PUT => 'PUT',
            Kind::M_DELETE => 'DELETE',
            default => 'OTHER',
        };
    }

    /**
     * UA → 设备类型。与 App\Util\Client::getDeviceTypeByUa 的取值保持一致，
     * 但不调它 —— 那个方法会走一遍额外的正则，这里只要一次 str_contains 序列。
     */
    public static function device(string $ua): int
    {
        if ($ua === '') {
            return Kind::DEV_OTHER;
        }
        $ua = strtolower($ua);
        if (str_contains($ua, 'ipad')) {
            return Kind::DEV_IPAD;
        }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipod')) {
            return Kind::DEV_IPHONE;
        }
        if (str_contains($ua, 'android')) {
            //安卓平板通常不带 mobile
            return str_contains($ua, 'mobile') ? Kind::DEV_ANDROID : Kind::DEV_IPAD;
        }
        if (str_contains($ua, 'windows') || str_contains($ua, 'macintosh') || str_contains($ua, 'x11')
            || str_contains($ua, 'linux') || str_contains($ua, 'cros')) {
            return Kind::DEV_PC;
        }
        return Kind::DEV_OTHER;
    }

    /**
     * Accept-Language 的主语言段，如 zh-cn / en
     */
    public static function lang(string $header): string
    {
        if ($header === '') {
            return '';
        }
        $first = explode(',', $header, 2)[0];
        $first = explode(';', $first, 2)[0];
        $first = strtolower(trim($first));
        return preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $first) ? $first : '';
    }

    /**
     * 路径归一化：小写、去查询串、折叠重复斜杠、去尾斜杠。
     * 目的是让 /Item/12 与 /item/12/ 落到同一个页面条目上。
     */
    public static function path(string $route): string
    {
        $path = strtolower(trim($route));
        if ($path === '') {
            return '/';
        }
        $q = strpos($path, '?');
        if ($q !== false) {
            $path = substr($path, 0, $q);
        }
        $path = str_replace('\\', '/', $path);
        while (str_contains($path, '//')) {
            $path = str_replace('//', '/', $path);
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        if ($path === '') {
            $path = '/';
        }
        return $path[0] === '/' ? $path : '/' . $path;
    }

    /**
     * 是否命中忽略清单（前缀匹配）
     *
     * @param string[] $prefixes 已小写
     */
    public static function ignored(string $route, array $prefixes): bool
    {
        if ($prefixes === []) {
            return false;
        }
        $route = strtolower($route);
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && strncmp($route, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function kindText(int $kind): string
    {
        return match ($kind) {
            Kind::REQ_PAGE => lang('页面'),
            Kind::REQ_API => lang('接口'),
            Kind::REQ_ADMIN => lang('后台'),
            Kind::REQ_404 => lang('404'),
            Kind::REQ_WAF => lang('拦截'),
            default => lang('其它'),
        };
    }

    public static function deviceText(int $dev): string
    {
        return match ($dev) {
            Kind::DEV_PC => lang('电脑'),
            Kind::DEV_ANDROID => 'Android',
            Kind::DEV_IPHONE => 'iPhone',
            Kind::DEV_IPAD => lang('平板'),
            default => lang('其它'),
        };
    }
}
