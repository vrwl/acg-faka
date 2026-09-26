<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Lib\MaxMind;

/**
 * MaxMind DB 纯 PHP 读取器。
 *
 * 两条硬约束决定了实现方式：
 *  1. **绝不整文件读入**。GeoLite2-City 有 60–80 MB，file_get_contents 会直接撞 memory_limit。
 *     用持久 fopen('rb') + fseek 随机读。
 *  2. **块级缓存**。搜索树的遍历有极强的根部局部性：前十几层永远落在同一批块上。
 *     4KB 一块、最多缓 64 块（256KB/进程），能把每次查询的 fread 次数从三十几降到个位数。
 */
final class Reader
{
    private const METADATA_MARKER = "\xAB\xCD\xEF" . 'MaxMind.com';
    private const METADATA_MAX_SIZE = 131072;
    private const DATA_SEPARATOR_SIZE = 16;

    private const BLOCK_BITS = 12;
    private const BLOCK_SIZE = 4096;
    private const BLOCK_MAX = 64;

    /** @var resource|null */
    private $fh = null;
    private string $file;
    private int $fileSize = 0;

    /** @var array<int,string> 块偏移 => 4KB 内容 */
    private array $blocks = [];

    private Metadata $metadata;
    private Decoder $decoder;

    private int $nodeCount = 0;
    private int $recordSize = 0;
    private int $nodeByteSize = 0;
    private int $searchTreeSize = 0;
    private int $ipVersion = 6;
    private int $ipv4Start = -1;

    /**
     * @throws InvalidDatabaseException
     */
    public function __construct(string $file)
    {
        $this->file = $file;
        if (!is_file($file) || !is_readable($file)) {
            throw new InvalidDatabaseException('IP 库文件不存在或不可读：' . $file);
        }
        $size = @filesize($file);
        if ($size === false || $size < 1024) {
            throw new InvalidDatabaseException('IP 库文件过小，可能没下载完');
        }
        $this->fileSize = (int)$size;

        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            throw new InvalidDatabaseException('无法打开 IP 库文件');
        }
        $this->fh = $fh;

        $this->metadata = $this->readMetadata();
        $this->nodeCount = $this->metadata->nodeCount;
        $this->recordSize = $this->metadata->recordSize;
        $this->nodeByteSize = (int)($this->recordSize * 2 / 8);
        $this->searchTreeSize = $this->nodeCount * $this->nodeByteSize;
        $this->ipVersion = $this->metadata->ipVersion;

        $this->decoder = new Decoder($this, $this->searchTreeSize + self::DATA_SEPARATOR_SIZE);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->fh)) {
            @fclose($this->fh);
        }
        $this->fh = null;
        $this->blocks = [];
    }

    public function metadata(): Metadata
    {
        return $this->metadata;
    }

    /**
     * 查询一个 IP。
     *
     * @param array<string,mixed>|null $want 只解这些键（见 Decoder::decode）
     * @return array{data:array<string,mixed>,prefix:int}|null null = 库里没有这个 IP
     */
    public function get(string $ip, ?array $want = null): ?array
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        //IPv4-only 的库不能查 IPv6；IPv6 库查 IPv4 时要从 ipv4Start 节点起步
        if (strlen($packed) === 16 && $this->ipVersion === 4) {
            //v4-mapped 的可以折回来，纯 v6 的查不了
            if (substr($packed, 0, 12) !== "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
                return null;
            }
            $packed = substr($packed, 12);
        }

        $found = $this->find($packed);
        if ($found === null) {
            return null;
        }
        [$pointer, $prefix] = $found;

        $offset = $pointer;
        $data = $this->decoder->decode($offset, $want);
        return ['data' => is_array($data) ? $data : [], 'prefix' => $prefix];
    }

    /**
     * 搜索树遍历。
     *
     * @return array{0:int,1:int}|null [数据段绝对偏移, 命中网络的前缀长度]
     */
    private function find(string $packed): ?array
    {
        $bits = strlen($packed) * 8;

        if ($bits === 32) {
            $node = $this->ipv4StartNode();
        } else {
            $node = 0;
        }

        $depth = 0;
        for ($i = 0; $i < $bits; $i++) {
            if ($node >= $this->nodeCount) {
                break;
            }
            $bit = (ord($packed[$i >> 3]) >> (7 - ($i & 7))) & 1;
            $node = $this->readNode($node, $bit);
            $depth = $i + 1;
        }

        if ($node === $this->nodeCount) {
            //空记录：这个网段库里没有数据
            return null;
        }
        if ($node < $this->nodeCount) {
            throw new InvalidDatabaseException('搜索树遍历异常，文件可能已损坏');
        }

        //规范：记录值大于节点数时，减去节点数再加上搜索树长度，就是数据段里的绝对偏移
        $pointer = $node - $this->nodeCount + $this->searchTreeSize;
        if ($pointer >= $this->fileSize) {
            throw new InvalidDatabaseException('数据指针越界，文件可能已损坏');
        }

        //IPv4 在 IPv6 库里是从第 96 位开始的，前缀长度要减掉这段
        if ($bits === 32 && $this->ipVersion === 6) {
            $depth += 96;
        }
        return [$pointer, $depth];
    }

    /**
     * 读节点的第 $index（0=左/bit 0，1=右/bit 1）条记录
     */
    private function readNode(int $node, int $index): int
    {
        $base = $node * $this->nodeByteSize;

        if ($this->recordSize === 28) {
            //28 位记录：两条记录共用中间那个字节的高低半字节
            $middle = ord($this->bytes($base + 3, 1));
            $high = $index === 0 ? (($middle & 0xF0) >> 4) : ($middle & 0x0F);
            $raw = $this->bytes($base + $index * 4, 3);
            return ($high << 24) | (ord($raw[0]) << 16) | (ord($raw[1]) << 8) | ord($raw[2]);
        }

        if ($this->recordSize === 24) {
            $raw = $this->bytes($base + $index * 3, 3);
            return (ord($raw[0]) << 16) | (ord($raw[1]) << 8) | ord($raw[2]);
        }

        if ($this->recordSize === 32) {
            $raw = $this->bytes($base + $index * 4, 4);
            return (ord($raw[0]) << 24) | (ord($raw[1]) << 16) | (ord($raw[2]) << 8) | ord($raw[3]);
        }

        throw new InvalidDatabaseException('不支持的记录宽度：' . $this->recordSize);
    }

    /**
     * IPv6 库里 IPv4 的起点节点：从根走 96 个 0 位。算一次就缓存。
     */
    private function ipv4StartNode(): int
    {
        if ($this->ipv4Start >= 0) {
            return $this->ipv4Start;
        }
        if ($this->ipVersion === 4) {
            return $this->ipv4Start = 0;
        }
        $node = 0;
        for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++) {
            $node = $this->readNode($node, 0);
        }
        return $this->ipv4Start = $node;
    }

    /**
     * 带 4KB 块缓存的随机读。跨块请求自动拼接。
     */
    public function bytes(int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        if ($offset < 0 || $offset + $length > $this->fileSize) {
            throw new InvalidDatabaseException('读越界：offset=' . $offset . ' length=' . $length);
        }

        $out = '';
        while ($length > 0) {
            $blockId = $offset >> self::BLOCK_BITS;
            if (!isset($this->blocks[$blockId])) {
                if (count($this->blocks) > self::BLOCK_MAX) {
                    //简单粗暴地丢掉一半：树遍历的局部性很强，留最近的就够了
                    $this->blocks = array_slice($this->blocks, -(int)(self::BLOCK_MAX / 2), null, true);
                }
                if (!is_resource($this->fh)) {
                    throw new InvalidDatabaseException('IP 库文件句柄已关闭');
                }
                @fseek($this->fh, $blockId << self::BLOCK_BITS);
                $chunk = @fread($this->fh, self::BLOCK_SIZE);
                $this->blocks[$blockId] = $chunk === false ? '' : $chunk;
            }
            $inBlock = $offset & (self::BLOCK_SIZE - 1);
            $take = min($length, self::BLOCK_SIZE - $inBlock);
            $piece = substr($this->blocks[$blockId], $inBlock, $take);
            if (strlen($piece) < $take) {
                throw new InvalidDatabaseException('读到文件末尾之外，文件可能被截断');
            }
            $out .= $piece;
            $offset += $take;
            $length -= $take;
        }
        return $out;
    }

    /**
     * 元数据在文件尾部，靠 marker 从后往前找。
     */
    private function readMetadata(): Metadata
    {
        $scan = min(self::METADATA_MAX_SIZE, $this->fileSize);
        $start = $this->fileSize - $scan;
        @fseek($this->fh, $start);
        $tail = (string)@fread($this->fh, $scan);

        $pos = strrpos($tail, self::METADATA_MARKER);
        if ($pos === false) {
            throw new InvalidDatabaseException('找不到 MaxMind 元数据标记，这不是一个有效的 mmdb 文件');
        }

        $metaStart = $start + $pos + strlen(self::METADATA_MARKER);

        //元数据自己也是一段标准编码的 map，用一个 pointerBase=metaStart 的临时解码器读
        $tempDecoder = new Decoder($this, $metaStart);
        $offset = $metaStart;
        $raw = $tempDecoder->decode($offset, null);
        if (!is_array($raw)) {
            throw new InvalidDatabaseException('元数据解析失败');
        }
        return Metadata::fromArray($raw);
    }

    public function file(): string
    {
        return $this->file;
    }

    public function fileSize(): int
    {
        return $this->fileSize;
    }
}
