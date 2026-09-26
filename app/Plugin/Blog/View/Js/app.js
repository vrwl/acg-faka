/* 次元博客 · app.js —— 导航行为 / 我的抽屉 / 无限滚动 / 搜索交互
   全部注册为 B.page 模块：pjax 换页后会重新初始化，监听器由 B.signal 统一回收。 */
(function () {
    'use strict';
    const B = window.BLOG;
    const on = (el, ev, fn, opt) => el.addEventListener(ev, fn, Object.assign({signal: B.signal}, opt || {}));

    /* 文档级：区分「硬刷新」与「pjax/浏览器返回」。
       刷新是一次显式的「重新开始」，不该把人放回上次的位置；返回则应该接着看。
       pjax 切页不产生新的 navigation entry，所以只对本文档的首次挂载做这个判断。 */
    let restoreArmed = (function () {
        try {
            const nav = performance.getEntriesByType('navigation')[0];
            return !nav || nav.type !== 'reload';
        } catch (e) {
            return true;
        }
    })();

    /* ---------- 卡片 DOM 构建（与 PostCard.html 同构，textContent 防注入） ---------- */
    function buildCard(card) {
        const article = document.createElement('article');
        article.className = 'blog-card' + (card.top ? ' is-top' : '');
        const a = document.createElement('a');
        a.className = 'blog-card__link';
        a.href = card.url;

        if (card.cover) {
            const media = document.createElement('div');
            media.className = 'blog-card__media';
            const img = document.createElement('img');
            img.src = card.cover;
            img.alt = '';
            img.loading = 'lazy';
            img.decoding = 'async';
            media.appendChild(img);
            a.appendChild(media);
        }

        const body = document.createElement('div');
        body.className = 'blog-card__body';

        const h2 = document.createElement('h2');
        h2.className = 'blog-card__title';
        if (card.top) {
            const pin = document.createElement('span');
            pin.className = 'blog-card__pin';
            pin.textContent = B.t('pinned');
            h2.appendChild(pin);
        }
        h2.appendChild(document.createTextNode(card.title || ''));
        body.appendChild(h2);

        if (card.summary) {
            const p = document.createElement('p');
            p.className = 'blog-card__summary';
            p.textContent = card.summary;
            body.appendChild(p);
        }

        const meta = document.createElement('div');
        meta.className = 'blog-card__meta';
        if (card.category) {
            const chip = document.createElement('span');
            chip.className = 'blog-chip';
            chip.textContent = card.category.name;
            meta.appendChild(chip);
        }
        const time = document.createElement('time');
        time.dateTime = card.publish_iso || '';
        time.setAttribute('data-blog-time', card.publish_iso || '');
        time.textContent = card.publish_date || '';
        meta.appendChild(time);

        const views = document.createElement('span');
        views.className = 'blog-card__stat';
        views.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.6"/></svg>';
        views.appendChild(document.createTextNode(String(card.views || 0)));
        meta.appendChild(views);

        const comments = document.createElement('span');
        comments.className = 'blog-card__stat';
        comments.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5c0 4.5-4 7.5-9 7.5-1 0-2-.12-2.9-.35L4 20l1.2-3.6C3.8 15.1 3 13.4 3 11.5 3 7 7 4 12 4s9 3 9 7.5z"/></svg>';
        comments.appendChild(document.createTextNode(String(card.comments_count || 0)));
        meta.appendChild(comments);

        const reading = document.createElement('span');
        reading.className = 'blog-card__reading';
        reading.textContent = (card.reading || 1) + ' ' + B.t('minutes');
        meta.appendChild(reading);

        body.appendChild(meta);
        a.appendChild(body);
        article.appendChild(a);
        return article;
    }
    B.buildCard = buildCard;

    /* ---------- 顶部导航：滚动态 + 返回键 ---------- */
    B.page(function nav() {
        const nav = B.$('[data-blog-nav]');
        if (!nav) return;

        let last = null;
        const onScroll = () => {
            const scrolled = window.scrollY > 8;
            if (scrolled !== last) {
                nav.classList.toggle('is-scrolled', scrolled);
                last = scrolled;
            }
        };
        on(document, 'scroll', onScroll, {passive: true});
        onScroll();

        const back = B.$('[data-blog-back]');
        if (back) {
            on(back, 'click', () => {
                const sameOrigin = document.referrer && new URL(document.referrer, location.href).origin === location.origin;
                if (history.length > 1 && sameOrigin) history.back();
                else location.href = B.cfg.urls.index;
            });
        }
    });

    /* ---------- 「我的」抽屉 + Tab 触感 ---------- */
    B.page(function shell() {
        const meBtn = B.$('[data-blog-me-btn]');
        if (meBtn) {
            on(meBtn, 'click', () => {
                const sheet = B.$('[data-blog-sheet="me"]');
                if (sheet) B.openSheet(sheet);
            });
        }
        B.$$('.blog-tab a').forEach(a => on(a, 'click', () => B.vibrate(6)));
    });

    /* ---------- 无限滚动 ---------- */
    B.page(function infinite() {
        const list = B.$('[data-blog-list][data-blog-infinite]');
        if (!list || !('IntersectionObserver' in window)) return;

        let ctx = {};
        try { ctx = JSON.parse(list.getAttribute('data-blog-infinite') || '{}'); } catch (e) {}
        const sentinel = B.$('[data-blog-sentinel]', list);
        const skeletons = B.$('[data-blog-skeletons]', list);
        const endMark = B.$('[data-blog-end]', list);
        const pagination = B.$('[data-blog-pagination]');
        if (!sentinel) return;
        if (pagination) pagination.hidden = true;

        let page = Math.max(1, parseInt(new URLSearchParams(location.search).get('page') || '1', 10));
        let loading = false;
        //SSR 只有一页（没渲染分页组件）时无需再拉：省一次空请求也不闪骨架
        let done = !pagination;
        let io = null;
        let restoring = false;

        /* 进度记忆。
           这里存「已加载到第几页 + 滚动位置」，而不是把页码写进 URL——
           `?page=3` 的语义是「只看第 3 页」，可无限滚动之后屏幕上是 1+2+3 页，
           两者对不上：带着 ?page=3 刷新，服务端只渲染第 3 页，前两页凭空消失，
           看着就像被自动跳走了。URL 保持干净，状态放 sessionStorage。 */
        const stateKey = 'blog_flow_' + location.pathname + location.search;
        let saved = null;
        try {
            saved = JSON.parse(sessionStorage.getItem(stateKey) || 'null');
        } catch (e) {}
        if (!restoreArmed) {
            //本文档是刷新进来的：丢掉旧进度，老老实实从第一页顶部开始
            saved = null;
            try { sessionStorage.removeItem(stateKey); } catch (e) {}
        }
        restoreArmed = true; //之后同文档内的 pjax 切页都按「返回」处理

        const remember = () => {
            if (restoring) return;
            try {
                sessionStorage.setItem(stateKey, JSON.stringify({p: page, y: Math.round(window.scrollY)}));
            } catch (e) {}
        };
        let scrollTimer = null;
        on(document, 'scroll', () => {
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(remember, 300);
        }, {passive: true});

        function loadNext() {
            if (loading || done) return Promise.resolve(false);
            loading = true;
            if (skeletons) skeletons.hidden = false;
            return B.fetch('/post/list', Object.assign({page: page + 1}, ctx)).then(res => {
                const items = (res.data && res.data.list) || [];
                const total = (res.data && res.data.total) || 0;
                if (items.length) {
                    page += 1;
                    items.forEach(card => list.insertBefore(buildCard(card), skeletons));
                    B.hydrateTimes(list);
                }
                const loaded = B.$$('.blog-card', list).length;
                if (!items.length || loaded >= total) {
                    done = true;
                    if (endMark && loaded > 0) endMark.hidden = false;
                    if (io) io.disconnect();
                }
                loading = false;
                if (skeletons) skeletons.hidden = true;
                if (!restoring) remember();
                return items.length > 0;
            }).catch(() => {
                if (skeletons) skeletons.hidden = true;
                setTimeout(() => { loading = false; }, 2500); //失败退避后允许重试
                return false;
            });
        }

        io = new IntersectionObserver((entries) => {
            //恢复进度期间别让哨兵重复触发，否则会和补页请求打架
            if (!entries.some(e => e.isIntersecting) || restoring) return;
            loadNext();
        }, {rootMargin: '800px 0px'});

        //返回时把上次看到的内容补齐，再回到原位置——只补内容，不动 URL
        if (saved && saved.p > page) {
            restoring = true;
            const target = saved.p;
            (function fill() {
                if (page >= target || done) {
                    restoring = false;
                    requestAnimationFrame(() => window.scrollTo(0, saved.y || 0));
                    return;
                }
                loadNext().then(ok => {
                    if (!ok) {
                        restoring = false;
                        return;
                    }
                    fill();
                });
            })();
        } else if (saved && saved.y) {
            requestAnimationFrame(() => window.scrollTo(0, saved.y));
        }

        io.observe(sentinel);
        //切页时断开，避免观察已脱离文档的节点
        if (B.signal) B.signal.addEventListener('abort', () => io.disconnect(), {once: true});
    });

    /* ---------- 搜索页：历史记录 + 即时建议 ---------- */
    B.page(function search() {
        const searchBar = B.$('[data-blog-searchbar]');
        if (!searchBar) return;

        const input = B.$('input[name="q"]', searchBar);
        const suggestBox = B.$('[data-blog-suggest]', searchBar);
        const HISTORY_KEY = 'blog_search_history';

        if (input && input.hasAttribute('data-blog-autofocus')) {
            setTimeout(() => { try { input.focus({preventScroll: true}); } catch (e) {} }, 120);
        }

        const readHistory = () => {
            try { return JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]'); } catch (e) { return []; }
        };
        const historyBlock = B.$('[data-blog-history]');
        const renderHistory = () => {
            if (!historyBlock) return;
            const items = readHistory();
            const listEl = B.$('[data-blog-history-list]', historyBlock);
            if (!items.length || !listEl) { historyBlock.hidden = true; return; }
            historyBlock.hidden = false;
            listEl.innerHTML = '';
            items.forEach(word => {
                const a = document.createElement('a');
                a.className = 'blog-chip blog-chip--tag';
                a.href = B.cfg.urls.search + (B.cfg.urls.search.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(word);
                a.textContent = word;
                listEl.appendChild(a);
            });
        };
        renderHistory();

        const clearBtn = B.$('[data-blog-history-clear]');
        if (clearBtn) on(clearBtn, 'click', () => {
            try { localStorage.removeItem(HISTORY_KEY); } catch (e) {}
            renderHistory();
        });

        on(searchBar, 'submit', () => {
            const q = (input.value || '').trim();
            if (!q) return;
            try {
                const items = readHistory().filter(w => w !== q);
                items.unshift(q);
                localStorage.setItem(HISTORY_KEY, JSON.stringify(items.slice(0, 8)));
            } catch (e) {}
        });

        if (input && suggestBox) {
            let timer = null, activeIndex = -1;
            const hide = () => { suggestBox.hidden = true; activeIndex = -1; };
            on(input, 'input', () => {
                clearTimeout(timer);
                const q = input.value.trim();
                if (q.length < 1) { hide(); return; }
                timer = setTimeout(() => {
                    B.fetch('/search/suggest', {q}).then(res => {
                        const items = (res.data && res.data.list) || [];
                        if (!items.length) { hide(); return; }
                        suggestBox.innerHTML = '';
                        items.forEach(item => {
                            const a = document.createElement('a');
                            a.href = item.url;
                            a.textContent = item.title;
                            suggestBox.appendChild(a);
                        });
                        suggestBox.hidden = false;
                        activeIndex = -1;
                    }).catch(() => hide());
                }, 300);
            });
            on(input, 'keydown', (e) => {
                if (suggestBox.hidden) return;
                const links = B.$$('a', suggestBox);
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = e.key === 'ArrowDown'
                        ? Math.min(activeIndex + 1, links.length - 1)
                        : Math.max(activeIndex - 1, 0);
                    links.forEach((a, i) => a.classList.toggle('is-active', i === activeIndex));
                } else if (e.key === 'Enter' && activeIndex > -1) {
                    e.preventDefault();
                    links[activeIndex].click();
                } else if (e.key === 'Escape') {
                    hide();
                }
            });
            on(document, 'click', (e) => {
                if (!searchBar.contains(e.target)) hide();
            });
        }
    });
})();
