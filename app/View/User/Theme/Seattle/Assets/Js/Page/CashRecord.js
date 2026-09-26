//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

!function () {
    'use strict';

    const $page = $('[data-st-page="cash-record"]').first();
    if (!$page.length) return;

    const escapeHtml = value => String(value == null || value === '' ? '—' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const formatAmount = value => {
        const amount = Number(value);
        if (!Number.isFinite(amount)) return '-';
        return `<strong>${acgCurrencySymbol()}${amount.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>`;
    };

    const formatFee = value => {
        const amount = Number(value);
        if (!Number.isFinite(amount)) return '-';
        return `${amount.toLocaleString('zh-CN', {maximumFractionDigits: 2})} ${i18n('元')}`;
    };

    const table = new Table('/user/api/cash/record', '#cash-table');

    table.setColumns([
        {field: 'amount', title: i18n('到账金额'), formatter: formatAmount},
        {field: 'type', title: i18n('申请类型'), dict: '_cash_order_type'},
        {field: 'card', title: i18n('到账钱包'), dict: '_cash_wallet_type'},
        {field: 'status', title: i18n('处理状态'), dict: '_cash_order_status'},
        {field: 'message', title: i18n('处理说明'), formatter: escapeHtml},
        {field: 'cost', title: i18n('手续费'), formatter: formatFee},
        {field: 'create_time', title: i18n('提交时间'), formatter: escapeHtml},
        {field: 'arrive_time', title: i18n('到账时间'), formatter: escapeHtml}
    ]);

    table.setSearch([
        {title: i18n('到账钱包'), name: 'equal-card', type: 'select', dict: '_cash_wallet_type'},
        {title: i18n('申请类型'), name: 'equal-type', type: 'select', dict: '_cash_order_type'},
        {title: i18n('提交时间'), name: 'between-create_time', type: 'date'}
    ]);

    table.setState('status', '_cash_order_status');
    table.render();
}();
