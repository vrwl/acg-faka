/* ============================================================================
   洛杉矶 · 手机端外壳运行时（APP 形态）
   吸顶 / 下拉刷新 / 半屏 Sheet / 更多菜单 / 页面转场 / 底部 TabBar 视图切换
   ========================================================================= */
(function (win, doc) {
    'use strict';

    var LA = win.LA;
    if (!LA || doc.documentElement.getAttribute('data-la-shell') === 'app') { return; }
    doc.documentElement.setAttribute('data-la-shell', 'app');

    var qs = LA.qs, qsa = LA.qsa, on = LA.on, esc = LA.esc;
    var scroller = qs('[data-la-scroll]');

    if (!qs('.la-tabbar')) { doc.body.classList.add('la-no-tab'); }

    // ------------------------------------------------------------ 吸顶

    (function stick() {
        if (!scroller) { return; }
        var head = qs('[data-la-apphead]');
        var bar = qs('[data-la-bar-trans]');
        if (!head && !bar) { return; }

        var sync = function () {
            var y = scroller.scrollTop;
            head && head.classList.toggle('is-stuck', y > 4);
            // 透明顶栏在滚过一屏图片高度的 60% 后转实底
            bar && bar.classList.toggle('is-solid', y > Math.min(220, win.innerHeight * 0.28));
        };

        scroller.addEventListener('scroll', LA.throttle(sync, 90), {passive: true});
        sync();
    })();

    // ------------------------------------------------------------ 下拉刷新

    (function pullRefresh() {
        if (!scroller) { return; }
        var box = qs('[data-la-refresh]', scroller);
        if (!box) { return; }

        var txt = qs('[data-la-refresh-txt]', box);
        var THRESHOLD = 62, MAX = 96;
        var startY = 0, pulling = false, dist = 0, busy = false;

        var say = function (key) {
            if (!txt) { return; }
            txt.textContent = LA.t(key);
        };

        scroller.addEventListener('touchstart', function (ev) {
            if (busy || scroller.scrollTop > 0 || ev.touches.length !== 1) { return; }
            startY = ev.touches[0].clientY;
            pulling = true;
            dist = 0;
            box.style.transition = 'none';
        }, {passive: true});

        scroller.addEventListener('touchmove', function (ev) {
            if (!pulling) { return; }
            var delta = ev.touches[0].clientY - startY;
            if (delta <= 0) {
                // 反向滑动就交还给正常滚动，别劫持用户的手势
                pulling = false;
                box.style.transition = '';
                box.style.height = '';
                box.classList.remove('is-pull', 'is-ready');
                return;
            }
            // 阻尼：越往下拉越沉，模拟原生的橡皮筋
            dist = Math.min(MAX, delta * 0.42);
            box.style.height = dist + 'px';
            box.classList.add('is-pull');
            box.classList.toggle('is-ready', dist >= THRESHOLD);
            say(dist >= THRESHOLD ? '松开立即刷新' : '下拉刷新');
        }, {passive: true});

        var release = function () {
            if (!pulling) { return; }
            pulling = false;
            box.style.transition = '';

            if (dist >= THRESHOLD) {
                busy = true;
                box.classList.remove('is-ready');
                box.classList.add('is-run');
                box.style.height = '46px';
                say('正在刷新');
                LA.dropCache();
                doc.dispatchEvent(new CustomEvent('la:refresh'));
                // 数据回来前给一个下限时长，避免刷新指示"闪一下"就没了
                setTimeout(function () {
                    box.style.height = '';
                    box.classList.remove('is-run', 'is-pull');
                    busy = false;
                    say('下拉刷新');
                }, 900);
            } else {
                box.style.height = '';
                box.classList.remove('is-pull', 'is-ready');
            }
        };

        scroller.addEventListener('touchend', release, {passive: true});
        scroller.addEventListener('touchcancel', release, {passive: true});
    })();

    // ------------------------------------------------------------ 半屏 Sheet

    var sheet = (function () {
        var root = qs('[data-la-sheet]');
        if (!root) { return {open: function () {}, close: function () {}}; }

        var panel = qs('.la-sheet__panel', root);
        var titleEl = qs('[data-la-sheet-title]', root);
        var bodyEl = qs('[data-la-sheet-body]', root);
        var onClose = null;

        // 以 DOM 节点方式装进来的内容（筛选抽屉搬的是平台的真实表单）：关掉后原样送回原位，
        // 不能像字符串内容那样 innerHTML 清掉 —— 表单上挂着 laydate / layui select 的绑定
        var kept = null;
        var restore = function () {
            if (!kept) { return; }
            var k = kept;
            kept = null;
            if (k.parent) {
                k.parent.insertBefore(k.node, k.next && k.next.parentNode === k.parent ? k.next : null);
            }
        };

        var close = function () {
            root.classList.remove('is-open');
            doc.body.style.removeProperty('overflow');
            setTimeout(function () {
                if (!root.classList.contains('is-open')) {
                    root.hidden = true;
                    root.setAttribute('aria-hidden', 'true');
                    restore();
                    bodyEl.innerHTML = '';
                }
            }, 380);
            if (typeof onClose === 'function') { onClose(); onClose = null; }
        };

        var open = function (title, html, opts) {
            opts = opts || {};
            titleEl.textContent = title || '';
            restore();
            if (html && html.nodeType === 1) {
                kept = {node: html, parent: html.parentNode, next: html.nextSibling};
                bodyEl.innerHTML = '';
                bodyEl.appendChild(html);
            } else {
                bodyEl.innerHTML = html || '';
            }
            onClose = opts.onClose || null;
            root.hidden = false;
            root.setAttribute('aria-hidden', 'false');
            doc.body.style.overflow = 'hidden';
            LA.raf(function () { LA.raf(function () { root.classList.add('is-open'); }); });
            typeof opts.onOpen === 'function' && opts.onOpen(bodyEl);
            return bodyEl;
        };

        on(root, 'click', '[data-la-sheet-close]', close);
        doc.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { close(); } });

        // 抓手下拽关闭
        (function drag() {
            var grip = qs('[data-la-sheet-grip]', root);
            if (!grip || !panel) { return; }
            var y0 = 0, dy = 0, on = false;

            grip.addEventListener('touchstart', function (ev) {
                on = true; y0 = ev.touches[0].clientY; dy = 0;
                root.classList.add('is-drag');
            }, {passive: true});

            grip.addEventListener('touchmove', function (ev) {
                if (!on) { return; }
                dy = Math.max(0, ev.touches[0].clientY - y0);
                panel.style.transform = 'translate3d(0,' + dy + 'px,0)';
            }, {passive: true});

            var end = function () {
                if (!on) { return; }
                on = false;
                root.classList.remove('is-drag');
                panel.style.transform = '';
                if (dy > 96) { close(); }
            };

            grip.addEventListener('touchend', end, {passive: true});
            grip.addEventListener('touchcancel', end, {passive: true});
        })();

        return {open: open, close: close, body: function () { return bodyEl; }};
    })();

    LA.sheet = sheet;

    // ------------------------------------------------------------ 平台弹层 → 底部抽屉
    //
    // 平台的 layer 弹窗（component.popup 表单 / layer.tab 多标签 / 查看卡密 / 消息预览 / 购买信息 …）
    // 在手机上被开成 100%×100% 的"全屏 PC 弹窗"：一条 PC 标题栏、一大片留白、漂在半空的保存 / 取消。
    // APP 的逻辑是底部抽屉：从底部滑上来、高度跟内容走、顶上一根抓手、可以下拽关掉、操作按钮吸底整行。
    // layer 的 DOM 契约（#layui-layerN > .layui-layer-title / -content / -btn，关闭走 layer.close(index)）
    // 一个字节不动 —— 弹层进入 body 的那一刻给它加 .la-lsheet，外形全在 App.css 里换。
    // 确认框 / 提示框（.layui-layer-dialog）不做抽屉：那是要一眼看完的东西，收成居中的小卡片（.la-lalert）。
    (function sheetify() {
        if (!('MutationObserver' in win)) { return; }
        var SKIP = /\blayui-layer-(msg|tips|loading|photos|iframe)\b/;

        var indexOf = function (el) { return parseInt(String(el.id || '').replace('layui-layer', ''), 10); };
        var closeLayer = function (el) {
            var idx = indexOf(el);
            if (!isNaN(idx) && win.layer) { win.layer.close(idx); }
        };

        var dress = function (el) {
            if (el.__laDressed || SKIP.test(el.className)) { return; }
            el.__laDressed = true;

            if (/\blayui-layer-dialog\b/.test(el.className)) {
                el.classList.add('la-lalert');
                return;
            }
            el.classList.add('la-lsheet');

            var grip = doc.createElement('div');
            grip.className = 'la-lsheet__grip';
            el.insertBefore(grip, el.firstChild);

            // 下拽关闭：抓手和标题栏都能拖；拖动中只动 transform（弹层里的下拉 / 日期面板都挂在 body 上，不受包含块影响）。
            // 只有竖向拖过 8px 才算拖：一次轻点、或者在标签条上横着滑，都不能碰 is-drag / transform ——
            // 之前一碰就加减 is-drag，抽屉的入场动画被重新触发，整张表单又从底下升一遍，
            // 补发的 mousedown 落到了还在半路的抽屉外面，标签也就切不动。
            var y0 = 0, x0 = 0, dy = 0, armed = false, dragging = false;
            var start = function (ev) {
                if (ev.touches.length !== 1) { return; }
                armed = true; dragging = false; dy = 0;
                y0 = ev.touches[0].clientY; x0 = ev.touches[0].clientX;
            };
            var move = function (ev) {
                if (!armed) { return; }
                var t = ev.touches[0];
                var ddy = t.clientY - y0, ddx = Math.abs(t.clientX - x0);
                if (!dragging) {
                    if (ddx > 8 && ddx > ddy) { armed = false; return; }   // 横着滑：交给标签条自己滚
                    if (ddy < 8) { return; }
                    dragging = true;
                    el.classList.add('is-drag');
                }
                dy = Math.max(0, ddy);
                el.style.transform = 'translate3d(0,' + dy + 'px,0)';
            };
            var end = function () {
                armed = false;
                if (!dragging) { return; }
                dragging = false;
                el.classList.remove('is-drag');
                el.style.transform = '';
                if (dy > 90) { closeLayer(el); }
            };
            var title = qs(':scope > .layui-layer-title', el);
            [grip, title].forEach(function (h) {
                if (!h) { return; }
                h.addEventListener('touchstart', start, {passive: true});
                h.addEventListener('touchmove', move, {passive: true});
                h.addEventListener('touchend', end, {passive: true});
                h.addEventListener('touchcancel', end, {passive: true});
            });

            // 多标签弹窗：标题栏是可横滑的分段条。layui 切标签认的是 mousedown，手机上轻点一下
            // 由浏览器补发的鼠标事件不可靠，这里在 touchend 上自己给它发一个（并拦掉补发，免得发两遍）；
            // 点中的那一段滚到中间。
            if (title && el.classList.contains('layui-layer-tab')) {
                var center = function (tab) {
                    title.scrollTo({left: tab.offsetLeft - (title.clientWidth - tab.offsetWidth) / 2, behavior: 'smooth'});
                };
                var tapX = 0, tapY = 0;
                title.addEventListener('touchstart', function (ev) {
                    tapX = ev.touches[0].clientX; tapY = ev.touches[0].clientY;
                }, {passive: true});
                title.addEventListener('touchend', function (ev) {
                    var tab = ev.target.closest('span');
                    var t = ev.changedTouches[0];
                    if (!tab || !t || Math.abs(t.clientX - tapX) > 10 || Math.abs(t.clientY - tapY) > 10) { return; }
                    ev.preventDefault();
                    tab.dispatchEvent(new MouseEvent('mousedown', {bubbles: true, cancelable: true}));
                    center(tab);
                }, {passive: false});
                title.addEventListener('click', function (ev) {
                    var tab = ev.target.closest('span');
                    tab && center(tab);
                });
            }

            // 点遮罩关掉：只对内容型抽屉（查看卡密 / 消息 / 购买信息），带表单的误触会丢输入
            var shade = doc.getElementById('layui-layer-shade' + indexOf(el));
            if (shade && !qs('form, .layui-form, input, textarea, select', el)) {
                shade.addEventListener('click', function () { closeLayer(el); });
            }
        };

        new MutationObserver(function (records) {
            records.forEach(function (r) {
                Array.prototype.forEach.call(r.addedNodes, function (n) {
                    if (n.nodeType === 1 && n.classList && n.classList.contains('layui-layer')) { dress(n); }
                });
            });
        }).observe(doc.body, {childList: true});
        qsa('body > .layui-layer').forEach(dress);
    })();

    // ------------------------------------------------------------ 更多菜单

    (function appMenu() {
        var panel = qs('[data-la-appmenu-panel]');
        if (!panel) { return; }

        var open = function () { panel.hidden = false; };
        var close = function () { panel.hidden = true; };

        on(doc, 'click', '[data-la-appmenu]', function (ev) { ev.preventDefault(); ev.stopPropagation(); panel.hidden ? open() : close(); });
        on(panel, 'click', '[data-la-appmenu-close]', close);
        // 选了主题/语言就收起面板（语言项随后会整页跳转，主题项换完肤面板没理由留着）
        on(panel, 'click', '[data-la-theme], [data-lang-value]', function () { setTimeout(close, 0); });
        doc.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { close(); } });
    })();

    // ------------------------------------------------------------ 返回

    on(doc, 'click', '[data-la-back]', function (ev) {
        ev.preventDefault();
        // 直接进详情页（没有站内来源）时回退会离站，兜回首页更符合 APP 直觉
        if (win.history.length > 1 && doc.referrer && doc.referrer.indexOf(win.location.origin) === 0) {
            win.history.back();
        } else {
            win.location.href = '/';
        }
    });

    on(doc, 'click', '[data-la-reload]', function () { win.location.reload(); });

    // ------------------------------------------------------------ TabBar 视图切换

    (function views() {
        var panes = qsa('[data-la-view-pane]');

        var switchTo = function (name) {
            if (!panes.length) { return false; }
            var hit = false;
            panes.forEach(function (p) {
                var on = p.getAttribute('data-la-view-pane') === name;
                p.hidden = !on;
                if (on) { hit = true; }
            });
            if (!hit) { return false; }

            qsa('.la-tabbar__item').forEach(function (item) {
                var view = item.getAttribute('data-la-view');
                item.classList.toggle('is-on', view ? view === name : (name === 'home' && !view && item.getAttribute('href') === '/'));
            });
            scroller && (scroller.scrollTop = 0);
            doc.dispatchEvent(new CustomEvent('la:view', {detail: {view: name}}));
            return true;
        };

        on(doc, 'click', '.la-tabbar__item[data-la-view]', function (ev, el) {
            var name = el.getAttribute('data-la-view');
            if (!panes.length) { return; }              // 不在首页：正常跳转
            ev.preventDefault();
            if (switchTo(name)) {
                try { win.history.replaceState(null, '', '#' + name); } catch (e) { /* 忽略 */ }
            }
        });

        // 首页带 #category / #seckill 进来时直接落到对应视图
        if (panes.length) {
            var hash = (win.location.hash || '').replace('#', '');
            if (!hash || !switchTo(hash)) { switchTo('home'); }
        }
        // 页面内任意元素都可以跳视图（瀑布流的「更多」标签就用它进分类视图）
        on(doc, 'click', '[data-la-goto]', function (ev, el) {
            if (!panes.length) { return; }
            ev.preventDefault();
            var name = el.getAttribute('data-la-goto');
            if (switchTo(name)) {
                try { win.history.replaceState(null, '', '#' + name); } catch (e) { /* 忽略 */ }
            }
        });

        LA.switchView = switchTo;
    })();

    // ------------------------------------------------------------ 页面转场

    (function transition() {
        var leaving = false;

        on(doc, 'click', 'a[href]', function (ev, a) {
            if (leaving || ev.defaultPrevented || ev.metaKey || ev.ctrlKey || ev.shiftKey) { return; }
            var href = a.getAttribute('href') || '';
            if (!href || href.charAt(0) === '#' || a.target === '_blank' || a.hasAttribute('download')) { return; }
            if (a.hasAttribute('data-la-view')) { return; }
            if (/^(https?:)?\/\//i.test(href) && href.indexOf(win.location.origin) !== 0) { return; }
            if (/^(mailto|tel|javascript):/i.test(href)) { return; }

            ev.preventDefault();
            leaving = true;
            doc.body.classList.add('is-leaving');
            setTimeout(function () { win.location.href = href; }, 170);
        });

        // 从 bfcache 返回时要把离场态清掉，否则整页停在半透明
        win.addEventListener('pageshow', function (ev) {
            if (ev.persisted) { leaving = false; doc.body.classList.remove('is-leaving'); }
        });
    })();

    LA.lazy();
    LA.reveal();
})(window, document);
