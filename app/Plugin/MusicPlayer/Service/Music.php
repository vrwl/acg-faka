<?php

declare(strict_types=1);

namespace App\Plugin\MusicPlayer\Service;

use App\Plugin\MusicPlayer\Service\Platform\Kugou;
use App\Plugin\MusicPlayer\Service\Platform\Netease;
use App\Plugin\MusicPlayer\Service\Platform\Tencent;

/**
 * 播放器门面：聚合自定义曲目与平台歌单，统一走文件缓存。
 * 缓存键包含关键配置，改配置即自然失效，无需手动清理。
 */
final class Music
{
    public const SERVERS = ['netease', 'tencent', 'kugou'];

    private const PLAYLIST_TTL = 600;
    private const COVER_TTL = 604800;

    /**
     * 前端歌单：自定义曲目在前，平台歌单在后
     */
    public static function tracks(): array
    {
        $config = self::config();
        $tracks = self::customTracks((string)($config['custom'] ?? ''));

        $playId = trim((string)($config['playId'] ?? ''));
        if ($playId !== '') {
            foreach (self::platformTracks($config, $playId) as $item) {
                $tracks[] = $item;
            }
        }
        return $tracks;
    }

    /**
     * 全网搜索（按关键词缓存 10 分钟）
     */
    public static function search(string $keyword): array
    {
        $keyword = mb_substr(trim($keyword), 0, 40);
        if ($keyword === '') {
            return [];
        }
        $config = self::config();
        $server = in_array($config['server'] ?? '', self::SERVERS) ? (string)$config['server'] : 'netease';
        $base = self::apiBase();

        $tracks = self::remember('search_' . $server . '_' . md5($keyword), self::PLAYLIST_TTL,
            function () use ($server, $keyword, $base): ?array {
                $tracks = array_map(
                    fn(Track $track): array => $track->toArray($base),
                    self::platform($server)->search($keyword)
                );
                return $tracks !== [] ? $tracks : null;
            }
        );
        return is_array($tracks) ? $tracks : [];
    }

    public static function mediaUrl(string $server, string $id): string
    {
        $config = self::config();
        $bitrate = in_array((int)($config['bitrate'] ?? 0), [128, 192, 320]) ? (int)$config['bitrate'] : 320;
        return self::platform($server)->mediaUrl($id, $bitrate);
    }

    public static function lyric(string $server, string $id): string
    {
        return (string)self::remember("lyric_{$server}_{$id}", self::COVER_TTL,
            fn(): string => self::platform($server)->lyric($id)
        );
    }

    /**
     * 封面直链（按曲目缓存，酷狗等平台需要逐曲解析）
     */
    public static function coverUrl(string $server, string $id): string
    {
        return (string)self::remember("cover_{$server}_{$id}", self::COVER_TTL,
            fn(): string => self::platform($server)->cover($id)
        );
    }

    public static function platform(string $server): Platform
    {
        $cookie = (string)(self::config()['cookie'] ?? '');
        return match ($server) {
            'tencent' => new Tencent($cookie),
            'kugou' => new Kugou($cookie),
            default => new Netease($cookie),
        };
    }

    public static function config(): array
    {
        return (array)getPluginConfig('MusicPlayer');
    }

    /**
     * 歌单条目的 url/lrc/pic 指向的解析基址：外部 API 或内置分发端点
     */
    public static function apiBase(): string
    {
        $api = trim((string)(self::config()['api'] ?? ''));
        return $api !== '' ? $api : '/plugin/MusicPlayer/api/meting';
    }

    private static function platformTracks(array $config, string $playId): array
    {
        $server = in_array($config['server'] ?? '', self::SERVERS) ? (string)$config['server'] : 'netease';
        $base = self::apiBase();
        $key = 'playlist_' . md5(implode('|', [
            $server, $playId, $base,
            (string)($config['bitrate'] ?? ''),
            md5((string)($config['cookie'] ?? '')),
        ]));

        $tracks = self::remember($key, self::PLAYLIST_TTL, function () use ($config, $server, $playId, $base): ?array {
            //配了外部 Meting API 就整单转发，否则用内置平台解析
            $api = trim((string)($config['api'] ?? ''));
            if ($api !== '') {
                $list = Http::getJson($api . (str_contains($api, '?') ? '&' : '?') . http_build_query([
                    'server' => $server,
                    'type' => 'playlist',
                    'id' => $playId,
                ]), [], 20);
                return is_array($list) ? array_values(array_filter($list, 'is_array')) : null;
            }

            $tracks = array_map(
                fn(Track $track): array => $track->toArray($base),
                self::platform($server)->playlist($playId)
            );
            return $tracks !== [] ? $tracks : null;
        });

        return is_array($tracks) ? $tracks : [];
    }

    /**
     * 自定义曲目：每行 歌名|歌手|音频URL|封面URL|歌词URL，# 开头为注释
     */
    private static function customTracks(string $text): array
    {
        $tracks = [];
        //\R 会误伤 CJK 字节，坚持显式换行符
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3 || $parts[0] === '' || !preg_match('#^https?://#i', $parts[2])) {
                continue;
            }
            $tracks[] = [
                'name' => $parts[0],
                'artist' => $parts[1] !== '' ? $parts[1] : '未知艺术家',
                'duration' => 0,
                'url' => $parts[2],
                'lrc' => $parts[4] ?? '',
                'cover' => $parts[3] ?? '',
                'pic' => '',
            ];
        }
        return $tracks;
    }

    /**
     * 极简文件缓存：$fn 返回 null 视为失败，不落缓存
     */
    private static function remember(string $key, int $ttl, callable $fn)
    {
        $file = self::cacheDir() . '/' . preg_replace('/[^0-9A-Za-z_\-.]/', '_', $key) . '.json';
        if (is_file($file) && (time() - (int)filemtime($file)) < $ttl) {
            $hit = json_decode((string)file_get_contents($file), true);
            if ($hit !== null) {
                return $hit;
            }
        }

        $fresh = $fn();
        if ($fresh !== null && $fresh !== '' && $fresh !== []) {
            @file_put_contents($file, json_encode($fresh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $fresh;
    }

    private static function cacheDir(): string
    {
        $dir = BASE_PATH . '/runtime/plugin/MusicPlayer';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }
}
