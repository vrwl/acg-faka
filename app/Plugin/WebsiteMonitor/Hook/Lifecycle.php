<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Hook;

use App\Plugin\WebsiteMonitor\Core\Ip;
use App\Plugin\WebsiteMonitor\Core\Kv;
use App\Plugin\WebsiteMonitor\Core\Lang;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Schema;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;
use App\Util\Client;
use Kernel\Annotation\Plugin;
use Kernel\Exception\JSONException;

/**
 * 插件生命周期：建表、写运行时目录、种蜘蛛签名、编译规则、校验配置。
 * INSTALL / START / UPGRADE 都会跑一遍 bootstrap（全部幂等）。
 */
class Lifecycle
{
    #[Plugin(state: Plugin::INSTALL)]
    public function install(): void
    {
        $this->bootstrap('install');
    }

    #[Plugin(state: Plugin::START)]
    public function start(): void
    {
        $this->bootstrap('start');
    }

    #[Plugin(state: Plugin::UPGRADE)]
    public function upgrade(): void
    {
        $this->bootstrap('upgrade');
    }

    #[Plugin(state: Plugin::STOP)]
    public function stop(): void
    {
        try {
            //停用后残留的编译产物会让下次启用读到旧规则，这里直接清掉
            @unlink(State::file());
            @unlink(State::rulesFile());
            Log::info('插件已停用');
        } catch (\Throwable $e) {
        }
    }

    /**
     * 保存配置：不合法直接拒绝（抛 JSONException 会中止保存并把消息显示给站长）
     *
     * @param string $pluginName
     * @param array<string,mixed> $map
     * @throws JSONException
     */
    #[Plugin(state: Plugin::SAVE_CONFIG)]
    public function saveConfig(string $pluginName = '', array $map = []): void
    {
        if ($pluginName !== '' && $pluginName !== Settings::PLUGIN) {
            return;
        }

        $this->validateEnum($map, 'waf_mode', [Settings::WAF_OBSERVE, Settings::WAF_PROTECT, Settings::WAF_STRICT], '防火墙模式');
        $this->validateEnum($map, 'region_mode', ['off', 'blacklist', 'whitelist'], '地区规则模式');
        $this->validateEnum($map, 'region_scope', ['country', 'province', 'city'], '地区规则粒度');
        $this->validateEnum($map, 'cc_driver', ['auto', 'redis', 'file'], '计数器驱动');
        $this->validateEnum($map, 'notify_min_level', [Settings::LEVEL_INFO, Settings::LEVEL_WARN, Settings::LEVEL_CRITICAL], '通知级别门槛');
        $this->validateEnum($map, 'log_level', ['debug', 'info', 'warn', 'error'], '日志级别');

        //HTTP 方法白名单
        if (isset($map['method_allow']) && trim((string)$map['method_allow']) !== '') {
            $allowed = ['GET', 'POST', 'HEAD', 'OPTIONS', 'PUT', 'DELETE', 'PATCH', 'TRACE', 'CONNECT'];
            foreach (preg_split('/[\s,;，；]+/u', strtoupper((string)$map['method_allow'])) ?: [] as $m) {
                if (trim($m) !== '' && !in_array(trim($m), $allowed, true)) {
                    throw new JSONException(lang('HTTP 方法白名单里有无法识别的值') . '：' . $m);
                }
            }
        }

        //永久放行清单：每行必须能解析成 IP / CIDR / 通配 / 区间
        foreach (['always_allow'] as $key) {
            if (!isset($map[$key])) {
                continue;
            }
            foreach ($this->splitLines((string)$map[$key]) as $i => $line) {
                if (Ip::parseRule($line) === null) {
                    throw new JSONException(Lang::t('永久放行清单第 :n 行无法识别', ['n' => $i + 1]) . '：' . $line);
                }
            }
        }

        //封禁梯度
        if (isset($map['ban_ladder']) && trim((string)$map['ban_ladder']) !== '') {
            foreach (preg_split('/[\s,;，；]+/u', (string)$map['ban_ladder']) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '' && (!ctype_digit($part) || (int)$part <= 0)) {
                    throw new JSONException(lang('封禁时长梯度只能填正整数秒，用逗号分隔，例如 300,1800,7200'));
                }
            }
        }

        //IP 库地址必须是 https（这文件会被直接写进服务器，明文 http 有中间人风险）
        if (isset($map['geo_db_url']) && trim((string)$map['geo_db_url']) !== '') {
            $url = trim((string)$map['geo_db_url']);
            if (!preg_match('#^https://[\w.-]+(:\d+)?/\S*$#i', $url)) {
                throw new JSONException(lang('IP 地理库地址必须是 https 开头的完整下载地址'));
            }
        }

        //cron 表达式（5 段）
        if (isset($map['geo_update_cron']) && trim((string)$map['geo_update_cron']) !== '') {
            $fields = preg_split('/\s+/', trim((string)$map['geo_update_cron'])) ?: [];
            if (count($fields) !== 5) {
                throw new JSONException(lang('更新周期必须是 5 段的 cron 表达式，例如 0 4 * * 1（每周一 4 点）'));
            }
        }

        //规则覆盖 JSON
        foreach (['waf_rules'] as $key) {
            if (!isset($map[$key]) || trim((string)$map[$key]) === '') {
                continue;
            }
            $decoded = json_decode(urldecode((string)$map[$key]), true);
            if (!is_array($decoded)) {
                $decoded = json_decode((string)$map[$key], true);
            }
            if (!is_array($decoded)) {
                throw new JSONException(lang('规则配置格式不正确，请刷新页面后重试'));
            }
        }

        //阈值下限：填 0 会让防护形同虚设，这里直接挡掉
        $mins = [
            'cc_burst_limit' => 5, 'cc_burst_window' => 1,
            'cc_sustain_limit' => 10, 'cc_sustain_window' => 10,
            'cc_path_limit' => 3, 'cc_path_window' => 1,
            'cc_global_limit' => 100, 'cc_global_window' => 1,
            'session_timeout' => 60, 'online_window' => 60,
            'raw_retention_days' => 1, 'attack_retention_days' => 1,
            'evidence_max_bytes' => 256, 'waf_body_max_kb' => 1,
        ];
        foreach ($mins as $key => $min) {
            if (isset($map[$key]) && trim((string)$map[$key]) !== '' && (int)$map[$key] < $min) {
                throw new JSONException(Lang::t(':key 不能小于 :min', ['key' => $key, 'min' => (string)$min]));
            }
        }
        if (isset($map['raw_sample']) && trim((string)$map['raw_sample']) !== '') {
            $sample = (int)$map['raw_sample'];
            if ($sample < 1 || $sample > 100) {
                throw new JSONException(lang('明细采样率只能填 1 到 100'));
            }
        }

        $this->guardRegionSelfLock($map);

        //配置变了就让编译产物过期，由后台任务或下一次管理员访问重建
        Settings::refresh();
        try {
            Settings::put('acl_version', (string)(Settings::int('acl_version', 1) + 1));
            State::reset();
            State::rebuild();
        } catch (\Throwable $e) {
            Log::exception('Lifecycle::saveConfig::rebuild', $e);
        }
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    /**
     * 存的就是内置源就抹成空值。
     *
     * 早期版本把内置地址当默认值渲染进配置弹窗，站长一点保存就落库了。留着的话
     * 那个地址会一直显示在输入框里，"不外露内置源"这件事对老站点就等于没做。
     * 抹掉不影响功能：Settings::geoUrl() 遇到空值照样解析成内置源。
     */
    private function hideBuiltinGeoUrl(): void
    {
        try {
            $stored = (string)Settings::get('geo_db_url');
            if ($stored !== '' && Settings::normalizeGeoUrl($stored) === '') {
                Settings::put('geo_db_url', '');
                Settings::refresh();
                Log::info('配置里存的是内置 IP 库地址，已清空（功能不变，只是不再显示出来）');
            }
        } catch (\Throwable $e) {
            Log::exception('Lifecycle::hideBuiltinGeoUrl', $e);
        }
    }

    private function bootstrap(string $reason): void
    {
        try {
            Runtime::ensureDirs();
            Schema::ensure();

            if ($reason === 'install') {
                $this->seedAdminIp();
            }
            $this->seedSpiders();

            Settings::refresh();
            $this->hideBuiltinGeoUrl();
            State::reset();
            State::rebuild();
            $this->compileRules();

            //语言包（Lang/*.json）入库：安装/更新时核心会扫，这里在启用时也扫一遍（有指纹，未变化不重复导入）
            try {
                if (method_exists(\Kernel\Util\Lang::class, 'scanExtensionPacks')) {
                    \Kernel\Util\Lang::scanExtensionPacks();
                }
            } catch (\Throwable $e) {
                Log::exception('Lifecycle::lang', $e);
            }

            Log::info('插件初始化完成', ['reason' => $reason, 'schema' => Schema::VERSION]);
        } catch (\Throwable $e) {
            Log::exception('Lifecycle::' . $reason, $e);
            //建表失败要让站长看到：START 抛异常 = 拒绝启用，好过带着半截表结构跑
            if ($reason !== 'install') {
                throw new JSONException(lang('网站监控统计插件初始化失败') . '：' . $e->getMessage());
            }
        }
    }

    /**
     * 安装时把当前管理员的 IP 写进永久放行清单。
     * 这是防锁死的第三道保险 —— 站长第一次启用防护时不会先把自己关在门外。
     */
    private function seedAdminIp(): void
    {
        try {
            if (trim(Settings::get('always_allow')) !== '') {
                return;
            }
            $ip = Ip::normalize(Client::getAddress());
            if ($ip === null || Ip::isPrivate($ip)) {
                return;
            }
            Settings::put('always_allow', $ip);
            Log::info('已把安装者 IP 写入永久放行清单', ['ip' => $ip]);
        } catch (\Throwable $e) {
            Log::exception('Lifecycle::seedAdminIp', $e);
        }
    }

    /**
     * 内置蜘蛛签名入库。builtin=1 的行按 code 合并更新，站长改过的（builtin=0）不覆盖。
     */
    private function seedSpiders(): void
    {
        try {
            $seedFile = BASE_PATH . '/app/Plugin/' . Settings::PLUGIN . '/Data/spiders.php';
            if (!is_file($seedFile)) {
                return;
            }
            $seeds = require $seedFile;
            if (!is_array($seeds) || $seeds === []) {
                return;
            }
            \App\Plugin\WebsiteMonitor\Core\Collect\SpiderRepo::seed($seeds);
            //入库之后必须再编译成 spiders.php —— 热路径只认编译产物，
            //光把签名写进数据库是不够的，漏了这步全站蜘蛛都识别不出来。
            \App\Plugin\WebsiteMonitor\Core\Collect\SpiderRepo::compile();
        } catch (\Throwable $e) {
            Log::exception('Lifecycle::seedSpiders', $e);
        }
    }

    private function compileRules(): void
    {
        try {
            \App\Plugin\WebsiteMonitor\Core\Acl\Compiler::build();
        } catch (\Throwable $e) {
            Log::exception('Lifecycle::compileRules', $e);
        }
    }

    /**
     * 地区规则自锁检测：会把站长自己封在外面的规则，直接拒绝保存。
     * 只有勾了「我已了解风险」才放行，且强制把当前 IP 写进永久放行清单。
     *
     * @param array<string,mixed> $map
     * @throws JSONException
     */
    private function guardRegionSelfLock(array $map): void
    {
        $mode = (string)($map['region_mode'] ?? Settings::get('region_mode', 'off'));
        if ($mode === 'off') {
            return;
        }
        if (!class_exists('\App\Plugin\WebsiteMonitor\Core\Geo\Locator')) {
            return;
        }
        try {
            if (!\App\Plugin\WebsiteMonitor\Core\Geo\Locator::available()) {
                throw new JSONException(lang('IP 地理库尚未下载，无法启用地区规则。请先到面板点「立即更新 IP 库」。'));
            }
            $ip = Ip::normalize(Client::getAddress());
            if ($ip === null || Ip::isPrivate($ip)) {
                return;
            }
            $confirmed = (string)($map['region_confirm'] ?? Settings::get('region_confirm', '0')) === '1';
            if ($confirmed) {
                //强制自保：把当前 IP 的段加进永久放行
                $current = Settings::get('always_allow');
                if (!str_contains($current, $ip)) {
                    Settings::put('always_allow', trim($current . "\n" . $ip));
                }
                return;
            }
            $verdict = \App\Plugin\WebsiteMonitor\Core\Acl\Region::wouldLockOut($ip, $mode, $map);
            if ($verdict !== null) {
                throw new JSONException(Lang::t('这条地区规则会把你自己挡在门外（你当前位于 :where）。请先把自己的 IP 加入永久放行清单，或勾选「我已了解风险」。', ['where' => $verdict]
                ));
            }
        } catch (JSONException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::exception('Lifecycle::guardRegionSelfLock', $e);
        }
    }

    /**
     * @param array<string,mixed> $map
     * @param string[] $allowed
     * @throws JSONException
     */
    private function validateEnum(array $map, string $key, array $allowed, string $label): void
    {
        if (!isset($map[$key]) || trim((string)$map[$key]) === '') {
            return;
        }
        if (!in_array(trim((string)$map[$key]), $allowed, true)) {
            throw new JSONException($label . lang(' 的取值不正确'));
        }
    }

    /**
     * @return string[]
     */
    private function splitLines(string $raw): array
    {
        $raw = str_replace(['%0A', '%0D', "\r"], ["\n", '', ''], $raw);
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $out[] = $line;
            }
        }
        return $out;
    }
}
