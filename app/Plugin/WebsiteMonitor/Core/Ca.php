<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

/**
 * 解析一份**真实可用**的 CA 根证书包。
 *
 * 为什么需要它：守护进程跑在 ThreadManager 自带的 swoole-cli 上，那个二进制既没有
 * openssl.cafile 也没有 curl.cainfo，于是 cURL 回落到编译时写死的默认路径
 * （/etc/ssl/certs/ca-certificates.crt，Debian 系的位置）。在 CentOS / RHEL 系的
 * 服务器上这个文件根本不存在，结果就是所有 HTTPS 下载都报
 * `cURL error 77: error setting certificate file` —— 网页端好好的，守护进程全挂。
 *
 * 项目里别的插件是直接 `'verify' => false` 绕过去的。这里**不能那么做**：
 * IP 地理库会被写进服务器并用于封禁判断，容忍中间人等于把安全功能拱手让人。
 * 找不到证书就如实报错，让站长去装 ca-certificates，而不是偷偷降级。
 */
final class Ca
{
    /** 各发行版的常见位置，按命中概率排 */
    private const CANDIDATES = [
        '/etc/pki/tls/certs/ca-bundle.crt',        // RHEL / CentOS / Fedora
        '/etc/ssl/certs/ca-certificates.crt',      // Debian / Ubuntu / Alpine
        '/etc/ssl/ca-bundle.pem',                  // openSUSE
        '/etc/pki/tls/cacert.pem',                 // 老 RHEL
        '/etc/ssl/cert.pem',                       // macOS / Alpine / FreeBSD
        '/usr/local/share/certs/ca-root-nss.crt',  // FreeBSD
        '/usr/local/etc/openssl/cert.pem',         // Homebrew
    ];

    private static ?string $resolved = null;
    private static bool $tried = false;

    /**
     * @return string|null 可用的 CA 包路径；null 表示这台机器上没找到
     */
    public static function bundle(): ?string
    {
        if (self::$tried) {
            return self::$resolved;
        }
        self::$tried = true;

        //先信 php.ini 的配置（网页端通常配好了）
        foreach (['curl.cainfo', 'openssl.cafile'] as $key) {
            $path = trim((string)@ini_get($key));
            if ($path !== '' && self::usable($path)) {
                return self::$resolved = $path;
            }
        }

        foreach (self::CANDIDATES as $path) {
            if (self::usable($path)) {
                return self::$resolved = $path;
            }
        }

        //还有一招：composer 装了 certainty / ca-bundle 之类的包时用它带的
        $vendor = BASE_PATH . '/vendor/composer/ca-bundle/res/cacert.pem';
        if (self::usable($vendor)) {
            return self::$resolved = $vendor;
        }

        Log::warn('找不到可用的 CA 根证书包，HTTPS 下载会失败', [
            'ini_curl' => (string)@ini_get('curl.cainfo'),
            'ini_openssl' => (string)@ini_get('openssl.cafile'),
        ]);
        return self::$resolved = null;
    }

    /**
     * Guzzle 的 verify 选项值。
     *
     * 找到证书就返回路径（**始终校验**）；找不到时也返回 true —— 让 cURL 用它自己的
     * 默认逻辑再试一次，失败就失败，绝不返回 false 把校验关掉。
     *
     * @return string|bool
     */
    public static function verifyOption()
    {
        $bundle = self::bundle();
        return $bundle ?? true;
    }

    /**
     * 给站长看的排障提示
     */
    public static function hint(): string
    {
        if (self::bundle() !== null) {
            return '';
        }
        return Lang::t(
            '服务器上找不到 CA 根证书包，HTTPS 下载无法校验证书。请安装系统证书包'
            . '（CentOS/RHEL：yum install -y ca-certificates；Debian/Ubuntu：apt install -y ca-certificates），'
            . '或在 php.ini 里配置 curl.cainfo。'
        );
    }

    private static function usable(string $path): bool
    {
        //符号链接也认，但目标必须真的存在且不是空文件
        return $path !== '' && @is_file($path) && @is_readable($path) && (int)@filesize($path) > 0;
    }

    public static function reset(): void
    {
        self::$resolved = null;
        self::$tried = false;
    }
}
