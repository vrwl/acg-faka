//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

!function () {
    'use strict';

    const $page = $('[data-st-page="promote"]').first();
    if (!$page.length) return;

    const appMode = window.matchMedia
        ? window.matchMedia('(max-width: 767px), (max-height: 500px) and (max-width: 1024px)').matches
        : (window.innerWidth < 768 || (window.innerHeight <= 500 && window.innerWidth <= 1024));
    const $dialog = $page.find('[data-st-sku-dialog]').first();
    const $content = $dialog.find('[data-st-sku-content]');
    const $qrDialog = $page.find('[data-st-qrcode-dialog]').first();
    let requestSequence = 0;
    let lastRequest = null;
    let skuOpener = null;
    let qrOpener = null;
    let qrBodyOverflow = null;

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
    const moneyText = value => {
        const amount = Number(value);
        return Number.isFinite(amount) ? `${acgCurrencySymbol()}${amount.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}` : '—';
    };
    const safeImageUrl = value => {
        try {
            const url = new URL(String(value || '/favicon.ico'), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : new URL('/favicon.ico', window.location.origin).href;
        } catch (error) {
            return new URL('/favicon.ico', window.location.origin).href;
        }
    };

    function productSummary(row) {
        const id = Number(row && row.id) || 0;
        const name = row && row.name ? row.name : `${i18n('商品')} ${id}`;
        return `<span class="st-product-summary"><img src="${escapeHtml(safeImageUrl(row && row.cover))}" alt=""><span><strong>${safeInlineHtml(name)}</strong><small>${i18n('商品')} ID ${id}</small></span></span>`;
    }

    function renderQrcode($target, size) {
        if (!$target.length || typeof $target.qrcode !== 'function') return false;
        const text = String($target.attr('data-url') || '');
        if (!text) return false;
        $target.empty().qrcode({render: 'table', width: size, height: size, text: text});
        const $graphic = $target.find('table, canvas, img').first();
        $graphic.attr({'aria-hidden': 'true', width: size, height: size});
        return $graphic.length > 0;
    }

    $page.find('.clipboard').on('click', function () {
        const text = String($(this).data('text') || '');
        util.copyTextToClipboard(text, () => message.success(i18n('推广链接已复制')), () => message.error(i18n('复制失败，请手动选择推广链接')));
    });

    const $qr = $page.find('#share-qrcode').first();
    const qrReady = renderQrcode($qr, 56);
    $page.find('[data-st-qrcode-open]')
        .prop('disabled', !qrReady)
        .attr('aria-disabled', String(!qrReady))
        .attr('aria-label', qrReady ? i18n('放大推广链接二维码') : i18n('二维码暂时无法生成'));

    function restoreQrState() {
        if (qrBodyOverflow !== null) {
            document.body.style.overflow = qrBodyOverflow;
            qrBodyOverflow = null;
        }
        if (qrOpener && document.contains(qrOpener)) qrOpener.focus({preventScroll: true});
        qrOpener = null;
    }

    function closeQrDialog() {
        const dialog = $qrDialog.get(0);
        if (!dialog) {
            restoreQrState();
            return;
        }
        if (typeof dialog.close === 'function' && dialog.open) dialog.close();
        else {
            $qrDialog.removeAttr('open');
            restoreQrState();
        }
    }

    function openQrDialog(trigger) {
        const $large = $qrDialog.find('#share-qrcode-large').first();
        if (!renderQrcode($large, 220)) {
            message.error(i18n('二维码生成失败，请复制推广链接分享'));
            return;
        }
        const dialog = $qrDialog.get(0);
        if (!dialog) return;
        qrOpener = trigger;
        if (qrBodyOverflow === null) {
            qrBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        }
        if (typeof dialog.showModal === 'function') {
            if (!dialog.open) dialog.showModal();
        } else $qrDialog.attr('open', 'open');
        $qrDialog.find('[data-st-qrcode-close]').trigger('focus');
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
        requestSequence += 1;
        const dialog = $dialog.get(0);
        if (dialog) {
            if (typeof dialog.close === 'function' && dialog.open) dialog.close();
            else $dialog.removeAttr('open');
        }
        if (skuOpener && document.contains(skuOpener)) skuOpener.focus({preventScroll: true});
        skuOpener = null;
    }

    function renderLoading() {
        $content.empty().append(
            $('<div class="st-loading"></div>')
                .append('<span class="st-spinner" aria-hidden="true"></span>')
                .append($('<span></span>').text(i18n('正在读取 SKU 收益…')))
        );
    }

    function renderError(text) {
        const $error = $('<div class="st-error st-sku-error"></div>');
        $error.append('<span class="material-icons-outlined">error</span>');
        $error.append($('<strong></strong>').text(i18n('SKU 收益读取失败')));
        $error.append($('<p></p>').text(text));
        $error.append('<button type="button" class="st-button st-button--secondary" data-st-sku-retry><span class="material-icons-outlined">refresh</span>' + i18n('重新加载') + '</button>');
        $content.empty().append($error);
    }

    function appendMetric($container, label, value, className) {
        const $metric = $('<div class="st-sku-metric"></div>');
        $metric.append($('<span></span>').text(label));
        $metric.append($('<strong></strong>').addClass(className || '').text(value));
        $container.append($metric);
    }

    function renderSku(data, productName) {
        const list = Array.isArray(data && data.list) ? data.list : [];
        const $summary = $('<section class="st-sku-summary"></section>');
        $summary.append($('<span></span>').text(productName || i18n('当前商品')));
        const context = data && data.race ? `${i18n('类别：')}${data.race}` : i18n('标准类别');
        $summary.append($('<small></small>').text(context));
        $summary.append($('<strong></strong>').text(`${i18n('基准预计收益')} ${moneyText(data && data.base_profit)}`));

        const $list = $('<div class="st-sku-detail-list"></div>');
        list.forEach(row => {
            const delta = Number(row.delta);
            const deltaClass = delta > 0 ? 'is-up' : (delta < 0 ? 'is-down' : '');
            const $item = $('<article class="st-sku-detail-item"></article>');
            const $header = $('<header></header>');
            $header.append($('<span class="material-icons-outlined">tune</span>'));
            $header.append($('<span></span>').append($('<strong></strong>').text(row.group || 'SKU')).append($('<small></small>').text(row.option || i18n('默认选项'))));
            $item.append($header);
            const $metrics = $('<div class="st-sku-metrics"></div>');
            appendMetric($metrics, i18n('SKU 加价'), moneyText(row.premium));
            appendMetric($metrics, i18n('游客价'), moneyText(row.guest_price));
            appendMetric($metrics, i18n('我的拿货价'), moneyText(row.my_price));
            appendMetric($metrics, i18n('预计收益'), moneyText(row.profit), 'is-profit');
            appendMetric($metrics, i18n('收益变化'), Number.isFinite(delta) ? `${delta > 0 ? '+' : ''}${delta.toFixed(2)}` : '—', deltaClass);
            $item.append($metrics);
            $list.append($item);
        });

        $content.empty().append($summary);
        if (list.length) $content.append($list);
        else $content.append('<div class="st-empty"><span class="material-icons-outlined">layers_clear</span><strong>' + i18n('暂无 SKU 选项') + '</strong><p>' + i18n('该商品当前没有可比较的 SKU 加价。') + '</p></div>');
    }

    function loadSku(request) {
        lastRequest = request;
        const sequence = ++requestSequence;
        renderLoading();
        showDialog();

        const fail = text => {
            if (sequence !== requestSequence || !document.contains($page[0])) return;
            renderError(text);
        };

        util.post({
            url: '/user/api/promote/sku',
            data: {commodityId: request.id, race: request.race},
            loader: false,
            done: res => {
                if (sequence !== requestSequence || !document.contains($page[0])) return;
                renderSku(res && res.data ? res.data : {}, request.name);
            },
            error: res => fail(res && res.msg ? res.msg : i18n('服务器未返回有效数据')),
            fail: () => fail(i18n('网络连接失败，请稍后重试'))
        });
    }

    const columns = [
        {field: 'name', title: i18n('商品'), align: 'left', halign: 'left', formatter: (value, row) => productSummary(row)},
        {field: 'race', title: i18n('类别'), align: 'left', halign: 'left', formatter: value => value ? `<span class="st-chip">${escapeHtml(value)}</span>` : '<span class="st-chip">' + i18n('标准') + '</span>'},
        {
            field: 'sku_count', title: 'SKU', align: 'right', halign: 'right', formatter: (value, row) => {
                const count = Number(value) || 0;
                if (count <= 0) return '-';
                return `<button type="button" class="st-text-button sku-detail" data-id="${Number(row.id)}" data-race="${escapeHtml(row.race || '')}" data-name="${escapeHtml(plainText(row.name || ''))}">${count} ${i18n('组')}<span class="material-icons-outlined">info</span></button>`;
            }
        }
    ];
    columns.push({field: 'guest_price', title: i18n('游客成交价'), align: 'right', halign: 'right', formatter: moneyText});
    columns.push({field: 'my_price', title: i18n('我的拿货价'), align: 'right', halign: 'right', formatter: moneyText});
    columns.push({field: 'profit', title: i18n('预计收益'), align: 'right', halign: 'right', formatter: moneyText});
    columns.push({
        field: 'rate', title: i18n('收益率'), align: 'right', halign: 'right', formatter: value => {
            const rate = Number(value);
            return `<span class="st-chip">${escapeHtml(Number.isFinite(rate) ? `${rate}%` : '—')}</span>`;
        }
    });

    const table = new Table('/user/api/promote/data', '#promote-table');
    table.setColumns(columns);
    table.setSearch([{title: i18n('商品名称'), name: 'search-name', type: 'input'}]);
    table.setPagination(appMode ? 5 : 10, appMode ? [5] : [10, 20, 50]);
    table.render();

    $page.on('click', '.sku-detail', function () {
        skuOpener = this;
        loadSku({
            id: Number($(this).data('id')),
            race: String($(this).attr('data-race') || ''),
            name: String($(this).attr('data-name') || '')
        });
    });
    $page.on('seattle:promote:sku', function (event, row, trigger) {
        if (!row) return;
        skuOpener = trigger || null;
        loadSku({
            id: Number(row.id),
            race: String(row.race || ''),
            name: plainText(row.name || '')
        });
    });
    $page.on('click', '[data-st-sku-retry]', function () {
        if (lastRequest) loadSku(lastRequest);
    });
    $dialog.on('click', '[data-st-sku-close]', closeDialog);
    $dialog.on('click', function (event) {
        if (event.target === this) closeDialog();
    });
    $dialog.on('cancel close', function () { requestSequence += 1; });
    $page.on('click', '[data-st-qrcode-open]', function () { openQrDialog(this); });
    $qrDialog.on('click', '[data-st-qrcode-close]', closeQrDialog);
    $qrDialog.on('click', function (event) { if (event.target === this) closeQrDialog(); });
    $qrDialog.on('cancel', function (event) {
        event.preventDefault();
        closeQrDialog();
    });
    $qrDialog.on('close', restoreQrState);
    $(document)
        .off('pjax:send.seattlePromote pjax:popstate.seattlePromote')
        .on('pjax:send.seattlePromote pjax:popstate.seattlePromote', function () {
            closeQrDialog();
            $(document).off('.seattlePromote');
        });
}();
