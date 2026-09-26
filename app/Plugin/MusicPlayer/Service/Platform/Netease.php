<?php

declare(strict_types=1);

namespace App\Plugin\MusicPlayer\Service\Platform;

use App\Plugin\MusicPlayer\Service\Http;
use App\Plugin\MusicPlayer\Service\Lyric;
use App\Plugin\MusicPlayer\Service\Platform;
use App\Plugin\MusicPlayer\Service\Track;

/**
 * 网易云音乐：走开放的 /api 明文接口（os=pc 形态），无需 weapi 加密。
 * VIP / 高音质曲目依赖后台配置的登录 COOKIE。
 */
final class Netease extends Platform
{
    private const ORIGIN = 'https://music.163.com';
    private const MAX_TRACKS = 2000;

    public function playlist(string $id): array
    {
        $data = Http::postJson(self::ORIGIN . '/api/v6/playlist/detail', [
            'id' => $id,
            'n' => 1000,
            's' => 0,
        ], $this->headers());

        $detail = $data['playlist'] ?? null;
        if (!is_array($detail)) {
            return [];
        }

        $tracks = [];
        $seen = [];
        foreach ((array)($detail['tracks'] ?? []) as $song) {
            $track = $this->hydrate($song);
            if ($track !== null) {
                $tracks[] = $track;
                $seen[$track->id] = true;
            }
        }

        //v6 单次最多回传 1000 首完整信息，超出部分按 trackIds 分批补齐
        $missing = [];
        foreach ((array)($detail['trackIds'] ?? []) as $ref) {
            $trackId = (string)($ref['id'] ?? '');
            if ($trackId !== '' && !isset($seen[$trackId])) {
                $missing[] = $trackId;
            }
        }
        foreach (array_chunk(array_slice($missing, 0, self::MAX_TRACKS - count($tracks)), 400) as $chunk) {
            foreach ($this->songs($chunk) as $track) {
                $tracks[] = $track;
            }
        }

        return $tracks;
    }

    public function search(string $keyword): array
    {
        $data = Http::postJson(self::ORIGIN . '/api/cloudsearch/pc', [
            's' => $keyword,
            'type' => 1,
            'limit' => 30,
            'offset' => 0,
        ], $this->headers());

        $tracks = [];
        foreach ((array)($data['result']['songs'] ?? []) as $song) {
            $track = $this->hydrate($song);
            if ($track !== null) {
                $tracks[] = $track;
            }
        }
        return $tracks;
    }

    public function mediaUrl(string $id, int $bitrate): string
    {
        $data = Http::postJson(self::ORIGIN . '/api/song/enhance/player/url', [
            'ids' => '[' . $id . ']',
            'br' => $bitrate * 1000,
        ], $this->headers());

        $url = (string)($data['data'][0]['url'] ?? '');
        if ($url !== '') {
            //音频节点证书齐全，统一升为 https 避免混合内容
            return preg_replace('#^http://#', 'https://', $url);
        }
        //拿不到直链（未登录的 VIP 曲目等）退回官方外链，多数曲目可 128k 试听
        return self::ORIGIN . '/song/media/outer/url?id=' . $id . '.mp3';
    }

    public function lyric(string $id): string
    {
        $data = Http::getJson(
            self::ORIGIN . '/api/song/lyric?' . http_build_query(['id' => $id, 'lv' => -1, 'tv' => -1]),
            $this->headers()
        );
        if (!is_array($data)) {
            return '';
        }
        return Lyric::merge(
            (string)($data['lrc']['lyric'] ?? ''),
            (string)($data['tlyric']['lyric'] ?? '')
        );
    }

    public function cover(string $id): string
    {
        foreach ($this->songs([$id]) as $track) {
            return $track->cover;
        }
        return '';
    }

    /**
     * @param string[] $ids
     * @return Track[]
     */
    private function songs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $payload = json_encode(array_map(fn(string $id): array => ['id' => $id], $ids));
        $data = Http::postJson(self::ORIGIN . '/api/v3/song/detail', ['c' => $payload], $this->headers());

        $tracks = [];
        foreach ((array)($data['songs'] ?? []) as $song) {
            $track = $this->hydrate($song);
            if ($track !== null) {
                $tracks[] = $track;
            }
        }
        return $tracks;
    }

    private function hydrate(mixed $song): ?Track
    {
        if (!is_array($song) || empty($song['id']) || empty($song['name'])) {
            return null;
        }
        $artists = array_map(
            fn(array $artist): string => (string)($artist['name'] ?? ''),
            (array)($song['ar'] ?? $song['artists'] ?? [])
        );
        $picUrl = (string)($song['al']['picUrl'] ?? $song['album']['picUrl'] ?? '');

        return new Track(
            source: 'netease',
            id: (string)$song['id'],
            name: (string)$song['name'],
            artist: implode(' / ', array_filter($artists)),
            cover: $picUrl !== '' ? preg_replace('#^http://#', 'https://', $picUrl) . '?param=400y400' : '',
            picId: (string)$song['id'],
            duration: (int)round(((int)($song['dt'] ?? $song['duration'] ?? 0)) / 1000)
        );
    }

    private function headers(): array
    {
        $cookie = 'os=pc; appver=8.10.35';
        if ($this->cookie !== '') {
            $cookie = $this->cookie . (str_contains($this->cookie, 'os=') ? '' : '; ' . $cookie);
        }
        return [
            'Referer: ' . self::ORIGIN . '/',
            'Origin: ' . self::ORIGIN,
            'Content-Type: application/x-www-form-urlencoded',
            'Cookie: ' . $cookie,
        ];
    }
}
