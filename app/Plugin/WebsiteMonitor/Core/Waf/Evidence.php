<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Waf;

/**
 * 取证片段的采集与脱敏。
 *
 * 取证的价值就在于能看到攻击者到底发了什么，所以这里一定会存到用户输入。
 * 正因如此，两条纪律不能破：
 *   1. 只存命中片段前后一小段上下文，绝不存整个 body
 *   2. 密码、令牌、卡号一律打码 —— 攻击日志不该变成第二个泄密渠道
 */
final class Evidence
{
    /** 命中片段前后各留多少字节上下文 */
    private const CONTEXT = 64;

    /** 单个片段的硬上限 */
    private const MAX = 512;

    /** 值需要打码的字段名（不区分大小写，子串匹配） */
    private const SECRET_KEYS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'apikey', 'api_key',
        'private', 'signature', 'sign', 'auth', 'credential', 'session',
        'card', 'cvv', 'cvc', 'bank', 'idcard', 'id_card', 'mobile', 'phone',
    ];

    /**
     * 从命中的主体里截出带上下文的片段并脱敏
     */
    public static function snippet(string $subject, string $matched): string
    {
        if ($subject === '') {
            return '';
        }
        $pos = $matched === '' ? 0 : strpos($subject, $matched);
        if ($pos === false) {
            $pos = 0;
        }
        $start = max(0, $pos - self::CONTEXT);
        $length = strlen($matched) + self::CONTEXT * 2;
        $piece = substr($subject, $start, min($length, self::MAX));

        if ($start > 0) {
            $piece = '…' . $piece;
        }
        if ($start + $length < strlen($subject)) {
            $piece .= '…';
        }
        return self::mask($piece);
    }

    /**
     * 值级打码：把 key=value 形式里敏感 key 的值换掉。
     *
     * 先过一遍框架自带的 maskSensitive（按字段名匹配），再补一层正则 ——
     * 展平后的字符串已经不是数组了，框架那套按 key 遍历的逻辑覆盖不到。
     */
    public static function mask(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $keys = implode('|', array_map(static fn(string $k): string => preg_quote($k, '#'), self::SECRET_KEYS));
        $masked = @preg_replace(
            '#((?:[\w.\[\]]*(?:' . $keys . ')[\w.\[\]]*)\s*[=:]\s*)([^&\s"\',}]{3,})#i',
            '$1***',
            $text
        );
        if (!is_string($masked)) {
            $masked = $text;
        }
        //邮箱与长数字串（卡号、手机号）局部打码
        $masked = (string)@preg_replace_callback(
            '#([\w.+-]{1,3})[\w.+-]*(@[\w.-]+)#',
            static fn(array $m): string => $m[1] . '***' . $m[2],
            $masked
        );
        $masked = (string)@preg_replace('#\b(\d{4})\d{6,12}(\d{4})\b#', '$1********$2', $masked);

        return $masked;
    }

    /**
     * 请求头取证（只取有意义的几个，全部脱敏）
     *
     * @return array<string,string>
     */
    public static function headers(): array
    {
        $out = [];
        foreach (['UserAgent', 'Referer', 'ContentType', 'Accept', 'XForwardedFor', 'Origin'] as $name) {
            $value = Surface::header($name);
            if ($value !== '') {
                $out[$name] = mb_substr(self::mask($value), 0, 255);
            }
        }
        return $out;
    }

    /**
     * 上传文件的取证信息（只记文件名与大小，绝不存内容）
     *
     * @return array<int,array<string,mixed>>
     */
    public static function files(): array
    {
        if ($_FILES === []) {
            return [];
        }
        $out = [];
        foreach ($_FILES as $field => $file) {
            $names = is_array($file['name'] ?? null) ? (array)$file['name'] : [$file['name'] ?? ''];
            $sizes = is_array($file['size'] ?? null) ? (array)$file['size'] : [$file['size'] ?? 0];
            foreach ($names as $i => $name) {
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $out[] = [
                    'field' => (string)$field,
                    'name' => mb_substr($name, 0, 120),
                    'size' => (int)($sizes[$i] ?? 0),
                ];
                if (count($out) >= 5) {
                    return $out;
                }
            }
        }
        return $out;
    }

    /**
     * 组装完整取证包（在即将拦截时才调，正常请求不付这个钱）
     *
     * @param array<string,mixed> $base 引擎已经收集的片段
     * @return array<string,mixed>
     */
    public static function build(array $base, int $maxBytes = 2048): array
    {
        $out = $base;
        $out['headers'] = self::headers();
        $files = self::files();
        if ($files !== []) {
            $out['files'] = $files;
        }
        $out['method'] = Surface::method();
        $out['path'] = mb_substr(Surface::route(), 0, 255);

        //整体体积兜底：JSON 编码后超限就丢掉最占地方的片段
        $encoded = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (is_string($encoded) && strlen($encoded) > $maxBytes) {
            unset($out['headers']);
            if (isset($out['matched'])) {
                $out['matched'] = mb_substr((string)$out['matched'], 0, 256);
            }
        }
        return $out;
    }
}
