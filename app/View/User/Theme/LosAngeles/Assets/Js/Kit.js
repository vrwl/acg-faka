/* ============================================================================
   洛杉矶 · 运行时基座 (window.LA)
   ----------------------------------------------------------------------------
   PC 外壳与手机 APP 外壳共用这一层：明暗切换、请求缓存、懒加载、
   入场观察、倒计时、金额拆分、商品卡渲染、安全工具。

   约束：
   · 不使用内联事件处理器（CSP 强制模式会拦 onclick=），一律事件委托。
   · 动画只碰 transform / opacity。
   · 所有插入 DOM 的外部字符串必须过 LA.esc()。
   ========================================================================= */
(function (win, doc) {
    'use strict';

    if (win.LA) { return; }

    // ------------------------------------------------------------ 平台全局补挂
    //
    // 平台的 util / format / treasure / trade / Table / Loading 是用顶层
    // `const x = new class {}` / `class Table {}` 声明的。经典脚本里的顶层
    // const/let/class 只进「全局词法环境」，**不会**成为 window 的属性 ——
    // 于是 window.util 是 undefined，而裸写 util 却拿得到。
    //
    // 这个差异极其隐蔽：typeof window.util === 'undefined' 的守卫会静默把
    // 整条下单链路降级掉（算价/库存/下单/查单全部失效），页面还不报错。
    // 在这里补挂一次，后面所有代码统一走 window，不必到处写 typeof 守卫。
    /* eslint-disable no-undef */
    if (typeof util !== 'undefined' && !win.util) { win.util = util; }
    if (typeof format !== 'undefined' && !win.format) { win.format = format; }
    if (typeof treasure !== 'undefined' && !win.treasure) { win.treasure = treasure; }
    if (typeof trade !== 'undefined' && !win.trade) { win.trade = trade; }
    if (typeof Table !== 'undefined' && !win.Table) { win.Table = Table; }
    if (typeof Loading !== 'undefined' && !win.Loading) { win.Loading = Loading; }
    /* eslint-enable no-undef */

    var root = doc.documentElement;
    var MODES = ['auto', 'light', 'dark'];

    // ------------------------------------------------------------ 选择器 / 事件

    function qs(sel, ctx) { return (ctx || doc).querySelector(sel); }

    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }

    /**
     * 事件委托。on(root, 'click', '.foo', fn) —— fn 里的 this 是命中的元素，
     * 第二个参数是那个元素（箭头函数也能拿到）。
     */
    function on(el, type, selector, handler, options) {
        if (typeof selector === 'function') {
            el.addEventListener(type, selector, handler || false);
            return;
        }
        el.addEventListener(type, function (ev) {
            var target = ev.target && ev.target.closest ? ev.target.closest(selector) : null;
            if (target && el.contains(target)) { handler.call(target, ev, target); }
        }, options || false);
    }

    // ------------------------------------------------------------ 安全

    var ESC_MAP = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};

    /** 插入 innerHTML 前的强制出口。数字/空值一律转空串。 */
    function esc(value) {
        if (value === null || value === undefined) { return ''; }
        return String(value).replace(/[&<>"']/g, function (c) { return ESC_MAP[c]; });
    }

    /** 属性里用的 URL：只放行站内相对路径与 http(s)，挡 javascript: */
    function safeUrl(value, fallback) {
        var url = String(value === null || value === undefined ? '' : value).trim();
        if (!url) { return fallback || ''; }
        if (url.charAt(0) === '/' && url.charAt(1) !== '/') { return url; }
        if (url.charAt(0) === '#') { return url; }
        return /^https?:\/\//i.test(url) ? url : (fallback || '');
    }

    // ------------------------------------------------------------ 节流 / 去抖

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, wait || 200);
        };
    }

    function throttle(fn, wait) {
        var last = 0, timer = null;
        return function () {
            var ctx = this, args = arguments, now = Date.now(), gap = now - last;
            if (gap >= (wait || 100)) {
                last = now;
                fn.apply(ctx, args);
            } else if (!timer) {
                timer = setTimeout(function () {
                    timer = null;
                    last = Date.now();
                    fn.apply(ctx, args);
                }, (wait || 100) - gap);
            }
        };
    }

    /** 下一帧执行，用于「改完 DOM 再加动画类」这种两步走 */
    function raf(fn) { return win.requestAnimationFrame ? win.requestAnimationFrame(fn) : setTimeout(fn, 16); }

    // ------------------------------------------------------------ 明暗

    var theme = {
        key: function () { return root.getAttribute('data-la-key') || 'la.theme'; },

        preference: function () {
            var p = root.getAttribute('data-theme-preference');
            return MODES.indexOf(p) === -1 ? 'auto' : p;
        },

        systemDark: function () {
            return !!(win.matchMedia && win.matchMedia('(prefers-color-scheme: dark)').matches);
        },

        /** 应用一个偏好（auto/light/dark），写入 localStorage 并广播 */
        set: function (pref, persist) {
            if (MODES.indexOf(pref) === -1) { pref = 'auto'; }
            var dark = pref === 'dark' || (pref === 'auto' && theme.systemDark());

            root.setAttribute('data-theme-preference', pref);
            root.setAttribute('data-theme', dark ? 'dark' : 'light');
            root.style.colorScheme = dark ? 'dark' : 'light';

            if (persist !== false) {
                try {
                    if (pref === (root.getAttribute('data-la-default') || 'auto')) {
                        win.localStorage.removeItem(theme.key());
                    } else {
                        win.localStorage.setItem(theme.key(), pref);
                    }
                } catch (e) { /* 隐私模式下写不进去，视觉效果本次仍然生效 */ }
            }

            doc.dispatchEvent(new CustomEvent('la:theme', {detail: {preference: pref, dark: dark}}));
            return pref;
        },

        /** 三态轮转：auto → light → dark → auto */
        cycle: function () {
            var next = {auto: 'light', light: 'dark', dark: 'auto'}[theme.preference()] || 'auto';
            return theme.set(next);
        },

        isDark: function () { return root.getAttribute('data-theme') === 'dark'; }
    };

    // 跟随系统：只有偏好是 auto 时才响应
    if (win.matchMedia) {
        var mq = win.matchMedia('(prefers-color-scheme: dark)');
        var onSystem = function () { if (theme.preference() === 'auto') { theme.set('auto', false); } };
        if (mq.addEventListener) { mq.addEventListener('change', onSystem); }
        else if (mq.addListener) { mq.addListener(onSystem); }
    }

    // 多标签页同步
    win.addEventListener('storage', function (ev) {
        if (ev.key === theme.key()) { theme.set(ev.newValue || root.getAttribute('data-la-default') || 'auto', false); }
    });

    // 全站统一的切换入口：任何带 data-la-theme="light|dark|auto|cycle" 的元素都能用
    on(doc, 'click', '[data-la-theme]', function (ev, el) {
        ev.preventDefault();
        var v = el.getAttribute('data-la-theme');
        v === 'cycle' ? theme.cycle() : theme.set(v);
    });

    // ------------------------------------------------------------ 请求

    var cache = Object.create(null);
    var inflight = Object.create(null);

    /**
     * GET + 内存缓存 + 同址请求合流。
     * 商城首页会对同一批分类反复取商品，缓存能省掉大量重复往返。
     *
     * @returns {Promise<{data:Array|Object, total:number}>}
     */
    function get(url, opts) {
        opts = opts || {};
        var ttl = opts.ttl === undefined ? 60000 : opts.ttl;
        var now = Date.now();

        if (ttl > 0 && cache[url] && now - cache[url].at < ttl) {
            return Promise.resolve(cache[url].value);
        }
        if (inflight[url]) { return inflight[url]; }

        var p = fetch(url, {
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            return res.json();
        }).then(function (json) {
            if (!json || json.code !== 200) {
                throw new Error((json && json.msg) || 'error');
            }
            var value = {data: json.data, total: json.total || 0};
            if (ttl > 0) { cache[url] = {at: Date.now(), value: value}; }
            return value;
        }).finally(function () { delete inflight[url]; });

        inflight[url] = p;
        return p;
    }

    /**
     * POST。done 收到的是完整响应（{code,msg,data}），与平台 util.post 一致。
     * opts.loader=true 时显示平台的全局 loading（下单这类慢请求需要）。
     */
    function post(url, data, opts) {
        opts = opts || {};
        return new Promise(function (resolve, reject) {
            if (!win.util || typeof win.util.post !== 'function') {
                reject(new Error('platform util missing'));
                return;
            }
            win.util.post({
                url: url,
                data: data || {},
                loader: opts.loader === true,
                done: resolve,
                error: function (res) { reject(res || new Error('error')); },
                fail: function () { reject(new Error('network')); }
            });
        });
    }

    function query(params) {
        var parts = [];
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v === null || v === undefined || v === '') { return; }
            parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
        });
        return parts.length ? '?' + parts.join('&') : '';
    }

    function dropCache() { cache = Object.create(null); }

    // ------------------------------------------------------------ 商品数据

    /** 商品列表。opts: {categoryId, keywords, page, limit, ttl} */
    function goods(opts) {
        opts = opts || {};
        var params = {};
        if (opts.categoryId) { params.categoryId = opts.categoryId; }
        if (opts.keywords) { params.keywords = opts.keywords; }
        if (opts.limit) {
            params.limit = opts.limit;
            params.page = opts.page || 1;
        }
        return get('/user/api/index/commodity' + query(params), {ttl: opts.ttl});
    }

    // ------------------------------------------------------------ 金额

    function symbol() {
        if (win.format && typeof win.format.currencySymbol === 'function') {
            try { return win.format.currencySymbol(); } catch (e) { /* 降级 */ }
        }
        var c = (win.getVar && win.getVar('CURRENCY')) || null;
        return (c && c.symbol) || '¥';
    }

    /** 金额拆成整数位与小数位，让小数位可以画得更小 —— 大商城的价格排版惯例 */
    function money(value) {
        var n = Number(value);
        if (!isFinite(n)) { n = 0; }
        var text = n.toFixed(2);
        var dot = text.indexOf('.');
        return {int: text.slice(0, dot), dec: text.slice(dot), raw: n};
    }

    function priceHtml(value, size) {
        var m = money(value);
        return '<span class="la-price ' + (size ? 'la-price--' + size : 'la-price--md') + '">'
            + '<span class="la-price__sym">' + esc(symbol()) + '</span>'
            + '<span class="la-price__int">' + m.int + '</span>'
            + '<span class="la-price__dec">' + m.dec + '</span>'
            + '</span>';
    }

    // ------------------------------------------------------------ 通用 Tab
    //
    // 容器 [data-la-tabs="key"]，按钮 .la-tabs__btn[data-la-tab]，面板 [data-la-pane]。
    // 只做显隐，面板里的 DOM 一个不动 —— 平台控制器按固定 ID 挂的表格、编辑器都还在原处。
    // 面板一显示就对里面的 bootstrap-table 补一次 resetView：它在 display:none 里初始化时列宽是 0。
    // 当前 Tab 写进 location.hash（#tab-xxx），刷新和分享链接都能落回同一个 Tab。
    function tabsGo(root, name, push) {
        root = typeof root === 'string' ? qs('[data-la-tabs="' + root + '"]') : root;
        if (!root) { return false; }
        var btn = qs('.la-tabs__btn[data-la-tab="' + name + '"]', root);
        var pane = qs('[data-la-pane="' + name + '"]', root);
        if (!btn || !pane) { return false; }

        qsa('.la-tabs__btn[data-la-tab]', root).forEach(function (b) {
            var onIt = b === btn;
            b.classList.toggle('is-on', onIt);
            b.setAttribute('aria-selected', onIt ? 'true' : 'false');
        });
        qsa('[data-la-pane]', root).forEach(function (p) { p.hidden = p !== pane; });

        if (win.jQuery && win.jQuery.fn && win.jQuery.fn.bootstrapTable) {
            qsa('table', pane).forEach(function (t) {
                try { win.jQuery(t).bootstrapTable('resetView'); } catch (e) { /* 不是 bootstrap-table 的表，忽略 */ }
            });
        }
        if (push !== false && win.history && win.history.replaceState) {
            try { win.history.replaceState(null, '', '#tab-' + name); } catch (e) { /* 忽略 */ }
        }
        doc.dispatchEvent(new CustomEvent('la:tab', {detail: {root: root, tab: name}}));
        return true;
    }

    on(doc, 'click', '[data-la-tabs] .la-tabs__btn[data-la-tab]', function (ev, el) {
        ev.preventDefault();
        tabsGo(el.closest('[data-la-tabs]'), el.getAttribute('data-la-tab'));
    });

    // 首屏：hash 指定了就落到那个 Tab，否则保持模板里的默认
    qsa('[data-la-tabs]').forEach(function (root) {
        var m = /#tab-([\w-]+)/.exec(win.location.hash || '');
        if (m) { tabsGo(root, m[1], false); }
    });

    // ------------------------------------------------------------ 站点 LOGO 兜底
    //
    // 页头的 LOGO 是站长上传的图；图挂了就退回纯 CSS 标记。
    // img 的 error 事件不冒泡，只能在捕获阶段从 document 上接。
    doc.addEventListener('error', function (ev) {
        var img = ev.target;
        if (!img || !img.classList || !img.classList.contains('la-logo__img')) { return; }
        var box = img.closest('.la-logo__mark');
        box && box.classList.remove('has-img');
        img.remove();
    }, true);

    // ------------------------------------------------------------ 懒加载 / 入场

    var lazyIO = null;

    function lazy(scope) {
        var nodes = qsa('img[data-src]', scope || doc);
        if (!nodes.length) { return; }

        if (!win.IntersectionObserver) {
            nodes.forEach(function (img) { img.src = img.getAttribute('data-src'); img.removeAttribute('data-src'); });
            return;
        }
        if (!lazyIO) {
            lazyIO = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) { return; }
                    var img = entry.target;
                    lazyIO.unobserve(img);
                    var src = img.getAttribute('data-src');
                    if (src) { img.src = src; }
                    img.removeAttribute('data-src');
                });
            }, {rootMargin: '320px 0px'});
        }
        nodes.forEach(function (img) { lazyIO.observe(img); });
    }

    var revealIO = null;

    /** 滚动入场：元素进入视口时加 la-in，触发一次就解绑 */
    function reveal(scope) {
        var nodes = qsa('[data-la-reveal]:not(.la-in)', scope || doc);
        if (!nodes.length) { return; }

        if (!win.IntersectionObserver) {
            nodes.forEach(function (el) { el.classList.add('la-in'); el.removeAttribute('data-la-reveal'); });
            return;
        }
        if (!revealIO) {
            revealIO = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) { return; }
                    revealIO.unobserve(entry.target);
                    entry.target.classList.add('la-in');
                    entry.target.removeAttribute('data-la-reveal');
                });
            }, {rootMargin: '0px 0px -8% 0px', threshold: .02});
        }
        nodes.forEach(function (el) { revealIO.observe(el); });
    }

    /** 触底加载：返回一个 stop() */
    function onBottom(sentinel, handler) {
        if (!sentinel || !win.IntersectionObserver) { return function () {}; }
        var io = new IntersectionObserver(function (entries) {
            if (entries[0] && entries[0].isIntersecting) { handler(); }
        }, {rootMargin: '480px 0px'});
        io.observe(sentinel);
        return function () { io.disconnect(); };
    }

    // ------------------------------------------------------------ 倒计时

    var ticks = [];
    var tickTimer = null;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    /**
     * 倒计时。全站共用一个 1s 定时器，元素多了也不会有几十个 timer。
     * @param {Element} el 容器，内部按 [data-d][data-h][data-m][data-s] 填充
     * @param {number} endMs 结束时间戳（毫秒）
     * @param {Function} onEnd 归零回调
     */
    function countdown(el, endMs, onEnd) {
        if (!el || !isFinite(endMs)) { return; }
        ticks.push({el: el, end: endMs, done: onEnd});
        if (!tickTimer) { tickTimer = setInterval(runTicks, 1000); }
        runTicks();
    }

    function runTicks() {
        var now = Date.now();
        ticks = ticks.filter(function (t) {
            if (!t.el.isConnected) { return false; }
            var left = Math.max(0, t.end - now);
            var total = Math.floor(left / 1000);
            var d = Math.floor(total / 86400);
            var h = Math.floor((total % 86400) / 3600);
            var m = Math.floor((total % 3600) / 60);
            var s = total % 60;

            var slot;
            if ((slot = t.el.querySelector('[data-d]'))) { slot.textContent = d; }
            // 不足一天时整个"N 天"段一起收起——只藏数字会留下一个孤零零的"天"
            var day = t.el.querySelector('[data-day]');
            day && day.classList.toggle('is-hidden', d <= 0);
            if ((slot = t.el.querySelector('[data-h]'))) { slot.textContent = pad(h); }
            if ((slot = t.el.querySelector('[data-m]'))) { slot.textContent = pad(m); }
            if ((slot = t.el.querySelector('[data-s]'))) { slot.textContent = pad(s); }

            if (left <= 0) {
                t.el.classList.add('is-over');
                typeof t.done === 'function' && t.done();
                return false;
            }
            return true;
        });
        if (!ticks.length && tickTimer) { clearInterval(tickTimer); tickTimer = null; }
    }

    // ------------------------------------------------------------ 提示

    function toast(text, type) {
        var msg = String(text || '');
        if (win.message && typeof win.message[type || 'success'] === 'function') {
            win.message[type || 'success'](msg);
            return;
        }
        if (win.layer && typeof win.layer.msg === 'function') { win.layer.msg(msg); }
    }

    function copy(text, okText) {
        var done = function () { toast(okText || (win.i18n ? win.i18n('已复制') : '已复制')); };
        if (win.util && typeof win.util.copyTextToClipboard === 'function') {
            win.util.copyTextToClipboard(String(text), done);
            return;
        }
        if (navigator.clipboard) { navigator.clipboard.writeText(String(text)).then(done); }
    }

    function t(text) { return win.i18n ? win.i18n(text) : text; }

    // ------------------------------------------------------------ 引导数据

    var bootData = null;

    function boot() {
        if (bootData) { return bootData; }
        var node = qs('#la-boot');
        try { bootData = node ? JSON.parse(node.textContent || '{}') : {}; }
        catch (e) { bootData = {}; }
        return bootData;
    }



    // ------------------------------------------------------------ 分类树（按需加载）
    //
    // 大站有几百个分类、上千个节点。整棵树如果由服务端渲染进 DOM，
    // 光首页就是几 MB 的 HTML（实测 300 个一级分类 → 4.3MB / 37000 个标签）。
    // 所以服务端只出一级分类，子级全部走这里：一次请求取回整棵树，
    // 内存 + sessionStorage 双缓存，用到哪一支才建哪一支的 DOM。

    var catTree = null;
    var catPromise = null;
    var catMap = null;
    var CAT_KEY = 'la.cats';

    function indexCats(nodes, parent, map) {
        (nodes || []).forEach(function (node) {
            if (!node || node.id === undefined) { return; }
            map[node.id] = {node: node, parent: parent};
            if (node.children && node.children.length) { indexCats(node.children, node, map); }
        });
        return map;
    }

    /** 整棵分类树。永远只发一次请求。 */
    function cats() {
        if (catTree) { return Promise.resolve(catTree); }
        if (catPromise) { return catPromise; }

        // 同一次会话内换页也不用重取
        try {
            var cached = win.sessionStorage.getItem(CAT_KEY);
            if (cached) {
                var parsed = JSON.parse(cached);
                if (parsed && parsed.at && Date.now() - parsed.at < 600000 && Array.isArray(parsed.data)) {
                    catTree = parsed.data;
                    return Promise.resolve(catTree);
                }
            }
        } catch (e) { /* 隐私模式/配额满，退化成每次请求 */ }

        catPromise = get('/user/api/index/data', {ttl: 600000}).then(function (res) {
            catTree = Array.isArray(res.data) ? res.data : [];
            try {
                win.sessionStorage.setItem(CAT_KEY, JSON.stringify({at: Date.now(), data: catTree}));
            } catch (e) { /* 存不下就算了 */ }
            return catTree;
        }).catch(function () {
            catTree = [];
            return catTree;
        }).finally(function () { catPromise = null; });

        return catPromise;
    }

    /** id → {node, parent}，用于面包屑与"展开当前分支" */
    function catIndex() {
        return cats().then(function (tree) {
            if (!catMap) { catMap = indexCats(tree, null, Object.create(null)); }
            return catMap;
        });
    }

    /** 从任意分类往上追到根，返回 [根, …, 自己] */
    /** 同步版：手上已经有 index 了就用这个（过滤器里逐条算路径，不能每条 await） */
    function trailIn(map, id) {
        var trail = [];
        var cur = map && map[id];
        var guard = 0;
        while (cur && guard++ < 32) {
            trail.unshift(cur.node);
            cur = cur.parent ? map[cur.parent.id] : null;
        }
        return trail;
    }

    function catTrail(id) {
        return catIndex().then(function (map) { return trailIn(map, id); });
    }

    /**
     * 分类链接。
     *
     * 核心在树顶塞了个伪分类「推荐」，它的 id 是字符串 'recommend' 不是数字，
     * 所以这里**不能** parseInt —— 那会把它变成 /cat/0。
     * 只放行 [A-Za-z0-9_-]，其余一律回落首页，别让脏 id 拼进 URL。
     */
    function catUrl(id) {
        var raw = String(id === undefined || id === null ? '' : id);
        return /^[A-Za-z0-9_-]{1,32}$/.test(raw) ? '/cat/' + raw : '/';
    }

    // ------------------------------------------------------------ 分页

    /**
     * 页码序列：首尾各留 1 页，当前页左右各 2 页，中间用省略号。
     * 1 2 … 11 12 [13] 14 15 … 208
     */
    function pageList(page, pages) {
        if (pages <= 9) {
            var all = [];
            for (var i = 1; i <= pages; i++) { all.push(i); }
            return all;
        }
        var out = [1];
        var from = Math.max(2, page - 2);
        var to = Math.min(pages - 1, page + 2);
        if (from > 2) { out.push('…'); }
        for (var j = from; j <= to; j++) { out.push(j); }
        if (to < pages - 1) { out.push('…'); }
        out.push(pages);
        return out;
    }

    /**
     * 渲染一条分页栏。大站没有真分页就等于"永远到不了第 200 页"。
     * @param {Element} host
     * @param {Object} state {page, total, limit, sizes:[24,48,96]}
     * @param {Function} onGo (page, limit) => void
     */
    function pager(host, state, onGo) {
        if (!host) { return; }
        var total = Math.max(0, parseInt(state.total, 10) || 0);
        var limit = Math.max(1, parseInt(state.limit, 10) || 24);
        var pages = Math.max(1, Math.ceil(total / limit));
        var page = Math.min(Math.max(1, parseInt(state.page, 10) || 1), pages);

        if (!total) { host.innerHTML = ''; host.hidden = true; return; }
        host.hidden = false;

        var sizes = (state.sizes || [24, 48, 96]).slice();
        //链接里带的 limit 可能不在预设里（别人发来的深链），别让下拉显示成 24
        if (sizes.indexOf(limit) === -1) { sizes.push(limit); sizes.sort(function (a, b) { return a - b; }); }
        var nums = pageList(page, pages).map(function (n) {
            if (n === '…') { return '<span class="la-page__gap">…</span>'; }
            return '<button type="button" class="la-page__no' + (n === page ? ' is-on' : '') + '" data-page="' + n + '">' + n + '</button>';
        }).join('');

        host.innerHTML = '<div class="la-page">'
            + '<button type="button" class="la-page__step" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>'
            + '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14.6 5.4-6.6 6.6 6.6 6.6"/></svg>'
            + esc(t('上一页')) + '</button>'
            + '<span class="la-page__nums">' + nums + '</span>'
            + '<button type="button" class="la-page__step" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>'
            + esc(t('下一页'))
            + '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.4 5.4 6.6 6.6-6.6 6.6"/></svg>'
            + '</button>'
            + '</div>'
            + '<div class="la-page__aside">'
            + '<span class="la-page__meta">' + esc(t('共 N 页 M 件').replace('N', pages).replace('M', total)) + '</span>'
            + '<label class="la-page__size">' + esc(t('每页'))
            + '<select data-size>' + sizes.map(function (n) {
                return '<option value="' + n + '"' + (n === limit ? ' selected' : '') + '>' + n + '</option>';
            }).join('') + '</select></label>'
            + '<label class="la-page__jump">' + esc(t('跳至'))
            + '<input type="number" min="1" max="' + pages + '" value="' + page + '" data-jump>'
            + '<button type="button" data-jump-go>' + esc(t('确定')) + '</button></label>'
            + '</div>';

        if (host.__laBound) { return; }
        host.__laBound = true;

        on(host, 'click', '[data-page]', function (ev, el) {
            if (el.disabled) { return; }
            var n = parseInt(el.getAttribute('data-page'), 10);
            if (n >= 1) { onGo(n, null); }
        });
        on(host, 'change', '[data-size]', function (ev, el) {
            onGo(1, parseInt(el.value, 10) || 24);
        });
        var jump = function () {
            var input = qs('[data-jump]', host);
            var n = parseInt(input && input.value, 10);
            if (n >= 1) { onGo(n, null); }
        };
        on(host, 'click', '[data-jump-go]', jump);
        on(host, 'keydown', '[data-jump]', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); jump(); }
        });
    }

    // ------------------------------------------------------------ 商品卡

    var TAG_COLORS = ['red', 'orange', 'green', 'cyan', 'blue', 'purple', 'pink', 'gray'];

    // stock_state：0=售罄 1=所剩无几 2=数量有限 3=现货充足 4=库存爆棚
    var STOCK_TEXT = ['已售罄', '所剩无几', '数量有限', '现货充足', '库存爆棚'];

    function tagsHtml(item, max) {
        var tags = Array.isArray(item && item.tags) ? item.tags : [];
        var html = '';
        tags.slice(0, max || 5).forEach(function (tag) {
            var text = tag && tag.text ? String(tag.text).trim() : '';
            if (!text) { return; }
            var color = tag && tag.color ? String(tag.color) : 'red';
            if (TAG_COLORS.indexOf(color) === -1) { color = 'red'; }
            html += '<span class="la-tag la-tag--' + color + '">' + esc(t(text)) + '</span>';
        });
        return html;
    }

    /**
     * 商品自带的角标。卡片上位置有限，$max 控制最多出几个。
     * 「秒杀」不在这里出 —— 它已经压在图片左上角了，再出一遍是重复。
     */
    function badgesHtml(item, max) {
        var out = [];
        if (item.has_wholesale) {
            out.push('<span class="la-badge la-badge--wholesale">' + esc(t('批发价')) + '</span>');
        }
        if (String(item.delivery_way) === '0') {
            out.push('<span class="la-badge la-badge--auto">' + esc(t('自动发货')) + '</span>');
        }
        return out.slice(0, max === undefined ? out.length : max).join('');
    }

    /**
     * 商品卡。PC 与手机共用同一份结构，靠外层容器的 class 决定排版，
     * 这样两端的数据口径、标签逻辑、售罄判断永远一致。
     *
     * 布局取自拼多多那一路：**价格是卡片上最大的元素**，其余全部让路。
     * 顺序固定为 图 → 名(两行) → 标签 → 「价格 + 已售」同一行。
     * 「已售」和价格挤在一行不是为了省地方，是因为这两个数放一起才构成
     * 「这么便宜还这么多人买」的判断，拆开就都变成了背景噪音。
     *
     * @param {Object} item 商品列表接口的一项
     * @param {Object} [opts] {rail:Boolean 横向轨道卡, rank:Number 楼层序号, buy:Boolean 出下单按钮}
     */
    function card(item, opts) {
        opts = opts || {};
        var cfg = boot();
        var id = parseInt(item.id, 10) || 0;
        var out = Number(item.stock_state) === 0;
        var cover = safeUrl(item.cover, '/favicon.ico');
        var name = t(String(item.name || ''));

        // price 已是登录后的实际成交价；user_price 是会员价。
        // 未登录且两者不同时，把会员价当"划线原价"的反面亮出来 —— 最有效的转化钩子。
        var price = Number(item.price) || 0;
        var member = Number(item.user_price) || 0;
        var showMember = cfg.showMemberPrice !== false && !cfg.logged && member > 0 && member < price;

        // 标签总共最多两个（站长标签优先，剩的名额才给系统角标）：
        // 卡片只有这么宽，第三个必然被挤成半截，不如不出
        var tagged = Array.isArray(item.tags) ? Math.min(item.tags.length, 2) : 0;
        var chips = tagsHtml(item, 2) + badgesHtml(item, 2 - tagged);

        // 已售 / 库存：两个独立开关，开了就都出，挤在价格右侧同一行。
        // 窄卡片上先截这一段（overflow ellipsis），绝不挤价格。
        var metaBits = [];
        if (cfg.showSold !== false) {
            metaBits.push('<span class="la-goods__sold">' + esc(t('已售')) + (parseInt(item.order_sold, 10) || 0) + '</span>');
        }
        if (cfg.showStock !== false) {
            var state = Number(item.stock_state) || 0;
            // 隐藏库存时接口回的是服务端拼的中文模糊文案，且不在 transList 的字段白名单里
            //（只翻了 name / category.name），换语言会漏。这里按 stock_state 自己出文案。
            metaBits.push('<span class="la-goods__stock" data-state="' + state + '">' + (String(item.inventory_hidden) === '1'
                ? esc(t(STOCK_TEXT[state] || STOCK_TEXT[0]))
                : esc(t('库存')) + esc(item.stock)) + '</span>');
        }
        var sold = metaBits.length ? '<span class="la-goods__meta">' + metaBits.join('') + '</span>' : '';

        var shop = '';
        if (cfg.showOwner !== false && item.owner && item.owner.username) {
            shop = '<div class="la-goods__shop">'
                + (item.owner.avatar ? '<img class="la-goods__shopimg" src="' + esc(safeUrl(item.owner.avatar, '/favicon.ico')) + '" alt="" loading="lazy" decoding="async">' : '')
                + '<span class="la-ellipsis">' + esc(item.owner.username) + '</span></div>';
        }

        return '<a class="la-goods' + (opts.rail ? ' la-goods--rail' : '') + (out ? ' is-out' : '')
            + '" href="/item/' + id + '" data-id="' + id + '"' + (opts.rank ? ' data-rank="' + opts.rank + '"' : '') + '>'
            + '<div class="la-goods__media">'
            + '<img class="la-goods__img" data-src="' + esc(cover) + '" alt="' + esc(name) + '" loading="lazy" decoding="async">'
            + (item.seckill_active ? '<span class="la-goods__flag">' + esc(t('秒杀')) + '</span>' : '')
            + (out ? '<span class="la-goods__out">' + esc(t('已售罄')) + '</span>' : '')
            + '</div>'
            + '<div class="la-goods__body">'
            + '<h3 class="la-goods__name la-clamp-2">' + esc(name) + '</h3>'
            + (chips ? '<div class="la-goods__tags">' + chips + '</div>' : '')
            + '<div class="la-goods__buy">'
            + priceHtml(price, opts.rail ? 'sm' : 'lg')
            + (showMember ? '<span class="la-goods__member">' + esc(t('会员')) + esc(symbol()) + money(member).int + '</span>' : '')
            + sold
            + '</div>'
            + shop
            + (opts.buy ? '<span class="la-goods__go">' + esc(t('立即购买')) + '</span>' : '')
            + '</div></a>';
    }

    /** 一批商品的骨架屏 */
    function skeleton(count, rail) {
        var html = '';
        for (var i = 0; i < (count || 8); i++) {
            html += '<div class="la-goods la-goods--ghost' + (rail ? ' la-goods--rail' : '') + '" aria-hidden="true">'
                + '<div class="la-goods__media"><div class="la-skeleton" style="position:absolute;inset:0"></div></div>'
                + '<div class="la-goods__body">'
                + '<div class="la-skeleton" style="height:14px;width:92%"></div>'
                + '<div class="la-skeleton" style="height:14px;width:58%;margin-top:7px"></div>'
                + '<div class="la-skeleton" style="height:20px;width:44%;margin-top:12px"></div>'
                + '</div></div>';
        }
        return html;
    }


    // ------------------------------------------------------------ 通用面板 / 输入框

    var panelIndex = null;

    function isApp() { return doc.body.classList.contains('la--app'); }

    /**
     * 弹一个主题面板：电脑端走 layui layer，手机端走底部 Sheet。
     * 两端外观不同但调用方只写一次。
     */
    function panel(title, html, onReady) {
        if (isApp() && LAref.sheet) {
            var body = LAref.sheet.open(title, html);
            onReady && onReady(body);
            return;
        }
        if (!win.layer) { return; }
        panelIndex = win.layer.open({
            type: 1,
            title: title,
            area: '440px',
            shadeClose: true,
            content: '<div class="la-panel">' + html + '</div>',
            success: function (layero) { onReady && onReady(layero[0]); }
        });
    }

    function closePanel() {
        if (isApp() && LAref.sheet) { LAref.sheet.close(); return; }
        if (win.layer && panelIndex !== null) { win.layer.close(panelIndex); panelIndex = null; }
    }

    /**
     * 输入框弹窗。平台的 message.prompt 依赖 SweetAlert2，而商城页的
     * 资源包里并没有它（会直接抛 "Swal is not defined"），所以自建一个，
     * 顺便让它长得和主题一致。
     *
     * @returns {Promise<string>} 确认时 resolve 输入值，取消则永不 resolve
     */
    function prompt(title, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var html = '<div class="la-prompt">'
                + (opts.desc ? '<p class="la-prompt__desc">' + esc(opts.desc) + '</p>' : '')
                + '<input class="la-input" type="' + (opts.password ? 'password' : 'text') + '" '
                + 'autocomplete="off" placeholder="' + esc(opts.placeholder || '') + '" data-la-prompt-input>'
                + '<div class="la-prompt__acts">'
                + '<button type="button" class="la-btn la-btn--line" data-la-prompt-cancel>' + esc(t('取消')) + '</button>'
                + '<button type="button" class="la-btn la-btn--brand" data-la-prompt-ok>' + esc(t('确定')) + '</button>'
                + '</div></div>';

            panel(title, html, function (root) {
                var input = root.querySelector('[data-la-prompt-input]');
                setTimeout(function () { input && input.focus(); }, 180);
                var submit = function () {
                    var value = input ? input.value : '';
                    closePanel();
                    resolve(value);
                };
                on(root, 'click', '[data-la-prompt-ok]', submit);
                on(root, 'click', '[data-la-prompt-cancel]', closePanel);
                input && input.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') { ev.preventDefault(); submit(); }
                });
            });
        });
    }

    // ------------------------------------------------------------ SweetAlert2 对话框补全
    //
    // 平台的「人机验证」是 message.prompt 弹一个 SweetAlert2，里面一张带 data-acg-refresh 的验证码图
    // 加一个空输入框：没有占位提示，也没有任何地方告诉用户点图能换一张（刷新由 csp-bind.js 全局处理）。
    // 弹窗出现时在这里补上占位文字、「看不清？点图片换一张」、点图后的淡出反馈，
    // 并给弹窗打上 la-swal--input / la-swal--captcha 钩子类，外形全在 Widget.css / App.css 里。
    (function swalDress() {
        var dress = function (popup) {
            if (!popup || popup.classList.contains('swal2-toast')) { return; }
            var input = qs('.swal2-input', popup);
            var hasInput = !!input && input.style.display !== 'none';
            var img = qs('.prompt-image-code', popup);
            popup.classList.toggle('la-swal--input', hasInput);
            popup.classList.toggle('la-swal--captcha', !!img);
            if (!img) { return; }

            if (hasInput && !input.getAttribute('placeholder')) {
                input.setAttribute('placeholder', t('输入图中的字符'));
                input.setAttribute('autocomplete', 'off');
                input.setAttribute('spellcheck', 'false');
            }
            if (!qs('.la-swal-captcha__hint', popup)) {
                var hint = doc.createElement('div');
                hint.className = 'la-swal-captcha__hint';
                hint.textContent = t('看不清？点图片换一张');
                img.insertAdjacentElement('afterend', hint);
            }
            if (!img.__laDressed) {
                img.__laDressed = true;
                var done = function () { img.classList.remove('is-refreshing'); };
                img.addEventListener('click', function () { img.classList.add('is-refreshing'); });
                img.addEventListener('load', done);
                img.addEventListener('error', done);
            }
        };

        var scan = function () { qsa('.swal2-container > .swal2-popup').forEach(dress); };

        // SweetAlert2 在同一次调用里建好容器、填好内容，观察器回调时内容已经齐了
        if ('MutationObserver' in win) {
            new MutationObserver(function (records) {
                for (var i = 0; i < records.length; i++) {
                    var added = records[i].addedNodes;
                    for (var j = 0; j < added.length; j++) {
                        var n = added[j];
                        if (n.nodeType === 1 && n.classList && n.classList.contains('swal2-container')) { scan(); return; }
                    }
                }
            }).observe(doc.body, {childList: true});
        }
        // 上一个弹窗还没关就又弹时容器会被复用、不会重新插进 body，靠聚焦兜底
        doc.addEventListener('focusin', function (ev) {
            var popup = ev.target && ev.target.closest ? ev.target.closest('.swal2-popup') : null;
            popup && dress(popup);
        });
    })();

    var LAref = win.LA = {
        qs: qs, qsa: qsa, on: on, esc: esc, safeUrl: safeUrl,
        debounce: debounce, throttle: throttle, raf: raf,
        theme: theme,
        get: get, post: post, query: query, dropCache: dropCache, goods: goods,
        symbol: symbol, money: money, priceHtml: priceHtml,
        lazy: lazy, reveal: reveal, onBottom: onBottom,
        countdown: countdown, toast: toast, copy: copy, t: t, boot: boot,
        cats: cats, catIndex: catIndex, catTrail: catTrail, trailIn: trailIn, catUrl: catUrl, tabsGo: tabsGo,
        pager: pager, pageList: pageList,
        card: card, skeleton: skeleton, tagsHtml: tagsHtml, badgesHtml: badgesHtml,
        panel: panel, closePanel: closePanel, prompt: prompt, isApp: isApp,
        STOCK_TEXT: STOCK_TEXT
    };
})(window, document);
