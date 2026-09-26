/* 次元博客 · front-comments.js —— 前台评论区：嵌套加载 / 发表与回复 / 待审态 / 折叠 */
(function () {
    'use strict';
    const B = window.BLOG;

    B.page(function commentsPage() {
    const section = B.$('[data-blog-comments]');
    if (!section) return;

    const postId = section.getAttribute('data-blog-comments');
    const allowed = section.getAttribute('data-blog-allow') === '1';
    const listEl = B.$('[data-blog-comments-list]', section);
    const moreEl = B.$('[data-blog-comments-more]', section);
    const moreBtn = moreEl ? moreEl.querySelector('button') : null;
    const composerHost = B.$('[data-blog-composer]', section);
    const countEl = B.$('[data-blog-comments-count]', section);

    let page = 0, pages = 1, loading = false;
    let replying = null; //{id, name}

    /* ---------- 评论已关闭 ---------- */
    if (!allowed) {
        if (composerHost) {
            composerHost.innerHTML = '';
            const note = document.createElement('div');
            note.className = 'blog-logincard';
            note.textContent = B.t('commentClosed');
            composerHost.appendChild(note);
        }
        if (listEl) listEl.innerHTML = '';
        loadPage(1); //已有评论仍展示（只关新增时的体验更合理）
    }

    /* ---------- 发表框 ---------- */
    function buildComposer() {
        if (!composerHost || !allowed) return;
        composerHost.innerHTML = '';

        if (!B.cfg.user) {
            const card = document.createElement('div');
            card.className = 'blog-logincard';
            const span = document.createElement('span');
            span.textContent = B.t('loginToComment');
            const a = document.createElement('a');
            a.href = B.cfg.login;
            a.textContent = B.t('toLogin');
            card.appendChild(span);
            card.appendChild(a);
            composerHost.appendChild(card);
            return;
        }

        const wrap = document.createElement('div');
        wrap.className = 'blog-composer';
        wrap.appendChild(B.avatarNode(B.cfg.user.name, B.cfg.user.avatar, 'blog-composer__avatar blog-cmt__avatar'));

        const main = document.createElement('div');
        main.className = 'blog-composer__main';

        //输入区与底部栏同处一张卡（.blog-composer__field），视觉更整
        const field = document.createElement('div');
        field.className = 'blog-composer__field';

        const box = document.createElement('textarea');
        box.className = 'blog-composer__box';
        box.rows = 1;
        box.maxLength = B.cfg.comment.maxlen;
        box.placeholder = B.t('commentPlaceholder');

        const autogrow = () => {
            box.style.height = 'auto';
            const h = Math.min(220, box.scrollHeight);
            box.style.height = h + 'px';
            //只有真到 max-height 才允许出滚动条，否则永远藏起来
            box.classList.toggle('is-scroll', box.scrollHeight > 220);
        };
        box.addEventListener('input', () => {
            autogrow();
            const left = B.cfg.comment.maxlen - box.value.length;
            countHint.textContent = left < 60 ? B.t('charsLeft') + ' ' + left : '';
            countHint.hidden = left >= 60;
            send.disabled = !box.value.trim();
        });

        const foot = document.createElement('div');
        foot.className = 'blog-composer__foot';

        const replyChip = document.createElement('span');
        replyChip.className = 'blog-composer__replying';
        replyChip.hidden = true;

        const countHint = document.createElement('span');
        countHint.className = 'blog-composer__count';
        countHint.hidden = true;

        const send = document.createElement('button');
        send.className = 'blog-composer__send';
        send.type = 'button';
        send.disabled = true;
        send.textContent = B.t('send');
        send.addEventListener('click', submit);

        //Ctrl/Cmd + Enter 提交
        box.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && !send.disabled) submit();
        });

        field.appendChild(box);
        foot.appendChild(replyChip);
        foot.appendChild(countHint);
        foot.appendChild(send);
        field.appendChild(foot);
        main.appendChild(field);
        wrap.appendChild(main);
        composerHost.appendChild(wrap);

        composerHost.__ui = {box, send, replyChip, autogrow};
    }

    function setReplying(target) {
        replying = target;
        const ui = composerHost && composerHost.__ui;
        if (!ui) {
            if (!B.cfg.user) B.loginPrompt();
            return;
        }
        ui.replyChip.hidden = !target;
        ui.replyChip.innerHTML = '';
        if (target) {
            ui.replyChip.appendChild(document.createTextNode(B.t('replyTo') + ' @'));
            const who = document.createElement('b');
            who.textContent = target.name;
            ui.replyChip.appendChild(who);
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.textContent = '✕';
            cancel.setAttribute('aria-label', B.t('cancel'));
            cancel.addEventListener('click', () => setReplying(null));
            ui.replyChip.appendChild(cancel);
            ui.box.focus();
            composerHost.scrollIntoView({behavior: B.reducedMotion ? 'auto' : 'smooth', block: 'center'});
        }
    }

    function submit() {
        const ui = composerHost.__ui;
        const content = ui.box.value.trim();
        if (!content) return;
        ui.send.disabled = true;
        ui.send.textContent = B.t('sending');
        B.fetch('/comment/create', {
            post_id: postId,
            parent_id: replying ? replying.id : 0,
            content_b64: B.b64(content)
        }).then(res => {
            ui.box.value = '';
            ui.box.style.height = '46px';
            ui.box.classList.remove('is-scroll');
            const cnt = composerHost.querySelector('.blog-composer__count');
            if (cnt) cnt.hidden = true;
            ui.send.textContent = B.t('send');
            B.vibrate(8);
            const item = res.data.comment;
            const pending = !!res.data.pending;
            B.toast(pending ? B.t('pendingTip') : B.t('commentOk'));
            insertComment(item, replying);
            if (!pending && countEl) countEl.textContent = String((parseInt(countEl.textContent || '0', 10) || 0) + 1);
            setReplying(null);
        }).catch(() => {
            ui.send.disabled = false;
            ui.send.textContent = B.t('send');
        });
    }

    /* ---------- 渲染 ---------- */
    function commentNode(item, isChild) {
        const li = document.createElement('div');
        li.className = 'blog-cmt' + (item.pending ? ' blog-cmt--pending' : '');
        li.setAttribute('data-cmt-id', item.id);

        li.appendChild(B.avatarNode(item.name, item.avatar, 'blog-cmt__avatar'));

        const main = document.createElement('div');
        main.className = 'blog-cmt__main';

        const head = document.createElement('div');
        head.className = 'blog-cmt__head';
        const name = document.createElement('span');
        name.className = 'blog-cmt__name';
        name.textContent = item.name;
        head.appendChild(name);
        if (item.is_admin) {
            const badge = document.createElement('span');
            badge.className = 'blog-cmt__badge blog-cmt__badge--admin';
            badge.textContent = B.t('admin');
            head.appendChild(badge);
        } else if (item.is_author) {
            const badge = document.createElement('span');
            badge.className = 'blog-cmt__badge blog-cmt__badge--author';
            badge.textContent = B.t('author');
            head.appendChild(badge);
        }
        const time = document.createElement('span');
        time.className = 'blog-cmt__time';
        time.textContent = B.relativeTime(item.time.replace(' ', 'T'));
        time.title = item.time;
        head.appendChild(time);
        if (item.pending) {
            const tag = document.createElement('span');
            tag.className = 'blog-cmt__pendingtag';
            tag.textContent = B.t('pendingTag');
            head.appendChild(tag);
        }
        main.appendChild(head);

        const content = document.createElement('div');
        content.className = 'blog-cmt__content';
        if (item.reply_to && isChild) {
            const replyTo = document.createElement('span');
            replyTo.className = 'blog-cmt__replyto';
            replyTo.textContent = '@' + item.reply_to + ' ';
            content.appendChild(replyTo);
        }
        content.appendChild(document.createTextNode(item.content));
        main.appendChild(content);

        if (allowed && !item.pending) {
            const acts = document.createElement('div');
            acts.className = 'blog-cmt__acts';
            const reply = document.createElement('button');
            reply.type = 'button';
            reply.textContent = B.t('replyTo');
            reply.addEventListener('click', () => setReplying({id: item.id, name: item.name}));
            acts.appendChild(reply);
            main.appendChild(acts);
        }

        li.appendChild(main);
        return li;
    }

    function rootNode(item) {
        const wrap = document.createElement('div');
        wrap.setAttribute('data-cmt-root', item.id);
        wrap.appendChild(commentNode(item, false));

        const childrenBox = document.createElement('div');
        childrenBox.className = 'blog-cmt__children';
        const children = item.children || [];
        const visible = children.slice(0, 3);
        const rest = children.slice(3);
        visible.forEach(child => childrenBox.appendChild(commentNode(child, true)));
        wrap.appendChild(childrenBox);

        if (rest.length) {
            const fold = document.createElement('button');
            fold.type = 'button';
            fold.className = 'blog-cmt__fold';
            fold.textContent = B.t('expandReplies') + ' (' + rest.length + ')';
            fold.addEventListener('click', () => {
                rest.forEach(child => childrenBox.appendChild(commentNode(child, true)));
                fold.remove();
            });
            wrap.appendChild(fold);
        }
        if (!children.length) childrenBox.hidden = true;
        return wrap;
    }

    function insertComment(item, replyTarget) {
        if (!listEl) return;
        if (replyTarget) {
            //挂到目标所在的顶楼下
            let rootId = item.root_id || replyTarget.id;
            const rootWrap = listEl.querySelector('[data-cmt-root="' + rootId + '"]');
            if (rootWrap) {
                const box = rootWrap.querySelector('.blog-cmt__children');
                box.hidden = false;
                box.appendChild(commentNode(item, true));
                return;
            }
        }
        const empty = listEl.querySelector('.blog-cmt-empty');
        if (empty) empty.remove();
        listEl.insertBefore(rootNode(Object.assign({children: []}, item)), listEl.firstChild);
    }

    /* ---------- 加载 ---------- */
    function loadPage(n) {
        if (loading) return;
        loading = true;
        B.fetch('/comment/list', {post_id: postId, page: n}).then(res => {
            if (n === 1 && listEl) listEl.innerHTML = '';
            const data = res.data || {};
            (data.list || []).forEach(item => listEl.appendChild(rootNode(item)));
            page = data.page || n;
            pages = data.pages || 1;
            if (countEl && typeof data.count_approved === 'number') countEl.textContent = String(data.count_approved);
            if (moreEl) moreEl.hidden = !(page < pages);
            if (!(data.list || []).length && page === 1 && listEl && !listEl.children.length) {
                const empty = document.createElement('div');
                empty.className = 'blog-cmt-empty';
                const icon = document.createElement('span');
                icon.className = 'blog-cmt-empty__icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5c0 4.5-4 7.5-9 7.5-1 0-2-.12-2.9-.35L4 20l1.2-3.6C3.8 15.1 3 13.4 3 11.5 3 7 7 4 12 4s9 3 9 7.5z"/></svg>';
                const text = document.createElement('span');
                text.textContent = B.t('noComments');
                empty.appendChild(icon);
                empty.appendChild(text);
                listEl.appendChild(empty);
            }
            loading = false;
        }).catch(() => {
            loading = false;
            if (n === 1 && listEl) {
                listEl.innerHTML = '';
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'blog-cmt__fold';
                retry.style.marginLeft = '0';
                retry.textContent = B.t('loadFail');
                retry.addEventListener('click', () => loadPage(1));
                listEl.appendChild(retry);
            }
        });
    }

    if (moreBtn) moreBtn.addEventListener('click', () => loadPage(page + 1));

    if (allowed) {
        buildComposer();
        loadPage(1);
    }

    //#comments 锚点直达时平滑定位
    if (location.hash === '#comments') {
        setTimeout(() => section.scrollIntoView({behavior: B.reducedMotion ? 'auto' : 'smooth'}), 200);
    }
    });
})();
