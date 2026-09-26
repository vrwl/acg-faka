!function () {
    'use strict';

    const $page = $('[data-st-page="personal"]').first();
    if (!$page.length) return;
    const $form = $page.find('.form-data').first();
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let saving = false;
    let resettingKey = false;
    let keyVisible = false;

    const $wechatQr = $page.find('.st-wechat-qr');
    if ($wechatQr.length && getVar('_user_wechat')) {
        $wechatQr.qrcode({
            render: "canvas",
            width: 100,
            height: 100,
            text: getVar('_user_wechat')
        });
    }


    util.bindButtonUpload('[data-st-page="personal"] .avatar-input', "/user/api/upload/send?mime=image", result => {
        $page.find('input[name=avatar]').val(result.url);
        $page.find('.avatar-img').attr("src", result.url);
    });

    util.bindButtonUpload('[data-st-page="personal"] .st-wechat-input', "/user/api/upload/send?mime=image", result => {
        $page.find('input[name=wechat]').val(result.url);
        $page.find('.st-wechat-qr, .st-wechat-upload').each(function () {
            $(this).empty().append($('<img>', {
                class: 'st-wechat-image',
                src: result.url,
                alt: i18n('微信收款二维码')
            }));
        });
        message.success(i18n("上传完成，需要保存才会生效哦"));
    });

    $page.on('click', '[data-st-avatar-upload]', function () {
        $page.find('.avatar-input').trigger('click');
    });

    $page.on('click', '[data-st-wechat-upload]', function () {
        $page.find('.st-wechat-input').trigger('click');
    });


    $page.find('.save-data').click(function () {
        if (saving) return;
        saving = true;
        const $button = $(this).prop('disabled', true).attr('aria-busy', 'true');
        const restore = () => {
            saving = false;
            $button.prop('disabled', false).removeAttr('aria-busy');
        };
        util.post({
            url: "/user/api/security/personal",
            data: util.getFormData($form[0]),
            done: () => {
                restore();
                const avatar = String($form.find('input[name="avatar"]').val() || '');
                if (avatar) {
                    $('.st-user-chip img, .st-nav__profile img').attr('src', avatar);
                }
                message.success(i18n("已生效"));
            },
            error: response => {
                restore();
                message.error(response && response.msg ? response.msg : i18n('保存失败，请检查后重试'));
            },
            fail: () => {
                restore();
                message.error(i18n('网络连接失败，请稍后重试'));
            }
        });
    });

    //安全导航里「个人资料 / 修改个人信息」= 同页两个面板,拦截为即时切换(不整页刷新);
    //在其它安全页(密码/邮箱/手机)这两个链接照常跳转到本页,由下方 URL 参数决定落在哪个面板
    const $info = $page.find('.st-subtab a[data-ptab="info"]');
    const $edit = $page.find('.st-subtab a[data-ptab="edit"]');
    function showTab(which, updateUrl) {
        const isEdit = which === "edit";
        $page.find('.st-tabpanel[data-panel="security"]').toggleClass("active", !isEdit);
        $page.find('.st-tabpanel[data-panel="profile"]').toggleClass("active", isEdit);
        $info.toggleClass("active", !isEdit);
        $edit.toggleClass("active", isEdit);
        $page.find('.st-tabpanel[data-panel="security"]').attr('aria-hidden', String(isEdit));
        $page.find('.st-tabpanel[data-panel="profile"]').attr('aria-hidden', String(!isEdit));
        $info.attr('aria-current', isEdit ? null : 'page');
        $edit.attr('aria-current', isEdit ? 'page' : null);
        const activeTab = (isEdit ? $edit : $info).get(0);
        if (activeTab && typeof activeTab.scrollIntoView === 'function') {
            activeTab.scrollIntoView({behavior: updateUrl === false || reduceMotion ? 'auto' : 'smooth', block: 'nearest', inline: 'center'});
        }
        if (updateUrl !== false && window.history && window.history.pushState) {
            const currentState = window.history.state && typeof window.history.state === 'object'
                ? window.history.state
                : {};
            const nextUrl = new URL(isEdit ? '/user/security/personal?tab=edit' : '/user/security/personal', window.location.href).href;
            const nextState = Object.assign({}, currentState, {url: nextUrl});
            if (nextUrl !== window.location.href) {
                window.history.pushState(nextState, document.title, nextUrl);
            } else {
                window.history.replaceState(nextState, document.title, nextUrl);
            }
            if ($.pjax && $.pjax.state && currentState.id && $.pjax.state.id === currentState.id) {
                $.pjax.state = nextState;
            }
        }
    }
    $info.on("click", function (e) { e.preventDefault(); showTab("info"); });
    $edit.on("click", function (e) { e.preventDefault(); showTab("edit"); });
    const syncTabFromLocation = () => {
        if (!document.contains($page[0])) return;
        showTab(util.getParam("tab") === "edit" ? "edit" : "info", false);
    };
    $(window).off('popstate.seattlePersonal').on('popstate.seattlePersonal', syncTabFromLocation);
    syncTabFromLocation();

    const $appKey = $page.find('.app-key');
    const $keyToggle = $page.find('.st-key-toggle');
    const maskedKey = '••••••••••••••••';
    const currentKey = () => String($appKey.attr('data-st-secret') || '');
    const renderKey = () => {
        $appKey.text(keyVisible ? currentKey() : maskedKey)
            .attr('aria-label', keyVisible ? i18n('商户密钥已显示') : i18n('商户密钥已隐藏'));
        $keyToggle.attr('aria-pressed', String(keyVisible));
        $keyToggle.find('.material-icons-outlined').text(keyVisible ? 'visibility_off' : 'visibility');
        $keyToggle.find('span:last-child').text(keyVisible ? i18n('隐藏') : i18n('显示'));
    };

    $keyToggle.on('click', function () {
        keyVisible = !keyVisible;
        renderKey();
    });
    $page.find('.st-key-copy').on('click', function () {
        const key = currentKey();
        if (!key) {
            message.error(i18n('当前没有可复制的商户密钥'));
            return;
        }
        util.copyTextToClipboard(key, () => message.success(i18n('商户密钥已复制')), () => message.error(i18n('复制失败，请重试')));
    });

    //账户与安全 tab:重置商户密钥
    $page.find('.reset-key').click(function () {
        if (resettingKey) return;
        message.ask(i18n("是否要重置您的密钥？"), () => {
            if (resettingKey) return;
            resettingKey = true;
            const $button = $page.find('.reset-key').prop('disabled', true).attr('aria-busy', 'true');
            const restore = () => {
                resettingKey = false;
                $button.prop('disabled', false).removeAttr('aria-busy');
            };
            util.post({
                url: '/user/api/security/resetKey',
                done: res => {
                    restore();
                    const nextKey = res && res.data ? String(res.data.app_key || '') : '';
                    if (!nextKey) {
                        message.error(i18n('服务器未返回新的密钥，请刷新页面确认'));
                        return;
                    }
                    $appKey.attr('data-st-secret', nextKey);
                    keyVisible = false;
                    renderKey();
                    message.success(i18n("密钥已重置"));
                },
                error: res => {
                    restore();
                    message.error(res && res.msg ? res.msg : i18n('密钥重置失败，请重试'));
                },
                fail: () => {
                    restore();
                    message.error(i18n('网络连接失败，请稍后重试'));
                }
            });
        });
    });
    renderKey();
}();
