/*
 * 网站监控统计 —— 插件配置弹窗
 *
 * 这个文件被 eval 在后台插件列表的上下文里，返回一个 tab 数组。
 * assign.PLUGIN_CONFIG 是当前已保存的配置。
 *
 * 两个必须记住的坑：
 *  1. type:"custom" 里手写的 <select> 一定要加 lay-ignore，
 *     否则 layui 会把它替换成自绘下拉，$().val() 取不到值。
 *  2. 复杂结构（规则表）不放这里 —— 它们在插件面板里管理，
 *     弹窗只放开关与阈值。硬塞进来只会又难用又容易被 $_POST 净化搞坏。
 */
(function () {
    var cfg = function (key, fallback) {
        var v = (assign.PLUGIN_CONFIG || {})[key];
        return (v === undefined || v === null || v === '') ? fallback : v;
    };
    var T = (typeof i18n === 'function') ? i18n : function (s) { return s; };

    var sw = function (name, title, tips, def) {
        return { title: T(title), name: name, type: 'switch', tips: T(tips || ''), default: cfg(name, def) };
    };
    var input = function (name, title, tips, def, placeholder) {
        return {
            title: T(title), name: name, type: 'input', tips: T(tips || ''),
            default: cfg(name, def), placeholder: placeholder || ''
        };
    };
    var area = function (name, title, tips, def, placeholder) {
        return {
            title: T(title), name: name, type: 'textarea', tips: T(tips || ''),
            default: cfg(name, def), placeholder: placeholder || '', height: 90
        };
    };
    // dict 必须是 [{id, name}] 数组：后台的 _Dict.globalization() 上来就 [...data]，
    // 传普通对象会直接抛 "data is not iterable"，整个配置弹窗渲染不出来。
    // name 传中文原文即可，globalization() 自己会过一遍 i18n()。
    var select = function (name, title, dict, tips, def) {
        var options = Object.keys(dict).map(function (k) {
            return { id: k, name: dict[k] };
        });
        return { title: T(title), name: name, type: 'select', dict: options, tips: T(tips || ''), default: cfg(name, def) };
    };

    /* ── 总览：一眼看清插件状态，并给出面板入口 ───────────────── */
    var overview = {
        name: T('总览'),
        form: [{
            title: false, name: '__overview', type: 'custom',
            complete: function (_, dom) {
                var box = $(dom);
                box.html('<div style="padding:4px 0 10px;font-size:13px;line-height:1.9;color:var(--md-on-surface-med)">'
                    + T('加载运行状态…') + '</div>');
                $.post('/plugin/WebsiteMonitor/admin/runtime', {}, function (res) {
                    if (!res || res.code !== 200) {
                        box.html('<div style="color:var(--md-error)">' + T('无法读取运行状态，请确认插件已启用') + '</div>');
                        return;
                    }
                    var d = res.data || {};
                    var geo = d.geo || {}, daemon = d.daemon || {}, notify = d.notify || {}, cm = d.client_mode || {};
                    var chip = function (ok, text) {
                        var color = ok ? 'var(--md-success)' : 'var(--md-error)';
                        return '<span style="color:' + color + '">' + (ok ? '● ' : '○ ') + text + '</span>';
                    };
                    var rows = [
                        [T('防火墙模式'), d.waf_mode === 'observe' ? T('观察（只记录不拦截）')
                            : (d.waf_mode === 'strict' ? T('严格') : T('拦截'))],
                        [T('线程管理器'), chip(daemon.running && daemon.task_alive,
                            daemon.running ? (daemon.task_alive ? T('运行中') : T('任务未注册')) : T('未运行'))],
                        [T('IP 地理库'), chip(geo.ready, geo.ready
                            ? ((geo.meta || {}).build_date || T('已就绪'))
                            : T('未下载（地区功能不可用）'))],
                        [T('通知中心'), chip(notify.api, notify.text || '')],
                        [T('Redis 加速'), chip(d.redis, d.redis ? T('可用') : T('未启用（不影响功能）'))],
                        [T('封禁中的 IP'), String(d.bans || 0)],
                        [T('识别到你的 IP'), cm.resolved || '—']
                    ];
                    var html = '<table style="width:100%;font-size:12.5px;line-height:2">';
                    rows.forEach(function (r) {
                        html += '<tr><td style="color:var(--md-on-surface-med);white-space:nowrap;padding-right:14px">'
                            + r[0] + '</td><td>' + r[1] + '</td></tr>';
                    });
                    html += '</table>';

                    if (cm.warn) {
                        html += '<div style="margin-top:10px;padding:9px 12px;border-radius:10px;font-size:12px;'
                            + 'background:rgba(var(--md-error-rgb),.10)">' + cm.warn + '</div>';
                    }
                    html += '<div style="margin-top:12px">'
                        + '<a href="/plugin/WebsiteMonitor/panel/index" target="_blank" class="btn btn-sm btn-primary">'
                        + T('打开监控面板') + '</a>'
                        + '<span style="margin-left:10px;font-size:12px;color:var(--md-on-surface-dis)">'
                        + T('规则、名单、封禁都在面板里管理') + '</span></div>';
                    box.html(html);
                }, 'json');
            }
        }]
    };

    /* ── 采集 ─────────────────────────────────────────────── */
    var collect = {
        name: T('采集'),
        form: [
            sw('collect_enabled', '启用采集', '关闭后不再记录任何访问数据，防火墙仍然工作', '1'),
            sw('count_admin', '统计后台访问', '关掉后，你自己在后台的操作不计入统计', '1'),
            sw('count_spider_in_pv', '蜘蛛计入 PV/UV', '默认不计入 —— 蜘蛛不是人，混进来会让转化率失真', '0'),
            input('session_timeout', '会话超时（秒）', '多久没有新请求就算一次访问结束，默认 1800', '1800'),
            input('online_window', '在线判定窗口（秒）', '最近多少秒内有请求算「在线」，默认 300', '300'),
            area('ignore_routes', '忽略的路由', '每行一个前缀，这些路径不计入统计。面板自己的接口已自动排除。',
                "/plugin/WebsiteMonitor/\n/admin/api/app/", '/admin/api/app/'),
            input('web_flush_interval', '网页接力间隔（秒）', '没装线程管理器时，多久由网页请求代为入库一次', '15')
        ]
    };

    /* ── 存储 ─────────────────────────────────────────────── */
    var storage = {
        name: T('存储'),
        form: [
            input('raw_retention_days', '访问明细保留（天）', '明细占地方最多；聚合报表不受影响，永久保留', '30'),
            input('raw_sample', '明细采样率（%）', '100 = 全量。流量很大时调低，聚合数字依然精确', '100'),
            input('flood_qps', '洪峰降级阈值（请求/秒）', '全站持续超过这个速率就自动切精简采集：PV / 攻击 / IP 依然精确，暂不记页面与来源明细，回落后自动恢复', '2000'),
            input('session_retention_days', '会话保留（天）', '', '30'),
            input('visitor_retention_days', '访客档案保留（天）', '', '180'),
            input('attack_retention_days', '攻击取证保留（天）', '取证里可能含用户明文输入，别留太久', '14'),
            input('agg_retention_days', '聚合报表保留（天）', '', '400'),
            sw('raw_partition', '明细表按天分区', '大流量站点建议开：清理旧数据从「逐行删」变成「秒删一个分区」。空表时启用最稳妥。', '0')
        ]
    };

    /* ── 防火墙 ───────────────────────────────────────────── */
    var waf = {
        name: T('防火墙'),
        form: [
            select('waf_mode', '防护模式', {
                observe: T('观察 —— 只记录不拦截（新装建议）'),
                protect: T('拦截 —— 按规则拦截并封禁'),
                strict: T('严格 —— 更激进，误伤风险更高')
            }, '先用观察模式跑几天，确认没有误伤再切到拦截', 'observe'),
            sw('waf_enabled', '启用规则引擎', '', '1'),
            sw('trust_admin', '管理员会话豁免', '已登录后台的请求不会被拦，这是防止把自己关在门外的关键保险', '1'),
            area('always_allow', '永久放行清单', '每行一个 IP / 网段 / 通配。安装时已自动写入你当时的 IP。',
                '', '1.2.3.4\n10.0.0.0/8'),
            input('waf_score_threshold', '风险分阈值', '单条规则不够格拦截时，累计分数超过它才拦', '10'),
            input('method_allow', '允许的 HTTP 方法', '逗号分隔，其余方法直接 405', 'GET,POST,HEAD,OPTIONS'),
            sw('waf_scan_body', '扫描请求体', '管理员会话下会自动跳过，避免后台提交模板时误报', '1'),
            sw('waf_scan_cookie', '扫描 Cookie', '', '1'),
            sw('waf_scan_files', '扫描上传文件名', '', '1'),
            input('waf_body_max_kb', '请求体扫描上限（KB）', '超过部分不扫，防止超长载荷拖慢请求', '64'),
            area('waf_exclude_fields', '不扫描的字段', '富文本字段写在这里，支持 tpl_* 前缀通配。这是最有效的降误报手段。',
                "content\nnotice\ndescription\nleave_message\nremark\ntpl_*", 'content'),
            area('waf_exclude_paths', '不扫描的路径', '每行一个前缀', '', '/admin/api/config/save'),
            sw('ua_block_tools', '拦截扫描工具 UA', 'sqlmap、nmap、nikto 这类，零误报', '1'),
            sw('ua_block_fake_spider', '拦截伪装蜘蛛', '声称是搜索引擎但反向解析对不上的', '1'),
            sw('ua_block_empty', '拦截空 UA', '默认关：部分正常 API 客户端不带 UA', '0'),
            sw('log_evidence', '记录取证片段', '关掉后只记命中规则，不存请求内容', '1'),
            input('evidence_max_bytes', '取证片段上限（字节）', '', '2048')
        ]
    };

    /* ── CC 防御 ──────────────────────────────────────────── */
    var cc = {
        name: T('CC 防御'),
        form: [
            sw('cc_enabled', '启用限频', '', '1'),
            select('cc_driver', '计数器驱动', {
                auto: T('自动（有 Redis 就用，否则用文件）'),
                redis: T('强制 Redis'),
                file: T('强制文件')
            }, '文件模式是无锁定长文件，攻击下也不会产生锁竞争', 'auto'),
            input('cc_burst_limit', '突发阈值（次）', '配合下面的窗口。60 次 / 10 秒 ≈ 6 QPS，正常浏览约 1–2 QPS', '60'),
            input('cc_burst_window', '突发窗口（秒）', '', '10'),
            input('cc_sustain_limit', '持续阈值（次）', '抓「慢速但不停歇」的刷子。600 次 / 10 分钟 ≈ 1 QPS', '600'),
            input('cc_sustain_window', '持续窗口（秒）', '', '600'),
            input('cc_path_limit', '单路径阈值（次）', '只限速不封禁 —— 轮询类接口天生高频，封了就是误伤', '30'),
            input('cc_path_window', '单路径窗口（秒）', '', '10'),
            input('cc_ban_seconds', '触发后封禁（秒）', '', '300'),
            sw('cc_exempt_spider', '豁免已验证蜘蛛', '通过反向解析验证过的搜索引擎不限频', '1'),
            sw('cc_exempt_member', '豁免已登录会员', '', '1'),
            area('cc_exempt_paths', '豁免路径', '支付回调这类必须放行的写在这里',
                "/user/api/order/callback\n/pay/", '/pay/'),
            sw('overload_enabled', '站点过载保护', '全站速率过高时收紧所有人的阈值，白名单与会员不受影响', '1'),
            input('cc_global_limit', '全站阈值（次）', '', '3000'),
            input('cc_global_window', '全站窗口（秒）', '', '10'),
            input('overload_factor', '过载收紧倍数', '过载时阈值除以它，默认 3', '3')
        ]
    };

    /* ── 封禁与登录 ───────────────────────────────────────── */
    var ban = {
        name: T('封禁'),
        form: [
            input('ban_ladder', '封禁时长梯度（秒）', '逗号分隔。同一 IP 反复触发会被越封越久', '300,1800,7200,86400,604800'),
            input('ban_permanent_after', '第几次转永久', '', '6'),
            input('ban_reset_hours', '多久无违规归零（小时）', '', '72'),
            sw('login_guard_enabled', '启用登录防护', '', '1'),
            input('login_fail_limit', '会员登录失败上限', '', '8'),
            input('login_fail_window', '统计窗口（分钟）', '', '10'),
            input('login_ban_seconds', '会员爆破封禁（秒）', '', '1800'),
            input('admin_login_fail_limit', '后台登录失败上限', '后台阈值要狠 —— 正常人不会连错三次以上', '3'),
            input('admin_login_ban_seconds', '后台爆破封禁（秒）', '', '3600')
        ]
    };

    /* ── 地区与地理库 ─────────────────────────────────────── */
    var geo = {
        name: T('地区'),
        form: [
            sw('geo_enabled', '启用地理定位', '关掉后不做归属地查询，地区统计与地区封锁都不可用', '1'),
            select('region_mode', '地区规则模式', {
                off: T('关闭'),
                blacklist: T('黑名单 —— 名单里的地区禁止访问'),
                whitelist: T('白名单 —— 只允许名单里的地区')
            }, '具体地区在面板的「规则」页里加。后台路由永远不受地区限制。', 'off'),
            select('region_scope', '地区粒度', {
                country: T('国家'), province: T('省 / 州'), city: T('城市')
            }, '', 'country'),
            sw('region_confirm', '我已了解地区规则的风险', '不勾选时，会把你自己所在地区挡掉的规则将被拒绝保存', '0'),
            //内置地址绝不写在这里：前端 JS 是明文，等于把作者自维护的镜像挂出来给人刷流量。
            //留空时由服务端解析成内置源（Settings::geoUrl）。
            input('geo_db_url', 'IP 库下载地址', '留空即可，默认使用插件内置的镜像（每天更新）。要换成自己的镜像就填这里，必须是 https 的完整下载地址。',
                '', 'https://你的域名/GeoLite2-City.mmdb'),
            sw('geo_auto_update', '自动更新 IP 库', '需要线程管理器。支持条件请求，没变化时几乎不产生流量。', '1'),
            input('geo_update_cron', '更新周期（cron）', '5 段表达式。默认每周一 04:00。', '0 4 * * 1'),
            select('geo_lang', '地名语言', { 'zh-CN': '简体中文', en: 'English' }, '', 'zh-CN'),
            input('geo_cache_ttl', '归属地缓存（秒）', '按命中的网段缓存，默认 30 天', '2592000')
        ]
    };

    /* ── 通知 ─────────────────────────────────────────────── */
    var notify = {
        name: T('通知'),
        form: [
            {
                title: false, name: '__notify_state', type: 'custom',
                complete: function (_, dom) {
                    var box = $(dom);
                    $.post('/plugin/WebsiteMonitor/admin/runtime', {}, function (res) {
                        var n = ((res || {}).data || {}).notify || {};
                        var color = n.api ? 'var(--md-success)' : 'var(--md-warning)';
                        box.html('<div style="padding:9px 12px;border-radius:10px;font-size:12.5px;line-height:1.7;'
                            + 'background:rgba(var(--md-primary-rgb),.08)">'
                            + '<span style="color:' + color + '">● </span>' + (n.text || '')
                            + '<div style="margin-top:4px;color:var(--md-on-surface-dis)">'
                            + T('告警通过「通知中心」插件发送，邮件与 Telegram 都用它的配置，发送记录也在它那边查。')
                            + '</div></div>');
                    }, 'json');
                }
            },
            sw('notify_enabled', '启用告警通知', '', '1'),
            select('notify_min_level', '级别门槛', {
                info: T('提示及以上（最啰嗦）'), warn: T('警告及以上（推荐）'), critical: T('仅严重')
            }, '', 'warn'),
            input('notify_cooldown', '同类告警冷却（分钟）', '冷却期内的重复告警会被压掉，解冻时一次性告诉你「期间又发生了 N 次」', '30'),
            input('notify_attack_burst', '攻击突发阈值（次）', '同一 IP 在窗口内被拦这么多次才告警', '50'),
            input('notify_attack_window', '突发统计窗口（分钟）', '', '10'),
            sw('notify_ban', 'IP 被自动封禁时通知', '', '1'),
            sw('notify_cc', 'CC 攻击时通知', '', '1'),
            sw('notify_overload', '站点过载时通知', '', '1'),
            sw('notify_admin_new_country', '后台异地登录时通知', '后台在从未出现过的国家登录时提醒', '1'),
            sw('notify_geo_fail', 'IP 库更新失败时通知', '连续失败 4 次才发，不会因为网络抖动就吵你', '1'),
            sw('notify_geo_ok', 'IP 库更新成功时通知', '默认关', '0'),
            sw('notify_digest', '每日统计摘要', '默认关', '0')
        ]
    };

    /* ── 高级 ─────────────────────────────────────────────── */
    var advanced = {
        name: T('高级'),
        form: [
            sw('emergency_off', '紧急停用全部防护', '出问题时的一键刹车。后台都进不去时，改为在服务器创建 runtime/plugin/WebsiteMonitor/DISABLED 空文件。', '0'),
            input('block_status_code', '拦截时的状态码', '默认 403', '403'),
            input('block_page_title', '拦截页标题', '留空用「安全防护」', ''),
            input('block_page_contact', '申诉联系方式', '会显示在拦截页上，方便被误伤的用户找到你', '', 'admin@example.com'),
            select('log_level', '日志级别', {
                debug: 'DEBUG', info: 'INFO', warn: 'WARN', error: 'ERROR'
            }, '', 'info'),
            sw('broadcast', '向其它插件广播事件', '通过钩子 0x7C101 / 0x7C102 广播拦截与封禁事件', '1'),
            sw('acl_enabled', '启用访问控制', '关掉后黑白名单与地区规则都不生效', '1'),
            //acl_version 是程序自增的编译版本号，必须原样带回，否则保存后会被重置成默认值
            { title: false, name: 'acl_version', type: 'input', hide: true, default: cfg('acl_version', '1') }
        ]
    };

    return [overview, collect, storage, waf, cc, ban, geo, notify, advanced];
})();
