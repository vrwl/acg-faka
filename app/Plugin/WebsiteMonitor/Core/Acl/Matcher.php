<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Acl;

use App\Plugin\WebsiteMonitor\Core\Ip;

/**
 * 热路径上的名单匹配：一次 inet_pton + 一次哈希查 + 最多 ~8 次掩码与哈希查。
 *
 * 编译产物的形状（由 Compiler 生成，存在 rules.php 里）：
 *   exact  32位hex          => ruleId      精确 IP，O(1)
 *   cidr   位数 => (掩码后hex => ruleId)    按前缀长度分桶
 *   plens  出现过的位数，降序                只探测真实存在的长度，通常 ≤ 8 个
 *   range  [[startHex, endHex, ruleId], …]  按 startHex 升序，二分查找
 *
 * 通配符 1.2.3.* 在编译期就转成了 /24，运行时不存在通配匹配。
 */
final class Matcher
{
    /**
     * @param array<string,mixed> $table Compiler 产出的一侧（allow 或 deny）
     * @return int|null 命中的规则 id
     */
    public static function match(string $ip, array $table): ?int
    {
        if ($table === [] || $ip === '') {
            return null;
        }
        $packed = Ip::pack($ip);
        if ($packed === null) {
            return null;
        }
        return self::matchPacked($packed, $table);
    }

    /**
     * 已经 pack 过的版本（同一请求里要查 allow / deny / always_allow 三张表，
     * 复用同一份 packed 能省两次 inet_pton）
     *
     * @param array<string,mixed> $table
     */
    public static function matchPacked(string $packed, array $table): ?int
    {
        $hex = bin2hex($packed);

        $exact = $table['exact'] ?? [];
        if (isset($exact[$hex])) {
            return (int)$exact[$hex];
        }

        $cidr = $table['cidr'] ?? [];
        if ($cidr !== []) {
            //plens 是降序的：更长的前缀（更精确的规则）先命中
            foreach (($table['plens'] ?? []) as $bits) {
                $bucket = $cidr[$bits] ?? null;
                if ($bucket === null) {
                    continue;
                }
                $masked = bin2hex(self::mask($packed, (int)$bits));
                if (isset($bucket[$masked])) {
                    return (int)$bucket[$masked];
                }
            }
        }

        $ranges = $table['range'] ?? [];
        if ($ranges !== []) {
            $lo = 0;
            $hi = count($ranges) - 1;
            while ($lo <= $hi) {
                $mid = ($lo + $hi) >> 1;
                $row = $ranges[$mid];
                if (strcmp($hex, (string)$row[0]) < 0) {
                    $hi = $mid - 1;
                } elseif (strcmp($hex, (string)$row[1]) > 0) {
                    $lo = $mid + 1;
                } else {
                    return (int)$row[2];
                }
            }
        }

        return null;
    }

    /**
     * 按位掩码。内联实现，避免热路径上多一层调用。
     */
    private static function mask(string $packed, int $bits): string
    {
        if ($bits >= 128) {
            return $packed;
        }
        if ($bits <= 0) {
            return str_repeat("\x00", 16);
        }
        $whole = $bits >> 3;
        $rem = $bits & 7;
        $out = substr($packed, 0, $whole);
        if ($rem > 0) {
            $out .= chr(ord($packed[$whole]) & ((0xFF << (8 - $rem)) & 0xFF));
            $whole++;
        }
        return str_pad($out, 16, "\x00");
    }

    /**
     * 往编译表里塞一条规则（Compiler 用）
     *
     * @param array<string,mixed> $table
     * @param array{start_hex:string,end_hex:string,bits:int} $parsed
     */
    public static function insert(array &$table, array $parsed, int $ruleId): void
    {
        $bits = (int)$parsed['bits'];
        if ($bits === 128) {
            $table['exact'][$parsed['start_hex']] = $ruleId;
            return;
        }
        if ($bits >= 0) {
            $table['cidr'][$bits][$parsed['start_hex']] = $ruleId;
            return;
        }
        $table['range'][] = [$parsed['start_hex'], $parsed['end_hex'], $ruleId];
    }

    /**
     * 收尾：算出 plens（降序）并把 range 按起点排序，供二分查找
     *
     * @param array<string,mixed> $table
     */
    public static function finalize(array &$table): void
    {
        $table['exact'] = $table['exact'] ?? [];
        $table['cidr'] = $table['cidr'] ?? [];
        $table['range'] = $table['range'] ?? [];

        $plens = array_map('intval', array_keys($table['cidr']));
        rsort($plens);
        $table['plens'] = $plens;

        usort($table['range'], static fn(array $a, array $b): int => strcmp((string)$a[0], (string)$b[0]));
    }

    /** 表里是否一条规则都没有（空表可以整段跳过） */
    public static function isEmpty(array $table): bool
    {
        return ($table['exact'] ?? []) === []
            && ($table['cidr'] ?? []) === []
            && ($table['range'] ?? []) === [];
    }
}
