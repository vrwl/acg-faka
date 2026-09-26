(function () {
    'use strict';

    var namespace = '.seattleTables';
    var mobileQuery = window.matchMedia ? window.matchMedia('(max-width: 767px), (max-height: 500px) and (max-width: 1024px)') : null;
    var scanQueued = false;
    var detailCaptureVersion = 0;
    var commodityState = {
        table: null,
        snapshot: null,
        source: null,
        row: null,
        rowIndex: -1,
        trigger: null,
        open: false
    };
    var mobilePrimaryFields = {
        'dashboard': ['trade_no', 'commodity', 'amount', 'status', 'delivery_status', 'secret'],
        'purchase-record': ['trade_no', 'commodity', 'amount', 'status', 'delivery_status', 'secret'],
        'bill': ['amount', 'type', 'currency', 'log', 'create_time'],
        'category': ['name', 'sort', 'status', 'operation'],
        'commodity': ['category.name', 'name', 'card_count', 'status', 'operation'],
        'card': ['secret', 'commodity', 'status', 'operation'],
        'coupon': ['code', 'mode', 'money', 'status', 'operation'],
        'order': ['trade_no', 'owner', 'commodity', 'amount', 'status', 'delivery_status', 'secret']
    };

    function all(selector, scope) {
        return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
    }

    function isMobile() {
        return mobileQuery ? mobileQuery.matches : (window.innerWidth < 768 || (window.innerHeight <= 500 && window.innerWidth <= 1024));
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    function cleanText(value, fallback) {
        var text = String(value == null ? '' : value).replace(/\s+/g, ' ').trim();
        return text || (fallback || '');
    }

    function nameText(value, fallback) {
        var text = window.SeattleTheme && typeof window.SeattleTheme.plainText === 'function'
            ? window.SeattleTheme.plainText(value)
            : String(value == null ? '' : value).replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
        return text || (fallback || '');
    }

    function safeInlineHtml(value) {
        return window.SeattleTheme && typeof window.SeattleTheme.safeInlineHtml === 'function'
            ? window.SeattleTheme.safeInlineHtml(value)
            : escapeHtml(value);
    }

    function commodityCategory(row) {
        return nameText(row && row.category && row.category.name != null ? row.category.name : (row && row['category.name']), i18n('未分类'));
    }

    function commodityPublished(row) {
        return String(row && row.status) === '1' || row && row.status === true;
    }

    function commodityAutomatic(row) {
        return String(row && row.delivery_way) === '0';
    }

    function commodityStock(row) {
        var value = commodityAutomatic(row) ? row && row.card_count : row && row.stock;
        return cleanText(value, '0');
    }

    function commoditySaleState(row) {
        if (!commodityPublished(row)) return {key: 'off', label: i18n('已下架')};
        var stock = Number(commodityStock(row));
        if (Number.isFinite(stock) && stock <= 0) return {key: 'low', label: i18n('待补货')};
        return {key: 'on', label: i18n('销售中')};
    }

    function commodityCover(row) {
        var cover = cleanText(row && row.cover);
        if (!cover || !/^(?:https?:\/\/|\/|\.\.\/|\.\/|data:image\/)/i.test(cover)) return '';
        return cover;
    }

    function focusCommodityStableControl() {
        var wrapper = commodityState.source && commodityState.source.closest('.bootstrap-table');
        var target = wrapper && wrapper.querySelector('.st-mobile-quick-search input, .st-mobile-filter-trigger, .table-switch-state button.active');
        if (target && target.isConnected) target.focus({preventScroll: true});
    }

    function closeCommoditySheet(restoreFocus) {
        var sheet = document.querySelector('[data-st-sheet="commodity-actions"]');
        if (sheet && (sheet.classList.contains('is-open') || commodityState.open)) {
            if (window.SeattleTheme && typeof window.SeattleTheme.closeSheets === 'function') {
                window.SeattleTheme.closeSheets();
            } else {
                sheet.classList.remove('is-open');
                sheet.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('st-sheet-open');
                var backdrop = document.querySelector('.st-sheet-backdrop');
                if (backdrop) backdrop.setAttribute('aria-hidden', 'true');
            }
        }
        commodityState.open = false;
        if (restoreFocus !== false && commodityState.trigger && commodityState.trigger.isConnected) commodityState.trigger.focus({preventScroll: true});
    }

    function ensureCommoditySheet() {
        var sheet = document.querySelector('[data-st-sheet="commodity-actions"]');
        if (!sheet) {
            sheet = document.createElement('section');
            sheet.className = 'st-sheet st-commodity-action-sheet';
            sheet.setAttribute('data-st-sheet', 'commodity-actions');
            sheet.setAttribute('aria-hidden', 'true');
            sheet.setAttribute('aria-labelledby', 'st-commodity-action-title');
            sheet.setAttribute('role', 'dialog');
            sheet.setAttribute('aria-modal', 'true');
            sheet.innerHTML = '<div class="st-sheet__handle" aria-hidden="true"></div>' +
                '<header><div class="st-commodity-sheet-heading"><span class="st-commodity-sheet-media material-icons-outlined" aria-hidden="true">inventory_2</span><span><strong id="st-commodity-action-title">' + i18n('商品操作') + '</strong><small data-st-commodity-sheet-subtitle></small></span></div><button type="button" class="st-icon-button" data-st-sheet-close aria-label="' + i18n('关闭商品操作') + '"><span class="material-icons-outlined" aria-hidden="true">close</span></button></header>' +
                '<dl class="st-commodity-sheet-summary" aria-label="' + i18n('商品摘要') + '"><div><dt>' + i18n('库存') + '</dt><dd data-st-commodity-summary-stock>0</dd></div><div><dt>' + i18n('已售') + '</dt><dd data-st-commodity-summary-sold>0</dd></div><div><dt>' + i18n('商品 ID') + '</dt><dd data-st-commodity-summary-id>—</dd></div></dl>' +
                '<div class="st-commodity-sheet-actions" role="group" aria-label="' + i18n('商品操作') + '"></div>';
            document.body.appendChild(sheet);
        }
        var trigger = document.querySelector('[data-st-commodity-sheet-trigger]');
        if (!trigger) {
            trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.hidden = true;
            trigger.setAttribute('data-st-commodity-sheet-trigger', '');
            trigger.setAttribute('data-st-sheet-open', 'commodity-actions');
            trigger.setAttribute('aria-expanded', 'false');
            document.body.appendChild(trigger);
        }
        return sheet;
    }

    function commodityActionVisible(id, row) {
        var actions = commodityState.snapshot && commodityState.snapshot.actions || [];
        var action = actions.find(function (item) { return item.id === id; });
        if (!action) return false;
        if (typeof action.show !== 'function') return true;
        try { return action.show(row) !== false; } catch (error) { return false; }
    }

    function appendCommodityAction(container, action, icon, label, modifier) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'st-commodity-sheet-action' + (modifier ? ' ' + modifier : '');
        button.setAttribute('data-st-commodity-action', action);
        button.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">' + escapeHtml(icon) + '</span><span>' + escapeHtml(label) + '</span>';
        container.appendChild(button);
    }

    function normalizeCommodityAddCard(source) {
        var wrapper = source && source.closest && source.closest('.bootstrap-table');
        all('.add-card', wrapper || document).forEach(function (button) {
            button.classList.add('st-commodity-add-card');
            button.textContent = i18n('加卡');
            button.setAttribute('title', i18n('为此商品加卡'));
            button.setAttribute('aria-label', i18n('为此商品加卡'));
        });
    }

    function fillCommoditySheet(row) {
        var sheet = ensureCommoditySheet();
        var name = nameText(row && row.name, i18n('未命名商品'));
        var category = commodityCategory(row);
        var automatic = commodityAutomatic(row);
        var cover = commodityCover(row);
        var media = sheet.querySelector('.st-commodity-sheet-media');
        media.className = 'st-commodity-sheet-media' + (cover ? '' : ' material-icons-outlined');
        if (cover) media.innerHTML = '<img src="' + escapeHtml(cover) + '" alt="">';
        else media.textContent = 'inventory_2';
        sheet.querySelector('#st-commodity-action-title').textContent = name;
        sheet.querySelector('[data-st-commodity-sheet-subtitle]').textContent = category + ' · ' + (automatic ? i18n('自动发货') : i18n('在线发货'));
        sheet.querySelector('[data-st-commodity-summary-stock]').textContent = commodityStock(row);
        sheet.querySelector('[data-st-commodity-summary-sold]').textContent = cleanText(row && row.card_success_count, '0');
        sheet.querySelector('[data-st-commodity-summary-id]').textContent = cleanText(row && row.id, '—');

        var actions = sheet.querySelector('.st-commodity-sheet-actions');
        actions.innerHTML = '';
        if (commodityActionVisible('operation:0', row)) appendCommodityAction(actions, 'edit', 'edit', i18n('编辑商品'), 'is-primary');
        if (automatic) appendCommodityAction(actions, 'upload', 'upload_file', i18n('加卡'));
        if (commodityActionVisible('share_url:0', row)) appendCommodityAction(actions, 'share', 'content_copy', i18n('复制推广链接'));
        appendCommodityAction(actions, 'status', commodityPublished(row) ? 'visibility_off' : 'visibility', commodityPublished(row) ? i18n('下架商品') : i18n('上架商品'), commodityPublished(row) ? 'is-warning' : 'is-primary');
        if (commodityActionVisible('operation:1', row)) appendCommodityAction(actions, 'clone', 'copy_all', i18n('克隆商品'));
        if (commodityState.snapshot && commodityState.snapshot.detail && commodityState.snapshot.detail.enabled) appendCommodityAction(actions, 'detail', 'info', i18n('查看完整信息'));
        if (commodityActionVisible('operation:2', row)) appendCommodityAction(actions, 'delete', 'delete', i18n('删除商品'), 'is-danger is-wide');
        return sheet;
    }

    function openCommoditySheet(button) {
        var index = Number(button.getAttribute('data-st-commodity-index'));
        var rows = commodityState.snapshot && commodityState.snapshot.rows || [];
        var row = rows[index];
        if (!row) return;
        commodityState.row = row;
        commodityState.rowIndex = index;
        commodityState.trigger = button;
        fillCommoditySheet(row);
        var trigger = document.querySelector('[data-st-commodity-sheet-trigger]');
        if (trigger) trigger.click();
        commodityState.open = true;
        window.requestAnimationFrame(function () {
            var first = document.querySelector('[data-st-sheet="commodity-actions"].is-open [data-st-commodity-action]');
            if (first) first.focus({preventScroll: true});
        });
    }

    function invokeCommodityAction(action) {
        var row = commodityState.row;
        var table = commodityState.table;
        var snapshot = commodityState.snapshot;
        if (!row || !table) return;
        var upload = null;
        if (action === 'upload') {
            upload = all('.add-card[data-id]', commodityState.source && commodityState.source.closest('.bootstrap-table') || document).find(function (button) {
                return String(button.getAttribute('data-id')) === String(row.id);
            });
        }
        var opensFollowup = ['edit', 'clone', 'delete', 'upload', 'detail'].indexOf(action) >= 0;
        var usesStableFocus = action === 'status';
        closeCommoditySheet(!opensFollowup && !usesStableFocus);
        if (usesStableFocus) focusCommodityStableControl();
        window.setTimeout(function () {
            if (action === 'edit') table.runAction('operation:0', row);
            else if (action === 'clone') table.runAction('operation:1', row);
            else if (action === 'delete') table.runAction('operation:2', row);
            else if (action === 'share') table.runAction('share_url:0', row);
            else if (action === 'upload') {
                if (upload) upload.click();
                else {
                    var temporary = document.createElement('button');
                    temporary.type = 'button';
                    temporary.hidden = true;
                    temporary.className = 'add-card';
                    temporary.setAttribute('data-id', cleanText(row.id));
                    document.body.appendChild(temporary);
                    temporary.click();
                    window.setTimeout(function () { temporary.remove(); }, 0);
                }
            }
            else if (action === 'status') table.updateField(row, 'status', commodityPublished(row) ? 0 : 1, {reload: true});
            else if (action === 'detail' && snapshot && snapshot.detail && typeof snapshot.detail.open === 'function') snapshot.detail.open(row);
        }, 0);
    }

    function commodityStateMarkup(type, icon, title, detail, action) {
        return '<div class="st-commodity-mobile-state is-' + type + '" role="status"><span class="' + (type === 'loading' ? 'st-commodity-mobile-spinner' : 'material-icons-outlined') + '" aria-hidden="true">' + (type === 'loading' ? '' : escapeHtml(icon)) + '</span><strong>' + escapeHtml(title) + '</strong><small>' + escapeHtml(detail) + '</small>' + (action ? '<button type="button" class="st-button st-button-quiet" data-st-commodity-retry><span class="material-icons-outlined" aria-hidden="true">refresh</span><span>' + i18n('重新加载') + '</span></button>' : '') + '</div>';
    }

    function renderCommoditySnapshot() {
        var snapshot = commodityState.snapshot;
        var source = commodityState.source;
        if (!snapshot || !source || !source.isConnected || !isMobile()) return;
        var page = source.closest('[data-st-page="commodity"]');
        var wrapper = source.closest('.bootstrap-table');
        var container = wrapper && wrapper.querySelector(':scope > .fixed-table-container');
        if (!page || !wrapper || !container) return;
        var list = wrapper.querySelector(':scope > .st-commodity-mobile-list');
        if (!list) {
            list = document.createElement('section');
            list.className = 'st-commodity-mobile-list';
            list.setAttribute('aria-label', i18n('商品列表'));
            list.setAttribute('aria-live', 'polite');
            container.insertAdjacentElement('afterend', list);
        }
        wrapper.classList.add('st-commodity-mobile-mounted');
        var status = snapshot.status || {};
        var rows = Array.isArray(snapshot.rows) ? snapshot.rows : [];
        if (status.loading) {
            list.innerHTML = commodityStateMarkup('loading', '', i18n('正在加载商品'), i18n('请稍候…'));
            return;
        }
        if (status.error) {
            list.innerHTML = commodityStateMarkup('error', 'error_outline', i18n('商品加载失败'), i18n('请检查网络后重试'), true);
            return;
        }
        if (!rows.length) {
            list.innerHTML = commodityStateMarkup('empty', 'inventory_2', i18n('暂无商品'), i18n('添加商品后会显示在这里'));
            return;
        }
        list.innerHTML = '<ul class="st-commodity-mobile-items">' + rows.map(function (row, index) {
            var name = nameText(row && row.name, i18n('未命名商品'));
            var nameHtml = safeInlineHtml(row && row.name ? row.name : i18n('未命名商品'));
            var category = commodityCategory(row);
            var stock = commodityStock(row);
            var saleState = commoditySaleState(row);
            var cover = commodityCover(row);
            var media = cover ? '<img src="' + escapeHtml(cover) + '" alt="" loading="lazy">' : '<span class="material-icons-outlined" aria-hidden="true">inventory_2</span>';
            return '<li><button type="button" class="st-commodity-mobile-row" data-st-commodity-index="' + index + '" aria-label="' + escapeHtml(name + '，' + category + '，' + saleState.label + i18n('，库存 {n}').replace('{n}', stock) + i18n('，打开商品操作')) + '">' +
                '<span class="st-commodity-mobile-icon">' + media + '</span>' +
                '<span class="st-commodity-mobile-copy"><strong>' + nameHtml + '</strong><small>' + escapeHtml(category) + '</small></span>' +
                '<span class="st-commodity-mobile-aside"><span class="st-commodity-mobile-status is-' + saleState.key + '">' + saleState.label + '</span><small>' + i18n('库存') + ' <strong>' + escapeHtml(stock) + '</strong></small></span>' +
                '<span class="material-icons-outlined st-commodity-mobile-chevron" aria-hidden="true">chevron_right</span></button></li>';
        }).join('') + '</ul>';
    }

    function teardownCommodityPresentation(removeGlobal) {
        all('.st-commodity-mobile-list').forEach(function (list) { list.remove(); });
        all('.bootstrap-table.st-commodity-mobile-mounted').forEach(function (wrapper) { wrapper.classList.remove('st-commodity-mobile-mounted'); });
        closeCommoditySheet(false);
        if (removeGlobal) {
            var sheet = document.querySelector('[data-st-sheet="commodity-actions"]');
            var trigger = document.querySelector('[data-st-commodity-sheet-trigger]');
            if (sheet) sheet.remove();
            if (trigger) trigger.remove();
            commodityState.table = null;
            commodityState.snapshot = null;
            commodityState.source = null;
            commodityState.row = null;
            commodityState.rowIndex = -1;
            commodityState.trigger = null;
        }
    }

    function handleCommodityLifecycle(event, payload) {
        var detail = payload || event && event.detail || {};
        var snapshot = detail.snapshot;
        var table = detail.table;
        var source = snapshot && snapshot.element || event && event.target;
        if (!snapshot || !table || !source || !source.closest || !source.closest('[data-st-page="commodity"]')) return;
        commodityState.table = table;
        commodityState.snapshot = snapshot;
        commodityState.source = source;
        normalizeCommodityAddCard(source);
        if (isMobile()) renderCommoditySnapshot();
        else teardownCommodityPresentation(false);
    }

    function tablePage(table) {
        var page = table && table.closest ? table.closest('[data-st-page]') : null;
        return page ? page.getAttribute('data-st-page') || '' : '';
    }

    function markTreeCell(cell, row, header) {
        var indents = all('.treegrid-indent', cell);
        var expander = cell.querySelector('.treegrid-expander');
        if (!indents.length && !expander) return;

        cell.classList.add('st-cell-tree');
        row.classList.add('st-row-tree');
        cell.setAttribute('data-st-tree-depth', String(indents.length));
        if (header && header.field) row.setAttribute('data-st-tree-field', header.field);
        if (expander) {
            var expandable = expander.classList.contains('treegrid-expander-expanded') || expander.classList.contains('treegrid-expander-collapsed');
            if (!expandable) {
                row.setAttribute('data-st-tree-leaf', 'true');
                row.removeAttribute('data-st-tree-expanded');
                expander.removeAttribute('role');
                expander.removeAttribute('tabindex');
                expander.removeAttribute('aria-expanded');
                expander.removeAttribute('aria-label');
                return;
            }
            var expanded = expander.classList.contains('treegrid-expander-expanded');
            row.removeAttribute('data-st-tree-leaf');
            row.setAttribute('data-st-tree-expanded', expanded ? 'true' : 'false');
            expander.setAttribute('role', 'button');
            expander.setAttribute('tabindex', '0');
            expander.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            expander.setAttribute('aria-label', expanded ? i18n('收起子分类') : i18n('展开子分类'));
        }
    }

    function moreMode(row, page) {
        if (!secondaryLines(row).length) return '';
        if (page === 'order' || page === 'coupon') return 'hover-detail';
        return 'cell-detail';
    }

    function ensureMoreCell(row, table, page) {
        var cell = row.querySelector(':scope > .st-cell-mobile-more');
        if (['commodity', 'category', 'card', 'coupon', 'order'].indexOf(page) >= 0) {
            if (cell) cell.remove();
            return;
        }
        if (!isMobile()) {
            if (cell) cell.remove();
            return;
        }
        var mode = moreMode(row, page);
        if (!mode) {
            if (cell) cell.remove();
            return;
        }
        if (!cell) {
            cell = document.createElement('td');
            cell.className = 'st-cell-mobile-more st-cell-actions';
            cell.setAttribute('data-label', i18n('更多信息'));
            cell.setAttribute('data-field', 'seattle_mobile_more');
            cell.innerHTML = '<button type="button" class="st-table-more-button" aria-expanded="false"><span class="material-icons-outlined" aria-hidden="true">expand_more</span><span>' + i18n('更多信息') + '</span></button><div class="st-table-more-panel" hidden aria-live="polite"></div>';
            row.appendChild(cell);
        }
        cell.setAttribute('data-st-more-mode', mode);
        cell.hidden = false;
        var button = cell.querySelector('.st-table-more-button');
        if (button) button.setAttribute('aria-label', i18n('查看本条记录的更多信息'));
    }

    function normalizeInlineActionCell(cell, page, field) {
        if (page !== 'commodity' || field !== 'card_count') return false;
        cell.classList.remove('st-cell-actions');
        cell.classList.add('st-cell-inline-action');
        var wrapper = cell.querySelector(':scope > .st-stock-cell');
        if (!wrapper) {
            wrapper = document.createElement('span');
            wrapper.className = 'st-stock-cell';
            while (cell.firstChild) wrapper.appendChild(cell.firstChild);
            cell.appendChild(wrapper);
        }
        return true;
    }

    function decorate(table) {
        if (!table || !table.tBodies || !table.tBodies.length) return;
        var headers = all('thead th', table).map(function (cell) {
            return {
                field: cell.getAttribute('data-field') || '',
                label: (cell.textContent || '').replace(/\s+/g, ' ').trim() || i18n('信息')
            };
        });
        var page = tablePage(table);
        table.classList.add('st-data-table');
        if (page) table.setAttribute('data-st-table-page', page);

        all('tbody tr', table).forEach(function (row) {
            if (row.classList.contains('no-records-found') || row.classList.contains('detail-view')) return;
            row.classList.add('st-data-row');
            all(':scope > td:not(.st-cell-mobile-more)', row).forEach(function (cell, index) {
                var header = headers[index] || {field: '', label: i18n('信息')};
                if (header.field) cell.setAttribute('data-field', header.field);
                cell.setAttribute('data-label', header.label);
                if (cell.classList.contains('bs-checkbox') || cell.querySelector('input[name="btSelectItem"]')) cell.classList.add('st-cell-select');
                var inlineAction = normalizeInlineActionCell(cell, page, header.field);
                if (!inlineAction && (/operation|action|operate/.test(header.field) || cell.querySelector('.a-badge-glass,button'))) cell.classList.add('st-cell-actions');
                var primary = mobilePrimaryFields[page];
                if (primary && header.field && primary.indexOf(header.field) < 0 && !cell.classList.contains('st-cell-select') && !cell.classList.contains('st-cell-actions')) {
                    cell.classList.add('st-cell-mobile-secondary');
                } else {
                    cell.classList.remove('st-cell-mobile-secondary');
                }
                markTreeCell(cell, row, header);
            });

            var detailTrigger = row.querySelector('.md-detail-trigger');
            if (detailTrigger) {
                detailTrigger.setAttribute('data-st-column-detail', 'true');
                detailTrigger.setAttribute('title', i18n('查看完整信息'));
            }
            ensureMoreCell(row, table, page);
        });
    }

    function scan(scope) {
        all('table', scope || document).forEach(decorate);
        adoptLegacyFloatHandlers();
    }

    function queueScan() {
        if (scanQueued) return;
        scanQueued = true;
        window.requestAnimationFrame(function () {
            scanQueued = false;
            scan(document);
        });
    }

    function legacyHandlers(type, predicate) {
        if (!window.jQuery || typeof window.jQuery._data !== 'function') return [];
        var events = window.jQuery._data(document, 'events') || {};
        return (events[type] || []).filter(predicate);
    }

    function adoptLegacyFloatHandlers() {
        if (!window.jQuery) return;
        var $document = window.jQuery(document);
        var clickHandlers = legacyHandlers('click', function (handle) {
            return handle.selector === '.lock-hotkeys-cancel' && String(handle.namespace || '').indexOf('seattleLegacyFloat') < 0;
        });
        var keyHandlers = legacyHandlers('keydown', function (handle) {
            if (String(handle.namespace || '').indexOf('seattleLegacyFloat') >= 0) return false;
            var source = '';
            try { source = Function.prototype.toString.call(handle.handler); } catch (error) {}
            return source.indexOf('lock-hotkeys') >= 0 && (source.indexOf('Control') >= 0 || source.indexOf('Shift') >= 0);
        });
        if (!clickHandlers.length && !keyHandlers.length) return;

        $document.off('.seattleLegacyFloat');
        clickHandlers.forEach(function (handle) { $document.off('click', handle.selector, handle.handler); });
        keyHandlers.forEach(function (handle) { $document.off('keydown', handle.handler); });
        if (clickHandlers.length) $document.on('click.seattleLegacyFloat', '.lock-hotkeys-cancel', clickHandlers[clickHandlers.length - 1].handler);
        if (keyHandlers.length) $document.on('keydown.seattleLegacyFloat', keyHandlers[keyHandlers.length - 1].handler);
    }

    function closeCapturedTips(tips) {
        tips.forEach(function (tip) {
            var index = Number(tip.getAttribute('times')) || 0;
            if (index && window.layer && typeof window.layer.close === 'function') window.layer.close(index);
            else tip.remove();
        });
    }

    function tipLines(tip) {
        var content = tip && tip.querySelector ? tip.querySelector('.layui-layer-content') : null;
        if (!content) return [];
        var clone = content.cloneNode(true);
        all('.lock-hotkeys, .layui-layer-TipsG, script, style', clone).forEach(function (node) { node.remove(); });
        all('br', clone).forEach(function (node) { node.replaceWith(document.createTextNode('\n')); });
        return String(clone.innerText || clone.textContent || '')
            .split(/\n+/)
            .map(function (line) { return line.replace(/\s+/g, ' ').trim(); })
            .filter(Boolean);
    }

    function secondaryLines(row) {
        return all(':scope > td.st-cell-mobile-secondary', row).map(function (cell) {
            var label = String(cell.getAttribute('data-label') || i18n('信息')).replace(/\s+/g, ' ').trim();
            var value = String(cell.innerText || cell.textContent || '').replace(/\s+/g, ' ').trim();
            return value && value !== '-' ? label + '：' + value : '';
        }).filter(Boolean);
    }

    function uniqueLines(lines) {
        var seen = Object.create(null);
        return lines.filter(function (line) {
            var key = String(line || '').trim();
            if (!key || seen[key]) return false;
            seen[key] = true;
            return true;
        });
    }

    function renderDetailLines(panel, lines, row) {
        var content = uniqueLines(lines);
        if (!content.length) content = [i18n('暂无更多信息')];
        panel.innerHTML = '<div class="st-table-more-list">' + content.map(function (line) {
            var separator = line.indexOf('：');
            if (separator > 0) return '<div><span>' + escapeHtml(line.slice(0, separator)) + '</span><strong>' + escapeHtml(line.slice(separator + 1)) + '</strong></div>';
            return '<div><strong>' + escapeHtml(line) + '</strong></div>';
        }).join('') + '</div>' + (row && row.querySelector('.md-detail-trigger') ? '<button type="button" class="st-button st-button-quiet st-table-column-detail"><span class="material-icons-outlined" aria-hidden="true">open_in_new</span><span>' + i18n('查看完整商品信息') + '</span></button>' : '');
    }

    function captureHoverDetails(row, panel, button) {
        var capture = detailCaptureVersion;
        var existing = new Set(all('.layui-layer-tips'));
        var fallbackLines = secondaryLines(row);
        panel.hidden = false;
        panel.innerHTML = '<span class="st-table-more-loading">' + i18n('正在读取更多信息…') + '</span>';
        button.disabled = true;
        window.jQuery(row).trigger('mouseenter');
        all('.layui-layer-tips').filter(function (tip) { return !existing.has(tip); }).forEach(function (tip) {
            tip.style.visibility = 'hidden';
            tip.setAttribute('data-st-captured-tip', 'true');
        });

        window.setTimeout(function () {
            var created = all('.layui-layer-tips').filter(function (tip) { return !existing.has(tip); });
            if (capture !== detailCaptureVersion || !document.contains(row) || !isMobile()) {
                closeCapturedTips(created);
                window.jQuery(row).trigger('mouseleave');
                button.disabled = false;
                panel.hidden = true;
                panel.innerHTML = '';
                panel.removeAttribute('data-st-loaded');
                return;
            }
            var lines = fallbackLines.concat(created.length ? tipLines(created[created.length - 1]) : []);
            closeCapturedTips(created);
            window.jQuery(row).trigger('mouseleave');
            button.disabled = false;
            renderDetailLines(panel, lines, row);
        }, 320);
    }

    function toggleMore(button) {
        var cell = button.closest('.st-cell-mobile-more');
        var row = button.closest('tr');
        var panel = cell && cell.querySelector('.st-table-more-panel');
        if (!cell || !row || !panel) return;
        var mode = cell.getAttribute('data-st-more-mode');
        var opening = panel.hidden;
        if (!opening) {
            panel.hidden = true;
            panel.innerHTML = '';
            panel.removeAttribute('data-st-loaded');
            button.setAttribute('aria-expanded', 'false');
            button.classList.remove('is-open');
            return;
        }
        button.setAttribute('aria-expanded', 'true');
        button.classList.add('is-open');
        if (panel.getAttribute('data-st-loaded') === '1') {
            panel.hidden = false;
            return;
        }
        panel.setAttribute('data-st-loaded', '1');
        if (mode === 'hover-detail') {
            captureHoverDetails(row, panel, button);
        } else {
            panel.hidden = false;
            renderDetailLines(panel, secondaryLines(row), row);
        }
    }

    function syncMobileRows() {
        var mobile = isMobile();
        all('.st-cell-mobile-more').forEach(function (cell) {
            if (!mobile) {
                var panel = cell.querySelector('.st-table-more-panel');
                var button = cell.querySelector('.st-table-more-button');
                if (panel) {
                    panel.hidden = true;
                    panel.innerHTML = '';
                    panel.removeAttribute('data-st-loaded');
                }
                if (button) {
                    button.classList.remove('is-open');
                    button.setAttribute('aria-expanded', 'false');
                }
                cell.remove();
                return;
            }
            cell.hidden = false;
        });
        if (mobile) {
            queueScan();
            renderCommoditySnapshot();
        } else {
            teardownCommodityPresentation(false);
        }
    }

    function clearTransientState() {
        detailCaptureVersion += 1;
        all('.st-table-more-panel').forEach(function (panel) {
            panel.hidden = true;
            panel.innerHTML = '';
            panel.removeAttribute('data-st-loaded');
        });
        all('.layui-layer-tips').forEach(function (tip) {
            if (tip.hasAttribute('data-st-captured-tip') || tip.querySelector('.lock-hotkeys')) closeCapturedTips([tip]);
        });
        if (window.jQuery) window.jQuery(document).off('.seattleLegacyFloat');
        teardownCommodityPresentation(true);
    }

    if (window.jQuery) {
        var $document = window.jQuery(document);
        $document.off(namespace);
        $document.on('click.seattleTables', '.st-cell-select', function (event) {
            if (event.target.matches('input[type="checkbox"]')) return;
            var checkbox = this.querySelector('input[type="checkbox"]');
            if (checkbox && !checkbox.disabled) checkbox.click();
        });
        $document.on('click.seattleTables', '.st-table-more-button', function () { toggleMore(this); });
        $document.on('click.seattleTables', '.st-commodity-mobile-row', function () { openCommoditySheet(this); });
        $document.on('click.seattleTables', '[data-st-commodity-action]', function () { invokeCommodityAction(this.getAttribute('data-st-commodity-action')); });
        $document.on('click.seattleTables', '[data-st-commodity-retry]', function () {
            var retry = commodityState.snapshot && commodityState.snapshot.status && commodityState.snapshot.status.retry;
            if (typeof retry === 'function') retry();
        });
        $document.on('click.seattleTables', '[data-st-sheet-close]', function () {
            if (!commodityState.open) return;
            commodityState.open = false;
            window.setTimeout(function () {
                if (commodityState.trigger && commodityState.trigger.isConnected) commodityState.trigger.focus({preventScroll: true});
            }, 0);
        });
        $document.on('keydown.seattleTables', '[data-st-sheet="commodity-actions"]', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeCommoditySheet(true);
                return;
            }
            if (event.key !== 'Tab') return;
            var focusable = all('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', this).filter(function (node) { return !node.hidden; });
            if (!focusable.length) return;
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
        $document.on('click.seattleTables', '.st-table-column-detail', function () {
            var row = this.closest('tr');
            var trigger = row && row.querySelector('.md-detail-trigger');
            if (trigger) window.jQuery(trigger).trigger('dblclick');
        });
        $document.on('keydown.seattleTables', '.treegrid-expander[role="button"]', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            this.click();
            window.setTimeout(queueScan, 0);
        });
        $document.on('post-body.bs.table.seattleTables load-success.bs.table.seattleTables reset-view.bs.table.seattleTables page-change.bs.table.seattleTables search.bs.table.seattleTables', function () {
            queueScan();
            window.setTimeout(adoptLegacyFloatHandlers, 0);
        });
        $document.on('admin:table:ready.seattleTables admin:table:update.seattleTables', handleCommodityLifecycle);
        $document.on('admin:table:destroy.seattleTables', function (event, payload) {
            var detail = payload || event && event.detail || {};
            if (detail.table && detail.table === commodityState.table) teardownCommodityPresentation(true);
        });
        $document.on('pjax:send.seattleTables pjax:popstate.seattleTables', clearTransientState);
        $document.on('pjax:end.seattleTables', queueScan);
    }

    if (window.__seattleTablesPageReadyHandler) document.removeEventListener('seattle:page-ready', window.__seattleTablesPageReadyHandler);
    window.__seattleTablesPageReadyHandler = queueScan;
    document.addEventListener('seattle:page-ready', queueScan);
    if (window.__seattleTablesResizeHandler) window.removeEventListener('resize', window.__seattleTablesResizeHandler);
    window.__seattleTablesResizeHandler = syncMobileRows;
    window.addEventListener('resize', syncMobileRows, {passive: true});

    if (window.__seattleTablesObserver) window.__seattleTablesObserver.disconnect();
    if (window.MutationObserver && document.body) {
        window.__seattleTablesObserver = new MutationObserver(queueScan);
        window.__seattleTablesObserver.observe(document.body, {childList: true, subtree: true});
    }

    scan(document);
}());
