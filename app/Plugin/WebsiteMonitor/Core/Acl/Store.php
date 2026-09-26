<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Acl;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Ip;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Settings;

/**
 * wm_rule 的增删改查、导入导出。
 *
 * 这里是**慢路径**（后台操作），可以放心做完整的解析与校验；
 * 热路径只认 Compiler 编译出来的哈希表，不碰这张表。
 */
final class Store
{
    /** 单次导入上限，防止一次贴进来几十万行把内存打爆 */
    public const IMPORT_MAX = 20000;

    /** IP 类规则 */
    public const IP_TYPES = [Kind::RULE_IP_DENY, Kind::RULE_IP_ALLOW];

    /** 地区类规则 */
    public const REGION_TYPES = [Kind::RULE_REGION_DENY, Kind::RULE_REGION_ALLOW];

    /**
     * 新增或更新一条规则。
     *
     * @param int $type Kind::RULE_*
     * @param string $value IP / CIDR / 通配 / 区间 / 地区码 / UA 关键词 / 路径前缀
     * @param int $expireAt 0 = 永久
     * @return array{ok:bool,id:int,msg:string}
     */
    public static function add(
        int $type,
        string $value,
        string $note = '',
        int $expireAt = 0,
        string $source = 'manual'
    ): array {
        $value = trim($value);
        if ($value === '') {
            return ['ok' => false, 'id' => 0, 'msg' => lang('规则内容不能为空')];
        }

        $startHex = '';
        $endHex = '';
        $family = 0;
        $norm = $value;

        if (in_array($type, self::IP_TYPES, true)) {
            $parsed = Ip::parseRule($value);
            if ($parsed === null) {
                return ['ok' => false, 'id' => 0, 'msg' => Lang::t('无法识别的 IP 规则：:v', ['v' => $value])];
            }
            $startHex = $parsed['start_hex'];
            $endHex = $parsed['end_hex'];
            $family = $parsed['family'];
            $norm = $parsed['norm'];
        } elseif (in_array($type, self::REGION_TYPES, true)) {
            $norm = Region::normalizeCode($value);
            if ($norm === null) {
                return ['ok' => false, 'id' => 0, 'msg' => Lang::t('无法识别的地区代码：:v（形如 CN、CN.GD、CN.GD.深圳市）', ['v' => $value])];
            }
        } elseif ($type === Kind::RULE_UA_DENY) {
            $norm = mb_strtolower(trim($value));
            if (mb_strlen($norm) < 3) {
                return ['ok' => false, 'id' => 0, 'msg' => lang('UA 关键词太短，至少 3 个字符，否则会大面积误伤')];
            }
        } elseif ($type === Kind::RULE_PATH_EXEMPT) {
            $norm = '/' . ltrim(strtolower(trim($value)), '/');
        } else {
            return ['ok' => false, 'id' => 0, 'msg' => lang('未知的规则类型')];
        }

        try {
            $row = [
                'type' => $type,
                'value' => mb_substr($norm, 0, 120),
                'start_hex' => $startHex,
                'end_hex' => $endHex,
                'family' => $family,
                'note' => mb_substr($note, 0, 120),
                'source' => mb_substr($source, 0, 16),
                'status' => 1,
                'expire_at' => max(0, $expireAt),
                'create_time' => Db::now(),
            ];
            Db::upsertMany(Db::RULE, [$row], [
                'note' => 'VALUES(`note`)',
                'status' => '1',
                'expire_at' => 'VALUES(`expire_at`)',
                'source' => 'VALUES(`source`)',
                'start_hex' => 'VALUES(`start_hex`)',
                'end_hex' => 'VALUES(`end_hex`)',
                'family' => 'VALUES(`family`)',
            ]);
            $id = (int)(Db::table(Db::RULE)->where('type', $type)->where('value', $row['value'])->value('id') ?? 0);
            Compiler::markStale();
            return ['ok' => true, 'id' => $id, 'msg' => lang('已保存')];
        } catch (\Throwable $e) {
            Log::exception('Store::add', $e, ['type' => $type, 'value' => $value]);
            return ['ok' => false, 'id' => 0, 'msg' => Lang::t('保存失败：:e', ['e' => $e->getMessage()])];
        }
    }

    /**
     * @param int[] $ids
     */
    public static function remove(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        try {
            $n = (int)Db::table(Db::RULE)->whereIn('id', $ids)->delete();
            if ($n > 0) {
                Compiler::markStale();
            }
            return $n;
        } catch (\Throwable $e) {
            Log::exception('Store::remove', $e);
            return 0;
        }
    }

    public static function removeByValue(int $type, string $value): int
    {
        try {
            $norm = in_array($type, self::IP_TYPES, true)
                ? (Ip::parseRule($value)['norm'] ?? $value)
                : $value;
            $n = (int)Db::table(Db::RULE)->where('type', $type)->where('value', $norm)->delete();
            if ($n > 0) {
                Compiler::markStale();
            }
            return $n;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function setStatus(array $ids, int $status): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        try {
            $n = (int)Db::table(Db::RULE)->whereIn('id', $ids)->update(['status' => $status ? 1 : 0]);
            if ($n > 0) {
                Compiler::markStale();
            }
            return $n;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 分页列表
     *
     * @param array{type?:int,status?:int,source?:string,keyword?:string,expired?:int} $filter
     * @return array{list:array<int,array<string,mixed>>,total:int}
     */
    public static function paginate(array $filter, int $page = 1, int $limit = 20): array
    {
        try {
            $q = Db::table(Db::RULE);
            if (!empty($filter['type'])) {
                $q->where('type', (int)$filter['type']);
            }
            if (isset($filter['status']) && $filter['status'] !== '') {
                $q->where('status', (int)$filter['status']);
            }
            if (!empty($filter['source'])) {
                $q->where('source', (string)$filter['source']);
            }
            $keyword = trim((string)($filter['keyword'] ?? ''));
            if ($keyword !== '') {
                //输入一个 IP 时，除了模糊搜规则文本，还要找出包含它的所有区间
                $hex = Ip::toHex($keyword);
                $q->where(static function ($sub) use ($keyword, $hex): void {
                    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
                    $sub->where('value', 'like', $like)->orWhere('note', 'like', $like);
                    if ($hex !== null) {
                        $sub->orWhere(static function ($r) use ($hex): void {
                            $r->where('start_hex', '<=', $hex)->where('end_hex', '>=', $hex)->where('start_hex', '!=', '');
                        });
                    }
                });
            }
            if (isset($filter['expired']) && $filter['expired'] !== '') {
                $now = time();
                if ((int)$filter['expired'] === 1) {
                    $q->where('expire_at', '>', 0)->where('expire_at', '<', $now);
                } else {
                    $q->where(static function ($sub) use ($now): void {
                        $sub->where('expire_at', 0)->orWhere('expire_at', '>=', $now);
                    });
                }
            }

            $total = (int)$q->count();
            $rows = $q->orderByDesc('id')->forPage(max(1, $page), max(1, min(200, $limit)))->get();

            $list = [];
            foreach ($rows as $row) {
                $list[] = [
                    'id' => (int)$row->id,
                    'type' => (int)$row->type,
                    'type_text' => self::typeText((int)$row->type),
                    'value' => (string)$row->value,
                    'note' => (string)$row->note,
                    'source' => (string)$row->source,
                    'status' => (int)$row->status,
                    'hits' => (int)$row->hits,
                    'last_hit_at' => (int)$row->last_hit_at,
                    'expire_at' => (int)$row->expire_at,
                    'expired' => (int)$row->expire_at > 0 && (int)$row->expire_at < time(),
                    'create_time' => (string)$row->create_time,
                ];
            }
            return ['list' => $list, 'total' => $total];
        } catch (\Throwable $e) {
            Log::exception('Store::paginate', $e);
            return ['list' => [], 'total' => 0];
        }
    }

    /**
     * 后台的「规则测试器」：这个 IP 命中了哪条规则
     *
     * @return array<string,mixed>|null
     */
    public static function findMatching(string $ip): ?array
    {
        $hex = Ip::toHex($ip);
        if ($hex === null) {
            return null;
        }
        try {
            $row = Db::table(Db::RULE)
                ->where('status', 1)
                ->where('start_hex', '!=', '')
                ->where('start_hex', '<=', $hex)
                ->where('end_hex', '>=', $hex)
                ->where(static function ($q): void {
                    $q->where('expire_at', 0)->orWhere('expire_at', '>=', time());
                })
                //白名单优先：type 2 排在 type 1 前面
                ->orderByRaw('FIELD(`type`, ' . Kind::RULE_IP_ALLOW . ', ' . Kind::RULE_IP_DENY . ')')
                ->first();
            if (!$row) {
                return null;
            }
            return [
                'id' => (int)$row->id,
                'type' => (int)$row->type,
                'type_text' => self::typeText((int)$row->type),
                'value' => (string)$row->value,
                'note' => (string)$row->note,
                'source' => (string)$row->source,
                'expire_at' => (int)$row->expire_at,
            ];
        } catch (\Throwable $e) {
            Log::exception('Store::findMatching', $e);
            return null;
        }
    }

    /**
     * 批量导入。每行 `规则[,备注[,有效天数]]`，# 开头是注释。
     *
     * @return array{ok:int,fail:int,errors:array<int,string>}
     */
    public static function import(int $type, string $text, string $source = 'import'): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $ok = 0;
        $fail = 0;
        $errors = [];

        foreach ($lines as $i => $line) {
            if ($ok + $fail >= self::IMPORT_MAX) {
                $errors[] = Lang::t('超过单次导入上限 :n 行，其余已忽略', ['n' => (string)self::IMPORT_MAX]);
                break;
            }
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode(',', $line, 3));
            $days = isset($parts[2]) && ctype_digit($parts[2]) ? (int)$parts[2] : 0;
            $result = self::add(
                $type,
                $parts[0],
                $parts[1] ?? '',
                $days > 0 ? time() + $days * 86400 : 0,
                $source
            );
            if ($result['ok']) {
                $ok++;
            } else {
                $fail++;
                if (count($errors) < 20) {
                    $errors[] = Lang::t('第 :n 行', ['n' => (string)($i + 1)]) . '：' . $result['msg'];
                }
            }
        }
        return ['ok' => $ok, 'fail' => $fail, 'errors' => $errors];
    }

    /**
     * 导出。$format = txt 时只导规则本身（可回灌），csv 带全部字段。
     */
    public static function export(int $type, string $format = 'txt'): string
    {
        try {
            $rows = Db::table(Db::RULE)->where('type', $type)->orderBy('id')->get();
            if ($format === 'csv') {
                $out = "规则,备注,来源,状态,命中次数,最近命中,到期时间,创建时间\n";
                foreach ($rows as $row) {
                    $out .= implode(',', [
                        self::csvCell((string)$row->value),
                        self::csvCell((string)$row->note),
                        self::csvCell((string)$row->source),
                        (int)$row->status === 1 ? '启用' : '停用',
                        (int)$row->hits,
                        (int)$row->last_hit_at > 0 ? date('Y-m-d H:i:s', (int)$row->last_hit_at) : '',
                        (int)$row->expire_at > 0 ? date('Y-m-d H:i:s', (int)$row->expire_at) : '永久',
                        self::csvCell((string)$row->create_time),
                    ]) . "\n";
                }
                return $out;
            }
            $out = '# ' . self::typeText($type) . ' — ' . date('Y-m-d H:i:s') . "\n";
            foreach ($rows as $row) {
                $out .= (string)$row->value;
                if (trim((string)$row->note) !== '') {
                    $out .= ',' . str_replace(',', ' ', (string)$row->note);
                }
                $out .= "\n";
            }
            return $out;
        } catch (\Throwable $e) {
            Log::exception('Store::export', $e);
            return '';
        }
    }

    /**
     * 清理过期规则（SweepTask 调）
     */
    public static function pruneExpired(): int
    {
        try {
            $n = (int)Db::table(Db::RULE)->where('expire_at', '>', 0)->where('expire_at', '<', time())->delete();
            if ($n > 0) {
                Compiler::markStale();
            }
            return $n;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 各类型规则条数（面板概览用）
     *
     * @return array<int,int>
     */
    public static function counts(): array
    {
        $out = [];
        try {
            $rows = Db::table(Db::RULE)->where('status', 1)
                ->selectRaw('`type`, COUNT(*) AS n')->groupBy('type')->get();
            foreach ($rows as $row) {
                $out[(int)$row->type] = (int)$row->n;
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    public static function typeText(int $type): string
    {
        return match ($type) {
            Kind::RULE_IP_DENY => lang('IP 黑名单'),
            Kind::RULE_IP_ALLOW => lang('IP 白名单'),
            Kind::RULE_REGION_DENY => lang('地区黑名单'),
            Kind::RULE_REGION_ALLOW => lang('地区白名单'),
            Kind::RULE_UA_DENY => lang('UA 黑名单'),
            Kind::RULE_PATH_EXEMPT => lang('路径豁免'),
            default => lang('未知'),
        };
    }

    private static function csvCell(string $value): string
    {
        if ($value === '') {
            return '';
        }
        //前导 = + - @ 会被 Excel 当公式执行，前面补个单引号
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")
            || in_array($value[0], ['=', '+', '-', '@'], true)) {
            return '"' . str_replace('"', '""', (in_array($value[0], ['=', '+', '-', '@'], true) ? "'" : '') . $value) . '"';
        }
        return $value;
    }

    /**
     * 让 Settings 可见（Compiler 需要读 always_allow）
     */
    public static function alwaysAllowLines(): array
    {
        return Settings::lines('always_allow');
    }
}
