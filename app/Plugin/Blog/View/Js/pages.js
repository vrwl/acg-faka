!function () {
    /**
     * 次元博客 · 独立页面列表（与文章共表，equal-type=page）
     */
    let table;
    const namespace = '.blogPagesController';
    let controllerActive = true;
    if (typeof window.__blogPagesDestroy === 'function') window.__blogPagesDestroy();

    const API = (p) => '/plugin/Blog/admin/' + p;
    //表格 formatter 返回的是 HTML 字符串，任何入库数据插进去前都得转义
    const esc = (s) => $('<i></i>').text(s == null ? '' : String(s)).html();
    //后台导航走 pjax（route() 是 URL 构造器不是跳转函数，别用它）
    const go = (url) => {
        if (window.AdminMobile && typeof AdminMobile.navigate === 'function') { AdminMobile.navigate(url); return; }
        if (window.jQuery && $.pjax) { $.pjax({url: url, container: '#pjax-container', fragment: '#pjax-container', timeout: 8000}); return; }
        window.location.href = url;
    };
    const goWrite = (id) => go('/plugin/Blog/panel/write' + (id ? '?id=' + id : '?type=page'));

    table = new Table(API('post/data') + '?type=page', '#blog-pages-table');
    table.setPagination(15, [15, 30, 50]);
    table.setColumns([
        {checkbox: true},
        {
            field: 'title', title: i18n('页面标题'),
            formatter: (value, row) => `<div><a href="#" data-acg-noop class="fw-bolder text-gray-800 blog-edit-link">${esc(value)}</a></div>` +
                `<div class="text-muted" style="font-size:12px">/${esc(row.slug)}</div>`,
            events: {'click .blog-edit-link': (event, value, row) => goWrite(row.id)}
        },
        {
            field: 'status', title: i18n('状态'), width: 80,
            formatter: (value, row) => {
                if (row.status == 0) return format.badge(i18n('草稿'), 'a-badge-dark');
                if (row.status == 2) return format.badge(i18n('隐藏'), 'a-badge-warning');
                return format.badge(i18n('已发布'), 'a-badge-success');
            }
        },
        {field: 'weight', title: i18n('排序'), width: 60},
        {field: 'views', title: i18n('浏览'), width: 60},
        {field: 'update_time', title: i18n('更新时间'), width: 140, formatter: v => v || '-'},
        {
            field: 'operation', title: i18n('操作'), type: 'button', buttons: [
                {
                    icon: 'blogi blogi-edit', title: i18n('编辑'), class: 'text-primary',
                    click: (event, value, row) => goWrite(row.id)
                },
                {
                    icon: 'blogi blogi-open', tips: i18n('前台预览'),
                    click: (event, value, row) => window.open('/plugin/Blog/page/detail?slug=' + encodeURIComponent(row.slug))
                },
                {
                    icon: 'blogi blogi-delete', tips: i18n('删除'), class: 'text-danger',
                    click: (event, value, row) => {
                        message.ask(i18n('确认删除这个页面？'), () => {
                            util.post(API('post/del'), {list: [row.id]}, () => table.refresh());
                        }, i18n('确认永久删除'), i18n('确认删除'));
                    }
                }
            ]
        }
    ]);
    table.setSearch([
        {title: i18n('标题(模糊)'), name: 'search-title', type: 'input'}
    ]);
    table.setDeleteSelector('.btn-app-del', API('post/del'));
    table.render();

    $('.btn-app-create').off(namespace).on('click' + namespace, () => goWrite(0));

    (window.AdminMobileRecipeQueue = window.AdminMobileRecipeQueue || []).push({
        id: 'blog-pages',
        title: i18n('独立页面'),
        pageType: 'list',
        match: {routes: ['/plugin/Blog/panel/pages']},
        primary: {field: 'title', label: i18n('页面标题')},
        status: [{field: 'status', label: i18n('状态')}],
        metrics: [{field: 'views', label: i18n('浏览')}, {field: 'weight', label: i18n('排序')}],
        details: [{field: 'slug', label: 'Slug'}, {field: 'update_time', label: i18n('更新时间')}],
        actions: {
            primary: [{id: 'operation:0', label: i18n('编辑')}],
            more: [{id: 'operation:1', label: i18n('前台预览')}, {id: 'operation:2', label: i18n('删除'), danger: true}],
            batch: [{selector: '.btn-app-del', label: i18n('删除选中'), role: 'batch', danger: true}],
            toolbar: [{selector: '.btn-app-create', label: i18n('新建页面'), role: 'primary'}]
        }
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-app-create, .btn-app-del').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__blogPagesDestroy === destroy) delete window.__blogPagesDestroy;
    }

    window.__blogPagesDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
