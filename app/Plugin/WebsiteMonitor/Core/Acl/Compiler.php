<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Acl;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Ip;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * 把数据库里的规则编译成 rules.php（一个纯 PHP 数组文件，require 走 opcache）。
 *
 * 为什么要编译：热路径每个请求都要判黑白名单，查库是不可能的。
 * 编译产物是哈希表 + 排好序的区间数组，匹配成本 O(1)-ish，且零 I/O。
 *
 * 什么不进编译产物：**自动封禁**。封禁是秒级写入，每封一个 IP 就重写整份快照 +
 * 失效 opcache，等于在被攻击时帮攻击者放大。自动封禁走 Ban 的一 IP 一文件方案。
 */
final class Compiler
{
    /** 编译锁的有效期：抢到锁的进程若崩了，20 秒后别人可以接手 */
    private const LOCK_TTL = 20;

    /**
     * 标记编译产物已过期。
     * 只写一个标记，**不在当前请求里重建** —— 重建要查库，并发下会被放大成雪崩。
     */
    public static function markStale(): void
    {
        try {
            Settings::bumpAclVersion();
            @touch(Runtime::dir() . '/.stale');
        } catch (\Throwable $e) {
            Log::exception('Compiler::markStale', $e);
        }
    }

    public static function isStale(): bool
    {
        return is_file(Runtime::dir() . '/.stale') || State::rulesStale();
    }

    /**
     * 需要时重建（守护任务每轮、后台打开面板时调）。
     * 用文件锁保证同一时刻只有一个进程在编译。
     */
    public static function rebuildIfStale(): bool
    {
        if (!self::isStale()) {
            return false;
        }
        $lock = Runtime::dir() . '/compile.lock';
        $fp = @fopen($lock, 'c');
        if ($fp === false) {
            return false;
        }
        //非阻塞：抢不到就说明别人正在编，本次直接跳过
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            //锁太旧说明持有者崩了，清掉让下一轮重来
            if (is_file($lock) && time() - (int)@filemtime($lock) > self::LOCK_TTL) {
                @unlink($lock);
            }
            return false;
        }
        try {
            @touch($lock);
            self::build();
            //蜘蛛签名也可能被站长改过，一起重编译（两份产物分开存，各自失效各自的 opcache）
            try {
                \App\Plugin\WebsiteMonitor\Core\Collect\SpiderRepo::compile();
            } catch (\Throwable $e) {
                Log::exception('Compiler::rebuildIfStale::spiders', $e);
            }
            @unlink(Runtime::dir() . '/.stale');
            return true;
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /**
     * 全量编译
     *
     * @return array<string,mixed>
     */
    public static function build(): array
    {
        $started = microtime(true);
        Settings::refresh();

        $rules = State::emptyRules();
        $rules['v'] = Settings::int('acl_version', 1);
        $rules['built_at'] = time();

        $allow = State::emptyAclSide();
        $deny = State::emptyAclSide();
        $alwaysAllow = State::emptyAclSide();
        $uaDeny = [];
        $pathExempt = [];

        // ── 永久放行清单（配置项，不是数据库规则）
        //    这是防锁死的第三道保险，安装时会自动写入站长自己的 IP
        foreach (Settings::lines('always_allow') as $line) {
            $parsed = Ip::parseRule($line);
            if ($parsed !== null) {
                Matcher::insert($alwaysAllow, $parsed, -1);
            }
        }

        // ── 数据库里的规则
        try {
            $rows = Db::table(Db::RULE)
                ->where('status', 1)
                ->where(static function ($q): void {
                    $q->where('expire_at', 0)->orWhere('expire_at', '>=', time());
                })
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $id = (int)$row->id;
                $type = (int)$row->type;
                $value = (string)$row->value;

                if ($type === Kind::RULE_IP_ALLOW || $type === Kind::RULE_IP_DENY) {
                    $parsed = self::parsedFromRow($row);
                    if ($parsed === null) {
                        continue;
                    }
                    Matcher::insert($type === Kind::RULE_IP_ALLOW ? $allow : $deny, $parsed, $id);
                    continue;
                }
                if ($type === Kind::RULE_UA_DENY) {
                    $uaDeny[mb_strtolower($value)] = $id;
                    continue;
                }
                if ($type === Kind::RULE_PATH_EXEMPT) {
                    $pathExempt[strtolower($value)] = $id;
                }
            }
        } catch (\Throwable $e) {
            Log::exception('Compiler::build::rules', $e);
        }

        Matcher::finalize($allow);
        Matcher::finalize($deny);
        Matcher::finalize($alwaysAllow);

        $rules['acl'] = ['allow' => $allow, 'deny' => $deny];
        $rules['always_allow'] = $alwaysAllow;
        $rules['ua_deny'] = $uaDeny;
        $rules['path_exempt'] = $pathExempt;

        // ── 地区规则
        $region = Region::load();
        $rules['region'] = [
            'mode' => Settings::get('region_mode', 'off'),
            'scope' => Settings::get('region_scope', 'country'),
            'deny' => $region['deny'],
            'allow' => $region['allow'],
        ];

        // ── WAF 规则（P7 才有，缺席时留空表示不扫）
        if (class_exists('\App\Plugin\WebsiteMonitor\Core\Waf\RuleSet')) {
            try {
                $waf = \App\Plugin\WebsiteMonitor\Core\Waf\RuleSet::compile();
                $rules['trigger'] = $waf['trigger'] ?? $rules['trigger'];
                $rules['waf'] = $waf['waf'] ?? [];
                $rules['single'] = $waf['single'] ?? [];
                $rules['scan_needles'] = $waf['scan_needles'] ?? [];
                $rules['meta'] = $waf['meta'] ?? [];
                $rules['exclude_paths'] = $waf['exclude_paths'] ?? [];
                $rules['exclude_fields'] = $waf['exclude_fields'] ?? [];
            } catch (\Throwable $e) {
                Log::exception('Compiler::build::waf', $e);
            }
        }

        // ── 常用阈值快照：热路径直接读整数，省掉每次的配置解析与类型转换
        $rules['thresholds'] = [
            'waf_mode' => Settings::wafMode(),
            'trust_admin' => Settings::bool('trust_admin'),
            'acl_enabled' => Settings::bool('acl_enabled'),
            'waf_enabled' => Settings::bool('waf_enabled'),
            'cc_enabled' => Settings::bool('cc_enabled'),
            'cc_burst_limit' => Settings::intMin('cc_burst_limit', 5, 60),
            'cc_burst_window' => Settings::intMin('cc_burst_window', 1, 10),
            'cc_sustain_limit' => Settings::intMin('cc_sustain_limit', 10, 600),
            'cc_sustain_window' => Settings::intMin('cc_sustain_window', 10, 600),
            'cc_path_limit' => Settings::intMin('cc_path_limit', 3, 30),
            'cc_path_window' => Settings::intMin('cc_path_window', 1, 10),
            'cc_global_limit' => Settings::intMin('cc_global_limit', 100, 3000),
            'cc_global_window' => Settings::intMin('cc_global_window', 1, 10),
            'cc_ban_seconds' => Settings::intMin('cc_ban_seconds', 30, 300),
            'cc_exempt_spider' => Settings::bool('cc_exempt_spider'),
            'cc_exempt_member' => Settings::bool('cc_exempt_member'),
            'cc_exempt_paths' => array_map('strtolower', Settings::lines('cc_exempt_paths')),
            'overload_enabled' => Settings::bool('overload_enabled'),
            'overload_factor' => Settings::intMin('overload_factor', 2, 3),
            'ladder' => Settings::banLadder(),
            'ban_permanent_after' => Settings::intMin('ban_permanent_after', 2, 6),
            'ban_reset_hours' => Settings::intMin('ban_reset_hours', 1, 72),
            'ua_block_tools' => Settings::bool('ua_block_tools'),
            'ua_block_empty' => Settings::bool('ua_block_empty'),
            'ua_block_fake_spider' => Settings::bool('ua_block_fake_spider'),
            'method_allow' => array_map('strtoupper', Settings::csv('method_allow')),
            'host_guard' => Settings::bool('host_guard'),
            'host_allow' => array_map('strtolower', Settings::lines('host_allow')),
            'waf_score_threshold' => Settings::intMin('waf_score_threshold', 1, 10),
            'waf_body_max_kb' => Settings::intMin('waf_body_max_kb', 1, 64),
            'scan_surfaces' => [
                'path' => Settings::bool('waf_scan_path'),
                'query' => Settings::bool('waf_scan_query'),
                'body' => Settings::bool('waf_scan_body'),
                'cookie' => Settings::bool('waf_scan_cookie'),
                'header' => Settings::bool('waf_scan_header'),
                'files' => Settings::bool('waf_scan_files'),
            ],
            'block_status_code' => Settings::intMin('block_status_code', 400, 403),
            'block_page_title' => Settings::get('block_page_title'),
            'block_page_contact' => Settings::get('block_page_contact'),
            'log_evidence' => Settings::bool('log_evidence'),
            'evidence_max_bytes' => Settings::intMin('evidence_max_bytes', 256, 2048),
        ];

        State::writeRules($rules);

        $ms = (int)round((microtime(true) - $started) * 1000);
        Log::info('规则已重新编译', [
            'version' => $rules['v'],
            'allow' => self::countSide($allow),
            'deny' => self::countSide($deny),
            'region' => count($region['deny']) + count($region['allow']),
            'ms' => $ms,
        ]);

        //广播给其它插件（返回值忽略）
        try {
            hook(0x7C104, [
                'version' => $rules['v'],
                'rules' => count($rules['waf']),
                'acl' => self::countSide($allow) + self::countSide($deny),
                'ms' => $ms,
            ]);
        } catch (\Throwable $e) {
        }

        return $rules;
    }

    /**
     * 直接用库里存好的 hex 区间，省掉重新解析
     *
     * @return array{start_hex:string,end_hex:string,bits:int}|null
     */
    private static function parsedFromRow(object $row): ?array
    {
        $start = (string)$row->start_hex;
        $end = (string)$row->end_hex;
        if ($start === '' || $end === '') {
            //老数据或手工塞进来的行：回退到重新解析
            $parsed = Ip::parseRule((string)$row->value);
            return $parsed === null ? null : ['start_hex' => $parsed['start_hex'], 'end_hex' => $parsed['end_hex'], 'bits' => $parsed['bits']];
        }
        if ($start === $end) {
            return ['start_hex' => $start, 'end_hex' => $end, 'bits' => 128];
        }
        $bits = self::prefixBits($start, $end);
        return ['start_hex' => $start, 'end_hex' => $end, 'bits' => $bits];
    }

    /**
     * 判断一个区间是不是规整的 CIDR；是就返回位数，否则返回 -1（走区间二分）。
     */
    private static function prefixBits(string $startHex, string $endHex): int
    {
        $start = @hex2bin($startHex);
        $end = @hex2bin($endHex);
        if ($start === false || $end === false || strlen($start) !== 16 || strlen($end) !== 16) {
            return -1;
        }
        //异或后必须是「高位全 0、低位全 1」的形状，才是规整 CIDR
        $xor = $start ^ $end;
        $bits = 0;
        for ($i = 0; $i < 16; $i++) {
            $byte = ord($xor[$i]);
            if ($byte === 0) {
                $bits += 8;
                continue;
            }
            //剩余字节必须全 1
            for ($j = $i + 1; $j < 16; $j++) {
                if (ord($xor[$j]) !== 0xFF) {
                    return -1;
                }
            }
            //本字节必须是 000...111 的形状
            $remain = 0;
            while ($byte > 0) {
                if (($byte & 1) === 0) {
                    return -1;
                }
                $byte >>= 1;
                $remain++;
            }
            $bits += 8 - $remain;
            //起点必须正好是该前缀的网络地址，否则它只是个碰巧长得像 CIDR 的区间。
            //漏了这一步会让 1.2.3.1-1.2.3.2 被当成 1.2.3.0/126，把 1.2.3.0 和 1.2.3.3 一起误伤。
            return Ip::maskPacked($start, $bits) === $start ? $bits : -1;
        }
        return 128;
    }

    private static function countSide(array $side): int
    {
        $n = count($side['exact'] ?? []) + count($side['range'] ?? []);
        foreach (($side['cidr'] ?? []) as $bucket) {
            $n += count($bucket);
        }
        return $n;
    }
}
