<?php

declare(strict_types=1);

namespace App\Plugin\MusicPlayer\Service\Platform;

use App\Plugin\MusicPlayer\Service\Http;
use App\Plugin\MusicPlayer\Service\Lyric;
use App\Plugin\MusicPlayer\Service\Platform;
use App\Plugin\MusicPlayer\Service\Track;

/**
 * QQ 音乐：歌单走 qzone 接口，直链走 musicu.fcg 的游客 vkey。
 * 游客最高 128k，绿钻高音质需在后台配置 COOKIE。
 */
final class Tencent extends Platform
{
    private const REFERER = 'https://y.qq.com/';

    public function playlist(string $id): array
    {
        $data = Http::getJson(
            'https://c.y.qq.com/qzone/fcg-bin/fcg_ucc_getcdinfo_byids_cp.fcg?' . http_build_query([
                'type' => 1,
                'json' => 1,
                'utf8' => 1,
                'onlysong' => 0,
                'new_format' => 1,
                'disstid' => $id,
                'format' => 'json',
                'loginUin' => 0,
                'platform' => 'yqq.json',
            ]),
            $this->headers()
        );

        $tracks = [];
        foreach ((array)($data['cdlist'][0]['songlist'] ?? []) as $song) {
            $track = $this->hydrate($song);
            if ($track !== null) {
                $tracks[] = $track;
            }
        }
        return $tracks;
    }

    public function search(string $keyword): array
    {
        $data = Http::getJson(
            'https://c.y.qq.com/soso/fcgi-bin/client_search_cp?' . http_build_query([
                'format' => 'json',
                'new_json' => 1,
                'w' => $keyword,
                'n' => 20,
                'p' => 1,
                'cr' => 1,
                'g_tk' => 5381,
            ]),
            $this->headers()
        );

        $tracks = [];
        foreach ((array)($data['data']['song']['list'] ?? []) as $song) {
            $track = $this->hydrate($song);
            if ($track !== null) {
                $tracks[] = $track;
            }
        }
        return $tracks;
    }

    /**
     * new_format/new_json 与旧结构字段名不同，两种都兜住
     */
    private function hydrate(mixed $song): ?Track
    {
        if (!is_array($song)) {
            return null;
        }
        $mid = (string)($song['mid'] ?? $song['songmid'] ?? '');
        $name = (string)($song['name'] ?? $song['songname'] ?? '');
        if ($mid === '' || $name === '') {
            return null;
        }
        $albumMid = (string)($song['album']['mid'] ?? $song['albummid'] ?? '');
        $artists = array_map(
            fn(array $singer): string => (string)($singer['name'] ?? ''),
            (array)($song['singer'] ?? [])
        );

        return new Track(
            source: 'tencent',
            id: $mid,
            name: $name,
            artist: implode(' / ', array_filter($artists)),
            cover: $albumMid !== '' ? $this->albumCover($albumMid) : '',
            picId: $albumMid,
            duration: (int)($song['interval'] ?? 0)
        );
    }

    public function mediaUrl(string $id, int $bitrate): string
    {
        //游客 vkey 已被官方关闭，实际可用性取决于 COOKIE 里的登录态
        $payload = json_encode([
            'req_0' => [
                'module' => 'vkey.GetVkeyServer',
                'method' => 'CgiGetVkey',
                'param' => [
                    'guid' => (string)random_int(1000000000, 9999999999),
                    'songmid' => [$id],
                    'songtype' => [0],
                    'uin' => $this->uin(),
                    'loginflag' => 1,
                    'platform' => '20',
                ],
            ],
        ]);

        $data = Http::getJson(
            'https://u.y.qq.com/cgi-bin/musicu.fcg?format=json&data=' . urlencode((string)$payload),
            $this->headers()
        );

        $purl = (string)($data['req_0']['data']['midurlinfo'][0]['purl'] ?? '');
        return $purl !== '' ? 'https://dl.stream.qqmusic.qq.com/' . $purl : '';
    }

    /**
     * 从 COOKIE 里提取登录 QQ 号，vkey 请求的 uin 需与登录态一致
     */
    private function uin(): string
    {
        if (preg_match('/(?:^|;\s*)(?:uin|wxuin)=o?0*(\d+)/', $this->cookie, $match)) {
            return $match[1];
        }
        return '0';
    }

    public function lyric(string $id): string
    {
        $data = Http::getJson(
            'https://c.y.qq.com/lyric/fcgi-bin/fcg_query_lyric_new.fcg?' . http_build_query([
                'songmid' => $id,
                'format' => 'json',
                'nobase64' => 0,
                'g_tk' => 5381,
            ]),
            $this->headers()
        );
        if (!is_array($data)) {
            return '';
        }
        return Lyric::merge(
            (string)base64_decode((string)($data['lyric'] ?? ''), true),
            (string)base64_decode((string)($data['trans'] ?? ''), true)
        );
    }

    public function cover(string $id): string
    {
        return $this->albumCover($id);
    }

    private function albumCover(string $albumMid): string
    {
        return 'https://y.gtimg.cn/music/photo_new/T002R500x500M000' . $albumMid . '.jpg';
    }

    private function headers(): array
    {
        $headers = [
            'Referer: ' . self::REFERER,
            'Origin: https://y.qq.com',
        ];
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        return $headers;
    }
}
