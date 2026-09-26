<?php

declare(strict_types=1);

namespace App\Plugin\MusicPlayer\Service;

/**
 * LRC 歌词工具：解析、译文按时间轴合并。
 * 合并输出 "原文\t译文"，前端按 \t 拆成双行展示。
 */
final class Lyric
{
    /**
     * @param string $main 原文 LRC
     * @param string $trans 译文 LRC（可空）
     */
    public static function merge(string $main, string $trans): string
    {
        $lines = self::parse($main);
        if ($lines === []) {
            return $main;
        }

        if ($trans !== '') {
            $translated = [];
            foreach (self::parse($trans) as [$ms, $text]) {
                if ($text !== '') {
                    $translated[$ms] = $text;
                }
            }
            foreach ($lines as $i => [$ms, $text]) {
                if (isset($translated[$ms]) && $text !== '') {
                    $lines[$i][1] = $text . "\t" . $translated[$ms];
                }
            }
        }

        $out = '';
        foreach ($lines as [$ms, $text]) {
            $out .= sprintf("[%02d:%02d.%03d]%s\n", intdiv($ms, 60000), intdiv($ms % 60000, 1000), $ms % 1000, $text);
        }
        return $out;
    }

    /**
     * @return array [[毫秒, 文本], ...] 按时间排序
     */
    private static function parse(string $lrc): array
    {
        $lines = [];
        //\R 的字节集含 \x85（常见汉字的中间字节），这里必须用显式换行符切分
        foreach (preg_split('/\r\n|\r|\n/', $lrc) as $line) {
            if (!preg_match_all('/\[(\d{1,2}):(\d{1,2}(?:[.:]\d{1,3})?)]/', $line, $stamps, PREG_SET_ORDER)) {
                continue;
            }
            $text = trim((string)preg_replace('/\[[^\]]*]/', '', $line));
            foreach ($stamps as $stamp) {
                $ms = (int)$stamp[1] * 60000 + (int)round((float)str_replace(':', '.', $stamp[2]) * 1000);
                $lines[] = [$ms, $text];
            }
        }
        usort($lines, fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return $lines;
    }
}
