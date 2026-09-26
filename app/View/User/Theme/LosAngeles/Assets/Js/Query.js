/* ============================================================================
   洛杉矶 · 订单查询（电脑端与手机端共用）

   接口：
     POST /user/api/index/query  {keywords,page,limit} → {list:[...], total}
        免登录，按 IP 限流 30 次 / 10 分钟
     POST /user/api/index/secret {tradeNo,password}    → {secret, leave_message}
        限流更严（单号+IP 8 次 / 10 分钟），所以只在用户点"查看卡密"时才请求

   订单字段：status 0=未支付 1=已支付；delivery_status 0=未发货 1=已发货；
   password 为 true 表示下单时设了查询密码，卡密要输密码才给。
   ========================================================================= */
(function (win, doc) {
    'use strict';

    var LA = win.LA;
    var page = LA && LA.qs('[data-la-query]');
    if (!LA || !page) { return; }

    var qs = LA.qs, qsa = LA.qsa, on = LA.on, esc = LA.esc;
    var isApp = doc.body.classList.contains('la--app');
    var form = qs('[data-la-query-form]', page);
    var input = qs('[data-la-query-input]', page);
    var list = qs('[data-la-query-list]', page);
    var endEl = qs('.la-fall__end', page);
    var sentinel = qs('[data-la-fall-more]', page);

    var state = {keywords: '', page: 0, total: 0, rows: [], done: true, loading: false};

    function statusChip(row) {
        if (Number(row.status) !== 1) {
            return '<span class="la-ostat la-ostat--wait">' + esc(LA.t('待支付')) + '</span>';
        }
        return Number(row.delivery_status) === 1
            ? '<span class="la-ostat la-ostat--done">' + esc(LA.t('已发货')) + '</span>'
            : '<span class="la-ostat la-ostat--ship">' + esc(LA.t('待发货')) + '</span>';
    }

    function spec(row) {
        var parts = [];
        if (row.race) { parts.push(String(row.race)); }
        if (row.sku) {
            try {
                var sku = typeof row.sku === 'string' ? JSON.parse(row.sku) : row.sku;
                if (sku && typeof sku === 'object') {
                    Object.keys(sku).forEach(function (k) { parts.push(k + '：' + sku[k]); });
                }
            } catch (e) { parts.push(String(row.sku)); }
        }
        return parts.length ? '<div class="la-ocard__spec">' + esc(LA.t(parts.join(' · '))) + '</div>' : '';
    }

    function card(row) {
        var commodity = row.commodity || {};
        var cover = LA.safeUrl(commodity.cover, '/favicon.ico');
        var paid = Number(row.status) === 1;
        var delivered = Number(row.delivery_status) === 1;
        var locked = row.password === true;

        var action = '';
        if (paid && delivered) {
            action = '<button type="button" class="la-btn la-btn--brand la-btn--sm" data-la-secret="' + esc(row.trade_no) + '"'
                + (locked ? ' data-locked="1"' : '') + '>'
                + esc(LA.t(locked ? '输入密码查看' : '查看卡密')) + '</button>';
        } else if (paid) {
            action = '<span class="la-ocard__tip">' + esc(LA.t('商家正在为你处理')) + '</span>';
        } else {
            action = '<span class="la-ocard__tip">' + esc(LA.t('订单尚未支付')) + '</span>';
        }

        return '<article class="la-ocard">'
            + '<header class="la-ocard__head">'
            + '<span class="la-ocard__no la-num">' + esc(row.trade_no) + '</span>'
            + statusChip(row)
            + '</header>'
            + '<div class="la-ocard__body">'
            + '<img class="la-ocard__img" src="' + esc(cover) + '" alt="" loading="lazy" decoding="async">'
            + '<div class="la-ocard__main">'
            + '<div class="la-ocard__name la-clamp-2">' + esc(LA.t(String(commodity.name || LA.t('商品已下架')))) + '</div>'
            + spec(row)
            + '<div class="la-ocard__meta">'
            + '<span>' + esc(LA.t('数量')) + ' <b class="la-num">' + (parseInt(row.card_num, 10) || 0) + '</b></span>'
            + '<span>' + esc(LA.t('下单')) + ' ' + esc(row.create_time || '-') + '</span>'
            + '</div></div>'
            + '<div class="la-ocard__right">' + LA.priceHtml(row.amount, 'sm')
            + (row.pay && row.pay.name ? '<span class="la-ocard__pay">' + esc(LA.t(row.pay.name)) + '</span>' : '')
            + '</div></div>'
            + '<footer class="la-ocard__foot">'
            + '<span class="la-ocard__contact">' + esc(LA.t('联系方式')) + '：' + esc(row.contact || '-') + '</span>'
            + action
            + '</footer></article>';
    }

    function empty(text, desc) {
        list.innerHTML = '<div class="la-empty">'
            + '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5.5 3.2h13v18l-2.6-1.7-2.6 1.7-2.6-1.7-2.6 1.7z"/><path d="M9 8.2h6"/><path d="M9 12.4h6"/></svg>'
            + '<div class="la-empty__title">' + esc(text) + '</div>'
            + '<div class="la-empty__desc">' + esc(desc || '') + '</div></div>';
    }

    function render(appended) {
        if (!state.rows.length) {
            empty(LA.t('没有查到订单'), LA.t('请核对订单号或下单时填写的联系方式'));
            return;
        }
        var html = state.rows.map(card).join('');
        if (appended) {
            var frag = doc.createElement('div');
            frag.innerHTML = state.rows.slice(state.rendered || 0).map(card).join('');
            while (frag.firstChild) { list.appendChild(frag.firstChild); }
        } else {
            list.innerHTML = html;
        }
        state.rendered = state.rows.length;
        LA.lazy(list);
    }

    function load(reset) {
        if (state.loading) { return; }
        if (reset) { state.page = 0; state.rows = []; state.rendered = 0; state.done = false; endEl && (endEl.hidden = true); }
        if (state.done) { return; }

        state.loading = true;
        state.page += 1;
        if (state.page === 1) { list.innerHTML = '<div class="la-queryload"><span class="la-spinner"></span></div>'; }

        LA.post('/user/api/index/query', {keywords: state.keywords, page: state.page, limit: 10})
            .then(function (res) {
                var d = (res && res.data) || {};
                var rows = d.list || [];
                state.total = d.total || rows.length;
                state.rows = state.rows.concat(rows);
                if (rows.length < 10 || state.rows.length >= state.total) { state.done = true; }
                render(state.page > 1);
            })
            .catch(function (res) {
                state.done = true;
                if (state.page === 1) { empty((res && res.msg) || LA.t('没有查到订单'), LA.t('稍后再试，或联系客服协助')); }
            })
            .finally(function () {
                state.loading = false;
                endEl && (endEl.hidden = !state.done || !state.rows.length);
            });
    }

    form && form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var kw = (input.value || '').trim();
        if (!kw) { LA.toast(LA.t('请输入订单号或联系方式'), 'warning'); input.focus(); return; }
        state.keywords = kw;
        // 查询结果可分享/可刷新：把关键词写进地址栏
        try { win.history.replaceState(null, '', '?tradeNo=' + encodeURIComponent(kw)); } catch (e) { /* 忽略 */ }
        load(true);
    });

    sentinel && LA.onBottom(sentinel, function () { load(false); });
    doc.addEventListener('la:refresh', function () { if (state.keywords) { load(true); } });

    // ------------------------------------------------------------ 查看卡密

    function showSecret(tradeNo, password) {
        LA.post('/user/api/index/secret', {tradeNo: tradeNo, password: password || ''}, {loader: true})
            .then(function (res) {
                var d = (res && res.data) || {};
                if (d.secret == null || d.secret === '') {
                    LA.toast(LA.t('该订单还没有可查看的卡密'), 'warning');
                    return;
                }
                if (win.treasure && typeof win.treasure.show === 'function') {
                    win.treasure.show(tradeNo, d.secret, d.leave_message);
                } else {
                    LA.toast(String(d.secret), 'success');
                }
            })
            .catch(function (res) { LA.toast((res && res.msg) || LA.t('卡密获取失败'), 'error'); });
    }

    on(page, 'click', '[data-la-secret]', function (ev, el) {
        var tradeNo = el.getAttribute('data-la-secret');
        if (el.getAttribute('data-locked') !== '1') { showSecret(tradeNo, ''); return; }

        // 密码保护的订单：密码接口限流很严（单号+IP 8 次 / 10 分钟），必须让用户一次填对。
        // 不用平台的 message.prompt —— 它依赖 SweetAlert2，而商城页没打包这个库。
        LA.prompt(LA.t('请输入订单密码'), {
            desc: LA.t('下单时设置的查询密码，输错次数过多会被暂时限制'),
            placeholder: LA.t('订单密码'),
            password: true
        }).then(function (value) {
            if (!value) { LA.toast(LA.t('请输入订单密码'), 'warning'); return; }
            showSecret(tradeNo, value);
        });
    });

    // ------------------------------------------------------------ 带参进入直接查

    (function auto() {
        var kw = (input && input.value || '').trim();
        if (!kw) {
            try { kw = new URLSearchParams(win.location.search).get('tradeNo') || ''; } catch (e) { kw = ''; }
            if (kw && input) { input.value = kw; }
        }
        if (kw) { state.keywords = kw; load(true); }
        else if (!isApp) { empty(LA.t('输入订单号或联系方式开始查询'), LA.t('订单号是下单后给你的 18 位数字')); }
    })();
})(window, document);
