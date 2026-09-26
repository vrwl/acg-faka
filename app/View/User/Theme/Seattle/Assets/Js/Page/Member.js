//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

!function () {
    'use strict';

    const $page = $('[data-st-page="member"]').first();
    if (!$page.length) return;

    const appMode = window.matchMedia
        ? window.matchMedia('(max-width: 767px), (max-height: 500px) and (max-width: 1024px)').matches
        : (window.innerWidth < 768 || (window.innerHeight <= 500 && window.innerWidth <= 1024));
    const balance = Number($page.data('st-transfer-balance')) || 0;
    const $dialog = $page.find('[data-st-transfer-dialog]').first();
    const $amount = $dialog.find('[data-st-transfer-amount]');
    const $status = $dialog.find('[data-st-transfer-status]');
    const $confirm = $dialog.find('[data-st-transfer-confirm]');
    let selectedMember = null;
    let transferring = false;
    let transferOpener = null;

    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    const safeImageUrl = value => {
        try {
            const url = new URL(String(value || '/favicon.ico'), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : new URL('/favicon.ico', window.location.origin).href;
        } catch (error) {
            return new URL('/favicon.ico', window.location.origin).href;
        }
    };
    const textCell = value => escapeHtml(value == null || value === '' ? '—' : value);
    const moneyText = value => {
        const amount = Number(value);
        return Number.isFinite(amount)
            ? `${acgCurrencySymbol()}${amount.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`
            : '—';
    };

    function memberSummary(row) {
        const id = Number(row.id) || 0;
        return `<span class="st-member-summary"><img src="${escapeHtml(safeImageUrl(row.avatar))}" alt=""><span><strong>${escapeHtml(row.username || `UID ${id}`)}</strong><small>UID ${id}</small></span></span>`;
    }

    function groupSummary(group) {
        if (!group || typeof group !== 'object') return '<span class="st-chip">' + i18n('普通会员') + '</span>';
        const name = escapeHtml(group.name || i18n('普通会员'));
        const icon = group.icon
            ? `<img src="${escapeHtml(safeImageUrl(group.icon))}" alt="">`
            : '<span class="material-icons-outlined" aria-hidden="true">badge</span>';
        return `<span class="st-member-group">${icon}<span>${name}</span></span>`;
    }

    function setStatus(state, icon, text) {
        $status.removeClass('is-waiting is-error is-ready is-ok is-busy').addClass(state).toggleClass('is-busy', icon === 'sync');
        $status.find('.material-icons-outlined').text(icon);
        $status.find('span:last-child').text(text);
    }

    function parsedAmount() {
        const raw = String($amount.val() || '').trim();
        if (!/^\d+(?:\.\d{1,2})?$/.test(raw)) return null;
        const value = Number(raw);
        return Number.isFinite(value) ? value : null;
    }

    function validateTransfer() {
        const value = parsedAmount();
        let valid = false;
        if (!$amount.val()) {
            setStatus('is-waiting', 'info', i18n('输入金额后可继续确认'));
        } else if (value === null || value <= 0) {
            setStatus('is-error', 'error', i18n('请输入大于 0、最多两位小数的金额'));
        } else if (value > balance) {
            setStatus('is-error', 'error', `${i18n('可用余额不足，当前可转')} ${acgCurrencySymbol()}${balance.toLocaleString('zh-CN', {maximumFractionDigits: 2})}`);
        } else {
            setStatus('is-ready', 'check_circle', `${i18n('将转账')} ${acgCurrencySymbol()}${value.toLocaleString('zh-CN', {maximumFractionDigits: 2})}`);
            valid = Boolean(selectedMember);
        }
        $confirm.prop('disabled', transferring || !valid).attr('aria-disabled', String(transferring || !valid));
        return valid;
    }

    function showDialog() {
        const dialog = $dialog.get(0);
        if (!dialog) return;
        if (typeof dialog.showModal === 'function') {
            if (!dialog.open) dialog.showModal();
        } else {
            $dialog.attr('open', 'open');
        }
    }

    function closeDialog() {
        if (transferring) return;
        const dialog = $dialog.get(0);
        if (!dialog) return;
        if (typeof dialog.close === 'function' && dialog.open) dialog.close();
        else $dialog.removeAttr('open');
        selectedMember = null;
        $amount.val('').trigger('input').trigger('change');
        if (transferOpener && document.contains(transferOpener)) transferOpener.focus({preventScroll: true});
        transferOpener = null;
    }

    function openTransfer(row, trigger) {
        selectedMember = {id: Number(row.id), username: String(row.username || `UID ${row.id}`)};
        transferOpener = trigger || null;
        transferring = false;
        $dialog.find('[data-st-transfer-recipient]').text(`${selectedMember.username} · UID ${selectedMember.id}`);
        $amount.val('').trigger('input').trigger('change');
        $dialog.find('[data-st-transfer-confirm-label]').text(i18n('确认转账'));
        validateTransfer();
        showDialog();
        window.setTimeout(() => $amount.trigger('focus'), 80);
    }

    const columns = [];
    if (!appMode) columns.push({field: 'id', title: 'ID', width: 80});
    columns.push({field: 'avatar', title: i18n('成员'), formatter: (value, row) => memberSummary(row)});
    columns.push({field: 'group', title: i18n('会员等级'), formatter: groupSummary});
    if (!appMode) {
        columns.push({field: 'email', title: i18n('邮箱'), formatter: textCell});
        columns.push({field: 'phone', title: i18n('手机号'), formatter: textCell});
        columns.push({field: 'qq', title: 'QQ', formatter: textCell});
        columns.push({field: 'balance', title: i18n('余额'), formatter: moneyText, sort: true});
    }
    columns.push({field: 'recharge', title: i18n('累计充值'), formatter: moneyText, sort: true});
    columns.push({field: 'create_time', title: i18n('注册时间'), formatter: textCell});
    columns.push({field: 'status', title: i18n('状态'), dict: '_user_status', class: 'normal'});
    columns.push({
        field: 'operation', title: i18n('操作'), type: 'button', buttons: [{
            icon: 'fa-duotone fa-regular fa-money-bill-transfer',
            title: i18n('转账'),
            class: 'text-primary st-member-transfer-open',
            click: (event, value, row) => openTransfer(row, event.currentTarget)
        }]
    });

    const table = new Table('/user/api/agentMember/data', '#member-table');
    table.setColumns(columns);
    table.setSearch([{title: i18n('会员 ID'), name: 'equal-id', type: 'input'}]);
    table.setState('status', '_user_status');
    table.render();

    $amount.on('input', validateTransfer);
    $dialog.on('click', '[data-st-transfer-close]', closeDialog);
    $dialog.on('click', function (event) {
        if (event.target === this) closeDialog();
    });
    $dialog.on('cancel', function (event) {
        event.preventDefault();
        closeDialog();
    });

    $confirm.on('click', function () {
        if (transferring || !validateTransfer() || !selectedMember) return;
        const amount = parsedAmount();
        const recipient = Object.assign({}, selectedMember);
        message.ask(
            `${i18n('确认向')} <strong>${escapeHtml(recipient.username)}</strong>（UID ${recipient.id}）${i18n('转账')} <strong>${acgCurrencySymbol()}${amount.toLocaleString('zh-CN', {maximumFractionDigits: 2})}</strong>？${i18n('成功后不可撤回。')}`,
            () => {
                transferring = true;
                $dialog.find('[data-st-transfer-close]').prop('disabled', true);
                $dialog.find('[data-st-transfer-confirm-label]').text(i18n('正在转账'));
                $confirm.prop('disabled', true).attr('aria-disabled', 'true');
                setStatus('is-waiting', 'sync', i18n('正在提交转账，请勿重复操作'));

                const finish = () => {
                    transferring = false;
                    $dialog.find('[data-st-transfer-close]').prop('disabled', false);
                    $dialog.find('[data-st-transfer-confirm-label]').text(i18n('确认转账'));
                    validateTransfer();
                };

                util.post({
                    url: '/user/api/agentMember/transfer',
                    data: {id: recipient.id, amount: amount},
                    done: () => {
                        if (!document.contains($page[0])) return;
                        finish();
                        message.success(i18n('转账成功'));
                        closeDialog();
                        table.refresh();
                    },
                    error: res => {
                        if (!document.contains($page[0])) return;
                        finish();
                        message.error(res && res.msg ? res.msg : i18n('转账失败，请重试'));
                    },
                    fail: () => {
                        if (!document.contains($page[0])) return;
                        finish();
                        message.error(i18n('网络连接失败，请稍后重试'));
                    }
                });
            },
            i18n('再次确认转账'),
            i18n('确认转账')
        );
    });
}();
