!function () {
    /**
     * 次元博客 · 评论审核台（后台）
     * 回复内容 base64 提交（评论里可能贴 SQL/命令，绕 WAF 误杀）。
     */
    let table;
    const namespace = '.blogCommentsController';
    let controllerActive = true;
    if (typeof window.__blogCommentsDestroy === 'function') window.__blogCommentsDestroy();

    const API = (p) => '/plugin/Blog/admin/' + p;
    const b64 = (s) => btoa(unescape(encodeURIComponent(s)));
    const esc = (s) => $('<i></i>').text(s == null ? '' : String(s)).html();

    const statusDict = [
        {id: 0, name: i18n('待审')},
        {id: 1, name: i18n('已通过')},
        {id: 2, name: i18n('垃圾')}
    ];

    //概览统计卡
    const loadStats = () => util.post({
        url: API('stats/overview'),
        loader: false,
        done: res => {
            const d = res.data || {};
            $('[data-stat]').each(function () {
                const value = Number(d[$(this).attr('data-stat')] || 0);
                $(this).toggleClass('is-empty', value === 0).text(value.toLocaleString());
            });
        },
        error: () => {}
    });
    loadStats();

    const statusBadge = (status) => {
        if (status == 0) return format.badge(i18n('待审'), 'a-badge-warning');
        if (status == 2) return format.badge(i18n('垃圾'), 'a-badge-danger');
        return format.badge(i18n('已通过'), 'a-badge-success');
    };

    //查看全文 + 上下文
    const view = (row) => {
        let html = '<div style="padding:6px 2px;line-height:1.9;word-break:break-word">';
        if (row.parent) {
            html += `<div style="padding:8px 12px;border-left:3px solid var(--md-outline,#ccc);background:var(--md-surface-2,#f6f7f9);border-radius:6px;margin-bottom:10px">
                <div class="text-muted" style="font-size:12px">${i18n('回复')} @${esc(row.parent.user_name)}：</div>
                <div>${esc(row.parent.content)}</div></div>`;
        }
        html += `<div style="white-space:pre-wrap">${esc(row.content)}</div>`;
        html += `<div class="text-muted" style="font-size:12px;margin-top:12px">
            ${esc(row.user_name)} · ${esc(row.create_time)} · IP: ${esc(row.create_ip || '-')}<br>UA: ${esc(row.ua || '-')}</div></div>`;
        layer.open({
            type: 1, title: i18n('评论详情'), area: ['520px', 'auto'], maxHeight: 560,
            shadeClose: true, content: html
        });
    };

    //快捷回复
    const reply = (row) => {
        component.popup({
            submit: (data, index) => {
                const content = (data.reply_content || '').trim();
                if (!content) { layer.msg(i18n('回复内容不能为空')); return; }
                util.post(API('comment/reply'), {id: row.id, content_b64: b64(content)}, () => {
                    layer.close(index);
                    message.success(i18n('已回复'));
                    table.refresh(); loadStats();
                });
            },
            tab: [{
                name: util.icon('blogi blogi-reply') + ' ' + i18n('回复评论'),
                form: [
                    {
                        title: false, name: 'reply_context', type: 'custom',
                        complete: (form, dom) => {
                            dom.html(`<div style="padding:8px 12px;border-left:3px solid var(--md-primary,#3699ff);background:var(--md-surface-2,#f6f7f9);border-radius:6px;margin-bottom:4px;word-break:break-word">
                                <div class="text-muted" style="font-size:12px">@${esc(row.user_name)}：</div>
                                <div style="white-space:pre-wrap">${esc(row.content)}</div></div>`);
                        }
                    },
                    {title: i18n('回复内容'), name: 'reply_content', type: 'textarea', height: 110, required: true, placeholder: i18n('以管理员身份回复（会自动通过被回复的评论）')}
                ]
            }],
            width: '520px',
            message: false
        });
    };

    const batch = (status, label) => {
        const ids = table.getSelectionIds();
        if (!ids.length) { layer.msg(i18n('请至少勾选 1 条评论')); return; }
        message.ask(`${label} <b>${ids.length}</b> ${i18n('条评论')}？`, () => {
            util.post(API('comment/audit'), {list: ids, status: status}, res => {
                message.success(`${i18n('已处理')} ${Number(res.data?.count || 0)} ${i18n('条')}`);
                table.refresh(); loadStats();
            });
        }, label, i18n('确认'));
    };

    table = new Table(API('comment/data'), '#blog-comments-table');
    table.setPagination(20, [20, 50, 100]);
    table.setColumns([
        {checkbox: true},
        {
            field: 'user_name', title: i18n('用户'), width: 120,
            formatter: (value, row) => {
                const avatar = row.user && row.user.avatar ? format.avatar(row.user.avatar) + ' ' : '';
                const admin = row.is_admin == 1 ? ' ' + format.badge(i18n('管理员'), 'a-badge-primary') : '';
                return avatar + esc(value) + admin;
            }
        },
        {
            field: 'content', title: i18n('内容'),
            formatter: (value, row) => {
                const text = String(value || '');
                const shown = text.length > 80 ? text.slice(0, 80) + '…' : text;
                const replyTag = row.reply_user_name ? `<span class="text-muted" style="font-size:12px">${i18n('回复')} @${esc(row.reply_user_name)}：</span>` : '';
                return `<a href="#" data-acg-noop class="text-gray-800 blog-view-comment" style="word-break:break-word">${replyTag}${esc(shown)}</a>`;
            },
            events: {'click .blog-view-comment': (event, value, row) => view(row)}
        },
        {
            field: 'post', title: i18n('文章'), width: 150,
            formatter: (value, row) => {
                if (!row.post) return '<span class="text-muted">-</span>';
                const title = row.post.title.length > 16 ? row.post.title.slice(0, 16) + '…' : row.post.title;
                return `<a href="/plugin/Blog/post/detail?slug=${encodeURIComponent(row.post.slug)}" target="_blank">${esc(title)}</a>`;
            }
        },
        {field: 'create_ip', title: 'IP', width: 110, formatter: v => v || '-'},
        {field: 'status', title: i18n('状态'), width: 70, formatter: v => statusBadge(v)},
        {field: 'create_time', title: i18n('时间'), width: 140},
        {
            field: 'operation', title: i18n('操作'), type: 'button', buttons: [
                {
                    icon: 'blogi blogi-check', tips: i18n('通过'), class: 'text-success',
                    show: row => row.status != 1,
                    click: (event, value, row) => util.post(API('comment/audit'), {list: [row.id], status: 1}, () => { table.refresh(); loadStats(); })
                },
                {
                    icon: 'blogi blogi-undo', tips: i18n('退回待审'), class: 'text-warning',
                    show: row => row.status == 1,
                    click: (event, value, row) => util.post(API('comment/audit'), {list: [row.id], status: 0}, () => { table.refresh(); loadStats(); })
                },
                {
                    icon: 'blogi blogi-reply', tips: i18n('回复'), class: 'text-primary',
                    click: (event, value, row) => reply(row)
                },
                {
                    icon: 'blogi blogi-block', tips: i18n('标记垃圾'),
                    show: row => row.status != 2,
                    click: (event, value, row) => util.post(API('comment/audit'), {list: [row.id], status: 2}, () => { table.refresh(); loadStats(); })
                },
                {
                    icon: 'blogi blogi-delete', tips: i18n('删除'), class: 'text-danger',
                    click: (event, value, row) => {
                        message.ask(i18n('确认删除？若是顶楼评论，其下所有回复会一并删除。'), () => {
                            util.post(API('comment/del'), {list: [row.id]}, () => { table.refresh(); loadStats(); });
                        }, i18n('确认永久删除'), i18n('确认删除'));
                    }
                }
            ]
        }
    ]);
    table.setSearch([
        {title: i18n('状态'), name: 'equal-status', type: 'select', dict: statusDict},
        {title: i18n('内容(模糊)'), name: 'search-content', type: 'input'},
        {title: i18n('用户名(模糊)'), name: 'search-user_name', type: 'input'},
        {title: i18n('起始时间'), name: 'betweenStart-create_time', type: 'date', hide: true},
        {title: i18n('结束时间'), name: 'betweenEnd-create_time', type: 'date', hide: true}
    ]);
    table.render();

    $('.btn-app-approve').off(namespace).on('click' + namespace, () => batch(1, i18n('批量通过')));
    $('.btn-app-spam').off(namespace).on('click' + namespace, () => batch(2, i18n('批量标记垃圾')));
    $('.btn-app-del').off(namespace).on('click' + namespace, () => {
        const ids = table.getSelectionIds();
        if (!ids.length) { layer.msg(i18n('请至少勾选 1 条评论')); return; }
        message.ask(`${i18n('确认删除')} <b>${ids.length}</b> ${i18n('条评论？顶楼评论的回复会一并删除。')}`, () => {
            util.post(API('comment/del'), {list: ids}, res => {
                message.success(`${i18n('已删除')} ${Number(res.data?.count || 0)} ${i18n('条')}`);
                table.refresh(); loadStats();
            });
        }, i18n('确认永久删除'), i18n('确认删除'));
    });

    (window.AdminMobileRecipeQueue = window.AdminMobileRecipeQueue || []).push({
        id: 'blog-comments',
        title: i18n('博客评论'),
        pageType: 'list',
        match: {routes: ['/plugin/Blog/panel/comments'], queryUrls: ['/plugin/Blog/admin/comment/data']},
        primary: {field: 'content', label: i18n('内容')},
        status: [{field: 'status', label: i18n('状态')}],
        details: [
            {field: 'user_name', label: i18n('用户')},
            {field: 'create_ip', label: 'IP'},
            {field: 'create_time', label: i18n('时间')}
        ],
        actions: {
            primary: [{id: 'operation:0', label: i18n('通过')}],
            more: [
                {id: 'operation:1', label: i18n('退回待审')},
                {id: 'operation:2', label: i18n('回复')},
                {id: 'operation:3', label: i18n('标记垃圾')},
                {id: 'operation:4', label: i18n('删除'), danger: true}
            ],
            batch: [
                {selector: '.btn-app-approve', label: i18n('批量通过'), role: 'batch'},
                {selector: '.btn-app-spam', label: i18n('批量垃圾'), role: 'batch'},
                {selector: '.btn-app-del', label: i18n('批量删除'), role: 'batch', danger: true}
            ]
        }
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-app-approve, .btn-app-spam, .btn-app-del').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__blogCommentsDestroy === destroy) delete window.__blogCommentsDestroy;
    }

    window.__blogCommentsDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
