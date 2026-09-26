!function () {
    /**
     * 次元博客 · 标签管理
     */
    let table;
    const namespace = '.blogTagsController';
    let controllerActive = true;
    if (typeof window.__blogTagsDestroy === 'function') window.__blogTagsDestroy();

    const API = (p) => '/plugin/Blog/admin/' + p;
    //表格 formatter 返回的是 HTML 字符串，任何入库数据插进去前都得转义
    const esc = (s) => $('<i></i>').text(s == null ? '' : String(s)).html();

    const modal = (row = null) => {
        component.popup({
            submit: API('tag/save'),
            tab: [{
                name: util.icon('blogi blogi-tag') + ' ' + (row ? i18n('编辑标签') : i18n('新建标签')),
                form: [
                    {title: i18n('标签名称'), name: 'name', type: 'input', required: true},
                    {title: 'Slug', name: 'slug', type: 'input', placeholder: i18n('URL 别名，留空自动生成')}
                ]
            }],
            assign: row || {},
            width: '460px',
            done: () => table.refresh()
        });
    };

    table = new Table(API('tag/data'), '#blog-tags-table');
    table.setPagination(20, [20, 50, 100]);
    table.setColumns([
        {checkbox: true},
        {field: 'name', title: i18n('标签名称'), formatter: v => format.badge(v, 'a-badge-light')},
        {field: 'slug', title: 'Slug', formatter: v => `<span class="text-muted">/${esc(v)}</span>`},
        {field: 'post_count', title: i18n('文章数'), width: 80},
        {field: 'create_time', title: i18n('创建时间'), width: 150},
        {
            field: 'operation', title: i18n('操作'), type: 'button', buttons: [
                {
                    icon: 'blogi blogi-edit', title: i18n('编辑'), class: 'text-primary',
                    click: (event, value, row) => modal(row)
                },
                {
                    icon: 'blogi blogi-open', tips: i18n('前台预览'),
                    click: (event, value, row) => window.open('/plugin/Blog/tag/detail?slug=' + encodeURIComponent(row.slug))
                },
                {
                    icon: 'blogi blogi-delete', tips: i18n('删除'), class: 'text-danger',
                    click: (event, value, row) => {
                        message.ask(i18n('确认删除该标签？文章上的此标签会被移除。'), () => {
                            util.post(API('tag/del'), {list: [row.id]}, () => table.refresh());
                        }, i18n('确认删除标签'), i18n('确认删除'));
                    }
                }
            ]
        }
    ]);
    table.setSearch([
        {title: i18n('标签名称(模糊)'), name: 'search-name', type: 'input'}
    ]);
    table.setDeleteSelector('.btn-app-del', API('tag/del'));
    table.render();

    $('.btn-app-create').off(namespace).on('click' + namespace, () => modal());

    (window.AdminMobileRecipeQueue = window.AdminMobileRecipeQueue || []).push({
        id: 'blog-tags',
        title: i18n('博客标签'),
        pageType: 'list',
        match: {routes: ['/plugin/Blog/panel/tags'], queryUrls: ['/plugin/Blog/admin/tag/data']},
        primary: {field: 'name', label: i18n('标签名称')},
        metrics: [{field: 'post_count', label: i18n('文章数')}],
        details: [{field: 'slug', label: 'Slug'}, {field: 'create_time', label: i18n('创建时间')}],
        actions: {
            primary: [{id: 'operation:0', label: i18n('编辑')}],
            more: [{id: 'operation:1', label: i18n('前台预览')}, {id: 'operation:2', label: i18n('删除'), danger: true}],
            batch: [{selector: '.btn-app-del', label: i18n('删除选中'), role: 'batch', danger: true}],
            toolbar: [{selector: '.btn-app-create', label: i18n('新建标签'), role: 'primary'}]
        }
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-app-create, .btn-app-del').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__blogTagsDestroy === destroy) delete window.__blogTagsDestroy;
    }

    window.__blogTagsDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
