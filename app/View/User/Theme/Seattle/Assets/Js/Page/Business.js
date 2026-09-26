//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

!function () {
    'use strict';

    const $page = $('[data-st-page="business"]').first();
    if (!$page.length) return;

    let categoryTable = null;
    let commodityTable = null;
    let tablesInitialized = false;
    let editorApi = null;
    let selectedCategoryId;
    let selectedCategoryName = '';
    const mobileBusinessQuery = '(max-width: 767px), (max-height: 500px) and (max-width: 1024px)';
    const businessMedia = window.matchMedia(mobileBusinessQuery);
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isMobileBusiness = () => businessMedia.matches;

    const isPageCurrent = () => document.contains($page[0]);
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    const safeInlineHtml = value => window.SeattleTheme && typeof window.SeattleTheme.safeInlineHtml === 'function'
        ? window.SeattleTheme.safeInlineHtml(value)
        : escapeHtml(value);
    const plainText = value => window.SeattleTheme && typeof window.SeattleTheme.plainText === 'function'
        ? window.SeattleTheme.plainText(value)
        : String(value ?? '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
    const safeImageUrl = value => {
        try {
            const url = new URL(String(value || ''), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
        } catch (error) {
            return '';
        }
    };
    const imageCell = value => {
        const source = safeImageUrl(value);
        return source ? `<img class="render-image" src="${escapeHtml(source)}" alt="" loading="lazy">` : '-';
    };

    function writeHistory(url, patch, method = 'replace') {
        if (!window.history || !window.history[`${method}State`]) return window.history && window.history.state;
        const current = window.history.state && typeof window.history.state === 'object' ? window.history.state : {};
        const next = Object.assign({}, current);
        Object.entries(patch || {}).forEach(([key, value]) => {
            if (value === undefined || value === null || value === false) delete next[key];
            else next[key] = value;
        });
        const nextUrl = new URL(url || window.location.href, window.location.href).href;
        next.url = nextUrl;
        window.history[`${method}State`](next, document.title, nextUrl);
        if ($.pjax && $.pjax.state && (!current.id || $.pjax.state.id === current.id)) $.pjax.state = next;
        return next;
    }

    function businessTransientDepth() {
        const value = Number(window.history && window.history.state && window.history.state.stBusinessTransientDepth);
        return Number.isFinite(value) && value > 0 ? Math.floor(value) : 0;
    }

    function markConfigDirty() {
        const $form = $page.find('[data-st-business-config]').first();
        if (!$form.length) return;
        $form.addClass('is-dirty');
        $form.find('.st-business-save-context > small').text(i18n('有未保存更改'));
    }

    function setButtonBusy($button, busy, labelSelector, busyText, idleText) {
        $button.prop('disabled', busy).attr('aria-disabled', String(busy));
        if (labelSelector) $button.find(labelSelector).text(busy ? busyText : idleText);
    }

    function initPurchase() {
        const $groups = $page.find('.business-group');
        const $pay = $page.find('.payButton');
        if (!$pay.length) return;

        let selected = null;
        let purchasing = false;
        const idleLabel = $pay.find('.st-business-purchase-label').text();

        $groups.on('click', function () {
            selected = {
                id: Number($(this).data('id')),
                name: String($(this).data('name') || '').trim(),
                price: Number($(this).data('price'))
            };
            $groups.removeClass('checked').attr('aria-pressed', 'false');
            $(this).addClass('checked').attr('aria-pressed', 'true');
            $pay.prop('disabled', false).attr('aria-disabled', 'false');
        });

        $pay.on('click', function () {
            if (purchasing) return;
            if (!selected || !selected.id) {
                message.error(i18n('请先选择要开通的套餐'));
                return;
            }

            const price = Number.isFinite(selected.price) ? `${acgCurrencySymbol()}${selected.price.toLocaleString('zh-CN', {maximumFractionDigits: 2})}` : i18n('页面所示价格');
            message.ask(
                `${i18n('确认选择')} <strong>${escapeHtml(selected.name || i18n('当前套餐'))}</strong>，${i18n('并支付')} ${escapeHtml(price)}？`,
                () => {
                    purchasing = true;
                    setButtonBusy($pay, true, '.st-business-purchase-label', i18n('正在处理'), idleLabel);
                    util.post({
                        url: '/user/api/business/purchase',
                        data: {levelId: selected.id},
                        done: () => {
                            if (!isPageCurrent()) return;
                            message.success(i18n('开通成功'));
                            window.location.href = '/user/business/index';
                        },
                        error: res => {
                            if (!isPageCurrent()) return;
                            purchasing = false;
                            setButtonBusy($pay, false, '.st-business-purchase-label', i18n('正在处理'), idleLabel);
                            message.error(res && res.msg ? res.msg : i18n('套餐购买失败，请重试'));
                        },
                        fail: () => {
                            if (!isPageCurrent()) return;
                            purchasing = false;
                            setButtonBusy($pay, false, '.st-business-purchase-label', i18n('正在处理'), idleLabel);
                            message.error(i18n('网络连接失败，请稍后重试'));
                        }
                    });
                },
                i18n('确认店铺套餐'),
                i18n('确认支付')
            );
        });
    }

    function initNoticeEditor() {
        const $mount = $page.find('.notice-editor').first();
        if (!$mount.length) return null;
        if (!window.EditorV2 || !window.ace) {
            $mount.html('<div class="st-ticket-editor-error" role="alert">' + i18n('公告编辑器加载失败，请刷新页面后重试。') + '</div>');
            return null;
        }
        ['basePath', 'workerPath', 'modePath', 'themePath'].forEach(name => {
            ace.config.set(name, '/assets/common/js/editor/code/lib');
        });
        $mount.html(EditorV2.buildHtml({name: 'notice', placeholder: i18n('填写店铺公告，支持 Markdown 语法')}));
        return EditorV2.register($mount.get(0), {
            name: 'notice',
            uploadUrl: '/user/api/upload/send',
            height: isMobileBusiness() ? 280 : 420,
            value: getVar('_business_notice_var') ?? '',
            onChange: markConfigDirty
        });
    }

    function applyMobileProductDetail(active, focusBack) {
        const $panel = $page.find('[data-st-business-panel="product"]');
        const $detail = $panel.find('[data-st-business-product-detail]');
        const mobileActive = Boolean(active && isMobileBusiness());
        $panel.toggleClass('is-product-detail', mobileActive);
        $detail.attr('aria-hidden', String(isMobileBusiness() && !mobileActive));
        $panel.find('.st-business-product-index').attr('aria-hidden', String(mobileActive));
        if (mobileActive) $detail.find('#st-master-commodity-title').text(selectedCategoryName || i18n('主站商品'));
        if (!mobileActive && focusBack) {
            window.requestAnimationFrame(() => $panel.find('.st-business-product-index .st-section-header h2').get(0)?.focus?.({preventScroll: true}));
        }
    }

    function showMobileProductDetail(pushHistory = true) {
        if (!isMobileBusiness()) return;
        const $panel = $page.find('[data-st-business-panel="product"]');
        applyMobileProductDetail(true, false);
        if (pushHistory && !(window.history.state && window.history.state.stBusinessProduct)) {
            writeHistory(window.location.href, {
                stBusinessProduct: {id: Number(selectedCategoryId), name: selectedCategoryName || i18n('主站商品')},
                stBusinessSheet: null,
                stBusinessTransientDepth: businessTransientDepth() + 1
            }, 'push');
        }
        window.requestAnimationFrame(() => {
            const top = $page.find('.st-business-tabs').get(0)?.getBoundingClientRect().bottom || 0;
            const current = window.scrollY;
            const panelTop = $panel.get(0)?.getBoundingClientRect().top || 0;
            window.scrollTo({top: Math.max(0, current + panelTop - top - 8), behavior: reduceMotion ? 'auto' : 'smooth'});
        });
    }

    function hideMobileProductDetail(focusBack, useHistory = false) {
        if (useHistory && window.history.state && window.history.state.stBusinessProduct) {
            window.history.back();
            return;
        }
        applyMobileProductDetail(false, focusBack);
    }

    function categoryColumns() {
        return [
            {field: 'icon', title: '#', class: 'nowrap', width: 56, formatter: imageCell},
            {field: 'name', title: i18n('主站分类名称'), class: 'nowrap', width: 180, formatter: value => safeInlineHtml(value || '—')},
            {
                field: 'status', title: i18n('状态'), type: 'button', class: 'nowrap', width: 90, buttons: [
                    {
                        icon: 'fa-duotone fa-regular fa-eye', title: i18n('显示'), class: 'text-success',
                        show: item => !item.user_category || item.user_category.status == 1,
                        click: (event, value, row) => {
                            const values = row.user_category || {id: 0, category_id: row.id};
                            guardedPost($(event.currentTarget), {url: '/user/api/master/setCategoryStatus', data: values}, i18n('已生效'), () => categoryTable.refresh());
                        }
                    },
                    {
                        icon: 'fa-duotone fa-regular fa-eye-slash', title: i18n('隐藏'), class: 'text-danger',
                        show: item => item.user_category && item.user_category.status == 0,
                        click: (event, value, row) => {
                            const values = row.user_category || {id: 0, category_id: row.id};
                            guardedPost($(event.currentTarget), {url: '/user/api/master/setCategoryStatus', data: values}, i18n('已生效'), () => categoryTable.refresh());
                        }
                    }
                ]
            },
            {
                field: 'user_name', title: i18n('自定义名称'), class: 'nowrap', width: 180,
                formatter: (value, item) => item.user_category && item.user_category.name ? safeInlineHtml(item.user_category.name) : '-'
            },
            {
                field: 'operation', title: i18n('操作'), type: 'button', class: 'nowrap', width: 160, buttons: [
                    {
                        icon: 'fa-duotone fa-regular fa-eye', title: i18n('查看商品'), class: 'text-success',
                        click: (event, value, row) => {
                            selectedCategoryId = row.id;
                            selectedCategoryName = plainText(row.name || '');
                            commodityTable.reload({silent: false, pageNumber: 1, query: {category_id: row.id}});
                            showMobileProductDetail();
                            window.requestAnimationFrame(() => {
                                const anchor = $page.find('#commodity').get(0);
                                const title = $page.find('#st-master-commodity-title').get(0);
                                if (title) title.focus({preventScroll: true});
                                if (anchor && !isMobileBusiness()) anchor.scrollIntoView({behavior: 'smooth', block: 'start'});
                            });
                        }
                    },
                    {
                        icon: 'fa-duotone fa-regular fa-gear', title: i18n('设置'), class: 'text-primary',
                        click: (event, value, row) => {
                            const values = row.user_category || {category_id: row.id};
                            component.popup({
                                submit: '/user/api/master/setCategory',
                                tab: [{
                                    name: `${util.icon('fa-duotone fa-regular fa-gear')} ${escapeHtml(plainText(row.name || i18n('分类设置')))}`,
                                    form: [
                                        {title: 'cid', name: 'category_id', type: 'input', hide: true},
                                        {title: i18n('自定义名称'), name: 'name', type: 'textarea', height: 48, placeholder: i18n('不填写则使用主站分类名称')},
                                        {title: i18n('状态'), name: 'status', type: 'switch', text: i18n('显示|隐藏'), default: 1} // 首次设置还没有记录，默认显示，否则保存即隐藏(#922)
                                    ]
                                }],
                                assign: values,
                                autoPosition: true,
                                height: 'auto',
                                width: '480px',
                                done: () => categoryTable.refresh()
                            });
                        }
                    }
                ]
            }
        ];
    }

    function commodityColumns() {
        return [
            {field: 'cover', title: '#', class: 'nowrap', width: 56, formatter: imageCell},
            {field: 'name', title: i18n('商品名称'), class: 'nowrap', width: 180, formatter: value => safeInlineHtml(value || '—')},
            {field: 'user_price', title: i18n('会员价'), class: 'nowrap', width: 90, formatter: value => escapeHtml(value == null ? '—' : value)},
            {field: 'price', title: i18n('游客价'), class: 'nowrap', width: 90, formatter: value => escapeHtml(value == null ? '—' : value)},
            {
                field: 'status', title: i18n('状态'), class: 'nowrap', width: 90, type: 'button', buttons: [
                    {
                        icon: 'fa-duotone fa-regular fa-eye', title: i18n('显示'), class: 'text-success',
                        show: item => !item.user_commodity || item.user_commodity.status == 1,
                        click: (event, value, row) => {
                            const values = row.user_commodity || {id: 0, commodity_id: row.id};
                            guardedPost($(event.currentTarget), {url: '/user/api/master/setCommodityStatus', data: values}, i18n('已生效'), () => commodityTable.refresh());
                        }
                    },
                    {
                        icon: 'fa-duotone fa-regular fa-eye-slash', title: i18n('隐藏'), class: 'text-danger',
                        show: item => item.user_commodity && item.user_commodity.status == 0,
                        click: (event, value, row) => {
                            const values = row.user_commodity || {id: 0, commodity_id: row.id};
                            guardedPost($(event.currentTarget), {url: '/user/api/master/setCommodityStatus', data: values}, i18n('已生效'), () => commodityTable.refresh());
                        }
                    }
                ]
            },
            {
                field: 'user_name', title: i18n('自定义名称'), class: 'nowrap', width: 180,
                formatter: (value, item) => item.user_commodity && item.user_commodity.name ? safeInlineHtml(item.user_commodity.name) : '-'
            },
            {
                field: 'premium', title: i18n('加价百分比'), class: 'nowrap', width: 110,
                formatter: (value, item) => {
                    const premium = Number(item.user_commodity && item.user_commodity.premium);
                    return !Number.isFinite(premium) || premium === 0 ? '-' : format.badge(`${premium}%`, 'a-badge-success');
                }
            },
            {
                field: 'operation', title: i18n('操作'), type: 'button', class: 'nowrap', width: 100, buttons: [{
                    icon: 'fa-duotone fa-regular fa-gear', title: i18n('设置'), class: 'text-primary',
                    click: (event, value, row) => {
                        const values = row.user_commodity || {commodity_id: row.id};
                        component.popup({
                            submit: '/user/api/master/setCommodity',
                            tab: [{
                                name: `${util.icon('fa-duotone fa-regular fa-gear')} ${escapeHtml(plainText(row.name || i18n('商品设置')))}`,
                                form: [
                                    {title: 'cid', name: 'commodity_id', type: 'input', hide: true},
                                        {title: i18n('自定义名称'), name: 'name', type: 'textarea', height: 48, placeholder: i18n('不填写则使用主站商品名称')},
                                    {title: i18n('自定义商品介绍'), name: 'description', type: 'textarea', height: 160, placeholder: i18n('不填写则使用主站商品介绍，支持HTML'), tips: i18n('主站介绍里可能带有主站自己的广告或联系方式，可以在这里换成你自己的。')},
                                    {title: i18n('加价百分比'), name: 'premium', type: 'input', placeholder: i18n('例如 50'), tips: i18n('填写 50 表示在主站价格基础上加价 50%。')},
                                    {title: i18n('状态'), name: 'status', type: 'switch', text: i18n('显示|隐藏'), default: 1} // 首次设置还没有记录，默认显示，否则保存即隐藏(#922)
                                ]
                            }],
                            assign: values,
                            autoPosition: true,
                            height: 'auto',
                            width: '480px',
                            done: () => commodityTable.refresh()
                        });
                    }
                }]
            }
        ];
    }

    function ensureTables() {
        if (tablesInitialized || !$page.find('#master_category').length || !$page.find('#master_commodity').length) return;
        tablesInitialized = true;
        categoryTable = new Table('/user/api/master/category', '#master_category');
        categoryTable.setTree(1);
        categoryTable.disablePagination(); // 分类树整棵渲染，接口不分页(#922)
        categoryTable.setColumns(categoryColumns());
        categoryTable.render();

        commodityTable = new Table('/user/api/master/commodity', '#master_commodity');
        commodityTable.setColumns(commodityColumns());
        commodityTable.render();
    }

    function resetVisibleComponents(name) {
        window.requestAnimationFrame(() => {
            if (!isPageCurrent()) return;
            if (name === 'basic' && editorApi && editorApi.cm) editorApi.cm.refresh();
            if (name === 'product') {
                ensureTables();
                window.requestAnimationFrame(() => {
                    $page.find('#master_category, #master_commodity').each(function () {
                        try { $(this).bootstrapTable('resetView'); } catch (error) {}
                    });
                    window.dispatchEvent(new Event('resize'));
                });
            }
            if (window.layui && layui.form) layui.form.render();
        });
    }

    function initTabs() {
        const $tabs = $page.find('[data-st-business-tab]');
        const $panels = $page.find('[data-st-business-panel]');
        const $form = $page.find('[data-st-business-config]').first();
        const $saveDock = $form.find('[data-st-business-save-dock]').first();
        const labels = {basic: i18n('店铺信息'), product: i18n('商品设置'), domain: i18n('域名绑定')};
        if (!$tabs.length || !$panels.length) return;

        const scrollToScreenTop = () => {
            if (!isMobileBusiness()) return;
            const form = $form.get(0);
            const nav = $form.find('.st-business-tabs').get(0);
            if (!form || !nav) return;
            const topbar = Number.parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--st-topbar')) || 56;
            const formTop = window.scrollY + form.getBoundingClientRect().top;
            const target = Math.max(0, formTop + nav.offsetTop - topbar);
            if (Math.abs(window.scrollY - target) > 4) {
                window.scrollTo({top: target, behavior: reduceMotion ? 'auto' : 'smooth'});
            }
        };

        const activate = (name, options = {}) => {
            if (!$tabs.filter(`[data-st-business-tab="${name}"]`).length) name = 'basic';
            const previous = $form.attr('data-st-business-active') || 'basic';
            if (previous !== name) applyMobileProductDetail(false, false);
            $form.attr('data-st-business-active', name);
            $page.attr('data-st-business-active', name);
            $tabs.each(function () {
                const active = $(this).data('st-business-tab') === name;
                $(this).toggleClass('is-active', active).attr({'aria-selected': String(active), tabindex: active ? '0' : '-1'});
            });
            $panels.each(function () {
                const active = $(this).data('st-business-panel') === name;
                $(this).toggleClass('is-active', active).attr('aria-hidden', String(!active));
            });
            $form.find('[data-st-business-save-context]').text(labels[name]);
            const productDockHidden = isMobileBusiness() && name === 'product';
            $saveDock.attr('aria-hidden', String(productDockHidden));
            $saveDock.find('button').attr('tabindex', productDockHidden ? '-1' : '0');
            if (isMobileBusiness()) {
                const url = new URL(window.location.href);
                if (name === 'basic') url.searchParams.delete('tab');
                else url.searchParams.set('tab', name);
                if (options.updateUrl !== false) {
                    writeHistory(url.href, {
                        stBusinessTab: name,
                        stBusinessProduct: null,
                        stBusinessSheet: null,
                        stBusinessTransientDepth: 0
                    });
                }
            }
            resetVisibleComponents(name);
            if (options.scroll === true) window.requestAnimationFrame(scrollToScreenTop);
        };

        $tabs.on('click', function () { activate($(this).data('st-business-tab'), {scroll: true}); });
        $tabs.on('keydown', function (event) {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const items = $tabs.toArray();
            const current = items.indexOf(this);
            let next = current;
            if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = items.length - 1;
            else next = (current + (event.key === 'ArrowRight' ? 1 : -1) + items.length) % items.length;
            items[next].focus({preventScroll: true});
            activate($(items[next]).data('st-business-tab'), {scroll: true});
        });
        $tabs.on('focus', function () {
            if (!isMobileBusiness()) this.scrollIntoView({behavior: 'smooth', block: 'nearest', inline: 'nearest'});
        });
        $(window).off('popstate.seattleBusinessTabs').on('popstate.seattleBusinessTabs', () => {
            if (!isPageCurrent()) return;
            activate(util.getParam('tab') || 'basic', {updateUrl: false, scroll: false});
        });
        activate(util.getParam('tab') || 'basic', {updateUrl: false, scroll: false});
    }

    function initMobileBusinessActions() {
        const $sheet = $page.find('[data-st-business-action-sheet]').first();
        const $backdrop = $page.find('.st-business-action-backdrop').first();
        const $background = $page.find('.st-business-app-header, .st-business-level-card, .st-business-tabs, .st-business-save-shortcut, .st-business-app-screen');
        let returnFocus = null;
        let afterClose = null;
        let closing = false;
        let pendingRestoreFocus = null;
        let closeTimer = null;
        let pendingDesktopCollapse = false;

        const isSheetOpen = () => $sheet.length && $sheet.attr('aria-hidden') === 'false';
        const focusable = () => $sheet.find('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])').filter(':visible').toArray();

        const finishClose = restoreFocus => {
            if (!$sheet.length || closeTimer) return;
            if (!closing) {
                closing = true;
                pendingRestoreFocus = restoreFocus !== false;
            } else if (pendingRestoreFocus === null && restoreFocus !== undefined) {
                pendingRestoreFocus = Boolean(restoreFocus);
            }
            $sheet.removeClass('is-open').addClass('is-closing');
            $backdrop.removeClass('is-open');
            const callback = afterClose;
            afterClose = null;
            closeTimer = window.setTimeout(() => {
                closeTimer = null;
                closing = false;
                const shouldRestore = pendingRestoreFocus === true;
                pendingRestoreFocus = null;
                $background.prop('inert', false);
                const fallbackFocus = shouldRestore && returnFocus?.isConnected
                    ? returnFocus
                    : $page.find('[data-st-business-tab][aria-selected="true"]').get(0);
                if ($sheet.get(0)?.contains(document.activeElement)) {
                    if (fallbackFocus?.focus) fallbackFocus.focus({preventScroll: true});
                    else document.activeElement?.blur?.();
                } else if (shouldRestore && fallbackFocus?.focus) {
                    fallbackFocus.focus({preventScroll: true});
                }
                $sheet.removeClass('is-closing').attr('aria-hidden', 'true').prop('hidden', true);
                $backdrop.prop('hidden', true);
                $('body').removeClass('st-business-sheet-open');
                $page.find('[data-st-business-actions-open]').attr('aria-expanded', 'false');
                if (!isPageCurrent()) return;
                if (typeof callback === 'function') callback();
            }, reduceMotion ? 0 : 220);
        };

        const closeSheet = (restoreFocus, callback) => {
            if (!isSheetOpen() || closing) {
                if (!closing && typeof callback === 'function') callback();
                return;
            }
            afterClose = callback || null;
            pendingRestoreFocus = Boolean(restoreFocus);
            closing = true;
            if (window.history.state && window.history.state.stBusinessSheet) {
                window.history.back();
                return;
            }
            finishClose(restoreFocus);
        };

        const openSheet = (name, trigger, pushHistory = true) => {
            if (!isMobileBusiness() || !$sheet.length) return;
            if (isSheetOpen() || closing) return;
            returnFocus = trigger;
            const commodity = name === 'commodity';
            $sheet.find('[data-st-business-action-title]').text(commodity ? i18n('商品批量操作') : i18n('分类批量操作'));
            $sheet.find('[data-st-business-action-subtitle]').text(commodity && selectedCategoryName ? `${i18n('当前分类：')}${selectedCategoryName}` : i18n('选择要执行的操作'));
            $sheet.find('[data-st-business-action-options]').each(function () {
                const active = $(this).data('st-business-action-options') === name;
                $(this).prop('hidden', !active);
            });
            $sheet.prop('hidden', false).attr('aria-hidden', 'false');
            $backdrop.prop('hidden', false);
            $background.prop('inert', true);
            $(trigger).attr('aria-expanded', 'true');
            $('body').addClass('st-business-sheet-open');
            if (pushHistory) {
                writeHistory(window.location.href, {
                    stBusinessSheet: name,
                    stBusinessTransientDepth: businessTransientDepth() + 1
                }, 'push');
            }
            window.requestAnimationFrame(() => {
                $sheet.addClass('is-open');
                $backdrop.addClass('is-open');
                $sheet.find('[data-st-business-action-options]:not([hidden]) button').first().trigger('focus');
            });
        };

        $page.find('[data-st-business-actions-open]').on('click', function () {
            openSheet($(this).data('st-business-actions-open'), this);
        });
        $page.find('[data-st-business-actions-close]').on('click', () => closeSheet(true));
        $page.find('[data-st-business-product-back]').on('click', () => hideMobileProductDetail(true, true));
        $page.find('[data-st-business-proxy]').on('click', function () {
            const type = $(this).data('st-business-proxy');
            const state = $(this).data('state');
            closeSheet(false, () => {
                if (!isPageCurrent()) return;
                if (type === 'category') $page.find(`.category-show[data-state="${state}"]`).first().trigger('click');
                if (type === 'commodity') $page.find(`.commodity-show[data-state="${state}"]`).first().trigger('click');
                if (type === 'premium') $page.find('.commodity-premium').first().trigger('click');
            });
        });
        $sheet.on('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeSheet(true);
                return;
            }
            if (event.key !== 'Tab') return;
            const items = focusable();
            if (!items.length) {
                event.preventDefault();
                $sheet.trigger('focus');
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        const syncHistory = () => {
            if (!isPageCurrent()) return;
            const state = window.history.state && typeof window.history.state === 'object' ? window.history.state : {};
            const wasProductDetail = $page.find('#st-business-panel-product').hasClass('is-product-detail');
            if (!isMobileBusiness()) {
                pendingDesktopCollapse = false;
                if (isSheetOpen() || closing) finishClose(false);
                applyMobileProductDetail(false, false);
                if (state.stBusinessSheet || state.stBusinessProduct || state.stBusinessTransientDepth) {
                    writeHistory(window.location.href, {
                        stBusinessSheet: null,
                        stBusinessProduct: null,
                        stBusinessTransientDepth: 0
                    });
                }
                return;
            }
            const detail = state.stBusinessProduct;
            if (detail && $page.attr('data-st-business-active') === 'product') {
                const nextId = Number(detail.id);
                const changed = nextId && nextId !== Number(selectedCategoryId);
                selectedCategoryId = nextId || selectedCategoryId;
                selectedCategoryName = plainText(detail.name || selectedCategoryName || i18n('主站商品'));
                if (changed) {
                    ensureTables();
                    commodityTable && commodityTable.reload({silent: false, pageNumber: 1, query: {category_id: selectedCategoryId}});
                }
                applyMobileProductDetail(true, false);
            } else {
                applyMobileProductDetail(false, wasProductDetail);
            }

            if (state.stBusinessSheet) {
                const trigger = $page.find(`[data-st-business-actions-open="${state.stBusinessSheet}"]`).filter(':visible').get(0);
                if (!isSheetOpen() && trigger) openSheet(state.stBusinessSheet, trigger, false);
            } else if (isSheetOpen()) {
                finishClose();
            }
        };

        const cleanup = () => {
            if (closeTimer) window.clearTimeout(closeTimer);
            closeTimer = null;
            closing = false;
            pendingRestoreFocus = null;
            pendingDesktopCollapse = false;
            afterClose = null;
            $sheet.removeClass('is-open is-closing').attr('aria-hidden', 'true').prop('hidden', true);
            $backdrop.removeClass('is-open').prop('hidden', true);
            $background.prop('inert', false);
            $page.find('[data-st-business-actions-open]').attr('aria-expanded', 'false');
            $('body').removeClass('st-business-sheet-open');
            if (editorApi && typeof editorApi.destroy === 'function') editorApi.destroy();
            editorApi = null;
            $(window).off('popstate.seattleBusinessActions popstate.seattleBusinessTabs pagehide.seattleBusinessActions');
            $(document).off('pjax:beforeReplace.seattleBusinessActions');
            if (businessMedia.removeEventListener) businessMedia.removeEventListener('change', handleMediaChange);
            else businessMedia.removeListener(handleMediaChange);
        };

        const handleMediaChange = () => {
            if (!isPageCurrent()) return;
            if (!isMobileBusiness()) {
                if (isSheetOpen()) finishClose(false);
                applyMobileProductDetail(false, false);
                $page.find('[data-st-business-save-dock]').attr('aria-hidden', 'false').find('button').attr('tabindex', '0');
                const depth = businessTransientDepth();
                if (depth > 0 && !pendingDesktopCollapse) {
                    pendingDesktopCollapse = true;
                    window.history.go(-depth);
                } else if (!pendingDesktopCollapse) {
                    writeHistory(window.location.href, {
                        stBusinessSheet: null,
                        stBusinessProduct: null,
                        stBusinessTransientDepth: 0
                    });
                }
            } else {
                const active = $page.attr('data-st-business-active') || 'basic';
                const hideDock = active === 'product';
                $page.find('[data-st-business-save-dock]').attr('aria-hidden', String(hideDock)).find('button').attr('tabindex', hideDock ? '-1' : '0');
                syncHistory();
            }
        };

        $('body').removeClass('st-business-sheet-open');
        $(window).off('popstate.seattleBusinessActions pagehide.seattleBusinessActions')
            .on('popstate.seattleBusinessActions', syncHistory)
            .on('pagehide.seattleBusinessActions', cleanup);
        $(document).off('pjax:beforeReplace.seattleBusinessActions').one('pjax:beforeReplace.seattleBusinessActions', cleanup);
        if (businessMedia.addEventListener) businessMedia.addEventListener('change', handleMediaChange);
        else businessMedia.addListener(handleMediaChange);
        const initialState = window.history.state && typeof window.history.state === 'object' ? window.history.state : {};
        if (!Number.isFinite(Number(initialState.stBusinessTransientDepth))) {
            writeHistory(window.location.href, {
                stBusinessTransientDepth: (initialState.stBusinessProduct ? 1 : 0) + (initialState.stBusinessSheet ? 1 : 0)
            });
        }
        syncHistory();
    }

    function guardedPost($button, request, successText, onSuccess) {
        if ($button.data('st-busy')) return;
        $button.data('st-busy', true).prop('disabled', true).attr('aria-disabled', 'true');
        const finish = () => $button.removeData('st-busy').prop('disabled', false).attr('aria-disabled', 'false');
        util.post({
            url: request.url,
            data: request.data,
            done: res => {
                if (!isPageCurrent()) return;
                finish();
                if (successText) message.success(successText);
                if (onSuccess) onSuccess(res);
            },
            error: res => {
                if (!isPageCurrent()) return;
                finish();
                message.error(res && res.msg ? res.msg : i18n('操作失败，请重试'));
            },
            fail: () => {
                if (!isPageCurrent()) return;
                finish();
                message.error(i18n('网络连接失败，请稍后重试'));
            }
        });
    }

    function initConfigActions() {
        const $form = $page.find('[data-st-business-config]').first();
        if (!$form.length) return;

        const $save = $form.find('.save-config');
        const idleSaveLabel = $save.find('.st-business-save-label').first().text();
        let saving = false;

        $form.on(
            'input change',
            '[data-st-business-panel="basic"] input, [data-st-business-panel="basic"] select, [data-st-business-panel="basic"] textarea, [data-st-business-panel="domain"] input, [data-st-business-panel="domain"] select, [data-st-business-panel="domain"] textarea',
            markConfigDirty
        );

        $save.on('click', function () {
            if (saving) return;
            const shopName = String($form.find('[name="shop_name"]').val() || '').trim();
            const title = String($form.find('[name="title"]').val() || '').trim();
            const serviceQq = String($form.find('[name="service_qq"]').val() || '').trim();
            const serviceUrl = String($form.find('[name="service_url"]').val() || '').trim();
            if (!shopName || !title) {
                message.error(!shopName ? i18n('请输入店铺名称') : i18n('请输入网站标题'));
                $page.find('[data-st-business-tab="basic"]').trigger('click');
                $form.find(!shopName ? '[name="shop_name"]' : '[name="title"]').trigger('focus');
                return;
            }
            if (serviceUrl && !/^https?:\/\//i.test(serviceUrl)) {
                message.error(i18n('客服链接需要以 http:// 或 https:// 开头'));
                $page.find('[data-st-business-tab="basic"]').trigger('click');
                $form.find('[name="service_url"]').trigger('focus');
                return;
            }
            if (serviceQq && !/^[1-9]\d{4,11}$/.test(serviceQq)) {
                message.error(i18n('请输入 5 至 12 位有效客服 QQ'));
                $page.find('[data-st-business-tab="basic"]').trigger('click');
                $form.find('[name="service_qq"]').trigger('focus');
                return;
            }

            const data = util.getFormData($form[0]);
            data.shop_name = shopName;
            data.title = title;
            data.service_qq = serviceQq;
            data.service_url = serviceUrl;
            data.master_display = $form.find('[name="master_display"]').prop('checked') ? 1 : 0;
            data.notice = editorApi && typeof editorApi.getHTML === 'function'
                ? editorApi.getHTML()
                : (getVar('_business_notice_var') ?? '');

            saving = true;
            setButtonBusy($save, true, '.st-business-save-label', i18n('正在保存'), idleSaveLabel);
            util.post({
                url: '/user/api/business/saveConfig',
                data: data,
                done: () => {
                    if (!isPageCurrent()) return;
                    saving = false;
                    setButtonBusy($save, false, '.st-business-save-label', i18n('正在保存'), idleSaveLabel);
                    $form.removeClass('is-dirty');
                    $form.find('.st-business-save-context > small').text(i18n('当前设置'));
                    message.success(i18n('保存成功'));
                },
                error: res => {
                    if (!isPageCurrent()) return;
                    saving = false;
                    setButtonBusy($save, false, '.st-business-save-label', i18n('正在保存'), idleSaveLabel);
                    message.error(res && res.msg ? res.msg : i18n('保存失败，请重试'));
                },
                fail: () => {
                    if (!isPageCurrent()) return;
                    saving = false;
                    setButtonBusy($save, false, '.st-business-save-label', i18n('正在保存'), idleSaveLabel);
                    message.error(i18n('网络连接失败，请稍后重试'));
                }
            });
        });

        $page.find('.clipboard').on('click', function () {
            util.copyTextToClipboard($(this).data('text'), () => message.success(i18n('已复制 CNAME 地址')), () => message.error(i18n('复制失败，请手动选择地址')));
        });

        const bindUnlink = (selector, type, label) => {
            $page.find(selector).on('click', function () {
                const $button = $(this);
                if ($button.data('st-busy')) return;
                message.ask(
                    `${i18n('确认解绑')}${label}？${i18n('解绑后，用户将立即无法通过旧地址访问店铺。')}`,
                    () => guardedPost($button, {url: '/user/api/business/unbind', data: {type: type}}, i18n('解绑成功'), () => window.setTimeout(() => window.location.reload(), 500)),
                    `${i18n('解绑')}${label}`,
                    i18n('确认解绑')
                );
            });
        };
        bindUnlink('.unbind-subdomain', 0, i18n('当前子域名'));
        bindUnlink('.unbind-topdomain', 1, i18n('当前独立域名'));

        $page.find('.category-show').on('click', function () {
            const $button = $(this);
            const status = Number($button.data('state'));
            message.ask(`${i18n('确认将全部主站分类设为')}${status === 1 ? i18n('显示') : i18n('隐藏')}？`, () => {
                guardedPost($button, {url: '/user/api/master/setCategoryAllStatus', data: {status: status}}, i18n('已生效'), () => categoryTable && categoryTable.refresh());
            }, i18n('批量修改分类'), i18n('确认修改'));
        });

        $page.find('.commodity-show').on('click', function () {
            const $button = $(this);
            const status = Number($button.data('state'));
            const scope = selectedCategoryName ? `${i18n('分类')}“${selectedCategoryName}”${i18n('中的')}` : i18n('全部');
            message.ask(`${i18n('确认将')}${escapeHtml(scope)}${i18n('主站商品设为')}${status === 1 ? i18n('显示') : i18n('隐藏')}？`, () => {
                guardedPost($button, {url: '/user/api/master/setCommodityAllStatus', data: {status: status, category_id: selectedCategoryId}}, i18n('已生效'), () => commodityTable && commodityTable.refresh());
            }, i18n('批量修改商品'), i18n('确认修改'));
        });

        $page.find('.commodity-premium').on('click', function () {
            component.popup({
                submit: '/user/api/master/setCommodityAllPremium',
                tab: [{
                    name: selectedCategoryName ? `${i18n('仅分类：')}${escapeHtml(selectedCategoryName)}` : i18n('全部商品'),
                    form: [
                        {title: 'cid', name: 'category_id', type: 'input', hide: true},
                        {title: i18n('加价百分比'), name: 'premium', type: 'input', placeholder: i18n('例如 50'), tips: i18n('填写 50 表示在主站价格基础上加价 50%。')}
                    ]
                }],
                assign: {category_id: selectedCategoryId},
                autoPosition: true,
                height: 'auto',
                width: '480px',
                done: () => commodityTable && commodityTable.refresh()
            });
        });
    }

    initPurchase();
    if ($page.attr('data-st-business-state') === 'config') {
        editorApi = initNoticeEditor();
        initTabs();
        initConfigActions();
        initMobileBusinessActions();
    }
}();
