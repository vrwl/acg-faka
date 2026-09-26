<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Lib\MaxMind;

/**
 * mmdb 元数据。校验也放在这里 —— 下载完成后先解析元数据再换文件，
 * 能挡住绝大多数「下了半个文件」「下到一个 HTML 错误页」的情况。
 */
final class Metadata
{
    public int $nodeCount = 0;
    public int $recordSize = 0;
    public int $ipVersion = 6;
    public string $databaseType = '';
    public int $binaryFormatMajorVersion = 0;
    public int $binaryFormatMinorVersion = 0;
    public int $buildEpoch = 0;
    public string $description = '';

    /** @var string[] */
    public array $languages = [];

    /**
     * @param array<string,mixed> $raw
     * @throws InvalidDatabaseException
     */
    public static function fromArray(array $raw): self
    {
        $meta = new self();
        $meta->nodeCount = (int)($raw['node_count'] ?? 0);
        $meta->recordSize = (int)($raw['record_size'] ?? 0);
        $meta->ipVersion = (int)($raw['ip_version'] ?? 6);
        $meta->databaseType = (string)($raw['database_type'] ?? '');
        $meta->binaryFormatMajorVersion = (int)($raw['binary_format_major_version'] ?? 0);
        $meta->binaryFormatMinorVersion = (int)($raw['binary_format_minor_version'] ?? 0);
        $meta->buildEpoch = (int)($raw['build_epoch'] ?? 0);
        $meta->languages = array_values(array_filter(array_map(
            static fn($v): string => (string)$v,
            (array)($raw['languages'] ?? [])
        )));

        $description = $raw['description'] ?? [];
        if (is_array($description)) {
            $meta->description = (string)($description['zh-CN'] ?? $description['en'] ?? reset($description) ?: '');
        }

        $meta->validate();
        return $meta;
    }

    /**
     * @throws InvalidDatabaseException
     */
    public function validate(): void
    {
        if ($this->binaryFormatMajorVersion !== 2) {
            throw new InvalidDatabaseException('不支持的 mmdb 格式版本：' . $this->binaryFormatMajorVersion);
        }
        if ($this->nodeCount <= 0) {
            throw new InvalidDatabaseException('节点数异常，文件可能已损坏');
        }
        if (!in_array($this->recordSize, [24, 28, 32], true)) {
            throw new InvalidDatabaseException('不支持的记录宽度：' . $this->recordSize);
        }
        if (!in_array($this->ipVersion, [4, 6], true)) {
            throw new InvalidDatabaseException('不支持的 IP 版本：' . $this->ipVersion);
        }
    }

    public function isCity(): bool
    {
        return stripos($this->databaseType, 'city') !== false;
    }

    public function isCountry(): bool
    {
        return stripos($this->databaseType, 'country') !== false;
    }

    public function buildDate(): string
    {
        return $this->buildEpoch > 0 ? date('Y-m-d H:i:s', $this->buildEpoch) : '';
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'node_count' => $this->nodeCount,
            'record_size' => $this->recordSize,
            'ip_version' => $this->ipVersion,
            'database_type' => $this->databaseType,
            'binary_format' => $this->binaryFormatMajorVersion . '.' . $this->binaryFormatMinorVersion,
            'build_epoch' => $this->buildEpoch,
            'build_date' => $this->buildDate(),
            'languages' => $this->languages,
            'description' => $this->description,
        ];
    }
}
