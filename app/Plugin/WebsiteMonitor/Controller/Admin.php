<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Controller;

use App\Controller\Base\API\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Plugin\WebsiteMonitor\Api\Guard;
use App\Plugin\WebsiteMonitor\Consts\Dim;
use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Acl\AdminGuard;
use App\Plugin\WebsiteMonitor\Core\Acl\Ban;
use App\Plugin\WebsiteMonitor\Core\Acl\Compiler;
use App\Plugin\WebsiteMonitor\Core\Acl\Store;
use App\Plugin\WebsiteMonitor\Core\Cc\Limiter;
use App\Plugin\WebsiteMonitor\Core\Collect\SpiderRepo;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Geo\Downloader;
use App\Plugin\WebsiteMonitor\Core\Geo\Locator;
use App\Plugin\WebsiteMonitor\Core\Ingest\Attacks;
use App\Plugin\WebsiteMonitor\Core\Ingest\Batch;
use App\Plugin\WebsiteMonitor\Core\Ip;
use App\Plugin\WebsiteMonitor\Core\Kv;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Query\Dimensions;
use App\Plugin\WebsiteMonitor\Core\Query\Overview;
use App\Plugin\WebsiteMonitor\Core\Query\Range;
use App\Plugin\WebsiteMonitor\Core\Query\Realtime;
use App\Plugin\WebsiteMonitor\Core\Query\Trend;
use App\Plugin\WebsiteMonitor\Core\Query\Visitor;
use App\Plugin\WebsiteMonitor\Core\Rollup\Daily;
use App\Plugin\WebsiteMonitor\Core\Rollup\Prune;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Schema;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\Spool\Writer;
use App\Plugin\WebsiteMonitor\Core\State;
use App\Plugin\WebsiteMonitor\Core\Waf\RuleSet;
use App\Plugin\WebsiteMonitor\Module\Notify\Bridge;
use App\Util\Client;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

/**
 * 面板后台接口。路由：/plugin/WebsiteMonitor/admin/<方法名>
 *
 * 传参注意：内核在 new Request() 时已经把 $_POST 过了一遍 HTMLPurifier +
 * htmlspecialchars(strip_tags())，JSON 字符串传进来会变成 &quot; 一堆。
 * 所以这里的接口**只收扁平标量与表单数组**（list[]=1、rule[0][x]=y）；
 * 确实要传原文的地方用 unsafePost() 取。
 */
#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Admin extends ManagePlugin
{
    /* ═══════════════════════════ 运行状态 ═══════════════════════════ */

    public function runtime(): array
    {
        $tm = Runtime::threadManager();
        $backlog = Writer::backlog();
        $state = State::get();

        return $this->json(data: [
            'enabled' => Settings::enabled(),
            'schema_ready' => Schema::ready(),
            'schema_version' => Kv::int('schema_version', 0),
            'waf_mode' => Settings::wafMode(),
            'enforcing' => Settings::enforcing(),
            'emergency_off' => Settings::bool('emergency_off'),
            'disabled_file' => Runtime::disabled(),
            'rescue_path' => AdminGuard::rescueHint(),
            'collect_mode' => State::collectMode(),
            'daemon' => [
                'installed' => $tm['installed'],
                'enabled' => $tm['enabled'],
                'running' => $tm['running'],
                'task_alive' => $tm['task_alive'],
                'hint' => Runtime::installHint(),
            ],
            'spool' => [
                'files' => $backlog['files'],
                'bytes' => $backlog['bytes'],
                'oldest' => $backlog['oldest'],
                'bad' => Prune::badSpoolCount(),
            ],
            'geo' => Locator::info(),
            'geo_progress' => Downloader::progressState(),
            'notify' => Bridge::status(),
            'redis' => Runtime::redisAvailable(),
            'rules_version' => (int)(State::rules()['v'] ?? 0),
            'rules_stale' => Compiler::isStale(),
            'bans' => Ban::activeCount(),
            'rule_counts' => Store::counts(),
            'client_mode' => $this->clientModeCheck(),
            'built_at' => (int)($state['built_at'] ?? 0),
            'storage' => Schema::storage(),
            'version' => Guard::version(),
        ]);
    }

    /* ═══════════════════════════ 统计 ═══════════════════════════ */

    public function overview(): array
    {
        return $this->json(data: Overview::build($this->range()));
    }

    public function realtime(): array
    {
        $after = (int)$this->request->post('after');
        $filter = (string)$this->request->post('filter');
        $data = Realtime::build($after, $filter !== '' ? $filter : 'all');
        $data['visitors'] = Realtime::visitors(24);
        return $this->json(data: $data);
    }

    public function trend(): array
    {
        $compare = (string)$this->request->post('compare') !== '0';
        return $this->json(data: Trend::build($this->range(), $compare));
    }

    public function sources(): array
    {
        return $this->json(data: Dimensions::sources($this->range(), $this->limit(20)));
    }

    public function pages(): array
    {
        return $this->json(data: Dimensions::pages($this->range(), $this->limit(30)));
    }

    public function environment(): array
    {
        return $this->json(data: Dimensions::environment($this->range()));
    }

    public function regions(): array
    {
        return $this->json(data: Dimensions::regions($this->range(), $this->limit(60)));
    }

    public function spiders(): array
    {
        return $this->json(data: Dimensions::spiders($this->range(), $this->limit(30)));
    }

    public function visitors(): array
    {
        $filter = [
            'spider' => (string)$this->request->post('spider'),
            'is_new' => (string)$this->request->post('is_new'),
            'dev' => (string)$this->request->post('dev'),
            'ref_type' => (string)$this->request->post('ref_type'),
            'member' => (string)$this->request->post('member'),
            'ip' => (string)$this->request->post('ip'),
            'vid' => (string)$this->request->post('vid'),
            'min_pv' => (string)$this->request->post('min_pv'),
        ];
        return $this->json(data: Visitor::sessions($this->range(), $filter, $this->page(), $this->limit(20)));
    }

    public function trail(): array
    {
        $sid = (string)$this->request->post('sid');
        if ($sid === '') {
            throw new JSONException(lang('缺少会话标识'));
        }
        return $this->json(data: ['trail' => Visitor::trail($sid)]);
    }

    public function visitorProfile(): array
    {
        $vid = (string)$this->request->post('vid');
        if ($vid === '') {
            throw new JSONException(lang('缺少访客标识'));
        }
        return $this->json(data: Visitor::profile($vid));
    }

    public function visitorTag(): array
    {
        $vid = (string)$this->request->post('vid');
        $tag = (int)$this->request->post('tag');
        $note = (string)$this->request->post('note');
        if ($vid === '') {
            throw new JSONException(lang('缺少访客标识'));
        }
        Visitor::tag($vid, $tag, $note);
        return $this->json(200, lang('已保存'));
    }

    /* ═══════════════════════════ 安全 ═══════════════════════════ */

    public function attacks(): array
    {
        $range = $this->range();
        $filter = [
            'kind' => (string)$this->request->post('kind'),
            'level' => (string)$this->request->post('level'),
            'blocked' => (string)$this->request->post('blocked'),
            'ip' => (string)$this->request->post('ip'),
            'rule' => (string)$this->request->post('rule'),
            'req_id' => (string)$this->request->post('req_id'),
            'keyword' => (string)$this->request->post('keyword'),
            'from' => $range->from,
            'to' => $range->to,
        ];
        $result = Attacks::paginate($filter, $this->page(), $this->limit(20));
        $result['summary'] = [
            'kinds' => Attacks::byKind($range->from, $range->to),
            'top' => Attacks::topSources($range->from, $range->to, 15),
            'bans' => Ban::activeCount(),
        ];
        return $this->json(data: $result);
    }

    public function rules(): array
    {
        return $this->json(data: [
            'catalog' => RuleSet::catalog(),
            'overrides' => RuleSet::overrides(),
            'mode' => Settings::wafMode(),
        ]);
    }

    /**
     * 规则开关与动作调整。用扁平表单数组传：
     *   rule[id]=sqli.union&rule[enabled]=1&rule[action]=block&rule[level]=critical
     */
    public function ruleSave(): array
    {
        $rule = $this->request->post('rule');
        if (!is_array($rule) || trim((string)($rule['id'] ?? '')) === '') {
            throw new JSONException(lang('缺少规则标识'));
        }
        $id = (string)$rule['id'];

        $overrides = RuleSet::overrides();
        $entry = (array)($overrides[$id] ?? []);
        if (isset($rule['enabled'])) {
            $entry['enabled'] = (string)$rule['enabled'] === '1';
        }
        foreach (['action', 'level'] as $key) {
            if (isset($rule[$key]) && trim((string)$rule[$key]) !== '') {
                $entry[$key] = (string)$rule[$key];
            }
        }
        foreach (['score', 'threshold', 'window'] as $key) {
            if (isset($rule[$key]) && trim((string)$rule[$key]) !== '') {
                $entry[$key] = (int)$rule[$key];
            }
        }
        $overrides[$id] = $entry;

        Settings::put('waf_rules', (string)json_encode($overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Compiler::markStale();
        Compiler::rebuildIfStale();
        return $this->json(200, lang('已保存，规则立即生效'));
    }

    public function setMode(): array
    {
        $mode = (string)$this->request->post('mode');
        if (!in_array($mode, [Settings::WAF_OBSERVE, Settings::WAF_PROTECT, Settings::WAF_STRICT], true)) {
            throw new JSONException(lang('模式取值不正确'));
        }

        //切到拦截模式前先做一次自检：确认站长自己不会被现有规则挡在门外
        if ($mode !== Settings::WAF_OBSERVE) {
            $ip = Client::getAddress();
            $problem = AdminGuard::selfCheck($ip, State::rules());
            if ($problem !== null && (string)$this->request->post('confirm') !== '1') {
                throw new JSONException(
                    $problem . '。' . lang('继续开启会把你自己挡在门外，请先处理，或勾选「我已了解风险」。')
                );
            }
            //无论如何都把当前 IP 记进滚动白名单，多一道保险
            AdminGuard::rememberAdminIp($ip);
        }

        Settings::put('waf_mode', $mode);
        Compiler::markStale();
        Compiler::rebuildIfStale();
        Log::warn('防火墙模式已切换', ['mode' => $mode]);
        return $this->json(200, Lang::t('已切换为：:m', ['m' => $this->modeText($mode)]));
    }

    /* ═══════════════════════════ 访问控制 ═══════════════════════════ */

    public function aclList(): array
    {
        $filter = [
            'type' => (string)$this->request->post('type'),
            'status' => (string)$this->request->post('status'),
            'source' => (string)$this->request->post('source'),
            'keyword' => (string)$this->request->post('keyword'),
            'expired' => (string)$this->request->post('expired'),
        ];
        return $this->json(data: Store::paginate($filter, $this->page(), $this->limit(20)));
    }

    public function aclSave(): array
    {
        $type = (int)$this->request->post('type');
        $value = trim((string)$this->request->post('value'));
        $note = (string)$this->request->post('note');
        $hours = (int)$this->request->post('hours');

        if ($value === '') {
            throw new JSONException(lang('规则内容不能为空'));
        }
        //防锁死：不许把自己所在的 IP 加进黑名单
        if ($type === Kind::RULE_IP_DENY) {
            $mine = Ip::normalize(Client::getAddress());
            $parsed = Ip::parseRule($value);
            if ($mine !== null && $parsed !== null && Ip::inRange($mine, $parsed['start_hex'], $parsed['end_hex'])) {
                throw new JSONException(Lang::t('这条规则会封禁你当前使用的 IP（:ip），已阻止。', ['ip' => $mine]));
            }
        }

        $result = Store::add($type, $value, $note, $hours > 0 ? time() + $hours * 3600 : 0, 'manual');
        if (!$result['ok']) {
            throw new JSONException($result['msg']);
        }
        Compiler::rebuildIfStale();
        return $this->json(200, lang('已保存'), ['id' => $result['id']]);
    }

    public function aclDelete(): array
    {
        $ids = $this->idList();
        $n = Store::remove($ids);
        Compiler::rebuildIfStale();
        return $this->json(200, Lang::t('已删除 :n 条', ['n' => (string)$n]));
    }

    public function aclStatus(): array
    {
        $n = Store::setStatus($this->idList(), (int)$this->request->post('status'));
        Compiler::rebuildIfStale();
        return $this->json(200, Lang::t('已更新 :n 条', ['n' => (string)$n]));
    }

    public function aclImport(): array
    {
        $type = (int)$this->request->post('type');
        //原文取：导入内容里有换行和斜杠，走净化过的 post() 会被改得面目全非
        $text = (string)$this->request->unsafePost('text');
        if (trim($text) === '') {
            throw new JSONException(lang('导入内容为空'));
        }
        $result = Store::import($type, urldecode($text));
        Compiler::rebuildIfStale();
        return $this->json(200, Lang::t('导入完成：成功 :ok 条，失败 :fail 条', [
            'ok' => (string)$result['ok'], 'fail' => (string)$result['fail'],
        ]), $result);
    }

    public function aclExport(): array
    {
        $type = (int)$this->request->post('type');
        $format = (string)$this->request->post('format') === 'csv' ? 'csv' : 'txt';
        return $this->json(data: ['content' => Store::export($type, $format), 'format' => $format]);
    }

    public function aclTest(): array
    {
        $ip = trim((string)$this->request->post('ip'));
        $normalized = Ip::normalize($ip);
        if ($normalized === null) {
            throw new JSONException(lang('请输入一个合法的 IP 地址'));
        }
        $thresholds = (array)(State::rules()['thresholds'] ?? []);
        return $this->json(data: [
            'ip' => $normalized,
            'geo' => Guard::lookup($normalized),
            'allowed' => Guard::isAllowed($normalized),
            'banned' => Guard::isBanned($normalized),
            'rule' => Store::findMatching($normalized),
            'admin_ip' => AdminGuard::isRememberedAdminIp($normalized),
            'cc' => Limiter::peek($normalized, $thresholds),
            'profile' => Guard::profile($normalized),
        ]);
    }

    /* ═══════════════════════════ 封禁 ═══════════════════════════ */

    public function bans(): array
    {
        return $this->json(data: Ban::paginate((string)$this->request->post('keyword'), $this->page(), $this->limit(20)));
    }

    /**
     * 封禁 IP。通知中心的「封锁 IP」按钮也打到这个接口。
     */
    public function banIp(): array
    {
        $ip = trim((string)$this->request->post('ip'));
        $seconds = (int)$this->request->post('seconds');
        $reason = (string)$this->request->post('reason');
        $source = (string)$this->request->post('source');

        $normalized = Ip::normalize($ip);
        if ($normalized === null) {
            throw new JSONException(lang('请输入一个合法的 IP 地址'));
        }
        $mine = Ip::normalize(Client::getAddress());
        if ($mine !== null && $mine === $normalized) {
            throw new JSONException(lang('这是你当前使用的 IP，已阻止封禁'));
        }
        if (Guard::isAllowed($normalized)) {
            throw new JSONException(lang('该 IP 在白名单里，请先移出白名单再封禁'));
        }

        $ok = Ban::add(
            $normalized,
            max(0, $seconds),
            $reason !== '' ? $reason : lang('后台手工封禁'),
            'manual',
            $source === 'nc' ? 3 : 0
        );
        if (!$ok) {
            throw new JSONException(lang('封禁失败'));
        }
        return $this->json(200, $seconds > 0
            ? Lang::t('已封禁 :ip（:d）', ['ip' => $normalized, 'd' => $this->durationText($seconds)])
            : Lang::t('已永久封禁 :ip', ['ip' => $normalized]));
    }

    public function unbanIp(): array
    {
        $ips = $this->request->post('list');
        $ips = is_array($ips) ? $ips : [(string)$this->request->post('ip')];
        $n = 0;
        foreach ($ips as $ip) {
            $normalized = Ip::normalize((string)$ip);
            if ($normalized !== null && Ban::release($normalized, 'manual')) {
                Limiter::forget($normalized);
                $n++;
            }
        }
        Compiler::rebuildIfStale();
        return $this->json(200, Lang::t('已解封 :n 个 IP', ['n' => (string)$n]));
    }

    public function banClear(): array
    {
        $n = Ban::clearAll();
        Compiler::rebuildIfStale();
        return $this->json(200, Lang::t('已清空 :n 条自动封禁', ['n' => (string)$n]));
    }

    /* ═══════════════════════════ 蜘蛛 ═══════════════════════════ */

    public function spiderRules(): array
    {
        return $this->json(data: ['list' => SpiderRepo::all()]);
    }

    public function spiderSave(): array
    {
        $spider = $this->request->post('spider');
        if (!is_array($spider)) {
            throw new JSONException(lang('参数不正确'));
        }
        $id = (int)($spider['id'] ?? 0);
        $row = [
            'code' => mb_substr(trim((string)($spider['code'] ?? '')), 0, 32),
            'name' => mb_substr(trim((string)($spider['name'] ?? '')), 0, 48),
            'pattern' => mb_strtolower(mb_substr(trim((string)($spider['pattern'] ?? '')), 0, 200)),
            'verify_domain' => mb_substr(trim((string)($spider['verify_domain'] ?? '')), 0, 255),
            'grp' => (int)($spider['grp'] ?? Kind::SP_OTHER),
            'status' => (string)($spider['status'] ?? '1') === '1' ? 1 : 0,
        ];
        if ($row['code'] === '' || $row['pattern'] === '') {
            throw new JSONException(lang('标识与匹配关键词都不能为空'));
        }
        try {
            if ($id > 0) {
                Db::table(Db::SPIDER)->where('id', $id)->update($row);
            } else {
                $row['builtin'] = 0;
                $row['sort'] = 500;
                $row['icon'] = '';
                Db::table(Db::SPIDER)->insert($row);
            }
        } catch (\Throwable $e) {
            throw new JSONException(Lang::t('保存失败：:e', ['e' => $e->getMessage()]));
        }
        SpiderRepo::compile();
        return $this->json(200, lang('已保存，签名立即生效'));
    }

    public function spiderDelete(): array
    {
        $ids = $this->idList();
        if ($ids === []) {
            throw new JSONException(lang('请选择要删除的签名'));
        }
        //内置签名只允许停用，不允许删除 —— 删了升级时又会被种回来，徒增困惑
        $n = (int)Db::table(Db::SPIDER)->whereIn('id', $ids)->where('builtin', 0)->delete();
        Db::table(Db::SPIDER)->whereIn('id', $ids)->where('builtin', 1)->update(['status' => 0]);
        SpiderRepo::compile();
        return $this->json(200, Lang::t('已删除 :n 条自定义签名，内置签名已停用', ['n' => (string)$n]));
    }

    /* ═══════════════════════════ 地理库 ═══════════════════════════ */

    public function geoStatus(): array
    {
        return $this->json(data: [
            'info' => Locator::info(),
            'progress' => Downloader::progressState(),
            'meta' => Kv::get('geo_meta', []),
        ]);
    }

    public function geoUpdate(): array
    {
        $tm = Runtime::threadManager();
        //有守护进程就交给它：60 MB 的下载不该占着一个 php-fpm 进程
        if ($tm['running'] && $tm['task_alive']) {
            Downloader::requestManual();
            return $this->json(200, lang('已通知后台任务开始更新，请稍候刷新查看进度'));
        }

        //没有守护进程只能同步下，把超时放开
        @set_time_limit(0);
        @ignore_user_abort(true);
        $result = Downloader::update(null, true);
        if (!$result['ok']) {
            throw new JSONException($result['msg']);
        }
        return $this->json(200, $result['msg']);
    }

    public function geoLookup(): array
    {
        $ip = trim((string)$this->request->post('ip'));
        if ($ip === '') {
            throw new JSONException(lang('请输入要查询的 IP'));
        }
        return $this->json(data: Guard::lookup($ip));
    }

    /* ═══════════════════════════ 运维 ═══════════════════════════ */

    public function maintenance(): array
    {
        $op = (string)$this->request->post('op');
        switch ($op) {
            case 'ingest':
                $batch = new Batch();
                $result = $batch->drain(microtime(true) + 5.0, 20000);
                return $this->json(200, Lang::t('已入库 :lines 行（:files 个文件）', [
                    'lines' => (string)$result['lines'], 'files' => (string)$result['files'],
                ]), $result);

            case 'rollup':
                $range = $this->range();
                $result = Daily::rebuildRange($range->fromDay, $range->toDay);
                return $this->json(200, Lang::t('已重建 :days 天的汇总（耗时 :ms ms）', [
                    'days' => (string)$result['days'], 'ms' => (string)$result['ms'],
                ]), $result);

            case 'compile':
                Compiler::markStale();
                Compiler::rebuildIfStale();
                SpiderRepo::compile();
                State::rebuild();
                return $this->json(200, lang('规则与签名已重新编译'));

            case 'prune':
                $stats = Prune::all();
                return $this->json(200, lang('清理完成'), $stats);

            case 'partition':
                return $this->json(200, Schema::tryPartition() ? lang('明细表已按天分区') : lang('分区未启用，详见日志'));

            case 'reset':
                if ((string)$this->request->post('confirm') !== 'RESET') {
                    throw new JSONException(lang('请输入 RESET 确认清空'));
                }
                Schema::truncateAll((string)$this->request->post('keep_rules') !== '0');
                return $this->json(200, lang('统计数据已清空'));

            case 'emergency_on':
                @file_put_contents(AdminGuard::rescueHint(), (string)time());
                return $this->json(200, lang('已紧急停用全部防护'));

            case 'emergency_off':
                @unlink(AdminGuard::rescueHint());
                return $this->json(200, lang('已恢复防护'));

            default:
                throw new JSONException(lang('不支持的操作'));
        }
    }

    public function logs(): array
    {
        $lines = max(20, min(1000, (int)$this->request->post('lines') ?: 200));
        $file = Log::path();
        if (!is_file($file)) {
            return $this->json(data: ['log' => '', 'bytes' => 0]);
        }
        $content = (string)@file_get_contents($file);
        $all = explode("\n", $content);
        return $this->json(data: [
            'log' => implode("\n", array_slice($all, -$lines)),
            'bytes' => strlen($content),
        ]);
    }

    public function logClear(): array
    {
        @file_put_contents(Log::path(), '');
        return $this->json(200, lang('日志已清空'));
    }

    /* ═══════════════════════════ 内部 ═══════════════════════════ */

    private function range(): Range
    {
        return Range::parse(
            (string)$this->request->post('range'),
            (string)$this->request->post('from'),
            (string)$this->request->post('to')
        );
    }

    private function page(): int
    {
        return max(1, (int)$this->request->post('page'));
    }

    private function limit(int $default): int
    {
        $limit = (int)$this->request->post('limit');
        return $limit > 0 ? min(200, $limit) : $default;
    }

    /**
     * @return int[]
     */
    private function idList(): array
    {
        $list = $this->request->post('list');
        if (!is_array($list)) {
            $single = (int)$this->request->post('id');
            return $single > 0 ? [$single] : [];
        }
        return array_values(array_filter(array_map('intval', $list)));
    }

    /**
     * 客户端 IP 获取方式自检。
     *
     * 这是最容易被忽视的配置陷阱：站点在 CDN 后面时如果没配受信代理，
     * 任何人都能伪造 X-Forwarded-For，封禁功能形同虚设。
     *
     * @return array<string,mixed>
     */
    private function clientModeCheck(): array
    {
        $mode = 0;
        $trusted = '';
        try {
            $mode = Client::getClientMode();
            $file = BASE_PATH . '/runtime/trusted_proxies';
            $trusted = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        } catch (\Throwable $e) {
        }

        $warn = '';
        if ($mode !== 0 && $trusted === '') {
            $warn = lang('当前从代理头取客户端 IP，但没有配置受信代理清单 —— 任何人都能伪造 IP，封禁与限频都会失效。请到「系统设置」补上 CDN 回源段。');
        }
        return [
            'mode' => $mode,
            'trusted_configured' => $trusted !== '',
            'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'resolved' => Client::getAddress(),
            'warn' => $warn,
        ];
    }

    private function modeText(string $mode): string
    {
        return match ($mode) {
            Settings::WAF_PROTECT => lang('拦截模式'),
            Settings::WAF_STRICT => lang('严格模式'),
            default => lang('观察模式'),
        };
    }

    private function durationText(int $seconds): string
    {
        if ($seconds >= 86400) {
            return Lang::t(':n 天', ['n' => (string)round($seconds / 86400, 1)]);
        }
        if ($seconds >= 3600) {
            return Lang::t(':n 小时', ['n' => (string)round($seconds / 3600, 1)]);
        }
        return Lang::t(':n 分钟', ['n' => (string)max(1, (int)round($seconds / 60))]);
    }
}
