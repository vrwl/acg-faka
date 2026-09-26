<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Referer;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Settings;

/**
 * 来源解析：分类、搜索引擎识别、关键词与 UTM 提取。
 *
 * 只在守护进程侧跑，每个不同的 Referer 解析一次就存进 wm_referer 字典。
 */
final class Parser
{
    private static ?array $data = null;

    /**
     * @param string $referer 原始 Referer 头
     * @param string $selfHost 本站域名（用来区分站内跳转）
     * @param array<string,string> $query 当前请求的查询参数（用来抓 UTM 与广告点击 id）
     *
     * @return array{
     *   type:int, host:string, url:string, engine:string, engine_name:string,
     *   keyword:string, utm_source:string, utm_medium:string, utm_campaign:string
     * }
     */
    public static function parse(string $referer, string $selfHost = '', array $query = []): array
    {
        $out = [
            'type' => Kind::REF_DIRECT,
            'host' => '',
            'url' => '',
            'engine' => '',
            'engine_name' => '',
            'keyword' => '',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
        ];

        $data = self::data();

        // ── UTM 与广告参数优先：带了这些就说明是投放来的，比 Referer 更可信
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $key) {
            $value = trim((string)($query[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = mb_substr($value, 0, 64);
            }
        }
        $isAd = $out['utm_source'] !== '';
        if (!$isAd) {
            foreach ((array)($data['ad_params'] ?? []) as $param) {
                if (isset($query[$param]) && trim((string)$query[$param]) !== '') {
                    $isAd = true;
                    break;
                }
            }
        }

        $referer = trim($referer);
        if ($referer !== '') {
            $out['url'] = mb_substr($referer, 0, 500);
            $host = strtolower((string)(parse_url($referer, PHP_URL_HOST) ?: ''));
            $out['host'] = $host;

            if ($host !== '') {
                if (self::isSelf($host, $selfHost)) {
                    $out['type'] = Kind::REF_INTERNAL;
                } elseif (($engine = self::matchEngine($host, $data)) !== null) {
                    $out['type'] = Kind::REF_SEARCH;
                    $out['engine'] = $engine['code'];
                    $out['engine_name'] = $engine['name'];
                    $out['keyword'] = self::keyword($referer, (string)$engine['q']);
                } elseif (($social = self::matchSocial($host, $data)) !== null) {
                    $out['type'] = Kind::REF_SOCIAL;
                    $out['engine'] = $social['code'];
                    $out['engine_name'] = $social['name'];
                } else {
                    $out['type'] = Kind::REF_LINK;
                }
            }
        }

        //广告归因优先级最高：投放带来的流量即使 Referer 是搜索引擎，也该算广告
        if ($isAd) {
            $out['type'] = Kind::REF_AD;
        }

        return $out;
    }

    /**
     * @return array{code:string,name:string,q:string}|null
     */
    private static function matchEngine(string $host, array $data): ?array
    {
        foreach ((array)($data['search'] ?? []) as $code => $item) {
            foreach (explode(',', (string)$item['host']) as $needle) {
                $needle = trim($needle);
                if ($needle !== '' && str_contains($host, $needle)) {
                    return ['code' => (string)$code, 'name' => (string)$item['name'], 'q' => (string)($item['q'] ?? '')];
                }
            }
        }
        return null;
    }

    /**
     * @return array{code:string,name:string}|null
     */
    private static function matchSocial(string $host, array $data): ?array
    {
        foreach ((array)($data['social'] ?? []) as $code => $item) {
            foreach (explode(',', (string)$item['host']) as $needle) {
                $needle = trim($needle);
                if ($needle !== '' && ($host === $needle || str_ends_with($host, '.' . $needle) || str_contains($host, $needle))) {
                    return ['code' => (string)$code, 'name' => (string)$item['name']];
                }
            }
        }
        return null;
    }

    /**
     * 从来源 URL 里抠搜索词。主流引擎早就加密了，能抠到的是少数。
     */
    private static function keyword(string $referer, string $params): string
    {
        if ($params === '') {
            return '';
        }
        $queryString = (string)(parse_url($referer, PHP_URL_QUERY) ?: '');
        if ($queryString === '') {
            return '';
        }
        parse_str($queryString, $parsed);
        foreach (explode(',', $params) as $key) {
            $key = trim($key);
            if ($key === '' || !isset($parsed[$key])) {
                continue;
            }
            $value = trim((string)$parsed[$key]);
            if ($value !== '') {
                return mb_substr($value, 0, 100);
            }
        }
        return '';
    }

    public static function typeText(int $type): string
    {
        return match ($type) {
            Kind::REF_INTERNAL => lang('站内'),
            Kind::REF_SEARCH => lang('搜索引擎'),
            Kind::REF_SOCIAL => lang('社交媒体'),
            Kind::REF_LINK => lang('外部链接'),
            Kind::REF_AD => lang('广告投放'),
            default => lang('直接访问'),
        };
    }

    /**
     * 是否本站域名。站内跳转不该算成「外部来源」。
     *
     * $selfHost 来自采集行里记下的 Host 头（最准，就是访客实际访问的那个域名），
     * 再加上后台配置的域名清单兜底 —— 站点可能有多个域名，配置项本身就是逗号分隔的。
     */
    private static function isSelf(string $host, string $selfHost): bool
    {
        if ($selfHost !== '' && ($host === $selfHost || str_ends_with($host, '.' . $selfHost))) {
            return true;
        }
        foreach (self::configuredHosts() as $candidate) {
            if ($host === $candidate || str_ends_with($host, '.' . $candidate)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 后台「域名与主站」里配置的域名（逗号分隔，可能带协议与路径）
     *
     * @return string[]
     */
    public static function configuredHosts(): array
    {
        static $hosts = null;
        if ($hosts !== null) {
            return $hosts;
        }
        $hosts = [];
        try {
            $raw = trim((string)\App\Model\Config::get('domain'));
            foreach (preg_split('/[\s,;，；\r\n]+/u', $raw) ?: [] as $item) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }
                $parsed = strtolower((string)(parse_url(
                    str_contains($item, '://') ? $item : 'http://' . $item,
                    PHP_URL_HOST
                ) ?: ''));
                if ($parsed !== '') {
                    $hosts[] = $parsed;
                }
            }
            $hosts = array_values(array_unique($hosts));
        } catch (\Throwable $e) {
            $hosts = [];
        }
        return $hosts;
    }

    /**
     * @return array<string,mixed>
     */
    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        try {
            $file = BASE_PATH . '/app/Plugin/' . Settings::PLUGIN . '/Data/engines.php';
            $loaded = is_file($file) ? require $file : [];
            return self::$data = is_array($loaded) ? $loaded : [];
        } catch (\Throwable $e) {
            return self::$data = [];
        }
    }
}
