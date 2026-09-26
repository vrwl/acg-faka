<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * 幂等建表与版本迁移。INSTALL / START / UPGRADE 都会跑一遍，随便跑多少次都安全。
 *
 * 几条必须遵守的约束（踩过坑，别改回去）：
 *  1. 目标 MySQL 5.7：没有窗口函数、没有 CTE，upsert 用 VALUES() 而不是 8.0 的 AS 别名。
 *  2. Illuminate 7 的 Blueprint::binary() 在 MySQL 语法里编译成 blob，**不能建索引**。
 *     所以所有 IP / 哈希列一律用 char(N) + ascii_bin 存十六进制文本：
 *     既能进索引，字典序又与二进制序一致（区间比较可直接用 BETWEEN），还能在 phpMyAdmin 里肉眼读。
 *  3. 短标识列必须显式 ascii：char(16) 在 utf8mb4 下占 64 字节索引位，ascii 下只占 16。
 *  4. hasTable()/hasColumn() 每次都查 information_schema，只允许在生命周期钩子里调，
 *     绝不能出现在请求路径上。
 */
final class Schema
{
    /** 结构版本：加列 / 加索引时 +1，并在 migrate() 里补对应分支 */
    public const VERSION = 3;

    private static bool $done = false;

    /**
     * 进程内只跑一次（守护任务每轮都会调）
     */
    public static function ensureOnce(): void
    {
        if (self::$done) {
            return;
        }
        self::ensure();
        self::$done = true;
    }

    /**
     * 表结构是否就绪 —— 对外 API 与面板据此判断插件可不可用。
     * 结果缓存在 Kv 里，避免反复查 information_schema。
     */
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $ready = Manager::schema()->hasTable(Db::KV) && Manager::schema()->hasTable(Db::ACCESS);
        } catch (\Throwable $e) {
            $ready = false;
        }
        return $ready;
    }

    public static function ensure(): void
    {
        $s = Manager::schema();

        self::access($s);
        self::sessions($s);
        self::seen($s);
        self::stats($s);
        self::dictionaries($s);
        self::security($s);
        self::spiders($s);
        self::state($s);

        self::migrate($s);
        Kv::set('schema_version', self::VERSION);
    }

    /* ────────────────────────────── 明细 ────────────────────────────── */

    private static function access($s): void
    {
        if ($s->hasTable(Db::ACCESS)) {
            return;
        }
        $s->create(Db::ACCESS, static function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->bigIncrements('id');
            $t->unsignedInteger('day')->comment('20260821，按天裁剪/分区键。必须 INT：MEDIUMINT 最大只有 16777215，装不下八位日期');
            $t->unsignedInteger('ts');
            $t->unsignedSmallInteger('ms')->default(0)->comment('耗时毫秒，65535 封顶');
            $t->char('vid', 16)->charset('ascii')->collation('ascii_bin')->default('');
            $t->char('sid', 16)->charset('ascii')->collation('ascii_bin')->default('');
            $t->unsignedInteger('uid')->default(0);
            $t->string('ip', 45)->charset('ascii')->collation('ascii_bin')->default('');
            $t->unsignedTinyInteger('kind')->default(0)->comment('0page 1api 2admin 3other 4-404 5waf');
            $t->unsignedTinyInteger('method')->default(1);
            $t->unsignedSmallInteger('status')->default(200);
            $t->unsignedInteger('page_id')->default(0);
            $t->string('query', 191)->default('');
            $t->unsignedInteger('ref_id')->default(0);
            $t->unsignedInteger('ua_id')->default(0);
            $t->unsignedSmallInteger('spider_id')->default(0);
            $t->unsignedSmallInteger('region_id')->default(0);
            $t->unsignedTinyInteger('dev')->default(0);
            $t->unsignedSmallInteger('flags')->default(0);
            //只留三个二级索引：行宽 ~110 字节，写放大控制在 2.2x 以内。
            //面板每次查询都必然带时间窗，先用 idx_ts 圈范围再过滤，不会有慢查询。
            $t->index('ts', 'idx_ts');
            $t->index(['vid', 'ts'], 'idx_vid_ts');
            $t->index(['ip', 'ts'], 'idx_ip_ts');
        });
    }

    private static function sessions($s): void
    {
        if (!$s->hasTable(Db::SESSION)) {
            $s->create(Db::SESSION, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('sid', 16)->charset('ascii')->collation('ascii_bin')->primary();
                $t->char('vid', 16)->charset('ascii')->collation('ascii_bin')->default('');
                $t->unsignedInteger('day')->default(0);
                $t->unsignedInteger('start_ts')->default(0);
                $t->unsignedInteger('end_ts')->default(0);
                //首条请求的排序键，越小越权威：0 = 采集时确认过是本次访问的第一个请求。
                //入口页/来源/UTM/新客标记都按它取胜者，与入库顺序无关。
                $t->unsignedInteger('entry_rank')->default(4294967295);
                $t->unsignedInteger('pv')->default(0);
                $t->unsignedInteger('entry_page_id')->default(0);
                $t->unsignedInteger('exit_page_id')->default(0);
                $t->unsignedInteger('ref_id')->default(0);
                $t->unsignedTinyInteger('ref_type')->default(0);
                $t->unsignedInteger('engine_id')->default(0);
                $t->unsignedInteger('utm_source_id')->default(0);
                $t->unsignedInteger('utm_medium_id')->default(0);
                $t->unsignedInteger('utm_campaign_id')->default(0);
                $t->string('ip', 45)->charset('ascii')->collation('ascii_bin')->default('');
                $t->unsignedSmallInteger('region_id')->default(0);
                $t->unsignedInteger('ua_id')->default(0);
                $t->unsignedInteger('browser_id')->default(0);
                $t->unsignedInteger('os_id')->default(0);
                $t->unsignedTinyInteger('dev')->default(0);
                $t->unsignedSmallInteger('spider_id')->default(0);
                $t->unsignedInteger('uid')->default(0);
                $t->unsignedTinyInteger('is_bounce')->default(1);
                $t->unsignedTinyInteger('is_new')->default(0);
                //idx_day_vid 让「某天精确 UV」= COUNT(DISTINCT vid) 走覆盖索引
                $t->index(['day', 'vid'], 'idx_day_vid');
                $t->index(['day', 'start_ts'], 'idx_day_start');
                $t->index(['day', 'entry_page_id'], 'idx_day_entry');
                $t->index(['day', 'ref_type'], 'idx_day_reftype');
            });
        }

        if (!$s->hasTable(Db::VISITOR)) {
            $s->create(Db::VISITOR, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('vid', 16)->charset('ascii')->collation('ascii_bin')->primary();
                $t->unsignedInteger('first_ts')->default(0);
                $t->unsignedInteger('last_ts')->default(0);
                $t->unsignedInteger('pv')->default(0);
                $t->unsignedInteger('sessions')->default(0);
                $t->unsignedInteger('uid')->default(0);
                $t->string('ip', 45)->charset('ascii')->collation('ascii_bin')->default('');
                $t->unsignedSmallInteger('region_id')->default(0);
                $t->unsignedInteger('ua_id')->default(0);
                $t->unsignedTinyInteger('id_kind')->default(0)->comment('0 cookie 1 指纹');
                $t->string('note', 120)->default('');
                $t->unsignedTinyInteger('tag')->default(0)->comment('0普通 1关注 2可疑 3拉黑');
                $t->index('last_ts', 'idx_last');
                $t->index('uid', 'idx_uid');
            });
        }

        if (!$s->hasTable(Db::ONLINE)) {
            $s->create(Db::ONLINE, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('vid', 16)->charset('ascii')->collation('ascii_bin')->primary();
                $t->char('sid', 16)->charset('ascii')->collation('ascii_bin')->default('');
                $t->unsignedInteger('uid')->default(0);
                $t->string('ip', 45)->charset('ascii')->collation('ascii_bin')->default('');
                $t->unsignedSmallInteger('region_id')->default(0);
                $t->unsignedInteger('ua_id')->default(0);
                $t->unsignedSmallInteger('spider_id')->default(0);
                $t->unsignedInteger('first_at')->default(0);
                $t->unsignedInteger('last_at')->default(0);
                $t->unsignedInteger('pv')->default(0);
                $t->unsignedInteger('page_id')->default(0);
                $t->unsignedInteger('entry_page_id')->default(0);
                $t->index('last_at', 'idx_last');
            });
        }
    }

    private static function seen($s): void
    {
        if ($s->hasTable(Db::SEEN)) {
            return;
        }
        //UV / IP 精确计数的唯一依据：批量 INSERT IGNORE 的「实际插入行数」= 该桶内真实新增数
        $s->create(Db::SEEN, static function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->unsignedTinyInteger('b')->comment('1 vid/分 2 vid/时 3 ip/分 4 ip/时 5 维度日 6 攻击证据');
            $t->unsignedInteger('t');
            $t->char('k', 16)->charset('ascii')->collation('ascii_bin');
            $t->primary(['b', 't', 'k'], 'pk_seen');
            $t->index(['b', 't'], 'idx_bt');
        });
    }

    /* ────────────────────────────── 汇总 ────────────────────────────── */

    private static function stats($s): void
    {
        $bucket = static function (Blueprint $t): void {
            $t->unsignedInteger('pv')->default(0);
            $t->unsignedInteger('uv')->default(0);
            $t->unsignedInteger('ip_n')->default(0);
            $t->unsignedInteger('sessions')->default(0);
            $t->unsignedInteger('api_pv')->default(0);
            $t->unsignedInteger('admin_pv')->default(0);
            $t->unsignedInteger('spider_pv')->default(0);
            $t->unsignedInteger('err4')->default(0);
            $t->unsignedInteger('err5')->default(0);
            $t->unsignedInteger('attack')->default(0);
            $t->unsignedInteger('blocked')->default(0);
            $t->unsignedBigInteger('ms_sum')->default(0);
            $t->unsignedSmallInteger('ms_max')->default(0);
            $t->unsignedTinyInteger('sample_pct')->default(100);
        };

        foreach ([Db::STAT_MIN, Db::STAT_HOUR] as $table) {
            if ($s->hasTable($table)) {
                continue;
            }
            $s->create($table, static function (Blueprint $t) use ($bucket): void {
                $t->engine = 'InnoDB';
                $t->unsignedInteger('t')->primary()->comment('桶起始 unix 时间');
                $bucket($t);
            });
        }

        if (!$s->hasTable(Db::STAT_DAY)) {
            $s->create(Db::STAT_DAY, static function (Blueprint $t) use ($bucket): void {
                $t->engine = 'InnoDB';
                $t->unsignedInteger('day')->primary();
                $bucket($t);
                $t->unsignedInteger('new_visitors')->default(0);
                $t->unsignedInteger('return_visitors')->default(0);
                $t->unsignedInteger('bounce')->default(0);
                $t->unsignedBigInteger('stay_sum')->default(0);
            });
        }

        if (!$s->hasTable(Db::AGG)) {
            //面板所有 TOP-N 的支点：扫描行数 = 天数 x 该维度基数，永远毫秒级
            $s->create(Db::AGG, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->unsignedInteger('day');
                $t->unsignedTinyInteger('dim');
                $t->unsignedInteger('key_id');
                $t->unsignedInteger('pv')->default(0)->comment('IngestTask 增量累加');
                $t->unsignedInteger('visits')->default(0)->comment('RollupTask 全量覆盖');
                $t->unsignedInteger('uv')->default(0)->comment('RollupTask 全量覆盖');
                $t->unsignedInteger('bounce')->default(0)->comment('RollupTask 全量覆盖');
                $t->unsignedBigInteger('ms_sum')->default(0);
                $t->unsignedBigInteger('stay_sum')->default(0);
                $t->primary(['day', 'dim', 'key_id'], 'pk_agg');
                $t->index(['day', 'dim', 'pv'], 'idx_day_dim_pv');
                $t->index(['day', 'dim', 'visits'], 'idx_day_dim_visits');
            });
        }
    }

    /* ────────────────────────────── 字典 ────────────────────────────── */

    private static function dictionaries($s): void
    {
        if (!$s->hasTable(Db::PAGE)) {
            $s->create(Db::PAGE, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->char('h', 16)->charset('ascii')->collation('ascii_bin')->comment('md5(path) 前 16 位');
                $t->string('path', 500)->default('');
                $t->string('title', 160)->default('');
                $t->unsignedTinyInteger('kind')->default(0);
                $t->unsignedInteger('first_ts')->default(0);
                $t->unsignedInteger('last_ts')->default(0);
                $t->unique('h', 'uk_h');
            });
        }

        if (!$s->hasTable(Db::REFERER)) {
            $s->create(Db::REFERER, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->char('h', 16)->charset('ascii')->collation('ascii_bin');
                $t->string('host', 120)->default('');
                $t->string('url', 500)->default('');
                $t->unsignedTinyInteger('type')->default(0)->comment('0直接 1站内 2搜索 3社交 4外链 5广告');
                $t->unsignedInteger('engine_id')->default(0);
                $t->string('keyword', 120)->default('');
                $t->unsignedInteger('first_ts')->default(0);
                $t->unsignedInteger('last_ts')->default(0);
                $t->unique('h', 'uk_h');
                $t->index('host', 'idx_host');
            });
        }

        if (!$s->hasTable(Db::UA)) {
            $s->create(Db::UA, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->char('h', 12)->charset('ascii')->collation('ascii_bin');
                $t->string('ua', 500)->default('');
                $t->unsignedInteger('browser_id')->default(0);
                $t->unsignedInteger('os_id')->default(0);
                $t->unsignedTinyInteger('dev')->default(0);
                $t->unsignedSmallInteger('spider_id')->default(0);
                $t->unsignedInteger('last_ts')->default(0);
                $t->unique('h', 'uk_h');
                $t->index('last_ts', 'idx_last');
            });
        }

        if (!$s->hasTable(Db::DICT)) {
            $s->create(Db::DICT, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->unsignedTinyInteger('type');
                $t->string('code', 64)->charset('ascii')->collation('ascii_bin');
                $t->string('name', 80)->default('');
                $t->unique(['type', 'code'], 'uk_type_code');
            });
        }

        if (!$s->hasTable(Db::REGION)) {
            $s->create(Db::REGION, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->smallIncrements('id');
                $t->char('country', 2)->charset('ascii')->collation('ascii_bin')->default('');
                $t->string('country_name', 48)->default('');
                $t->string('province', 48)->default('');
                $t->string('city', 48)->default('');
                $t->string('continent', 2)->charset('ascii')->default('');
                //2 + 48*4 + 48*4 = 386 字节，远低于 3072 上限
                $t->unique(['country', 'province', 'city'], 'uk_region');
            });
        }
    }

    /* ────────────────────────────── 安全 ────────────────────────────── */

    private static function security($s): void
    {
        if (!$s->hasTable(Db::RULE)) {
            $s->create(Db::RULE, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->unsignedTinyInteger('type')->comment('1IP黑 2IP白 3地域黑 4地域白 5UA黑 6路径豁免');
                $t->string('value', 120)->comment('1.2.3.0/24 | CN.GD | *bot*');
                //定长 hex：字典序与二进制序一致，可直接 BETWEEN 做区间命中查询
                $t->char('start_hex', 32)->charset('ascii')->collation('ascii_bin')->default('');
                $t->char('end_hex', 32)->charset('ascii')->collation('ascii_bin')->default('');
                $t->unsignedTinyInteger('family')->default(0)->comment('4 / 6 / 0=非IP');
                $t->string('note', 120)->default('');
                $t->string('source', 16)->default('manual');
                $t->unsignedTinyInteger('status')->default(1);
                $t->unsignedInteger('hits')->default(0);
                $t->unsignedInteger('last_hit_at')->default(0);
                $t->unsignedInteger('expire_at')->default(0)->comment('0=永久');
                $t->dateTime('create_time')->nullable();
                $t->index(['type', 'status'], 'idx_type_status');
                $t->index(['start_hex', 'end_hex'], 'idx_range');
                $t->unique(['type', 'value'], 'uk_type_value');
            });
        }

        if (!$s->hasTable(Db::BAN)) {
            //自动封禁。热路径不查这张表（走 runtime 下的一 IP 一文件），
            //这里是后台展示与跨进程持久化用的权威副本。
            $s->create(Db::BAN, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->string('ip', 45)->charset('ascii')->collation('ascii_bin')->primary();
                $t->char('start_hex', 32)->charset('ascii')->collation('ascii_bin')->default('');
                $t->string('reason', 120)->default('');
                $t->string('rule', 48)->default('');
                $t->unsignedTinyInteger('level')->default(0)->comment('封禁梯度第几级');
                $t->unsignedInteger('hits')->default(0);
                $t->unsignedInteger('create_at')->default(0);
                $t->unsignedInteger('expire_at')->default(0)->comment('0=永久');
                $t->unsignedInteger('last_at')->default(0);
                $t->unsignedSmallInteger('region_id')->default(0);
                $t->unsignedTinyInteger('source')->default(1)->comment('0手工 1自动 2API 3通知中心');
                $t->index('expire_at', 'idx_expire');
                $t->index('create_at', 'idx_create');
            });
        }

        if (!$s->hasTable(Db::ATTACK)) {
            $s->create(Db::ATTACK, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->unsignedInteger('ts');
                $t->unsignedInteger('day')->default(0);
                $t->string('ip', 45)->charset('ascii')->collation('ascii_bin')->default('');
                $t->char('vid', 16)->charset('ascii')->collation('ascii_bin')->default('');
                $t->unsignedTinyInteger('kind')->default(0);
                $t->string('rule', 48)->default('');
                $t->string('level', 12)->default('warn');
                $t->unsignedSmallInteger('score')->default(0);
                $t->unsignedTinyInteger('act')->default(0)->comment('0记录 1计分 2拦截 3封禁 4限速');
                $t->unsignedTinyInteger('blocked')->default(0)->comment('观察模式下恒为 0');
                $t->unsignedTinyInteger('method')->default(1);
                $t->unsignedSmallInteger('status')->default(0);
                $t->string('path', 500)->default('');
                $t->unsignedInteger('ua_id')->default(0);
                $t->unsignedSmallInteger('region_id')->default(0);
                $t->string('ref', 255)->default('');
                $t->char('req_id', 24)->charset('ascii')->collation('ascii_bin')->default('')->comment('拦截页上给用户看的编号');
                $t->text('evidence')->nullable()->comment('已脱敏的取证片段 JSON');
                $t->index('ts', 'idx_ts');
                $t->index(['ip', 'ts'], 'idx_ip_ts');
                $t->index(['kind', 'ts'], 'idx_kind_ts');
                $t->index('req_id', 'idx_req');
            });
        }

        if (!$s->hasTable(Db::IP)) {
            $s->create(Db::IP, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->string('ip', 45)->charset('ascii')->collation('ascii_bin')->primary();
                $t->unsignedInteger('first_at')->default(0);
                $t->unsignedInteger('last_at')->default(0);
                $t->unsignedInteger('req')->default(0);
                $t->unsignedInteger('atk')->default(0);
                $t->unsignedInteger('ban_cnt')->default(0);
                $t->string('last_rule', 48)->default('');
                $t->unsignedSmallInteger('region_id')->default(0);
                $t->unsignedSmallInteger('spider_id')->default(0);
                $t->unsignedInteger('ua_id')->default(0);
                $t->string('note', 120)->default('');
                $t->index('last_at', 'idx_last');
                $t->index('atk', 'idx_atk');
            });
        }
    }

    /* ────────────────────────────── 蜘蛛 ────────────────────────────── */

    private static function spiders($s): void
    {
        if (!$s->hasTable(Db::SPIDER)) {
            $s->create(Db::SPIDER, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->smallIncrements('id');
                $t->string('code', 32)->charset('ascii')->collation('ascii_bin');
                $t->string('name', 48)->default('');
                $t->string('pattern', 200)->default('')->comment('UA 小写关键词，| 分隔，参与合并正则');
                $t->string('verify_domain', 255)->default('')->comment('反查域名后缀，逗号分隔');
                $t->unsignedTinyInteger('grp')->default(1);
                $t->string('icon', 48)->default('');
                $t->unsignedTinyInteger('status')->default(1);
                $t->unsignedTinyInteger('builtin')->default(1);
                $t->unsignedSmallInteger('sort')->default(100);
                $t->unique('code', 'uk_code');
            });
        }

        if (!$s->hasTable(Db::SPIDER_IP)) {
            $s->create(Db::SPIDER_IP, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->string('ip', 45)->charset('ascii')->collation('ascii_bin')->primary();
                $t->unsignedSmallInteger('spider_id')->default(0);
                $t->unsignedTinyInteger('verified')->default(0)->comment('0待验 1真 2伪 3无需验证');
                $t->string('ptr', 191)->default('');
                $t->unsignedInteger('checked_at')->default(0);
                $t->unsignedInteger('expire_at')->default(0);
                $t->index('expire_at', 'idx_expire');
                $t->index('verified', 'idx_verified');
            });
        }
    }

    /* ────────────────────────────── 状态 ────────────────────────────── */

    private static function state($s): void
    {
        if (!$s->hasTable(Db::KV)) {
            $s->create(Db::KV, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->string('k', 96)->charset('ascii')->collation('ascii_bin')->primary();
                $t->text('v')->nullable();
                $t->unsignedInteger('expire_at')->default(0);
                $t->index('expire_at', 'idx_expire');
            });
        }

        if (!$s->hasTable(Db::INGEST)) {
            //入库幂等标记：一个 seal 文件全局只能入库一次
            $s->create(Db::INGEST, static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->string('f', 96)->charset('ascii')->collation('ascii_bin')->primary();
                $t->unsignedInteger('ts')->default(0);
                $t->unsignedInteger('lines')->default(0);
                $t->index('ts', 'idx_ts');
            });
        }
    }

    /* ────────────────────────────── 迁移 ────────────────────────────── */

    /**
     * 版本升级时补列 / 补索引。
     * 注意：项目没装 doctrine/dbal，Blueprint::change() 不可用，改列类型请写原生 ALTER。
     */
    private static function migrate($s): void
    {
        $from = (int)Kv::int('schema_version', 0);
        if ($from >= self::VERSION) {
            return;
        }

        //v2：day 列原本是 MEDIUMINT（最大 16777215），根本装不下 20260821 这种八位日期，
        //   写入会直接报 1264 out of range。同时把会话/在线的 pv 从 SMALLINT 放宽到 INT，
        //   免得极端会话（爬虫或压测）超过 65535 时炸掉。
        self::widen(Db::ACCESS, 'day', 'INT UNSIGNED NOT NULL');
        self::widen(Db::SESSION, 'day', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::widen(Db::ATTACK, 'day', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::widen(Db::AGG, 'day', 'INT UNSIGNED NOT NULL');
        self::widen(Db::STAT_DAY, 'day', 'INT UNSIGNED NOT NULL');
        self::widen(Db::SESSION, 'pv', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::widen(Db::ONLINE, 'pv', 'INT UNSIGNED NOT NULL DEFAULT 0');

        //v3：会话首条请求的排序键
        if ($from < 3 && $s->hasTable(Db::SESSION) && !$s->hasColumn(Db::SESSION, 'entry_rank')) {
            try {
                $s->table(Db::SESSION, static function (Blueprint $t): void {
                    $t->unsignedInteger('entry_rank')->default(4294967295);
                });
                //已有会话没有这个信息，用起始时间兜底，至少保持时间序语义
                Db::conn()->statement(
                    'UPDATE `' . Db::physical(Db::SESSION) . '` SET `entry_rank` = `start_ts` WHERE `entry_rank` = 4294967295'
                );
                Log::info('已添加列', ['table' => Db::SESSION, 'column' => 'entry_rank']);
            } catch (\Throwable $e) {
                Log::exception('Schema::migrate::entry_rank', $e);
            }
        }
    }

    /**
     * 按需加宽列。项目没装 doctrine/dbal，Blueprint::change() 用不了，只能写原生 ALTER。
     * 先查一次 information_schema，类型已经对了就不动 —— ALTER 会重建整张表，不能白做。
     */
    private static function widen(string $table, string $column, string $definition): void
    {
        try {
            $physical = Db::physical($table);
            $rows = Db::conn()->select(
                'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$physical, $column]
            );
            $current = strtolower((string)($rows[0]->t ?? ''));
            if ($current === '') {
                return;
            }
            $wanted = strtolower(explode(' ', trim($definition))[0]);
            //已经是目标类型（int 不是 mediumint / smallint）就跳过
            if (str_starts_with($current, $wanted) && !str_starts_with($current, 'mediumint') && !str_starts_with($current, 'smallint')) {
                return;
            }
            Db::conn()->statement(
                'ALTER TABLE `' . $physical . '` MODIFY `' . $column . '` ' . $definition
            );
            Log::info('已加宽列', ['table' => $table, 'column' => $column, 'from' => $current, 'to' => $definition]);
        } catch (\Throwable $e) {
            Log::exception('Schema::widen', $e, ['table' => $table, 'column' => $column]);
        }
    }

    /**
     * 按天分区（可选，raw_partition=1 时启用）。
     * MySQL 5.7 要求分区键必须属于每一个唯一键，所以主键要改成 (id, day)。
     * 失败不抛异常，只记日志并回落到分块 DELETE 清理。
     */
    public static function tryPartition(): bool
    {
        try {
            $table = Db::physical(Db::ACCESS);
            $conn = Db::conn();
            $exists = $conn->select(
                'SELECT COUNT(*) AS n FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
                [$table]
            );
            if ((int)($exists[0]->n ?? 0) > 0) {
                return true;
            }
            if ((int)(Db::table(Db::ACCESS)->count()) > 0) {
                Log::warn('明细表已有数据，跳过自动分区（请在低峰期手工执行）');
                return false;
            }
            $conn->statement('ALTER TABLE `' . $table . '` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`, `day`)');
            $today = (int)date('Ymd');
            $conn->statement(
                'ALTER TABLE `' . $table . '` PARTITION BY RANGE (`day`) ('
                . 'PARTITION p' . $today . ' VALUES LESS THAN (' . ($today + 1) . '), '
                . 'PARTITION pmax VALUES LESS THAN MAXVALUE)'
            );
            Log::info('明细表已切换为按天分区');
            return true;
        } catch (\Throwable $e) {
            Log::exception('Schema::tryPartition', $e);
            Kv::set('partition_error', $e->getMessage(), 86400);
            return false;
        }
    }

    /**
     * 各表行数与磁盘占用（设置页展示用，走 information_schema 的估算值，很快）
     * @return array<int,array{table:string,rows:int,mb:float}>
     */
    public static function storage(): array
    {
        $out = [];
        try {
            $prefix = Db::conn()->getTablePrefix();
            $rows = Db::conn()->select(
                'SELECT TABLE_NAME AS t, TABLE_ROWS AS r, (DATA_LENGTH + INDEX_LENGTH) AS b'
                . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
                [$prefix . 'wm\_%']
            );
            foreach ($rows as $row) {
                $out[] = [
                    'table' => (string)$row->t,
                    'rows' => (int)$row->r,
                    'mb' => round(((int)$row->b) / 1048576, 2),
                ];
            }
            usort($out, static fn(array $a, array $b): int => $b['mb'] <=> $a['mb']);
        } catch (\Throwable $e) {
            Log::exception('Schema::storage', $e);
        }
        return $out;
    }

    /**
     * 清空全部统计与安全数据（设置页的危险操作，双重确认后调用）
     * @param bool $keepRules 是否保留人工规则与白名单
     */
    public static function truncateAll(bool $keepRules = true): void
    {
        $tables = [
            Db::ACCESS, Db::SESSION, Db::VISITOR, Db::ONLINE, Db::SEEN,
            Db::STAT_MIN, Db::STAT_HOUR, Db::STAT_DAY, Db::AGG,
            Db::PAGE, Db::REFERER, Db::UA, Db::DICT,
            Db::ATTACK, Db::IP, Db::SPIDER_IP, Db::INGEST,
        ];
        if (!$keepRules) {
            $tables[] = Db::RULE;
            $tables[] = Db::BAN;
        }
        foreach ($tables as $table) {
            try {
                Db::conn()->statement('TRUNCATE TABLE `' . Db::physical($table) . '`');
            } catch (\Throwable $e) {
                Log::exception('Schema::truncateAll', $e, ['table' => $table]);
            }
        }
        Log::warn('已清空监控数据', ['keep_rules' => $keepRules]);
    }
}
