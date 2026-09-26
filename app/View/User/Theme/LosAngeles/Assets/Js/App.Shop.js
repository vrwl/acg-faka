/* ============================================================================
   洛杉矶 · 手机端商城（APP 形态）
   首页三视图（首页 / 分类 / 秒杀）+ 分类列表 + 搜索半屏面板。

   与电脑端共用 LA.card 的数据口径与标签逻辑，但交互完全是 APP 的：
   横滑轨、双列瀑布、半屏搜索、下拉刷新。
   ========================================================================= */
(function (win, doc) {
    'use strict';

    var LA = win.LA;
    if (!LA) { return; }

    var qs = LA.qs, qsa = LA.qsa, on = LA.on, esc = LA.esc;
    var scroller = qs('[data-la-scroll]');

    // ============================================================ 轮播

    (function slider() {
        var box = qs('[data-la-slider]');
        if (!box) { return; }

        var track = qs('[data-la-slider-track]', box);
        var slides = qsa('.la-appslider__slide', track);
        if (slides.length < 2) { return; }

        var dots = qs('[data-la-slider-dots]', box);
        var index = 0, timer = null, x0 = 0, dx = 0;

        if (dots) {
            dots.innerHTML = slides.map(function (_, i) {
                return '<span class="la-appslider__dot' + (i === 0 ? ' is-on' : '') + '"></span>';
            }).join('');
        }

        var to = function (i) {
            index = (i + slides.length) % slides.length;
            track.style.transform = 'translate3d(' + (-index * 100) + '%,0,0)';
            dots && qsa('.la-appslider__dot', dots).forEach(function (d, n) { d.classList.toggle('is-on', n === index); });
        };

        var play = function () { stop(); timer = setInterval(function () { to(index + 1); }, 4600); };
        var stop = function () { clearInterval(timer); timer = null; };

        box.addEventListener('touchstart', function (ev) { stop(); x0 = ev.touches[0].clientX; dx = 0; }, {passive: true});
        box.addEventListener('touchmove', function (ev) { dx = ev.touches[0].clientX - x0; }, {passive: true});
        box.addEventListener('touchend', function () {
            if (Math.abs(dx) > 40) { to(index + (dx < 0 ? 1 : -1)); }
            play();
        }, {passive: true});

        doc.addEventListener('visibilitychange', function () { doc.hidden ? stop() : play(); });
        to(0);
        play();
    })();

    // ============================================================ 金刚区翻页点

    (function kingkong() {
        var pager = qs('[data-la-kingkong]');
        var dots = qs('[data-la-kingkong-dots]');
        if (!pager || !dots) { return; }

        var pages = qsa('.la-kingkong__page', pager);
        if (pages.length < 2) { return; }

        dots.innerHTML = pages.map(function (_, i) {
            return '<span class="la-kingkong__dot' + (i === 0 ? ' is-on' : '') + '"></span>';
        }).join('');

        pager.addEventListener('scroll', LA.throttle(function () {
            var i = Math.round(pager.scrollLeft / Math.max(1, pager.clientWidth));
            qsa('.la-kingkong__dot', dots).forEach(function (d, n) { d.classList.toggle('is-on', n === i); });
        }, 120), {passive: true});
    })();

    // ============================================================ 商品流
    //
    // 两种模式：首页「猜你喜欢」无限滚动（逛），分类列表真分页（找）。
    // 上万个商品的站，靠上拉是翻不到第 200 页的。

    function Feed(opts) {
        this.slot = opts.slot;
        this.mode = opts.mode || 'scroll';
        this.sentinel = opts.sentinel || null;
        this.endEl = opts.endEl || null;
        this.countEl = opts.countEl || null;
        this.pagerEl = opts.pagerEl || null;
        this.limit = opts.limit || 20;
        this.categoryId = opts.categoryId || 0;
        this.keywords = opts.keywords || '';
        this.sort = 'default';
        this.inStock = false;
        this.page = 0;
        this.total = 0;
        this.rendered = 0;
        this.all = [];
        this.done = false;
        this.loading = false;
        if (this.mode === 'scroll' && this.sentinel) {
            var self = this;
            LA.onBottom(this.sentinel, function () { self.next(); });
        }
    }

    Feed.prototype.reset = function (page) {
        this.all = [];
        this.rendered = 0;
        this.done = false;
        this.total = 0;
        this.endEl && (this.endEl.hidden = true);
        this.slot.innerHTML = LA.skeleton(6);
        if (this.mode === 'page') {
            this.go(page || 1);
        } else {
            this.page = 0;
            this.next();
        }
    };

    Feed.prototype.go = function (n, limit) {
        if (this.loading) { return; }
        var self = this;
        if (limit) { this.limit = limit; }
        this.page = Math.max(1, parseInt(n, 10) || 1);
        this.loading = true;
        this.slot.innerHTML = LA.skeleton(6);

        LA.goods({
            categoryId: this.categoryId || undefined,
            keywords: this.keywords || undefined,
            limit: this.limit,
            page: this.page,
            ttl: 45000
        }).then(function (res) {
            self.total = res.total || 0;
            self.all = res.data || [];
            var pages = Math.max(1, Math.ceil(self.total / self.limit));
            if (self.page > pages && self.total > 0) { self.loading = false; self.go(pages); return; }
            self.render(false);
            self.paint();
            self.sync();
        }).catch(function () {
            self.empty(LA.t('商品加载失败，请稍后重试'));
            self.pagerEl && (self.pagerEl.hidden = true);
        }).finally(function () { self.loading = false; });
    };

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
        LA.pager(this.pagerEl, {page: this.page, limit: this.limit, total: this.total, sizes: [20, 40, 80]}, function (n, size) {
            self.go(n, size);
            var scroller = doc.querySelector('[data-la-scroll]');
            scroller ? scroller.scrollTo({top: 0, behavior: 'smooth'}) : win.scrollTo({top: 0, behavior: 'smooth'});
        });
    };

    Feed.prototype.sync = function () {
        if (this.mode !== 'page' || !win.history || !win.history.replaceState) { return; }
        try {
            var u = new URL(win.location.href);
            this.page > 1 ? u.searchParams.set('page', this.page) : u.searchParams.delete('page');
            this.limit !== 20 ? u.searchParams.set('limit', this.limit) : u.searchParams.delete('limit');
            this.keywords ? u.searchParams.set('keywords', this.keywords) : u.searchParams.delete('keywords');
            win.history.replaceState(null, '', u.pathname + u.search);
        } catch (e) { /* 老浏览器忽略 */ }
    };

    /** 本页视图：接口没有排序参数，排序只重排当前这一页 */
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
        this.slot.innerHTML = '<div class="la-empty">'
            + '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3.2 8 4v9.6l-8 4-8-4V7.2z"/><path d="m4 7.2 8 4 8-4"/><path d="M12 11.2v9.6"/></svg>'
            + '<div class="la-empty__title">' + esc(text || LA.t('这里还没有商品')) + '</div>'
            + '<div class="la-empty__desc">' + esc(LA.t('换个分类或关键词试试')) + '</div></div>';
    };

    Feed.prototype.render = function (appended) {
        var rows = this.view();

        if (this.countEl) {
            this.countEl.textContent = this.total ? LA.t('共 N 件').replace('N', this.total) : '';
        }

        if (!rows.length) {
            this.empty(this.inStock && this.all.length ? LA.t('本页没有现货商品') : '');
            return;
        }

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

    // ============================================================ 首页

    (function home() {
        var panes = qsa('[data-la-view-pane]');
        if (!panes.length) { return; }

        var loaders = [];

        var fill = function (slot, rows, opts) {
            if (!rows.length) { return false; }
            slot.innerHTML = rows.map(function (row) { return LA.card(row, opts); }).join('');
            LA.lazy(slot);
            return true;
        };

        // ---- 秒杀（一次扫描喂两处：首页轨道 + 秒杀视图）
        var seckillFloor = qs('[data-la-floor="seckill"]');
        var seckillAll = qs('[data-la-slot="seckill-all"]');
        var loadSeckill = function () {
            var railSlot = seckillFloor ? qs('[data-la-slot="seckill"]', seckillFloor) : null;
            railSlot && (railSlot.innerHTML = LA.skeleton(4, true));
            seckillAll && (seckillAll.innerHTML = LA.skeleton(6));

            // 接口没有秒杀过滤参数，只能自己扫；扫描有上限（≤3 页 × 100）
            var SCAN_PAGES = 3, SCAN_SIZE = 100, WANT = 30;
            var hits = [];
            var scan = function (page) {
                return LA.goods({limit: SCAN_SIZE, page: page, ttl: 60000}).then(function (res) {
                    var list = res.data || [];
                    list.forEach(function (r) { if (r.seckill_active && hits.length < WANT) { hits.push(r); } });
                    var more = list.length >= SCAN_SIZE
                        && hits.length < WANT
                        && page < SCAN_PAGES
                        && page * SCAN_SIZE < (res.total || 0);
                    return more ? scan(page + 1) : hits;
                });
            };

            return scan(1).then(function (rows) {
                if (railSlot) {
                    if (fill(railSlot, rows.slice(0, 12), {rail: true})) { seckillFloor.hidden = false; }
                    else { seckillFloor.remove(); }
                }
                if (seckillAll) {
                    if (!rows.length) {
                        seckillAll.innerHTML = '<div class="la-empty"><div class="la-empty__title">'
                            + esc(LA.t('暂无秒杀商品')) + '</div><div class="la-empty__desc">'
                            + esc(LA.t('活动马上就来，先逛逛别的')) + '</div></div>';
                    } else {
                        fill(seckillAll, rows);
                    }
                }
            }).catch(function () {
                // 首页那条轨道没数据就整块拿掉；秒杀视图是一个独立页面，不能留一屏骨架屏在那闪
                seckillFloor && seckillFloor.remove();
                if (seckillAll) {
                    seckillAll.innerHTML = '<div class="la-empty"><div class="la-empty__title">'
                        + esc(LA.t('秒杀加载失败')) + '</div><div class="la-empty__desc">'
                        + esc(LA.t('请检查网络后再试一次')) + '</div></div>';
                }
            });
        };
        // 「秒杀专区」关掉后楼层和秒杀视图都不渲染，这时再去扫 3×100 条商品纯属浪费流量
        if (seckillFloor || seckillAll) { loaders.push(loadSeckill); }

        // ---- 推荐
        var rec = qs('[data-la-floor="recommend"]');
        if (rec) {
            loaders.push(function () {
                var slot = qs('[data-la-slot="recommend"]', rec);
                slot.innerHTML = LA.skeleton(4, true);
                return LA.goods({categoryId: 'recommend', limit: 12, page: 1, ttl: 60000}).then(function (res) {
                    fill(slot, (res.data || []).slice(0, 12), {rail: true}) ? (rec.hidden = false) : rec.remove();
                }).catch(function () { rec.remove(); });
            });
        }

        // ---- 分类楼层
        qsa('[data-la-floor="category"]').forEach(function (floor) {
            var id = floor.getAttribute('data-cat');
            var slot = qs('[data-la-slot="cat-' + id + '"]', floor);
            if (!slot) { return; }
            loaders.push(function () {
                slot.innerHTML = LA.skeleton(4, true);
                return LA.goods({categoryId: id, limit: 10, page: 1, ttl: 60000}).then(function (res) {
                    fill(slot, (res.data || []).slice(0, 10), {rail: true}) ? (floor.hidden = false) : floor.remove();
                }).catch(function () { floor.remove(); });
            });
        });

        // ---- 猜你喜欢
        var fall = qs('[data-la-floor="waterfall"]');
        var feed = null;
        if (fall) {
            feed = new Feed({
                slot: qs('[data-la-slot="waterfall"]', fall),
                sentinel: qs('[data-la-fall-more]', fall),
                endEl: qs('.la-fall__end', fall),
                limit: 20
            });
            loaders.push(function () { feed.reset(); });

            on(fall, 'click', '[data-la-fall-tabs] .la-chip', function (ev, el) {
                qsa('[data-la-fall-tabs] .la-chip', fall).forEach(function (c) { c.classList.remove('is-on'); });
                el.classList.add('is-on');
                var cid = el.getAttribute('data-cat');
                // 伪分类「推荐」的 id 是字符串，parseInt 会把它变成 NaN → 0 → 等于「全部」
                feed.setCategory(cid === 'recommend' ? 'recommend' : (parseInt(cid, 10) || 0));
                el.scrollIntoView({inline: 'center', block: 'nearest', behavior: 'smooth'});
            });
        }

        var runAll = function () { loaders.forEach(function (fn) { fn(); }); };
        runAll();
        doc.addEventListener('la:refresh', runAll);

        // 搜索半屏提交后把关键词接进"猜你喜欢"（feed 只在这个作用域里）
        doc.addEventListener('la:search', function (ev) {
            var kw = ev.detail && ev.detail.keywords;
            if (!kw || !feed || !fall) { return; }
            qsa('[data-la-fall-tabs] .la-chip', fall).forEach(function (c) { c.classList.remove('is-on'); });
            feed.setKeywords(kw);
        });

        // ---- 分类视图：左一级导轨 / 右「这个分类里有什么」
        //
        //      右栏以前只放子分类图标网格。可大多数站的一级分类本身就是末级、商品直接挂在上面，
        //      点开十个有七个是「暂无子分类」的空白页。现在右栏以商品为主：
        //        分类头（图标 / 名称 / 件数 / 进入）→ 有货的子分类横滑标签 → 横排商品列表（触底续载）；
        //      整棵子树一件商品都没有时，一句「还在上新」再接「为你推荐」，右栏永远不空。
        //      件数取分类树的 commodity_count（每个节点只算自己名下的），子树合计在前端算。
        //      每次切换都新建一个 Feed 和它自己的列表容器：Feed 没有取消机制，
        //      复用同一个实例的话，上一个分类慢回来的结果会写进新分类的列表里。
        (function catview() {
            var rail = qs('[data-la-catview-rail]');
            var body = qs('[data-la-catview-body]');
            if (!rail || !body) { return; }

            var filter = qs('[data-la-catview-filter]');
            var SVG = '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
            var ICON_BOX = SVG + ' stroke-width="1.4"><path d="m12 3.2 8 4v9.6l-8 4-8-4V7.2z"/><path d="m4 7.2 8 4 8-4"/><path d="M12 11.2v9.6"/></svg>';
            var ICON_GO = SVG + ' stroke-width="2"><path d="m9.4 5.4 6.6 6.6-6.6 6.6"/></svg>';
            var ICON_SPARK = SVG + ' stroke-width="1.8"><path d="M12 3.5l1.9 4.6 4.6 1.9-4.6 1.9L12 16.5l-1.9-4.6L5.5 10l4.6-1.9z"/><path d="M18.5 15.5l.8 1.9 1.9.8-1.9.8-.8 1.9-.8-1.9-1.9-.8 1.9-.8z"/></svg>';

            body.innerHTML = '<div class="la-catpane">'
                + '<header class="la-catpane__head" data-cp-head></header>'
                + '<div class="la-chiprow la-catpane__chips" data-cp-chips hidden></div>'
                + '<div class="la-catpane__tip" data-cp-tip hidden></div>'
                + '<div data-cp-feed></div>'
                + '</div>';

            var headEl = qs('[data-cp-head]', body);
            var chipsEl = qs('[data-cp-chips]', body);
            var tipEl = qs('[data-cp-tip]', body);
            var feedBox = qs('[data-cp-feed]', body);

            var current = null;   // 当前导轨按钮
            var ticket = 0;       // 切换令牌：分类树回来时已经换了分类就作废
            var run = null;       // 正在跑的 {feed, stop}
            var state = null;     // {startId, fallbackId, withTip}，重试用
            var treeOk = false;

            function plain(html) { return String(html == null ? '' : html).replace(/<[^>]*>/g, '').trim(); }

            // 子树商品合计，算一次记在节点上
            function goodsOf(node) {
                if (!node) { return 0; }
                if (typeof node.__laGoods === 'number') { return node.__laGoods; }
                var n = Number(node.commodity_count) || 0;
                (node.children || []).forEach(function (k) { n += goodsOf(k); });
                node.__laGoods = n;
                return n;
            }

            function headHtml(node, total) {
                var name = plain(node.name) || ('#' + node.id);
                var icon = LA.safeUrl(node.icon, '');
                var ico = icon && !/\/favicon\.ico(\?|$)/i.test(icon)
                    ? '<img class="la-catpane__ico" src="' + esc(icon) + '" alt="" width="40" height="40" decoding="async">'
                    : '<span class="la-catpane__ico la-catpane__ico--txt" aria-hidden="true">' + esc(Array.from(name)[0] || '#') + '</span>';
                return ico
                    + '<div class="la-catpane__title">'
                    + '<div class="la-catpane__name">' + esc(name) + '</div>'
                    + '<div class="la-catpane__meta">' + esc(total ? LA.t('共 N 件').replace('N', total) : LA.t('暂无商品')) + '</div>'
                    + '</div>'
                    + '<a class="la-catpane__go" href="' + esc(LA.catUrl(node.id)) + '">' + esc(LA.t('进入')) + ICON_GO + '</a>';
            }

            // 标签：本级自己挂着商品时第一个是「全部」，其后是名下有商品的子孙分类（按树序拍平）
            function chipItems(node) {
                var own = Number(node.commodity_count) || 0;
                var items = own > 0 ? [{id: node.id, name: LA.t('全部'), n: own}] : [];
                (function walk(kids) {
                    (kids || []).forEach(function (k) {
                        if (!k || !k.name) { return; }
                        var n = Number(k.commodity_count) || 0;
                        if (n > 0) { items.push({id: k.id, name: plain(k.name), n: n}); }
                        walk(k.children);
                    });
                })(node.children);
                return items;
            }

            function showTip() {
                tipEl.innerHTML = '<div class="la-catpane__note">' + ICON_BOX
                    + '<div><b>' + esc(LA.t('这个分类还在上新')) + '</b><span>' + esc(LA.t('先看看这些')) + '</span></div></div>'
                    + '<div class="la-catpane__label">' + ICON_SPARK + '<span>' + esc(LA.t('为你推荐')) + '</span></div>';
                tipEl.hidden = false;
            }

            function failHtml() {
                return '<div class="la-empty la-catpane__fail">' + ICON_BOX
                    + '<div class="la-empty__title">' + esc(LA.t('商品加载失败，请稍后重试')) + '</div>'
                    + '<button type="button" class="la-catpane__retry" data-cp-retry>' + esc(LA.t('重新加载')) + '</button></div>';
            }

            // 起一条新的商品列表。withTip = 这条就是「为你推荐」兜底列表
            function mount(startId, fallbackId, withTip) {
                if (run) { run.stop(); }
                state = {startId: startId, fallbackId: fallbackId, withTip: !!withTip};

                var slotEl = doc.createElement('div');
                slotEl.className = 'la-catfeed';
                slotEl.setAttribute('aria-live', 'polite');
                var foot = doc.createElement('div');
                foot.className = 'la-fall__foot';
                foot.innerHTML = '<span class="la-fall__sentinel"></span><div class="la-fall__end" hidden>' + esc(LA.t('已经到底啦')) + '</div>';
                feedBox.innerHTML = '';
                feedBox.appendChild(slotEl);
                feedBox.appendChild(foot);

                var feed = new Feed({slot: slotEl, endEl: qs('.la-fall__end', foot), limit: 20});
                var fellBack = !!withTip;
                tipEl.hidden = !withTip;
                if (withTip) { showTip(); }

                // 列表回来是空的（件数和接口口径对不上：隐藏商品、会员等级限制…）也接「为你推荐」；
                // 出错给重试，不留一个没有出路的空态
                feed.empty = function (text) {
                    this.rendered = 0;
                    if (text) { slotEl.innerHTML = failHtml(); return; }
                    if (!fellBack && String(this.categoryId) !== String(fallbackId)) {
                        fellBack = true;
                        var self = this;
                        // 等这次请求的 finally 把 loading 放开，否则 next() 会被直接挡回去
                        setTimeout(function () {
                            if (!run || run.feed !== self) { return; }
                            state.startId = fallbackId;
                            state.withTip = true;
                            showTip();
                            self.setCategory(fallbackId);
                        }, 0);
                        return;
                    }
                    slotEl.innerHTML = '<div class="la-empty">' + ICON_BOX
                        + '<div class="la-empty__title">' + esc(LA.t('这里还没有商品')) + '</div></div>';
                };

                feed.setCategory(startId);
                run = {feed: feed, stop: LA.onBottom(qs('.la-fall__sentinel', foot), function () { feed.next(); })};
            }

            function clearPane() {
                ++ticket;
                if (run) { run.stop(); run = null; }
                headEl.innerHTML = '';
                chipsEl.hidden = true;
                tipEl.hidden = true;
                feedBox.innerHTML = '';
            }

            function show(el) {
                if (!el || el === current) { return; }
                current = el;
                qsa('.la-catview__tab', rail).forEach(function (t) {
                    var on = t === el;
                    t.classList.toggle('is-on', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                body.scrollTop = 0;

                var my = ++ticket;
                var id = el.getAttribute('data-id');
                if (run) { run.stop(); run = null; }
                headEl.innerHTML = '<span class="la-catpane__ico la-skeleton"></span>'
                    + '<div class="la-catpane__title"><div class="la-skeleton" style="height:15px;width:62%"></div>'
                    + '<div class="la-skeleton" style="height:11px;width:34%;margin-top:7px"></div></div>';
                chipsEl.hidden = true;
                tipEl.hidden = true;
                feedBox.innerHTML = '<div class="la-catfeed">' + LA.skeleton(5) + '</div>';

                var fail = function () {
                    treeOk = false;
                    headEl.innerHTML = '';
                    feedBox.innerHTML = '<div class="la-catfeed">' + failHtml() + '</div>';
                };

                LA.catIndex().then(function (map) {
                    if (my !== ticket) { return; }
                    var hit = map[id];
                    if (!hit) { fail(); return; }
                    treeOk = true;

                    var node = hit.node;
                    var total = goodsOf(node);
                    var rec = map.recommend && map.recommend.node;
                    // 兜底列表：「推荐」有货就用推荐，否则全部商品；本身就是「推荐」时兜底只能是全部商品
                    var fallbackId = rec && goodsOf(rec) > 0 && String(node.id) !== 'recommend' ? 'recommend' : 0;

                    headEl.innerHTML = headHtml(node, total);
                    // 站长上传的分类图标经常已经失效：加载失败就退回首字头像，别留一个空白方块
                    var icoImg = qs('img.la-catpane__ico', headEl);
                    icoImg && icoImg.addEventListener('error', function () {
                        var txt = doc.createElement('span');
                        txt.className = 'la-catpane__ico la-catpane__ico--txt';
                        txt.setAttribute('aria-hidden', 'true');
                        txt.textContent = Array.from(plain(node.name) || '#')[0];
                        icoImg.replaceWith(txt);
                    });

                    var items = total ? chipItems(node) : [];
                    // 只有一个选项、而且就是本级「全部」时不出标签行
                    var showChips = items.length > 1 || (items.length === 1 && String(items[0].id) !== String(node.id));
                    chipsEl.innerHTML = showChips ? items.map(function (c, i) {
                        return '<button type="button" class="la-chip' + (i === 0 ? ' is-on' : '') + '" data-cp-cat="' + esc(String(c.id)) + '">'
                            + '<span>' + esc(c.name) + '</span><span class="la-chip__n">' + c.n + '</span></button>';
                    }).join('') : '';
                    chipsEl.scrollLeft = 0;
                    chipsEl.hidden = !showChips;
                    chipsEl.__laFallback = fallbackId;

                    if (!total) { mount(fallbackId, fallbackId, true); return; }
                    mount(items.length ? items[0].id : node.id, fallbackId, false);
                }).catch(function () {
                    if (my === ticket) { fail(); }
                });
            }

            on(rail, 'click', '.la-catview__tab', function (ev, el) { show(el); });

            on(chipsEl, 'click', '[data-cp-cat]', function (ev, el) {
                if (el.classList.contains('is-on')) { return; }
                qsa('.la-chip', chipsEl).forEach(function (c) { c.classList.toggle('is-on', c === el); });
                chipsEl.scrollTo({left: el.offsetLeft - (chipsEl.clientWidth - el.offsetWidth) / 2, behavior: 'smooth'});
                // 已经往下翻过了就把标签行拉回视野，列表从头看
                var delta = chipsEl.getBoundingClientRect().top - body.getBoundingClientRect().top;
                if (delta < 0) { body.scrollTop += delta - 8; }
                var raw = el.getAttribute('data-cp-cat');
                mount(raw === 'recommend' ? 'recommend' : (parseInt(raw, 10) || 0), chipsEl.__laFallback || 0, false);
            });

            on(body, 'click', '[data-cp-retry]', function () {
                // 分类树本身没取到时 Kit 里缓存的是空树，只有整页重载才会重取
                if (!treeOk) { win.location.reload(); return; }
                LA.dropCache && LA.dropCache();
                state && mount(state.startId, state.fallbackId, state.withTip);
            });

            // 一级导轨只出前 80 个。筛选搜的是整棵树（含二三级），
            // 手机上翻一个 300 项的竖排列表是不现实的。
            if (filter) {
                var rest = qs('.la-catview__rest', rail);
                var rail0 = rail.innerHTML;

                var apply = LA.debounce(function () {
                    var kw = filter.value.trim().toLowerCase();
                    if (!kw) {
                        rail.innerHTML = rail0;
                        current = null;
                        show(qs('.la-catview__tab', rail));
                        return;
                    }
                    LA.catIndex().then(function (map) {
                        var hits = [];
                        Object.keys(map).forEach(function (id) {
                            if (hits.length >= 60) { return; }
                            var node = map[id].node;
                            if (plain(node.name).toLowerCase().indexOf(kw) === -1) { return; }
                            hits.push({id: id, name: plain(node.name)});
                        });
                        rest && (rest.hidden = true);
                        current = null;
                        if (!hits.length) {
                            rail.innerHTML = '<div class="la-catview__empty">' + esc(LA.t('没有匹配的分类')) + '</div>';
                            clearPane();
                            return;
                        }
                        rail.innerHTML = hits.map(function (h) {
                            return '<button type="button" class="la-catview__tab" role="tab" aria-selected="false" data-id="' + esc(h.id) + '">'
                                + '<span class="la-catview__tabname">' + esc(h.name) + '</span></button>';
                        }).join('');
                        show(qs('.la-catview__tab', rail));
                    });
                }, 180);
                filter.addEventListener('input', apply);
            }

            // 进这个视图才拉树，首页首屏一个多余请求都不发
            doc.addEventListener('la:view', function (ev) {
                if (ev.detail && ev.detail.view === 'category') { show(current || qs('.la-catview__tab', rail)); }
            });
            var pane = qs('[data-la-view-pane="category"]');
            if (pane && !pane.hidden) { show(qs('.la-catview__tab', rail)); }
        })();

        // ---- 公告全文
        var notice = qs('[data-la-notice]');
        var noticeHtml = qs('[data-la-notice-html]');
        if (notice && noticeHtml && LA.sheet) {
            notice.addEventListener('click', function () {
                LA.sheet.open(LA.t('店铺公告'), '<div class="la-rich">' + noticeHtml.innerHTML + '</div>');
            });
        }
    })();

    // ============================================================ 分类列表

    (function catalog() {
        var page = qs('[data-la-catalog]');
        if (!page) { return; }

        var q = null;
        try { q = new URLSearchParams(win.location.search); } catch (e) { q = null; }

        // 手机列表是 APP 逻辑：一直往下滑，滑到底自动接下一页，不出电脑端那种页码条
        var feed = new Feed({
            slot: qs('[data-la-slot="catalog"]', page),
            sentinel: qs('[data-la-catalog-more]', page),
            endEl: qs('.la-fall__end', page),
            countEl: qs('[data-la-count]', page),
            categoryId: parseInt(page.getAttribute('data-cat'), 10) || 0,
            keywords: (q && q.get('keywords')) || '',
            limit: 20
        });
        if (!feed.categoryId && page.getAttribute('data-cat') === 'recommend') { feed.categoryId = 'recommend'; }

        // 「本页」在滑动加载里没有意义了
        var baseEmpty = feed.empty;
        feed.empty = function (text) {
            baseEmpty.call(this, text === LA.t('本页没有现货商品') ? LA.t('暂时没有现货商品') : text);
        };

        var sortBar = qs('[data-la-catsort]', page);
        var hint = qs('[data-la-sort-hint]', page);
        var CAP = 200;
        var want = {sort: 'default', inStock: false};

        // 接口没有排序参数，排序只能排已经拿到手的商品。滑动加载只拿了第一页时，
        // 按销量 / 价格排出来的只是「前 20 件里的顺序」，不可信 —— 先把后面的页补载进来（最多 200 件）再排
        var fill = function () {
            return new Promise(function (resolve) {
                var guard = 0;
                (function step() {
                    if (feed.done || feed.all.length >= CAP || guard++ > 100) { resolve(); return; }
                    if (!feed.loading) { feed.next(); }
                    setTimeout(step, 120);
                })();
            });
        };

        // 排序和「有货」都等补载完一次性套上：边加载边重排，列表会跳
        var refit = function () {
            var apply = function () {
                feed.sort = want.sort;
                feed.inStock = want.inStock;
                feed.render(false);
            };
            if (want.sort === 'default' && !want.inStock) {
                hint.hidden = true;
                apply();
                return;
            }
            sortBar && sortBar.classList.add('is-busy');
            fill().then(function () {
                sortBar && sortBar.classList.remove('is-busy');
                apply();
                var partial = !feed.done && feed.all.length >= CAP;
                hint.hidden = !partial;
                if (partial) { hint.textContent = LA.t('只按前 N 件排序，继续下滑会加载更多').replace('N', feed.all.length); }
            });
        };

        feed.reset();
        doc.addEventListener('la:refresh', function () {
            LA.dropCache && LA.dropCache();
            feed.sort = 'default';
            feed.inStock = false;
            feed.reset();
            refit();
        });

        // 在本分类中搜索
        var inner = qs('[data-la-inner-search]', page);
        if (inner) {
            var input = qs('input', inner);
            var clear = qs('[data-la-inner-clear]', inner);
            var syncClear = function () { clear && (clear.hidden = !input.value); };
            var run = function () {
                feed.keywords = (input.value || '').trim();
                // 关键词记进地址栏：点进商品再返回，搜索条件还在
                try {
                    var u = new URL(win.location.href);
                    feed.keywords ? u.searchParams.set('keywords', feed.keywords) : u.searchParams.delete('keywords');
                    u.searchParams.delete('page');
                    u.searchParams.delete('limit');
                    win.history.replaceState(null, '', u.pathname + u.search);
                } catch (e) { /* 老浏览器忽略 */ }
                feed.sort = 'default';
                feed.inStock = false;
                feed.reset();
                refit();
            };
            inner.addEventListener('submit', function (ev) { ev.preventDefault(); input.blur(); run(); });
            input.addEventListener('input', syncClear);
            // 清掉关键词就回到这个分类的全部商品，否则框里是空的、列表还是搜索结果
            clear && clear.addEventListener('click', function () {
                input.value = '';
                syncClear();
                run();
            });
            if (feed.keywords) { input.value = feed.keywords; }
            syncClear();
        }

        var priceDir = 'asc';
        on(page, 'click', '[data-la-sort]', function (ev, el) {
            var mode = el.getAttribute('data-la-sort');
            if (mode.indexOf('price') === 0) {
                if (el.classList.contains('is-on')) { priceDir = priceDir === 'asc' ? 'desc' : 'asc'; }
                mode = 'price-' + priceDir;
                var ar = qs('[data-la-sort-ar]', el);
                ar && ar.classList.toggle('is-desc', priceDir === 'desc');
            } else if (el.classList.contains('is-on')) {
                return;
            }
            qsa('[data-la-sort]', page).forEach(function (b) { b.classList.toggle('is-on', b === el); });
            want.sort = mode;
            refit();
        });

        var stock = qs('[data-la-instock]', page);
        stock && stock.addEventListener('change', function () {
            want.inStock = stock.checked;
            refit();
        });
    })();

    // ============================================================ 搜索半屏

    (function search() {
        var pill = qs('[data-la-searchpill]');
        if (!pill || !LA.sheet) { return; }

        var html = '<div class="la-searchsheet">'
            + '<div class="la-searchsheet__box">'
            + '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.6 15.6 4.1 4.1"/></svg>'
            + '<input class="la-searchsheet__input" type="search" enterkeyhint="search" autocomplete="off" placeholder="' + esc(LA.t('搜索商品名称、关键词')) + '" data-la-ss-input>'
            + '<button type="button" class="la-searchsheet__go" data-la-ss-go>' + esc(LA.t('搜索')) + '</button>'
            + '</div><div class="la-searchsheet__list" data-la-ss-list></div></div>';

        var run = function (body) {
            var input = qs('[data-la-ss-input]', body);
            var list = qs('[data-la-ss-list]', body);
            var fall = qs('[data-la-floor="waterfall"]');

            setTimeout(function () { input.focus(); }, 220);

            var lookup = LA.debounce(function () {
                var kw = input.value.trim();
                if (!kw) { list.innerHTML = ''; return; }
                LA.goods({keywords: kw, limit: 8, page: 1, ttl: 30000}).then(function (res) {
                    var rows = res.data || [];
                    if (!rows.length) {
                        list.innerHTML = '<div class="la-empty"><div class="la-empty__title">' + esc(LA.t('没有找到相关商品')) + '</div></div>';
                        return;
                    }
                    list.innerHTML = rows.map(function (row) {
                        return '<a class="la-searchsheet__row" href="/item/' + (parseInt(row.id, 10) || 0) + '">'
                            + '<img src="' + esc(LA.safeUrl(row.cover, '/favicon.ico')) + '" alt="" loading="lazy" decoding="async">'
                            + '<span class="la-sugg__main"><span class="la-sugg__name la-ellipsis">' + esc(LA.t(String(row.name || '')))
                            + '</span><span class="la-sugg__cat">' + esc(row.category && row.category.name ? LA.t(row.category.name) : '') + '</span></span>'
                            + LA.priceHtml(row.price, 'sm') + '</a>';
                    }).join('');
                }).catch(function () {
                    list.innerHTML = '<div class="la-empty"><div class="la-empty__title">' + esc(LA.t('搜索失败')) + '</div>'
                        + '<div class="la-empty__desc">' + esc(LA.t('请检查网络后再试一次')) + '</div></div>';
                });
            }, 240);

            input.addEventListener('input', lookup);

            var submit = function () {
                var kw = input.value.trim();
                if (!kw) { LA.toast(LA.t('请输入要搜索的商品名称'), 'warning'); return; }
                LA.sheet.close();
                var pillText = qs('[data-la-searchpill-text]');
                if (fall && LA.switchView) {
                    LA.switchView('home');
                    pill.classList.add('is-filled');
                    pillText && (pillText.textContent = kw);
                    doc.dispatchEvent(new CustomEvent('la:search', {detail: {keywords: kw}}));
                    setTimeout(function () { fall.scrollIntoView({behavior: 'smooth', block: 'start'}); }, 260);
                } else {
                    win.location.href = '/?keywords=' + encodeURIComponent(kw);
                }
            };

            input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); submit(); } });
            on(body, 'click', '[data-la-ss-go]', submit);
        };

        pill.addEventListener('click', function () {
            LA.sheet.open(LA.t('搜索'), html, {onOpen: run});
        });
    })();
})(window, document);
