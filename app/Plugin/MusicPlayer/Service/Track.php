<?php

declare(strict_types=1);

namespace App\Plugin\MusicPlayer\Service;

/**
 * 曲目实体：平台解析结果的统一形态
 */
final class Track
{
    public function __construct(
        public string $source,
        public string $id,
        public string $name,
        public string $artist,
        public string $cover = '',
        public string $picId = '',
        public int    $duration = 0
    )
    {
    }

    /**
     * 输出给前端的结构；url/lrc/pic 统一走 Meting 形态的分发端点，
     * cover 优先给平台直链（显示快），取色走 pic&proxy=1。
     */
    public function toArray(string $apiBase): array
    {
        $query = fn(string $type, string $id): string => $apiBase
            . (str_contains($apiBase, '?') ? '&' : '?')
            . http_build_query(['server' => $this->source, 'type' => $type, 'id' => $id]);

        $pic = $this->picId !== '' ? $query('pic', $this->picId) : '';

        return [
            'name' => $this->name,
            'artist' => $this->artist !== '' ? $this->artist : '未知艺术家',
            'duration' => $this->duration,
            'url' => $query('url', $this->id),
            'lrc' => $query('lrc', $this->id),
            'cover' => $this->cover !== '' ? $this->cover : $pic,
            'pic' => $pic,
        ];
    }
}
