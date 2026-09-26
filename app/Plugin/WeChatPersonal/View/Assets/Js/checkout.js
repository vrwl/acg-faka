/* 收银台行为层：倒计时、状态轮询、复制、终态切换。
   三个通道共用，差异只在模板结构里。 */
!function () {
    var app = document.getElementById('app');
    if (!app) return;

    var cfg = {
        trade: app.dataset.trade,
        remain: parseInt(app.dataset.remain, 10) || 0,
        total: Math.max(parseInt(app.dataset.total, 10) || 300, 1),
        state: app.dataset.state,
        ret: app.dataset.return,
        amount: app.dataset.amount,
        payUrl: app.dataset.payurl || '',
        route: app.dataset.route
    };
    var body = document.body;
    var RING = 99.9;

    /* ── 端识别：手机上扫不了自己屏幕的码，改成直接唤起 App ── */
    var isMobile = /Android|iPhone|iPad|iPod|Windows Phone|HarmonyOS|MicroMessenger|AlipayClient/i.test(navigator.userAgent)
        || (window.matchMedia && window.matchMedia('(pointer: coarse)').matches && window.innerWidth < 820);
    body.dataset.device = isMobile ? 'wap' : 'pc';

    /* ── 二维码 ── */
    var holder = document.getElementById('qrcode');
    if (holder && cfg.payUrl && window.jQuery && jQuery.fn.qrcode) {
        var size = window.devicePixelRatio > 1 ? 392 : 196;
        jQuery(holder).qrcode({ render: 'canvas', width: size, height: size, text: cfg.payUrl, background: '#ffffff', foreground: '#111318' });
    }

    /* ── 复制 ── */
    function legacyCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0';
        ta.setAttribute('readonly', '');
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, text.length);
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    var toastTimer = 0;
    function showToast(msg) {
        var toast = document.getElementById('toast');
        if (!toast) return;
        document.getElementById('toastText').textContent = msg;
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 1600);
    }

    function copy(text, btn, msg) {
        var done = function () {
            showToast(msg);
            if (!btn) return;
            btn.classList.add('done');
            var use = btn.querySelector('use');
            if (use) { use.setAttribute('href', '#ic-check'); use.setAttribute('xlink:href', '#ic-check'); }
            setTimeout(function () {
                btn.classList.remove('done');
                if (use) { use.setAttribute('href', '#ic-copy'); use.setAttribute('xlink:href', '#ic-copy'); }
            }, 1800);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text); done(); });
        } else {
            legacyCopy(text);
            done();
        }
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (el) {
        el.addEventListener('click', function () {
            copy(el.dataset.copy, el.matches('.cbtn') ? el : null, el.dataset.copytip || '已复制');
        });
    });

    /* ── 倒计时 ── */
    var remain = cfg.remain;
    var cd = document.getElementById('cd');
    var ring = document.getElementById('ring');
    var bar = document.getElementById('bar');

    function paintClock() {
        if (!cd) return;
        var m = Math.floor(remain / 60), s = remain % 60;
        cd.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        var frac = Math.max(0, Math.min(1, remain / cfg.total));
        if (ring) ring.style.strokeDashoffset = (RING * (1 - frac)).toFixed(2);
        if (bar) bar.style.transform = 'scaleX(' + frac.toFixed(4) + ')';
        body.dataset.urgency = remain <= 30 ? 'danger' : (remain <= 90 ? 'warn' : '');
    }

    function setState(state) {
        if (cfg.state === state) return;
        cfg.state = state;
        body.dataset.state = state;
        if (state === 'paid') {
            var pt = document.getElementById('paidText');
            if (pt) pt.innerHTML = '正在返回商户…';
            setTimeout(function () { if (cfg.ret) window.location.href = cfg.ret; }, 2600);
        }
    }

    if (cfg.state === 'pending') {
        paintClock();
        setInterval(function () {
            if (cfg.state !== 'pending') return;
            if (remain > 0) { remain--; paintClock(); }
            if (remain <= 0) {
                var lt = document.getElementById('listenText');
                if (lt) lt.textContent = '正在确认…';
            }
        }, 1000);
    }
    //打开时就已是成功态（用户回看订单）不自动跳转，把人留在凭据页

    /* ── 状态轮询：纯只读接口，到账由挂机端上报 ── */
    var polling = false;
    function poll() {
        if (cfg.state !== 'pending' || polling || !window.jQuery) return;
        polling = true;
        jQuery.get(cfg.route + '/api/order/state?tradeNo=' + encodeURIComponent(cfg.trade), function (res) {
            polling = false;
            if (!res || res.code !== 200 || !res.data) { setState('expired'); return; }
            if (parseInt(res.data.status, 10) === 1) setState('paid');
        }, 'json').fail(function (xhr) {
            polling = false;
            //订单过期时接口返回非 200，这时才切过期态；网络抖动不动它
            if (xhr && xhr.status >= 400 && xhr.status < 500) setState('expired');
        });
    }

    setInterval(function () { if (!document.hidden) poll(); }, 3000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    poll();
}();
