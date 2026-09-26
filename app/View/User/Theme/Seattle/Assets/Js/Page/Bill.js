//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

!function () {
    'use strict';

    const $page = $('[data-st-page="bill"]').first();
    if (!$page.length) return;

    const escapeHtml = value => String(value == null || value === '' ? '—' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    const money = (value, className) => {
        const amount = Number(value);
        if (!Number.isFinite(amount)) return '—';
        return `<strong class="st-bill-money ${className}">${acgCurrencySymbol()}${amount.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>`;
    };

    const table = new Table('/user/api/bill/data', '#bill-table');
    table.setColumns([
        {field: 'amount', title: i18n('金额'), sort: true, formatter: (value, row) => money(value, Number(row.type) === 0 ? 'is-expense' : 'is-income')},
        {field: 'balance', title: i18n('余额'), sort: true, formatter: value => money(value, 'is-balance')},
        {field: 'type', title: i18n('收支类型'), dict: '_bill_status'},
        {field: 'currency', title: i18n('货币类型'), dict: '_bill_currency_type'},
        {field: 'log', title: i18n('交易信息'), formatter: escapeHtml},
        {field: 'create_time', title: i18n('交易时间'), formatter: escapeHtml}
    ]);
    table.setSearch([
        {title: i18n('支出/收入'), name: 'equal-type', type: 'select', dict: [{id: 0, name: i18n('支出')}, {id: 1, name: i18n('收入')}]},
        {title: i18n('钱包类型'), name: 'equal-currency', type: 'select', dict: [{id: 0, name: i18n('余额')}, {id: 1, name: i18n('硬币')}]},
        {title: i18n('交易详情'), name: 'search-log', type: 'input'},
        {title: i18n('交易时间'), name: 'between-create_time', type: 'date'}
    ]);
    table.setState('type', '_bill_status');
    table.render();
}();
