/* 次元博客 · post.js —— 阅读体验：代码块 / TOC / 进度条 / 灯箱 / 点赞 / 分享 / 提示框标签 */
(function () {
    'use strict';
    const B = window.BLOG;
    const on = (el, ev, fn, opt) => el.addEventListener(ev, fn, Object.assign({signal: B.signal}, opt || {}));

    B.page(function postPage() {
    const prose = B.$('[data-blog-prose]');
    if (!prose) return;

    /* ---------- 首图优先级 ---------- */
    const firstImg = B.$('img', prose);
    if (firstImg) {
        firstImg.removeAttribute('loading');
        firstImg.setAttribute('fetchpriority', 'high');
    }

    /* ---------- 提示框标签（渲染缓存语言中立，标签由前端按当前语言注入） ---------- */
    B.$$('.blog-admon', prose).forEach(box => {
        const kind = ['tip', 'info', 'warning', 'danger', 'note'].find(k => box.classList.contains('blog-admon--' + k));
        if (!kind || box.querySelector('.blog-admon__label')) return;
        const label = document.createElement('span');
        label.className = 'blog-admon__label';
        label.textContent = B.t(kind);
        box.insertBefore(label, box.firstChild);
    });

    /* ---------- 标题锚点 ---------- */
    B.$$('h1[id], h2[id], h3[id]', prose).forEach(h => {
        const a = document.createElement('a');
        a.className = 'blog-anchor';
        a.href = '#' + h.id;
        a.textContent = '#';
        a.setAttribute('aria-hidden', 'true');
        h.appendChild(a);
    });

    /* ---------- 代码块增强：语言标签 + 复制 + 超高折叠 + 按需高亮 ---------- */
    const pres = B.$$('pre', prose).filter(pre => pre.querySelector('code'));
    pres.forEach(pre => {
        const code = pre.querySelector('code');
        const langMatch = (code.className || '').match(/language-([\w+-]+)/);
        const lang = langMatch ? langMatch[1] : 'text';

        const block = document.createElement('div');
        block.className = 'blog-codeblock';
        pre.parentNode.insertBefore(block, pre);

        const bar = document.createElement('div');
        bar.className = 'blog-codeblock__bar';
        const langEl = document.createElement('span');
        langEl.className = 'blog-codeblock__lang';
        langEl.textContent = lang;
        const copyBtn = document.createElement('button');
        copyBtn.className = 'blog-codeblock__copy';
        copyBtn.type = 'button';
        copyBtn.textContent = B.t('copy');
        copyBtn.addEventListener('click', () => {
            const text = code.textContent || '';
            const ok = () => {
                copyBtn.textContent = '✓ ' + B.t('copied');
                copyBtn.classList.add('is-done');
                B.vibrate(6);
                setTimeout(() => {
                    copyBtn.textContent = B.t('copy');
                    copyBtn.classList.remove('is-done');
                }, 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(ok, () => B.toast(B.t('copyFail'), 'error'));
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.cssText = 'position:fixed;opacity:0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); ok(); } catch (e) { B.toast(B.t('copyFail'), 'error'); }
                ta.remove();
            }
        });
        bar.appendChild(langEl);
        bar.appendChild(copyBtn);
        block.appendChild(bar);
        block.appendChild(pre);

        //超高折叠
        requestAnimationFrame(() => {
            if (pre.scrollHeight > 500) {
                block.classList.add('is-collapsed');
                const expand = document.createElement('button');
                expand.type = 'button';
                expand.className = 'blog-codeblock__expand';
                expand.textContent = B.t('expand') + ' ↓';
                expand.addEventListener('click', () => {
                    block.classList.remove('is-collapsed');
                    expand.remove();
                });
                block.appendChild(expand);
            }
        });
    });

    //hljs 按需加载：已加载过就直接用，避免 pjax 每次切页重复插 <script>
    if (pres.length && B.cfg.hljs) {
        const highlight = () => {
            if (!window.hljs) return;
            pres.forEach(pre => {
                const code = pre.querySelector('code');
                try { window.hljs.highlightElement(code); } catch (e) {}
            });
        };
        if (window.hljs) {
            highlight();
        } else if (!B.__hljsLoading) {
            B.__hljsLoading = true;
            const script = document.createElement('script');
            script.src = B.cfg.hljs;
            script.onload = highlight;
            document.body.appendChild(script);
        } else {
            //另一次切页正在加载，等它好了再上色
            const timer = setInterval(() => {
                if (window.hljs) { clearInterval(timer); highlight(); }
            }, 120);
            if (B.signal) B.signal.addEventListener('abort', () => clearInterval(timer), {once: true});
        }
    }

    /* ---------- TOC（桌面右栏 + 移动端浮钮 Sheet） ---------- */
    const headings = B.$$('h1[id], h2[id], h3[id]', prose);
    if (headings.length >= 2) {
        /* 目录跳转自己接管，不交给浏览器的锚点导航 —— 那条路有两个坑：
           ① 手机端目录装在 <dialog> 模态里，模态打开期间整页滚动是锁着的，
              浏览器那一跳会被直接吞掉，用户点了像没反应；
           ② 同一个标题点第二次时 URL 片段没变，浏览器认为"已经在目标上"就不再滚，
              从别处滚回来再点目录同样失灵。
           自己滚还顺带拿到了平滑滚动与 reduce-motion 兼容。*/
        const gotoHeading = (id, sheet) => {
            const target = document.getElementById(id);
            if (!target) return;
            //先关模态再滚：dialog.close() 是同步的，滚动锁当场就释放了，
            //所以紧接着滚是安全的。这里刻意不套 requestAnimationFrame ——
            //标签页在后台时 rAF 会被节流到几乎不跑，跳转就永远等不到那一帧
            if (sheet) B.closeSheet(sheet, true);
            //replaceState 而不是 pushState：pjax 的 popstate 会把"后退"当成换页整块重载，
            //在同一篇文章里堆一串锚点历史只会让后退变得莫名其妙
            try { history.replaceState(history.state, '', '#' + encodeURIComponent(id)); } catch (e) {}
            target.scrollIntoView({behavior: B.reducedMotion ? 'auto' : 'smooth', block: 'start'});
        };

        const buildToc = (container, sheet) => {
            container.innerHTML = '';
            headings.forEach(h => {
                const a = document.createElement('a');
                a.href = '#' + h.id;
                a.textContent = h.textContent.replace(/#$/, '');
                a.setAttribute('data-level', h.tagName.charAt(1));
                a.setAttribute('data-toc-for', h.id);
                a.addEventListener('click', (e) => {
                    //中键/新窗口等交给浏览器
                    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                    e.preventDefault();
                    gotoHeading(h.id, sheet);
                }, B.signal ? {signal: B.signal} : undefined);
                container.appendChild(a);
            });
        };
        const railToc = B.$('[data-blog-toc]');
        const railCard = B.$('[data-blog-toc-card]');
        if (railToc && railCard) {
            buildToc(railToc);
            railCard.hidden = false;
        }
        const mobileToc = B.$('[data-blog-toc-mobile]');
        const tocSheet = B.$('[data-blog-sheet="toc"]');
        const tocFab = B.$('[data-blog-tocfab]');
        if (mobileToc && tocSheet && tocFab) {
            buildToc(mobileToc, tocSheet);
            tocFab.setAttribute('data-ready', '1');
            tocFab.hidden = false;
            tocFab.addEventListener('click', () => B.openSheet(tocSheet));
        }

        //当前章节高亮
        if ('IntersectionObserver' in window) {
            const mark = (id) => {
                B.$$('[data-toc-for]').forEach(a => a.classList.toggle('is-active', a.getAttribute('data-toc-for') === id));
            };
            let current = '';
            const io = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        current = entry.target.id;
                        mark(current);
                    }
                });
            }, {rootMargin: '-64px 0px -70% 0px', threshold: 0});
            headings.forEach(h => io.observe(h));
            if (B.signal) B.signal.addEventListener('abort', () => io.disconnect(), {once: true});
        }
    }

    /* ---------- 阅读进度条（scroll-timeline 不支持时的 JS 兜底） ---------- */
    const progress = B.$('[data-blog-progress] i');
    if (progress && !CSS.supports('animation-timeline: scroll()')) {
        let ticking = false;
        const update = () => {
            const doc = document.documentElement;
            const max = doc.scrollHeight - innerHeight;
            progress.style.width = (max > 0 ? Math.min(100, window.scrollY / max * 100) : 0) + '%';
            ticking = false;
        };
        on(document, 'scroll', () => {
            if (!ticking) { ticking = true; requestAnimationFrame(update); }
        }, {passive: true});
        update();
    }

    /* ---------- 图片灯箱（dialog + 原生捏合 + 下滑关闭） ---------- */
    let lightbox = document.querySelector('.blog-lightbox');   //pjax 复用已存在的实例
    function openLightbox(src) {
        if (!lightbox) {
            lightbox = document.createElement('dialog');
            lightbox.className = 'blog-lightbox';
            const img = document.createElement('img');
            img.alt = '';
            lightbox.appendChild(img);
            document.body.appendChild(lightbox);
            lightbox.addEventListener('click', () => lightbox.close());
            let startY = 0;
            lightbox.addEventListener('touchstart', (e) => { startY = e.touches[0].clientY; }, {passive: true});
            lightbox.addEventListener('touchmove', (e) => {
                const dy = e.touches[0].clientY - startY;
                if (Math.abs(dy) > 4) img.style.transform = 'translateY(' + dy + 'px) scale(' + Math.max(.72, 1 - Math.abs(dy) / 600) + ')';
            }, {passive: true});
            lightbox.addEventListener('touchend', (e) => {
                const dy = (e.changedTouches[0].clientY - startY);
                if (Math.abs(dy) > 80) lightbox.close(); else img.style.transform = '';
            });
            lightbox.addEventListener('close', () => { img.style.transform = ''; });
        }
        lightbox.querySelector('img').src = src;
        lightbox.showModal();
    }
    prose.addEventListener('click', (e) => {
        const img = e.target.closest('img');
        if (img && !e.target.closest('a')) openLightbox(img.currentSrc || img.src);
    });

    /* ---------- 点赞（optimistic + 粒子 + 触感） ---------- */
    const likeBtn = B.$('[data-blog-like]');
    if (likeBtn) {
        const countEl = B.$('[data-blog-like-count]', likeBtn);
        const article = B.$('[data-post-id]');
        const postId = article ? article.getAttribute('data-post-id') : 0;
        let busy = false;
        likeBtn.addEventListener('click', () => {
            if (!B.cfg.user) { B.loginPrompt(); return; }
            if (busy) return;
            busy = true;
            const wasLiked = likeBtn.getAttribute('aria-pressed') === 'true';
            const oldCount = parseInt(countEl.textContent || '0', 10) || 0;
            //乐观更新
            likeBtn.setAttribute('aria-pressed', wasLiked ? 'false' : 'true');
            countEl.textContent = String(Math.max(0, oldCount + (wasLiked ? -1 : 1)));
            if (!wasLiked && !B.reducedMotion) {
                likeBtn.classList.add('is-burst');
                setTimeout(() => likeBtn.classList.remove('is-burst'), 560);
            }
            B.vibrate(10);
            B.fetch('/like/toggle', {post_id: postId}).then(res => {
                likeBtn.setAttribute('aria-pressed', res.data.liked ? 'true' : 'false');
                countEl.textContent = String(res.data.likes);
                busy = false;
            }).catch(() => {
                likeBtn.setAttribute('aria-pressed', wasLiked ? 'true' : 'false');
                countEl.textContent = String(oldCount);
                busy = false;
            });
        });
    }

    /* ---------- 分享 ---------- */
    const shareBtn = B.$('[data-blog-share]');
    if (shareBtn) {
        shareBtn.addEventListener('click', () => {
            const url = location.href;
            const title = document.title;
            if (navigator.share) {
                navigator.share({title, url}).catch(() => {});
                return;
            }
            const done = () => B.toast(B.t('linkCopied'));
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done, () => B.toast(B.t('copyFail'), 'error'));
            } else {
                B.toast(url);
            }
        });
    }
    });
})();
