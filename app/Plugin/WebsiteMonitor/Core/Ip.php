<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

/**
 * IP 归一化与规则解析。
 *
 * 为什么自备一份：App\Util\Client 里的 normalizeIp() 与 ipMatchesRange() 都是 private，
 * 外部调不到；App\Util\CallbackIpWhitelist::allows() 每次都要重新解析整串规则，
 * 是 O(规则数) 的字符串处理，不能放在每请求的热路径上。
 *
 * 统一表示法：所有 IP 都按 **16 字节** 处理（IPv4 折成 IPv4-mapped IPv6），
 * 十六进制文本恒为 32 个小写字符。这样一套区间列就能同时容纳 v4 与 v6，
 * 且 hex 的字典序与二进制序一致，BETWEEN 可直接用。
 */
final class Ip
{
    /** IPv4-mapped IPv6 的 12 字节前缀 */
    private const V4_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    /**
     * 归一化成可读形式：去方括号与端口，校验合法性，把 ::ffff:1.2.3.4 折回 1.2.3.4。
     * 非法返回 null。
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        //[2001:db8::1]:443
        if ($value[0] === '[') {
            $end = strpos($value, ']');
            if ($end === false) {
                return null;
            }
            $value = substr($value, 1, $end - 1);
        } elseif (substr_count($value, ':') === 1 && str_contains($value, '.')) {
            //1.2.3.4:8080（只有一个冒号才是 v4:port，否则是 v6）
            $value = substr($value, 0, (int)strpos($value, ':'));
        }

        $packed = @inet_pton($value);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, self::V4_PREFIX)) {
            $packed = substr($packed, 12);
        }
        $back = @inet_ntop($packed);
        return $back === false ? null : strtolower($back);
    }

    /**
     * 转成定长 16 字节二进制（IPv4 折成 IPv4-mapped）。非法返回 null。
     */
    public static function pack(string $value): ?string
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return null;
        }
        $packed = @inet_pton($normalized);
        if ($packed === false) {
            return null;
        }
        return strlen($packed) === 4 ? self::V4_PREFIX . $packed : $packed;
    }

    /**
     * 32 个小写十六进制字符。非法返回 null。
     */
    public static function toHex(string $value): ?string
    {
        $packed = self::pack($value);
        return $packed === null ? null : bin2hex($packed);
    }

    public static function hexToIp(string $hex): ?string
    {
        $hex = trim($hex);
        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            return null;
        }
        $packed = @hex2bin($hex);
        if ($packed === false) {
            return null;
        }
        if (str_starts_with($packed, self::V4_PREFIX)) {
            $packed = substr($packed, 12);
        }
        $ip = @inet_ntop($packed);
        return $ip === false ? null : $ip;
    }

    /** 4 或 6；非法返回 0 */
    public static function family(string $value): int
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return 0;
        }
        return str_contains($normalized, ':') ? 6 : 4;
    }

    /** 是否内网 / 保留地址（这类 IP 不做地理查询，也不该被封） */
    public static function isPrivate(string $value): bool
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return false;
        }
        return filter_var(
            $normalized,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * 按位数截断（16 字节空间内的位数，0-128）
     */
    public static function maskPacked(string $packed, int $bits): string
    {
        $bits = max(0, min(128, $bits));
        $whole = $bits >> 3;
        $rem = $bits & 7;
        $out = substr($packed, 0, $whole);
        if ($rem > 0) {
            $out .= chr(ord($packed[$whole]) & ((0xFF << (8 - $rem)) & 0xFF));
            $whole++;
        }
        return str_pad($out, 16, "\x00");
    }

    /**
     * 解析一条名单规则，返回统一的区间表示。
     *
     * 支持四种写法：
     *   1.2.3.4                  单个 IP（v4/v6 都行）
     *   1.2.3.0/24               CIDR（v4 的位数会自动换算到 16 字节空间）
     *   1.2.3.*  /  1.2.*.*      尾部通配（编译期就转成 CIDR，运行时不存在通配匹配）
     *   1.2.3.4-1.2.3.100        区间
     *
     * @return array{start_hex:string,end_hex:string,family:int,norm:string,bits:int}|null
     */
    public static function parseRule(string $value): ?array
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        // ── 区间 a-b
        if (str_contains($value, '-')) {
            [$lo, $hi] = array_pad(explode('-', $value, 2), 2, '');
            $loPacked = self::pack(trim($lo));
            $hiPacked = self::pack(trim($hi));
            if ($loPacked === null || $hiPacked === null) {
                return null;
            }
            if (strcmp($loPacked, $hiPacked) > 0) {
                [$loPacked, $hiPacked] = [$hiPacked, $loPacked];
            }
            return [
                'start_hex' => bin2hex($loPacked),
                'end_hex' => bin2hex($hiPacked),
                'family' => self::family(trim($lo)),
                'norm' => self::normalize(trim($lo)) . '-' . self::normalize(trim($hi)),
                'bits' => -1,
            ];
        }

        // ── 尾部通配 1.2.3.* → 1.2.3.0/24
        if (str_contains($value, '*')) {
            $parts = explode('.', $value);
            if (count($parts) !== 4) {
                return null;
            }
            $solid = 0;
            foreach ($parts as $part) {
                if ($part === '*') {
                    break;
                }
                if (!ctype_digit($part) || (int)$part > 255) {
                    return null;
                }
                $solid++;
            }
            //通配后面不许再出现数字（1.*.3.* 这种没有意义）
            for ($i = $solid; $i < 4; $i++) {
                if ($parts[$i] !== '*') {
                    return null;
                }
            }
            if ($solid === 0) {
                return null;
            }
            $base = implode('.', array_slice($parts, 0, $solid)) . str_repeat('.0', 4 - $solid);
            $value = $base . '/' . ($solid * 8);
        }

        // ── CIDR
        if (str_contains($value, '/')) {
            [$addr, $len] = array_pad(explode('/', $value, 2), 2, '');
            $packed = self::pack(trim($addr));
            if ($packed === null || !ctype_digit(trim($len))) {
                return null;
            }
            $family = self::family(trim($addr));
            $prefix = (int)trim($len);
            $max = $family === 4 ? 32 : 128;
            if ($prefix < 0 || $prefix > $max) {
                return null;
            }
            //IPv4 的 /24 在 16 字节空间里是 /120
            $bits = $family === 4 ? $prefix + 96 : $prefix;
            $start = self::maskPacked($packed, $bits);
            $end = self::broadcast($start, $bits);
            return [
                'start_hex' => bin2hex($start),
                'end_hex' => bin2hex($end),
                'family' => $family,
                'norm' => self::normalize((string)@inet_ntop(
                    $family === 4 ? substr($start, 12) : $start
                )) . '/' . $prefix,
                'bits' => $bits,
            ];
        }

        // ── 单个 IP
        $packed = self::pack($value);
        if ($packed === null) {
            return null;
        }
        return [
            'start_hex' => bin2hex($packed),
            'end_hex' => bin2hex($packed),
            'family' => self::family($value),
            'norm' => (string)self::normalize($value),
            'bits' => 128,
        ];
    }

    /**
     * 区间末地址：把掩码位之后的所有位置 1
     */
    private static function broadcast(string $start, int $bits): string
    {
        $bits = max(0, min(128, $bits));
        $out = $start;
        $whole = $bits >> 3;
        $rem = $bits & 7;
        if ($rem > 0) {
            $out[$whole] = chr(ord($out[$whole]) | (0xFF >> $rem));
            $whole++;
        }
        for ($i = $whole; $i < 16; $i++) {
            $out[$i] = "\xff";
        }
        return $out;
    }

    /**
     * 命中判断（用于后台「这个 IP 命中哪条规则」，不是热路径）
     */
    public static function inRange(string $ip, string $startHex, string $endHex): bool
    {
        $hex = self::toHex($ip);
        if ($hex === null || $startHex === '' || $endHex === '') {
            return false;
        }
        return strcmp($hex, strtolower($startHex)) >= 0 && strcmp($hex, strtolower($endHex)) <= 0;
    }

    /**
     * 匿名化：IPv4 抹掉最后一段，IPv6 只留前 48 位。用于导出与日志展示。
     */
    public static function anonymize(string $value): string
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return '';
        }
        if (!str_contains($normalized, ':')) {
            $parts = explode('.', $normalized);
            $parts[3] = '0';
            return implode('.', $parts);
        }
        $packed = @inet_pton($normalized);
        if ($packed === false) {
            return $normalized;
        }
        $ip = @inet_ntop(self::maskPacked($packed, 48));
        return $ip === false ? $normalized : $ip;
    }
}
