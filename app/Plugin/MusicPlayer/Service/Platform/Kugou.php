<?php

declare(strict_types=1);

namespace App\Plugin\MusicPlayer\Service\Platform;

use App\Plugin\MusicPlayer\Service\Http;
use App\Plugin\MusicPlayer\Service\Lyric;
use App\Plugin\MusicPlayer\Service\Platform;
use App\Plugin\MusicPlayer\Service\Track;

/**
 * 酷狗音乐：歌单走移动端 special 接口，直链与封面走 getSongInfo。
 * 歌单接口不含封面，封面由 pic 端点按 hash 懒加载。
 */
final class Kugou extends Platform
{
    public function playlist(string $id): array
    {
        $data = Http::getJson(
            'http://mobilecdn.kugou.com/api/v3/special/song?' . http_build_query([
                'specialid' => $id,
                'page' => 1,
                'pagesize' => 1000,
                'plat' => 0,
                'version' => 9108,
            ]),
            $this->headers()
        );

        $tracks = [];
        foreach ((array)($data['data']['info'] ?? []) as $song) {
            if (!is_array($song) || empty($song['hash'])) {
                continue;
            }
            //filename 形如 "歌手A、歌手B - 歌名"
            $filename = (string)($song['filename'] ?? '');
            [$artist, $name] = array_pad(explode(' - ', $filename, 2), 2, '');
            if (trim($name) === '') {
                [$artist, $name] = ['', $filename];
            }

            $tracks[] = new Track(
                source: 'kugou',
                id: (string)$song['hash'],
                name: trim($name),
                artist: trim($artist),
                picId: (string)$song['hash'],
                duration: (int)($song['duration'] ?? 0)
            );
        }
        return $tracks;
    }

    public function search(string $keyword): array
    {
        $data = Http::getJson(
            'http://mobilecdn.kugou.com/api/v3/search/song?' . http_build_query([
                'format' => 'json',
                'keyword' => $keyword,
                'page' => 1,
                'pagesize' => 20,
                'showtype' => 1,
            ]),
            $this->headers()
        );

        $tracks = [];
        foreach ((array)($data['data']['info'] ?? []) as $song) {
            if (!is_array($song) || empty($song['hash'])) {
                continue;
            }
            $name = trim((string)($song['songname'] ?? ''));
            $artist = trim((string)($song['singername'] ?? ''));
            if ($name === '') {
                [$artist, $name] = array_pad(explode(' - ', (string)($song['filename'] ?? ''), 2), 2, '');
            }
            if (trim($name) === '') {
                continue;
            }
            $tracks[] = new Track(
                source: 'kugou',
                id: (string)$song['hash'],
                name: trim($name),
                artist: trim($artist),
                picId: (string)$song['hash'],
                duration: (int)($song['duration'] ?? 0)
            );
        }
        return $tracks;
    }

    public function mediaUrl(string $id, int $bitrate): string
    {
        $info = $this->songInfo($id);
        return (string)($info['url'] ?? '');
    }

    public function lyric(string $id): string
    {
        $info = $this->songInfo($id);
        $raw = Http::get(
            'http://m.kugou.com/app/i/krc.php?' . http_build_query([
                'cmd' => 100,
                'hash' => $id,
                'timelength' => (int)($info['timeLength'] ?? 0) * 1000,
            ]),
            $this->headers()
        );
        //返回的是纯文本 LRC，粗校验一下再交给合并器
        if (!is_string($raw) || !str_contains($raw, '[')) {
            return '';
        }
        return Lyric::merge($raw, '');
    }

    public function cover(string $id): string
    {
        $info = $this->songInfo($id);
        $img = (string)($info['imgUrl'] ?? $info['album_img'] ?? '');
        return $img !== '' ? str_replace('{size}', '400', $img) : '';
    }

    private function songInfo(string $hash): array
    {
        $data = Http::getJson(
            'http://m.kugou.com/app/i/getSongInfo.php?' . http_build_query([
                'cmd' => 'playInfo',
                'hash' => $hash,
            ]),
            $this->headers()
        );
        return is_array($data) ? $data : [];
    }

    private function headers(): array
    {
        $headers = ['Referer: http://m.kugou.com/'];
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        return $headers;
    }
}
