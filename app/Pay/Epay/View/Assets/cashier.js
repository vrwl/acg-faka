(function () {
    var app = document.getElementById('app');
    if (!app) return;

    var cfg = {
        trade: app.dataset.trade || '',
        qr: app.dataset.qr || '',
        ret: app.dataset.return || '/',
        channel: app.dataset.channel || '',
        state: app.dataset.state || 'pending'
    };
    var body = document.body;
    var ua = navigator.userAgent || '';
    var mobile = /Android|iPhone|iPad|iPod|HarmonyOS|Mobile/i.test(ua);
    var inApp = {
        alipay: /AlipayClient/i.test(ua),
        wxpay: /MicroMessenger/i.test(ua),
        qqpay: /\bQQ\//i.test(ua)
    };

    function renderQr() {
        var box = document.getElementById('qrcode');
        if (!box || !cfg.qr || typeof $ === 'undefined' || !$.fn.qrcode) return;
        $(box).qrcode({ render: 'canvas', width: 392, height: 392, text: cfg.qr, background: '#ffffff', foreground: '#111318' });
        var canvas = box.querySelector('canvas');
        if (!canvas) return;
        try {
            var img = new Image();
            img.alt = box.getAttribute('data-alt') || '';
            img.src = canvas.toDataURL('image/png');
            box.replaceChild(img, canvas);
        } catch (e) {}
    }

    function prepareApp() {
        var hint = document.getElementById('scanHint');
        if (hint && inApp[cfg.channel] && hint.getAttribute('data-inapp')) {
            hint.textContent = hint.getAttribute('data-inapp');
        } else if (hint && mobile && !inApp[cfg.channel] && hint.getAttribute('data-mobile')) {
            hint.textContent = hint.getAttribute('data-mobile');
        }
        if (cfg.channel !== 'alipay') return;
        var direct = /^https?:\/\//i.test(cfg.qr);
        if (inApp.alipay && direct) {
            window.location.href = cfg.qr;
            return;
        }
        var open = document.getElementById('openApp');
        if (open && mobile) {
            open.href = 'alipays://platformapi/startapp?appId=20000067&url=' + encodeURIComponent(cfg.qr);
            body.classList.add('is-mobile');
        }
    }

    var toastTimer = 0;
    function showToast(msg) {
        var toast = document.getElementById('toast');
        var text = document.getElementById('toastText');
        if (!toast || !text) return;
        text.textContent = msg;
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 1600);
    }

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

    function copy(text, btn, msg) {
        var done = function () {
            showToast(msg);
            if (!btn) return;
            btn.classList.add('done');
            setTimeout(function () { btn.classList.remove('done'); }, 1800);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text); done(); });
        } else {
            legacyCopy(text);
            done();
        }
    }

    var copyTrade = document.getElementById('copyTrade');
    if (copyTrade) {
        copyTrade.addEventListener('click', function () {
            copy(cfg.trade, this, copyTrade.getAttribute('data-done') || '');
        });
    }

    function setState(state) {
        if (cfg.state === state) return;
        cfg.state = state;
        body.dataset.state = state;
        if (state === 'paid') {
            var paidText = document.getElementById('paidText');
            if (paidText && paidText.getAttribute('data-leaving')) paidText.textContent = paidText.getAttribute('data-leaving');
            setTimeout(function () { window.location.href = cfg.ret; }, 2600);
        }
    }

    var polling = false;
    var backoff = 0;
    function poll() {
        if (cfg.state !== 'pending' || polling || document.hidden) return;
        if (backoff > 0) {
            backoff--;
            return;
        }
        polling = true;
        $.post('/user/api/order/state', { tradeNo: cfg.trade }, function (res) {
            polling = false;
            if (res && res.code === 200 && res.data) {
                if (Number(res.data.status) === 1) setState('paid');
                return;
            }
            backoff = 4;
        }, 'json').fail(function () {
            polling = false;
            backoff = 1;
        });
    }

    if (cfg.state === 'pending') {
        renderQr();
        prepareApp();
        setInterval(poll, 6000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
        setTimeout(poll, 1500);
    }
})();
