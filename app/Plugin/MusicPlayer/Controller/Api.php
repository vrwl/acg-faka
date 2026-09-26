<?php

declare(strict_types=1);

namespace App\Plugin\MusicPlayer\Controller;

use App\Interceptor\Waf;
use App\Plugin\MusicPlayer\Service\Http;
use App\Plugin\MusicPlayer\Service\Music;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class], Interceptor::TYPE_API)]
class Api
{

    /**
     * 歌单：/plugin/MusicPlayer/api/playlist
     */
    public function playlist(): void
    {
        $tracks = Music::tracks();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => 200,
            'hash' => substr(md5((string)json_encode($tracks)), 0, 12),
            'data' => $tracks,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Meting 形态的分发端点：?server=&type=url|pic|lrc|playlist&id=
     * 歌单条目里的资源链接统一指到这里，与外部 API 的 URL 结构一致。
     */
    public function meting(): void
    {
        switch ((string)($_GET['type'] ?? '')) {
            case 'url':
                $this->url();
                break;
            case 'pic':
                $this->pic();
                break;
            case 'lrc':
                $this->lrc();
                break;
            case 'playlist':
                $this->playlist();
                break;
            case 'search':
                $this->search();
                break;
            default:
                $this->deny('bad type');
        }
    }

    /**
     * 全网搜索：?type=search&id=关键词（沿用 Meting 的 URL 形态）
     */
    public function search(): void
    {
        $tracks = Music::search((string)($_GET['id'] ?? ''));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 200, 'data' => $tracks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 音频直链：302 到平台节点
     */
    public function url(): void
    {
        [$server, $id] = $this->args();
        $url = Music::mediaUrl($server, $id);
        if ($url === '') {
            $this->deny('media not found', 404);
        }
        header('Location: ' . $url);
    }

    /**
     * 封面：默认 302 直链；&proxy=1 中转字节并放开 CORS，供前端 canvas 取色
     */
    public function pic(): void
    {
        [$server, $id] = $this->args();
        $url = Music::coverUrl($server, $id);
        if ($url === '') {
            $this->deny('cover not found', 404);
        }

        if (($_GET['proxy'] ?? '') != '1') {
            header('Cache-Control: public, max-age=86400');
            header('Location: ' . $url);
            return;
        }

        $image = Http::fetchImage($url);
        if ($image === null) {
            $this->deny('cover fetch failed', 404);
        }
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: ' . $image['type']);
        header('Cache-Control: public, max-age=86400');
        echo $image['body'];
    }

    /**
     * 歌词：纯文本 LRC，译文以 \t 并入行尾
     */
    public function lrc(): void
    {
        [$server, $id] = $this->args();
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo Music::lyric($server, $id);
    }

    /**
     * @return string[] [server, id]
     */
    private function args(): array
    {
        $server = (string)($_GET['server'] ?? '');
        $id = (string)($_GET['id'] ?? '');
        if (!in_array($server, Music::SERVERS) || !preg_match('/^[0-9A-Za-z_\-]{1,64}$/', $id)) {
            $this->deny('bad request');
        }
        return [$server, $id];
    }

    private function deny(string $message, int $status = 400): void
    {
        http_response_code($status);
        die($message);
    }
}
