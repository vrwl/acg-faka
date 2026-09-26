!function () {
    /**
     * 次元博客 · 设置页
     * 脏值追踪 + 撤销 + Ctrl/Cmd+S 保存 + 侧栏滚动定位；维护动作分批循环。
     */
    const namespace = '.blogSettingsController';
    let controllerActive = true;
    if (typeof window.__blogSettingsDestroy === 'function') window.__blogSettingsDestroy();

    const API = (p) => '/plugin/Blog/admin/' + p;
    const $root = () => $('.blog-admin');
    let saved = {};   //服务端最后已知值，用于脏值比对与撤销

    const readForm = () => {
        const data = {};
        $root().find('[data-blog-setting]').each(function () {
            const $el = $(this);
            const key = $el.attr('name');
            data[key] = $el.is(':checkbox') ? ($el.prop('checked') ? '1' : '0') : String($el.val() ?? '');
        });
        return data;
    };

    const fillForm = (values) => {
        $root().find('[data-blog-setting]').each(function () {
            const $el = $(this);
            const key = $el.attr('name');
            if (!(key in values)) return;
            if ($el.is(':checkbox')) $el.prop('checked', String(values[key]) === '1');
            else $el.val(values[key]);
        });
    };

    const isDirty = () => {
        const now = readForm();
        return Object.keys(now).some(k => String(now[k]) !== String(saved[k] ?? ''));
    };

    const refreshState = () => {
        const dirty = isDirty();
        const $state = $('[data-blog-save-state]');
        $state.toggleClass('is-dirty', dirty)
            .text(dirty ? i18n('有未保存的更改') : i18n('所有更改已保存'));
        $('.blog-reset-settings').prop('disabled', !dirty);
        $('.blog-save-settings').prop('disabled', !dirty);
    };

    //回填
    util.post({
        url: API('setting/get'),
        done: res => {
            saved = res.data.values || {};
            fillForm(saved);
            refreshState();
        }
    });

    $(document).off('input' + namespace + ' change' + namespace, '[data-blog-setting]')
        .on('input' + namespace + ' change' + namespace, '[data-blog-setting]', refreshState);

    //保存
    const save = () => {
        if (!isDirty()) return;
        const data = readForm();
        util.post({
            url: API('setting/save'),
            data: data,
            done: () => {
                saved = data;
                refreshState();
                message.success(i18n('设置已保存'));
            }
        });
    };

    $(document).off('click' + namespace, '.blog-save-settings').on('click' + namespace, '.blog-save-settings', save);

    $(document).off('click' + namespace, '.blog-reset-settings').on('click' + namespace, '.blog-reset-settings', () => {
        fillForm(saved);
        refreshState();
        layer.msg(i18n('已撤销未保存的更改'));
    });

    //Ctrl/Cmd + S
    const onKeydown = (e) => {
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            save();
        }
    };
    document.addEventListener('keydown', onKeydown);

    //离开前提醒
    const beforeUnload = (e) => {
        if (!isDirty()) return;
        e.preventDefault();
        e.returnValue = '';
    };
    window.addEventListener('beforeunload', beforeUnload);

    //侧栏定位 + 滚动高亮
    $(document).off('click' + namespace, '[data-blog-sidenav] a').on('click' + namespace, '[data-blog-sidenav] a', function (e) {
        e.preventDefault();
        const target = document.querySelector($(this).attr('href'));
        if (target) target.scrollIntoView({behavior: 'smooth', block: 'start'});
    });

    let spy = null;
    if ('IntersectionObserver' in window) {
        spy = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const id = entry.target.id;
                $('[data-blog-sidenav] a').removeClass('is-active')
                    .filter('[href="#' + id + '"]').addClass('is-active');
            });
        }, {rootMargin: '-90px 0px -70% 0px'});
        $root().find('.ba-card[id]').each(function () { spy.observe(this); });
    }

    //维护：重建渲染缓存（分批循环直到 remaining=0）
    $(document).off('click' + namespace, '.blog-rebuild-render').on('click' + namespace, '.blog-rebuild-render', function () {
        const $btn = $(this).prop('disabled', true);
        const $status = $('[data-blog-maintain-status]');
        let total = 0;
        const step = () => {
            util.post({
                url: API('setting/rebuildRender'),
                loader: false,
                done: res => {
                    total += Number(res.data.done || 0);
                    const remaining = Number(res.data.remaining || 0);
                    $status.text(i18n('已重建') + ' ' + total + ' · ' + i18n('剩余') + ' ' + remaining);
                    if (remaining > 0 && controllerActive) {
                        setTimeout(step, 120);
                    } else {
                        $btn.prop('disabled', false);
                        message.success(i18n('渲染缓存重建完成'));
                    }
                },
                error: () => $btn.prop('disabled', false)
            });
        };
        step();
    });

    //维护：重算计数
    $(document).off('click' + namespace, '.blog-recalc-counts').on('click' + namespace, '.blog-recalc-counts', function () {
        const $btn = $(this).prop('disabled', true);
        util.post({
            url: API('setting/recalcCounts'),
            done: res => {
                $btn.prop('disabled', false);
                const d = res.data || {};
                $('[data-blog-maintain-status]').text(
                    (d.categories || 0) + ' ' + i18n('分类') + ' · ' + (d.tags || 0) + ' ' + i18n('标签') + ' · ' + (d.posts || 0) + ' ' + i18n('文章')
                );
                message.success(i18n('计数已重算'));
            },
            error: () => $btn.prop('disabled', false)
        });
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        document.removeEventListener('keydown', onKeydown);
        window.removeEventListener('beforeunload', beforeUnload);
        if (spy) spy.disconnect();
        $(document).off(namespace);
        if (window.__blogSettingsDestroy === destroy) delete window.__blogSettingsDestroy;
    }

    window.__blogSettingsDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
