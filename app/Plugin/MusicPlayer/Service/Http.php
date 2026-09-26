<?php

declare(strict_types=1);

namespace App\Plugin\MusicPlayer\Service;

/**
 * 轻量 HTTP 客户端：平台接口请求与封面字节中转共用
 */
final class Http
{
    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /**
     * @param array $headers ["Referer: xx", "Cookie: xx"]
     */
    public static function get(string $url, array $headers = [], int $timeout = 10): ?string
    {
        return self::request($url, null, $headers, $timeout);
    }

    /**
     * @param array|string $form 表单数据
     */
    public static function post(string $url, $form, array $headers = [], int $timeout = 10): ?string
    {
        $body = is_array($form) ? http_build_query($form) : (string)$form;
        return self::request($url, $body, $headers, $timeout);
    }

    public static function getJson(string $url, array $headers = [], int $timeout = 10): ?array
    {
        return self::decode(self::get($url, $headers, $timeout));
    }

    /**
     * @param array|string $form
     */
    public static function postJson(string $url, $form, array $headers = [], int $timeout = 10): ?array
    {
        return self::decode(self::post($url, $form, $headers, $timeout));
    }

    /**
     * 拉取远端图片字节（封面取色代理用），带类型与体积防线
     *
     * @return array|null ['type' => contentType, 'body' => bytes]
     */
    public static function fetchImage(string $url, int $maxBytes = 6291456): ?array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $ch = self::channel($url, 15);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes);
        $body = curl_exec($ch);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $code >= 400 || strlen($body) > $maxBytes || !str_starts_with($type, 'image/')) {
            return null;
        }
        return ['type' => $type, 'body' => $body];
    }

    private static function request(string $url, ?string $body, array $headers, int $timeout): ?string
    {
        $ch = self::channel($url, $timeout);
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return (is_string($response) && $code < 400) ? $response : null;
    }

    /**
     * @return resource|\CurlHandle
     */
    private static function channel(string $url, int $timeout)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::UA,
        ]);
        return $ch;
    }

    private static function decode(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
