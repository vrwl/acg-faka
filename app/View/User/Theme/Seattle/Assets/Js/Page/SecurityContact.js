!function () {
    'use strict';

    const $page = $('[data-st-page="email"], [data-st-page="phone"]').first();
    if (!$page.length) return;

    const isEmail = $page.is('[data-st-page="email"]');
    const field = isEmail ? 'email' : 'phone';
    const captchaField = isEmail ? 'email_captcha' : 'phone_captcha';
    const sendAction = isEmail ? 'emailBindNew' : 'phoneBindNew';
    const endpoint = isEmail ? '/user/api/security/email' : '/user/api/security/phone';
    const sendEndpoint = `/user/api/security/${sendAction}`;
    const $form = $page.find('.form-data').first();
    const $identity = $form.find(`input[name="${field}"]`);
    const $captcha = $form.find(`input[name="${captchaField}"]`);
    const $send = $form.find('.send-captcha');
    const $save = $form.find('.save-data');
    let sending = false;
    let saving = false;

    function setInvalid($input, invalid) {
        $input.toggleClass('st-field-invalid', invalid).attr('aria-invalid', String(invalid));
    }

    function identityError() {
        const value = String($identity.val() || '').trim();
        if (!value) return isEmail ? i18n('请输入新的邮箱地址') : i18n('请输入新的手机号码');
        if (isEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return i18n('请输入有效的邮箱地址');
        if (!isEmail && !/^1[3-9]\d{9}$/.test(value)) return i18n('请输入有效的 11 位手机号码');
        return '';
    }

    function updateActions() {
        const identityReady = !identityError();
        const captchaReady = String($captcha.val() || '').trim().length > 0;
        $send.prop('disabled', sending || !identityReady).attr('aria-disabled', String(sending || !identityReady));
        $save.prop('disabled', saving || !identityReady || !captchaReady).attr('aria-disabled', String(saving || !identityReady || !captchaReady));
    }

    function resetButton($button, text) {
        $button.removeAttr('aria-busy').text(text);
        updateActions();
    }

    function openCaptchaPrompt() {
        const error = identityError();
        setInvalid($identity, Boolean(error));
        if (error) {
            message.error(error);
            $identity.trigger('focus');
            return;
        }
        if (sending) return;

        const imageId = `st-security-captcha-${Date.now()}`;
        message.prompt({
            title: i18n('人机验证'),
            width: 420,
            html: `<button type="button" class="st-captcha-refresh" data-st-captcha-refresh="${sendAction}" aria-label="${i18n('更换图形验证码')}"><img id="${imageId}" src="/user/captcha/image?action=${sendAction}" class="prompt-image-code" alt="${i18n('图形验证码，点击更换')}"></button>`,
            inputLabel: i18n('图形验证码'),
            inputPlaceholder: i18n('输入图中字符'),
            inputAttributes: {inputmode: 'numeric', autocomplete: 'off'},
            customClass: {popup: 'st-security-captcha-prompt'},
            confirmButtonText: i18n('继续操作'),
            inputValidator: value => (!String(value || '').trim() && i18n('请输入图形验证码'))
        }).then(result => {
            if (!result || result.isConfirmed !== true) return;
            sending = true;
            $send.attr('aria-busy', 'true').text(i18n('正在发送'));
            updateActions();
            const fail = text => {
                sending = false;
                resetButton($send, i18n('获取验证码'));
                message.error(text);
            };
            util.post({
                url: sendEndpoint,
                data: {captcha: String(result.value || '').trim(), [field]: String($identity.val() || '').trim()},
                done: () => {
                    sending = false;
                    resetButton($send, i18n('获取验证码'));
                    util.countDown($send.get(0), 60);
                    message.success(i18n('验证码发送成功'));
                },
                error: response => fail(response && response.msg ? response.msg : i18n('验证码发送失败，请重试')),
                fail: () => fail(i18n('网络连接失败，请稍后重试'))
            });
        });
    }

    function saveContact() {
        const error = identityError();
        setInvalid($identity, Boolean(error));
        if (error) {
            message.error(error);
            $identity.trigger('focus');
            return;
        }
        if (!String($captcha.val() || '').trim()) {
            setInvalid($captcha, true);
            message.error(i18n('请输入收到的验证码'));
            $captcha.trigger('focus');
            return;
        }
        setInvalid($captcha, false);
        if (saving) return;
        saving = true;
        $save.attr('aria-busy', 'true');
        updateActions();
        const $label = $save.find('.st-security-save-label').text(i18n('正在保存'));
        const fail = text => {
            saving = false;
            $save.removeAttr('aria-busy');
            $label.text(i18n('保存修改'));
            updateActions();
            message.error(text);
        };
        util.post({
            url: endpoint,
            data: util.getFormData($form[0]),
            done: () => {
                message.success(i18n('绑定成功'));
                window.setTimeout(() => window.location.reload(), 1200);
            },
            error: response => fail(response && response.msg ? response.msg : i18n('保存失败，请检查后重试')),
            fail: () => fail(i18n('网络连接失败，请稍后重试'))
        });
    }

    $(document)
        .off('click.seattleSecurityCaptcha', '[data-st-captcha-refresh]')
        .on('click.seattleSecurityCaptcha', '[data-st-captcha-refresh]', function () {
            const action = $(this).data('st-captcha-refresh');
            $(this).find('img').attr('src', `/user/captcha/image?action=${encodeURIComponent(action)}&t=${Date.now()}`);
        });
    $send.on('click', openCaptchaPrompt);
    $identity.on('input', function () { setInvalid($identity, false); updateActions(); });
    $captcha.on('input', function () { setInvalid($captcha, false); updateActions(); });
    $form.on('submit', function (event) { event.preventDefault(); saveContact(); });
    updateActions();
    window.requestAnimationFrame(() => {
        const active = $page.find('.st-subnav a.active, .st-subnav a.is-active').get(0);
        if (active && typeof active.scrollIntoView === 'function') active.scrollIntoView({block: 'nearest', inline: 'center'});
    });
}();
