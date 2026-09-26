/* ============================================================================
   洛杉矶 · 电脑端外壳运行时
   吸顶阴影 / 巨型菜单 / 搜索联想 / 浮层 / 滚动公告 / 回到顶部
   ========================================================================= */
(function (win, doc) {
    'use strict';

    var LA = win.LA;
    if (!LA || doc.documentElement.getAttribute('data-la-shell') === 'pc') { return; }
    doc.documentElement.setAttribute('data-la-shell', 'pc');

    var qs = LA.qs, qsa = LA.qsa, on = LA.on, esc = LA.esc;

    // ------------------------------------------------------------ 吸顶

    (function stickyHeader() {
        var header = qs('[data-la-header]');
        if (!header) { return; }
        var stuck = false;
        var sync = function () {
            var now = win.scrollY > 8;
            if (now !== stuck) { stuck = now; header.classList.toggle('is-stuck', now); }
        };
        win.addEventListener('scroll', LA.throttle(sync, 100), {passive: true});
        sync();
    })();

    // ------------------------------------------------------------ 通用浮层

    (function popovers() {
        var closeAll = function (except) {
            qsa('.la-pop.is-open').forEach(function (pop) {
                if (pop === except) { return; }
                pop.classList.remove('is-open');
                var trigger = qs('[data-la-pop-trigger]', pop);
                trigger && trigger.setAttribute('aria-expanded', 'false');
            });
        };

        on(doc, 'click', '[data-la-pop-trigger]', function (ev, el) {
            var pop = el.closest('[data-la-pop]');
            if (!pop) { return; }
            ev.preventDefault();
            var open = !pop.classList.contains('is-open');
            closeAll(pop);
            pop.classList.toggle('is-open', open);
            el.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        // 点到浮层外面才关。
        // 这里**不能**靠触发器里的 stopPropagation：打开逻辑和这条"点外面关闭"
        // 都挂在 document 上，stopPropagation 只拦冒泡、拦不住同一节点上的其它监听，
        // 结果就是每次点击"打开→立刻关闭"，浮层永远出不来。
        // 判断点击源是否在某个 [data-la-pop] 里，才是可靠的做法。
        doc.addEventListener('click', function (ev) {
            var t = ev.target;
            if (t && t.closest && t.closest('[data-la-pop]')) { return; }
            closeAll(null);
        });

        // 选完一项就收起。主题项换完肤、语言项要跳转、账户菜单是链接 ——
        // 没有任何一种情况需要面板在选完之后继续挂着。
        // 这条也挂在 document 上，和主题切换（Kit.js 的 [data-la-theme] 委托）互不影响：
        // 两个监听都会跑，一个换肤一个收面板。
        on(doc, 'click', '.la-pop__item', function () { closeAll(null); });
        doc.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { closeAll(null); } });
    })();

    // ------------------------------------------------------------ 巨型菜单
    //
    // 服务端只出一级分类导轨；子级面板在这里按需构建。
    // 大站几百个分类、上千个节点，整棵树渲染进 HTML 会把首页撑到几 MB。

    (function mega() {
        var mega = qs('[data-la-mega]');
        if (!mega) { return; }

        var trigger = qs('[data-la-mega-trigger]', mega);
        var panel = qs('[data-la-mega-panel]', mega);
        var rail = qs('.la-mega__rail', mega);
        var body = qs('[data-la-mega-body]', mega);
        var filter = qs('[data-la-mega-filter]', mega);
        if (!trigger || !panel || !rail || !body) { return; }

        var openTimer = null, closeTimer = null, hoverTimer = null;
        var index = null;          // id -> {node, parent}
        var built = Object.create(null);
        var current = null;

        var LEAF_MAX = 14;         // 单个二级分类下最多列几个三级，多的收进"更多"

        function paneHtml(node) {
            var kids = (node.children || []).filter(function (k) { return k && k.name; });
            var head = '<div class="la-mega__pane-head">'
                + '<h3>' + node.name + '</h3>'
                + '<a class="la-mega__all" href="' + esc(LA.catUrl(node.id)) + '">' + esc(LA.t('查看全部'))
                + '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.4 5.4 6.6 6.6-6.6 6.6"/></svg>'
                + '</a></div>';

            if (!kids.length) {
                return '<div class="la-mega__pane">' + head
                    + '<a class="la-mega__solo" href="' + esc(LA.catUrl(node.id)) + '">'
                    + '<span class="la-mega__solo-ico">'
                    + (node.icon ? '<img src="' + esc(LA.safeUrl(node.icon, '')) + '" alt="" width="40" height="40" loading="lazy" decoding="async">'
                        : '<svg class="la-i la-i--xl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3.2 8 4v9.6l-8 4-8-4V7.2z"/><path d="m4 7.2 8 4 8-4"/><path d="M12 11.2v9.6"/></svg>')
                    + '</span>'
                    + '<strong class="la-mega__solo-name">' + node.name + '</strong>'
                    + '<span class="la-mega__solo-desc">' + esc(LA.t('查看该分类下的全部商品')) + '</span>'
                    + '<span class="la-mega__solo-btn">' + esc(LA.t('进入专区'))
                    + '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.4 5.4 6.6 6.6-6.6 6.6"/></svg>'
                    + '</span></a></div>';
            }

            var groups = kids.map(function (sub) {
                var leaves = (sub.children || []).filter(function (l) { return l && l.name; });
                var shown = leaves.slice(0, LEAF_MAX);
                var rest = leaves.length - shown.length;
                var leafHtml = shown.map(function (l) {
                    return '<a class="la-mega__leaf" href="' + esc(LA.catUrl(l.id)) + '">' + l.name + '</a>';
                }).join('');
                if (rest > 0) {
                    leafHtml += '<a class="la-mega__leaf la-mega__leaf--more" href="' + esc(LA.catUrl(sub.id)) + '">'
                        + esc(LA.t('更多 N 个').replace('N', rest)) + '</a>';
                }
                return '<div class="la-mega__group">'
                    + '<a class="la-mega__sub" href="' + esc(LA.catUrl(sub.id)) + '">'
                    + (sub.icon ? '<img class="la-mega__sub-ico" src="' + esc(LA.safeUrl(sub.icon, '')) + '" alt="" width="30" height="30" loading="lazy" decoding="async">' : '')
                    + '<span class="la-ellipsis">' + sub.name + '</span></a>'
                    + (leafHtml ? '<div class="la-mega__leaves">' + leafHtml + '</div>' : '')
                    + '</div>';
            }).join('');

            return '<div class="la-mega__pane">' + head + '<div class="la-mega__groups">' + groups + '</div></div>';
        }

        function showPane(btn) {
            if (!btn || btn === current) { return; }
            current = btn;
            qsa('.la-mega__cat', rail).forEach(function (b) {
                var on = b === btn;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            var id = btn.getAttribute('data-id');
            if (built[id]) { body.innerHTML = built[id]; return; }

            // 首次进入某个分类才建它的 DOM
            body.innerHTML = '<div class="la-mega__loading"><span class="la-spinner"></span></div>';
            LA.catIndex().then(function (map) {
                index = map;
                var hit = map[id];
                var html = hit ? paneHtml(hit.node) : '';
                built[id] = html;
                if (current === btn) { body.innerHTML = html; }
            });
        }

        var open = function () {
            clearTimeout(closeTimer);
            if (mega.classList.contains('is-open')) { return; }
            panel.hidden = false;
            mega.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            // 打开就把树预热好，后面切分类才是瞬时的
            LA.cats();
            showPane(current || qs('.la-mega__cat', rail));
        };

        var close = function () {
            clearTimeout(openTimer);
            if (!mega.classList.contains('is-open')) { return; }
            mega.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
            closeTimer = setTimeout(function () {
                if (!mega.classList.contains('is-open')) { panel.hidden = true; }
            }, 220);
        };

        mega.addEventListener('mouseenter', function () { openTimer = setTimeout(open, 90); });
        mega.addEventListener('mouseleave', function () { clearTimeout(openTimer); closeTimer = setTimeout(close, 160); });
        trigger.addEventListener('click', function (ev) {
            ev.preventDefault();
            mega.classList.contains('is-open') ? close() : open();
        });
        doc.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { close(); } });
        win.addEventListener('scroll', function () { close(); }, {passive: true});
        doc.addEventListener('click', function (ev) { if (!mega.contains(ev.target)) { close(); } });

        // 悬停切换：加一点延迟，斜着划过导轨时不会连闪好几个面板
        on(rail, 'mouseenter', '.la-mega__cat', function (ev, el) {
            clearTimeout(hoverTimer);
            hoverTimer = setTimeout(function () { showPane(el); }, 60);
        }, true);
        on(rail, 'focus', '.la-mega__cat', function (ev, el) { showPane(el); }, true);
        on(rail, 'click', '.la-mega__cat', function (ev, el) {
            var id = el.getAttribute('data-id');
            if (id) { win.location.href = LA.catUrl(id); }
        });

        // 导轨只出前 40 个一级分类。过滤框搜的是**整棵树**（含二三级），
        // 命中什么就把导轨换成什么 —— 300 项的长列表翻不动，搜索才有用。
        if (filter) {
            var rests = qs('.la-mega__rest', mega);
            var rail0 = rail.innerHTML;

            var restore = function () {
                rail.innerHTML = rail0;
                rests && (rests.hidden = false);
                current = null;
                showPane(qs('.la-mega__cat', rail));
            };

            var apply = LA.debounce(function () {
                var kw = filter.value.trim().toLowerCase();
                if (!kw) { restore(); return; }

                LA.catIndex().then(function (map) {
                    var hits = [];
                    Object.keys(map).forEach(function (id) {
                        if (hits.length >= 60) { return; }
                        var node = map[id].node;
                        var name = String(node.name || '').replace(/<[^>]*>/g, '');
                        if (name.toLowerCase().indexOf(kw) === -1) { return; }
                        hits.push({id: id, name: node.name, path: LA.trailIn(map, id), kids: (node.children || []).length});
                    });

                    rests && (rests.hidden = true);
                    if (!hits.length) {
                        rail.innerHTML = '<div class="la-mega__empty">' + esc(LA.t('没有匹配的分类')) + '</div>';
                        body.innerHTML = '';
                        current = null;
                        return;
                    }

                    rail.innerHTML = hits.map(function (h) {
                        // 命中的可能是三级分类，把路径写出来，不然一堆同名分类分不清
                        var trail = (h.path || []).slice(0, -1).map(function (n) {
                            return String(n.name || '').replace(/<[^>]*>/g, '');
                        }).join(' / ');
                        return '<button type="button" class="la-mega__cat" role="tab" aria-selected="false"'
                            + ' data-id="' + esc(h.id) + '" data-kids="' + h.kids + '">'
                            + '<span class="la-mega__cat-ico is-dot"></span>'
                            + '<span class="la-mega__cat-name la-ellipsis">' + h.name
                            + (trail ? '<small>' + esc(trail) + '</small>' : '') + '</span>'
                            + (h.kids ? '<span class="la-mega__cat-go" aria-hidden="true"></span>' : '')
                            + '</button>';
                    }).join('');
                    current = null;
                    showPane(qs('.la-mega__cat', rail));
                });
            }, 180);

            filter.addEventListener('input', apply);
            filter.addEventListener('click', function (ev) { ev.stopPropagation(); });
        }
    })();

    // ------------------------------------------------------------ 公告条

    // 首页公告压成一行跑马灯式的条，点开才出全文（富文本，原样渲染）
    (function notice() {
        var bar = qs('[data-la-notice]');
        var full = qs('[data-la-notice-html]');
        if (!bar || !full) { return; }
        bar.addEventListener('click', function () {
            LA.panel(LA.t('店铺公告'), '<div class="la-rich">' + full.innerHTML + '</div>');
        });
    })();

    // ------------------------------------------------------------ 搜索联想

    (function search() {
        var form = qs('[data-la-search]');
        if (!form) { return; }

        var input = qs('[data-la-search-input]', form);
        var drop = qs('[data-la-search-drop]', form);
        var clear = qs('[data-la-search-clear]', form);
        if (!input || !drop) { return; }

        var items = [];
        var cursor = -1;

        var hide = function () { drop.hidden = true; cursor = -1; };

        var highlight = function (text, kw) {
            var safe = esc(text);
            if (!kw) { return safe; }
            var i = text.toLowerCase().indexOf(kw.toLowerCase());
            if (i < 0) { return safe; }
            return esc(text.slice(0, i)) + '<span class="la-sugg__hit">' + esc(text.slice(i, i + kw.length)) + '</span>' + esc(text.slice(i + kw.length));
        };

        var render = function (rows, kw) {
            items = rows || [];
            if (!items.length) {
                drop.innerHTML = '<div class="la-sugg__empty">' + esc(LA.t('没有找到相关商品')) + '</div>';
                drop.hidden = false;
                return;
            }
            var html = items.map(function (row) {
                return '<a class="la-sugg" href="/item/' + (parseInt(row.id, 10) || 0) + '">'
                    + '<img class="la-sugg__img" src="' + esc(LA.safeUrl(row.cover, '/favicon.ico')) + '" alt="" loading="lazy" decoding="async">'
                    + '<span class="la-sugg__main">'
                    + '<span class="la-sugg__name la-ellipsis">' + highlight(LA.t(String(row.name || '')), kw) + '</span>'
                    + '<span class="la-sugg__cat">' + esc(row.category && row.category.name ? LA.t(row.category.name) : '') + '</span>'
                    + '</span>'
                    + LA.priceHtml(row.price, 'sm')
                    + '</a>';
            }).join('');
            html += '<button type="button" class="la-sugg__more" data-la-search-all>' + esc(LA.t('查看全部结果')) + '</button>';
            drop.innerHTML = html;
            drop.hidden = false;
            cursor = -1;
        };

        var lookup = LA.debounce(function () {
            var kw = input.value.trim();
            clear.hidden = kw === '';
            if (kw.length < 1) { hide(); return; }
            LA.goods({keywords: kw, limit: 6, page: 1, ttl: 30000})
                .then(function (res) { render((res.data || []).slice(0, 6), kw); })
                .catch(function () { hide(); });
        }, 220);

        input.addEventListener('input', lookup);
        input.addEventListener('focus', function () { if (input.value.trim() && items.length) { drop.hidden = false; } });

        clear && clear.addEventListener('click', function () {
            input.value = '';
            clear.hidden = true;
            hide();
            input.focus();
        });

        input.addEventListener('keydown', function (ev) {
            var nodes = qsa('.la-sugg', drop);
            if (ev.key === 'Escape') { hide(); return; }
            if (!nodes.length || drop.hidden) { return; }
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                cursor = ev.key === 'ArrowDown'
                    ? (cursor + 1) % nodes.length
                    : (cursor - 1 + nodes.length) % nodes.length;
                nodes.forEach(function (n, i) { n.classList.toggle('is-on', i === cursor); });
                nodes[cursor].scrollIntoView({block: 'nearest'});
            } else if (ev.key === 'Enter' && cursor >= 0) {
                ev.preventDefault();
                win.location.href = nodes[cursor].getAttribute('href');
            }
        });

        var go = function () {
            var kw = input.value.trim();
            if (!kw) { LA.toast(LA.t('请输入要搜索的商品名称'), 'warning'); return; }
            hide();
            // 首页就地搜索（不刷新），其它页跳回首页带上关键词
            if (doc.body.getAttribute('data-la-page') === 'home') {
                doc.dispatchEvent(new CustomEvent('la:search', {detail: {keywords: kw}}));
            } else {
                win.location.href = '/?keywords=' + encodeURIComponent(kw);
            }
        };

        form.addEventListener('submit', function (ev) { ev.preventDefault(); go(); });
        on(drop, 'click', '[data-la-search-all]', go);
        doc.addEventListener('click', function (ev) { if (!form.contains(ev.target)) { hide(); } });

        // 首页带 ?keywords= 进来时回填搜索框
        try {
            var pre = new URLSearchParams(win.location.search).get('keywords');
            if (pre) { input.value = pre; clear.hidden = false; }
        } catch (e) { /* 老浏览器忽略 */ }
    })();

    // ------------------------------------------------------------ 滚动公告

    (function marquee() {
        qsa('[data-la-marquee]').forEach(function (box) {
            var text = qs('.la-marquee__text', box);
            if (!text) { return; }
            var run = function () {
                var overflow = text.scrollWidth > box.clientWidth;
                box.classList.toggle('is-run', overflow);
                if (overflow) {
                    // 恒定速度（约 60px/s）而不是恒定时长，长短公告观感一致
                    box.style.setProperty('--la-marquee-dur', Math.max(14, (text.scrollWidth + box.clientWidth) / 60) + 's');
                }
            };
            run();
            win.addEventListener('resize', LA.debounce(run, 200));
        });
    })();

    // ------------------------------------------------------------ 回到顶部

    (function toTop() {
        var btn = doc.createElement('button');
        btn.type = 'button';
        btn.className = 'la-totop';
        btn.setAttribute('aria-label', LA.t('回到顶部'));
        btn.innerHTML = '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5.4 14.6 6.6-6.6 6.6 6.6"/></svg>';
        doc.body.appendChild(btn);

        var jump = function () { win.scrollTo({top: 0, behavior: 'smooth'}); };
        btn.addEventListener('click', jump);
        on(doc, 'click', '[data-la-top]', jump);

        win.addEventListener('scroll', LA.throttle(function () {
            btn.classList.toggle('is-on', win.scrollY > 520);
        }, 160), {passive: true});
    })();

    // ------------------------------------------------------------ 杂项

    on(doc, 'click', '[data-la-reload]', function () { win.location.reload(); });

    LA.lazy();
    LA.reveal();
})(window, document);
