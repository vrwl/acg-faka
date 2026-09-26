<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Module\Notify;

/**
 * 把告警组装成通知中心 security_alert 模板需要的那组变量。
 *
 * 变量名与通知中心出厂模板严格对齐（Template/Default/security_alert.html）：
 *   detector / name / title / summary / level / level_text / scope / scope_text
 *   count / threshold / window / window_text / suppressed / has_suppressed
 *   first_seen / last_seen / evidence_table / evidence_lines / has_evidence / evidence_count
 *   actions_html / actions_lines / has_actions / extra_html
 *
 * 这里自带一份实现而不是调通知中心的 Alerter，是为了让本插件在通知中心缺席时
 * 也能完整加载（Alerter 是它内部类，我们不该依赖它的可见性不变）。
 */
final class AlertVars
{
    /** 证据表最多展示几行，多了邮件会很难看 */
    private const EVIDENCE_MAX = 12;

    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public static function build(array $spec, string $level): array
    {
        $evidence = array_slice((array)($spec['evidence'] ?? []), 0, self::EVIDENCE_MAX);
        $actions = array_values(array_filter(array_map(
            static fn($a): string => trim((string)$a),
            (array)($spec['actions'] ?? [])
        )));
        $window = (int)($spec['window'] ?? 0);
        $suppressed = (int)($spec['suppressed'] ?? 0);
        $now = time();

        return [
            'detector' => mb_substr((string)($spec['event'] ?? 'wm.alert'), 0, 48),
            'name' => (string)($spec['name'] ?? $spec['title'] ?? ''),
            'title' => (string)($spec['title'] ?? ''),
            'summary' => (string)($spec['summary'] ?? ''),
            'level' => $level,
            'level_text' => self::levelText($level),
            'scope' => (string)($spec['scope'] ?? ''),
            'scope_text' => (string)($spec['scope_text'] ?? ''),
            'count' => (int)($spec['count'] ?? 0),
            'threshold' => (int)($spec['threshold'] ?? 0),
            'window' => $window,
            'window_text' => $window > 0 ? $window . ' ' . lang('分钟') : '—',
            'suppressed' => $suppressed,
            'has_suppressed' => $suppressed > 0 ? '1' : '',
            'first_seen' => date('Y-m-d H:i:s', (int)($spec['first_seen'] ?? $now)),
            'last_seen' => date('Y-m-d H:i:s', (int)($spec['last_seen'] ?? $now)),
            'evidence_table' => self::evidenceTable($evidence),
            'evidence_lines' => self::evidenceLines($evidence),
            'has_evidence' => $evidence !== [] ? '1' : '',
            'evidence_count' => count($evidence),
            'actions_html' => self::actionsHtml($actions),
            'actions_lines' => self::actionsLines($actions),
            'has_actions' => $actions !== [] ? '1' : '',
            'extra_html' => self::extraHtml((array)($spec['extra'] ?? [])),
        ];
    }

    public static function levelText(string $level): string
    {
        return match ($level) {
            'critical' => lang('严重'),
            'warn' => lang('警告'),
            default => lang('提示'),
        };
    }

    /**
     * 邮件里的证据表格。所有值都转义过 —— 证据本来就是攻击者控制的内容，
     * 直接拼进 HTML 等于把 XSS 送到站长的邮箱里。
     *
     * @param array<int,array<string,mixed>> $evidence
     */
    public static function evidenceTable(array $evidence): string
    {
        if ($evidence === []) {
            return '';
        }
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';
        foreach ($evidence as $item) {
            $item = (array)$item;
            $cells = [
                (string)($item['time'] ?? ''),
                (string)($item['ip'] ?? ''),
                (string)($item['path'] ?? ''),
                (string)($item['note'] ?? ''),
            ];
            //一行里如果只有 note，就合并成一整格，别留一排空白
            if (trim($cells[0] . $cells[1] . $cells[2]) === '') {
                $rows .= '<tr><td colspan="4" style="padding:6px 8px;border-top:1px solid #eceef2;font-size:13px;">'
                    . $e($cells[3]) . '</td></tr>';
                continue;
            }
            $rows .= '<tr>'
                . '<td style="padding:6px 8px;border-top:1px solid #eceef2;font-size:12px;white-space:nowrap;color:#79839a;">' . $e($cells[0]) . '</td>'
                . '<td style="padding:6px 8px;border-top:1px solid #eceef2;font-size:12px;white-space:nowrap;">' . $e($cells[1]) . '</td>'
                . '<td style="padding:6px 8px;border-top:1px solid #eceef2;font-size:12px;word-break:break-all;">' . $e($cells[2]) . '</td>'
                . '<td style="padding:6px 8px;border-top:1px solid #eceef2;font-size:12px;color:#79839a;">' . $e($cells[3]) . '</td>'
                . '</tr>';
        }
        return '<table style="width:100%;border-collapse:collapse;margin:8px 0;">' . $rows . '</table>';
    }

    /**
     * Telegram 用的纯文本证据
     *
     * @param array<int,array<string,mixed>> $evidence
     */
    public static function evidenceLines(array $evidence): string
    {
        if ($evidence === []) {
            return '';
        }
        $lines = [];
        foreach ($evidence as $item) {
            $item = (array)$item;
            $parts = array_values(array_filter([
                (string)($item['time'] ?? ''),
                (string)($item['ip'] ?? ''),
                (string)($item['path'] ?? ''),
                (string)($item['note'] ?? ''),
            ], static fn(string $s): bool => trim($s) !== ''));
            if ($parts !== []) {
                $lines[] = '• ' . implode('  ', $parts);
            }
        }
        return implode("\n", $lines);
    }

    /**
     * @param string[] $actions
     */
    public static function actionsHtml(array $actions): string
    {
        if ($actions === []) {
            return '';
        }
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $items = '';
        foreach ($actions as $action) {
            $items .= '<li style="margin:4px 0;">' . $e($action) . '</li>';
        }
        return '<ol style="margin:8px 0;padding-left:20px;font-size:13.5px;line-height:1.7;">' . $items . '</ol>';
    }

    /**
     * @param string[] $actions
     */
    public static function actionsLines(array $actions): string
    {
        if ($actions === []) {
            return '';
        }
        $lines = [];
        foreach ($actions as $i => $action) {
            $lines[] = ($i + 1) . '. ' . $action;
        }
        return implode("\n", $lines);
    }

    /**
     * 附加信息表（归属地、规则、处置动作…）
     *
     * @param array<string,mixed> $extra
     */
    public static function extraHtml(array $extra): string
    {
        $extra = array_filter($extra, static fn($v): bool => is_scalar($v) && trim((string)$v) !== '');
        if ($extra === []) {
            return '';
        }
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';
        foreach ($extra as $key => $value) {
            $rows .= '<tr>'
                . '<td style="padding:4px 8px;font-size:12.5px;color:#79839a;white-space:nowrap;">' . $e($key) . '</td>'
                . '<td style="padding:4px 8px;font-size:12.5px;word-break:break-all;">' . $e($value) . '</td>'
                . '</tr>';
        }
        return '<table style="width:100%;border-collapse:collapse;margin:8px 0;">' . $rows . '</table>';
    }
}
