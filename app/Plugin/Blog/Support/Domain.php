<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Support;

use App\Plugin\Blog\Core\Settings;

/**
 * 独立域名绑定。
 *
 * 站长把某个域名解析到本站后填进设置，该域名下就只有博客：
 * 根路径直接是博客首页，商城的任何页面一律不可达，站内链接也不再出现商城地址——
 * 用途是把博客当独立文档站发给下游对接的人看。
 *
 * 判定只认 Host 头。反代场景下 Host 由 nginx 透传，和证书/解析是一致的；
 * 这里不读 X-Forwarded-Host，那个头客户端可伪造，会让人绕过隔离拿到商城页面。
 */
final class Domain
{
    private static ?array $bound = null;
    private static ?bool $active = null;

    /** 配置项变更后清掉本请求缓存（保存设置时调用） */
    public static function refresh(): void
    {
        self::$bound = null;
        self::$active = null;
    }

    /**
     * 归一化域名输入：站长常常连 https:// 和结尾斜杠一起粘进来。
     * 去协议、去路径、去端口、去首尾点，转小写。非法则返回空串。
     */
    public static function normalize(string $raw): string
    {
        $host = trim($raw);
        if ($host === '') {
            return '';
        }
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host) ?? $host;
        $host = explode('/', $host)[0];          //去路径
        $host = explode('?', $host)[0];
        $host = explode('#', $host)[0];
        $host = explode('@', $host);             //去可能的 user:pass@
        $host = end($host);
        $host = explode(':', $host)[0];          //去端口
        $host = strtolower(trim($host, ". \t\n\r\0\x0B"));

        //标准主机名：点分标签，每段字母数字与连字符，连字符不在两端
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)
            && $host !== 'localhost') {
            return '';
        }
        return $host;
    }

    /** @return string[] 已绑定的域名（可填多个，逗号或换行分隔） */
    public static function bound(): array
    {
        if (self::$bound === null) {
            $list = [];
            foreach (Settings::list('standalone_domain') as $raw) {
                $host = self::normalize($raw);
                if ($host !== '') {
                    $list[$host] = true;
                }
            }
            self::$bound = array_keys($list);
        }
        return self::$bound;
    }

    /** 当前请求的 Host（去端口、小写） */
    public static function currentHost(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $host = explode(':', $host)[0];
        return strtolower(trim($host, '. '));
    }

    /** 当前请求是否来自绑定的独立域名 */
    public static function active(): bool
    {
        if (self::$active === null) {
            $bound = self::bound();
            self::$active = $bound !== [] && in_array(self::currentHost(), $bound, true);
        }
        return self::$active;
    }
}
