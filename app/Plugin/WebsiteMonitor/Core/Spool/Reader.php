<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Spool;

use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;

/**
 * spool 的摘取与读取。
 *
 * 「摘取」用 rename：把 s0.log 原子改名到 seal/<ts>-<shard>-<rand>.log。
 * rename 之后写入方的 file_put_contents(FILE_APPEND) 会自动重建一个新的 s0.log，
 * 中间不会丢行，也不需要写入方配合做任何事。
 *
 * 一个 seal 文件只可能被一个进程 rename 成功，天然就是分配单元。
 */
final class Reader
{
    /**
     * 把当前分片摘出来，返回待处理的 seal 文件列表（含之前遗留未处理的）。
     *
     * @return string[]
     */
    public static function seal(): array
    {
        $sealDir = Runtime::seal();
        if (!is_dir($sealDir)) {
            @mkdir($sealDir, 0755, true);
        }

        $now = time();
        for ($shard = 0; $shard < Writer::SHARDS; $shard++) {
            $src = Runtime::spool() . '/s' . $shard . '.log';
            if (!is_file($src) || (int)@filesize($src) <= 0) {
                continue;
            }
            $dst = $sealDir . '/' . $now . '-' . $shard . '-' . bin2hex(random_bytes(3)) . '.log';
            //失败说明别的进程刚摘走了，跳过即可
            @rename($src, $dst);
        }

        $files = @glob($sealDir . '/*.log');
        if (!is_array($files)) {
            return [];
        }
        //先处理旧的，保证时间序
        sort($files);
        return $files;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function read(string $file): array
    {
        return Line::parseFile($file);
    }

    public static function discard(string $file): void
    {
        @unlink($file);
    }

    /**
     * 处理失败时把文件退回队列末尾，并记一次失败计数。
     * 连续失败 5 次的文件会被挪到 seal/bad/，避免一个坏文件卡死整条流水线。
     */
    public static function requeue(string $file, string $reason): void
    {
        try {
            $marker = $file . '.fail';
            $fails = (int)@file_get_contents($marker) + 1;
            if ($fails >= 5) {
                $badDir = Runtime::seal() . '/bad';
                if (!is_dir($badDir)) {
                    @mkdir($badDir, 0755, true);
                }
                @rename($file, $badDir . '/' . basename($file));
                @unlink($marker);
                Log::error('spool 文件连续入库失败，已隔离', ['file' => basename($file), 'reason' => $reason]);
                return;
            }
            @file_put_contents($marker, (string)$fails);
            Log::warn('spool 文件入库失败，稍后重试', ['file' => basename($file), 'fails' => $fails, 'reason' => $reason]);
        } catch (\Throwable $e) {
        }
    }

    public static function clearFailMarker(string $file): void
    {
        @unlink($file . '.fail');
    }

    /**
     * 被隔离的坏文件数（面板提示用）
     */
    public static function badCount(): int
    {
        $files = @glob(Runtime::seal() . '/bad/*.log');
        return is_array($files) ? count($files) : 0;
    }
}
