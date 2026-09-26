<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Waf;

use App\Plugin\WebsiteMonitor\Api\Decision;
use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Acl\AdminGuard;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;

/**
 * WAF 判定引擎。跑在每个请求上，预算是「正常请求几乎为零」。
 *
 * 执行顺序（成本从低到高）：
 *   1. 路径排除清单              O(排除条数) 的 strncmp
 *   2. HTTP 方法白名单           一次数组查
 *   3. 敏感路径字面量            strpos 循环，只扫 PATH
 *   4. 触发字符预筛              strpbrk 一次扫描 —— 正常请求到此结束
 *   5. 分块正则                  只有第 4 步命中才跑
 *   6. 计分汇总                  超过阈值才升级为拦截
 */
final class Engine
{
    /** 检测面 => 是否默认开启（对应 waf_scan_* 配置） */
    private const SURFACE_SWITCH = [
        Surface::PATH => 'path',
        Surface::QUERY => 'query',
        Surface::BODY => 'body',
        Surface::COOKIE => 'cookie',
        Surface::HEADER => 'header',
        Surface::FILES => 'files',
    ];

    /**
     * @param array<string,mixed> $rules rules.php 的全部内容
     */
    public static function inspect(Decision $decision, array $rules): void
    {
        try {
            $thresholds = (array)($rules['thresholds'] ?? []);
            $meta = (array)($rules['meta'] ?? []);
            $route = $decision->route;

            // ── 1. 路径排除（站长手工加的白名单路径）
            foreach ((array)($rules['exclude_paths'] ?? []) as $prefix) {
                if ($prefix !== '' && str_starts_with($route, (string)$prefix)) {
                    return;
                }
            }

            $isAdminRoute = Surface::isAdminRoute();
            //管理员会话下整体跳过 BODY 面：后台天天在提交含 <script>、eval( 的模板与公告，
            //这是全站最大的误报来源。有 cookie 才值得去验会话（两次查询，慢路径才付）。
            $adminSession = $isAdminRoute
                && ($thresholds['trust_admin'] ?? true)
                && AdminGuard::hasAdminCookie()
                && AdminGuard::isLoggedInAdmin();

            // ── 2. HTTP 方法白名单
            $allowedMethods = (array)($thresholds['method_allow'] ?? []);
            if ($allowedMethods !== [] && !in_array($decision->method, $allowedMethods, true)) {
                $decision->hit('method.disallowed', Kind::ATK_METHOD, Decision::BLOCK, 'warn', 6);
                $decision->status = 405;
                $decision->publicReason = lang('不支持的请求方式');
                return;
            }

            // ── 3. Host 校验（默认关，多域名站点容易误伤）
            if (($thresholds['host_guard'] ?? false) && ($hosts = (array)($thresholds['host_allow'] ?? [])) !== []) {
                $host = Surface::host();
                if ($host !== '' && !in_array($host, $hosts, true)) {
                    $decision->hit('host.mismatch', Kind::ATK_PROTOCOL, Decision::BLOCK, 'warn', 6);
                    $decision->publicReason = lang('请求的域名不被本站接受');
                    return;
                }
            }

            // ── 4. 恶意工具 UA（零误报，值得单独早判）
            if ($thresholds['ua_block_tools'] ?? true) {
                self::scanSurface($decision, $rules, $meta, Surface::HEADER, $thresholds, $adminSession);
                if ($decision->blocking()) {
                    return;
                }
            }

            // ── 5. 敏感路径字面量（只扫 PATH，strpos 比正则快得多）
            $scanSurfaces = (array)($thresholds['scan_surfaces'] ?? []);
            if (($scanSurfaces['path'] ?? true)) {
                $needle = self::matchNeedle(Surface::decodedPath(), (array)($rules['scan_needles'] ?? []));
                if ($needle !== null) {
                    $ruleMeta = (array)($meta['scan.sensitive'] ?? []);
                    if (($ruleMeta['enabled'] ?? true)) {
                        $decision->hit(
                            'scan.sensitive',
                            Kind::ATK_SENSITIVE,
                            RuleSet::actionOf((string)($ruleMeta['action'] ?? 'score')),
                            (string)($ruleMeta['level'] ?? 'warn'),
                            (int)($ruleMeta['score'] ?? 8)
                        );
                        $decision->evidence['needle'] = $needle;
                        Scanner::onSensitive($decision, $needle);
                    }
                }
            }

            // ── 6. 逐面扫描
            foreach (self::SURFACE_SWITCH as $surface => $switch) {
                if ($surface === Surface::HEADER) {
                    continue; //上面已经扫过
                }
                if (!($scanSurfaces[$switch] ?? true)) {
                    continue;
                }
                if ($surface === Surface::BODY && $adminSession) {
                    continue;
                }
                self::scanSurface($decision, $rules, $meta, $surface, $thresholds, $adminSession);
                if ($decision->blocking()) {
                    return;
                }
            }

            // ── 7. 计分汇总：单条不够格，累计超阈值就升级为拦截
            $limit = (int)($thresholds['waf_score_threshold'] ?? 10);
            if ($decision->action === Decision::SCORE && $decision->score >= $limit) {
                $decision->escalate(Decision::BLOCK, Lang::t('累计风险分 :s 已达阈值 :t', [
                    's' => (string)$decision->score,
                    't' => (string)$limit,
                ]), 'score');
                $decision->ruleKind = $decision->ruleKind ?: Kind::ATK_WAF;
            }
        } catch (\Throwable $e) {
            //引擎自身出错一律 fail-open：漏防一次远好过把站点带崩
            Log::exception('Engine::inspect', $e);
        }
    }

    /**
     * 扫描一个检测面
     *
     * @param array<string,mixed> $rules
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $thresholds
     */
    private static function scanSurface(
        Decision $decision,
        array $rules,
        array $meta,
        string $surface,
        array $thresholds,
        bool $adminSession
    ): void {
        $blocks = (array)(($rules['waf'] ?? [])[$surface] ?? []);
        if ($blocks === []) {
            return;
        }

        $excludeFields = (array)($rules['exclude_fields'] ?? []);
        $bodyMax = max(1024, (int)($thresholds['waf_body_max_kb'] ?? 64) * 1024);
        $subject = Surface::flat($surface, $excludeFields, $bodyMax);
        if ($subject === '') {
            return;
        }

        //触发字符预筛：正常请求一个特殊字符都不含，整段正则直接跳过。
        //
        //三个面**必须**跳过预筛，否则等于给攻击者开后门：
        //  HEADER 恶意工具 UA 全是普通字母（sqlmap、nmap、nikto…），一个触发字符都没有
        //  FILES  文件名同理（shell.php）
        //  PATH   路径穿越 /../../etc/passwd 只有点和斜杠，也不含触发字符
        //         而路径本来就短（≤2KB），无条件跑正则的成本可以忽略
        if (!in_array($surface, [Surface::HEADER, Surface::FILES, Surface::PATH], true)) {
            $trigger = (string)($rules['trigger'] ?? '');
            if ($trigger !== '' && strpbrk($subject, $trigger) === false) {
                return;
            }
        }

        foreach ($blocks as $block) {
            $re = (string)($block['re'] ?? '');
            $hitId = (string)($block['id'] ?? '');
            if ($re === '' || $hitId === '') {
                continue;
            }
            $matched = @preg_match($re, $subject, $m);

            if ($matched === false) {
                //回溯超限或非法 UTF-8。绝不能当成「没命中」放过去 ——
                //那正好是攻击者想要的效果（超长载荷撑爆回溯 = 免费绕过）。
                $error = preg_last_error();
                Log::warn('WAF 正则执行失败', ['surface' => $surface, 'error' => $error, 'len' => strlen($subject)]);
                if (($thresholds['waf_mode'] ?? '') === \App\Plugin\WebsiteMonitor\Core\Settings::WAF_STRICT) {
                    $decision->hit('waf.engine_error', Kind::ATK_PROTOCOL, Decision::BLOCK, 'warn', 6);
                    $decision->evidence['engine_error'] = $error;
                    return;
                }
                continue;
            }
            if ($matched !== 1) {
                continue;
            }

            $ruleMeta = (array)($meta[$hitId] ?? []);
            if (!($ruleMeta['enabled'] ?? true)) {
                continue;
            }
            //管理员会话下，误报率高的规则一律降级为只记录
            $action = RuleSet::actionOf((string)($ruleMeta['action'] ?? 'score'));
            if ($adminSession && ($ruleMeta['fp'] ?? 'low') !== 'low') {
                $action = Decision::LOG;
            }

            $decision->hit(
                $hitId,
                Kind::ATK_WAF,
                $action,
                (string)($ruleMeta['level'] ?? 'warn'),
                (int)($ruleMeta['score'] ?? 5)
            );
            $decision->evidence['surface'] = $surface;
            $decision->evidence['matched'] = Evidence::snippet($subject, (string)($m[0] ?? ''));
            if ((int)($ruleMeta['status'] ?? 0) > 0) {
                $decision->status = (int)$ruleMeta['status'];
            }
            if ($decision->blocking()) {
                return;
            }
        }
    }

    /**
     * @param string[] $needles
     */
    private static function matchNeedle(string $path, array $needles): ?string
    {
        if ($path === '' || $needles === []) {
            return null;
        }
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($path, (string)$needle)) {
                return (string)$needle;
            }
        }
        return null;
    }
}
