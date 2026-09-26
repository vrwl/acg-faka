/* ============================================================================
   洛杉矶 · 电脑端商城（首页楼层引擎 + 分类列表）

   同一个 INDEX 模板承载两种形态：
     · 营销首页  [data-la-home]     —— 秒杀 / 推荐 / 分类楼层 / 猜你喜欢
     · 分类列表  [data-la-catalog]  —— /cat/{id} 与站内搜索

   接口只有 GET /user/api/index/commodity（categoryId | recommend、keywords、
   limit、page），**没有排序参数**（服务端恒定 sort asc），所以：

     · 分类列表走**真分页**：页码 + 上下页 + 跳页 + 每页数量。
       几千上万个商品靠无限滚动是翻不到第 200 页的，必须给页码。
       排序只能作用在当前这一页上，所以 UI 上明写「本页排序」，
       不假装它是全站排序。
     · 首页「猜你喜欢」保留无限滚动 —— 那是逛的场景，不是找的场景。
     · 秒杀楼层扫描分页进行且有上限（最多 3 页 × 100，凑够 12 个就停），
       不会因为站里有一万个商品就把首页拖死。
       列表接口把 seckill_start/end_time 剔掉了，所以首页不做倒计时，
       只给"抢购中"的呼吸态，不编造不存在的剩余时间。
   ========================================================================= */
(function (win, doc) {
    'use strict';

    var LA = win.LA;
    if (!LA) { return; }

    var qs = LA.qs, qsa = LA.qsa, on = LA.on, esc = LA.esc;

    // ============================================================ 首屏分类导轨
    //
    // 服务端只出前 14 个一级分类，悬浮面板在这里按需建。

    (function heroRail() {
        var rail = qs('[data-la-rail]');
        if (!rail) { return; }
        var host = rail.closest('.la-hero__rail');
        var pane = qs('[data-la-rail-pane]', host);
        if (!pane) { return; }

        var timer = null, hoverTimer = null, current = null;
        var built = Object.create(null);
        var LEAF_MAX = 10;

        function html(node) {
            var kids = (node.children || []).filter(function (k) { return k && k.name; });
            var head = '<div class="la-hero__flyout-head"><h3>' + node.name + '</h3>'
                + '<a href="' + esc(LA.catUrl(node.id)) + '">' + esc(LA.t('全部'))
                + '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.4 5.4 6.6 6.6-6.6 6.6"/></svg>'
                + '</a></div>';
            if (!kids.length) { return ''; }
            var body = kids.map(function (sub) {
                var leaves = (sub.children || []).filter(function (l) { return l && l.name; });
                var shown = leaves.slice(0, LEAF_MAX);
                var rest = leaves.length - shown.length;
                var leafHtml = shown.map(function (l) {
                    return '<a href="' + esc(LA.catUrl(l.id)) + '">' + l.name + '</a>';
                }).join('');
                if (rest > 0) {
                    leafHtml += '<a class="is-more" href="' + esc(LA.catUrl(sub.id)) + '">+' + rest + '</a>';
                }
                return '<div class="la-hero__flyout-group">'
                    + '<a class="la-hero__flyout-sub" href="' + esc(LA.catUrl(sub.id)) + '">' + sub.name + '</a>'
                    + (leafHtml ? '<div class="la-hero__flyout-leaves">' + leafHtml + '</div>' : '')
                    + '</div>';
            }).join('');
            return head + '<div class="la-hero__flyout-body">' + body + '</div>';
        }

        var hide = function () {
            current = null;
            host.classList.remove('is-fly');
            pane.hidden = true;
            qsa('[data-la-rail-cat]', rail).forEach(function (c) { c.classList.remove('is-on'); });
        };

        var show = function (el) {
            var id = el.getAttribute('data-la-rail-cat');
            if (id === current) { return; }
            if (parseInt(el.getAttribute('data-kids'), 10) <= 0) { hide(); return; }
            current = id;
            qsa('[data-la-rail-cat]', rail).forEach(function (c) { c.classList.toggle('is-on', c === el); });
            host.classList.add('is-fly');
            pane.hidden = false;

            if (built[id] !== undefined) {
                built[id] ? (pane.innerHTML = built[id]) : hide();
                return;
            }
            pane.innerHTML = '<div class="la-hero__flyout-load"><span class="la-spinner"></span></div>';
            LA.catIndex().then(function (map) {
                var hit = map[id];
                var markup = hit ? html(hit.node) : '';
                built[id] = markup;
                if (current !== id) { return; }
                markup ? (pane.innerHTML = markup) : hide();
            }).catch(function () { built[id] = ''; hide(); });
        };

        on(rail, 'mouseenter', '[data-la-rail-cat]', function (ev, el) {
            clearTimeout(timer);
            clearTimeout(hoverTimer);
            hoverTimer = setTimeout(function () { show(el); }, 70);
        }, true);

        host.addEventListener('mouseleave', function () {
            clearTimeout(hoverTimer);
            timer = setTimeout(hide, 130);
        });
        host.addEventListener('mouseenter', function () { clearTimeout(timer); });
        // 树预热：鼠标一进导轨就把整棵树取回来，后面切分类是纯本地
        rail.addEventListener('mouseenter', function () { LA.cats(); }, {once: true});
    })();

    // ============================================================ 轮播

    (function slider() {
        var box = qs('[data-la-slider]');
        if (!box) { return; }

        var track = qs('[data-la-slider-track]', box);
        var slides = qsa('.la-slider__slide', track);
        if (slides.length < 2) { return; }

        var dots = qs('[data-la-slider-dots]', box);
        var index = 0, timer = null;

        if (dots) {
            dots.innerHTML = slides.map(function (_, i) {
                return '<button type="button" class="la-slider__dot' + (i === 0 ? ' is-on' : '') + '" data-go="' + i + '" aria-label="' + esc(LA.t('第 N 张').replace('N', i + 1)) + '"></button>';
            }).join('');
        }

        var to = function (i) {
            index = (i + slides.length) % slides.length;
            track.style.transform = 'translate3d(' + (-index * 100) + '%,0,0)';
            dots && qsa('.la-slider__dot', dots).forEach(function (d, n) { d.classList.toggle('is-on', n === index); });
        };

        var play = function () { stop(); timer = setInterval(function () { to(index + 1); }, 5200); };
        var stop = function () { clearInterval(timer); timer = null; };

        on(box, 'click', '[data-la-slider-prev]', function () { to(index - 1); play(); });
        on(box, 'click', '[data-la-slider-next]', function () { to(index + 1); play(); });
        dots && on(dots, 'click', '.la-slider__dot', function (ev, el) { to(parseInt(el.getAttribute('data-go'), 10) || 0); play(); });

        box.addEventListener('mouseenter', stop);
        box.addEventListener('mouseleave', play);
        doc.addEventListener('visibilitychange', function () { doc.hidden ? stop() : play(); });

        to(0);
        play();
    })();

    // ============================================================ 商品流控制器

    /**
     * 一段商品流。两种模式：
     *   mode 'scroll' —— 无限滚动（首页猜你喜欢）
     *   mode 'page'   —— 真分页（分类列表 / 搜索结果）
     *
     * 分页模式把 page/limit 同步进 URL query，刷新、前进后退、把链接发给别人
     * 都能落回同一页 —— 这是大站列表页的最低要求。
     */
    function Feed(opts) {
        this.slot = opts.slot;
        this.mode = opts.mode || 'scroll';
        this.sentinel = opts.sentinel || null;
        this.endEl = opts.endEl || null;
        this.countEl = opts.countEl || null;
        this.pagerEl = opts.pagerEl || null;
        this.topEl = opts.topEl || null;         // 翻页后滚回的锚点
        this.limit = opts.limit || 24;
        this.categoryId = opts.categoryId || 0;
        this.keywords = opts.keywords || '';
        this.sort = 'default';
        this.inStock = false;
        this.page = 0;
        this.total = 0;
        this.all = [];
        this.rendered = 0;
        this.done = false;
        this.loading = false;
        this.stopWatch = null;
        if (this.mode === 'scroll') { this.watch(); }
    }

    Feed.prototype.watch = function () {
        var self = this;
        if (!this.sentinel) { return; }
        this.stopWatch && this.stopWatch();
        this.stopWatch = LA.onBottom(this.sentinel, function () { self.next(); });
    };

    /** 从第一页重新开始（分类切换、关键词变化） */
    Feed.prototype.reset = function (page) {
        this.all = [];
        this.done = false;
        this.total = 0;
        this.rendered = 0;
        this.endEl && (this.endEl.hidden = true);
        this.slot.innerHTML = LA.skeleton(this.limit > 12 ? 12 : this.limit);
        if (this.mode === 'page') {
            this.go(page || 1);
        } else {
            this.page = 0;
            this.next();
        }
    };

    /** 分页模式：直接跳到第 n 页 */
    Feed.prototype.go = function (n, limit) {
        if (this.loading) { return; }
        var self = this;
        if (limit) { this.limit = limit; }
        this.page = Math.max(1, parseInt(n, 10) || 1);
        this.loading = true;
        this.slot.setAttribute('aria-busy', 'true');
        this.slot.innerHTML = LA.skeleton(this.limit > 12 ? 12 : this.limit);

        LA.goods({
            categoryId: this.categoryId || undefined,
            keywords: this.keywords || undefined,
            limit: this.limit,
            page: this.page,
            ttl: 45000
        }).then(function (res) {
            self.total = res.total || 0;
            self.all = res.data || [];
            // 请求的页码超出实际页数（站长下架了一批商品、别人发来的旧链接）
            var pages = Math.max(1, Math.ceil(self.total / self.limit));
            if (self.page > pages && self.total > 0) { self.loading = false; self.go(pages); return; }
            self.render(false);
            self.paint();
            self.sync();
        }).catch(function () {
            self.empty(LA.t('商品加载失败，请稍后重试'));
            self.pagerEl && (self.pagerEl.hidden = true);
        }).finally(function () {
            self.loading = false;
            self.slot.removeAttribute('aria-busy');
        });
    };

    /** 滚动模式：追加下一页 */
    Feed.prototype.next = function () {
        if (this.loading || this.done) { return; }
        var self = this;
        this.loading = true;
        this.page += 1;

        LA.goods({
            categoryId: this.categoryId || undefined,
            keywords: this.keywords || undefined,
            limit: this.limit,
            page: this.page,
            ttl: 45000
        }).then(function (res) {
            var rows = res.data || [];
            self.total = res.total || 0;
            if (self.page === 1) { self.all = []; }
            self.all = self.all.concat(rows);
            if (rows.length < self.limit || self.all.length >= self.total) { self.done = true; }
            self.render(self.page > 1);
        }).catch(function () {
            self.done = true;
            if (self.page === 1) { self.empty(LA.t('商品加载失败，请稍后重试')); }
        }).finally(function () {
            self.loading = false;
            self.endEl && (self.endEl.hidden = !self.done || !self.all.length);
        });
    };

    Feed.prototype.paint = function () {
        var self = this;
        if (!this.pagerEl) { return; }
        LA.pager(this.pagerEl, {page: this.page, limit: this.limit, total: this.total}, function (n, size) {
            self.go(n, size);
            var top = self.topEl || self.slot;
            var y = top.getBoundingClientRect().top + win.pageYOffset - 96;
            win.scrollTo({top: y < 0 ? 0 : y, behavior: 'smooth'});
        });
    };

    /** 把当前页码写回地址栏，不产生历史记录堆积（replace） */
    Feed.prototype.sync = function () {
        if (this.mode !== 'page' || !win.history || !win.history.replaceState) { return; }
        try {
            var u = new URL(win.location.href);
            this.page > 1 ? u.searchParams.set('page', this.page) : u.searchParams.delete('page');
            this.limit !== 24 ? u.searchParams.set('limit', this.limit) : u.searchParams.delete('limit');
            this.keywords ? u.searchParams.set('keywords', this.keywords) : u.searchParams.delete('keywords');
            win.history.replaceState(null, '', u.pathname + u.search);
        } catch (e) { /* 老浏览器忽略 */ }
    };

    /** 本页视图：排序与库存过滤只作用在已取回的这一页上 */
    Feed.prototype.view = function () {
        var rows = this.all.slice();
        if (this.inStock) { rows = rows.filter(function (r) { return Number(r.stock_state) > 0; }); }
        if (this.sort === 'sold') {
            rows.sort(function (a, b) { return (Number(b.order_sold) || 0) - (Number(a.order_sold) || 0); });
        } else if (this.sort === 'price-asc') {
            rows.sort(function (a, b) { return (Number(a.price) || 0) - (Number(b.price) || 0); });
        } else if (this.sort === 'price-desc') {
            rows.sort(function (a, b) { return (Number(b.price) || 0) - (Number(a.price) || 0); });
        }
        return rows;
    };

    Feed.prototype.empty = function (text) {
        this.rendered = 0;
        this.slot.innerHTML = '<div class="la-empty la-grid__full">'
            + '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3.2 8 4v9.6l-8 4-8-4V7.2z"/><path d="m4 7.2 8 4 8-4"/><path d="M12 11.2v9.6"/></svg>'
            + '<div class="la-empty__title">' + esc(text || LA.t('这里还没有商品')) + '</div>'
            + '<div class="la-empty__desc">' + esc(LA.t('换个分类或关键词试试')) + '</div></div>';
    };

    Feed.prototype.render = function (appended) {
        var rows = this.view();

        if (this.countEl) {
            this.countEl.textContent = this.total
                ? LA.t('共 N 件商品').replace('N', this.total)
                : '';
        }

        if (!rows.length) {
            this.empty(this.inStock && this.all.length ? LA.t('本页没有现货商品') : '');
            return;
        }

        // 默认排序且未过滤时只追加，滚动更顺；一旦排序/过滤生效就整块重绘
        var plain = this.sort === 'default' && !this.inStock;

        if (appended && plain && this.rendered) {
            var frag = doc.createElement('div');
            frag.innerHTML = rows.slice(this.rendered).map(function (row) { return LA.card(row); }).join('');
            while (frag.firstChild) { this.slot.appendChild(frag.firstChild); }
        } else {
            this.slot.innerHTML = rows.map(function (row) { return LA.card(row); }).join('');
        }
        this.rendered = rows.length;

        LA.lazy(this.slot);
    };

    Feed.prototype.setCategory = function (id) { this.categoryId = id; this.keywords = ''; this.reset(); };
    Feed.prototype.setKeywords = function (kw) { this.keywords = kw; this.reset(); };
    Feed.prototype.setSort = function (s) { this.sort = s; this.render(false); };
    Feed.prototype.setInStock = function (v) { this.inStock = !!v; this.render(false); };

    // ============================================================ 营销首页

    (function home() {
        var home = qs('[data-la-home]');
        if (!home) { return; }

        var fill = function (slot, rows, opts) {
            if (!rows.length) { return false; }
            slot.innerHTML = rows.map(function (row) { return LA.card(row, opts); }).join('');
            LA.lazy(slot);
            return true;
        };

        var reveal = function (floor) {
            floor.hidden = false;
            floor.setAttribute('data-la-reveal', '');
            LA.reveal(home);
        };

        // ---- 秒杀：接口没有 seckill 过滤参数，只能自己扫。
        //      扫描**有上限**：最多 3 页 × 100 条，或凑够 12 个就停。
        //      站里有一万个商品时也就是 3 个请求，不会把首页拖垮。
        var seckill = qs('[data-la-floor="seckill"]');
        if (seckill) {
            var slot = qs('[data-la-slot="seckill"]', seckill);
            slot.innerHTML = LA.skeleton(6, true);

            var SCAN_PAGES = 3, SCAN_SIZE = 100, WANT = 12;
            var hits = [];

            var scan = function (page) {
                return LA.goods({limit: SCAN_SIZE, page: page, ttl: 60000}).then(function (res) {
                    var rows = res.data || [];
                    rows.forEach(function (r) { if (r.seckill_active && hits.length < WANT) { hits.push(r); } });
                    var more = rows.length >= SCAN_SIZE
                        && hits.length < WANT
                        && page < SCAN_PAGES
                        && page * SCAN_SIZE < (res.total || 0);
                    return more ? scan(page + 1) : hits;
                });
            };

            scan(1).then(function (rows) {
                fill(slot, rows, {rail: true}) ? reveal(seckill) : seckill.remove();
            }).catch(function () { seckill.remove(); });
        }

        // ---- 推荐
        var rec = qs('[data-la-floor="recommend"]');
        if (rec) {
            var recSlot = qs('[data-la-slot="recommend"]', rec);
            recSlot.innerHTML = LA.skeleton(6);
            LA.goods({categoryId: 'recommend', limit: 12, page: 1, ttl: 60000}).then(function (res) {
                fill(recSlot, (res.data || []).slice(0, 12)) ? reveal(rec) : rec.remove();
            }).catch(function () { rec.remove(); });
        }

        // ---- 分类楼层（逐个拉，失败的那一层自己消失，不影响别人）
        qsa('[data-la-floor="category"]', home).forEach(function (floor) {
            var id = floor.getAttribute('data-cat');
            var catSlot = qs('[data-la-slot="cat-' + id + '"]', floor);
            if (!catSlot) { return; }
            catSlot.innerHTML = LA.skeleton(4);
            LA.goods({categoryId: id, limit: 8, page: 1, ttl: 60000}).then(function (res) {
                fill(catSlot, (res.data || []).slice(0, 8)) ? reveal(floor) : floor.remove();
            }).catch(function () { floor.remove(); });
        });

        // ---- 猜你喜欢
        var fall = qs('[data-la-floor="waterfall"]');
        if (fall) {
            var feed = new Feed({
                mode: 'scroll',
                slot: qs('[data-la-slot="waterfall"]', fall),
                sentinel: qs('[data-la-fall-more]', fall),
                endEl: qs('.la-fall__end', fall),
                limit: 24
            });
            feed.reset();

            // 首页标签条是 .la-tab（列表页的筛选胶囊才是 .la-chip），选择器别混
            on(fall, 'click', '[data-la-fall-tabs] .la-tab', function (ev, el) {
                qsa('[data-la-fall-tabs] .la-tab', fall).forEach(function (c) { c.classList.remove('is-on'); });
                el.classList.add('is-on');
                var cid = el.getAttribute('data-cat');
                // 伪分类「推荐」的 id 是字符串，parseInt 会把它变成 NaN → 0 → 等于「全部」
                feed.setCategory(cid === 'recommend' ? 'recommend' : (parseInt(cid, 10) || 0));
                fall.scrollIntoView({behavior: 'smooth', block: 'start'});
            });

            // 搜索一律去列表页 —— 那边才有分页和结果计数，
            // 在首页瀑布流里出结果，用户翻不到第二页
            doc.addEventListener('la:search', function (ev) {
                var kw = ev.detail && ev.detail.keywords;
                if (kw) { win.location.href = '/cat/recommend?keywords=' + encodeURIComponent(kw); }
            });
        }
    })();

    // ============================================================ 侧栏分类树
    //
    // 服务端只出一级 + 当前这一支。别的分支点开才从缓存的整棵树里现建。

    (function tree() {
        var tree = qs('[data-la-tree]');
        if (!tree) { return; }

        var active = parseInt(tree.getAttribute('data-active'), 10) || 0;
        var filter = qs('[data-la-tree-filter]');

        function branchHtml(nodes, depth) {
            return (nodes || []).filter(function (n) { return n && n.name; }).map(function (n) {
                var id = parseInt(n.id, 10) || 0;
                var kids = (n.children || []).filter(function (k) { return k && k.name; });
                return '<div class="la-tree__node" data-node="' + id + '" data-kids="' + kids.length + '">'
                    + '<div class="la-tree__row' + (id === active ? ' is-on' : '') + '" style="--d:' + depth + '">'
                    + (kids.length
                        ? '<button type="button" class="la-tree__toggle" data-la-tree-toggle aria-expanded="false" aria-label="' + esc(LA.t('展开')) + '">'
                          + '<span class="la-tree__arr" aria-hidden="true"></span></button>'
                        : '<span class="la-tree__toggle is-leaf" aria-hidden="true"></span>')
                    + '<a class="la-tree__link" href="' + esc(LA.catUrl(id)) + '"><span class="la-ellipsis">' + n.name + '</span>'
                    + (kids.length ? '<em class="la-tree__num">' + kids.length + '</em>' : '')
                    + '</a></div>'
                    + (kids.length ? '<div class="la-tree__kids" hidden data-la-tree-kids></div>' : '')
                    + '</div>';
            }).join('');
        }

        on(tree, 'click', '[data-la-tree-toggle]', function (ev, btn) {
            ev.preventDefault();
            var node = btn.closest('.la-tree__node');
            var kidsBox = qs('[data-la-tree-kids]', node);
            if (!kidsBox) { return; }

            var open = node.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            kidsBox.hidden = !open;
            if (!open || kidsBox.getAttribute('data-built')) { return; }

            var id = node.getAttribute('data-node');
            kidsBox.innerHTML = '<div class="la-tree__load"><span class="la-spinner"></span></div>';
            LA.catIndex().then(function (map) {
                var hit = map[id];
                var row = qs('.la-tree__row', node);
                var depth = (parseInt(row && row.style.getPropertyValue('--d'), 10) || 0) + 1;
                kidsBox.innerHTML = hit ? branchHtml(hit.node.children, depth) : '';
                kidsBox.setAttribute('data-built', '1');
            }).catch(function () { kidsBox.innerHTML = ''; });
        });

        // 侧栏只出前 60 个一级分类，筛选搜的是整棵树（含二三级），
        // 命中什么就把侧栏换成一张扁平的结果列表 —— 带路径，不会认错分类。
        if (filter) {
            var restEl = qs('.la-tree__rest');
            var tree0 = tree.innerHTML;

            var apply = LA.debounce(function () {
                var kw = filter.value.trim().toLowerCase();
                if (!kw) {
                    tree.innerHTML = tree0;
                    restEl && (restEl.hidden = false);
                    return;
                }
                LA.catIndex().then(function (map) {
                    var hits = [];
                    Object.keys(map).forEach(function (id) {
                        if (hits.length >= 80) { return; }
                        var node = map[id].node;
                        if (String(node.name || '').replace(/<[^>]*>/g, '').toLowerCase().indexOf(kw) === -1) { return; }
                        hits.push({id: id, node: node, path: LA.trailIn(map, id)});
                    });
                    restEl && (restEl.hidden = true);
                    if (!hits.length) {
                        tree.innerHTML = '<div class="la-tree__empty">' + esc(LA.t('没有匹配的分类')) + '</div>';
                        return;
                    }
                    tree.innerHTML = hits.map(function (h) {
                        var trail = (h.path || []).slice(0, -1).map(function (n) {
                            return String(n.name || '').replace(/<[^>]*>/g, '');
                        }).join(' / ');
                        var kids = (h.node.children || []).length;
                        return '<div class="la-tree__node"><div class="la-tree__row'
                            + (String(h.id) === String(active) ? ' is-on' : '') + '" style="--d:0">'
                            + '<span class="la-tree__toggle is-leaf" aria-hidden="true"></span>'
                            + '<a class="la-tree__link" href="' + esc(LA.catUrl(h.id)) + '">'
                            + '<span class="la-ellipsis">' + h.node.name
                            + (trail ? '<small>' + esc(trail) + '</small>' : '') + '</span>'
                            + (kids ? '<em class="la-tree__num">' + kids + '</em>' : '')
                            + '</a></div></div>';
                    }).join('');
                });
            }, 180);
            filter.addEventListener('input', apply);
        }

        // 预热：树迟早要用，空闲时先取
        (win.requestIdleCallback || function (fn) { setTimeout(fn, 600); })(function () { LA.cats(); });
    })();

    // ============================================================ 分类列表

    (function catalog() {
        var page = qs('[data-la-catalog]');
        if (!page) { return; }

        var q = {};
        try { q = new URLSearchParams(win.location.search); } catch (e) { q = null; }
        var num = function (k, d) {
            var v = q && q.get(k);
            v = parseInt(v, 10);
            return v > 0 ? v : d;
        };

        var feed = new Feed({
            mode: 'page',
            slot: qs('[data-la-slot="catalog"]', page),
            countEl: qs('[data-la-count]', page),
            pagerEl: qs('[data-la-pager]', page),
            topEl: qs('[data-la-catalog-top]', page) || page,
            categoryId: parseInt(page.getAttribute('data-cat'), 10) || 0,
            keywords: (q && q.get('keywords')) || '',
            limit: num('limit', 24)
        });
        if (!feed.categoryId && page.getAttribute('data-cat') === 'recommend') { feed.categoryId = 'recommend'; }
        feed.reset(num('page', 1));

        // 排序只作用在当前这一页（接口无排序参数），模板里已明写「本页排序」
        var priceDir = 'asc';
        on(page, 'click', '[data-la-sort]', function (ev, el) {
            var mode = el.getAttribute('data-la-sort');
            if (mode.indexOf('price') === 0) {
                if (el.classList.contains('is-on')) { priceDir = priceDir === 'asc' ? 'desc' : 'asc'; }
                mode = 'price-' + priceDir;
                var ar = qs('[data-la-sort-ar]', el);
                ar && ar.classList.toggle('is-desc', priceDir === 'desc');
            }
            qsa('[data-la-sort]', page).forEach(function (b) { b.classList.remove('is-on'); });
            el.classList.add('is-on');
            feed.setSort(mode);
        });

        var stock = qs('[data-la-instock]', page);
        stock && stock.addEventListener('change', function () { feed.setInStock(stock.checked); });

        // 在本分类中搜索：几百个分类的站，全站搜索命中太杂
        var inner = qs('[data-la-inner-search]', page);
        if (inner) {
            var input = qs('input', inner);
            var run = function () {
                var kw = (input.value || '').trim();
                feed.keywords = kw;
                feed.reset(1);
                var chip = qs('[data-la-inner-chip]', page);
                if (chip) {
                    chip.hidden = !kw;
                    var label = qs('[data-la-inner-kw]', chip);
                    label && (label.textContent = kw);
                }
            };
            inner.addEventListener('submit', function (ev) { ev.preventDefault(); run(); });
            on(page, 'click', '[data-la-inner-clear]', function () { input.value = ''; run(); });
        }

        doc.addEventListener('la:search', function (ev) {
            var kw = ev.detail && ev.detail.keywords;
            if (kw) { win.location.href = '/cat/recommend?keywords=' + encodeURIComponent(kw); }
        });
    })();
})(window, document);
