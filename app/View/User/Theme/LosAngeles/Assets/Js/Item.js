/* ============================================================================
   洛杉矶 · 商品详情 / 下单引擎（电脑端与手机端共用）

   两端的模板、样式、交互都是分开写的，但「下单」这条链路只能有一份真相：
   实时算价 → 实时库存 → 取支付方式 → 提交订单 → 发卡/跳转。
   任何一端单独改都会造成收款口径不一致，所以引擎放这一份，
   端特有的外壳行为（放大镜 / 详情 Tab / 半屏下单面板）在文件末尾按端分支。

   接口契约：
     POST /user/api/index/valuation  item_id,num,race,sku[],card_id,coupon → {price}
     POST /user/api/index/stock      item_id,race,sku[]                    → {stock,stock_state}
     GET  /user/api/index/pay        （按设备过滤，游客不含余额支付）      → [{id,name,icon,handle}]
     POST /user/api/index/card       item_id,page,limit,race              → {list:[{id,draft,draft_premium}]}
     POST /user/api/order/trade      上述全部 + pay_id (+captcha)
        · 余额/免费 → {secret,tradeNo,leave_message}   就地发卡
        · 其它网关 → {url,...}                          跳转（http(s) 外部网关或 /user/pay/order.* 站内收银台）
   ========================================================================= */
(function (win, doc) {
    'use strict';

    var LA = win.LA;
    var page = LA && LA.qs('[data-la-item]');
    if (!LA || !page) { return; }

    var qs = LA.qs, qsa = LA.qsa, on = LA.on, esc = LA.esc;
    var isApp = doc.body.classList.contains('la--app');
    var item = (win.getVar && win.getVar('_var_item')) || {};
    var itemId = parseInt(page.getAttribute('data-id'), 10) || 0;
    var form = qs('[data-la-buy]');
    var busy = false;

    // ------------------------------------------------------------ 载荷

    function selected(selector, group) {
        var nodes = qsa(selector).filter(function (b) {
            return !group || b.getAttribute('data-sku') === group;
        });
        var hit = nodes.filter(function (b) { return b.classList.contains('is-primary'); })[0];
        return hit || nodes[0] || null;
    }

    function payload() {
        var data = {};
        if (form) {
            // 用 FormData 而不是手写取值：自定义控件（widget_render）与插件注入的
            // 字段都在表单里，手写永远会漏
            new FormData(form).forEach(function (value, key) {
                if (key.slice(-2) === '[]') {
                    var k = key.slice(0, -2);
                    (data[k] = data[k] || []).push(value);
                } else {
                    data[key] = value;
                }
            });
        }
        data.item_id = itemId;

        if (item.config && item.config.category) {
            var race = selected('.switch-race');
            if (race) { data.race = race.getAttribute('data-sku'); }
        }
        if (item.config && item.config.sku) {
            data.sku = {};
            Object.keys(item.config.sku).forEach(function (group) {
                var btn = selected('.switch-sku', group);
                if (btn) { data.sku[group] = btn.getAttribute('data-value'); }
            });
        }
        return data;
    }

    function setPrice(target, value) {
        qsa(target).forEach(function (el) {
            var m = LA.money(value);
            el.innerHTML = '<span class="la-price__sym">' + esc(LA.symbol()) + '</span>'
                + '<span class="la-price__int">' + m.int + '</span>'
                + '<span class="la-price__dec">' + m.dec + '</span>';
        });
    }

    // ------------------------------------------------------------ 算价 / 库存

    var revalue = LA.debounce(function () {
        LA.post('/user/api/index/valuation', payload()).then(function (res) {
            var price = (res && res.data && res.data.price) != null ? res.data.price : null;
            if (price === null) { return; }
            setPrice('[data-la-total]', price);
        }).catch(function () { /* 算价失败保持原值，下单时服务端仍会以真实价格结算 */ });
    }, 260);

    var restock = LA.debounce(function () {
        LA.post('/user/api/index/stock', payload()).then(function (res) {
            var d = (res && res.data) || {};
            qsa('[data-la-stock]').forEach(function (el) { el.textContent = d.stock; });
            var out = Number(d.stock_state) === 0;
            qsa('[data-la-submit]').forEach(function (b) {
                b.disabled = out;
                var label = qs('span', b);
                if (label) { label.textContent = LA.t(out ? '已售罄' : (isApp ? '确认下单' : '立即购买')); }
            });
        }).catch(function () { /* 库存刷新失败不阻断下单 */ });
    }, 260);

    // ------------------------------------------------------------ 规格选择

    function syncLadder() {
        var ladder = qs('[data-la-ladder]');
        if (!ladder) { return; }
        var race = selected('.switch-race');
        var key = race ? race.getAttribute('data-sku') : '';
        var tables = qsa('.la-ladder__table', ladder);
        var matched = tables.filter(function (t) { return t.getAttribute('data-race') === key; });
        // 该种类没有专属阶梯时，回落到通用批发表
        if (!matched.length) { matched = tables.filter(function (t) { return t.getAttribute('data-race') === ''; }); }
        tables.forEach(function (t) { t.hidden = matched.indexOf(t) === -1; });
        ladder.hidden = !matched.length;
    }

    function syncSummary() {
        var box = qs('[data-la-spec-summary]');
        if (!box) { return; }
        var parts = [];
        var race = selected('.switch-race');
        if (race) { parts.push(race.textContent.trim().split('\n')[0]); }
        Object.keys((item.config && item.config.sku) || {}).forEach(function (g) {
            var b = selected('.switch-sku', g);
            if (b) { parts.push(b.textContent.trim().split('\n')[0]); }
        });
        var num = qs('.la-stepper__input');
        if (num) { parts.push('× ' + num.value); }
        box.textContent = parts.length ? parts.join(' / ') : LA.t('种类 / 规格 / 数量');
    }

    on(doc, 'click', '.switch-race', function (ev, el) {
        qsa('.switch-race').forEach(function (b) { b.classList.toggle('is-primary', b === el); });
        syncLadder();
        syncSummary();
        revalue();
        restock();
    });

    on(doc, 'click', '.switch-sku', function (ev, el) {
        var group = el.getAttribute('data-sku');
        qsa('.switch-sku').forEach(function (b) {
            if (b.getAttribute('data-sku') === group) { b.classList.toggle('is-primary', b === el); }
        });
        syncSummary();
        revalue();
        restock();
    });

    // ------------------------------------------------------------ 数量

    function stepNum(delta) {
        var input = qs('.la-stepper__input');
        if (!input) { return; }
        var min = parseInt(input.getAttribute('min'), 10) || 1;
        var maxAttr = input.getAttribute('max');
        var max = maxAttr ? (parseInt(maxAttr, 10) || 0) : 0;
        var value = (parseInt(input.value, 10) || min) + delta;
        if (value < min) { value = min; }
        if (max > 0 && value > max) {
            value = max;
            LA.toast(LA.t('已达到单次最大购买数量'), 'warning');
        }
        input.value = value;
        syncSummary();
        revalue();
    }

    on(doc, 'click', '.change-num-add', function () { stepNum(1); });
    on(doc, 'click', '.change-num-sub', function () { stepNum(-1); });

    on(doc, 'change', '.la-stepper__input', function (ev, el) {
        var min = parseInt(el.getAttribute('min'), 10) || 1;
        if ((parseInt(el.value, 10) || 0) < min) { el.value = min; }
        syncSummary();
        revalue();
    });

    on(doc, 'input', '[name="coupon"]', LA.debounce(revalue, 400));

    // ------------------------------------------------------------ 验证码

    on(doc, 'click', '[data-la-captcha]', function (ev, el) {
        el.src = '/user/captcha/image?action=trade&_=' + Date.now();
    });

    // ------------------------------------------------------------ 指定卡密

    on(doc, 'click', '[data-la-pick-card]', function () {
        LA.post('/user/api/index/card', {
            item_id: itemId,
            page: 1,
            limit: 200,
            race: (selected('.switch-race') || {getAttribute: function () { return ''; }}).getAttribute('data-sku') || ''
        }).then(function (res) {
            var list = (res && res.data && res.data.list) || [];
            if (!list.length) { LA.toast(LA.t('暂时没有可指定的宝贝'), 'warning'); return; }

            var html = '<div class="la-cards">' + list.map(function (row) {
                var fee = Number(row.draft_premium) || 0;
                return '<button type="button" class="la-cards__row" data-card="' + (parseInt(row.id, 10) || 0) + '">'
                    + '<span class="la-cards__txt">' + esc(LA.t(String(row.draft || ''))) + '</span>'
                    + (fee > 0 ? '<span class="la-cards__fee">+' + esc(LA.symbol()) + LA.money(fee).int + LA.money(fee).dec + '</span>' : '')
                    + '</button>';
            }).join('') + '</div>';

            LA.panel(LA.t('挑选指定卡密'), html, function (root) {
                on(root, 'click', '[data-card]', function (ev, el) {
                    var id = el.getAttribute('data-card');
                    var input = qs('[name="card_id"]');
                    if (input) { input.value = id; }
                    var picked = qs('[data-la-card-picked]');
                    if (picked) {
                        picked.hidden = false;
                        picked.textContent = LA.t('已选') + '：' + qs('.la-cards__txt', el).textContent;
                    }
                    LA.closePanel();
                    revalue();
                });
            });
        }).catch(function (res) {
            LA.toast((res && res.msg) || LA.t('宝贝列表获取失败'), 'error');
        });
    });

    // ------------------------------------------------------------ 下单

    function safeGo(url) {
        // 下单接口返回的支付地址有两种合法形态：
        //   · 完整的 http(s) 地址 —— 跳到外部网关（USDT、个人挂机、SuperPay…）；
        //   · 单斜杠开头的站内地址 —— 核心给「站内收银台」（/user/pay/order.订单号.1，扫码页）
        //     和「自动提交表单页」（…​.2）用的，okpay、码支付、V 免签、官方微信扫码等都走这条。
        // 以前这里只认 http(s)，第二种一律被拦成「支付地址异常」，订单建好了人却进不了收银台。
        // 规则改用 LA.safeUrl：javascript:、//外站 这类仍然挡住；锚点对支付没有意义，也当异常。
        var target = LA.safeUrl(url, '');
        if (!target || target.charAt(0) === '#') {
            LA.toast(LA.t('支付地址异常，请联系客服'), 'error');
            return;
        }
        win.location.href = target;
    }

    function finish(res) {
        var data = (res && res.data) || {};
        if (data.secret != null && data.secret !== '') {
            if (win.treasure && typeof win.treasure.show === 'function') {
                win.treasure.show(data.tradeNo, data.secret, data.leave_message);
            } else {
                LA.panel(LA.t('购买成功'), '<div class="md-secret"><div class="md-secret__code">' + esc(data.secret) + '</div></div>');
            }
            restock();
            return;
        }
        if (data.url) { safeGo(data.url); return; }
        LA.toast(LA.t('下单成功'), 'success');
    }

    function submitTrade(payId) {
        if (busy) { return; }
        busy = true;
        var data = payload();
        data.pay_id = payId;

        LA.post('/user/api/order/trade', data, {loader: true}).then(function (res) {
            LA.closePanel();
            finish(res);
        }).catch(function (res) {
            LA.toast((res && res.msg) || LA.t('下单失败，请稍后重试'), 'error');
            // 验证码是一次性的，失败后必须换一张，否则用户会一直提交同一个错码
            var cap = qs('[data-la-captcha]');
            if (cap) { cap.src = '/user/captcha/image?action=trade&_=' + Date.now(); }
        }).finally(function () { busy = false; });
    }

    function choosePay() {
        LA.get('/user/api/index/pay?itemId=' + itemId, {ttl: 0}).then(function (res) {
            var list = res.data || [];
            if (!list.length) {
                LA.toast(LA.t('商家还没有配置支付方式'), 'warning');
                return;
            }
            var html = '<div class="la-pays pay-list">' + list.map(function (p) {
                var icon = p.icon
                    ? (win.util && win.util.icon ? win.util.icon(p.icon) : '')
                    : '';
                return '<button type="button" class="la-pays__row cash-pay" data-pay="' + (parseInt(p.id, 10) || 0) + '">'
                    + '<span class="la-pays__ico">' + icon + '</span>'
                    + '<span class="la-pays__name">' + esc(LA.t(String(p.name || ''))) + '</span>'
                    + '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.4 5.4 6.6 6.6-6.6 6.6"/></svg>'
                    + '</button>';
            }).join('') + '</div>';

            LA.panel(LA.t('选择支付方式'), html, function (root) {
                on(root, 'click', '[data-pay]', function (ev, el) {
                    submitTrade(parseInt(el.getAttribute('data-pay'), 10) || 0);
                });
            });
        }).catch(function (res) {
            LA.toast((res && res.msg) || LA.t('支付方式获取失败'), 'error');
        });
    }

    function validate() {
        var contact = qs('[name="contact"]');
        if (contact && !contact.value.trim()) {
            LA.toast(LA.t('请填写联系方式，发货信息会发送到这里'), 'warning');
            contact.focus();
            return false;
        }
        var captcha = qs('[name="captcha"]');
        if (captcha && !captcha.value.trim()) {
            LA.toast(LA.t('请输入验证码'), 'warning');
            captcha.focus();
            return false;
        }
        return true;
    }

    on(doc, 'click', '[data-la-submit]', function (ev, el) {
        if (el.disabled) { return; }
        if (!validate()) { return; }
        choosePay();
    });

    // ------------------------------------------------------------ 分享

    on(doc, 'click', '[data-la-share]', function (ev, el) {
        var url = el.getAttribute('data-url') || win.location.href;
        if (navigator.share) {
            navigator.share({title: doc.title, url: url}).catch(function () { /* 用户取消 */ });
            return;
        }
        LA.copy(url, LA.t('商品链接已复制'));
    });

    // ------------------------------------------------------------ 秒杀倒计时

    (function countdown() {
        qsa('[data-la-countdown]').forEach(function (box) {
            var raw = box.getAttribute('data-end') || '';
            // "YYYY-MM-DD HH:MM:SS" 在 Safari 里 new Date() 会得到 Invalid Date，只能手工拆
            var m = raw.match(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
            if (!m) { box.hidden = true; return; }
            var end = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]).getTime();
            if (end <= Date.now()) { box.hidden = true; return; }
            LA.countdown(box, end, function () {
                LA.toast(LA.t('秒杀已结束'), 'warning');
                setTimeout(function () { win.location.reload(); }, 1200);
            });
        });
    })();

    // ------------------------------------------------------------ 电脑端外壳：放大镜 + 详情 Tab

    if (!isApp) {
        (function zoom() {
            var box = qs('[data-la-zoom]');
            var lens = qs('[data-la-zoom-lens]');
            var pane = qs('[data-la-zoom-pane]');
            if (!box || !lens || !pane) { return; }
            var big = qs('img', pane);
            var ZOOM = 2.4;

            box.addEventListener('mouseenter', function () { lens.hidden = false; pane.hidden = false; });
            box.addEventListener('mouseleave', function () { lens.hidden = true; pane.hidden = true; });
            box.addEventListener('mousemove', function (ev) {
                var r = box.getBoundingClientRect();
                var lw = lens.offsetWidth, lh = lens.offsetHeight;
                var x = Math.min(Math.max(ev.clientX - r.left - lw / 2, 0), r.width - lw);
                var y = Math.min(Math.max(ev.clientY - r.top - lh / 2, 0), r.height - lh);
                lens.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0)';
                big.style.width = (r.width * ZOOM) + 'px';
                big.style.transform = 'translate3d(' + (-x * ZOOM) + 'px,' + (-y * ZOOM) + 'px,0)';
            });
        })();

        on(doc, 'click', '[data-la-tab]', function (ev, el) {
            var key = el.getAttribute('data-la-tab');
            qsa('[data-la-tab]').forEach(function (b) { b.classList.toggle('is-on', b === el); });
            qsa('[data-la-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-la-pane') !== key; });
        });
    }

    // ------------------------------------------------------------ 手机端外壳：下单半屏面板

    if (isApp) {
        var sheet = qs('[data-la-buysheet]');
        if (sheet) {
            var open = function () {
                sheet.hidden = false;
                sheet.setAttribute('aria-hidden', 'false');
                doc.body.style.overflow = 'hidden';
                LA.raf(function () { LA.raf(function () { sheet.classList.add('is-open'); }); });
            };
            var close = function () {
                sheet.classList.remove('is-open');
                doc.body.style.removeProperty('overflow');
                setTimeout(function () {
                    if (!sheet.classList.contains('is-open')) {
                        sheet.hidden = true;
                        sheet.setAttribute('aria-hidden', 'true');
                    }
                }, 380);
            };

            on(doc, 'click', '[data-la-open-buy]', function (ev, el) { if (!el.disabled) { open(); } });
            on(sheet, 'click', '[data-la-buysheet-close]', close);
            doc.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { close(); } });
        }
    }

    // ------------------------------------------------------------ 初始化

    syncLadder();
    syncSummary();
    if ((item.config && (item.config.category || item.config.sku)) || qs('[name="coupon"]')) {
        revalue();
        restock();
    }
})(window, document);
