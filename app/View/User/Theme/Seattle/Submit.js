(() => {
    const setting = values && values.setting ? values.setting : {};
    const targets = [
        {id: 'store', name: i18n('商城首页')},
        {id: 'query', name: i18n('订单查询')},
        {id: 'dashboard', name: i18n('会员中心')},
        {id: 'purchase', name: i18n('购买记录')},
        {id: 'recharge', name: i18n('充值中心')},
        {id: 'bill', name: i18n('我的账单')},
        {id: 'business', name: i18n('我的店铺')},
        {id: 'promote', name: i18n('推广中心')},
        {id: 'member', name: i18n('我的下级')},
        {id: 'login', name: i18n('会员登录')},
        {id: 'register', name: i18n('会员注册')}
    ];
    const preferredIcons = [
        'receipt_long',
        'person',
        'home',
        'login',
        'how_to_reg',
        'shopping_bag',
        'account_balance_wallet',
        'payments',
        'storefront',
        'campaign',
        'groups',
        'support_agent'
    ];
    const iconAliases = {
        receipt_long: i18n('订单 发票 查询'),
        person: i18n('会员 用户 个人'),
        home: i18n('首页 主页'),
        login: i18n('登录 进入'),
        how_to_reg: i18n('注册 加入'),
        shopping_bag: i18n('商品 购物袋'),
        account_balance_wallet: i18n('钱包 余额'),
        payments: i18n('支付 账单 付款'),
        storefront: i18n('店铺 商城'),
        campaign: i18n('推广 公告 营销'),
        groups: i18n('成员 下级 团队'),
        support_agent: i18n('客服 工单 帮助')
    };
    const rawIconCatalog = values && values.info ? String(values.info.MATERIAL_ICON_NAMES || '') : '';
    const catalogIcons = rawIconCatalog.split('|').filter(icon => /^[a-z0-9_]{1,64}$/.test(icon));
    const sourceIcons = catalogIcons.length ? catalogIcons : preferredIcons;
    const catalogSet = new Set(sourceIcons);
    const icons = [
        ...preferredIcons.filter(icon => catalogSet.has(icon)),
        ...sourceIcons.filter(icon => !preferredIcons.includes(icon))
    ];
    const audiences = [
        {id: 'all', name: i18n('所有人')},
        {id: 'member', name: i18n('仅登录会员')},
        {id: 'guest', name: i18n('仅访客')}
    ];
    const sites = [
        {id: 'all', name: i18n('全部站点')},
        {id: 'master', name: i18n('仅主站')},
        {id: 'branch', name: i18n('仅分站')}
    ];
    const linkTypes = [
        {id: 'internal', name: i18n('站内页面')},
        {id: 'external', name: i18n('外部链接')}
    ];
    const targetIds = new Set(targets.map(item => item.id));
    const iconIds = new Set(icons);
    const audienceIds = new Set(audiences.map(item => item.id));
    const siteIds = new Set(sites.map(item => item.id));
    const linkTypeIds = new Set(linkTypes.map(item => item.id));

    const legacyMenus = [
        {
            name: setting.quick_primary_name || i18n('查询订单'),
            icon: setting.quick_primary_icon || 'receipt_long',
            audience: 'all',
            link_type: 'internal',
            target: setting.quick_primary_target || 'query',
            url: ''
        },
        {
            name: setting.quick_member_name || i18n('会员中心'),
            icon: setting.quick_member_icon || 'person',
            audience: 'member',
            link_type: 'internal',
            target: setting.quick_member_target || 'dashboard',
            url: ''
        },
        {
            name: setting.quick_guest_name || i18n('会员登录'),
            icon: setting.quick_guest_icon || 'login',
            audience: 'guest',
            link_type: 'internal',
            target: setting.quick_guest_target || 'login',
            url: ''
        }
    ];
    const menuDefault = Object.prototype.hasOwnProperty.call(setting, 'quick_menu')
        ? (Array.isArray(setting.quick_menu) ? JSON.stringify(setting.quick_menu) : String(setting.quick_menu || '[]'))
        : JSON.stringify(legacyMenus);

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[character]);

    const normalizeMenu = value => {
        const source = value && typeof value === 'object' ? value : {};
        const linkType = linkTypeIds.has(String(source.link_type || '')) ? String(source.link_type) : 'internal';
        const target = linkType === 'internal'
            ? (targetIds.has(String(source.target || '')) ? String(source.target) : 'store')
            : '';
        const icon = iconIds.has(String(source.icon || '')) ? String(source.icon) : 'receipt_long';
        const audience = audienceIds.has(String(source.audience || '')) ? String(source.audience) : 'all';
        const site = siteIds.has(String(source.site || '')) ? String(source.site) : 'all';

        return {
            name: String(source.name || '').slice(0, 32),
            icon,
            audience,
            site,
            link_type: linkType,
            target,
            url: linkType === 'external' ? String(source.url || '').slice(0, 2048) : ''
        };
    };

    const parseMenus = value => {
        try {
            let source = String(value ?? '').trim();
            if (/^%5B/i.test(source)) {
                source = decodeURIComponent(source);
            }
            const parsed = JSON.parse(source || '[]');
            if (!Array.isArray(parsed)) {
                throw new TypeError('quick_menu must be an array');
            }
            let recovered = parsed.length > 12;
            const menus = parsed.slice(0, 12).map(item => {
                const normalized = normalizeMenu(item);
                const sourceItem = item && typeof item === 'object' && !Array.isArray(item) ? item : null;
                const changed = !sourceItem
                    || typeof sourceItem.name !== 'string'
                    || sourceItem.name !== normalized.name
                    || String(sourceItem.icon || '') !== normalized.icon
                    || String(sourceItem.audience || '') !== normalized.audience
                    || String(sourceItem.site ?? 'all') !== normalized.site
                    || String(sourceItem.link_type || '') !== normalized.link_type
                    || (normalized.link_type === 'internal' && String(sourceItem.target || '') !== normalized.target)
                    || (normalized.link_type === 'external' && (
                        typeof sourceItem.url !== 'string'
                        || sourceItem.url !== normalized.url
                    ));
                recovered = recovered || changed;
                return normalized;
            });
            return {menus, recovered};
        } catch (error) {
            return {menus: legacyMenus.map(normalizeMenu), recovered: true};
        }
    };

    //分类栏宽度：id 必须和 Assets/Css 里的 .st-store-layout[data-st-rail="..."] 对齐
    const categoryWidths = [
        {id: 'standard', name: i18n('标准')},
        {id: 'wide', name: i18n('加宽')},
        {id: 'wider', name: i18n('最宽')}
    ];

    //快捷入口关掉后，标题/说明/菜单编辑器都没有意义，直接收起来少点干扰
    const quickFields = ['quick_title', 'quick_subtitle', 'quick_menu'];
    const toggleQuickFields = (builder, enabled) => {
        quickFields.forEach(name => enabled ? builder.show(name) : builder.hide(name));
    };

    const optionHtml = (items, selected) => items.map(item =>
        `<option value="${escapeHtml(item.id)}"${item.id === selected ? ' selected' : ''}>${escapeHtml(item.name)}</option>`
    ).join('');

    const menuBuilder = (builder, instance) => {
        const unique = `seattle-menu-${builder.getUnique()}`;
        const initialState = parseMenus(builder.form.quick_menu.default ?? menuDefault);
        const initialMenus = initialState.menus;
        instance.html(
            `<style>` +
                `.${unique}{display:grid;gap:12px;width:100%;}` +
                `.${unique} .seattle-menu-intro{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 16px;border:1px solid var(--md-divider,#e5e7eb);border-radius:var(--md-radius-lg,12px);background:var(--md-surface-2,#f8fafc);}` +
                `.${unique} .seattle-menu-intro>div{display:grid;min-width:0;gap:3px;}` +
                `.${unique} .seattle-menu-intro strong{color:var(--md-on-surface,#111827);font-size:14px;line-height:1.4;}` +
                `.${unique} .seattle-menu-intro span{color:var(--md-on-surface-med,#64748b);font-size:12px;line-height:1.5;}` +
                `.${unique} .seattle-menu-add{flex:0 0 auto;margin:0;white-space:nowrap;}` +
                `.${unique} .seattle-menu-add:disabled{border-color:var(--md-divider,#e5e7eb);background:var(--md-surface-2,#f8fafc);color:var(--md-on-surface-dis,#94a3b8);cursor:not-allowed;opacity:.72;}` +
                `.${unique} .seattle-menu-add:disabled:hover{border-color:var(--md-divider,#e5e7eb);background:var(--md-surface-2,#f8fafc);}` +
                `.${unique} .seattle-menu-list{display:grid;gap:12px;}` +
                //入场动画只做透明度淡入，绝对不能带 transform：卡片一旦有非 none 的 transform
                //（无论是 forwards 保留的 translateY(0)，还是卡片在弹层未显示时动画已耗尽、
                //backwards 残留的 translateY(-4px)），都会让它成为绝对定位后代的包含块，
                //卡片内 layui 下拉框(dl 绝对定位)的定位基准随之错乱、菜单被甩到视口外，
                //表现就是"跳转方式/显示范围等下拉点了不展开"。opacity 不产生包含块，最稳。
                `.${unique} .seattle-menu-card{display:block!important;margin:0!important;padding:14px 16px 16px;animation:seattle-menu-enter .16s ease both;}` +
                `.${unique} .seattle-menu-card.is-removing{pointer-events:none;}` +
                `.${unique} .seattle-menu-head{margin-bottom:14px;}` +
                `.${unique} .seattle-menu-title{display:inline-flex;align-items:center;gap:8px;}` +
                `.${unique} .seattle-menu-icon-preview{display:inline-flex;width:28px;height:28px;align-items:center;justify-content:center;border-radius:50%;background:rgba(var(--md-primary-rgb,29,155,240),.12);color:var(--md-primary,#1d9bf0);font-size:17px;}` +
                `.${unique} .seattle-menu-actions{display:inline-flex;align-items:center;gap:4px;}` +
                `.${unique} button.widget-btn{padding:0;border:0;background:transparent;}` +
                `.${unique} button.widget-btn:hover:not(:disabled){background:var(--md-hover-overlay,rgba(15,23,42,.06));color:var(--md-primary,#1d9bf0);}` +
                `.${unique} button.widget-btn:disabled{cursor:not-allowed;opacity:.36;}` +
                `.${unique} button.widget-del:hover:not(:disabled){background:rgba(var(--md-error-rgb,239,68,68),.12);color:var(--md-error,#ef4444);}` +
                `.${unique} .seattle-menu-grid{grid-template-columns:repeat(2,minmax(0,1fr));}` +
                `.${unique} .seattle-menu-url-field{grid-column:1/-1;}` +
                `.${unique} .seattle-menu-card-error{display:none;margin-top:10px;padding:8px 10px;border-radius:8px;background:rgba(var(--md-error-rgb,239,68,68),.10);color:var(--md-error,#ef4444);font-size:12px;line-height:1.5;}` +
                `.${unique} .seattle-menu-card.is-invalid .seattle-menu-card-error{display:block;}` +
                `.${unique} .seattle-menu-card.is-invalid .seattle-menu-name,` +
                `.${unique} .seattle-menu-card.is-invalid .seattle-menu-url{border-color:var(--md-error,#ef4444)!important;}` +
                `.${unique} .seattle-menu-empty{display:grid;place-items:center;gap:6px;padding:28px 16px;border:1px dashed var(--md-outline,#cbd5e1);border-radius:var(--md-radius-lg,12px);color:var(--md-on-surface-med,#64748b);text-align:center;}` +
                `.${unique} .seattle-menu-empty[hidden]{display:none!important;}` +
                `.${unique} .seattle-menu-empty i{font-size:22px;}` +
                `.${unique} .seattle-menu-empty strong{color:var(--md-on-surface,#111827);font-size:13px;}` +
                `.${unique} .seattle-menu-empty span{font-size:12px;}` +
                `.${unique} .seattle-menu-warning{display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border:1px solid rgba(var(--md-warning-rgb,245,158,11),.30);border-radius:var(--md-radius,10px);background:rgba(var(--md-warning-rgb,245,158,11),.10);color:var(--md-warning,#b45309);font-size:12px;line-height:1.55;}` +
                `.${unique} .seattle-menu-warning i{flex:0 0 auto;margin-top:2px;}` +
                `.${unique} .seattle-menu-icon-control{display:flex;width:100%;min-height:48px;align-items:center;gap:12px;margin:0;padding:8px 12px;border:1px solid var(--md-outline,#cbd5e1);border-radius:10px;background:var(--md-surface,#fff);color:var(--md-on-surface,#111827);text-align:left;transition:border-color .16s ease,box-shadow .16s ease,background .16s ease;}` +
                `.${unique} .seattle-menu-icon-control:hover{border-color:var(--md-primary,#1d9bf0);background:var(--md-hover-overlay,rgba(15,23,42,.04));}` +
                `.${unique} .seattle-menu-icon-control:focus-visible{outline:0;border-color:var(--md-primary,#1d9bf0);box-shadow:0 0 0 3px rgba(var(--md-primary-rgb,29,155,240),.16);}` +
                `.${unique} .widget-field.seattle-menu-icon-field>label{top:0!important;transform:translateY(-50%) scale(.82)!important;background:var(--md-surface-2,#f8fafc)!important;}` +
                `.${unique} .seattle-menu-icon-control .material-icons-outlined{display:inline-flex;width:30px;height:30px;flex:0 0 auto;align-items:center;justify-content:center;border-radius:8px;background:rgba(var(--md-primary-rgb,29,155,240),.12);color:var(--md-primary,#1d9bf0);font-size:20px;}` +
                `.${unique} .seattle-menu-icon-control-name{min-width:0;flex:1;overflow:hidden;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;text-overflow:ellipsis;white-space:nowrap;}` +
                `.${unique} .seattle-menu-icon-control .seattle-menu-icon-chevron{width:auto;height:auto;background:none;color:var(--md-on-surface-med,#64748b);font-size:20px;}` +
                `.${unique}-icon-layer .layui-layer-content{height:calc(100% - 43px)!important;overflow:hidden!important;background:var(--md-surface,#fff);}` +
                `.${unique}-icon-catalog{display:grid;height:100%;min-height:0;grid-template-rows:auto auto minmax(0,1fr) auto;background:var(--md-surface,#fff);color:var(--md-on-surface,#111827);}` +
                `.${unique}-icon-catalog .seattle-icon-search-wrap{position:relative;padding:16px 16px 8px;}` +
                `.${unique}-icon-catalog .seattle-icon-search-wrap>.material-icons-outlined{position:absolute;left:30px;top:31px;color:var(--md-on-surface-med,#64748b);font-size:20px;pointer-events:none;}` +
                `.${unique}-icon-catalog .seattle-icon-search{width:100%;height:48px;padding:0 44px;border:1px solid var(--md-outline,#cbd5e1);border-radius:999px;background:var(--md-surface-2,#f8fafc);color:var(--md-on-surface,#111827);font-size:14px;outline:0;transition:border-color .16s ease,box-shadow .16s ease;}` +
                `.${unique}-icon-catalog .seattle-icon-search:focus{border-color:var(--md-primary,#1d9bf0);box-shadow:0 0 0 3px rgba(var(--md-primary-rgb,29,155,240),.14);}` +
                `.${unique}-icon-catalog .seattle-icon-search-clear{position:absolute;right:24px;top:24px;display:inline-flex;width:36px;height:36px;align-items:center;justify-content:center;padding:0;border:0;border-radius:50%;background:transparent;color:var(--md-on-surface-med,#64748b);}` +
                `.${unique}-icon-catalog .seattle-icon-search-clear[hidden]{display:none;}` +
                `.${unique}-icon-catalog .seattle-icon-search-clear:hover{background:var(--md-hover-overlay,rgba(15,23,42,.06));color:var(--md-on-surface,#111827);}` +
                `.${unique}-icon-catalog .seattle-icon-meta{display:flex;min-width:0;align-items:center;justify-content:space-between;gap:12px;padding:4px 18px 10px;color:var(--md-on-surface-med,#64748b);font-size:12px;}` +
                `.${unique}-icon-catalog .seattle-icon-meta>span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}` +
                `.${unique}-icon-catalog .seattle-icon-meta>span:first-child{flex:0 1 auto;}` +
                `.${unique}-icon-catalog .seattle-icon-meta>span:last-child{flex:1 1 auto;text-align:right;}` +
                `.${unique}-icon-catalog .seattle-icon-meta strong{color:var(--md-on-surface,#111827);font-weight:600;}` +
                `.${unique}-icon-catalog .seattle-icon-grid-wrap{min-height:0;overflow:auto;padding:0 16px 16px;overscroll-behavior:contain;}` +
                `.${unique}-icon-catalog .seattle-icon-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(82px,1fr));gap:8px;}` +
                `.${unique}-icon-catalog .seattle-icon-option{display:grid;min-width:0;min-height:76px;place-items:center;align-content:center;gap:6px;padding:8px 6px;border:1px solid var(--md-divider,#e5e7eb);border-radius:12px;background:var(--md-surface,#fff);color:var(--md-on-surface-med,#64748b);transition:border-color .14s ease,background .14s ease,color .14s ease,transform .14s ease;}` +
                `.${unique}-icon-catalog .seattle-icon-option:hover{border-color:rgba(var(--md-primary-rgb,29,155,240),.55);background:rgba(var(--md-primary-rgb,29,155,240),.08);color:var(--md-primary,#1d9bf0);transform:translateY(-1px);}` +
                `.${unique}-icon-catalog .seattle-icon-option:focus-visible{outline:0;border-color:var(--md-primary,#1d9bf0);box-shadow:0 0 0 3px rgba(var(--md-primary-rgb,29,155,240),.14);}` +
                `.${unique}-icon-catalog .seattle-icon-option.is-selected{border-color:var(--md-primary,#1d9bf0);background:rgba(var(--md-primary-rgb,29,155,240),.12);color:var(--md-primary,#1d9bf0);}` +
                `.${unique}-icon-catalog .seattle-icon-option .material-icons-outlined{font-size:27px;}` +
                `.${unique}-icon-catalog .seattle-icon-option small{display:block;width:100%;overflow:hidden;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:10px;line-height:1.25;text-align:center;text-overflow:ellipsis;white-space:nowrap;}` +
                `.${unique}-icon-catalog .seattle-icon-empty{display:grid;min-height:220px;place-items:center;align-content:center;gap:8px;color:var(--md-on-surface-med,#64748b);text-align:center;}` +
                `.${unique}-icon-catalog .seattle-icon-empty[hidden]{display:none;}` +
                `.${unique}-icon-catalog .seattle-icon-empty .material-icons-outlined{font-size:32px;}` +
                `.${unique}-icon-catalog .seattle-icon-pagination{display:grid;grid-template-columns:44px minmax(0,1fr) 44px;align-items:center;gap:8px;padding:12px 16px calc(12px + env(safe-area-inset-bottom));border-top:1px solid var(--md-divider,#e5e7eb);background:var(--md-surface,#fff);}` +
                `.${unique}-icon-catalog .seattle-icon-page-button{display:inline-flex;width:44px;height:44px;align-items:center;justify-content:center;padding:0;border:1px solid var(--md-divider,#e5e7eb);border-radius:50%;background:transparent;color:var(--md-on-surface,#111827);}` +
                `.${unique}-icon-catalog .seattle-icon-page-button:hover:not(:disabled){border-color:var(--md-primary,#1d9bf0);background:rgba(var(--md-primary-rgb,29,155,240),.08);color:var(--md-primary,#1d9bf0);}` +
                `.${unique}-icon-catalog .seattle-icon-page-button:disabled{cursor:not-allowed;opacity:.35;}` +
                `.${unique}-icon-catalog .seattle-icon-page-status{overflow:hidden;color:var(--md-on-surface-med,#64748b);font-size:12px;text-align:center;text-overflow:ellipsis;white-space:nowrap;}` +
                `.${unique}-mobile-picker .admin-mobile-overlay__body{display:flex;min-height:0;flex:1 1 auto;padding:0!important;overflow:hidden!important;}` +
                `.${unique}-mobile-picker .${unique}-icon-catalog{width:100%;height:100%;}` +
                `@keyframes seattle-menu-enter{from{opacity:0}to{opacity:1}}` +
                `@media(max-width:640px){.${unique} .seattle-menu-intro{align-items:stretch;flex-direction:column;}.${unique} .seattle-menu-add{justify-content:center;width:100%;}.${unique} .seattle-menu-actions{gap:2px;}.${unique} .seattle-menu-actions button.widget-btn{width:44px;height:44px;border-radius:12px;}.${unique} .seattle-menu-grid{grid-template-columns:1fr!important;}.${unique} .seattle-menu-url-field{grid-column:auto;}.${unique}-icon-catalog .seattle-icon-search-wrap{padding:12px 12px 8px;}.${unique}-icon-catalog .seattle-icon-search-wrap>.material-icons-outlined{left:26px;top:27px;}.${unique}-icon-catalog .seattle-icon-search-clear{right:20px;top:20px;}.${unique}-icon-catalog .seattle-icon-meta{padding:4px 14px 10px;}.${unique}-icon-catalog .seattle-icon-grid-wrap{padding:0 12px 12px;}.${unique}-icon-catalog .seattle-icon-grid{grid-template-columns:repeat(auto-fill,minmax(68px,1fr));gap:8px;}.${unique}-icon-catalog .seattle-icon-option{min-height:72px;}}` +
            `</style>` +
            `<div class="seattle-menu-builder ${unique}">` +
                `<input type="hidden" name="quick_menu" class="seattle-menu-value">` +
                `<div class="seattle-menu-intro">` +
                    `<div><strong>${i18n('自定义快捷菜单')}</strong><span>${i18n('可按访客或会员显示，也可以跳转到外部网站')}</span></div>` +
                    `<button type="button" class="widget-add-control seattle-menu-add"><i class="fa-duotone fa-regular fa-plus"></i>${i18n('添加菜单')}</button>` +
                `</div>` +
                (initialState.recovered
                    ? `<div class="seattle-menu-warning" role="status"><i class="fa-duotone fa-regular fa-triangle-exclamation"></i><span>${i18n('原快捷菜单配置无法读取，已显示兼容默认值；确认无误后保存即可重建配置。')}</span></div>`
                    : '') +
                `<div class="seattle-menu-list"></div>` +
                `<div class="seattle-menu-empty" hidden><i class="fa-duotone fa-regular fa-link-slash"></i><strong>${i18n('暂时没有快捷菜单')}</strong><span>${i18n('添加后会显示在电脑端商城首页右侧')}</span></div>` +
            `</div>`
        );

        const root = instance.find(`.${unique}`);
        const list = root.find('.seattle-menu-list');
        const hidden = root.find('.seattle-menu-value');
        const addButton = root.find('.seattle-menu-add');
        const formFilter = String(instance.closest('form').attr('lay-filter') || '');
        const iconPageSize = 96;
        let activeIconPicker = null;

        const renderSelects = () => {
            formFilter ? layui.form.render('select', formFilter) : layui.form.render('select');
        };

        const closeIconPicker = selected => {
            const active = activeIconPicker;
            activeIconPicker = null;

            if (!active) {
                return;
            }

            if (active.kind === 'mobile') {
                selected ? active.handle.commit() : active.handle.close();
            } else {
                layer.close(active.index);
            }
        };

        const createIconCatalog = card => {
            const selectedIcon = String(card.find('.seattle-menu-icon').val() || 'receipt_long');
            let page = Math.max(0, Math.floor(Math.max(0, icons.indexOf(selectedIcon)) / iconPageSize));
            const catalog = $(
                `<div class="${unique}-icon-catalog">` +
                    `<div class="seattle-icon-search-wrap">` +
                        `<span class="material-icons-outlined" aria-hidden="true">search</span>` +
                        `<input type="search" class="seattle-icon-search" placeholder="${i18n('搜索图标名称，如 home、首页')}" autocomplete="off" spellcheck="false" enterkeyhint="search" aria-label="${i18n('搜索菜单图标')}">` +
                        `<button type="button" class="seattle-icon-search-clear" aria-label="${i18n('清除搜索')}" hidden><span class="material-icons-outlined" aria-hidden="true">close</span></button>` +
                    `</div>` +
                    `<div class="seattle-icon-meta"><span>${i18n('Material Icons Outlined')} · <strong class="seattle-icon-result-count"></strong></span><span class="seattle-icon-current"></span></div>` +
                    `<div class="seattle-icon-grid-wrap">` +
                        `<div class="seattle-icon-grid" role="listbox" aria-label="${i18n('菜单图标')}"></div>` +
                        `<div class="seattle-icon-empty" hidden><span class="material-icons-outlined" aria-hidden="true">search_off</span><strong>${i18n('没有匹配的图标')}</strong><span>${i18n('试试英文名称或更短的关键词')}</span></div>` +
                    `</div>` +
                    `<div class="seattle-icon-pagination">` +
                        `<button type="button" class="seattle-icon-page-button seattle-icon-page-prev" aria-label="${i18n('上一页')}"><span class="material-icons-outlined" aria-hidden="true">chevron_left</span></button>` +
                        `<span class="seattle-icon-page-status" aria-live="polite"></span>` +
                        `<button type="button" class="seattle-icon-page-button seattle-icon-page-next" aria-label="${i18n('下一页')}"><span class="material-icons-outlined" aria-hidden="true">chevron_right</span></button>` +
                    `</div>` +
                `</div>`
            );
            const search = catalog.find('.seattle-icon-search');
            const clearSearch = catalog.find('.seattle-icon-search-clear');
            const grid = catalog.find('.seattle-icon-grid');
            const gridWrap = catalog.find('.seattle-icon-grid-wrap');
            const empty = catalog.find('.seattle-icon-empty');
            const resultCount = catalog.find('.seattle-icon-result-count');
            const current = catalog.find('.seattle-icon-current');
            const pageStatus = catalog.find('.seattle-icon-page-status');
            const previous = catalog.find('.seattle-icon-page-prev');
            const next = catalog.find('.seattle-icon-page-next');

            const matchingIcons = () => {
                const rawQuery = String(search.val() || '').trim().toLowerCase();
                const query = rawQuery.replace(/[\s-]+/g, '_');

                if (!query) {
                    return icons;
                }

                return icons.filter(icon => {
                    const alias = String(iconAliases[icon] || '').toLowerCase();
                    return icon.includes(query)
                        || icon.replace(/_/g, ' ').includes(rawQuery)
                        || alias.includes(rawQuery);
                });
            };

            const renderIcons = () => {
                const matches = matchingIcons();
                const totalPages = Math.max(1, Math.ceil(matches.length / iconPageSize));
                page = Math.min(Math.max(0, page), totalPages - 1);
                const pageIcons = matches.slice(page * iconPageSize, (page + 1) * iconPageSize);

                grid.html(pageIcons.map(icon =>
                    `<button type="button" class="seattle-icon-option${icon === selectedIcon ? ' is-selected' : ''}" data-icon="${escapeHtml(icon)}" role="option" aria-selected="${icon === selectedIcon ? 'true' : 'false'}" title="${escapeHtml(icon)}">` +
                        `<span class="material-icons-outlined" aria-hidden="true">${escapeHtml(icon)}</span>` +
                        `<small>${escapeHtml(icon)}</small>` +
                    `</button>`
                ).join(''));
                grid.prop('hidden', pageIcons.length === 0);
                empty.prop('hidden', pageIcons.length !== 0);
                resultCount.text(`${matches.length.toLocaleString()} ${i18n('个图标')}`);
                current.text(`${i18n('当前')}：${selectedIcon}`);
                pageStatus.text(matches.length ? `${i18n('第')} ${page + 1} / ${totalPages} ${i18n('页')}` : i18n('无搜索结果'));
                previous.prop('disabled', page === 0 || matches.length === 0);
                next.prop('disabled', page >= totalPages - 1 || matches.length === 0);
                clearSearch.prop('hidden', String(search.val() || '').length === 0);
                gridWrap.scrollTop(0);
            };

            search.on('input', () => {
                page = 0;
                renderIcons();
            });
            search.on('keydown', event => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                }
            });
            clearSearch.on('click', () => {
                search.val('');
                page = 0;
                renderIcons();
                search.trigger('focus');
            });
            previous.on('click', () => {
                page -= 1;
                renderIcons();
            });
            next.on('click', () => {
                page += 1;
                renderIcons();
            });
            grid.on('click', '.seattle-icon-option', event => {
                const icon = String($(event.currentTarget).data('icon') || '');

                if (!iconIds.has(icon)) {
                    return;
                }

                card.find('.seattle-menu-icon').val(icon).trigger('change');
                closeIconPicker(true);
            });
            catalog.on('keydown', event => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    closeIconPicker(false);
                }
            });
            renderIcons();

            return catalog;
        };

        const openIconPicker = card => {
            closeIconPicker(false);

            const content = createIconCatalog(card);
            const mobileOverlay = window.AdminMobile
                && typeof window.AdminMobile.isEnabled === 'function'
                && window.AdminMobile.isEnabled()
                && typeof window.AdminMobile.openSheet === 'function';

            if (mobileOverlay) {
                const handle = window.AdminMobile.openSheet({
                    id: `${unique}-icon-picker`,
                    title: i18n('选择菜单图标'),
                    subtitle: `${icons.length.toLocaleString()} ${i18n('个 Material Icons Outlined 图标')}`,
                    content: content.get(0),
                    className: `${unique}-mobile-picker`,
                    fullScreen: true,
                    shadeClose: true,
                    onClose: () => {
                        if (activeIconPicker && activeIconPicker.content.get(0) === content.get(0)) {
                            activeIconPicker = null;
                        }
                        content.remove();
                    }
                });

                if (handle && handle.handled) {
                    activeIconPicker = {kind: 'mobile', handle, content};
                    return;
                }
            }

            const width = Math.min(760, Math.max(240, window.innerWidth - 32), window.innerWidth);
            const height = Math.min(720, Math.max(240, window.innerHeight - 32), window.innerHeight);
            const mountClass = `${unique}-icon-mount`;
            let index = 0;
            index = layer.open({
                type: 1,
                title: `${i18n('选择菜单图标')} · ${icons.length.toLocaleString()}`,
                area: [`${width}px`, `${height}px`],
                skin: `${unique}-icon-layer`,
                // layer 3.5.1 can leave an orphan shade when a jQuery object is
                // opened from inside another layer. Create the nested layer
                // from a string mount, then move the live catalog into it.
                content: `<div class="${mountClass}"></div>`,
                shade: 0.28,
                shadeClose: true,
                resize: false,
                success: (layerElement, openedIndex) => {
                    const mount = layerElement.find(`.${mountClass}`);
                    if (!mount.length) {
                        content.remove();
                        layer.close(openedIndex);
                        return;
                    }
                    mount.replaceWith(content);
                    content.find('.seattle-icon-search').trigger('focus');
                },
                end: () => {
                    if (activeIconPicker && activeIconPicker.kind === 'desktop' && activeIconPicker.index === index) {
                        activeIconPicker = null;
                    }
                    content.remove();
                }
            });
            activeIconPicker = {kind: 'desktop', index, content};
        };

        const showLinkFields = (card, linkType) => {
            const external = linkType === 'external';
            card.find('.seattle-menu-target-field').toggle(!external);
            card.find('.seattle-menu-url-field').toggle(external);
        };

        const normalizeExternalUrl = value => {
            const url = String(value || '').trim();
            if (
                !url
                || url.length > 2048
                || !/^https?:\/\//i.test(url)
                || /[\s\u0000-\u001f\u007f\\]/u.test(url)
                || /^\/\//.test(url)
            ) {
                return null;
            }

            try {
                const parsed = new URL(url);
                if (!['http:', 'https:'].includes(parsed.protocol) || !parsed.hostname || parsed.username || parsed.password) {
                    return null;
                }
                return parsed.href.length <= 2048 ? parsed.href : null;
            } catch (error) {
                return null;
            }
        };

        const sync = () => {
            const menus = [];
            let valid = true;
            list.children('.seattle-menu-card:not(.is-removing)').each(function () {
                const card = $(this);
                const linkType = String(card.find('.seattle-menu-link-type').val() || 'internal');
                const rawUrl = linkType === 'external' ? String(card.find('.seattle-menu-url').val() || '').trim() : '';
                const externalUrl = linkType === 'external' ? normalizeExternalUrl(rawUrl) : '';
                const item = {
                    name: String(card.find('.seattle-menu-name').val() || '').trim(),
                    icon: String(card.find('.seattle-menu-icon').val() || 'receipt_long'),
                    audience: String(card.find('.seattle-menu-audience').val() || 'all'),
                    site: String(card.find('.seattle-menu-site').val() || 'all'),
                    link_type: linkType,
                    target: linkType === 'internal' ? String(card.find('.seattle-menu-target').val() || 'store') : '',
                    url: linkType === 'external' ? (externalUrl ?? rawUrl) : ''
                };
                const nameValid = item.name.length > 0 && item.name.length <= 32 && !/[\u0000-\u001f\u007f]/.test(item.name);
                const linkValid = linkType === 'internal' ? targetIds.has(item.target) : externalUrl !== null;
                const iconValid = iconIds.has(item.icon);
                const itemValid = nameValid
                    && linkTypeIds.has(item.link_type)
                    && audienceIds.has(item.audience)
                    && siteIds.has(item.site)
                    && iconValid
                    && linkValid;
                const errorText = !nameValid
                    ? i18n('请填写 1–32 个字符的菜单名称')
                    : (!iconValid
                        ? i18n('请选择有效的菜单图标')
                        : (!linkValid ? i18n('外部链接必须是完整的 http:// 或 https:// 地址') : i18n('请完整填写菜单信息')));

                card.toggleClass('is-invalid', !itemValid);
                card.find('.seattle-menu-card-error').text(itemValid ? '' : errorText);
                card.find('.seattle-menu-icon-preview, .seattle-menu-icon-control-preview').text(item.icon);
                card.find('.seattle-menu-icon-control-name').text(item.icon);
                valid = valid && itemValid;
                menus.push(item);
            });
            hidden.val(valid ? encodeURIComponent(JSON.stringify(menus)) : '__SEATTLE_QUICK_MENU_INVALID__');
            root.find('.seattle-menu-empty').prop('hidden', menus.length !== 0);
            const reachedLimit = menus.length >= 12;
            addButton
                .prop('disabled', reachedLimit)
                .attr('aria-disabled', reachedLimit ? 'true' : 'false')
                .attr('title', reachedLimit ? i18n('最多只能添加 12 个菜单') : '');
        };

        // Layui's rendered select stops click bubbling and does not dispatch a
        // native change event. One capture listener owned by this component
        // observes option clicks, then reads the updated native select.
        const captureSelect = event => {
            const option = event.target && event.target.closest
                ? event.target.closest('.layui-form-select dd[lay-value]')
                : null;
            const cardElement = option && option.closest ? option.closest('.seattle-menu-card') : null;
            if (!option || !cardElement || !root.get(0).contains(cardElement)) {
                return;
            }

            window.setTimeout(() => {
                if (!cardElement.isConnected || cardElement.classList.contains('is-removing')) {
                    return;
                }
                const card = $(cardElement);
                showLinkFields(card, String(card.find('.seattle-menu-link-type').val() || 'internal'));
                sync();
            }, 0);
        };
        root.get(0).addEventListener('click', captureSelect, true);

        const refreshOrder = () => {
            const cards = list.children('.seattle-menu-card:not(.is-removing)');
            cards.each(function (index) {
                const card = $(this);
                card.find('.seattle-menu-number').text(`${i18n('菜单')} ${index + 1}`);
                card.find('.seattle-menu-up').prop('disabled', index === 0).attr('aria-disabled', index === 0 ? 'true' : 'false');
                card.find('.seattle-menu-down').prop('disabled', index === cards.length - 1).attr('aria-disabled', index === cards.length - 1 ? 'true' : 'false');
            });
            sync();
        };

        const addMenu = (value, shouldRender = true, shouldRefresh = true) => {
            if (list.children('.seattle-menu-card:not(.is-removing)').length >= 12) {
                layer.msg(i18n('最多只能添加 12 个菜单'));
                return;
            }

            const item = normalizeMenu(Object.assign({
                name: '',
                icon: 'receipt_long',
                audience: 'all',
                site: 'all',
                link_type: 'internal',
                target: 'store',
                url: ''
            }, value || {}));
            const card = $(
                `<div class="widget-block seattle-menu-card">` +
                    `<div class="widget-head seattle-menu-head">` +
                        `<span class="widget-title seattle-menu-title"><span class="material-icons-outlined seattle-menu-icon-preview" aria-hidden="true">${escapeHtml(item.icon)}</span><span class="seattle-menu-number"></span></span>` +
                        `<span class="seattle-menu-actions">` +
                            `<button type="button" class="widget-btn seattle-menu-up" aria-label="${i18n('上移菜单')}" title="${i18n('上移菜单')}"><i class="fa-duotone fa-regular fa-arrow-up"></i></button>` +
                            `<button type="button" class="widget-btn seattle-menu-down" aria-label="${i18n('下移菜单')}" title="${i18n('下移菜单')}"><i class="fa-duotone fa-regular fa-arrow-down"></i></button>` +
                            `<button type="button" class="widget-btn widget-del seattle-menu-delete" aria-label="${i18n('删除菜单')}" title="${i18n('删除菜单')}"><i class="fa-duotone fa-regular fa-trash-can"></i></button>` +
                        `</span>` +
                    `</div>` +
                    `<div class="widget-grid seattle-menu-grid">` +
                        `<div class="widget-field seattle-menu-name-field"><label>${i18n('菜单名称')}</label><input type="text" maxlength="32" class="layui-input seattle-menu-name" placeholder="${i18n('例如：在线客服')}" value="${escapeHtml(item.name)}"></div>` +
                        `<div class="widget-field"><label>${i18n('显示范围')}</label><div class="widget-general"><select class="seattle-menu-audience">` +
                            optionHtml(audiences, item.audience) +
                        `</select></div></div>` +
                        `<div class="widget-field"><label>${i18n('显示站点')}</label><div class="widget-general"><select class="seattle-menu-site">` +
                            optionHtml(sites, item.site) +
                        `</select></div></div>` +
                        `<div class="widget-field"><label>${i18n('跳转方式')}</label><div class="widget-general"><select class="seattle-menu-link-type">` +
                            optionHtml(linkTypes, item.link_type) +
                        `</select></div></div>` +
                        `<div class="widget-field seattle-menu-target-field"><label>${i18n('站内页面')}</label><div class="widget-general"><select class="seattle-menu-target">${optionHtml(targets, item.target)}</select></div></div>` +
                        `<div class="widget-field seattle-menu-url-field"><label>${i18n('外部网址')}</label><input type="url" maxlength="2048" class="layui-input seattle-menu-url" placeholder="https://example.com" value="${escapeHtml(item.url)}"></div>` +
                        `<div class="widget-field seattle-menu-icon-field"><label>${i18n('菜单图标')}</label>` +
                            `<input type="hidden" class="seattle-menu-icon" value="${escapeHtml(item.icon)}">` +
                            `<button type="button" class="seattle-menu-icon-control" aria-label="${i18n('选择菜单图标')}">` +
                                `<span class="material-icons-outlined seattle-menu-icon-control-preview" aria-hidden="true">${escapeHtml(item.icon)}</span>` +
                                `<span class="seattle-menu-icon-control-name">${escapeHtml(item.icon)}</span>` +
                                `<span class="material-icons-outlined seattle-menu-icon-chevron" aria-hidden="true">expand_more</span>` +
                            `</button>` +
                        `</div>` +
                    `</div>` +
                    `<div class="seattle-menu-card-error" role="alert"></div>` +
                `</div>`
            );

            list.append(card);
            card.show();

            showLinkFields(card, item.link_type);

            card.on('input change', 'input, select', sync);
            card.on('change', '.seattle-menu-link-type', event => {
                showLinkFields(card, String(event.currentTarget.value || 'internal'));
                sync();
            });
            card.find('.seattle-menu-icon-control').on('click', () => openIconPicker(card));
            card.find('.seattle-menu-up').on('click', () => {
                const previous = card.prev('.seattle-menu-card:not(.is-removing)');
                if (previous.length) {
                    card.insertBefore(previous);
                    refreshOrder();
                }
            });
            card.find('.seattle-menu-down').on('click', () => {
                const next = card.next('.seattle-menu-card:not(.is-removing)');
                if (next.length) {
                    card.insertAfter(next);
                    refreshOrder();
                }
            });
            card.find('.seattle-menu-delete').on('click', () => {
                card.addClass('is-removing');
                refreshOrder();
                card.stop(true, true).fadeOut(120, () => {
                    card.remove();
                });
            });

            shouldRender && renderSelects();
            shouldRefresh && refreshOrder();
        };

        initialMenus.forEach(item => addMenu(item, false, false));
        renderSelects();
        refreshOrder();
        addButton.on('click', () => addMenu({}));
        sync();

        return () => {
            closeIconPicker(false);
            root.get(0).removeEventListener('click', captureSelect, true);
            root.find('*').addBack().off();
        };
    };

    return [
        {
            name: util.icon('fa-duotone fa-regular fa-code') + ' ' + i18n('西雅图'),
            form: [
                {
                    title: i18n('色彩模式'),
                    name: 'theme_mode',
                    type: 'radio',
                    dict: [
                        {id: 'auto', name: i18n('跟随系统')},
                        {id: 'light', name: i18n('固定白天')},
                        {id: 'dark', name: i18n('固定黑夜')}
                    ],
                    default: 'auto'
                },
                {title: i18n('销量显示'), name: 'show_sold', type: 'switch', text: i18n('开启'), default: '1'},
                {
                    title: i18n('分类栏宽度'),
                    name: 'category_width',
                    type: 'radio',
                    dict: categoryWidths,
                    default: 'standard'
                },
                {
                    title: i18n('快捷入口面板'),
                    name: 'show_quick_menu',
                    type: 'switch',
                    placeholder: i18n('显示') + '|' + i18n('隐藏'),
                    default: '1',
                    complete: (builder, checked) => { checked || toggleQuickFields(builder, false); },
                    change: (builder, checked) => toggleQuickFields(builder, checked)
                },
                {
                    title: i18n('购买提示面板'),
                    name: 'show_buy_tips',
                    type: 'switch',
                    placeholder: i18n('显示') + '|' + i18n('隐藏'),
                    default: '1'
                },
                {title: i18n('快捷入口标题'), name: 'quick_title', type: 'input', placeholder: i18n('显示在电脑端首页右侧'), default: i18n('快捷入口')},
                {title: i18n('快捷入口说明'), name: 'quick_subtitle', type: 'input', placeholder: i18n('显示在快捷入口标题下方'), default: i18n('常用服务')},
                {
                    title: false,
                    name: 'quick_menu',
                    type: 'custom',
                    default: menuDefault,
                    regex: {
                        value: '^%5B.*%5D$',
                        message: i18n('请完整填写每个快捷菜单的名称和链接')
                    },
                    complete: menuBuilder
                }
            ]
        }
    ];
})()
