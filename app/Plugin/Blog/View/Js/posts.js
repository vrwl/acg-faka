!function () {
    /**
     * 次元博客 · 文章列表
     * 套路完全对齐 assets/admin/controller/trade/coupon.js：IIFE + PJAX 销毁守卫 + Table 三件套。
     */
    let table;
    const namespace = '.blogPostsController';
    let controllerActive = true;
    if (typeof window.__blogPostsDestroy === 'function') window.__blogPostsDestroy();

    const API = (p) => '/plugin/Blog/admin/' + p;
    //表格 formatter 返回的是 HTML 字符串，任何入库数据插进去前都得转义
    const esc = (s) => $('<i></i>').text(s == null ? '' : String(s)).html();
    //后台导航走 pjax（route() 是 URL 构造器不是跳转函数，别用它）
    const go = (url) => {
        if (window.AdminMobile && typeof AdminMobile.navigate === 'function') { AdminMobile.navigate(url); return; }
        if (window.jQuery && $.pjax) { $.pjax({url: url, container: '#pjax-container', fragment: '#pjax-container', timeout: 8000}); return; }
        window.location.href = url;
    };
    const goWrite = (id, type) => go('/plugin/Blog/panel/write' + (id ? '?id=' + id : (type ? '?type=' + type : '')));

    //概览统计卡（数字滚动到位，空值保持灰）
    util.post({
        url: API('stats/overview'),
        loader: false,
        done: res => {
            const d = res.data || {};
            $('[data-stat]').each(function () {
                const el = this;
                const target = Number(d[$(el).attr('data-stat')] || 0);
                $(el).toggleClass('is-empty', target === 0);
                if (target === 0) { el.textContent = '0'; return; }
                const start = performance.now();
                const tick = (now) => {
                    const p = Math.min(1, (now - start) / 520);
                    el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))).toLocaleString();
                    if (p < 1) requestAnimationFrame(tick);
                };
                requestAnimationFrame(tick);
            });
        },
        error: () => {}
    });

    //分类下拉（搜索用，树序缩进）
    util.post({
        url: API('category/data'),
        loader: false,
        done: res => {
            const dict = [{id: 0, name: i18n('未分类')}];
            (res.data.list || []).forEach(c => {
                dict.push({id: c.id, name: '　'.repeat(c.depth || 0) + c.name});
            });
            buildTable(dict);
        },
        error: () => buildTable([])
    });

    function statusBadge(row) {
        if (row.status == 0) return format.badge(i18n('草稿'), 'a-badge-dark');
        if (row.status == 2) return format.badge(i18n('隐藏'), 'a-badge-warning');
        if (row.scheduled == 1) return format.badge(i18n('定时中'), 'a-badge-info');
        return format.badge(i18n('已发布'), 'a-badge-success');
    }

    function buildTable(categoryDict) {
        table = new Table(API('post/data'), '#blog-posts-table');
        table.setPagination(15, [15, 30, 50, 100]);
        table.setColumns([
            {checkbox: true},
            {field: 'cover', title: '', type: 'image', width: 30, style: 'border-radius:8px;'},
            {
                field: 'title', title: i18n('标题'),
                formatter: (value, row) => {
                    let badge = row.top == 1 ? format.badge(i18n('置顶'), 'a-badge-primary') + ' ' : '';
                    return `<div><a href="#" data-acg-noop class="fw-bolder text-gray-800 blog-edit-link">${badge}${esc(value)}</a></div>` +
                        `<div class="text-muted" style="font-size:12px">/${esc(row.slug)}</div>`;
                },
                events: {
                    'click .blog-edit-link': (event, value, row) => goWrite(row.id)
                }
            },
            {
                field: 'category', title: i18n('分类'), width: 90,
                formatter: (value, row) => row.category ? esc(row.category.name) : `<span class="text-muted">${i18n('未分类')}</span>`
            },
            {
                field: 'tags', title: i18n('标签'),
                formatter: (value) => {
                    if (!value || !value.length) return '<span class="text-muted">-</span>';
                    const shown = value.slice(0, 3).map(t => format.badge(esc(t.name), 'a-badge-light')).join(' ');
                    return value.length > 3 ? shown + ` <span class="text-muted">+${value.length - 3}</span>` : shown;
                }
            },
            {field: 'views', title: i18n('浏览'), width: 55},
            {field: 'comments_count', title: i18n('评论'), width: 55},
            {field: 'likes', title: i18n('点赞'), width: 55},
            {field: 'status', title: i18n('状态'), width: 70, formatter: (value, row) => statusBadge(row)},
            {field: 'publish_time', title: i18n('发布时间'), width: 135, formatter: v => v || '-'},
            {
                field: 'operation', title: i18n('操作'), type: 'button', buttons: [
                    {
                        icon: 'blogi blogi-edit', title: i18n('编辑'), class: 'text-primary',
                        click: (event, value, row) => goWrite(row.id)
                    },
                    {
                        icon: 'blogi blogi-open', tips: i18n('前台预览'),
                        click: (event, value, row) => window.open('/plugin/Blog/post/detail?slug=' + encodeURIComponent(row.slug))
                    },
                    {
                        icon: 'blogi blogi-publish', tips: i18n('发布'), class: 'text-success',
                        show: row => row.status != 1,
                        click: (event, value, row) => {
                            util.post(API('post/setStatus'), {list: [row.id], status: 1}, () => table.refresh());
                        }
                    },
                    {
                        icon: 'blogi blogi-eye-off', tips: i18n('隐藏'), class: 'text-warning',
                        show: row => row.status == 1,
                        click: (event, value, row) => {
                            util.post(API('post/setStatus'), {list: [row.id], status: 2}, () => table.refresh());
                        }
                    },
                    {
                        icon: 'blogi blogi-pin', tips: i18n('置顶/取消置顶'),
                        click: (event, value, row) => {
                            util.post(API('post/setTop'), {id: row.id, top: row.top == 1 ? 0 : 1}, () => table.refresh());
                        }
                    },
                    {
                        icon: 'blogi blogi-delete', tips: i18n('删除'), class: 'text-danger',
                        click: (event, value, row) => {
                            message.ask(i18n('确认删除这篇文章？评论与点赞会一并删除。'), () => {
                                util.post(API('post/del'), {list: [row.id]}, () => table.refresh());
                            }, i18n('确认永久删除'), i18n('确认删除'));
                        }
                    }
                ]
            }
        ]);
        table.setSearch([
            {title: i18n('标题(模糊)'), name: 'search-title', type: 'input'},
            {title: i18n('分类'), name: 'equal-category_id', type: 'select', dict: categoryDict},
            {
                title: i18n('状态'), name: 'equal-status', type: 'select',
                dict: [{id: 0, name: i18n('草稿')}, {id: 1, name: i18n('已发布')}, {id: 2, name: i18n('隐藏')}]
            },
            {title: i18n('发布起始'), name: 'betweenStart-publish_time', type: 'date', hide: true},
            {title: i18n('发布结束'), name: 'betweenEnd-publish_time', type: 'date', hide: true}
        ]);
        table.setDeleteSelector('.btn-app-del', API('post/del'));
        table.render();
    }

    $('.btn-app-create').off(namespace).on('click' + namespace, () => goWrite(0, 'post'));

    //AdminMobile recipe：手机卡片流精修
    (window.AdminMobileRecipeQueue = window.AdminMobileRecipeQueue || []).push({
        id: 'blog-posts',
        title: i18n('博客文章'),
        pageType: 'list',
        match: {routes: ['/plugin/Blog/panel/posts'], queryUrls: ['/plugin/Blog/admin/post/data']},
        primary: {field: 'title', label: i18n('标题')},
        media: {fields: ['cover'], type: 'image', shape: 'rounded'},
        status: [{field: 'status', label: i18n('状态')}],
        metrics: [
            {field: 'views', label: i18n('浏览')},
            {field: 'comments_count', label: i18n('评论')},
            {field: 'likes', label: i18n('点赞')}
        ],
        details: [
            {field: 'slug', label: 'Slug'},
            {field: 'publish_time', label: i18n('发布时间')},
            {field: 'author_name', label: i18n('作者')}
        ],
        actions: {
            primary: [{selector: null, id: 'operation:0', label: i18n('编辑')}],
            more: [
                {id: 'operation:1', label: i18n('前台预览')},
                {id: 'operation:2', label: i18n('发布')},
                {id: 'operation:3', label: i18n('隐藏')},
                {id: 'operation:4', label: i18n('置顶/取消')},
                {id: 'operation:5', label: i18n('删除'), danger: true}
            ],
            batch: [{selector: '.btn-app-del', label: i18n('删除选中'), role: 'batch', danger: true}],
            toolbar: [{selector: '.btn-app-create', label: i18n('写文章'), role: 'primary'}]
        }
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-app-create, .btn-app-del').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__blogPostsDestroy === destroy) delete window.__blogPostsDestroy;
    }

    window.__blogPostsDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
