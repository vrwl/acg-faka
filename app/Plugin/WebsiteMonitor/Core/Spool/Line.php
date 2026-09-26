<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Spool;

/**
 * spool 行的编解码。
 *
 * 用 \x1f（单元分隔符）分隔的定位字段，而不是 JSON：
 * json_encode/decode 在每请求的热路径上是纯浪费，implode/explode 快一个数量级，
 * 而且 \x1f 是控制字符，正常的 URL / UA / Referer 里不可能出现。
 *
 * 两种行：
 *   v1 完整行（约 220–320 字节）
 *   v2 精简行（约 40 字节）—— 洪水降级时用，PV / 攻击数 / TOP-IP 依然精确，
 *      只丢页面 / 来源 / UA 明细。
 */
final class Line
{
    public const SEP = "\x1f";
    public const V_FULL = 1;
    public const V_LEAN = 2;

    /** v1 字段顺序，改动即破坏兼容，只允许在末尾追加 */
    private const FIELDS_FULL = [
        'v', 'ts', 'ms', 'status', 'kind', 'method', 'vid', 'sid', 'uid', 'flags',
        'ip', 'path', 'query', 'ref', 'uahash', 'ua', 'lang', 'spider', 'host', 'dev',
        'atk_kind', 'atk_rule', 'atk_act', 'atk_score', 'atk_level', 'req_id', 'evidence',
    ];

    /** v2 字段顺序 */
    private const FIELDS_LEAN = [
        'v', 'ts', 'kind', 'status', 'spider', 'ip', 'flags', 'atk_kind', 'atk_rule', 'atk_act',
    ];

    /**
     * 组装一行（末尾自带换行）
     *
     * @param array<string,mixed> $row
     */
    public static function pack(array $row, bool $lean = false): string
    {
        $fields = $lean ? self::FIELDS_LEAN : self::FIELDS_FULL;
        $row['v'] = $lean ? self::V_LEAN : self::V_FULL;

        $out = [];
        foreach ($fields as $field) {
            $out[] = self::clean($row[$field] ?? '');
        }
        return implode(self::SEP, $out) . "\n";
    }

    /**
     * 解析一行；格式不对返回 null（坏行直接丢，不能让一行脏数据卡住整批入库）
     *
     * @return array<string,mixed>|null
     */
    public static function unpack(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            return null;
        }
        $parts = explode(self::SEP, $line);
        $version = (int)($parts[0] ?? 0);
        $fields = match ($version) {
            self::V_FULL => self::FIELDS_FULL,
            self::V_LEAN => self::FIELDS_LEAN,
            default => null,
        };
        if ($fields === null) {
            return null;
        }

        $out = [];
        foreach ($fields as $i => $field) {
            $out[$field] = $parts[$i] ?? '';
        }
        //数值字段统一转型，后续折叠时就不用反复 (int) 了
        foreach (['v', 'ts', 'ms', 'status', 'kind', 'method', 'uid', 'flags', 'spider', 'dev',
                     'atk_kind', 'atk_act', 'atk_score'] as $numeric) {
            if (isset($out[$numeric])) {
                $out[$numeric] = (int)$out[$numeric];
            }
        }
        if ($out['ts'] <= 0) {
            return null;
        }
        return $out;
    }

    /**
     * 整个文件 → 行数组
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parseFile(string $file): array
    {
        $rows = [];
        $fp = @fopen($file, 'r');
        if ($fp === false) {
            return $rows;
        }
        while (($line = fgets($fp)) !== false) {
            $row = self::unpack($line);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        @fclose($fp);
        return $rows;
    }

    /**
     * 字段值清洗：分隔符与换行必须消失，否则会撕裂整行。
     */
    private static function clean(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if ($value === true) {
            return '1';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        $value = (string)$value;
        if ($value === '') {
            return '';
        }
        return strtr($value, [self::SEP => ' ', "\n" => ' ', "\r" => '']);
    }
}
