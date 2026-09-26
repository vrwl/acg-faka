!function () {
    /**
     * 次元博客 · 分类管理（树序展平列表，缩进显示层级；量级小，关闭分页）
     */
    let table;
    const namespace = '.blogCategoriesController';
    let controllerActive = true;
    if (typeof window.__blogCategoriesDestroy === 'function') window.__blogCategoriesDestroy();

    const API = (p) => '/plugin/Blog/admin/' + p;
    //表格 formatter 返回的是 HTML 字符串，任何入库数据插进去前都得转义
    const esc = (s) => $('<i></i>').text(s == null ? '' : String(s)).html();

    //弹窗（新建/编辑共用）：父分类选项每次现取，排除自己
    const modal = (row = null) => {
        util.post({
            url: API('category/data'),
            done: res => {
                const dict = [{id: 0, name: i18n('（顶级分类）')}];
                (res.data.list || []).forEach(c => {
                    if (row && c.id === row.id) return;
                    dict.push({id: c.id, name: '　'.repeat(c.depth || 0) + c.name});
                });
                component.popup({
                    submit: API('category/save'),
                    tab: [{
                        name: util.icon('blogi blogi-folder') + ' ' + (row ? i18n('编辑分类') : i18n('新建分类')),
                        form: [
                            {title: i18n('分类名称'), name: 'name', type: 'input', required: true, placeholder: i18n('如：Linux 教程')},
                            {title: 'Slug', name: 'slug', type: 'input', placeholder: i18n('URL 别名，留空自动生成'), tips: i18n('用于前台地址 /blog/category/{slug}，建议小写字母与连字符')},
                            {title: i18n('父分类'), name: 'parent_id', type: 'select', dict: dict, default: 0},
                            {title: i18n('描述'), name: 'description', type: 'textarea', height: 70, placeholder: i18n('显示在分类页头部（可留空）')},
                            {title: i18n('封面图'), name: 'cover', type: 'image', photoAlbumUrl: '/admin/api/upload/get', uploadUrl: '/admin/api/upload/send'},
                            {title: i18n('排序'), name: 'weight', type: 'number', default: 0, tips: i18n('同级从小到大排列')}
                        ]
                    }],
                    assign: row || {},
                    width: '560px',
                    done: () => table.refresh()
                });
            }
        });
    };

    table = new Table(API('category/data'), '#blog-categories-table');
    table.disablePagination();
    table.setColumns([
        {
            field: 'name', title: i18n('分类名称'),
            formatter: (value, row) => {
                const indent = '<span style="display:inline-block;width:' + (row.depth || 0) * 22 + 'px"></span>';
                const branch = row.depth > 0 ? '<span class="text-muted">└─ </span>' : '';
                return `${indent}${branch}<span class="fw-bolder">${esc(value)}</span>`;
            }
        },
        {field: 'slug', title: 'Slug', formatter: v => `<span class="text-muted">/${esc(v)}</span>`},
        {field: 'post_count', title: i18n('文章数'), width: 70},
        {field: 'weight', title: i18n('排序'), width: 60},
        {field: 'create_time', title: i18n('创建时间'), width: 140},
        {
            field: 'operation', title: i18n('操作'), type: 'button', buttons: [
                {
                    icon: 'blogi blogi-edit', title: i18n('编辑'), class: 'text-primary',
                    click: (event, value, row) => modal(row)
                },
                {
                    icon: 'blogi blogi-open', tips: i18n('前台预览'),
                    click: (event, value, row) => window.open('/plugin/Blog/category/detail?slug=' + encodeURIComponent(row.slug))
                },
                {
                    icon: 'blogi blogi-delete', tips: i18n('删除'), class: 'text-danger',
                    click: (event, value, row) => {
                        message.ask(
                            i18n('确认删除该分类？其下文章将归入「未分类」。'),
                            () => util.post(API('category/del'), {id: row.id}, () => table.refresh()),
                            i18n('确认删除分类'), i18n('确认删除')
                        );
                    }
                }
            ]
        }
    ]);
    table.render();

    $('.btn-app-create').off(namespace).on('click' + namespace, () => modal());

    (window.AdminMobileRecipeQueue = window.AdminMobileRecipeQueue || []).push({
        id: 'blog-categories',
        title: i18n('博客分类'),
        pageType: 'list',
        match: {routes: ['/plugin/Blog/panel/categories'], queryUrls: ['/plugin/Blog/admin/category/data']},
        primary: {field: 'name', label: i18n('分类名称')},
        metrics: [{field: 'post_count', label: i18n('文章数')}, {field: 'weight', label: i18n('排序')}],
        details: [{field: 'slug', label: 'Slug'}, {field: 'create_time', label: i18n('创建时间')}],
        selection: false,
        actions: {
            primary: [{id: 'operation:0', label: i18n('编辑')}],
            more: [{id: 'operation:1', label: i18n('前台预览')}, {id: 'operation:2', label: i18n('删除'), danger: true}],
            toolbar: [{selector: '.btn-app-create', label: i18n('新建分类'), role: 'primary'}]
        }
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-app-create').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__blogCategoriesDestroy === destroy) delete window.__blogCategoriesDestroy;
    }

    window.__blogCategoriesDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
