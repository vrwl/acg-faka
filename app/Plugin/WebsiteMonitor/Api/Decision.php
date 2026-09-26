<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Api;

use App\Plugin\WebsiteMonitor\Consts\Kind;

/**
 * 一次请求的判决。也是给其它插件的否决协议载体。
 *
 * 为什么用可变对象而不是靠 hook 返回值：内核的 hook() 派发器遇到 bool 返回会**短路整条链**
 * （见 kernel/Util/Plugin.php），第一个订阅方就把后面的都挡住了，没法做「多方投票」。
 * 所以我们把这个对象按引用传出去，订阅方改对象、返回 null，谁都不会被吞掉。
 *
 *   $d->allow('插件名', '理由');                  软放行，后来者仍可加严
 *   $d->hardAllow('插件名', '理由');              强制放行并锁定
 *   $d->escalate(Decision::BLOCK, '理由');        加严
 */
final class Decision
{
    public const PASS = 0;
    public const LOG = 1;
    public const SCORE = 2;
    public const BLOCK = 3;
    public const BAN = 4;
    public const THROTTLE = 5;

    public string $ip = '';
    public string $route = '';
    public string $ua = '';
    public string $method = 'GET';

    public ?string $ruleId = null;
    public int $ruleKind = 0;
    public string $level = 'info';
    public int $score = 0;
    public int $action = self::PASS;
    public int $status = 403;
    public int $retryAfter = 0;
    public string $publicReason = '';

    /** @var array<string,mixed> 取证片段（已脱敏） */
    public array $evidence = [];

    /** @var array<int,array{by:string,why:string}> 否决与加严的记录，会写进取证日志 */
    public array $vetoes = [];

    /** 观察模式：判决照常算、照常记录，但一律放行 */
    public bool $observeOnly = false;

    private bool $locked = false;
    private string $requestId = '';

    public static function make(string $ip, string $route, string $ua, string $method): self
    {
        $d = new self();
        $d->ip = $ip;
        $d->route = $route;
        $d->ua = $ua;
        $d->method = $method;
        return $d;
    }

    /** 软放行：后来者仍可加严 */
    public function allow(string $by, string $why = ''): void
    {
        if ($this->locked) {
            return;
        }
        $this->action = self::PASS;
        $this->vetoes[] = ['by' => $by, 'why' => $why];
    }

    /** 强制放行并锁定，后续订阅方无法再改 */
    public function hardAllow(string $by, string $why = ''): void
    {
        if ($this->locked) {
            return;
        }
        $this->action = self::PASS;
        $this->vetoes[] = ['by' => $by, 'why' => $why];
        $this->locked = true;
    }

    /** 加严（只升不降） */
    public function escalate(int $action, string $why = '', string $by = 'escalate'): void
    {
        if ($this->locked) {
            return;
        }
        if ($action > $this->action) {
            $this->action = $action;
        }
        if ($why !== '') {
            $this->vetoes[] = ['by' => $by, 'why' => $why];
        }
    }

    public function locked(): bool
    {
        return $this->locked;
    }

    /** 命中一条规则 */
    public function hit(string $ruleId, int $ruleKind, int $action, string $level = 'warn', int $score = 0): void
    {
        if ($this->locked) {
            return;
        }
        $this->ruleId = $ruleId;
        $this->ruleKind = $ruleKind;
        $this->level = self::maxLevel($this->level, $level);
        $this->score += $score;
        if ($action > $this->action) {
            $this->action = $action;
        }
    }

    public function blocking(): bool
    {
        return $this->action >= self::BLOCK;
    }

    /**
     * 给用户看的请求编号。可在后台按此编号搜到完整取证，
     * 这样站长能在不暴露任何规则细节的前提下帮用户排查误伤。
     */
    public function requestId(): string
    {
        if ($this->requestId !== '') {
            return $this->requestId;
        }
        $suffix = '';
        try {
            $suffix = strtoupper(bin2hex(random_bytes(2)));
        } catch (\Throwable $e) {
            $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 4));
        }
        return $this->requestId = 'WM-'
            . strtoupper(base_convert((string)time(), 10, 36)) . '-'
            . strtoupper(substr(md5($this->ip), 0, 4)) . '-'
            . $suffix;
    }

    /**
     * 判决 → 攻击日志的处置动作
     */
    public function actKind(): int
    {
        return match ($this->action) {
            self::BAN => Kind::ACT_BAN,
            self::BLOCK => Kind::ACT_BLOCK,
            self::THROTTLE => Kind::ACT_THROTTLE,
            self::SCORE => Kind::ACT_SCORE,
            default => Kind::ACT_LOG,
        };
    }

    /**
     * @return array<string,mixed> 广播给其它插件的只读快照
     */
    public function toArray(): array
    {
        return [
            'id' => $this->requestId(),
            'ip' => $this->ip,
            'route' => $this->route,
            'ua' => $this->ua,
            'method' => $this->method,
            'rule' => $this->ruleId,
            'kind' => $this->ruleKind,
            'level' => $this->level,
            'score' => $this->score,
            'action' => $this->action,
            'blocked' => $this->blocking() && !$this->observeOnly,
            'observe' => $this->observeOnly,
            'vetoes' => $this->vetoes,
        ];
    }

    private static function maxLevel(string $a, string $b): string
    {
        $rank = ['info' => 0, 'warn' => 1, 'critical' => 2];
        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }
}
