<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

/**
 * 编译态文件的读写。这是整个热路径的性能基础。
 *
 * 三个文件，**故意不合并成一个**：
 *   state.php    模式 / 守护心跳 / 采样率 / 忽略路由 —— 30 秒级变动
 *   rules.php    黑白名单 + WAF 规则的编译产物   —— 天级变动
 *   spiders.php  蜘蛛合并正则                    —— 天级变动
 * 合成一个的话，每 30 秒的心跳刷新都会让整份规则失效 opcache，得不偿失。
 *
 * 读取全部走 require + opcache：命中后是纯内存数组读，零 I/O、零解析。
 * 写入一律先写临时文件再 rename（原子替换，避免 require 到写了一半的文件），
 * 然后 opcache_invalidate。
 */
final class State
{
    public const MODE_FULL = 'full';
    public const MODE_LEAN = 'lean';
    public const MODE_OFF = 'off';

    /** 守护进程心跳的有效期：超过这个时间没刷新，就认为它不在，网页端接力 */
    private const HEARTBEAT_TTL = 30;

    /** spool 单分片体积上限，超过就降级到 lean */
    private const SPOOL_CAP = 33554432;

    private static ?array $state = null;
    private static ?array $rules = null;
    private static ?array $spiders = null;

    public static function file(): string
    {
        return Runtime::dir() . '/state.php';
    }

    public static function rulesFile(): string
    {
        return Runtime::dir() . '/rules.php';
    }

    public static function spidersFile(): string
    {
        return Runtime::dir() . '/spiders.php';
    }

    /**
     * 热路径入口。进程内缓存，一个请求最多 require 一次。
     *
     * @return array<string,mixed>
     */
    public static function get(): array
    {
        if (self::$state !== null) {
            return self::$state;
        }
        $file = self::file();
        if (is_file($file)) {
            try {
                $loaded = require $file;
                if (is_array($loaded) && isset($loaded['v'])) {
                    return self::$state = $loaded;
                }
            } catch (\Throwable $e) {
            }
        }
        //文件缺失（刚装 / 被清过）：给一份安全默认，不阻塞本次请求
        return self::$state = self::defaults();
    }

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'v' => 1,
            'rev' => 0,
            'collect_mode' => self::MODE_FULL,
            'daemon_ok' => false,
            'expires' => 0,
            'sample' => 100,
            'spool_cap' => self::SPOOL_CAP,
            'ignore' => ['/plugin/websitemonitor/'],
            'count_admin' => true,
            'session_timeout' => 1800,
            'online_window' => 300,
            'built_at' => 0,
        ];
    }

    /**
     * 从配置重建 state.php（生命周期钩子、保存配置、守护任务里调）
     */
    public static function rebuild(): array
    {
        Settings::refresh();
        $tm = Runtime::threadManager();

        $ignore = [];
        foreach (Settings::lines('ignore_routes') as $line) {
            $ignore[] = strtolower($line);
        }
        //面板自己的轮询绝不能污染统计，也不能在被攻击时叠加负载
        $ignore[] = '/plugin/websitemonitor/';
        $ignore = array_values(array_unique(array_filter($ignore)));

        $sample = Settings::int('raw_sample', 100);
        $state = [
            'v' => 1,
            'rev' => Settings::int('acl_version', 1),
            //访客 id 的签名密钥：烘焙进编译产物，热路径就不用为它查一次库
            'secret' => self::secret(),
            'collect_mode' => Settings::bool('collect_enabled') ? self::MODE_FULL : self::MODE_OFF,
            'daemon_ok' => $tm['running'] && $tm['task_alive'],
            'expires' => time() + self::HEARTBEAT_TTL,
            'sample' => max(1, min(100, $sample)),
            'spool_cap' => self::SPOOL_CAP,
            'ignore' => $ignore,
            'count_admin' => Settings::bool('count_admin'),
            'session_timeout' => Settings::intMin('session_timeout', 60, 1800),
            'online_window' => Settings::intMin('online_window', 60, 300),
            'built_at' => time(),
        ];
        self::write($state);
        return $state;
    }

    /**
     * 访客 id 的 HMAC 密钥。首次调用时生成并存进 wm_kv，之后一直复用。
     * 换了密钥等于所有老访客变成新访客，所以只在完全没有时才生成。
     */
    public static function secret(): string
    {
        $current = self::$state['secret'] ?? null;
        if (is_string($current) && $current !== '') {
            return $current;
        }
        try {
            $stored = Kv::get('vid_secret');
            if (is_string($stored) && $stored !== '') {
                return $stored;
            }
            $fresh = bin2hex(random_bytes(16));
            Kv::set('vid_secret', $fresh);
            return $fresh;
        } catch (\Throwable $e) {
            //数据库还没就绪时给一个由站点信息派生的稳定值，避免每次请求都变
            return substr(md5(BASE_PATH . '|wm-vid'), 0, 32);
        }
    }

    /**
     * 守护进程心跳：每轮 IngestTask 开头调一次，告诉网页端「我还活着，不用接力」
     */
    public static function heartbeat(): void
    {
        $state = self::get();
        $state['daemon_ok'] = true;
        $state['expires'] = time() + self::HEARTBEAT_TTL;
        self::write($state);
    }

    /**
     * 网页端发现心跳过期时调：重新探测守护进程状态并写回
     */
    public static function refreshDaemonFlag(): array
    {
        $tm = Runtime::threadManager();
        $state = self::get();
        $state['daemon_ok'] = $tm['running'] && $tm['task_alive'];
        $state['expires'] = time() + self::HEARTBEAT_TTL;
        self::write($state);
        return $state;
    }

    /**
     * 守护进程是否在正常消费。网页端据此决定要不要接力。
     */
    public static function daemonAlive(): bool
    {
        $state = self::get();
        return !empty($state['daemon_ok']) && (int)($state['expires'] ?? 0) > time();
    }

    public static function collectMode(): string
    {
        $mode = (string)(self::get()['collect_mode'] ?? self::MODE_FULL);
        return in_array($mode, [self::MODE_FULL, self::MODE_LEAN, self::MODE_OFF], true) ? $mode : self::MODE_FULL;
    }

    /**
     * spool 撑爆时降级：行长从 ~260 字节掉到 ~40 字节，PV / 攻击数 / TOP-IP 依然精确，
     * 只丢页面 / 来源 / UA 明细。写一次文件，2 秒内全站 fpm 进程通过 opcache 感知。
     */
    public static function markFlood(): void
    {
        $state = self::get();
        if (($state['collect_mode'] ?? '') === self::MODE_LEAN) {
            return;
        }
        $state['collect_mode'] = self::MODE_LEAN;
        $state['lean_since'] = time();
        self::write($state);
        Log::warn('spool 积压超过阈值，采集降级为精简模式');
    }

    /**
     * 按实际入库速率自适应升降级（IngestTask 每轮末尾调）
     *
     * @param int $linesPerSec 最近一轮的行速率
     */
    public static function adaptMode(int $linesPerSec): void
    {
        $state = self::get();
        $mode = (string)($state['collect_mode'] ?? self::MODE_FULL);
        if ($mode === self::MODE_OFF) {
            return;
        }
        $limit = max(200, Settings::int('flood_qps', 2000));
        if ($mode === self::MODE_FULL && $linesPerSec > $limit) {
            self::markFlood();
            return;
        }
        //迟滞回差：掉到阈值的 60% 以下才恢复，避免在临界点来回抖
        if ($mode === self::MODE_LEAN && $linesPerSec < $limit * 0.6) {
            $since = (int)($state['lean_since'] ?? 0);
            if ($since > 0 && time() - $since < 60) {
                return;
            }
            $state['collect_mode'] = self::MODE_FULL;
            unset($state['lean_since']);
            self::write($state);
            Log::info('流量回落，采集恢复完整模式');
        }
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function write(array $state): void
    {
        self::$state = $state;
        self::dump(self::file(), $state);
    }

    /* ───────────────────────── 规则与蜘蛛 ───────────────────────── */

    /**
     * 编译好的黑白名单 + WAF 规则。缺失时返回空结构（fail-open，不拦任何人）。
     *
     * @return array<string,mixed>
     */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        $file = self::rulesFile();
        if (is_file($file)) {
            try {
                $loaded = require $file;
                if (is_array($loaded) && isset($loaded['v'])) {
                    return self::$rules = $loaded;
                }
            } catch (\Throwable $e) {
            }
        }
        return self::$rules = self::emptyRules();
    }

    /**
     * @return array<string,mixed>
     */
    public static function emptyRules(): array
    {
        return [
            'v' => 0,
            'built_at' => 0,
            'trigger' => "'\"<>()\\;|`$*%",
            'acl' => [
                'allow' => self::emptyAclSide(),
                'deny' => self::emptyAclSide(),
            ],
            'always_allow' => self::emptyAclSide(),
            'region' => ['mode' => 'off', 'scope' => 'country', 'deny' => [], 'allow' => []],
            'ua_deny' => [],
            'path_exempt' => [],
            'waf' => [],
            'scan_needles' => [],
            'meta' => [],
            'exclude_paths' => [],
            'exclude_fields' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function emptyAclSide(): array
    {
        return ['exact' => [], 'cidr' => [], 'plens' => [], 'range' => []];
    }

    /**
     * @param array<string,mixed> $rules
     */
    public static function writeRules(array $rules): void
    {
        self::$rules = $rules;
        self::dump(self::rulesFile(), $rules);
    }

    /**
     * 蜘蛛合并正则 + 关键词映射
     *
     * @return array<string,mixed>
     */
    public static function spiders(): array
    {
        if (self::$spiders !== null) {
            return self::$spiders;
        }
        $file = self::spidersFile();
        if (is_file($file)) {
            try {
                $loaded = require $file;
                if (is_array($loaded) && isset($loaded['re'])) {
                    return self::$spiders = $loaded;
                }
            } catch (\Throwable $e) {
            }
        }
        return self::$spiders = ['v' => 0, 're' => '', 'map' => [], 'scan' => [], 'verify' => []];
    }

    /**
     * @param array<string,mixed> $spiders
     */
    public static function writeSpiders(array $spiders): void
    {
        self::$spiders = $spiders;
        self::dump(self::spidersFile(), $spiders);
    }

    /**
     * 编译产物是否与当前配置版本一致。
     * 不一致时**不在请求内重建** —— 重建要查库，并发下会被放大成雪崩。
     */
    public static function rulesStale(): bool
    {
        return (int)(self::rules()['v'] ?? 0) !== Settings::int('acl_version', 1);
    }

    /**
     * 丢掉进程内缓存（守护任务每轮、后台保存配置后调）
     */
    public static function reset(): void
    {
        self::$state = null;
        self::$rules = null;
        self::$spiders = null;
    }

    /* ───────────────────────── 内部 ───────────────────────── */

    /**
     * 原子写出一个 `return [...]` 的 PHP 数组文件
     *
     * @param array<string,mixed> $data
     */
    private static function dump(string $file, array $data): void
    {
        try {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $code = "<?php\n//由 WebsiteMonitor 自动生成，请勿手改；改动会在下次重建时被覆盖。\nreturn "
                . var_export($data, true) . ";\n";
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $code, LOCK_EX) === false) {
                return;
            }
            //同分区 rename 是原子的，读者永远看不到写了一半的文件
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                return;
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        } catch (\Throwable $e) {
            Log::exception('State::dump', $e, ['file' => basename($file)]);
        }
    }
}
