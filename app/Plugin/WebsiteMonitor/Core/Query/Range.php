<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Query;

/**
 * 面板的时间区间解析。所有查询都必须带时间窗 —— 这是「永远不会有慢查询」的前提。
 */
final class Range
{
    public int $from;
    public int $to;
    public int $fromDay;
    public int $toDay;
    public string $grain;
    public string $key;
    public int $days;

    /**
     * @param string $key today | yesterday | 7d | 30d | month | custom
     */
    public static function parse(string $key, string $from = '', string $to = ''): self
    {
        $r = new self();
        $now = time();
        $r->key = $key;

        switch ($key) {
            case 'yesterday':
                $r->from = (int)strtotime('yesterday 00:00:00');
                $r->to = (int)strtotime('yesterday 23:59:59');
                break;
            case '7d':
                $r->from = (int)strtotime('-6 days 00:00:00');
                $r->to = $now;
                break;
            case '30d':
                $r->from = (int)strtotime('-29 days 00:00:00');
                $r->to = $now;
                break;
            case 'month':
                $r->from = (int)strtotime('first day of this month 00:00:00');
                $r->to = $now;
                break;
            case 'custom':
                $r->from = self::stamp($from, (int)strtotime('-6 days 00:00:00'));
                $r->to = self::stamp($to, $now, true);
                break;
            case 'today':
            default:
                $r->key = 'today';
                $r->from = (int)strtotime('today 00:00:00');
                $r->to = $now;
                break;
        }

        if ($r->to < $r->from) {
            [$r->from, $r->to] = [$r->to, $r->from];
        }
        //最长 400 天，防止误填把库拖垮
        if ($r->to - $r->from > 400 * 86400) {
            $r->from = $r->to - 400 * 86400;
        }

        $r->fromDay = (int)date('Ymd', $r->from);
        $r->toDay = (int)date('Ymd', $r->to);
        $r->days = max(1, (int)floor(($r->to - $r->from) / 86400) + 1);

        //粒度自适应：区间越长，桶越粗，保证返回的点数始终在几十到几百之间
        $span = $r->to - $r->from;
        $r->grain = $span <= 7200 ? 'min' : ($span <= 3 * 86400 ? 'hour' : 'day');

        return $r;
    }

    /**
     * 上一个等长周期（用于同比）
     */
    public function previous(): self
    {
        $prev = new self();
        $span = $this->to - $this->from;
        $prev->from = $this->from - $span - 1;
        $prev->to = $this->from - 1;
        $prev->fromDay = (int)date('Ymd', $prev->from);
        $prev->toDay = (int)date('Ymd', $prev->to);
        $prev->grain = $this->grain;
        $prev->key = $this->key . ':prev';
        $prev->days = $this->days;
        return $prev;
    }

    /** 是否可以直接读天表（跨天且不含今天的未完成部分时最省） */
    public function useDayTable(): bool
    {
        return $this->grain === 'day';
    }

    public function label(): string
    {
        return match ($this->key) {
            'yesterday' => lang('昨日'),
            '7d' => lang('近 7 天'),
            '30d' => lang('近 30 天'),
            'month' => lang('本月'),
            'custom' => date('m-d', $this->from) . ' ~ ' . date('m-d', $this->to),
            default => lang('今日'),
        };
    }

    private static function stamp(string $value, int $default, bool $endOfDay = false): int
    {
        $value = trim($value);
        if ($value === '') {
            return $default;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return $default;
        }
        //只给了日期没给时分，结束时间要取到当天最后一秒
        if ($endOfDay && strlen($value) <= 10) {
            $ts = (int)strtotime(date('Y-m-d', $ts) . ' 23:59:59');
        }
        return (int)$ts;
    }
}
