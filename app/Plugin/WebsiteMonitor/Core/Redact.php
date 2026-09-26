<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

final class Redact
{
    public const MASK = '[已掩码]';

    private const EXACT = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'sign', 'signature',
        'auth', 'authorization', 'credential', 'cookie', 'session',
        'app_key', 'appkey', 'api_key', 'apikey', 'access_key', 'secret_key',
        'private_key', 'app_secret', 'access_token', 'refresh_token', 'session_token',
    ];

    private const SUFFIX = [
        '_password', '_passwd', '_pwd', '_secret', '_token', '_key',
        '_sign', '_signature', '_credential', '_session', '_auth',
    ];

    public static function isSecret(string $field): bool
    {
        $name = strtolower(trim($field));
        if ($name === '') {
            return false;
        }
        if (in_array($name, self::EXACT, true)) {
            return true;
        }
        foreach (self::SUFFIX as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }
        return false;
    }

    public static function value(string $field, string $value): string
    {
        return self::isSecret($field) ? self::MASK : $value;
    }

    public static function queryString(string $query): string
    {
        if ($query === '') {
            return $query;
        }
        $parts = [];
        foreach (explode('&', $query) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $parts[] = $pair;
                continue;
            }
            $key = substr($pair, 0, $eq);
            $parts[] = $key . '=' . self::value($key, substr($pair, $eq + 1));
        }
        return implode('&', $parts);
    }

    public static function blob(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $text = (string)preg_replace_callback(
            '/"([A-Za-z0-9_.\-]{1,64})"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/',
            static fn(array $m): string => self::isSecret($m[1])
                ? '"' . $m[1] . '":"' . self::MASK . '"'
                : $m[0],
            $text
        );

        return (string)preg_replace_callback(
            '/([A-Za-z0-9_.\-]{1,64})=([^&\s"\']{1,4096})/',
            static fn(array $m): string => self::isSecret($m[1])
                ? $m[1] . '=' . self::MASK
                : $m[0],
            $text
        );
    }
}
