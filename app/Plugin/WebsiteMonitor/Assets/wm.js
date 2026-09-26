/*
 * 网站监控统计 —— 面板逻辑
 *
 * 三件必须做对的事：
 *  1. 标签页懒加载：切到哪个才拉哪个的数据，切走就 dispose 图表
 *  2. 主题联动：ECharts 不认 CSS 变量，得在 JS 里读令牌值，
 *     并监听 html[data-theme] 的变化重建 option
 *  3. pjax 拆卸：后台是单页架构，切页时必须清掉所有定时器、图表实例、
 *     观察器与未完成的请求，否则切几次之后浏览器就开始卡
 */
(function () {
    'use strict';

    var API = function (p) { return '/plugin/WebsiteMonitor/admin/' + p; };
    var $root = document.getElementById('wm-root');
    if (!$root) { return; }

    /* ───────────────────────── 生命周期 ───────────────────────── */

    var wm = {
        timers: [],
        charts: [],
        observers: [],
        aborts: [],
        destroyed: false
    };

    wm.every = function (fn, ms) {
        var id = setInterval(function () { if (!wm.destroyed) { fn(); } }, ms);
        wm.timers.push(id);
        return id;
    };
    wm.after = function (fn, ms) {
        var id = setTimeout(function () { if (!wm.destroyed) { fn(); } }, ms);
        wm.timers.push(id);
        return id;
    };

    function teardown() {
        if (wm.destroyed) { return; }
        wm.destroyed = true;
        wm.timers.forEach(function (id) { clearInterval(id); clearTimeout(id); });
        wm.charts.forEach(function (c) { try { c.dispose(); } catch (e) {} });
        wm.observers.forEach(function (o) { try { o.disconnect(); } catch (e) {} });
        wm.aborts.forEach(function (a) { try { a.abort(); } catch (e) {} });
        window.removeEventListener('resize', onResize);
        document.removeEventListener('visibilitychange', onVisibility);
        $(document).off('.wm');
        wm.timers = []; wm.charts = []; wm.observers = []; wm.aborts = [];
    }
    // admin:page:destroy 与 pjax:beforeReplace 同时触发，挂哪个都行，两个都挂更保险
    $(document).one('admin:page:destroy.wm pjax:beforeReplace.wm', teardown);
    window.addEventListener('beforeunload', teardown, { once: true });

    /* ───────────────────────── 工具 ───────────────────────── */

    var T = (typeof i18n === 'function') ? i18n : function (s) { return s; };

    function post(path, data, done, fail) {
        if (wm.destroyed) { return; }
        util.post({
            url: API(path),
            data: data || {},
            loader: false,
            done: function (res) { if (!wm.destroyed) { done && done(res.data || {}, res); } },
            error: function (res) {
                if (wm.destroyed) { return; }
                if (fail) { fail(res); } else { message.error((res && res.msg) || T('请求失败')); }
            },
            fail: function () {
                if (wm.destroyed) { return; }
                if (fail) { fail(null); } else { message.error(T('网络异常，请稍后重试')); }
            }
        });
    }

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function num(n) {
        n = Number(n) || 0;
        if (n >= 100000000) { return (n / 100000000).toFixed(2) + T('亿'); }
        if (n >= 10000) { return (n / 10000).toFixed(n >= 100000 ? 1 : 2) + T('万'); }
        return String(n);
    }

    function dur(sec) {
        sec = Math.max(0, Number(sec) || 0);
        if (sec < 60) { return sec + T(' 秒'); }
        if (sec < 3600) { return Math.floor(sec / 60) + T(' 分') + (sec % 60 ? (sec % 60) + T(' 秒') : ''); }
        if (sec < 86400) { return (sec / 3600).toFixed(1) + T(' 小时'); }
        return (sec / 86400).toFixed(1) + T(' 天');
    }

    function bytes(n) {
        n = Number(n) || 0;
        if (n >= 1073741824) { return (n / 1073741824).toFixed(2) + ' GB'; }
        if (n >= 1048576) { return (n / 1048576).toFixed(1) + ' MB'; }
        if (n >= 1024) { return (n / 1024).toFixed(1) + ' KB'; }
        return n + ' B';
    }

    function ago(ts) {
        if (!ts) { return '—'; }
        var d = Math.max(0, Math.floor(Date.now() / 1000) - Number(ts));
        if (d < 60) { return T('刚刚'); }
        if (d < 3600) { return Math.floor(d / 60) + T(' 分钟前'); }
        if (d < 86400) { return Math.floor(d / 3600) + T(' 小时前'); }
        return Math.floor(d / 86400) + T(' 天前');
    }

    function el(id) { return document.getElementById(id); }
    function html(id, content) { var n = el(id); if (n) { n.innerHTML = content; } }
    function text(id, content) { var n = el(id); if (n) { n.textContent = content; } }

    function empty(msg, icon) {
        return '<div class="wm-empty"><span class="wm-empty__icon">' + (icon || '&#128202;') + '</span>' + esc(msg) + '</div>';
    }

    function skeleton(rows) {
        var s = '<div style="padding:16px 18px">';
        for (var i = 0; i < (rows || 4); i++) {
            s += '<div class="wm-skeleton" style="width:' + (60 + Math.random() * 40) + '%"></div>';
        }
        return s + '</div>';
    }

    /* ───────────────────────── 主题令牌 ───────────────────────── */

    var tokenCache = {};
    function token(name) {
        if (tokenCache[name] !== undefined) { return tokenCache[name]; }
        var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        tokenCache[name] = v;
        return v;
    }
    function rgba(name, alpha) {
        var v = token(name + '-rgb') || '25,118,210';
        return 'rgba(' + v + ',' + alpha + ')';
    }

    // 主题切换后令牌值全变了，缓存必须失效并重建所有图表
    var themeObserver = new MutationObserver(function () {
        tokenCache = {};
        wm.charts.forEach(function (c) {
            if (c && c.__wmOption) {
                try { c.setOption(c.__wmOption(), true); } catch (e) {}
            }
        });
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    wm.observers.push(themeObserver);

    /* ───────────────────────── 图表工厂 ───────────────────────── */

    function chart(id, optionFn) {
        var node = el(id);
        if (!node || typeof echarts === 'undefined') { return null; }
        var inst = echarts.getInstanceByDom(node) || echarts.init(node);
        inst.__wmOption = optionFn;
        inst.setOption(optionFn(), true);
        if (wm.charts.indexOf(inst) === -1) { wm.charts.push(inst); }
        return inst;
    }

    function onResize() {
        wm.charts.forEach(function (c) { try { c.resize(); } catch (e) {} });
    }
    window.addEventListener('resize', onResize);

    function axisBase() {
        return {
            grid: { left: 8, right: 12, top: 30, bottom: 4, containLabel: true },
            tooltip: {
                trigger: 'axis',
                backgroundColor: token('--md-surface'),
                borderColor: token('--md-divider'),
                textStyle: { color: token('--md-on-surface'), fontSize: 12 },
                extraCssText: 'box-shadow:' + (token('--md-e8') || '0 6px 18px rgba(0,0,0,.14)') + ';border-radius:10px;'
            },
            legend: {
                top: 0, right: 0, itemWidth: 10, itemHeight: 10, itemGap: 14,
                textStyle: { color: token('--md-on-surface-med'), fontSize: 11 }
            }
        };
    }

    function catAxis(data, interval) {
        return {
            type: 'category', boundaryGap: false, data: data,
            axisLine: { lineStyle: { color: token('--md-divider') } },
            axisTick: { show: false },
            axisLabel: { color: token('--md-on-surface-dis'), fontSize: 10, interval: interval == null ? 'auto' : interval }
        };
    }

    function valAxis(extra) {
        return Object.assign({
            type: 'value', minInterval: 1,
            splitLine: { lineStyle: { color: token('--md-divider'), type: 'dashed' } },
            axisLabel: { color: token('--md-on-surface-dis'), fontSize: 10 }
        }, extra || {});
    }

    function areaSeries(name, data, colorToken) {
        return {
            name: name, type: 'line', smooth: 0.28, showSymbol: false, data: data,
            lineStyle: { width: 2, color: token(colorToken) },
            itemStyle: { color: token(colorToken) },
            areaStyle: {
                color: {
                    type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                    colorStops: [
                        { offset: 0, color: rgba(colorToken, 0.26) },
                        { offset: 1, color: rgba(colorToken, 0) }
                    ]
                }
            }
        };
    }

    /* 迷你趋势线（卡片上那条） */
    function sparkline(node, values, colorToken) {
        if (!node) { return; }
        values = values || [];
        if (values.length < 2) { node.innerHTML = ''; return; }
        var w = 100, h = 18, max = Math.max.apply(null, values) || 1, min = Math.min.apply(null, values);
        var span = (max - min) || 1;
        var pts = values.map(function (v, i) {
            return (i / (values.length - 1) * w).toFixed(1) + ',' + (h - ((v - min) / span) * (h - 3) - 1.5).toFixed(1);
        });
        var color = token(colorToken || '--md-primary');
        node.innerHTML =
            '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" style="width:100%;height:100%;display:block">' +
            '<polyline fill="none" stroke="' + color + '" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" points="' + pts.join(' ') + '"/>' +
            '</svg>';
    }

    /* ───────────────────────── 数字滚动 ───────────────────────── */

    function countUp(node, target) {
        if (!node) { return; }
        var from = Number(node.getAttribute('data-v') || 0);
        target = Number(target) || 0;
        node.setAttribute('data-v', target);
        if (from === target) { node.textContent = num(target); return; }
        if (Math.abs(target - from) > 5000 || document.hidden) {
            node.textContent = num(target);
            return;
        }
        var start = performance.now(), span = 260;
        (function step(now) {
            if (wm.destroyed) { return; }
            var p = Math.min(1, (now - start) / span);
            node.textContent = num(Math.round(from + (target - from) * (1 - Math.pow(1 - p, 3))));
            if (p < 1) { requestAnimationFrame(step); }
        })(start);
    }

    /* ───────────────────────── 全局状态 ───────────────────────── */

    var state = {
        tab: 'realtime',
        range: localStorage.getItem('wm-range') || 'today',
        from: '',
        to: '',
        runtime: {},
        streamAfter: 0,
        streamFilter: 'all',
        streamPaused: false,
        loaded: {}
    };

    function rangeParams(extra) {
        return Object.assign({ range: state.range, from: state.from, to: state.to }, extra || {});
    }

    /* ───────────────────────── 运行状态条 ───────────────────────── */

    function loadRuntime() {
        post('runtime', {}, function (d) {
            state.runtime = d;
            renderNotices(d);
            renderModeBar(d);
        });
    }

    function renderNotices(d) {
        var out = [];

        if (d.disabled_file) {
            out.push(note('error', T('防护已被紧急停用文件关闭。删除该文件后恢复：') +
                '<code>' + esc(d.rescue_path) + '</code>',
                '<button class="wm-btn wm-btn--sm" data-act="emergency-off">' + T('立即恢复') + '</button>'));
        } else if (d.waf_mode === 'observe') {
            out.push(note('info',
                T('当前是观察模式：只记录不拦截。确认没有误伤后，到「安全」页切换到拦截模式。'),
                '<button class="wm-btn wm-btn--sm wm-btn--primary" data-act="goto-security">' + T('去查看') + '</button>'));
        }

        if (d.daemon && d.daemon.hint) {
            out.push(note('warn', esc(d.daemon.hint)));
        }
        if (d.client_mode && d.client_mode.warn) {
            out.push(note('error', esc(d.client_mode.warn)));
        }
        if (d.geo && !d.geo.ready) {
            out.push(note('warn', T('IP 地理库尚未就绪：地区统计与地区封锁不可用。') +
                (d.geo.error ? ' <span class="wm-sub">' + esc(d.geo.error) + '</span>' : ''),
                '<button class="wm-btn wm-btn--sm wm-btn--primary" data-act="geo-update">' + T('立即下载') + '</button>'));
        }
        if (d.spool && d.spool.bad > 0) {
            out.push(note('warn', T('有 :n 个采集文件反复入库失败，已被隔离，详见插件日志。').replace(':n', d.spool.bad)));
        }
        html('wm-notices', out.join(''));
    }

    function note(kind, body, action) {
        return '<div class="wm-note wm-note--' + kind + '"><div>' + body + '</div>' +
            '<div class="wm-note__spacer"></div>' + (action || '') + '</div>';
    }

    function renderModeBar(d) {
        var modes = [
            ['observe', T('观察'), T('只记录不拦截')],
            ['protect', T('拦截'), T('按规则拦截并封禁')],
            ['strict', T('严格'), T('更激进，误伤风险更高')]
        ];
        html('wm-mode', modes.map(function (m) {
            return '<button data-mode="' + m[0] + '" class="' + (d.waf_mode === m[0] ? 'on' : '') + '" title="' + esc(m[2]) + '">' + m[1] + '</button>';
        }).join(''));
    }

    /* ───────────────────────── Hero ───────────────────────── */

    var METRICS = [
        { key: 'pv', label: '浏览量 PV', spark: 'pv' },
        { key: 'uv', label: '独立访客 UV', spark: 'uv' },
        { key: 'visits', label: '访问次数', spark: null },
        { key: 'bounce_rate', label: '跳出率', suffix: '%', inverse: true },
        { key: 'avg_stay', label: '平均停留', fmt: dur },
        { key: 'attack', label: '拦截 / 攻击', spark: 'attack', inverse: true }
    ];

    function loadOverview() {
        post('overview', rangeParams(), function (d) {
            var cur = d.current || {}, delta = d.delta || {}, spark = d.spark || {};
            var out = METRICS.map(function (m) {
                var v = cur[m.key] || 0;
                var shown = m.fmt ? m.fmt(v) : (num(v) + (m.suffix || ''));
                return '<div class="wm-metric' + (m.inverse ? ' wm-metric--inverse' : '') + '">' +
                    '<span class="wm-metric__label">' + T(m.label) + '</span>' +
                    '<span class="wm-metric__value num" data-metric="' + m.key + '">' + esc(shown) + '</span>' +
                    '<span class="wm-metric__foot">' + deltaTag(delta[m.key]) +
                    '<span class="wm-metric__spark" data-spark="' + (m.spark || '') + '"></span></span>' +
                    '</div>';
            }).join('');
            html('wm-metrics', out);

            METRICS.forEach(function (m) {
                if (!m.spark) { return; }
                var node = $root.querySelector('[data-spark="' + m.spark + '"]');
                sparkline(node, spark[m.spark], m.key === 'attack' ? '--md-error' : '--md-primary');
            });
            text('wm-range-label', d.range_text || '');
        });
    }

    function deltaTag(v) {
        if (v == null) { return '<span class="wm-delta wm-delta--flat">—</span>'; }
        v = Number(v);
        if (!v) { return '<span class="wm-delta wm-delta--flat">' + T('持平') + '</span>'; }
        var cls = v > 0 ? 'up' : 'down';
        return '<span class="wm-delta wm-delta--' + cls + '">' + (v > 0 ? '↑' : '↓') + Math.abs(v) + '%</span>';
    }

    /* ───────────────────────── 实时 ───────────────────────── */

    function loadRealtime() {
        post('realtime', { after: state.streamAfter, filter: state.streamFilter }, function (d) {
            var online = d.online || {};
            countUp(el('wm-online'), online.total);
            text('wm-online-meta', T('会员 :m · 蜘蛛 :b · 窗口 :w')
                .replace(':m', online.members || 0)
                .replace(':b', online.bots || 0)
                .replace(':w', dur(online.window || 300)));
            var dot = el('wm-online-dot');
            if (dot) { dot.className = 'wm-pulse' + (online.total > 0 ? '' : ' wm-pulse--idle'); }

            var ov = d.overload || {};
            var badge = el('wm-overload');
            if (badge) {
                badge.innerHTML = ov.on
                    ? '<span class="wm-tag wm-tag--error">' + T('过载保护中') + ' · ' + (ov.qps || 0) + ' QPS</span>'
                    : '<span class="wm-tag">' + (ov.qps || 0) + ' QPS</span>';
            }

            renderMinuteChart(d.minutes || {});
            appendStream(d.stream || []);
            renderRealtimeTop(d.top || {});
            renderOnlineVisitors(d.visitors || []);
        });
    }

    function renderMinuteChart(m) {
        chart('wm-chart-realtime', function () {
            var base = axisBase();
            return {
                grid: base.grid, tooltip: base.tooltip, legend: base.legend,
                xAxis: catAxis(m.axis || [], 9),
                yAxis: [valAxis(), valAxis({ show: false })],
                series: [
                    areaSeries(T('浏览量'), (m.series || {}).pv || [], '--md-primary'),
                    {
                        name: T('访客'), type: 'line', smooth: 0.28, showSymbol: false,
                        data: (m.series || {}).uv || [],
                        lineStyle: { width: 1.8, type: 'dashed', color: token('--md-secondary') },
                        itemStyle: { color: token('--md-secondary') }
                    },
                    {
                        name: T('攻击'), type: 'bar', yAxisIndex: 1, barWidth: 3,
                        data: (m.series || {}).attack || [],
                        itemStyle: { color: token('--md-error'), borderRadius: [2, 2, 0, 0] }
                    }
                ],
                animationDuration: 260, animationEasing: 'cubicOut'
            };
        });
    }

    function appendStream(rows) {
        if (!rows.length) { return; }
        var box = el('wm-stream');
        if (!box || state.streamPaused) {
            if (rows.length) { state.streamAfter = rows[rows.length - 1].id; }
            return;
        }
        var placeholder = box.querySelector('.wm-empty');
        if (placeholder) { placeholder.remove(); }

        var frag = '';
        rows.forEach(function (r) {
            state.streamAfter = Math.max(state.streamAfter, r.id);
            var cls = r.attack ? 'wm-stream__row--atk' : (r.status >= 400 ? 'wm-stream__row--err' : 'wm-stream__row--ok');
            frag = '<div class="wm-stream__row wm-stream__row--new ' + cls + '" data-vid="' + esc(r.vid) + '" data-ip="' + esc(r.ip) + '">' +
                '<span class="wm-stream__time">' + esc(r.time) + '</span>' +
                '<span class="wm-stream__ip">' + esc(r.ip) + '</span>' +
                '<span class="wm-stream__region">' + esc(r.spider || r.region || '') + '</span>' +
                '<span class="wm-stream__method">' + esc(r.method) + '</span>' +
                '<span class="wm-stream__path">' + esc(r.path || '/') + (r.query ? '<span class="wm-sub">?' + esc(r.query) + '</span>' : '') + '</span>' +
                '<span class="wm-stream__status">' + r.status + '</span>' +
                '<span class="wm-stream__ms">' + r.ms + 'ms</span>' +
                '</div>' + frag;
        });
        box.insertAdjacentHTML('afterbegin', frag);

        // 只留最近 200 行，否则页面开久了 DOM 会越堆越大
        var all = box.querySelectorAll('.wm-stream__row');
        for (var i = 200; i < all.length; i++) { all[i].remove(); }
    }

    function renderRealtimeTop(top) {
        [['pages', 'wm-top-pages', T('暂无页面数据')],
         ['referers', 'wm-top-refs', T('暂无来源数据')],
         ['regions', 'wm-top-regions', T('暂无地区数据')]].forEach(function (t) {
            var rows = top[t[0]] || [];
            if (!rows.length) { html(t[1], empty(t[2])); return; }
            html(t[1], rows.map(function (r) {
                return '<div style="padding:9px 16px;border-bottom:1px solid var(--md-divider)">' +
                    '<div style="display:flex;gap:10px;align-items:baseline">' +
                    '<span class="wm-clip" style="flex:1 1 auto">' + esc(r.name) + '</span>' +
                    '<span class="num" style="font-weight:600">' + num(r.pv || r.visits) + '</span></div>' +
                    '<div class="wm-bar"><i style="width:' + Math.min(100, r.percent || 0) + '%"></i></div>' +
                    '</div>';
            }).join(''));
        });
    }

    function renderOnlineVisitors(rows) {
        if (!rows.length) { html('wm-online-list', empty(T('当前没有在线访客'), '&#128100;')); return; }
        html('wm-online-list', rows.map(function (r) {
            return '<div style="padding:10px 16px;border-bottom:1px solid var(--md-divider);cursor:pointer" data-vid="' + esc(r.vid) + '">' +
                '<div style="display:flex;gap:8px;align-items:center">' +
                '<span class="wm-tag' + (r.spider ? '' : ' wm-tag--primary') + '">' + esc(r.spider || r.region || T('未知')) + '</span>' +
                '<span class="wm-sub num">' + esc(r.ip) + '</span>' +
                '<span style="flex:1 1 auto"></span>' +
                '<span class="wm-sub num">' + r.pv + ' ' + T('页') + '</span></div>' +
                '<div class="wm-clip wm-sub" style="margin-top:3px">' + esc(r.path || '/') + '</div>' +
                '<div class="wm-sub" style="margin-top:2px">' + T('停留 :s · :i 前活跃').replace(':s', dur(r.stay)).replace(':i', dur(r.idle)) + '</div>' +
                '</div>';
        }).join(''));
    }

    /* ───────────────────────── 趋势 ───────────────────────── */

    function loadTrend() {
        html('wm-trend-wrap', '<div class="wm-chart"></div>');
        post('trend', rangeParams({ compare: 1 }), function (d) {
            html('wm-trend-wrap', '<div id="wm-chart-trend" class="wm-chart wm-chart--lg"></div>');
            var s = d.series || {}, prev = d.previous || {};
            chart('wm-chart-trend', function () {
                var base = axisBase();
                return {
                    grid: base.grid, tooltip: base.tooltip, legend: base.legend,
                    dataZoom: [{ type: 'inside' }],
                    xAxis: catAxis(d.axis || []),
                    yAxis: valAxis(),
                    series: [
                        areaSeries(T('浏览量'), s.pv || [], '--md-primary'),
                        {
                            name: T('访客'), type: 'line', smooth: 0.28, showSymbol: false, data: s.uv || [],
                            lineStyle: { width: 2, color: token('--md-secondary') }, itemStyle: { color: token('--md-secondary') }
                        },
                        {
                            name: T('访问次数'), type: 'line', smooth: 0.28, showSymbol: false, data: s.visits || [],
                            lineStyle: { width: 1.6, color: token('--md-info') }, itemStyle: { color: token('--md-info') }
                        },
                        {
                            name: T('上一周期'), type: 'line', smooth: 0.28, showSymbol: false, data: prev.pv || [],
                            lineStyle: { width: 1.4, type: 'dotted', color: token('--md-on-surface-dis') },
                            itemStyle: { color: token('--md-on-surface-dis') }
                        }
                    ]
                };
            });
            renderHeatmap(d.heatmap || {});
        });
    }

    function renderHeatmap(hm) {
        if (!hm.cells || !hm.cells.length) { html('wm-heatmap-wrap', empty(T('暂无数据'))); return; }
        html('wm-heatmap-wrap', '<div id="wm-chart-heatmap" class="wm-chart"></div>');
        var weeks = [T('周一'), T('周二'), T('周三'), T('周四'), T('周五'), T('周六'), T('周日')];
        var hours = []; for (var i = 0; i < 24; i++) { hours.push(i + ':00'); }
        chart('wm-chart-heatmap', function () {
            return {
                grid: { left: 8, right: 12, top: 12, bottom: 46, containLabel: true },
                tooltip: {
                    backgroundColor: token('--md-surface'), borderColor: token('--md-divider'),
                    textStyle: { color: token('--md-on-surface'), fontSize: 12 },
                    formatter: function (p) {
                        return weeks[p.value[1]] + ' ' + hours[p.value[0]] + '<br/>' + T('浏览量') + ' ' + p.value[2];
                    }
                },
                xAxis: { type: 'category', data: hours, splitArea: { show: true }, axisLabel: { color: token('--md-on-surface-dis'), fontSize: 10, interval: 1 }, axisLine: { lineStyle: { color: token('--md-divider') } } },
                yAxis: { type: 'category', data: weeks, splitArea: { show: true }, axisLabel: { color: token('--md-on-surface-dis'), fontSize: 10 }, axisLine: { lineStyle: { color: token('--md-divider') } } },
                visualMap: {
                    min: 0, max: hm.max || 1, calculable: true, orient: 'horizontal',
                    left: 'center', bottom: 0, itemHeight: 90,
                    inRange: { color: [rgba('--md-primary', 0.08), token('--md-primary')] },
                    textStyle: { color: token('--md-on-surface-med'), fontSize: 10 }
                },
                series: [{
                    type: 'heatmap', data: hm.cells,
                    itemStyle: { borderRadius: 3, borderWidth: 2, borderColor: token('--md-surface') },
                    emphasis: { itemStyle: { shadowBlur: 8, shadowColor: rgba('--md-primary', 0.4) } }
                }]
            };
        });
    }

    /* ───────────────────────── 通用排行渲染 ───────────────────────── */

    function rankTable(rows, cols, emptyMsg) {
        if (!rows || !rows.length) { return empty(emptyMsg || T('暂无数据')); }
        var head = cols.map(function (c) { return '<th class="' + (c.right ? 'right' : '') + '">' + T(c.title) + '</th>'; }).join('');
        var body = rows.map(function (r) {
            return '<tr>' + cols.map(function (c) {
                return '<td class="' + (c.right ? 'right num' : '') + '">' + c.render(r) + '</td>';
            }).join('') + '</tr>';
        }).join('');
        return '<div class="wm-scroll"><table class="wm-table"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>';
    }

    function nameCell(r) {
        //副标题与主标题一样时不重复显示（来源域名、部分搜索引擎会撞）
        var extra = r.extra || '';
        if (extra && extra.toLowerCase() === String(r.name || '').toLowerCase()) {
            extra = '';
        }
        return '<span class="wm-clip" title="' + esc(r.name) + '">' + esc(r.name) + '</span>' +
            (extra ? '<span class="wm-sub">' + esc(extra) + '</span>' : '') +
            '<div class="wm-bar"><i style="width:' + Math.min(100, r.percent || 0) + '%"></i></div>';
    }

    var COL = {
        name: { title: '名称', render: nameCell },
        pv: { title: '浏览量', right: true, render: function (r) { return num(r.pv); } },
        visits: { title: '访问次数', right: true, render: function (r) { return num(r.visits); } },
        uv: { title: '访客', right: true, render: function (r) { return num(r.uv); } },
        bounce: { title: '跳出率', right: true, render: function (r) { return (r.bounce_rate || 0) + '%'; } },
        stay: { title: '平均停留', right: true, render: function (r) { return dur(r.avg_stay); } },
        ms: { title: '平均耗时', right: true, render: function (r) { return (r.avg_ms || 0) + ' ms'; } }
    };

    /* ───────────────────────── 来源 ───────────────────────── */

    function loadSources() {
        html('wm-src-types', skeleton(3));
        post('sources', rangeParams(), function (d) {
            chartPie('wm-chart-src-type', d.types || [], 'visits');
            html('wm-src-platform', rankTable(d.platforms, [COL.name, COL.visits, COL.uv, COL.bounce], T('还没有来自搜索引擎或社交平台的访问')));
            html('wm-src-host', rankTable(d.hosts, [COL.name, COL.visits, COL.uv, COL.bounce], T('还没有外部来源')));
            html('wm-src-url', rankTable(d.urls, [COL.name, COL.pv], T('还没有来源网址')));
            html('wm-src-keyword', rankTable(d.keywords, [COL.name, COL.pv],
                T('还没有可解析的搜索词。主流搜索引擎早已对来源加密，这一项通常只有零星数据，属正常现象。')));
            var utm = d.utm || {};
            html('wm-src-utm', rankTable(utm.source, [COL.name, COL.visits, COL.uv], T('还没有带 UTM 参数的访问')));
        });
    }

    function chartPie(id, rows, field) {
        var wrap = el(id);
        if (!wrap) { return; }
        if (!rows || !rows.length) { wrap.innerHTML = ''; wrap.parentNode.innerHTML = empty(T('暂无数据')); return; }
        var palette = ['--md-primary', '--md-info', '--md-success', '--md-warning', '--md-secondary', '--md-error'];
        chart(id, function () {
            var total = rows.reduce(function (s, r) { return s + (r[field] || 0); }, 0);
            return {
                tooltip: {
                    trigger: 'item', backgroundColor: token('--md-surface'), borderColor: token('--md-divider'),
                    textStyle: { color: token('--md-on-surface'), fontSize: 12 }
                },
                legend: { bottom: 0, itemWidth: 9, itemHeight: 9, textStyle: { color: token('--md-on-surface-med'), fontSize: 11 } },
                series: [{
                    type: 'pie', radius: ['58%', '80%'], center: ['50%', '44%'], avoidLabelOverlap: true,
                    itemStyle: { borderColor: token('--md-surface'), borderWidth: 2, borderRadius: 4 },
                    label: {
                        show: true, position: 'center', formatter: function () { return num(total) + '\n' + T('合计'); },
                        color: token('--md-on-surface'), fontSize: 15, lineHeight: 20, fontWeight: 600
                    },
                    emphasis: { label: { show: true, fontSize: 15, formatter: '{b}\n{c}' } },
                    labelLine: { show: false },
                    data: rows.map(function (r, i) {
                        return {
                            name: r.name, value: r[field] || 0,
                            itemStyle: { color: token(palette[i % palette.length]) }
                        };
                    })
                }]
            };
        });
    }

    /* ───────────────────────── 页面 ───────────────────────── */

    function loadPages() {
        html('wm-page-list', skeleton(5));
        post('pages', rangeParams(), function (d) {
            html('wm-page-list', rankTable(d.pages, [COL.name, COL.pv, COL.uv, COL.ms], T('还没有页面访问数据')));
            html('wm-page-landing', rankTable(d.landing, [COL.name, COL.visits, COL.bounce, COL.stay], T('暂无着陆页数据')));
            html('wm-page-exit', rankTable(d.exit, [COL.name, COL.visits], T('暂无退出页数据')));
            html('wm-page-404', rankTable((d.notfound || []).map(function (r) {
                return { name: r.path, pv: r.count, percent: 0, extra: ago(r.last_ts) };
            }), [COL.name, COL.pv], T('太好了，这段时间没有 404')));
            html('wm-page-slow', rankTable((d.slow || []).map(function (r) {
                return { name: r.path, pv: r.pv, avg_ms: r.avg_ms, percent: 0 };
            }), [COL.name, COL.pv, COL.ms], T('暂无慢页面')));
            renderTreemap(d.pages || []);
        });
    }

    function renderTreemap(rows) {
        if (!rows.length) { html('wm-page-treemap-wrap', empty(T('暂无数据'))); return; }
        html('wm-page-treemap-wrap', '<div id="wm-chart-treemap" class="wm-chart"></div>');
        chart('wm-chart-treemap', function () {
            return {
                tooltip: {
                    backgroundColor: token('--md-surface'), borderColor: token('--md-divider'),
                    textStyle: { color: token('--md-on-surface'), fontSize: 12 },
                    formatter: function (p) {
                        return esc(p.name) + '<br/>' + T('浏览量') + ' ' + p.value + '<br/>' + T('访客') + ' ' + (p.data.uv || 0);
                    }
                },
                series: [{
                    type: 'treemap', roam: false, nodeClick: false, breadcrumb: { show: false },
                    left: 0, right: 0, top: 0, bottom: 0,
                    itemStyle: { borderColor: token('--md-surface'), borderWidth: 2, gapWidth: 2 },
                    label: { fontSize: 11, color: '#fff', overflow: 'truncate' },
                    data: rows.slice(0, 40).map(function (r, i) {
                        // 面积 = 浏览量，颜色深浅 = 相对热度
                        var ratio = Math.min(1, (r.percent || 0) / 25);
                        return {
                            name: r.name, value: r.pv, uv: r.uv,
                            itemStyle: { color: rgba('--md-primary', 0.30 + ratio * 0.6) }
                        };
                    })
                }]
            };
        });
    }

    /* ───────────────────────── 地域 ───────────────────────── */

    function loadRegions() {
        html('wm-region-bar-wrap', skeleton(5));
        post('regions', rangeParams(), function (d) {
            var countries = d.countries || [];
            if (!countries.length) {
                html('wm-region-bar-wrap', empty(T('还没有地区数据。若刚装好插件，请先下载 IP 地理库。'), '&#127758;'));
                html('wm-region-table', '');
                return;
            }
            html('wm-region-bar-wrap', '<div id="wm-chart-region" class="wm-chart wm-chart--lg"></div>');
            var top = countries.slice(0, 16).reverse();
            chart('wm-chart-region', function () {
                return {
                    grid: { left: 8, right: 40, top: 10, bottom: 6, containLabel: true },
                    tooltip: {
                        trigger: 'axis', axisPointer: { type: 'shadow' },
                        backgroundColor: token('--md-surface'), borderColor: token('--md-divider'),
                        textStyle: { color: token('--md-on-surface'), fontSize: 12 }
                    },
                    xAxis: valAxis(),
                    yAxis: {
                        type: 'category', data: top.map(function (c) { return c.name || c.code; }),
                        axisLine: { lineStyle: { color: token('--md-divider') } }, axisTick: { show: false },
                        axisLabel: { color: token('--md-on-surface-med'), fontSize: 11 }
                    },
                    series: [{
                        //只有一两个地区时，百分比宽度会让柱子胖到铺满整张卡，加个绝对上限
                        type: 'bar', barWidth: '62%', barMaxWidth: 42,
                        data: top.map(function (c) { return c.visits; }),
                        itemStyle: { color: token('--md-primary'), borderRadius: [0, 4, 4, 0] },
                        label: { show: true, position: 'right', color: token('--md-on-surface-med'), fontSize: 11 }
                    }]
                };
            });
            html('wm-region-table', rankTable(d.detail, [COL.name, COL.visits, COL.uv, COL.bounce], T('暂无地区明细')));
        });
    }

    /* ───────────────────────── 访客 ───────────────────────── */

    var visitorPage = 1;

    function loadVisitors(page) {
        visitorPage = page || 1;
        html('wm-visitor-list', skeleton(6));
        var f = {};
        ['spider', 'is_new', 'dev', 'ref_type', 'ip'].forEach(function (k) {
            var n = el('wm-vf-' + k);
            if (n && n.value !== '') { f[k] = n.value; }
        });
        post('visitors', rangeParams(Object.assign({ page: visitorPage, limit: 20 }, f)), function (d) {
            var rows = d.list || [];
            if (!rows.length) { html('wm-visitor-list', empty(T('这个时间段没有访问记录'), '&#128100;')); return; }
            html('wm-visitor-list',
                '<div class="wm-scroll"><table class="wm-table"><thead><tr>' +
                '<th>' + T('开始时间') + '</th><th>' + T('来源') + '</th><th>' + T('入口页') + '</th>' +
                '<th>' + T('地区') + '</th><th>' + T('设备') + '</th>' +
                '<th class="right">' + T('页数') + '</th><th class="right">' + T('停留') + '</th><th>' + T('操作') + '</th>' +
                '</tr></thead><tbody>' + rows.map(function (r) {
                    return '<tr>' +
                        '<td class="num">' + esc(r.start) + (r.is_new ? ' <span class="wm-tag wm-tag--primary">' + T('新') + '</span>' : '') + '</td>' +
                        '<td>' + esc(r.ref_type_text) + (r.ref_host ? '<span class="wm-sub">' + esc(r.ref_host) + '</span>' : '') + '</td>' +
                        '<td><span class="wm-clip" style="max-width:220px">' + esc(r.entry || '/') + '</span></td>' +
                        '<td>' + esc(r.region || (r.spider ? r.spider : '—')) + '</td>' +
                        '<td>' + esc(r.dev_text) + '</td>' +
                        '<td class="right num">' + r.pv + (r.bounce ? ' <span class="wm-tag wm-tag--warn">' + T('跳出') + '</span>' : '') + '</td>' +
                        '<td class="right num">' + dur(r.stay) + '</td>' +
                        '<td><button class="wm-btn wm-btn--sm" data-trail="' + esc(r.sid) + '">' + T('足迹') + '</button></td>' +
                        '</tr>';
                }).join('') + '</tbody></table></div>' + pager(d.total, visitorPage, 20, 'visitor'));
        });
    }

    function pager(total, page, limit, tag) {
        var pages = Math.max(1, Math.ceil(total / limit));
        if (pages <= 1) { return '<div class="wm-pager">' + T('共 :n 条').replace(':n', total) + '</div>'; }
        return '<div class="wm-pager">' + T('共 :n 条').replace(':n', total) +
            ' · ' + page + '/' + pages +
            ' <button class="wm-btn wm-btn--sm" data-page="' + tag + ':' + Math.max(1, page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>' + T('上一页') + '</button>' +
            ' <button class="wm-btn wm-btn--sm" data-page="' + tag + ':' + Math.min(pages, page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>' + T('下一页') + '</button></div>';
    }

    function openTrail(sid) {
        openDrawer(T('访问足迹'), skeleton(6));
        post('trail', { sid: sid }, function (d) {
            var rows = d.trail || [];
            if (!rows.length) { drawerBody(empty(T('没有找到这次访问的明细。可能已超过明细保留期。'))); return; }
            drawerBody('<div class="wm-trail">' + rows.map(function (r) {
                return '<div class="wm-trail__item' + (r.status >= 400 ? ' wm-trail__item--err' : '') + '">' +
                    '<div class="wm-trail__path">' + esc(r.path || '/') + (r.query ? '<span class="wm-sub">?' + esc(r.query) + '</span>' : '') + '</div>' +
                    '<div class="wm-trail__meta">' + esc(r.time) + ' · ' + esc(r.method) + ' ' + r.status +
                    ' · ' + r.ms + 'ms' + (r.dwell ? ' · ' + T('停留 :s').replace(':s', dur(r.dwell)) : '') + '</div>' +
                    '</div>';
            }).join('') + '</div>');
        });
    }

    /* ───────────────────────── 蜘蛛 ───────────────────────── */

    function loadSpiders() {
        html('wm-spider-list', skeleton(5));
        post('spiders', rangeParams(), function (d) {
            var rows = d.list || [];
            if (!rows.length) {
                html('wm-spider-list', empty(T('这个时间段还没有蜘蛛抓取记录'), '&#128375;'));
            } else {
                html('wm-spider-list', rankTable(rows.map(function (r) {
                    return Object.assign({}, r, { extra: r.group_text });
                }), [COL.name, COL.pv, COL.ms], ''));
            }
            var fake = d.fake || [];
            html('wm-spider-fake', fake.length ? rankTable(fake.map(function (r) {
                return { name: r.ip, extra: T('伪装成 :s · 反解 :p').replace(':s', r.claimed || '?').replace(':p', r.ptr || T('无')), pv: 0, percent: 0 };
            }), [COL.name], '') : empty(T('没有发现伪装成搜索引擎的访问'), '&#9989;'));
        });
    }

    /* ───────────────────────── 安全 ───────────────────────── */

    var attackPage = 1;

    function loadSecurity(page) {
        attackPage = page || 1;
        html('wm-attack-list', skeleton(6));
        var f = {};
        ['kind', 'blocked', 'ip'].forEach(function (k) {
            var n = el('wm-af-' + k);
            if (n && n.value !== '') { f[k] = n.value; }
        });
        post('attacks', rangeParams(Object.assign({ page: attackPage, limit: 20 }, f)), function (d) {
            var s = d.summary || {};
            html('wm-attack-kinds', (s.kinds || []).length
                ? (s.kinds || []).map(function (k) {
                    return '<span class="wm-tag wm-tag--warn" style="margin:0 6px 6px 0">' + esc(k.kind_text) + ' ' + k.count + '</span>';
                }).join('')
                : '<span class="wm-sub">' + T('这段时间没有检测到攻击') + '</span>');

            html('wm-attack-top', (s.top || []).length ? rankTable((s.top || []).map(function (r) {
                return { name: r.ip, extra: T('最近 :t').replace(':t', ago(r.last_ts)), pv: r.count, percent: 0 };
            }).map(function (r, i) { return r; }), [COL.name, COL.pv], '') : empty(T('暂无攻击来源')));

            var rows = d.list || [];
            if (!rows.length) {
                html('wm-attack-list', empty(T('这个时间段没有安全事件'), '&#128737;'));
                return;
            }
            html('wm-attack-list',
                '<div class="wm-scroll"><table class="wm-table"><thead><tr>' +
                '<th>' + T('时间') + '</th><th>' + T('类型') + '</th><th>' + T('来源 IP') + '</th>' +
                '<th>' + T('规则') + '</th><th>' + T('路径') + '</th><th>' + T('处置') + '</th><th>' + T('操作') + '</th>' +
                '</tr></thead><tbody>' + rows.map(function (r) {
                    var lvl = r.level === 'critical' ? 'error' : (r.level === 'warn' ? 'warn' : 'info');
                    return '<tr>' +
                        '<td class="num">' + esc(r.time) + '</td>' +
                        '<td><span class="wm-tag wm-tag--' + lvl + '">' + esc(r.kind_text) + '</span></td>' +
                        '<td class="num">' + esc(r.ip) + '</td>' +
                        '<td><span class="wm-sub">' + esc(r.rule) + '</span></td>' +
                        '<td><span class="wm-clip" style="max-width:240px" title="' + esc(r.path) + '">' + esc(r.path) + '</span></td>' +
                        '<td>' + (r.blocked ? '<span class="wm-tag wm-tag--error">' + T('已拦截') + '</span>' : '<span class="wm-tag">' + esc(r.act_text) + '</span>') + '</td>' +
                        '<td><button class="wm-btn wm-btn--sm" data-detail="' + r.id + '">' + T('详情') + '</button>' +
                        ' <button class="wm-btn wm-btn--sm wm-btn--danger" data-ban="' + esc(r.ip) + '">' + T('封禁') + '</button></td>' +
                        '</tr>';
                }).join('') + '</tbody></table></div>' + pager(d.total, attackPage, 20, 'attack'));
            window.__wmAttacks = rows;
        });
    }

    function openAttackDetail(id) {
        var row = (window.__wmAttacks || []).filter(function (r) { return String(r.id) === String(id); })[0];
        if (!row) { return; }
        var ev = row.evidence || {};
        var kv = [
            [T('时间'), row.time], [T('来源 IP'), row.ip], [T('类型'), row.kind_text],
            [T('命中规则'), row.rule], [T('风险分'), row.score], [T('处置'), row.act_text],
            [T('请求编号'), row.req_id || '—'], [T('方法'), row.method], [T('状态码'), row.status],
            [T('路径'), row.path], [T('来源页'), row.ref || '—'], ['User-Agent', row.ua || '—']
        ];
        var body = '<dl class="wm-kv">' + kv.map(function (p) {
            return '<dt>' + esc(p[0]) + '</dt><dd>' + esc(p[1]) + '</dd>';
        }).join('') + '</dl>';

        if (Object.keys(ev).length) {
            body += '<div class="wm-card" style="margin-top:16px"><div class="wm-card__head">' +
                '<h3 class="wm-card__title">' + T('取证片段') + '</h3>' +
                '<span class="wm-card__sub">' + T('已脱敏') + '</span></div>' +
                '<div class="wm-card__body"><pre style="margin:0;white-space:pre-wrap;word-break:break-all;font-size:11.5px;color:var(--md-on-surface-med)">' +
                esc(JSON.stringify(ev, null, 2)) + '</pre></div></div>';
        }
        body += '<div style="margin-top:16px;display:flex;gap:8px">' +
            '<button class="wm-btn wm-btn--danger" data-ban="' + esc(row.ip) + '">' + T('封禁这个 IP') + '</button>' +
            '<button class="wm-btn" data-test-ip="' + esc(row.ip) + '">' + T('查这个 IP') + '</button></div>';
        openDrawer(T('安全事件详情'), body);
    }

    /* ───────────────────────── 规则 ───────────────────────── */

    function loadRules() {
        html('wm-rule-list', skeleton(6));
        post('rules', {}, function (d) {
            var groups = {};
            (d.catalog || []).forEach(function (r) {
                (groups[r.group] = groups[r.group] || []).push(r);
            });
            var out = Object.keys(groups).map(function (g) {
                return '<div class="wm-card"><div class="wm-card__head"><h3 class="wm-card__title">' + esc(groupName(g)) +
                    '</h3><span class="wm-card__sub">' + groups[g].length + ' ' + T('条') + '</span></div>' +
                    '<div class="wm-card__body wm-card__body--flush">' + groups[g].map(ruleRow).join('') + '</div></div>';
            }).join('');
            html('wm-rule-list', out || empty(T('没有规则')));
        });
    }

    function groupName(g) {
        return ({
            injection: T('SQL 注入与命令执行'), xss: T('跨站脚本'),
            traversal: T('路径穿越与文件包含'), scanner: T('扫描器探测'),
            protocol: T('UA、协议与上传')
        })[g] || g;
    }

    function ruleRow(r) {
        var fp = r.fp === 'high' ? '<span class="wm-tag wm-tag--warn">' + T('易误报') + '</span>'
            : (r.fp === 'medium' ? '<span class="wm-tag">' + T('偶有误报') + '</span>' : '');
        var actions = [['log', T('仅记录')], ['score', T('计分')], ['block', T('拦截')], ['ban', T('封禁')]];
        return '<div class="wm-rule">' +
            '<label class="wm-switch"><input type="checkbox" data-rule-toggle="' + esc(r.id) + '"' + (r.enabled ? ' checked' : '') + '><span></span></label>' +
            '<div class="wm-rule__main"><div class="wm-rule__name">' + esc(r.name) + ' ' + fp + '</div>' +
            '<div class="wm-rule__id">' + esc(r.id) + '</div></div>' +
            '<select class="wm-select" lay-ignore data-rule-action="' + esc(r.id) + '">' +
            actions.map(function (a) {
                return '<option value="' + a[0] + '"' + (r.action === a[0] ? ' selected' : '') + '>' + a[1] + '</option>';
            }).join('') + '</select></div>';
    }

    /* ───────────────────────── 访问控制 ───────────────────────── */

    var aclPage = 1, aclType = 1;

    function loadAcl(page) {
        aclPage = page || 1;
        html('wm-acl-list', skeleton(5));
        var kw = el('wm-acl-kw');
        post('aclList', { type: aclType, page: aclPage, limit: 20, keyword: kw ? kw.value : '' }, function (d) {
            var rows = d.list || [];
            if (!rows.length) { html('wm-acl-list', empty(T('这一类还没有规则'))); return; }
            html('wm-acl-list',
                '<div class="wm-scroll"><table class="wm-table"><thead><tr>' +
                '<th>' + T('规则') + '</th><th>' + T('备注') + '</th><th>' + T('来源') + '</th>' +
                '<th class="right">' + T('命中') + '</th><th>' + T('到期') + '</th><th>' + T('操作') + '</th>' +
                '</tr></thead><tbody>' + rows.map(function (r) {
                    return '<tr>' +
                        '<td class="num">' + esc(r.value) + (r.status ? '' : ' <span class="wm-tag">' + T('已停用') + '</span>') + '</td>' +
                        '<td><span class="wm-clip">' + esc(r.note || '—') + '</span></td>' +
                        '<td><span class="wm-sub">' + esc(r.source) + '</span></td>' +
                        '<td class="right num">' + num(r.hits) + '</td>' +
                        '<td class="wm-sub">' + (r.expire_at ? (r.expired ? T('已过期') : ago(r.expire_at)) : T('永久')) + '</td>' +
                        '<td><button class="wm-btn wm-btn--sm wm-btn--danger" data-acl-del="' + r.id + '">' + T('删除') + '</button></td>' +
                        '</tr>';
                }).join('') + '</tbody></table></div>' + pager(d.total, aclPage, 20, 'acl'));
        });
    }

    function loadBans(page) {
        html('wm-ban-list', skeleton(4));
        post('bans', { page: page || 1, limit: 20 }, function (d) {
            var rows = d.list || [];
            if (!rows.length) { html('wm-ban-list', empty(T('当前没有被自动封禁的 IP'), '&#9989;')); return; }
            html('wm-ban-list',
                '<div class="wm-scroll"><table class="wm-table"><thead><tr>' +
                '<th>IP</th><th>' + T('原因') + '</th><th>' + T('规则') + '</th>' +
                '<th class="right">' + T('级别') + '</th><th>' + T('剩余') + '</th><th>' + T('操作') + '</th>' +
                '</tr></thead><tbody>' + rows.map(function (r) {
                    return '<tr>' +
                        '<td class="num">' + esc(r.ip) + '</td>' +
                        '<td><span class="wm-clip">' + esc(r.reason || '—') + '</span></td>' +
                        '<td><span class="wm-sub">' + esc(r.rule) + '</span></td>' +
                        '<td class="right num">' + r.level + '</td>' +
                        '<td>' + (r.permanent ? '<span class="wm-tag wm-tag--error">' + T('永久') + '</span>' : dur(r.left)) + '</td>' +
                        '<td><button class="wm-btn wm-btn--sm" data-unban="' + esc(r.ip) + '">' + T('解封') + '</button></td>' +
                        '</tr>';
                }).join('') + '</tbody></table></div>');
        });
    }

    /* ───────────────────────── 设置 ───────────────────────── */

    function wafModeText(mode) {
        if (mode === 'protect') { return T('拦截模式'); }
        if (mode === 'strict') { return T('严格模式'); }
        return T('观察模式');
    }

    //内核 ip_get_mode 的取值就是这张表的下标，直接把请求头名字显示出来，比显示个数字有用
    var IP_MODE_HEADERS = ['REMOTE_ADDR', 'X-Real-IP', 'X-Forwarded-For', 'Client-IP', 'X-Forwarded',
        'X-Cluster-Client-IP', 'Forwarded-For', 'Forwarded', 'CF-Connecting-IP'];

    function clientModeText(cm) {
        var mode = +cm.mode || 0;
        var header = IP_MODE_HEADERS[mode] || ('#' + mode);
        //直连模式下本来就没有代理可信任，再提「未配置受信代理」纯属吓人
        if (mode === 0) {
            return T('直连') + '（' + header + '）';
        }
        return header + (cm.trusted_configured ? T('（已配置受信代理）') : T('（未配置受信代理）'));
    }

    function loadSettings() {
        var d = state.runtime;
        var geo = d.geo || {}, meta = geo.meta || {}, daemon = d.daemon || {};
        var rows = [
            [T('插件状态'), d.enabled ? T('运行中') : T('已停用')],
            [T('表结构版本'), 'v' + (d.schema_version || 0)],
            [T('防火墙模式'), wafModeText(d.waf_mode)],
            [T('采集模式'), d.collect_mode === 'lean' ? T('精简（流量过大，已自动降级）') : (d.collect_mode === 'off' ? T('已关闭') : T('完整'))],
            [T('线程管理器'), daemon.running ? (daemon.task_alive ? T('运行中，任务已注册') : T('运行中，但任务未注册')) : T('未运行')],
            [T('Redis 加速'), d.redis ? T('可用') : T('不可用（不影响功能）')],
            [T('通知中心'), (d.notify || {}).text || '—'],
            [T('规则版本'), 'v' + (d.rules_version || 0) + (d.rules_stale ? ' ' + T('（待重编译）') : '')],
            [T('封禁中的 IP'), num(d.bans || 0)],
            [T('客户端 IP 模式'), clientModeText(d.client_mode || {})],
            [T('本机识别到的你的 IP'), (d.client_mode || {}).resolved || '—'],
            [T('IP 地理库'), geo.ready ? (meta.database_type + ' · ' + T('构建于 ') + meta.build_date + ' · ' + bytes(geo.size)) : T('未就绪')],
            [T('采集积压'), (d.spool || {}).files + ' ' + T('个文件') + ' / ' + bytes((d.spool || {}).bytes)]
        ];
        html('wm-set-runtime', '<dl class="wm-kv">' + rows.map(function (p) {
            return '<dt>' + esc(p[0]) + '</dt><dd>' + esc(p[1]) + '</dd>';
        }).join('') + '</dl>');

        var st = d.storage || [];
        html('wm-set-storage', st.length ? rankTable(st.map(function (r) {
            return { name: r.table, pv: r.rows, extra: r.mb + ' MB', percent: 0 };
        }), [COL.name, { title: '行数', right: true, render: function (r) { return num(r.pv); } }], '') : empty(T('暂无数据')));
    }

    /* ───────────────────────── 抽屉 ───────────────────────── */

    function openDrawer(title, body) {
        var d = el('wm-drawer');
        if (!d) { return; }
        text('wm-drawer-title', title);
        html('wm-drawer-body', body);
        d.classList.add('wm-drawer--on');
    }
    function drawerBody(body) { html('wm-drawer-body', body); }
    function closeDrawer() {
        var d = el('wm-drawer');
        if (d) { d.classList.remove('wm-drawer--on'); }
    }

    /* ───────────────────────── 标签页 ───────────────────────── */

    var LOADERS = {
        realtime: function () { loadRealtime(); },
        trend: loadTrend,
        sources: loadSources,
        pages: loadPages,
        regions: loadRegions,
        visitors: function () { loadVisitors(1); },
        spiders: loadSpiders,
        security: function () { loadSecurity(1); },
        rules: function () { loadRules(); loadAcl(1); loadBans(1); },
        settings: loadSettings
    };

    function switchTab(tab) {
        if (!LOADERS[tab]) { return; }
        state.tab = tab;
        $root.querySelectorAll('.wm-tab').forEach(function (b) {
            b.classList.toggle('wm-tab--on', b.getAttribute('data-tab') === tab);
        });
        $root.querySelectorAll('.wm-pane').forEach(function (p) {
            p.classList.toggle('wm-pane--on', p.getAttribute('data-pane') === tab);
        });
        // 切进来才加载，切走的图表交给 resize 自适应；只有第一次进入才拉数据
        if (!state.loaded[tab]) {
            state.loaded[tab] = true;
            LOADERS[tab]();
        }
        wm.after(onResize, 60);
    }

    function reloadCurrent() {
        state.loaded = {};
        loadOverview();
        LOADERS[state.tab]();
    }

    /* ───────────────────────── 轮询 ───────────────────────── */

    function onVisibility() {
        // 页面被切到后台时，浏览器会把定时器压到 1 秒甚至 1 分钟一次，
        // 与其让它半死不活，不如显式降频，回来时立刻补一次
        if (!document.hidden && state.tab === 'realtime') { loadRealtime(); }
    }
    document.addEventListener('visibilitychange', onVisibility);

    wm.every(function () {
        if (state.tab !== 'realtime' || document.hidden) { return; }
        loadRealtime();
    }, 3000);

    wm.every(function () {
        if (document.hidden) { return; }
        loadRuntime();
    }, 30000);

    /* ───────────────────────── 事件 ───────────────────────── */

    $($root).on('click.wm', '[data-tab]', function () { switchTab(this.getAttribute('data-tab')); });

    $($root).on('click.wm', '[data-range]', function () {
        state.range = this.getAttribute('data-range');
        localStorage.setItem('wm-range', state.range);
        $root.querySelectorAll('[data-range]').forEach(function (b) {
            b.classList.toggle('on', b.getAttribute('data-range') === state.range);
        });
        reloadCurrent();
    });

    $($root).on('click.wm', '[data-mode]', function () {
        var mode = this.getAttribute('data-mode');
        if (mode === state.runtime.waf_mode) { return; }
        var go = function (confirm) {
            post('setMode', { mode: mode, confirm: confirm ? 1 : 0 }, function (_, res) {
                message.success(res.msg);
                loadRuntime();
            }, function (res) {
                if (!res || !res.msg) { message.error(T('切换失败')); return; }
                // 自检拦下来了：把风险说清楚再让站长决定
                layer.confirm(res.msg, { title: T('确认切换'), btn: [T('我已了解，继续'), T('取消')] }, function (idx) {
                    layer.close(idx);
                    go(true);
                });
            });
        };
        go(false);
    });

    $($root).on('click.wm', '[data-act]', function () {
        var act = this.getAttribute('data-act');
        if (act === 'goto-security') { switchTab('rules'); return; }
        if (act === 'geo-update') {
            message.success(T('开始更新 IP 库，可能需要几分钟'));
            post('geoUpdate', {}, function (_, res) { message.success(res.msg); loadRuntime(); });
            return;
        }
        if (act === 'emergency-off') {
            post('maintenance', { op: 'emergency_off' }, function (_, res) { message.success(res.msg); loadRuntime(); });
            return;
        }
        if (act === 'refresh') { reloadCurrent(); loadRuntime(); return; }
        if (act === 'stream-pause') {
            state.streamPaused = !state.streamPaused;
            this.textContent = state.streamPaused ? T('继续') : T('暂停');
            return;
        }
        if (act === 'stream-clear') { html('wm-stream', empty(T('等待新请求…'), '&#9203;')); return; }
    });

    $($root).on('change.wm', '[data-stream-filter]', function () {
        state.streamFilter = this.value;
        state.streamAfter = 0;
        html('wm-stream', empty(T('加载中…')));
        loadRealtime();
    });

    $($root).on('click.wm', '[data-op]', function () {
        var op = this.getAttribute('data-op');
        var btn = this;
        btn.disabled = true;
        post('maintenance', rangeParams({ op: op }), function (_, res) {
            btn.disabled = false;
            message.success(res.msg);
            loadRuntime();
        }, function (res) {
            btn.disabled = false;
            message.error((res && res.msg) || T('操作失败'));
        });
    });

    $($root).on('click.wm', '[data-trail]', function () { openTrail(this.getAttribute('data-trail')); });
    $($root).on('click.wm', '[data-detail]', function () { openAttackDetail(this.getAttribute('data-detail')); });
    $($root).on('click.wm', '[data-vid]', function () {
        var vid = this.getAttribute('data-vid');
        if (!vid) { return; }
        openDrawer(T('访客档案'), skeleton(6));
        post('visitorProfile', { vid: vid }, function (d) {
            if (!d.vid) { drawerBody(empty(T('找不到这个访客'))); return; }
            var kv = [
                [T('首次访问'), new Date(d.first_ts * 1000).toLocaleString()],
                [T('最近访问'), new Date(d.last_ts * 1000).toLocaleString()],
                [T('累计浏览'), num(d.pv)], [T('访问次数'), num(d.sessions)],
                [T('地区'), d.region || '—'], [T('最近 IP'), d.ip],
                [T('识别方式'), d.id_kind ? T('指纹（未使用 Cookie）') : T('Cookie')],
                ['User-Agent', d.ua || '—']
            ];
            drawerBody('<dl class="wm-kv">' + kv.map(function (p) {
                return '<dt>' + esc(p[0]) + '</dt><dd>' + esc(p[1]) + '</dd>';
            }).join('') + '</dl>' +
                '<h4 style="margin:18px 0 8px;font-size:13px">' + T('历史访问') + '</h4>' +
                (d.session_list || []).map(function (s) {
                    return '<div style="padding:8px 0;border-bottom:1px solid var(--md-divider)">' +
                        '<div style="display:flex;gap:8px"><span class="wm-clip" style="flex:1 1 auto">' + esc(s.entry || '/') + '</span>' +
                        '<span class="wm-sub num">' + s.pv + T(' 页') + '</span></div>' +
                        '<div class="wm-sub">' + esc(s.start) + ' · ' + dur(s.stay) + ' · ' + esc(s.ref_type_text) + '</div>' +
                        '<button class="wm-btn wm-btn--sm" style="margin-top:5px" data-trail="' + esc(s.sid) + '">' + T('看足迹') + '</button></div>';
                }).join(''));
        });
    });

    $($root).on('click.wm', '[data-ban]', function () {
        var ip = this.getAttribute('data-ban');
        var opts = [[3600, T('1 小时')], [21600, T('6 小时')], [86400, T('1 天')], [604800, T('7 天')], [0, T('永久')]];
        var body = '<div style="padding:18px">' +
            '<p style="margin:0 0 12px">' + T('封禁 IP') + ' <b class="num">' + esc(ip) + '</b></p>' +
            '<select class="wm-select" lay-ignore id="wm-ban-sec" style="width:100%;margin-bottom:10px">' +
            opts.map(function (o) { return '<option value="' + o[0] + '">' + o[1] + '</option>'; }).join('') + '</select>' +
            '<input class="wm-input" id="wm-ban-reason" style="width:100%" placeholder="' + T('封禁原因（可选）') + '"></div>';
        layer.open({
            type: 1, title: T('封禁 IP'), area: ['360px', 'auto'], content: body,
            btn: [T('确认封禁'), T('取消')],
            yes: function (idx) {
                var sec = Number((el('wm-ban-sec') || {}).value || 0);
                var reason = (el('wm-ban-reason') || {}).value || '';
                layer.close(idx);
                post('banIp', { ip: ip, seconds: sec, reason: reason }, function (_, res) {
                    message.success(res.msg);
                    loadRuntime();
                    if (state.tab === 'rules') { loadBans(1); }
                });
            }
        });
    });

    $($root).on('click.wm', '[data-unban]', function () {
        var ip = this.getAttribute('data-unban');
        post('unbanIp', { ip: ip }, function (_, res) { message.success(res.msg); loadBans(1); loadRuntime(); });
    });

    $($root).on('click.wm', '[data-acl-del]', function () {
        var id = this.getAttribute('data-acl-del');
        post('aclDelete', { id: id }, function (_, res) { message.success(res.msg); loadAcl(aclPage); });
    });

    $($root).on('click.wm', '[data-acl-type]', function () {
        aclType = Number(this.getAttribute('data-acl-type'));
        $root.querySelectorAll('[data-acl-type]').forEach(function (b) {
            b.classList.toggle('on', Number(b.getAttribute('data-acl-type')) === aclType);
        });
        loadAcl(1);
    });

    $($root).on('click.wm', '#wm-acl-add', function () {
        var value = (el('wm-acl-value') || {}).value || '';
        var note = (el('wm-acl-note') || {}).value || '';
        var hours = Number((el('wm-acl-hours') || {}).value || 0);
        if (!value.trim()) { message.error(T('请输入规则内容')); return; }
        post('aclSave', { type: aclType, value: value, note: note, hours: hours }, function (_, res) {
            message.success(res.msg);
            if (el('wm-acl-value')) { el('wm-acl-value').value = ''; }
            if (el('wm-acl-note')) { el('wm-acl-note').value = ''; }
            loadAcl(1);
        });
    });

    $($root).on('click.wm', '#wm-acl-search', function () { loadAcl(1); });

    $($root).on('click.wm', '#wm-test-run, [data-test-ip]', function () {
        var ip = this.getAttribute('data-test-ip') || (el('wm-test-ip') || {}).value || '';
        if (!ip.trim()) { message.error(T('请输入要检测的 IP')); return; }
        openDrawer(T('IP 检测'), skeleton(5));
        post('aclTest', { ip: ip }, function (d) {
            var geo = d.geo || {}, ban = d.banned, rule = d.rule, p = d.profile || {}, cc = d.cc || {};
            var kv = [
                ['IP', d.ip], [T('归属地'), geo.text || T('未知')],
                [T('状态'), ban ? T('已封禁，剩余 :s').replace(':s', ban.left < 0 ? T('永久') : dur(ban.left)) : (d.allowed ? T('在白名单中') : T('正常'))],
                [T('命中规则'), rule ? (rule.type_text + ' · ' + rule.value) : T('无')],
                [T('管理员常用 IP'), d.admin_ip ? T('是') : T('否')],
                [T('当前限频计数'), T('突发 :b / 持续 :s').replace(':b', cc.burst || 0).replace(':s', cc.sustain || 0)],
                [T('累计请求'), num(p.requests || 0)], [T('累计攻击'), num(p.attacks || 0)],
                [T('首次出现'), p.first_at ? new Date(p.first_at * 1000).toLocaleString() : '—'],
                [T('最近出现'), p.last_at ? new Date(p.last_at * 1000).toLocaleString() : '—']
            ];
            drawerBody('<dl class="wm-kv">' + kv.map(function (x) {
                return '<dt>' + esc(x[0]) + '</dt><dd>' + esc(x[1]) + '</dd>';
            }).join('') + '</dl><div style="margin-top:16px;display:flex;gap:8px">' +
                '<button class="wm-btn wm-btn--danger" data-ban="' + esc(d.ip) + '">' + T('封禁') + '</button>' +
                '<button class="wm-btn" data-unban="' + esc(d.ip) + '">' + T('解封') + '</button></div>');
        });
    });

    $($root).on('change.wm', '[data-rule-toggle]', function () {
        var id = this.getAttribute('data-rule-toggle');
        post('ruleSave', { rule: { id: id, enabled: this.checked ? 1 : 0 } }, function (_, res) {
            message.success(res.msg);
        });
    });

    $($root).on('change.wm', '[data-rule-action]', function () {
        var id = this.getAttribute('data-rule-action');
        post('ruleSave', { rule: { id: id, action: this.value } }, function (_, res) {
            message.success(res.msg);
        });
    });

    $($root).on('click.wm', '[data-page]', function () {
        var parts = this.getAttribute('data-page').split(':');
        if (parts[0] === 'visitor') { loadVisitors(Number(parts[1])); }
        else if (parts[0] === 'attack') { loadSecurity(Number(parts[1])); }
        else if (parts[0] === 'acl') { loadAcl(Number(parts[1])); }
    });

    $($root).on('change.wm', '#wm-vf-spider,#wm-vf-is_new,#wm-vf-dev,#wm-vf-ref_type', function () { loadVisitors(1); });
    $($root).on('change.wm', '#wm-af-kind,#wm-af-blocked', function () { loadSecurity(1); });
    $($root).on('keydown.wm', '#wm-vf-ip', function (e) { if (e.key === 'Enter') { loadVisitors(1); } });
    $($root).on('keydown.wm', '#wm-af-ip', function (e) { if (e.key === 'Enter') { loadSecurity(1); } });
    $($root).on('keydown.wm', '#wm-test-ip', function (e) { if (e.key === 'Enter') { $('#wm-test-run').click(); } });

    $(document).on('click.wm', '#wm-drawer .wm-drawer__mask, #wm-drawer .wm-drawer__close', closeDrawer);
    $(document).on('keydown.wm', function (e) { if (e.key === 'Escape') { closeDrawer(); } });

    /* ───────────────────────── 启动 ───────────────────────── */

    $root.querySelectorAll('[data-range]').forEach(function (b) {
        b.classList.toggle('on', b.getAttribute('data-range') === state.range);
    });
    loadRuntime();
    loadOverview();
    switchTab('realtime');
})();
