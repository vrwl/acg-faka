/* 次元博客 · core.js —— 基础设施：请求 / toast / sheet / 工具（零依赖 vanilla） */
(function () {
    'use strict';
    const cfg = window.__BLOG__ || {};
    const B = window.BLOG = {cfg};

    B.$ = (sel, root) => (root || document).querySelector(sel);
    B.$$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    /* ---------- 页面生命周期 ----------
       pjax 会整块换掉 #blog-shell，页面级逻辑必须能重复初始化。
       约定：每个模块用 B.page(fn) 注册，fn 内自行判断本页有没有它要的元素；
       fn 里所有 addEventListener 都带 {signal: B.signal}，切页时统一 abort，
       不用各自写 cleanup，也就不会漏解绑。 */
    B.pages = [];
    B.mounted = false;
    B.signal = null;
    let ac = null;

    B.page = function (fn) {
        B.pages.push(fn);
        if (B.mounted) run(fn);       //懒加载的模块注册时已在页面中，立刻跑一次
    };

    function run(fn) {
        try { fn(); } catch (e) { console.error('[blog] page init failed', e); }
    }

    B.mount = function () {
        if (B.mounted) return;
        ac = new AbortController();
        B.signal = ac.signal;
        B.mounted = true;
        B.pages.forEach(run);
        B.hydrateTimes(document);
    };

    B.unmount = function () {
        if (ac) ac.abort();
        ac = null;
        B.signal = null;
        B.mounted = false;
        //sheet 都在 #blog-shell 里，pjax 换页时被整块替换掉。
        //模态 dialog 就这么被摘走的话，带走的是整页的滚动锁 —— 换页前先关干净
        document.querySelectorAll('dialog[open]').forEach(d => {
            try { d.close(); } catch (e) {}
        });
    };

    //defer 脚本按序执行完才触发 DOMContentLoaded，此时所有模块都已注册
    document.addEventListener('DOMContentLoaded', () => B.mount(), {once: true});
    B.t = (key) => (cfg.t && cfg.t[key]) || key;
    B.esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    B.b64 = (s) => btoa(unescape(encodeURIComponent(String(s))));
    B.vibrate = (ms) => { try { navigator.vibrate && navigator.vibrate(ms || 8); } catch (e) {} };
    B.reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- toast ---------- */
    let toastHost = null;
    B.toast = function (msg, type) {
        if (!toastHost) {
            toastHost = document.createElement('div');
            toastHost.className = 'blog-toasthost';
            toastHost.setAttribute('aria-live', 'polite');
            toastHost.style.cssText = 'position:fixed;left:50%;bottom:calc(84px + env(safe-area-inset-bottom));transform:translateX(-50%);z-index:400;display:flex;flex-direction:column;gap:8px;align-items:center;pointer-events:none';
            document.body.appendChild(toastHost);
        }
        const el = document.createElement('div');
        el.textContent = msg;
        el.style.cssText = 'max-width:82vw;padding:10px 18px;border-radius:999px;font-size:13.5px;font-weight:600;' +
            'background:var(--blog-surface-2);color:var(--blog-ink);border:1px solid var(--blog-border);box-shadow:var(--blog-shadow-2);' +
            '-webkit-backdrop-filter:blur(16px);backdrop-filter:blur(16px);opacity:0;transform:translateY(8px);transition:all .22s var(--blog-ease)';
        if (type === 'error') el.style.color = 'var(--blog-danger)';
        toastHost.appendChild(el);
        requestAnimationFrame(() => { el.style.opacity = '1'; el.style.transform = 'none'; });
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(6px)';
            setTimeout(() => el.remove(), 240);
        }, 2400);
    };

    /* ---------- 请求（POST urlencoded，契约 {code,msg,data}） ---------- */
    B.fetch = function (path, data) {
        const body = new URLSearchParams();
        Object.keys(data || {}).forEach(k => {
            const v = data[k];
            if (Array.isArray(v)) v.forEach(item => body.append(k + '[]', item));
            else if (v !== undefined && v !== null) body.append(k, v);
        });
        return fetch(cfg.api + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest'},
            body: body.toString()
        }).then(r => r.json()).then(res => {
            if (res && res.code === 200) return res;
            /* 不能只看 code：JSONException 继承自 \Exception，getCode() 恒为 0，
               和拦截器「登录会话过期」用的 code:0 完全撞车 —— 业务报错会被误判成未登录。
               三级信号，从强到弱：① 服务端显式标记（本插件接口）；② 页面本就是游客态渲染的；
               ③ 认文案兜底（核心拦截器没有结构化标记，四种站点语言都得认） */
            const msg = (res && res.msg) || 'Error';
            const needLogin = !!(res && res.data && res.data.need_login)
                || (res && res.code === 0 && (!cfg.user || /登录|登入|ログイン|login|sign\s*in/i.test(msg)));
            if (needLogin) {
                B.loginPrompt();
                throw Object.assign(new Error(msg), {handled: true});
            }
            B.toast(msg, 'error');
            throw Object.assign(new Error(msg), {handled: true});
        });
    };

    B.loginPrompt = function () {
        B.toast(B.t('needLogin'));
        setTimeout(() => { location.href = cfg.login; }, 650);
    };

    /* ---------- Sheet（dialog 底部抽屉：拖拽关闭 + 背板点击关闭） ---------- */
    B.openSheet = function (dialog) {
        if (!dialog || dialog.open) return;
        dialog.classList.remove('is-closing');
        try { dialog.showModal(); } catch (e) { return; }
        B.vibrate(6);

        if (!dialog.__blogWired) {
            dialog.__blogWired = true;
            dialog.addEventListener('click', (e) => {
                const rect = dialog.getBoundingClientRect();
                const inside = e.clientX >= rect.left && e.clientX <= rect.right && e.clientY >= rect.top && e.clientY <= rect.bottom;
                if (!inside) B.closeSheet(dialog);
            });
            dialog.addEventListener('cancel', (e) => { e.preventDefault(); B.closeSheet(dialog); });
            //拖拽下滑关闭
            let startY = 0, delta = 0, dragging = false;
            dialog.addEventListener('touchstart', (e) => {
                if (dialog.scrollTop > 2) return;
                startY = e.touches[0].clientY;
                dragging = true;
                delta = 0;
            }, {passive: true});
            dialog.addEventListener('touchmove', (e) => {
                if (!dragging) return;
                delta = e.touches[0].clientY - startY;
                if (delta > 0) dialog.style.transform = 'translateY(' + delta + 'px)';
            }, {passive: true});
            dialog.addEventListener('touchend', () => {
                if (!dragging) return;
                dragging = false;
                dialog.style.transition = 'transform .2s var(--blog-ease)';
                if (delta > 90) { B.closeSheet(dialog); } else { dialog.style.transform = ''; }
                setTimeout(() => { dialog.style.transition = ''; }, 220);
            });
        }
    };

    /**
     * @param dialog
     * @param immediate 立刻关掉、不播关闭动画。关闭之后马上要滚动页面时必须用它 ——
     *                  模态没关掉，整页滚动就还锁着（见 post.js 的目录跳转）
     */
    B.closeSheet = function (dialog, immediate) {
        if (!dialog || !dialog.open) return;
        const finish = () => {
            dialog.classList.remove('is-closing');
            dialog.style.transform = '';
            try { dialog.close(); } catch (e) {}
        };
        if (immediate || B.reducedMotion) { finish(); return; }
        dialog.classList.add('is-closing');
        setTimeout(finish, 190);
    };

    /* ---------- 相对时间 ---------- */
    const rtfLang = {'zh-cn': 'zh-CN', 'zh-tw': 'zh-TW', 'en': 'en', 'ja': 'ja'}[typeof getVar === 'function' ? getVar('LANG') : 'zh-cn'] || 'zh-CN';
    B.relativeTime = function (iso) {
        const time = new Date(iso).getTime();
        if (!time) return '';
        const diff = (Date.now() - time) / 1000;
        if (diff < 60) return B.t('justNow');
        try {
            const rtf = new Intl.RelativeTimeFormat(rtfLang, {numeric: 'always'});
            if (diff < 3600) return rtf.format(-Math.floor(diff / 60), 'minute');
            if (diff < 86400) return rtf.format(-Math.floor(diff / 3600), 'hour');
            if (diff < 86400 * 30) return rtf.format(-Math.floor(diff / 86400), 'day');
        } catch (e) {
            if (diff < 3600) return Math.floor(diff / 60) + ' ' + B.t('minutesAgo');
            if (diff < 86400) return Math.floor(diff / 3600) + ' ' + B.t('hoursAgo');
            if (diff < 86400 * 30) return Math.floor(diff / 86400) + ' ' + B.t('daysAgo');
        }
        return new Date(time).toLocaleDateString();
    };

    //列表时间自动相对化（30 天内），完整时间进 title
    B.hydrateTimes = function (root) {
        B.$$('[data-blog-time]', root).forEach(el => {
            const iso = el.getAttribute('data-blog-time');
            if (!iso) return;
            const abs = new Date(iso);
            if (!abs.getTime()) return;
            el.title = abs.toLocaleString();
            if ((Date.now() - abs.getTime()) < 86400 * 30 * 1000) el.textContent = B.relativeTime(iso);
        });
    };

    /* ---------- 卡片指针高光（仅精确指针） ---------- */
    if (matchMedia('(hover: hover) and (pointer: fine)').matches && !B.reducedMotion) {
        document.addEventListener('pointermove', (e) => {
            const card = e.target.closest && e.target.closest('.blog-card');
            if (!card) return;
            const rect = card.getBoundingClientRect();
            card.style.setProperty('--mx', ((e.clientX - rect.left) / rect.width * 100) + '%');
            card.style.setProperty('--my', ((e.clientY - rect.top) / rect.height * 100) + '%');
        }, {passive: true});
    }

    /* ---------- 头像兜底（加载失败 → 首字符色块） ---------- */
    B.avatarNode = function (name, url, cls) {
        const wrap = document.createElement('span');
        wrap.className = cls;
        const letter = (name || '?').trim().charAt(0).toUpperCase();
        let hash = 0;
        for (let i = 0; i < (name || '').length; i++) hash = (hash * 31 + name.charCodeAt(i)) >>> 0;
        const fallback = () => {
            wrap.textContent = letter;
            wrap.style.background = 'hsl(' + (hash % 360) + ' 55% 52%)';
        };
        if (url) {
            const img = document.createElement('img');
            img.src = url;
            img.alt = '';
            img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block';
            img.onerror = fallback;
            wrap.appendChild(img);
        } else {
            fallback();
        }
        return wrap;
    };

    //hydrateTimes 已并入 B.mount()，每次 pjax 切页都会重跑
})();
