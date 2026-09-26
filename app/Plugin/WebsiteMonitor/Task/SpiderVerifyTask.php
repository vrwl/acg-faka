<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Task;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Acl\Ban;
use App\Plugin\WebsiteMonitor\Core\Collect\Spider;
use App\Plugin\WebsiteMonitor\Core\Db;
use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Settings;
use App\Plugin\WebsiteMonitor\Core\State;

/**
 * 伪蜘蛛识别：反向 DNS 正反查。
 *
 * **这件事绝对不能放进网页请求**。gethostbyaddr() 没有超时参数，一次慢 PTR 就能
 * 给请求加上好几秒；攻击者只要伪造一个 Googlebot 的 UA 就能把整站拖死。
 * 所以热路径只写一个空文件排队，真正的查询在这里慢慢做。
 *
 * 校验是双向的：PTR 得到域名 → 域名后缀要匹配 → 再正查回来必须还是原 IP。
 * 只做单向 PTR 是不够的，任何人都能把自己的反解设成 googlebot.com。
 */
class SpiderVerifyTask
{
    /** 每轮最多查多少个，避免一次卡太久 */
    private const BATCH = 30;

    /** 真蜘蛛的结论缓存 30 天，伪蜘蛛只缓存 3 天（可能是动态 IP 换了主人） */
    private const TTL_REAL = 2592000;
    private const TTL_FAKE = 259200;

    public function run(object $ctx): void
    {
        try {
            Settings::refresh();
            if (!Settings::enabled()) {
                return;
            }
            State::reset();

            $pending = Spider::pending(self::BATCH);
            if ($pending === []) {
                return;
            }

            $spiders = State::spiders();
            $verifyMap = (array)($spiders['verify'] ?? []);
            $real = 0;
            $fake = 0;

            foreach ($pending as $item) {
                if (method_exists($ctx, 'shouldContinue') && !$ctx->shouldContinue()) {
                    break;
                }
                if (method_exists($ctx, 'heartbeat')) {
                    $ctx->heartbeat();
                }

                $ip = (string)$item['ip'];
                $spiderId = (int)$item['spider_id'];
                $domains = (array)($verifyMap[$spiderId] ?? []);

                //内网与保留地址不做反查：它们永远不会有 googlebot 的反解，
                //一判就是「伪蜘蛛」。局域网测试、或代理没配好导致所有 IP 都是内网时，
                //这会把所有人都封掉。
                if (\App\Plugin\WebsiteMonitor\Core\Ip::isPrivate($ip)) {
                    Spider::writeState($ip, Kind::SPV_SKIP, self::TTL_FAKE);
                    @unlink((string)$item['file']);
                    continue;
                }

                if ($domains === []) {
                    //这个蜘蛛官方没公布可校验的域名，标记为「无需验证」直接放行
                    Spider::writeState($ip, Kind::SPV_SKIP, self::TTL_REAL);
                    self::record($ip, $spiderId, Kind::SPV_SKIP, '');
                    @unlink((string)$item['file']);
                    continue;
                }

                $ptr = @gethostbyaddr($ip);
                $ok = false;
                if (is_string($ptr) && $ptr !== '' && $ptr !== $ip && self::suffixMatch($ptr, $domains)) {
                    //正查确认：伪造 PTR 的人没法让正解也指回来
                    $forward = @gethostbynamel($ptr);
                    $ok = is_array($forward) && in_array($ip, $forward, true);
                }

                $verdict = $ok ? Kind::SPV_REAL : Kind::SPV_FAKE;
                Spider::writeState($ip, $verdict, $ok ? self::TTL_REAL : self::TTL_FAKE);
                self::record($ip, $spiderId, $verdict, is_string($ptr) ? $ptr : '');
                @unlink((string)$item['file']);

                if ($ok) {
                    $real++;
                    continue;
                }

                $fake++;
                self::logAttack($ip, $spiderId, is_string($ptr) ? $ptr : '');
                if (Settings::bool('ua_block_fake_spider')) {
                    //走 auto() 而不是 add()：它会先过观察模式、白名单、管理员 IP 的闸门
                    Ban::auto($ip, 86400, lang('伪装成搜索引擎蜘蛛'), 'ua.fake_spider');
                }
            }

            if ($real > 0 || $fake > 0) {
                Log::info('蜘蛛反查完成', ['real' => $real, 'fake' => $fake]);
            }
        } catch (\Throwable $e) {
            Log::exception('SpiderVerifyTask::run', $e);
            if (method_exists($ctx, 'error')) {
                $ctx->error('蜘蛛反查失败：' . $e->getMessage());
            }
        }
    }

    /**
     * @param string[] $domains
     */
    private static function suffixMatch(string $ptr, array $domains): bool
    {
        $ptr = strtolower(rtrim($ptr, '.'));
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string)$domain));
            if ($domain === '') {
                continue;
            }
            $domain = ltrim($domain, '.');
            if ($ptr === $domain || str_ends_with($ptr, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    private static function record(string $ip, int $spiderId, int $verdict, string $ptr): void
    {
        try {
            Db::upsertMany(Db::SPIDER_IP, [[
                'ip' => $ip,
                'spider_id' => $spiderId,
                'verified' => $verdict,
                'ptr' => mb_substr($ptr, 0, 191),
                'checked_at' => time(),
                'expire_at' => time() + ($verdict === Kind::SPV_FAKE ? self::TTL_FAKE : self::TTL_REAL),
            ]], [
                'spider_id' => 'VALUES(`spider_id`)',
                'verified' => 'VALUES(`verified`)',
                'ptr' => 'VALUES(`ptr`)',
                'checked_at' => 'VALUES(`checked_at`)',
                'expire_at' => 'VALUES(`expire_at`)',
            ]);
        } catch (\Throwable $e) {
            Log::exception('SpiderVerifyTask::record', $e, ['ip' => $ip]);
        }
    }

    private static function logAttack(string $ip, int $spiderId, string $ptr): void
    {
        try {
            Db::table(Db::ATTACK)->insert([
                'ts' => time(),
                'day' => (int)date('Ymd'),
                'ip' => $ip,
                'vid' => '',
                'kind' => Kind::ATK_FAKE_SPIDER,
                'rule' => 'ua.fake_spider',
                'level' => 'warn',
                'score' => 8,
                'act' => Settings::bool('ua_block_fake_spider') ? Kind::ACT_BAN : Kind::ACT_LOG,
                'blocked' => 0,
                'method' => Kind::M_GET,
                'status' => 0,
                'path' => '',
                'ua_id' => 0,
                'region_id' => 0,
                'ref' => '',
                'req_id' => '',
                'evidence' => Db::json(['spider_id' => $spiderId, 'ptr' => $ptr === '' ? lang('无反解记录') : $ptr]),
            ]);
        } catch (\Throwable $e) {
            Log::exception('SpiderVerifyTask::logAttack', $e);
        }
    }
}
