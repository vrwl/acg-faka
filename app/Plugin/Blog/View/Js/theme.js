/* 次元博客 · theme.js —— 双维度外观：风格(glass/paper/mag/neon) × 明暗(light/dark/auto) + 语言 */
(function () {
    'use strict';
    const B = window.BLOG;
    const html = document.documentElement;
    const STYLES = ['glass', 'paper', 'mag', 'neon'];
    const media = matchMedia('(prefers-color-scheme: dark)');

    /* ---------- 应用与同步 ---------- */
    function currentPref() {
        return html.getAttribute('data-theme-pref') || 'auto';
    }

    /* syncUI() 必须跟着 mutate 一起进 swap()：
       startViewTransition 的回调是异步跑的（浏览器要先给旧状态拍快照），
       写在 swap() 之后的 syncUI() 会赶在属性真正落到 <html> 之前执行，
       于是读到的还是上一次的值 —— 表现就是「每次切换选中的还是上一个选项，点两次才对」。 */
    function applyDark(pref, animate) {
        const dark = pref === 'dark' || (pref === 'auto' && media.matches);
        swap(() => {
            html.setAttribute('data-theme', dark ? 'dark' : 'light');
            html.setAttribute('data-theme-pref', pref);
            syncUI();
        }, animate);
        try { localStorage.setItem('blog-theme', pref); } catch (e) {}
    }

    function applyStyle(style, animate) {
        if (STYLES.indexOf(style) < 0) return;
        swap(() => {
            html.setAttribute('data-blog-style', style);
            syncUI();
        }, animate);
        try { localStorage.setItem('blog-style', style); } catch (e) {}
        B.vibrate(6);
    }

    /* 切换动画：支持 View Transitions 用圆形揭示，否则加闸门瞬切 */
    function swap(mutate, animate) {
        if (animate && document.startViewTransition && !B.reducedMotion) {
            const vt = document.startViewTransition(() => {
                mutate();
                syncThemeColor();
            });
            //过渡被下一次切换打断时 ready 会 reject，吞掉避免 unhandledrejection
            if (vt && vt.ready && vt.ready.catch) vt.ready.catch(() => {});
            return;
        }
        html.classList.add('blog-notransition');
        mutate();
        syncThemeColor();
        requestAnimationFrame(() => requestAnimationFrame(() => html.classList.remove('blog-notransition')));
    }

    function syncThemeColor() {
        const meta = document.querySelector('[data-blog-themecolor]');
        if (!meta) return;
        //取 body 实际背景色（令牌随主题解析）
        requestAnimationFrame(() => {
            const bg = getComputedStyle(document.body).backgroundColor;
            if (bg && bg !== 'rgba(0, 0, 0, 0)') meta.setAttribute('content', bg);
        });
    }

    function syncUI() {
        const style = html.getAttribute('data-blog-style');
        const pref = currentPref();
        B.$$('[data-blog-set-style]').forEach(btn => {
            const active = btn.getAttribute('data-blog-set-style') === style;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        B.$$('[data-blog-set-dark]').forEach(btn => {
            const active = btn.getAttribute('data-blog-set-dark') === pref;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        const lang = (typeof getVar === 'function' && getVar('LANG')) || 'zh-cn';
        B.$$('[data-lang-value]').forEach(btn => {
            btn.classList.toggle('is-active', btn.getAttribute('data-lang-value') === lang);
        });
    }

    /* ---------- 事件 ---------- */
    document.addEventListener('click', (e) => {
        const styleBtn = e.target.closest('[data-blog-set-style]');
        if (styleBtn) { applyStyle(styleBtn.getAttribute('data-blog-set-style'), true); return; }

        const darkBtn = e.target.closest('[data-blog-set-dark]');
        if (darkBtn) { applyDark(darkBtn.getAttribute('data-blog-set-dark'), true); return; }

        const langBtn = e.target.closest('[data-lang-value]');
        if (langBtn) {
            const lang = langBtn.getAttribute('data-lang-value');
            document.cookie = 'acg_lang=' + lang + ';path=/;max-age=' + (86400 * 365 * 10) + ';samesite=lax';
            location.reload();
            return;
        }

        const appearanceBtn = e.target.closest('[data-blog-appearance-btn]');
        const pop = B.$('[data-blog-pop="appearance"]');
        if (appearanceBtn && pop) {
            if (pop.open) { pop.close(); } else { pop.show(); syncUI(); }
            e.stopPropagation();
            return;
        }
        //点击外部关闭外观弹层
        if (pop && pop.open && !e.target.closest('[data-blog-pop="appearance"]')) pop.close();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const pop = B.$('[data-blog-pop="appearance"]');
        if (pop && pop.open) pop.close();
    });

    //auto 模式实时跟随系统
    const onMedia = () => { if (currentPref() === 'auto') applyDark('auto', false); };
    media.addEventListener ? media.addEventListener('change', onMedia) : media.addListener(onMedia);

    //document 级委托只绑一次；UI 高亮随页面重新同步（外观按钮在 #blog-shell 内，pjax 会换掉）
    B.page(function appearance() {
        syncUI();
        syncThemeColor();
    });
})();
