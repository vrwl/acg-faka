/* 次元博客 · pjax.js —— 站内无刷新导航
 *
 * 只替换 #blog-shell（导航 + 正文 + 页脚 + TabBar + Sheet），
 * 保留 <html> 的主题属性、氛围光斑层与已加载的 CSS/JS —— 切页不闪、不重下资源。
 *
 * 生命周期：B.unmount()（abort 上一页所有监听）→ 换 DOM → B.mount()（重跑所有 B.page 模块）。
 * 支持 View Transitions（同文档），不支持则直接替换；任何异常都降级为整页跳转。
 */
(function () {
    'use strict';
    const B = window.BLOG;
    if (!B) return;

    const SHELL = '#blog-shell';
    const TIMEOUT = 8000;
    let current = null;      //进行中的请求，用于取消
    let bar = null;

    /* ---------- 顶部加载进度条 ---------- */
    function barStart() {
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'blog-pjaxbar';
            document.body.appendChild(bar);
        }
        bar.classList.remove('is-done');
        bar.style.width = '0';
        requestAnimationFrame(() => {
            bar.classList.add('is-loading');
            bar.style.width = '70%';
        });
    }

    function barDone() {
        if (!bar) return;
        bar.style.width = '100%';
        bar.classList.add('is-done');
        setTimeout(() => {
            if (!bar) return;
            bar.classList.remove('is-loading', 'is-done');
            bar.style.width = '0';
        }, 260);
    }

    /* ---------- 该不该接管这个链接 ---------- */
    function shouldHandle(a, e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return false;
        if (!a || !a.href) return false;
        if (a.target && a.target !== '_self') return false;
        if (a.hasAttribute('download') || a.getAttribute('rel') === 'external') return false;
        if (a.hasAttribute('data-no-pjax')) return false;

        const url = new URL(a.href, location.href);
        if (url.origin !== location.origin) return false;
        //纯锚点跳转交给浏览器
        if (url.pathname === location.pathname && url.search === location.search && url.hash) return false;
        //只接管博客自己的页面；RSS/sitemap 等直出文件不接管
        if (!isBlogPage(url.pathname)) return false;
        return true;
    }

    function isBlogPage(pathname) {
        const prettyRoot = (B.cfg.urls.index || '/blog').split('?')[0];
        const inPretty = pathname === prettyRoot || pathname.indexOf(prettyRoot + '/') === 0;
        const inNative = pathname.indexOf('/plugin/Blog/') === 0 || pathname.indexOf('/plugin/blog/') === 0;
        if (!inPretty && !inNative) return false;
        //feed / sitemap 是 XML，必须整页跳转
        if (/\/(feed|sitemap\.xml)$/.test(pathname)) return false;
        if (/\/plugin\/[Bb]log\/(feed|meta)\//.test(pathname)) return false;
        return true;
    }

    /* ---------- 核心：加载并替换 ---------- */
    function load(url, opts) {
        opts = opts || {};
        const shell = document.querySelector(SHELL);
        if (!shell) { location.href = url; return; }

        if (current) current.abort();
        const ac = new AbortController();
        current = ac;
        const timer = setTimeout(() => ac.abort(), TIMEOUT);

        barStart();
        document.documentElement.classList.add('blog-pjax-loading');

        fetch(url, {
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'X-PJAX': 'true'},
            signal: ac.signal
        }).then(res => {
            //跨站重定向（登录页等）交给浏览器
            if (res.redirected && new URL(res.url).origin !== location.origin) throw new Error('redirect');
            if (!res.ok && res.status !== 404) throw new Error('http ' + res.status);
            return res.text().then(html => ({html, finalUrl: res.url || url}));
        }).then(({html, finalUrl}) => {
            clearTimeout(timer);
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = doc.querySelector(SHELL);
            //返回的不是博客页面（安全验证页、登录页…）→ 整页跳转，别硬塞
            if (!next) throw new Error('no shell');

            const apply = () => swap(next, doc, finalUrl, opts);
            if (document.startViewTransition && !B.reducedMotion) {
                const vt = document.startViewTransition(apply);
                if (vt && vt.ready && vt.ready.catch) vt.ready.catch(() => {});
            } else {
                apply();
            }
            barDone();
            document.documentElement.classList.remove('blog-pjax-loading');
            current = null;
        }).catch(err => {
            clearTimeout(timer);
            document.documentElement.classList.remove('blog-pjax-loading');
            if (err && err.name === 'AbortError' && current !== ac) return;  //被新导航取代，静默
            barDone();
            current = null;
            location.href = url;   //任何意外都退回整页跳转，用户不会卡住
        });
    }

    /* ---------- 替换 DOM 与页面状态 ---------- */
    function swap(next, doc, finalUrl, opts) {
        B.unmount();

        const shell = document.querySelector(SHELL);
        shell.replaceWith(next);

        //<body> 的类名带着 body_class / blog-has-tab / blog-is-sub，必须同步
        const nextBody = doc.body;
        if (nextBody) document.body.className = nextBody.className;

        document.title = doc.title || document.title;
        syncHead(doc);

        if (!opts.pop) {
            history.pushState({blogPjax: true}, '', finalUrl);
            window.scrollTo(0, 0);
        }

        B.mount();
        //让读屏与浏览器焦点回到新页面顶部
        const main = document.getElementById('blog-main');
        if (main) {
            main.setAttribute('tabindex', '-1');
            try { main.focus({preventScroll: true}); } catch (e) {}
            main.removeAttribute('tabindex');
        }
        document.dispatchEvent(new CustomEvent('blog:pjax', {detail: {url: finalUrl}}));
    }

    /* ---------- <head> 里跟着页面变的那几项 ---------- */
    function syncHead(doc) {
        const pairs = [
            ['link[rel="canonical"]', 'href'],
            ['meta[name="description"]', 'content'],
            ['meta[name="keywords"]', 'content'],
            ['meta[property="og:title"]', 'content'],
            ['meta[property="og:description"]', 'content'],
            ['meta[property="og:image"]', 'content'],
            ['meta[property="og:type"]', 'content'],
            ['meta[name="robots"]', 'content']
        ];
        pairs.forEach(([sel, attr]) => {
            const from = doc.querySelector(sel);
            let to = document.querySelector(sel);
            if (!from) { if (to) to.remove(); return; }
            if (!to) {
                to = from.cloneNode(true);
                document.head.appendChild(to);
                return;
            }
            to.setAttribute(attr, from.getAttribute(attr) || '');
        });

        //JSON-LD 结构化数据整块换掉
        const oldLd = document.querySelector('script[type="application/ld+json"]');
        const newLd = doc.querySelector('script[type="application/ld+json"]');
        if (oldLd) oldLd.remove();
        if (newLd) document.head.appendChild(newLd.cloneNode(true));
    }

    /* ---------- 事件绑定（document 级，只绑一次） ---------- */
    document.addEventListener('click', (e) => {
        const a = e.target.closest && e.target.closest('a[href]');
        if (!a || !shouldHandle(a, e)) return;
        e.preventDefault();
        const url = new URL(a.href, location.href).toString();
        if (url === location.href) return;
        load(url);
    });

    //搜索表单等 GET 表单也走 pjax
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!form || form.method.toLowerCase() !== 'get' || form.hasAttribute('data-no-pjax')) return;
        const action = new URL(form.getAttribute('action') || location.pathname, location.href);
        if (action.origin !== location.origin || !isBlogPage(action.pathname)) return;
        e.preventDefault();
        const params = new URLSearchParams(new FormData(form));
        action.search = params.toString();
        load(action.toString());
    });

    window.addEventListener('popstate', (e) => {
        //只接管自己 push 过的历史项，其余交给浏览器
        if (!e.state || !e.state.blogPjax) {
            if (isBlogPage(location.pathname)) load(location.href, {pop: true});
            return;
        }
        load(location.href, {pop: true});
    });

    //首屏也标记成 pjax 历史项，返回时能被正确接管
    if (!history.state || !history.state.blogPjax) {
        try { history.replaceState({blogPjax: true}, '', location.href); } catch (err) {}
    }
})();
