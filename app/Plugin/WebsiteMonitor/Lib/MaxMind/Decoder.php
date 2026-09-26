<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Lib\MaxMind;

/**
 * MaxMind DB 数据段解码器（binary format 2.x）。
 *
 * 命名空间刻意不叫 MaxMind\Db\Reader：将来如果项目真装了官方 composer 包，
 * 两边不会撞类名。
 *
 * 关键优化是 **惰性键过滤**：City 库的每条记录都带几十种语言的地名，
 * 全解一遍比只解 zh-CN + en 慢将近十倍。decode() 支持传一棵「想要的键」的树，
 * 不在树上的子结构直接 skip 掉，连构造都不构造。
 */
final class Decoder
{
    private const T_EXTENDED = 0;
    private const T_POINTER = 1;
    private const T_UTF8 = 2;
    private const T_DOUBLE = 3;
    private const T_BYTES = 4;
    private const T_UINT16 = 5;
    private const T_UINT32 = 6;
    private const T_MAP = 7;
    private const T_INT32 = 8;
    private const T_UINT64 = 9;
    private const T_UINT128 = 10;
    private const T_ARRAY = 11;
    private const T_CONTAINER = 12;
    private const T_END_MARKER = 13;
    private const T_BOOLEAN = 14;
    private const T_FLOAT = 15;

    private Reader $reader;
    private int $pointerBase;

    public function __construct(Reader $reader, int $pointerBase)
    {
        $this->reader = $reader;
        $this->pointerBase = $pointerBase;
    }

    /**
     * 解码一个值。$offset 会被推进到该值之后。
     *
     * @param array<string,mixed>|null $want null = 全解；数组 = 只解这些键（值为 true 表示整支都要）
     * @param int $depth 防御性递归深度限制
     */
    public function decode(int &$offset, ?array $want = null, int $depth = 0)
    {
        if ($depth > 32) {
            throw new InvalidDatabaseException('数据段嵌套过深，文件可能已损坏');
        }

        $ctrl = ord($this->reader->bytes($offset, 1));
        $offset++;
        $type = $ctrl >> 5;

        if ($type === self::T_EXTENDED) {
            $type = ord($this->reader->bytes($offset, 1)) + 7;
            $offset++;
        }

        //指针要在读长度之前处理：它的 size 字段含义完全不同
        if ($type === self::T_POINTER) {
            $target = $this->pointer($ctrl, $offset);
            //指针跳转不推进外层游标
            $cursor = $target;
            return $this->decode($cursor, $want, $depth + 1);
        }

        $size = $this->size($ctrl, $offset);

        switch ($type) {
            case self::T_UTF8:
                $value = $size === 0 ? '' : $this->reader->bytes($offset, $size);
                $offset += $size;
                return $value;

            case self::T_MAP:
                return $this->map($offset, $size, $want, $depth);

            case self::T_ARRAY:
                $list = [];
                for ($i = 0; $i < $size; $i++) {
                    $list[] = $this->decode($offset, $want, $depth + 1);
                }
                return $list;

            case self::T_UINT16:
            case self::T_UINT32:
                $value = $this->uint($offset, $size);
                $offset += $size;
                return $value;

            case self::T_UINT64:
            case self::T_UINT128:
                //超过 PHP 整数范围的部分用字符串表示，本插件用不到这两种类型
                $value = $size === 0 ? 0 : $this->uint($offset, min($size, 8));
                $offset += $size;
                return $value;

            case self::T_INT32:
                $value = $this->int32($offset, $size);
                $offset += $size;
                return $value;

            case self::T_BOOLEAN:
                //布尔的值就藏在 size 里，不占数据字节
                return $size !== 0;

            case self::T_DOUBLE:
                $raw = $this->reader->bytes($offset, $size);
                $offset += $size;
                return $size === 8 ? (float)(unpack('E', $raw)[1] ?? 0.0) : 0.0;

            case self::T_FLOAT:
                $raw = $this->reader->bytes($offset, $size);
                $offset += $size;
                return $size === 4 ? (float)(unpack('G', $raw)[1] ?? 0.0) : 0.0;

            case self::T_BYTES:
                $value = $size === 0 ? '' : $this->reader->bytes($offset, $size);
                $offset += $size;
                return $value;

            case self::T_CONTAINER:
            case self::T_END_MARKER:
                return null;

            default:
                throw new InvalidDatabaseException('未知的数据类型：' . $type);
        }
    }

    /**
     * 只推进游标、不构造值。这是惰性过滤能省下开销的关键。
     */
    public function skip(int &$offset, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new InvalidDatabaseException('数据段嵌套过深，文件可能已损坏');
        }

        $ctrl = ord($this->reader->bytes($offset, 1));
        $offset++;
        $type = $ctrl >> 5;

        if ($type === self::T_EXTENDED) {
            $type = ord($this->reader->bytes($offset, 1)) + 7;
            $offset++;
        }

        if ($type === self::T_POINTER) {
            //指针本身占几个字节读掉就行，目标内容不用管
            $this->pointer($ctrl, $offset);
            return;
        }

        $size = $this->size($ctrl, $offset);

        if ($type === self::T_MAP) {
            for ($i = 0; $i < $size; $i++) {
                $this->skip($offset, $depth + 1);
                $this->skip($offset, $depth + 1);
            }
            return;
        }
        if ($type === self::T_ARRAY) {
            for ($i = 0; $i < $size; $i++) {
                $this->skip($offset, $depth + 1);
            }
            return;
        }
        if ($type === self::T_BOOLEAN || $type === self::T_CONTAINER || $type === self::T_END_MARKER) {
            return;
        }
        $offset += $size;
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    /**
     * @param array<string,mixed>|null $want
     * @return array<string,mixed>
     */
    private function map(int &$offset, int $size, ?array $want, int $depth): array
    {
        $out = [];
        for ($i = 0; $i < $size; $i++) {
            $key = $this->decode($offset, null, $depth + 1);
            $key = is_string($key) ? $key : (string)$key;

            if ($want === null) {
                $out[$key] = $this->decode($offset, null, $depth + 1);
                continue;
            }

            $sub = $want[$key] ?? false;
            if ($sub === false) {
                //不想要的整支跳过，连值都不构造
                $this->skip($offset, $depth + 1);
                continue;
            }
            $out[$key] = $this->decode($offset, is_array($sub) ? $sub : null, $depth + 1);
        }
        return $out;
    }

    /**
     * 指针解码。四种宽度对应不同的偏移基数，是格式规定的常量。
     */
    private function pointer(int $ctrl, int &$offset): int
    {
        $size = $ctrl & 0x1F;
        $pointerSize = (($size >> 3) & 0x03) + 1;
        $base = $pointerSize === 4 ? 0 : ($size & 0x07);
        $raw = $this->reader->bytes($offset, $pointerSize);
        $offset += $pointerSize;

        $value = $base;
        for ($i = 0; $i < $pointerSize; $i++) {
            $value = ($value << 8) | ord($raw[$i]);
        }

        //规范定义的固定补偿量
        $bump = [1 => 0, 2 => 2048, 3 => 526336, 4 => 0];
        return $value + $bump[$pointerSize] + $this->pointerBase;
    }

    private function size(int $ctrl, int &$offset): int
    {
        $size = $ctrl & 0x1F;
        if ($size < 29) {
            return $size;
        }
        if ($size === 29) {
            $size = 29 + ord($this->reader->bytes($offset, 1));
            $offset += 1;
            return $size;
        }
        if ($size === 30) {
            $raw = $this->reader->bytes($offset, 2);
            $offset += 2;
            return 285 + ((ord($raw[0]) << 8) | ord($raw[1]));
        }
        $raw = $this->reader->bytes($offset, 3);
        $offset += 3;
        return 65821 + ((ord($raw[0]) << 16) | (ord($raw[1]) << 8) | ord($raw[2]));
    }

    private function uint(int $offset, int $size): int
    {
        if ($size === 0) {
            return 0;
        }
        $raw = $this->reader->bytes($offset, $size);
        $value = 0;
        for ($i = 0; $i < $size; $i++) {
            $value = ($value << 8) | ord($raw[$i]);
        }
        return $value;
    }

    private function int32(int $offset, int $size): int
    {
        if ($size === 0) {
            return 0;
        }
        $value = $this->uint($offset, $size);
        //补码还原负数
        $bits = $size * 8;
        if ($bits < 64 && ($value & (1 << ($bits - 1))) !== 0) {
            $value -= (1 << $bits);
        }
        return $value;
    }
}
