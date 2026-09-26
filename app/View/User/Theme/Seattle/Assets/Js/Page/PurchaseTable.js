//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

!function () {
    'use strict';

    const $page = $('[data-st-page="dashboard"], [data-st-page="purchase-record"]').first();
    if (!$page.length) return;

    const dashboard = $page.is('[data-st-page="dashboard"]');
    const tableSelector = dashboard ? '#recent-buy-table' : '#bill-table';
    if (!$page.find(tableSelector).length) return;

    const secretRows = new Map();
    let activeSecret = null;
    let dialogOpener = null;

    const escapeHtml = value => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    const safeInlineHtml = value => window.SeattleTheme && typeof window.SeattleTheme.safeInlineHtml === 'function'
        ? window.SeattleTheme.safeInlineHtml(value)
        : escapeHtml(value);

    const safeImageUrl = value => {
        try {
            const url = new URL(String(value || ''), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
        } catch (error) {
            return '';
        }
    };

    const imageSlot = (url, fallback) => {
        const safeUrl = safeImageUrl(url);
        const image = safeUrl ? `<img src="${escapeHtml(safeUrl)}" alt="" data-acg-fallback="remove">` : '';
        return `<span class="st-order-media"><span class="material-icons-outlined" aria-hidden="true">${fallback}</span>${image}</span>`;
    };

    const itemSummary = item => {
        if (!item || typeof item !== 'object') {
            return `<span class="st-order-summary">${imageSlot('', 'inventory_2')}<span><strong>${i18n('已下架商品')}</strong><small>${i18n('商品资料已不可用')}</small></span></span>`;
        }
        return `<span class="st-order-summary">${imageSlot(item.cover, 'inventory_2')}<span><strong>${safeInlineHtml(item.name || i18n('未命名商品'))}</strong><small>${i18n('商城商品')}</small></span></span>`;
    };

    const paySummary = item => {
        if (!item || typeof item !== 'object') return '—';
        return `<span class="st-order-pay">${imageSlot(item.icon, 'payments')}<span>${escapeHtml(item.name || i18n('支付方式'))}</span></span>`;
    };

    const skuObject = value => {
        if (value && typeof value === 'object' && !Array.isArray(value)) return value;
        if (typeof value !== 'string' || !value.trim()) return null;
        try {
            const parsed = JSON.parse(value);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
        } catch (error) {
            return null;
        }
    };

    const skuSummary = (value, row) => {
        const race = row && row.race && row.race !== '-' ? String(row.race) : '';
        const sku = skuObject(value);
        const entries = sku ? Object.entries(sku) : [];
        if (!race && !entries.length) return '—';
        const chips = [];
        if (race) chips.push(`<span class="st-chip"><span>${i18n('类别')}</span><strong>${escapeHtml(race)}</strong></span>`);
        entries.forEach(([key, entry]) => {
            chips.push(`<span class="st-chip"><span>${escapeHtml(key)}</span><strong>${escapeHtml(entry)}</strong></span>`);
        });
        return `<span class="st-order-sku">${chips.join('')}</span>`;
    };

    const amountSummary = value => {
        const amount = Number(value);
        return Number.isFinite(amount)
            ? `<strong class="st-order-amount">${acgCurrencySymbol()}${amount.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>`
            : '—';
    };

    const secretButton = (value, row) => {
        if (!row || Number(row.status) !== 1 || !value) return '—';
        const key = String(row.id || row.trade_no || `${Date.now()}-${secretRows.size}`);
        secretRows.set(key, {secret: String(value), tradeNo: String(row.trade_no || 'export')});
        return `<button type="button" class="st-text-button st-order-secret-open" data-st-order-key="${escapeHtml(key)}"><span class="material-icons-outlined" aria-hidden="true">visibility</span><span>${i18n('查看卡密')}</span></button>`;
    };

    const $dialog = $(`
        <dialog class="st-dialog st-order-secret-dialog" aria-labelledby="st-order-secret-title">
            <header class="st-dialog-header">
                <div><span class="st-panel__icon material-icons-outlined" aria-hidden="true">key</span><div><h2 id="st-order-secret-title">${i18n('查看卡密')}</h2><small class="st-order-secret-trade"></small></div></div>
                <button type="button" class="st-icon-button" data-st-secret-close aria-label="${i18n('关闭')}"><span class="material-icons-outlined" aria-hidden="true">close</span></button>
            </header>
            <div class="st-dialog-body"><pre class="st-order-secret-code" tabindex="0"></pre></div>
            <footer class="st-dialog-actions">
                <button type="button" class="st-button st-button--secondary" data-st-secret-copy><span class="material-icons-outlined" aria-hidden="true">content_copy</span><span>${i18n('复制卡密')}</span></button>
                <button type="button" class="st-button st-button--primary" data-st-secret-download><span class="material-icons-outlined" aria-hidden="true">download</span><span>${i18n('下载文本')}</span></button>
            </footer>
        </dialog>
    `).appendTo($page);

    function showDialog(entry, opener) {
        activeSecret = entry;
        dialogOpener = opener || null;
        $dialog.find('.st-order-secret-trade').text(`${i18n('订单')} ${entry.tradeNo}`);
        $dialog.find('.st-order-secret-code').text(entry.secret);
        const dialog = $dialog.get(0);
        if (typeof dialog.showModal === 'function') {
            if (!dialog.open) dialog.showModal();
        } else {
            $dialog.attr('open', 'open');
        }
        window.setTimeout(() => $dialog.find('[data-st-secret-copy]').trigger('focus'), 40);
    }

    function closeDialog() {
        const dialog = $dialog.get(0);
        if (typeof dialog.close === 'function' && dialog.open) dialog.close();
        else $dialog.removeAttr('open');
        activeSecret = null;
        if (dialogOpener && document.contains(dialogOpener)) dialogOpener.focus({preventScroll: true});
        dialogOpener = null;
    }

    $page.on('click', '.st-order-secret-open', function () {
        const entry = secretRows.get(String($(this).attr('data-st-order-key') || ''));
        if (entry) showDialog(entry, this);
    });
    $dialog.on('click', '[data-st-secret-close]', closeDialog);
    $dialog.on('click', function (event) { if (event.target === this) closeDialog(); });
    $dialog.on('cancel', function (event) { event.preventDefault(); closeDialog(); });
    $dialog.on('click', '[data-st-secret-copy]', function () {
        if (!activeSecret) return;
        util.copyTextToClipboard(activeSecret.secret, () => message.success(i18n('卡密已复制')), () => message.error(i18n('复制失败，请重试')));
    });
    $dialog.on('click', '[data-st-secret-download]', function () {
        if (!activeSecret) return;
        const blob = new Blob([activeSecret.secret], {type: 'text/plain;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${i18n('卡密')}_${activeSecret.tradeNo.replace(/[^A-Za-z0-9_-]/g, '_') || 'export'}.txt`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    });

    $page.off('seattle:purchase:secret.seattlePurchaseTable').on('seattle:purchase:secret.seattlePurchaseTable', function (event, row, opener) {
        if (!row || Number(row.status) !== 1 || !row.secret) return;
        showDialog({secret: String(row.secret), tradeNo: String(row.trade_no || 'export')}, opener || null);
    });

    $page.find(tableSelector).attr('data-st-mobile-record', 'purchase');
    const table = new Table('/user/api/purchaseRecord/data', tableSelector);
    table.setColumns([
        {field: 'trade_no', title: i18n('订单号'), align: 'left', halign: 'left', formatter: (value, row) => `<span class="st-order-summary st-order-no"><span><strong>${escapeHtml(value || '—')}</strong><small>${i18n('下单')} ${escapeHtml(row && row.create_time ? row.create_time : '—')}</small></span></span>`},
        {field: 'commodity', title: i18n('商品'), align: 'left', halign: 'left', formatter: itemSummary},
        {field: 'sku', title: i18n('类别/SKU'), align: 'left', halign: 'left', formatter: skuSummary},
        {field: 'card_num', title: i18n('数量'), align: 'right', halign: 'right', formatter: value => escapeHtml(value == null ? '—' : value)},
        {field: 'amount', title: i18n('金额'), align: 'right', halign: 'right', formatter: amountSummary},
        {field: 'pay', title: i18n('支付方式'), align: 'left', halign: 'left', formatter: paySummary},
        {field: 'status', title: i18n('付款状态'), align: 'center', halign: 'center', dict: '_order_status'},
        {field: 'delivery_status', title: i18n('发货状态'), align: 'center', halign: 'center', dict: '_order_delivery_status'},
        {field: 'secret', title: i18n('操作'), align: 'center', halign: 'center', formatter: secretButton}
    ]);

    if (dashboard) {
        table.setPagination(5, [5]);
    } else {
        table.setSearch([
            {title: i18n('订单号'), name: 'equal-trade_no', default: util.getParam('tradeNo'), type: 'input'},
            {title: i18n('下单时间'), name: 'between-create_time', type: 'date'}
        ]);
        table.setState('status', '_order_status');
    }
    table.render();
}();
