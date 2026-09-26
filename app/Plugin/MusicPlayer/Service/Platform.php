<?php

declare(strict_types=1);

namespace App\Plugin\MusicPlayer\Service;

/**
 * 音乐平台抽象：每个平台自己实现歌单、直链、歌词与封面的解析
 */
abstract class Platform
{
    protected string $cookie;

    public function __construct(string $cookie = '')
    {
        $this->cookie = trim($cookie);
    }

    /**
     * 解析歌单
     *
     * @return Track[]
     */
    abstract public function playlist(string $id): array;

    /**
     * 全网搜索
     *
     * @return Track[]
     */
    abstract public function search(string $keyword): array;

    /**
     * 音频直链，拿不到返回 ''
     */
    abstract public function mediaUrl(string $id, int $bitrate): string;

    /**
     * LRC 歌词（译文已按 \t 合并），拿不到返回 ''
     */
    abstract public function lyric(string $id): string;

    /**
     * 封面直链，拿不到返回 ''
     */
    abstract public function cover(string $id): string;
}
