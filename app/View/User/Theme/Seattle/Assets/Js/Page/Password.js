!function () {
    'use strict';

    const $page = $('[data-st-page="password"]').first();
    if (!$page.length) return;

    const $form = $page.find('.form-data').first();
    const $oldPassword = $form.find('input[name="old_password"]');
    const $password = $form.find('input[name="password"]');
    const $confirmation = $form.find('input[name="re_password"]');
    const $strength = $form.find('.st-password-strength');
    const $save = $form.find('.save-data');
    let submitting = false;

    function setStrength(state, icon, text) {
        $strength.removeClass('is-waiting is-error is-ready is-ok').addClass(state);
        $strength.find('.material-icons-outlined').text(icon);
        $strength.find('span:last-child').text(text);
    }

    function passwordScore(value) {
        let score = value.length >= 6 ? 1 : 0;
        if (value.length >= 12) score += 1;
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score += 1;
        if (/\d/.test(value)) score += 1;
        if (/[^A-Za-z0-9]/.test(value)) score += 1;
        return score;
    }

    function validate() {
        const oldPassword = $oldPassword.val();
        const password = $password.val();
        const confirmation = $confirmation.val();
        let valid = false;
        let oldInvalid = false;
        let passwordInvalid = false;
        let confirmationInvalid = false;

        if (!password) {
            setStrength('is-waiting', 'info', i18n('请输入至少 6 位新密码，建议使用 8 位以上'));
        } else if (password.length < 6) {
            passwordInvalid = true;
            setStrength('is-error', 'error', `${i18n('还需要')} ${6 - password.length} ${i18n('位字符')}`);
        } else if (confirmation && confirmation !== password) {
            confirmationInvalid = true;
            setStrength('is-error', 'error', i18n('两次输入的新密码不一致'));
        } else {
            const score = passwordScore(password);
            const strengthText = score >= 4 ? i18n('密码强度高') : (score >= 3 ? i18n('密码强度良好') : i18n('密码可用'));
            if (!confirmation) {
                setStrength('is-waiting', 'info', `${strengthText}；${i18n('请再次输入新密码')}`);
            } else if (!oldPassword) {
                oldInvalid = true;
                setStrength('is-waiting', 'info', `${strengthText}；${i18n('请输入当前密码')}`);
            } else {
                if (score >= 3) setStrength('is-ready', score >= 4 ? 'verified_user' : 'check_circle', strengthText);
                else setStrength('is-waiting', 'info', i18n('密码可用，加入大小写字母、数字或符号会更安全'));
                valid = true;
            }
        }

        $oldPassword.toggleClass('st-field-invalid', oldInvalid).attr('aria-invalid', String(oldInvalid));
        $password.toggleClass('st-field-invalid', passwordInvalid).attr('aria-invalid', String(passwordInvalid));
        $confirmation.toggleClass('st-field-invalid', confirmationInvalid).attr('aria-invalid', String(confirmationInvalid));
        $save.prop('disabled', submitting || !valid).attr('aria-disabled', String(submitting || !valid));
        return valid;
    }

    $form.on('click', '.st-password-toggle', function () {
        const $button = $(this);
        const $input = $form.find(`input[name="${$button.data('password-target')}"]`).first();
        if (!$input.length) return;
        const reveal = $input.attr('type') === 'password';
        $input.attr('type', reveal ? 'text' : 'password');
        $button.attr('aria-pressed', String(reveal));
        $button.find('.material-icons-outlined').text(reveal ? 'visibility_off' : 'visibility');
        $button.find('span:last-child').text(reveal ? i18n('隐藏') : i18n('显示'));
        $input.trigger('focus');
    });

    $form.on('input', 'input[type="password"], input[type="text"]', validate);
    $form.on('submit', function (event) {
        event.preventDefault();
        if (!$save.prop('disabled')) $save.trigger('click');
    });

    $save.on('click', function () {
        if (submitting || !validate()) return;
        submitting = true;
        $save.find('.st-password-submit-label').text(i18n('正在保存'));
        validate();

        const finish = () => {
            submitting = false;
            $save.find('.st-password-submit-label').text(i18n('保存新密码'));
            validate();
        };

        util.post({
            url: '/user/api/security/password',
            data: util.getFormData($form[0]),
            done: () => {
                message.success(i18n('修改成功'));
                window.setTimeout(() => window.location.reload(), 1500);
            },
            error: res => {
                finish();
                message.error(res && res.msg ? res.msg : i18n('密码修改失败，请检查后重试'));
            },
            fail: () => {
                finish();
                message.error(i18n('网络连接失败，请稍后重试'));
            }
        });
    });

    validate();
    window.requestAnimationFrame(() => {
        const active = $page.find('.st-subnav a.active, .st-subnav a.is-active').get(0);
        if (active && typeof active.scrollIntoView === 'function') active.scrollIntoView({block: 'nearest', inline: 'center'});
    });
}();
