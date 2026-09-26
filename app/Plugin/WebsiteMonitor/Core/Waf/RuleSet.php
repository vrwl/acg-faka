<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Waf;

use App\Plugin\WebsiteMonitor\Api\Decision;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Settings;

/**
 * WAF 规则的加载、覆盖与编译。
 *
 * 编译产物的两个关键优化：
 *  1. **分块合并正则**：同一个检测面上的规则每 12 条合成一个 alternation，
 *     一次 preg_match 就能判定「这一块有没有命中」；只有命中了才逐条定位是哪条。
 *     正常请求根本不会命中，所以永远只付分块那几次的钱。
 *  2. **触发字符集**：把所有规则里出现的特殊字符汇总成一个字符串，
 *     热路径先用 strpbrk 扫一遍 —— 正常请求（/user/index/index?cid=3）里
 *     一个都不含，直接跳过全部正则。这是整个防火墙的性能守门员。
 */
final class RuleSet
{
    /**
     * 注入类载荷绕不开的字符。正常的路径与数字参数一个都不含。
     * 注意别把 `-`、`.`、`_`、`/` 这类正常字符放进来，否则预筛等于没做。
     */
    private const TRIGGER = "'\"<>()\\;|`$*%{}[]&=:";

    /**
     * 编译全部规则
     *
     * @return array<string,mixed>
     */
    public static function compile(): array
    {
        $overrides = self::overrides();
        $rules = [];
        $needles = [];

        foreach (self::files() as $file) {
            try {
                $raw = json_decode((string)@file_get_contents($file), true);
            } catch (\Throwable $e) {
                $raw = null;
            }
            if (!is_array($raw)) {
                Log::warn('WAF 规则文件解析失败', ['file' => basename($file)]);
                continue;
            }
            foreach ((array)($raw['needles'] ?? []) as $needle) {
                $needle = strtolower(trim((string)$needle));
                if ($needle !== '') {
                    $needles[] = $needle;
                }
            }
            foreach ((array)($raw['rules'] ?? []) as $rule) {
                $id = (string)($rule['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $override = (array)($overrides[$id] ?? []);
                $enabled = array_key_exists('enabled', $override)
                    ? (bool)$override['enabled']
                    : (bool)($rule['enabled'] ?? true);

                $pattern = self::sanitizePattern((string)($rule['re'] ?? ''), $id);

                $rules[$id] = [
                    'id' => $id,
                    'name' => (string)($rule['name'] ?? $id),
                    'group' => (string)($raw['group'] ?? 'other'),
                    'surface' => array_map('strval', (array)($rule['surface'] ?? [])),
                    're' => $pattern,
                    'action' => (string)($override['action'] ?? $rule['action'] ?? 'score'),
                    'level' => (string)($override['level'] ?? $rule['level'] ?? 'warn'),
                    'score' => (int)($override['score'] ?? $rule['score'] ?? 5),
                    'status' => (int)($rule['status'] ?? 0),
                    'threshold' => (int)($override['threshold'] ?? $rule['threshold'] ?? 0),
                    'window' => (int)($override['window'] ?? $rule['window'] ?? 0),
                    'fp' => (string)($rule['fp'] ?? 'low'),
                    'enabled' => $enabled,
                ];
            }
        }

        $surfaces = self::buildSurfaces($rules);

        //id => 正则 的平表：给 Api\Guard::checkRequest() 用 ——
        //其它插件想对任意一段输入跑一遍全部规则时，不关心检测面的划分。
        $single = [];
        foreach ($rules as $id => $rule) {
            if ($rule['enabled'] && $rule['re'] !== '') {
                $compiled = '#' . $rule['re'] . '#i';
                if (@preg_match($compiled, '') !== false) {
                    $single[$id] = $compiled;
                }
            }
        }

        $meta = [];
        foreach ($rules as $id => $rule) {
            $meta[$id] = [
                'name' => $rule['name'],
                'group' => $rule['group'],
                'action' => $rule['action'],
                'level' => $rule['level'],
                'score' => $rule['score'],
                'status' => $rule['status'],
                'threshold' => $rule['threshold'],
                'window' => $rule['window'],
                'enabled' => $rule['enabled'],
                'fp' => $rule['fp'],
            ];
        }

        return [
            'trigger' => self::TRIGGER,
            'waf' => $surfaces,
            'single' => $single,
            'scan_needles' => array_values(array_unique($needles)),
            'meta' => $meta,
            'exclude_paths' => array_map('strtolower', Settings::lines('waf_exclude_paths')),
            'exclude_fields' => array_map('strtolower', Settings::lines('waf_exclude_fields')),
        ];
    }

    /**
     * 规则源码里出现未转义的 `#` 会直接截断正则（我们用 `#` 当分隔符），
     * 后果是这条规则连同它所在的整个分块一起静默失效 —— 防火墙看着在跑，其实什么都不拦。
     * 这里统一转义掉，并留一条日志提醒规则作者改用 \x23。
     */
    private static function sanitizePattern(string $pattern, string $id): string
    {
        if ($pattern === '' || !str_contains($pattern, '#')) {
            return $pattern;
        }
        //只转义没被反斜杠转义过的 #
        $safe = preg_replace('/(?<!\\\\)#/', '\\#', $pattern);
        if (!is_string($safe)) {
            return $pattern;
        }
        if ($safe !== $pattern) {
            Log::warn('WAF 规则含未转义的 # 分隔符，已自动转义（建议改写成 \\x23）', ['id' => $id]);
        }
        return $safe;
    }

    /**
     * 按检测面分组，每条规则一个独立正则。
     *
     * 这里**故意不做分块合并**。直觉上「把 12 条规则合成一个大 alternation，一次
     * preg_match 判定整块」应该更快，实测正相反：合并后慢 2.9 倍（20.8µs vs 7.2µs）。
     * 原因是 PCRE 的起始优化（首字节集合、必需字节、最小长度）在单个模式上很有效，
     * 一旦变成巨型 alternation 就全部失效，只能老老实实逐位回溯。
     *
     * 逐条匹配还顺带省掉了「命中后再定位是哪一条」的二次扫描。
     *
     * @param array<string,array<string,mixed>> $rules
     * @return array<string,array<int,array{id:string,re:string}>>
     */
    private static function buildSurfaces(array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            if (!$rule['enabled'] || $rule['re'] === '') {
                continue;
            }
            $compiled = '#' . $rule['re'] . '#i';
            //编译期自检：坏正则绝不能带到线上，否则每个请求都会静默失败，
            //防火墙看着在跑，其实什么都不拦。
            if (@preg_match($compiled, '') === false) {
                Log::error('WAF 规则正则非法，已跳过', ['id' => $rule['id']]);
                continue;
            }
            foreach ($rule['surface'] as $surface) {
                $out[$surface][] = ['id' => $rule['id'], 're' => $compiled];
            }
        }
        return $out;
    }

    /**
     * 站长在面板里改过的规则设置（存成 JSON 字符串）
     *
     * @return array<string,array<string,mixed>>
     */
    public static function overrides(): array
    {
        $raw = Settings::get('waf_rules', '{}');
        if (trim($raw) === '' || $raw === '{}') {
            return [];
        }
        //配置保存路径会 urlencode，两种都试一遍
        $decoded = json_decode(urldecode($raw), true);
        if (!is_array($decoded)) {
            $decoded = json_decode($raw, true);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 规则清单（面板展示与配置用，不进热路径）
     *
     * @return array<int,array<string,mixed>>
     */
    public static function catalog(): array
    {
        $compiled = self::compile();
        $out = [];
        foreach ((array)$compiled['meta'] as $id => $meta) {
            $out[] = array_merge(['id' => (string)$id], (array)$meta);
        }
        usort($out, static function (array $a, array $b): int {
            return [$a['group'], $a['id']] <=> [$b['group'], $b['id']];
        });
        return $out;
    }

    /**
     * @return string[]
     */
    private static function files(): array
    {
        $dir = BASE_PATH . '/app/Plugin/' . Settings::PLUGIN . '/Core/Waf/Rule';
        $files = @glob($dir . '/*.json');
        return is_array($files) ? $files : [];
    }

    /**
     * 动作字符串 → Decision 常量
     */
    public static function actionOf(string $action): int
    {
        return match ($action) {
            'log' => Decision::LOG,
            'score' => Decision::SCORE,
            'block' => Decision::BLOCK,
            'ban' => Decision::BAN,
            'throttle' => Decision::THROTTLE,
            default => Decision::SCORE,
        };
    }

    public static function actionText(string $action): string
    {
        return match ($action) {
            'log' => lang('仅记录'),
            'score' => lang('计分'),
            'block' => lang('拦截'),
            'ban' => lang('封禁'),
            'throttle' => lang('限速'),
            default => lang('计分'),
        };
    }
}
