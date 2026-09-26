//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

(function () {
    'use strict';

    var namespace = '.seattleMobileRecords';
    var supportedPages = ['category', 'card', 'coupon', 'order', 'purchase', 'master-category', 'master-commodity', 'bill', 'cash-record', 'message', 'promote'];
    var mobileQuery = window.matchMedia
        ? window.matchMedia('(max-width: 767px), (max-height: 500px) and (max-width: 1024px)')
        : null;
    var presenters = new Map();
    var activeRecord = null;
    var activeActions = [];
    var operationEpoch = 0;

    function all(selector, scope) {
        return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
    }

    function isMobile() {
        return mobileQuery ? mobileQuery.matches : (window.innerWidth < 768 || (window.innerHeight <= 500 && window.innerWidth <= 1024));
    }

    function sourceTableFor(node) {
        var wrapper = node && node.closest && node.closest('.bootstrap-table');
        if (!wrapper) return null;
        return wrapper.querySelector('.fixed-table-body > table')
            || all('table[id]', wrapper).find(function (table) { return Boolean(table.id); })
            || null;
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

    function valueAt(source, path) {
        return String(path || '').split('.').reduce(function (value, key) {
            return value == null ? undefined : value[key];
        }, source);
    }

    function safeImage(value) {
        var image = cleanText(value);
        return /^(?:https?:\/\/|\/|\.\.\/|\.\/|data:image\/)/i.test(image) ? image : '';
    }

    function asObject(value) {
        if (value && typeof value === 'object') return value;
        if (typeof value !== 'string' || !value.trim()) return {};
        try {
            var parsed = JSON.parse(value);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function shortDate(value) {
        var text = cleanText(value);
        if (!text) return '—';
        return text.length >= 10 ? text.slice(0, 10) : text;
    }

    function money(value) {
        var amount = Number(value);
        if (!Number.isFinite(amount)) return acgCurrencySymbol() + '0';
        return acgCurrencySymbol() + String(Number(amount.toFixed(2)));
    }

    function formattedNumber(value, minimumFractionDigits) {
        var amount = Number(value);
        if (!Number.isFinite(amount)) return '0';
        return amount.toLocaleString('zh-CN', {
            minimumFractionDigits: minimumFractionDigits || 0,
            maximumFractionDigits: 2
        });
    }

    function walletValue(value, currency, signedType) {
        var prefix = signedType === 0 ? '-' : (signedType === 1 ? '+' : '');
        return prefix + (Number(currency) === 1
            ? formattedNumber(value) + ' ' + i18n('硬币')
            : acgCurrencySymbol() + formattedNumber(value, 2));
    }

    function displayText(snapshot, field, row, index, fallback) {
        if (snapshot && typeof snapshot.displayValue === 'function') {
            try {
                var value = nameText(snapshot.displayValue(field, row, index));
                if (value) return value;
            } catch (error) {}
        }
        return fallback || '—';
    }

    function maskedSecret(value, fallback) {
        var secret = cleanText(value);
        if (!secret) return fallback || i18n('未填写卡密');
        if (secret.length <= 4) return '••••';
        if (secret.length <= 9) return secret.slice(0, 2) + '••••' + secret.slice(-2);
        return secret.slice(0, 4) + '••••' + secret.slice(-4);
    }

    function fullyMaskedSecret(value, fallback) {
        return cleanText(value) ? '••••••' : (fallback || '—');
    }

    function tailMaskedSecret(value, fallback) {
        var secret = cleanText(value);
        if (!secret) return fallback || '—';
        if (secret.length <= 4) return '••••';
        return '••••' + secret.slice(-Math.min(4, Math.max(2, Math.floor(secret.length / 3))));
    }

    function deviceLabel(value) {
        return ({0: 'PC', 1: i18n('安卓'), 2: 'IOS', 3: 'iPad'})[Number(value)] || i18n('未知设备');
    }

    function categoryEnabled(row) {
        return Boolean(row && (row.status === true || Number(row.status) === 1));
    }

    function isTreePage(page) {
        return page === 'category' || page === 'master-category';
    }

    function masterVisible(row, relationName) {
        var relation = row && row[relationName];
        return !relation || Number(relation.status) === 1;
    }

    function skuSummary(row) {
        var parts = [];
        var race = cleanText(row && row.race);
        if (race) parts.push(race);
        var sku = asObject(row && row.sku);
        Object.keys(sku).slice(0, 2).forEach(function (key) {
            parts.push(cleanText(key) + ' ' + cleanText(sku[key]));
        });
        return parts.join(' · ');
    }

    function actionFor(snapshot, id, row) {
        var action = (snapshot && snapshot.actions || []).find(function (item) { return item.id === id; });
        if (!action) return null;
        if (typeof action.show !== 'function') return action;
        try { return action.show(row) === false ? null : action; } catch (error) { return null; }
    }

    function selectionFor(snapshot, row, index) {
        var selection = snapshot && snapshot.selection || {};
        var state = (selection.rowStates || [])[index] || {};
        var rowId = cleanText(row && row.id);
        var selected = (selection.rows || []).some(function (selectedRow) {
            return selectedRow === row || (rowId && cleanText(selectedRow && selectedRow.id) === rowId);
        });
        return {enabled: Boolean(selection.enabled), selectable: state.selectable !== false, selected: selected};
    }

    function categoryEntries(rows, collapsedCategories) {
        collapsedCategories = collapsedCategories || new Set();
        var byId = new Map();
        var children = new Map();
        var roots = [];
        var visited = new Set();
        var visiting = new Set();
        var entries = [];

        rows.forEach(function (row, index) {
            var id = cleanText(row && row.id, 'row-' + index);
            byId.set(id, {row: row, index: index, id: id});
        });
        byId.forEach(function (item) {
            var pid = cleanText(item.row && item.row.pid);
            if (!pid || pid === '0' || pid === item.id || !byId.has(pid)) roots.push(item);
            else {
                if (!children.has(pid)) children.set(pid, []);
                children.get(pid).push(item);
            }
        });

        function markHidden(item) {
            if (!item || visited.has(item.id) || visiting.has(item.id)) return;
            visited.add(item.id);
            (children.get(item.id) || []).forEach(markHidden);
        }

        function walk(item, depth, parentName) {
            if (!item || visited.has(item.id) || visiting.has(item.id)) return;
            visiting.add(item.id);
            var branch = children.get(item.id) || [];
            entries.push({
                row: item.row,
                index: item.index,
                id: item.id,
                depth: Math.min(depth, 5),
                parentName: parentName,
                hasChildren: branch.length > 0,
                childrenCount: branch.length,
                expanded: !collapsedCategories.has(item.id)
            });
            if (!collapsedCategories.has(item.id)) {
                branch.forEach(function (child) { walk(child, depth + 1, nameText(item.row && item.row.name, i18n('上级分类'))); });
            } else branch.forEach(markHidden);
            visiting.delete(item.id);
            visited.add(item.id);
        }

        roots.forEach(function (root) { walk(root, 0, ''); });
        byId.forEach(function (item) { walk(item, 0, ''); });
        return entries;
    }

    function categoryView(entry) {
        var row = entry.row;
        var enabled = categoryEnabled(row);
        var level = entry.depth === 0 ? i18n('一级分类') : i18n('第 {n} 级分类').replace('{n}', entry.depth + 1);
        return {
            page: 'category',
            image: safeImage(row.icon),
            icon: entry.hasChildren ? 'folder' : 'subdirectory_arrow_right',
            eyebrow: level,
            title: nameText(row.name, i18n('未命名分类')),
            titleHtml: safeInlineHtml(row.name || i18n('未命名分类')),
            subtitle: (entry.parentName || i18n('根目录')) + ' · #' + cleanText(row.id, '—'),
            status: {label: enabled ? i18n('已启用') : i18n('未启用'), key: enabled ? 'on' : 'off'},
            metric: i18n('排序 {n}').replace('{n}', cleanText(row.sort, '—')),
            summary: [[i18n('排序'), cleanText(row.sort, '—')], [i18n('层级'), level], [i18n('状态'), enabled ? i18n('已启用') : i18n('未启用')]],
            details: [[i18n('上级分类'), entry.parentName || i18n('根目录')], [i18n('子分类'), entry.childrenCount ? i18n('{n} 个').replace('{n}', entry.childrenCount) : i18n('无')], [i18n('分类 ID'), cleanText(row.id, '—')]],
            aria: nameText(row.name, i18n('未命名分类')) + '，' + level + '，' + (enabled ? i18n('已启用') : i18n('未启用'))
        };
    }

    function masterCategoryView(entry) {
        var row = entry.row;
        var relation = row.user_category || {};
        var visible = masterVisible(row, 'user_category');
        var originalName = nameText(row.name, i18n('未命名主站分类'));
        var customName = nameText(relation.name);
        var title = customName || originalName;
        var titleSource = customName ? relation.name : row.name;
        var level = entry.depth === 0 ? i18n('一级分类') : i18n('第 {n} 级分类').replace('{n}', entry.depth + 1);
        return {
            page: 'master-category',
            image: safeImage(row.icon),
            icon: entry.hasChildren ? 'folder' : 'subdirectory_arrow_right',
            eyebrow: level,
            title: title,
            titleHtml: safeInlineHtml(titleSource || title),
            subtitle: customName ? i18n('主站：') + originalName + ' · #' + cleanText(row.id, '—') : (entry.parentName || i18n('根目录')) + ' · #' + cleanText(row.id, '—'),
            status: {label: visible ? i18n('显示') : i18n('隐藏'), key: visible ? 'on' : 'off'},
            metric: entry.childrenCount ? i18n('{n} 个子分类').replace('{n}', entry.childrenCount) : (customName ? i18n('已自定义名称') : i18n('跟随主站名称')),
            summary: [[i18n('状态'), visible ? i18n('显示') : i18n('隐藏')], [i18n('层级'), level], [i18n('分类 ID'), cleanText(row.id, '—')]],
            details: [
                [i18n('主站名称'), originalName],
                [i18n('自定义名称'), customName || i18n('跟随主站名称')],
                [i18n('上级分类'), entry.parentName || i18n('根目录')],
                [i18n('子分类'), entry.childrenCount ? i18n('{n} 个').replace('{n}', entry.childrenCount) : i18n('无')],
                [i18n('显示状态'), visible ? i18n('显示') : i18n('隐藏')],
                [i18n('分类 ID'), cleanText(row.id, '—')]
            ],
            aria: title + '，' + level + i18n('，当前') + (visible ? i18n('显示') : i18n('隐藏'))
        };
    }

    function masterCommodityView(row) {
        var relation = row.user_commodity || {};
        var visible = masterVisible(row, 'user_commodity');
        var originalName = nameText(row.name, i18n('未命名主站商品'));
        var customName = nameText(relation.name);
        var title = customName || originalName;
        var titleSource = customName ? relation.name : row.name;
        var premium = Number(relation.premium || 0);
        var hasPremium = Number.isFinite(premium) && premium !== 0;
        var premiumLabel = hasPremium ? i18n('加价 {n}%').replace('{n}', String(Number(premium.toFixed(2)))) : i18n('跟随主站定价');
        return {
            page: 'master-commodity',
            image: safeImage(row.cover),
            icon: 'inventory_2',
            eyebrow: i18n('主站商品'),
            title: title,
            titleHtml: safeInlineHtml(titleSource || title),
            subtitle: customName ? i18n('主站：') + originalName : i18n('商品 #{id} · 沿用主站名称').replace('{id}', cleanText(row.id, '—')),
            status: {label: visible ? i18n('显示') : i18n('隐藏'), key: visible ? 'on' : 'off'},
            metric: premiumLabel,
            summary: [[i18n('状态'), visible ? i18n('显示') : i18n('隐藏')], [i18n('加价'), hasPremium ? String(Number(premium.toFixed(2))) + '%' : i18n('无')], [i18n('商品 ID'), cleanText(row.id, '—')]],
            details: [
                [i18n('主站名称'), originalName],
                [i18n('自定义名称'), customName || i18n('跟随主站名称')],
                [i18n('会员价'), money(row.user_price)],
                [i18n('游客价'), money(row.price)],
                [i18n('加价比例'), premiumLabel],
                [i18n('显示状态'), visible ? i18n('显示') : i18n('隐藏')],
                [i18n('商品 ID'), cleanText(row.id, '—')]
            ],
            aria: title + i18n('，主站商品，当前') + (visible ? i18n('显示') : i18n('隐藏')) + '，' + premiumLabel
        };
    }

    function cardView(row) {
        var status = Number(row.status);
        var statusMap = {
            0: {label: i18n('未出售'), key: 'on'},
            1: {label: i18n('已出售'), key: 'neutral'},
            2: {label: i18n('已锁定'), key: 'off'}
        };
        var state = statusMap[status] || {label: i18n('未知状态'), key: 'neutral'};
        var commodity = row.commodity || {};
        var sku = skuSummary(row);
        var premium = Number(row.draft_premium || 0);
        return {
            page: 'card',
            image: safeImage(commodity.cover),
            icon: 'vpn_key',
            eyebrow: nameText(commodity.name, i18n('数字卡密')),
            eyebrowHtml: safeInlineHtml(commodity.name || i18n('数字卡密')),
            title: i18n('卡密 #') + cleanText(row.id, '—'),
            subtitle: maskedSecret(row.secret) + (sku ? ' · ' + sku : ''),
            status: state,
            metric: status === 1 ? i18n('售于 {date}').replace('{date}', shortDate(row.purchase_time)) : i18n('入库 {date}').replace('{date}', shortDate(row.create_time)),
            summary: [[i18n('状态'), state.label], [i18n('商品'), nameText(commodity.name, '—')], [i18n('卡密 ID'), cleanText(row.id, '—')]],
            details: [
                [i18n('卡密内容'), cleanText(row.secret, '—')],
                [i18n('类别 / SKU'), sku || '—'],
                [i18n('预选信息'), cleanText(row.draft, '—')],
                [i18n('独立加价'), premium > 0 ? money(premium) : i18n('无')],
                [i18n('创建时间'), cleanText(row.create_time, '—')],
                [i18n('出售时间'), cleanText(row.purchase_time, i18n('未出售'))],
                [i18n('关联订单'), cleanText(valueAt(row, 'order.trade_no'), '—')],
                [i18n('备注'), cleanText(row.note, '—')]
            ],
            aria: i18n('卡密 {id}').replace('{id}', cleanText(row.id, '—')) + '，' + nameText(commodity.name, i18n('未关联商品')) + '，' + state.label
        };
    }

    function couponScope(row) {
        if (!row.commodity && !row.category) return i18n('全场通用');
        if (!row.commodity && row.category) return //用函数式替换：分类名是商家自填的自由文本，若含 $& / $` / $$ 会被 String.replace 当特殊语法
        i18n('分类 · {name}').replace('{name}', function () { return nameText(row.category.name, i18n('未命名分类')); });
        var scope = nameText(valueAt(row, 'commodity.name'), i18n('指定商品'));
        var sku = skuSummary(row);
        return scope + (sku ? ' · ' + sku : '');
    }

    function couponView(row) {
        var status = Number(row.status);
        var statusMap = {
            0: {label: i18n('正常使用'), key: 'on'},
            1: {label: i18n('已失效'), key: 'neutral'},
            2: {label: i18n('已锁定'), key: 'off'}
        };
        var state = statusMap[status] || {label: i18n('未知状态'), key: 'neutral'};
        var percentage = Number(row.mode) === 1;
        var value = percentage ? String(Number(row.money || 0) * 10) + i18n('折') : money(row.money);
        var expire = cleanText(row.expire_time, i18n('永久'));
        return {
            page: 'coupon',
            image: '',
            icon: 'local_offer',
            eyebrow: percentage ? i18n('百分比抵扣') : i18n('金额抵扣'),
            title: maskedSecret(row.code, i18n('未填写券码')),
            subtitle: couponScope(row),
            status: state,
            metric: i18n('剩余 {n} 次').replace('{n}', cleanText(row.life, '0')),
            summary: [[i18n('面值'), value], [i18n('剩余 / 已用'), cleanText(row.life, '0') + ' / ' + cleanText(row.use_life, '0')], [i18n('到期'), expire]],
            details: [
                [i18n('代券码'), cleanText(row.code, '—')],
                [i18n('抵扣范围'), couponScope(row)],
                [i18n('状态'), state.label],
                [i18n('备注'), cleanText(row.note, '—')],
                [i18n('创建时间'), cleanText(row.create_time, '—')],
                [i18n('使用时间'), cleanText(row.service_time, i18n('未使用'))],
                [i18n('最后订单'), cleanText(row.trade_no, '—')]
            ],
            aria: i18n('代券 {code}').replace('{code}', function () { return maskedSecret(row.code, i18n('未填写券码')); }) + '，' + state.label + i18n('，剩余 {n} 次').replace('{n}', cleanText(row.life, '0'))
        };
    }

    function orderView(row, snapshot, index) {
        var paid = Number(row.status) === 1;
        var delivered = Number(row.delivery_status) === 1;
        var automatic = Number(valueAt(row, 'commodity.delivery_way')) === 0;
        var state = !paid
            ? {label: i18n('未支付'), key: 'off'}
            : (delivered ? {label: i18n('已完成'), key: 'on'} : {label: i18n('待发货'), key: 'low'});
        var commodity = row.commodity || {};
        var owner = cleanText(valueAt(row, 'owner.username'), i18n('访客'));
        var tradeNo = cleanText(row.trade_no, '—');
        var sku = skuSummary(row);
        var canViewPurchaseInfo = row?.merchant_permissions?.view_purchase_info === true;
        var device = canViewPurchaseInfo ? deviceLabel(row.create_device) : i18n('受保护');
        var queryPassword = cleanText(row.password);
        var reservedCard = cleanText(valueAt(row, 'card.secret'));
        return {
            page: 'order',
            image: safeImage(commodity.cover),
            icon: 'receipt_long',
            eyebrow: automatic ? i18n('自动发货') : i18n('手动发货'),
            title: nameText(commodity.name, i18n('未知商品')),
            titleHtml: safeInlineHtml(commodity.name || i18n('未知商品')),
            subtitle: tradeNo + ' · ×' + cleanText(row.card_num, '1') + ' · ' + owner,
            status: state,
            metric: i18n('下单 {date}').replace('{date}', shortDate(row.create_time)),
            summary: [[i18n('订单金额'), money(row.amount)], [i18n('数量'), '×' + cleanText(row.card_num, '1')], [i18n('买家'), owner]],
            details: [
                [i18n('订单号'), tradeNo],
                [i18n('类别 / SKU'), sku || '—'],
                [i18n('支付状态'), paid ? i18n('已支付') : i18n('未支付')],
                [i18n('发货状态'), delivered ? i18n('已发货') : i18n('未发货')],
                [i18n('支付方式'), cleanText(valueAt(row, 'pay.name'), '—')],
                [i18n('联系方式'), cleanText(row.contact, '—')],
                [i18n('查询密码'), fullyMaskedSecret(queryPassword)],
                [i18n('预选卡密'), tailMaskedSecret(reservedCard)],
                [i18n('下单时间'), cleanText(row.create_time, '—')],
                [i18n('支付时间'), cleanText(row.pay_time, '—')],
                [i18n('优惠券'), tailMaskedSecret(valueAt(row, 'coupon.code'))],
                [i18n('设备 / IP'), device + ' / ' + cleanText(row.create_ip, '—')]
            ],
            aria: i18n('订单 {no}').replace('{no}', tradeNo) + '，' + nameText(commodity.name, i18n('未知商品')) + '，' + state.label
        };
    }

    function purchaseView(row) {
        var paid = Number(row.status) === 1;
        var delivered = Number(row.delivery_status) === 1;
        var deliveryWay = Number(valueAt(row, 'commodity.delivery_way'));
        var state = !paid
            ? {label: i18n('未支付'), key: 'off'}
            : (delivered ? {label: i18n('已完成'), key: 'on'} : {label: i18n('待发货'), key: 'low'});
        var commodity = row.commodity || {};
        var tradeNo = cleanText(row.trade_no, '—');
        var quantity = cleanText(row.card_num, '1');
        var sku = skuSummary(row);
        var deliveryLabel = deliveryWay === 0 ? i18n('自动发货') : (deliveryWay === 1 ? i18n('在线发货') : i18n('商城订单'));
        return {
            page: 'purchase',
            image: safeImage(commodity.cover),
            icon: 'shopping_bag',
            eyebrow: deliveryLabel,
            title: nameText(commodity.name, i18n('已下架商品')),
            titleHtml: safeInlineHtml(commodity.name || i18n('已下架商品')),
            subtitle: i18n('订单 {no}').replace('{no}', tradeNo) + ' · ×' + quantity,
            status: state,
            metric: money(row.amount),
            summary: [[i18n('金额'), money(row.amount)], [i18n('数量'), '×' + quantity], [i18n('下单'), shortDate(row.create_time)]],
            details: [
                [i18n('订单号'), tradeNo],
                [i18n('商品'), nameText(commodity.name, i18n('已下架商品'))],
                [i18n('类别 / SKU'), sku || '—'],
                [i18n('购买数量'), '×' + quantity],
                [i18n('订单金额'), money(row.amount)],
                [i18n('付款状态'), paid ? i18n('已支付') : i18n('未支付')],
                [i18n('发货状态'), delivered ? i18n('已发货') : i18n('未发货')],
                [i18n('支付方式'), cleanText(valueAt(row, 'pay.name'), '—')],
                [i18n('联系方式'), cleanText(row.contact, '—')],
                [i18n('下单时间'), cleanText(row.create_time, '—')],
                [i18n('支付时间'), cleanText(row.pay_time, '—')]
            ],
            aria: i18n('订单 {no}').replace('{no}', tradeNo) + '，' + nameText(commodity.name, i18n('已下架商品')) + '，' + state.label
        };
    }

    function billView(row, snapshot, index) {
        var type = Number(row.type);
        var currency = Number(row.currency);
        var income = type === 1;
        var typeLabel = displayText(snapshot, 'type', row, index, income ? i18n('收入') : i18n('支出'));
        var currencyLabel = displayText(snapshot, 'currency', row, index, currency === 1 ? i18n('硬币') : i18n('余额'));
        var log = nameText(row.log, i18n('账户变动'));
        return {
            page: 'bill',
            image: '',
            icon: income ? 'south_west' : 'north_east',
            eyebrow: currencyLabel,
            title: log,
            subtitle: cleanText(row.create_time, i18n('时间未记录')) + ' · ' + i18n('账单 #{id}').replace('{id}', cleanText(row.id, '—')),
            status: {label: typeLabel, key: income ? 'on' : 'off'},
            metric: walletValue(row.amount, currency, type),
            summary: [
                [i18n('本笔变动'), walletValue(row.amount, currency, type)],
                [i18n('变动后'), walletValue(row.balance, currency)],
                [i18n('钱包'), currencyLabel]
            ],
            details: [
                [i18n('收支类型'), typeLabel],
                [i18n('交易信息'), log],
                [i18n('交易时间'), cleanText(row.create_time, '—')],
                [i18n('账单编号'), cleanText(row.id, '—')]
            ],
            aria: log + '，' + typeLabel + walletValue(row.amount, currency, type) + '，' + currencyLabel
        };
    }

    function cashRecordView(row, snapshot, index) {
        var status = Number(row.status);
        var amount = Number(row.amount);
        var cost = Number(row.cost);
        var requested = (Number.isFinite(amount) ? amount : 0) + (Number.isFinite(cost) ? cost : 0);
        var typeLabel = displayText(snapshot, 'type', row, index, Number(row.type) === 0 ? i18n('自动结算') : i18n('手动提交'));
        var walletLabel = displayText(snapshot, 'card', row, index, i18n('未知钱包'));
        var statusFallback = status === 1 ? i18n('已到账') : (status === 2 ? i18n('兑现失败') : i18n('银行处理中'));
        var statusLabel = displayText(snapshot, 'status', row, index, statusFallback);
        var statusKey = status === 1 ? 'on' : (status === 2 ? 'off' : 'low');
        return {
            page: 'cash-record',
            image: '',
            icon: 'account_balance_wallet',
            eyebrow: typeLabel,
            title: i18n('兑现至') + walletLabel,
            subtitle: cleanText(row.create_time, i18n('时间未记录')) + ' · ' + i18n('记录 #{id}').replace('{id}', cleanText(row.id, '—')),
            status: {label: statusLabel, key: statusKey},
            metric: acgCurrencySymbol() + formattedNumber(row.amount, 2),
            summary: [
                [i18n('申请金额'), acgCurrencySymbol() + formattedNumber(requested, 2)],
                [i18n('手续费'), acgCurrencySymbol() + formattedNumber(row.cost, 2)],
                [i18n('净到账'), acgCurrencySymbol() + formattedNumber(row.amount, 2)]
            ],
            details: [
                [i18n('申请类型'), typeLabel],
                [i18n('到账钱包'), walletLabel],
                [i18n('处理状态'), statusLabel],
                [i18n('处理说明'), cleanText(row.message, '—')],
                [i18n('提交时间'), cleanText(row.create_time, '—')],
                [i18n('到账时间'), cleanText(row.arrive_time, i18n('未到账'))],
                [i18n('记录编号'), cleanText(row.id, '—')]
            ],
            aria: i18n('兑现至') + walletLabel + '，' + statusLabel + i18n('，净到账') + formattedNumber(row.amount, 2) + i18n('元')
        };
    }

    function messageView(row) {
        var source = row && (row.message || row.system_message) || row || {};
        var title = nameText(row && row.title != null ? row.title : source.title, i18n('未命名消息'));
        var summary = nameText(row && row.summary != null ? row.summary : source.summary, i18n('点击查看消息详情'));
        var receivedAt = cleanText(row && row.create_time != null ? row.create_time : source.create_time, i18n('时间未记录'));
        var updatedAt = cleanText(row && row.update_time != null ? row.update_time : source.update_time, '—');
        var readAt = cleanText(row && row.read_time, i18n('未读'));
        var receiptId = cleanText(row && (row.id || row.user_message_id || source.user_message_id), '—');
        var read = Boolean(row && row.read_time);
        var state = read
            ? {label: i18n('已读'), key: 'neutral'}
            : {label: i18n('未读'), key: 'primary'};
        return {
            page: 'message',
            image: '',
            icon: read ? 'drafts' : 'mark_email_unread',
            eyebrow: read ? i18n('已读消息') : i18n('新消息'),
            title: title,
            subtitle: summary,
            status: state,
            metric: shortDate(receivedAt),
            summary: [
                [i18n('状态'), state.label],
                [i18n('接收日期'), shortDate(receivedAt)],
                [i18n('消息 ID'), receiptId]
            ],
            details: [
                [i18n('消息摘要'), summary],
                [i18n('接收时间'), receivedAt],
                [i18n('阅读时间'), readAt],
                [i18n('更新时间'), updatedAt],
                [i18n('消息 ID'), receiptId]
            ],
            aria: title + '，' + state.label + i18n('，接收于') + receivedAt
        };
    }

    function promoteView(row) {
        var skuCount = Math.max(0, Number(row && row.sku_count) || 0);
        var rate = Number(row && row.rate);
        var rateLabel = Number.isFinite(rate) ? String(Number(rate.toFixed(2))) + '%' : '—';
        var rateKey = !Number.isFinite(rate) || rate === 0 ? 'neutral' : (rate > 0 ? 'on' : 'off');
        var category = cleanText(row && row.race, i18n('标准类别'));
        var productId = cleanText(row && row.id, '—');
        var title = nameText(row && row.name, i18n('未命名商品'));
        var profit = money(row && row.profit);
        return {
            page: 'promote',
            image: safeImage(row && row.cover),
            icon: 'calculate',
            eyebrow: category,
            title: title,
            titleHtml: safeInlineHtml(row && row.name || title),
            subtitle: i18n('商品 #') + productId + ' · ' + (skuCount > 0 ? i18n('{n} 组 SKU').replace('{n}', skuCount) : i18n('标准定价')),
            status: {label: i18n('收益率 {rate}').replace('{rate}', rateLabel), key: rateKey},
            metric: i18n('预计 {amount}').replace('{amount}', profit),
            summary: [[i18n('预计收益'), profit], [i18n('游客成交价'), money(row && row.guest_price)], [i18n('我的拿货价'), money(row && row.my_price)]],
            details: [
                [i18n('商品 ID'), productId],
                [i18n('商品类别'), category],
                [i18n('SKU 组数'), skuCount > 0 ? i18n('{n} 组').replace('{n}', skuCount) : i18n('无')],
                [i18n('游客成交价'), money(row && row.guest_price)],
                [i18n('我的拿货价'), money(row && row.my_price)],
                [i18n('预计收益'), profit],
                [i18n('收益率'), rateLabel]
            ],
            aria: title + i18n('，预计收益') + profit + i18n('，收益率') + rateLabel
        };
    }

    function viewFor(presenter, row, index, entry) {
        if (presenter.page === 'category') return categoryView(entry);
        if (presenter.page === 'master-category') return masterCategoryView(entry);
        if (presenter.page === 'master-commodity') return masterCommodityView(row);
        if (presenter.page === 'card') return cardView(row);
        if (presenter.page === 'coupon') return couponView(row);
        if (presenter.page === 'purchase') return purchaseView(row);
        if (presenter.page === 'bill') return billView(row, presenter.snapshot, index);
        if (presenter.page === 'cash-record') return cashRecordView(row, presenter.snapshot, index);
        if (presenter.page === 'message') return messageView(row);
        if (presenter.page === 'promote') return promoteView(row);
        return orderView(row, presenter.snapshot, index);
    }

    function actionsFor(presenter, row, index, view) {
        var snapshot = presenter.snapshot;
        var actions = [];
        var used = new Set();
        function run(id, label, icon, modifier, behavior) {
            used.add(id);
            var original = actionFor(snapshot, id, row);
            if (!original) return;
            actions.push({kind: 'run', id: id, label: label, icon: icon, modifier: modifier || '', behavior: behavior || 'followup'});
        }
        //subject 显式传入：原来靠 label.replace(/^复制/,'') 去掉「复制」二字，
        //label 翻译之后这个字符串手术就失效了（英文下会得到「Copy Card KeyCopied」）
        function copy(label, icon, value, subject) {
            var name = cleanText(subject || '') || cleanText(label).replace(/^复制/, '');
            if (cleanText(value)) actions.push({kind: 'copy', label: label, icon: icon, value: String(value), modifier: '', successLabel: name ? name + i18n('已复制') : i18n('复制成功')});
        }
        function extensionIcon(action) {
            //action.title 在 table.js getActions() 里已经过 i18n(),拿它匹配中文关键字
            //换语言后必然落空(图标全退化成 more_horiz)。definition 存的是未翻译的原始按钮配置。
            var definition = (action && action.definition) || {};
            var rawTitle = definition.title || definition.tips || '';
            var source = cleanText([action && action.icon, rawTitle].filter(Boolean).join(' ')).toLowerCase();
            if (/trash|delete|删除|移除/.test(source)) return 'delete';
            if (/edit|pen|修改|编辑/.test(source)) return 'edit';
            if (/lock-open|unlock|解锁/.test(source)) return 'lock_open';
            if (/lock|锁定/.test(source)) return 'lock';
            if (/copy|复制/.test(source)) return 'content_copy';
            if (/truck|deliver|发货/.test(source)) return 'local_shipping';
            if (/eye|view|查看/.test(source)) return 'visibility';
            return 'more_horiz';
        }

        if (presenter.page === 'category') {
            run('operation:0', i18n('编辑分类'), 'edit', 'is-primary', 'followup');
            run('share_url:0', i18n('复制推广链接'), 'content_copy', '', 'restore');
            actions.push({kind: 'status', label: (categoryEnabled(row) ? i18n('停用分类') : i18n('启用分类')), icon: (categoryEnabled(row) ? 'visibility_off' : 'visibility'), modifier: categoryEnabled(row) ? 'is-warning' : 'is-primary', behavior: 'stable'});
            run('operation:1', i18n('删除分类'), 'delete', 'is-danger is-wide', 'followup');
        } else if (presenter.page === 'card') {
            copy(i18n('复制卡密'), 'content_copy', row.secret, i18n('卡密'));
            run('operation:0', i18n('编辑卡密'), 'edit', 'is-primary', 'followup');
            run('operation:1', i18n('锁定卡密'), 'lock', 'is-warning', 'stable');
            run('operation:2', i18n('解锁卡密'), 'lock_open', 'is-primary', 'stable');
            run('operation:3', i18n('删除卡密'), 'delete', 'is-danger', 'followup');
        } else if (presenter.page === 'coupon') {
            copy(i18n('复制代券码'), 'content_copy', row.code, i18n('代券码'));
            run('operation:0', i18n('锁定代券'), 'lock', 'is-warning', 'stable');
            run('operation:1', i18n('解锁代券'), 'lock_open', 'is-primary', 'stable');
            run('operation:2', i18n('删除代券'), 'delete', 'is-danger', 'followup');
        } else if (presenter.page === 'purchase') {
            copy(i18n('复制订单号'), 'content_copy', row.trade_no, i18n('订单号'));
            if (Number(row.status) === 1 && cleanText(row.secret)) {
                actions.push({kind: 'purchase-secret', label: i18n('查看卡密'), icon: 'key', modifier: 'is-primary', behavior: 'followup'});
            }
        } else if (presenter.page === 'master-category') {
            run('operation:0', i18n('查看分类商品'), 'visibility', 'is-primary', 'followup');
            run('operation:1', i18n('设置分类'), 'settings', '', 'followup');
            if (masterVisible(row, 'user_category')) run('status:0', i18n('隐藏分类'), 'visibility_off', 'is-warning', 'stable');
            else run('status:1', i18n('显示分类'), 'visibility', 'is-primary', 'stable');
        } else if (presenter.page === 'master-commodity') {
            run('operation:0', i18n('设置商品'), 'settings', 'is-primary', 'followup');
            if (masterVisible(row, 'user_commodity')) run('status:0', i18n('隐藏商品'), 'visibility_off', 'is-warning', 'stable');
            else run('status:1', i18n('显示商品'), 'visibility', 'is-primary', 'stable');
        } else if (presenter.page === 'message') {
            run('operation:0', i18n('查看消息'), 'visibility', 'is-primary', 'followup');
            run('operation:1', i18n('删除消息'), 'delete', 'is-danger', 'followup');
        } else if (presenter.page === 'promote') {
            if (Number(row.sku_count) > 0) actions.push({kind: 'promote-sku', label: i18n('查看 SKU 收益'), icon: 'tune', modifier: 'is-primary', behavior: 'followup'});
        } else if (presenter.page === 'bill' || presenter.page === 'cash-record') {
            return [];
        } else {
            copy(i18n('复制订单号'), 'content_copy', row.trade_no, i18n('订单号'));
            copy(i18n('复制查询密码'), 'password', row.password, i18n('查询密码'));
            copy(i18n('复制预选卡密'), 'key', valueAt(row, 'card.secret'), i18n('预选卡密'));
            run('secret:0', i18n('查看交付内容'), 'key', 'is-primary', 'followup');
            run('secret:1', Number(row.delivery_status) === 1 ? i18n('修改交付内容') : i18n('手动发货'), 'local_shipping', 'is-primary', 'followup');
            run('widget:0', i18n('查看购买信息'), 'fact_check', '', 'followup');
        }
        (snapshot && snapshot.actions || []).forEach(function (original) {
            if (!original || used.has(original.id) || !actionFor(snapshot, original.id, row)) return;
            actions.push({
                kind: 'run',
                id: original.id,
                label: cleanText(original.title, i18n('更多操作')),
                icon: extensionIcon(original),
                modifier: original.danger || original.category === 'danger' ? 'is-danger' : '',
                behavior: 'followup'
            });
        });
        return actions;
    }

    function mediaMarkup(view) {
        if (view.image) return '<img src="' + escapeHtml(view.image) + '" alt="" loading="lazy">';
        return '<span class="material-icons-outlined" aria-hidden="true">' + escapeHtml(view.icon) + '</span>';
    }

    function stateMarkup(type, icon, title, detail, retry) {
        return '<div class="st-record-mobile-state st-commodity-mobile-state is-' + type + '" role="status">'
            + '<span class="' + (type === 'loading' ? 'st-commodity-mobile-spinner' : 'material-icons-outlined') + '" aria-hidden="true">' + (type === 'loading' ? '' : escapeHtml(icon)) + '</span>'
            + '<strong>' + escapeHtml(title) + '</strong><small>' + escapeHtml(detail) + '</small>'
            + (retry ? '<button type="button" class="st-button st-button-quiet" data-st-record-retry><span class="material-icons-outlined" aria-hidden="true">refresh</span><span>' + i18n('重新加载') + '</span></button>' : '')
            + '</div>';
    }

    function recordLine(presenter, entry) {
        var row = entry.row;
        var index = entry.index;
        var view = viewFor(presenter, row, index, entry);
        var selection = selectionFor(presenter.snapshot, row, index);
        var rowId = cleanText(row && row.id, String(index));
        var leading = '';
        var lineClasses = ['st-record-mobile-line'];
        var directCategory = presenter.page === 'master-category';

        if (directCategory) lineClasses.push('has-row-menu');

        if (isTreePage(presenter.page)) {
            lineClasses.push('has-tree-control');
            leading = entry.hasChildren
                ? '<button type="button" class="st-record-tree-toggle" data-st-record-tree-id="' + escapeHtml(entry.id) + '" aria-expanded="' + (entry.expanded ? 'true' : 'false') + '" aria-label="' + (entry.expanded ? i18n('收起') : i18n('展开')) + escapeHtml(view.title) + '"><span class="material-icons-outlined" aria-hidden="true">' + (entry.expanded ? 'expand_more' : 'chevron_right') + '</span></button>'
                : '<span class="st-record-tree-leaf"><span class="material-icons-outlined" aria-hidden="true">subdirectory_arrow_right</span></span>';
        } else if (selection.enabled) {
            lineClasses.push('has-selection');
            leading = '<button type="button" class="st-record-mobile-select' + (selection.selected ? ' is-selected' : '') + '" data-st-record-select="' + index + '" data-st-record-id="' + escapeHtml(rowId) + '" aria-pressed="' + (selection.selected ? 'true' : 'false') + '"' + (selection.selectable ? '' : ' disabled') + ' aria-label="' + (selection.selected ? i18n('取消选择') : i18n('选择')) + escapeHtml(view.title) + '"><span class="material-icons-outlined" aria-hidden="true">' + (selection.selected ? 'check_circle' : 'radio_button_unchecked') + '</span></button>';
        }

        var rowClass = 'st-record-mobile-row' + (isTreePage(presenter.page) ? ' is-tree-row' : '') + (directCategory ? ' is-direct-action' : '');
        return '<li data-st-record-entry-id="' + escapeHtml(entry.id) + '" data-st-record-depth="' + (entry.depth || 0) + '" style="--st-record-depth:' + (entry.depth || 0) + '"><div class="' + lineClasses.join(' ') + '">' + leading
            + '<button type="button" class="' + rowClass + '" data-st-record-index="' + index + '" aria-label="' + escapeHtml(view.aria + (directCategory ? i18n('，查看分类商品') : i18n('，打开操作菜单'))) + '">'
            + '<span class="st-record-mobile-icon">' + mediaMarkup(view) + '</span>'
            + '<span class="st-record-mobile-copy"><span class="st-record-mobile-eyebrow">' + (view.eyebrowHtml || escapeHtml(view.eyebrow)) + '</span><strong' + (presenter.page === 'coupon' ? ' class="is-mono"' : '') + '>' + (view.titleHtml || escapeHtml(view.title)) + '</strong><small>' + escapeHtml(view.subtitle) + '</small></span>'
            + '<span class="st-record-mobile-aside"><span class="st-record-mobile-status is-' + escapeHtml(view.status.key) + '">' + escapeHtml(view.status.label) + '</span><small>' + escapeHtml(view.metric) + '</small></span>'
            + '<span class="material-icons-outlined st-record-mobile-chevron" aria-hidden="true">chevron_right</span></button>'
            + (directCategory ? '<button type="button" class="st-record-mobile-menu" data-st-record-menu-index="' + index + '" aria-label="' + escapeHtml(view.title + i18n('更多操作')) + '"><span class="material-icons-outlined" aria-hidden="true">more_vert</span></button>' : '')
            + '</div></li>';
    }

    function categoryBranchItems(button) {
        var item = button && button.closest('li[data-st-record-depth]');
        if (!item) return [];
        var depth = Number(item.getAttribute('data-st-record-depth')) || 0;
        var branch = [];
        var next = item.nextElementSibling;
        while (next) {
            var nextDepth = Number(next.getAttribute('data-st-record-depth')) || 0;
            if (nextDepth <= depth) break;
            branch.push(next);
            next = next.nextElementSibling;
        }
        return branch;
    }

    function categoryToggleFor(presenter, id) {
        return all('[data-st-record-tree-id]', presenter && presenter.list).find(function (button) {
            return button.getAttribute('data-st-record-tree-id') === id;
        });
    }

    function toggleCategoryBranch(button) {
        var id = button && button.getAttribute('data-st-record-tree-id') || '';
        var source = sourceTableFor(button);
        var presenter = allPresenters().find(function (item) { return item.source === source; });
        if (!presenter || !id || presenter.treeTransitionTimer) return;
        var expanding = presenter.collapsedCategories.has(id);
        presenter.pendingFocus = {type: 'tree', id: id};
        button.disabled = true;

        if (!expanding) {
            categoryBranchItems(button).forEach(function (item) { item.classList.add('is-tree-leaving'); });
            presenter.treeTransitionTimer = window.setTimeout(function () {
                presenter.treeTransitionTimer = null;
                presenter.collapsedCategories.add(id);
                renderPresenter(presenter);
            }, 170);
            return;
        }

        presenter.collapsedCategories.delete(id);
        renderPresenter(presenter);
        var refreshedToggle = categoryToggleFor(presenter, id);
        var entering = categoryBranchItems(refreshedToggle);
        entering.forEach(function (item) { item.classList.add('is-tree-entering'); });
        presenter.treeTransitionTimer = window.setTimeout(function () {
            presenter.treeTransitionTimer = null;
            entering.forEach(function (item) { if (item.isConnected) item.classList.remove('is-tree-entering'); });
        }, 170);
    }

    function selectionBar(presenter, entries) {
        if (presenter.page !== 'card' && presenter.page !== 'coupon' && presenter.page !== 'message') return '';
        if (!presenter.snapshot.selection || !presenter.snapshot.selection.enabled) return '';
        var selectable = entries.filter(function (entry) { return selectionFor(presenter.snapshot, entry.row, entry.index).selectable; });
        var selected = selectable.filter(function (entry) { return selectionFor(presenter.snapshot, entry.row, entry.index).selected; });
        var allSelected = selectable.length > 0 && selected.length === selectable.length;
        return '<div class="st-record-selection-bar"><span>' + i18n('本页') + ' ' + entries.length + ' ' + i18n('项') + ' · ' + i18n('已选') + ' <strong>' + selected.length + '</strong></span><button type="button" data-st-record-select-all aria-pressed="' + (allSelected ? 'true' : 'false') + '"><span class="material-icons-outlined" aria-hidden="true">' + (allSelected ? 'deselect' : 'select_all') + '</span><span>' + (allSelected ? i18n('取消全选') : i18n('选择本页')) + '</span></button></div>';
    }

    function restorePendingFocus(presenter) {
        if (!presenter.pendingFocus || !presenter.list) return;
        var pending = presenter.pendingFocus;
        if (pending.type === 'record' && presenter.pendingFocusTimer) window.clearTimeout(presenter.pendingFocusTimer);
        presenter.pendingFocusTimer = null;
        presenter.pendingFocus = null;
        window.requestAnimationFrame(function () {
            if (!presenter.list || !presenter.list.isConnected) return;
            var target = null;
            if (pending.type === 'selection') {
                target = all('[data-st-record-select]', presenter.list).find(function (button) { return button.getAttribute('data-st-record-id') === pending.id; });
            } else if (pending.type === 'tree') {
                target = all('[data-st-record-tree-id]', presenter.list).find(function (button) { return button.getAttribute('data-st-record-tree-id') === pending.id; });
            } else if (pending.type === 'all') target = presenter.list.querySelector('[data-st-record-select-all]');
            else if (pending.type === 'record') {
                var item = all('[data-st-record-entry-id]', presenter.list).find(function (entry) { return entry.getAttribute('data-st-record-entry-id') === pending.id; });
                target = item && item.querySelector('.st-record-mobile-row');
            }
            if (target && !target.disabled && target.getClientRects().length) target.focus({preventScroll: true});
        });
    }

    function resetDesktopTable(presenter) {
        var source = presenter && presenter.source;
        if (!source || !source.isConnected || !window.jQuery || !window.jQuery.fn.bootstrapTable) return;
        window.setTimeout(function () {
            if (!source.isConnected) return;
            try { window.jQuery(source).bootstrapTable('resetView'); } catch (error) {}
        }, 0);
    }

    function teardownPresenter(presenter, resetView) {
        if (!presenter) return;
        if (presenter.treeTransitionTimer) window.clearTimeout(presenter.treeTransitionTimer);
        presenter.treeTransitionTimer = null;
        clearPendingRecordFocus(presenter);
        var wrapper = presenter.source && presenter.source.closest('.bootstrap-table');
        var mounted = wrapper && wrapper.classList.contains('st-record-mobile-mounted');
        if (presenter.list) presenter.list.remove();
        presenter.list = null;
        if (wrapper) wrapper.classList.remove('st-record-mobile-mounted');
        if (mounted && resetView) resetDesktopTable(presenter);
    }

    function renderPresenter(presenter) {
        var snapshot = presenter.snapshot;
        var source = presenter.source;
        if (!snapshot || !source || !source.isConnected) return;
        if (!isMobile()) {
            teardownPresenter(presenter, true);
            return;
        }
        var wrapper = source.closest('.bootstrap-table');
        var container = wrapper && wrapper.querySelector(':scope > .fixed-table-container');
        if (!wrapper || !container) return;
        var list = presenter.list;
        if (!list || !list.isConnected) {
            list = document.createElement('section');
            list.className = 'st-record-mobile-list';
            var labels = {'category': i18n('分类列表'), 'card': i18n('卡密列表'), 'coupon': i18n('代券列表'), 'order': i18n('订单列表'), 'purchase': i18n('购买记录'), 'master-category': i18n('主站分类列表'), 'master-commodity': i18n('主站商品列表'), 'bill': i18n('账单明细'), 'cash-record': i18n('兑现记录'), 'message': i18n('消息列表'), 'promote': i18n('商品预计收益')};
            list.setAttribute('aria-label', labels[presenter.page] || i18n('记录列表'));
            list.setAttribute('aria-live', 'polite');
            container.insertAdjacentElement('afterend', list);
            presenter.list = list;
        }
        list.setAttribute('data-st-record-page', presenter.page);
        wrapper.classList.add('st-record-mobile-mounted');
        var status = snapshot.status || {};
        var rows = Array.isArray(snapshot.rows) ? snapshot.rows : [];
        if (status.loading) {
            list.innerHTML = stateMarkup('loading', '', i18n('正在加载'), i18n('请稍候…'));
            return;
        }
        if (status.error) {
            clearPendingRecordFocus(presenter);
            list.innerHTML = stateMarkup('error', 'error_outline', i18n('加载失败'), i18n('请检查网络后重试'), true);
            return;
        }
        if (!rows.length) {
            clearPendingRecordFocus(presenter);
            list.innerHTML = stateMarkup('empty', 'inbox', i18n('暂无记录'), i18n('调整筛选条件后再试'));
            return;
        }
        var entries = isTreePage(presenter.page)
            ? categoryEntries(rows, presenter.collapsedCategories)
            : rows.map(function (row, index) { return {row: row, index: index, id: cleanText(row && row.id, String(index)), depth: 0}; });
        list.innerHTML = selectionBar(presenter, entries)
            + '<ul class="st-record-mobile-items">' + entries.map(function (entry) { return recordLine(presenter, entry); }).join('') + '</ul>';
        restorePendingFocus(presenter);
    }

    function clearPendingRecordFocus(presenter) {
        if (!presenter) return;
        if (presenter.pendingFocusTimer) window.clearTimeout(presenter.pendingFocusTimer);
        presenter.pendingFocusTimer = null;
        if (presenter.pendingFocus && presenter.pendingFocus.type === 'record') presenter.pendingFocus = null;
    }

    function queuePendingRecordFocus(presenter, id) {
        clearPendingRecordFocus(presenter);
        presenter.pendingFocus = {type: 'record', id: id};
        presenter.pendingFocusTimer = window.setTimeout(function () {
            presenter.pendingFocusTimer = null;
            if (presenter.pendingFocus && presenter.pendingFocus.type === 'record' && presenter.pendingFocus.id === id) presenter.pendingFocus = null;
        }, 8000);
    }

    function ensureSheet() {
        var sheet = document.querySelector('[data-st-sheet="record-actions"]');
        if (!sheet) {
            sheet = document.createElement('section');
            sheet.className = 'st-sheet st-record-action-sheet st-commodity-action-sheet';
            sheet.setAttribute('data-st-sheet', 'record-actions');
            sheet.setAttribute('aria-hidden', 'true');
            sheet.setAttribute('aria-labelledby', 'st-record-action-title');
            sheet.setAttribute('role', 'dialog');
            sheet.setAttribute('aria-modal', 'true');
            sheet.setAttribute('tabindex', '-1');
            sheet.innerHTML = '<div class="st-sheet__handle" aria-hidden="true"></div>'
                + '<header><div class="st-record-sheet-heading st-commodity-sheet-heading"><span class="st-record-sheet-media st-commodity-sheet-media material-icons-outlined" aria-hidden="true">list_alt</span><span><strong id="st-record-action-title">' + i18n('记录操作') + '</strong><small data-st-record-sheet-subtitle></small></span></div><button type="button" class="st-icon-button" data-st-sheet-close aria-label="' + i18n('关闭操作菜单') + '"><span class="material-icons-outlined" aria-hidden="true">close</span></button></header>'
                + '<dl class="st-record-sheet-summary st-commodity-sheet-summary"></dl>'
                + '<dl class="st-record-sheet-details"></dl>'
                + '<div class="st-record-sort-editor" hidden><label><span>' + i18n('分类排序') + '</span><input type="number" min="1000" max="60000" step="1" inputmode="numeric" aria-label="' + i18n('分类排序') + '"></label><button type="button" data-st-record-sort-save><span class="material-icons-outlined" aria-hidden="true">save</span><span>' + i18n('保存排序') + '</span></button></div>'
                + '<div class="st-record-sheet-actions st-commodity-sheet-actions" role="group" aria-label="' + i18n('记录操作') + '"></div>';
            document.body.appendChild(sheet);
        }
        var trigger = document.querySelector('[data-st-record-sheet-trigger]');
        if (!trigger) {
            trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.hidden = true;
            trigger.setAttribute('data-st-record-sheet-trigger', '');
            trigger.setAttribute('data-st-sheet-open', 'record-actions');
            trigger.setAttribute('aria-expanded', 'false');
            document.body.appendChild(trigger);
        }
        return sheet;
    }

    function detailsMarkup(items) {
        return (items || []).filter(function (item) { return item && item.length >= 2; }).map(function (item) {
            return '<div><dt>' + escapeHtml(item[0]) + '</dt><dd>' + escapeHtml(cleanText(item[1], '—')) + '</dd></div>';
        }).join('');
    }

    function fillSheet(presenter, row, index, entry, view) {
        var sheet = ensureSheet();
        var media = sheet.querySelector('.st-record-sheet-media');
        media.className = 'st-record-sheet-media st-commodity-sheet-media' + (view.image ? '' : ' material-icons-outlined');
        media.innerHTML = view.image ? '<img src="' + escapeHtml(view.image) + '" alt="">' : '';
        if (!view.image) media.textContent = view.icon;
        sheet.querySelector('#st-record-action-title').textContent = view.title;
        sheet.querySelector('[data-st-record-sheet-subtitle]').textContent = view.eyebrow + ' · ' + view.status.label;
        sheet.querySelector('.st-record-sheet-summary').innerHTML = detailsMarkup(view.summary);
        sheet.querySelector('.st-record-sheet-details').innerHTML = detailsMarkup(view.details);
        var editor = sheet.querySelector('.st-record-sort-editor');
        editor.hidden = presenter.page !== 'category';
        if (!editor.hidden) editor.querySelector('input').value = cleanText(row.sort, '1000');

        activeActions = actionsFor(presenter, row, index, view);
        var actions = sheet.querySelector('.st-record-sheet-actions');
        actions.hidden = activeActions.length === 0;
        actions.innerHTML = activeActions.map(function (action, actionIndex) {
            return '<button type="button" class="st-record-sheet-action st-commodity-sheet-action ' + escapeHtml(action.modifier || '') + '" data-st-record-action="' + actionIndex + '"><span class="material-icons-outlined" aria-hidden="true">' + escapeHtml(action.icon) + '</span><span>' + escapeHtml(action.label) + '</span></button>';
        }).join('');
        return sheet;
    }

    function focusStable(presenter, fallback) {
        var wrapper = presenter && presenter.source && presenter.source.closest('.bootstrap-table');
        var target = wrapper && all('.st-mobile-quick-search input, .st-mobile-filter-trigger, .table-switch-state button.active', wrapper).find(function (node) {
            return !node.disabled && node.getAttribute('aria-hidden') !== 'true' && node.getClientRects().length > 0;
        });
        if (!target && fallback && fallback.isConnected && !fallback.disabled && fallback.getClientRects().length) target = fallback;
        if (target) target.focus({preventScroll: true});
        return Boolean(target);
    }

    function closeRecordSheet(restoreFocus) {
        var trigger = activeRecord && activeRecord.trigger;
        if (window.SeattleTheme && typeof window.SeattleTheme.closeSheets === 'function') window.SeattleTheme.closeSheets();
        else {
            var sheet = document.querySelector('[data-st-sheet="record-actions"]');
            if (sheet) {
                sheet.classList.remove('is-open');
                sheet.setAttribute('aria-hidden', 'true');
            }
            if (document.body) document.body.classList.remove('st-sheet-open');
            var backdrop = document.querySelector('.st-sheet-backdrop');
            if (backdrop) backdrop.setAttribute('aria-hidden', 'true');
        }
        activeRecord = null;
        activeActions = [];
        if (restoreFocus !== false && trigger && trigger.isConnected) {
            window.setTimeout(function () { if (trigger.isConnected) trigger.focus({preventScroll: true}); }, 0);
        }
    }

    function openRecord(button) {
        var source = sourceTableFor(button);
        var presenter = allPresenters().find(function (item) { return item.source === source; });
        if (!presenter) return;
        clearPendingRecordFocus(presenter);
        var index = Number(button.getAttribute('data-st-record-index'));
        var row = presenter.snapshot && presenter.snapshot.rows && presenter.snapshot.rows[index];
        if (!row) return;
        var entry = isTreePage(presenter.page)
            ? categoryEntries(presenter.snapshot.rows, presenter.collapsedCategories).find(function (item) { return item.index === index; })
            : {row: row, index: index, id: cleanText(row.id, String(index)), depth: 0};
        if (!entry) return;
        var view = viewFor(presenter, row, index, entry);
        activeRecord = {presenter: presenter, row: row, index: index, entry: entry, view: view, trigger: button, epoch: operationEpoch};
        var sheet = fillSheet(presenter, row, index, entry, view);
        var hiddenTrigger = document.querySelector('[data-st-record-sheet-trigger]');
        if (hiddenTrigger) hiddenTrigger.click();
        window.requestAnimationFrame(function () {
            var first = sheet.querySelector('[data-st-record-action]')
                || sheet.querySelector('.st-record-sort-editor:not([hidden]) input')
                || sheet.querySelector('[data-st-sheet-close]');
            if (first && sheet.classList.contains('is-open')) first.focus({preventScroll: true});
        });
    }

    function openRecordOrPrimary(button) {
        var source = sourceTableFor(button);
        var presenter = allPresenters().find(function (item) { return item.source === source; });
        if (!presenter || presenter.page !== 'master-category') {
            openRecord(button);
            return;
        }
        var index = Number(button.getAttribute('data-st-record-index'));
        var row = presenter.snapshot && presenter.snapshot.rows && presenter.snapshot.rows[index];
        if (!row) return;
        presenter.table.runAction('operation:0', row, null, index);
    }

    function copyNotice(type, text) {
        if (window.message && typeof window.message[type] === 'function') {
            window.message[type](text);
            return;
        }
        if (window.SeattleTheme && typeof window.SeattleTheme.notify === 'function') {
            window.SeattleTheme.notify(text, {type: type === 'success' ? 'success' : 'error'});
        }
    }

    function legacyCopy(value, successLabel) {
        var field = document.createElement('textarea');
        var copied = false;
        field.value = String(value);
        field.setAttribute('readonly', 'readonly');
        field.style.position = 'fixed';
        field.style.top = '-1000px';
        field.style.left = '-1000px';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.focus();
        field.select();
        field.setSelectionRange(0, field.value.length);
        try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
        field.remove();
        copyNotice(copied ? 'success' : 'error', copied ? (successLabel || i18n('复制成功')) : i18n('复制失败，请长按订单号手动复制'));
    }

    function copyText(value, successLabel) {
        var text = String(value);
        var success = function () { copyNotice('success', successLabel || i18n('复制成功')); };
        var fallback = function () { legacyCopy(text, successLabel); };
        if (!window.isSecureContext || !navigator.clipboard || !navigator.clipboard.writeText) {
            fallback();
            return;
        }
        if (window.util && typeof window.util.copyTextToClipboard === 'function') {
            try { window.util.copyTextToClipboard(text, success, fallback); } catch (error) { fallback(); }
            return;
        }
        navigator.clipboard.writeText(text).then(success).catch(fallback);
    }

    function invokeRecordAction(actionIndex) {
        var context = activeRecord;
        var action = activeActions[actionIndex];
        if (!context || !action) return;
        var presenter = context.presenter;
        var stable = action.behavior === 'stable';
        var followup = action.behavior === 'followup';
        if (stable) queuePendingRecordFocus(presenter, cleanText(context.entry && context.entry.id, cleanText(context.row && context.row.id, String(context.index))));
        closeRecordSheet(!stable && !followup);
        if (stable || followup) focusStable(presenter, context.trigger);
        if (action.kind === 'copy') {
            copyText(action.value, action.successLabel || i18n('复制成功'));
            return;
        }
        if (action.kind === 'purchase-secret') {
            var purchasePage = presenter.source && presenter.source.closest('[data-st-page="dashboard"], [data-st-page="purchase-record"]');
            if (purchasePage && window.jQuery) {
                window.setTimeout(function () {
                    if (purchasePage.isConnected) window.jQuery(purchasePage).trigger('seattle:purchase:secret', [context.row, context.trigger]);
                }, 0);
            }
            return;
        }
        if (action.kind === 'promote-sku') {
            var promotePage = presenter.source && presenter.source.closest('[data-st-page="promote"]');
            if (promotePage && window.jQuery) {
                window.setTimeout(function () {
                    if (promotePage.isConnected) window.jQuery(promotePage).trigger('seattle:promote:sku', [context.row, context.trigger]);
                }, 0);
            }
            return;
        }
        var currentEpoch = operationEpoch;
        window.setTimeout(function () {
            if (currentEpoch !== operationEpoch || !presenter.source || !presenter.source.isConnected) return;
            if (action.kind === 'run') {
                var result = presenter.table.runAction(action.id, context.row, null, context.index);
                if (result === false) {
                    clearPendingRecordFocus(presenter);
                    focusStable(presenter, context.trigger);
                }
            }
            else if (action.kind === 'status') presenter.table.updateField(context.row, 'status', categoryEnabled(context.row) ? 0 : 1, {reload: true});
        }, 0);
    }

    function saveCategorySort() {
        var context = activeRecord;
        var sheet = document.querySelector('[data-st-sheet="record-actions"]');
        var input = sheet && sheet.querySelector('.st-record-sort-editor input');
        if (!context || !input || context.presenter.page !== 'category') return;
        var value = Number(input.value);
        if (!Number.isInteger(value) || value < 1000 || value > 60000) {
            if (window.SeattleTheme && typeof window.SeattleTheme.notify === 'function') window.SeattleTheme.notify(i18n('排序需填写 1000 至 60000 的整数'), {type: 'error'});
            else if (window.message && typeof window.message.error === 'function') window.message.error(i18n('排序需填写 1000 至 60000 的整数'));
            input.focus();
            return;
        }
        var presenter = context.presenter;
        var row = context.row;
        closeRecordSheet(false);
        focusStable(presenter);
        presenter.table.updateField(row, 'sort', value, {reload: true});
    }

    function allPresenters() {
        return Array.from(presenters.values());
    }

    function pageFor(source) {
        var mobileRecord = source && source.getAttribute && source.getAttribute('data-st-mobile-record');
        if (mobileRecord === 'purchase') {
            return source.closest('[data-st-page="dashboard"], [data-st-page="purchase-record"]') ? 'purchase' : null;
        }
        if (mobileRecord === 'master-category' || mobileRecord === 'master-commodity') {
            return source.closest('[data-st-page="business"]') ? mobileRecord : null;
        }
        var page = source && source.closest && source.closest('[data-st-page][data-st-table]');
        if (!page) return null;
        var name = page.getAttribute('data-st-page') || '';
        if (supportedPages.indexOf(name) < 0) return null;
        var selector = page.getAttribute('data-st-table') || '';
        if (!selector || page.querySelector(selector) !== source) return null;
        return name;
    }

    function handleLifecycle(event, payload) {
        var detail = payload || event && event.detail || {};
        var table = detail.table;
        var snapshot = detail.snapshot;
        if (!table) return;
        if (event.type === 'admin:table:destroy') {
            var previous = presenters.get(table);
            if (previous && activeRecord && activeRecord.presenter === previous) {
                operationEpoch += 1;
                closeRecordSheet(false);
            }
            if (previous) teardownPresenter(previous, false);
            presenters.delete(table);
            return;
        }
        var source = snapshot && snapshot.element;
        var page = pageFor(source);
        if (!snapshot || !source || !page) return;
        var presenter = presenters.get(table);
        if (!presenter) {
            presenter = {table: table, snapshot: snapshot, source: source, page: page, list: null, pendingFocus: null, collapsedCategories: new Set(), treeTransitionTimer: null};
            presenters.set(table, presenter);
        } else {
            presenter.snapshot = snapshot;
            presenter.source = source;
            presenter.page = page;
        }
        renderPresenter(presenter);
    }

    function clearPresenters() {
        operationEpoch += 1;
        closeRecordSheet(false);
        allPresenters().forEach(function (presenter) { teardownPresenter(presenter, false); });
        presenters.clear();
        var sheet = document.querySelector('[data-st-sheet="record-actions"]');
        var trigger = document.querySelector('[data-st-record-sheet-trigger]');
        if (sheet) sheet.remove();
        if (trigger) trigger.remove();
    }

    function syncViewports() {
        allPresenters().forEach(function (presenter) {
            if (!isMobile() && activeRecord && activeRecord.presenter === presenter) {
                operationEpoch += 1;
                closeRecordSheet(false);
            }
            renderPresenter(presenter);
        });
    }

    if (window.__seattleMobileRecordsDestroy) window.__seattleMobileRecordsDestroy();
    if (window.__seattleMobileRecordsMediaCleanup) window.__seattleMobileRecordsMediaCleanup();

    if (window.jQuery) {
        var $document = window.jQuery(document);
        $document.off(namespace)
            .on('admin:table:ready' + namespace + ' admin:table:update' + namespace + ' admin:table:destroy' + namespace, handleLifecycle)
            .on('click' + namespace, '.st-record-mobile-row', function () { openRecordOrPrimary(this); })
            .on('click' + namespace, '[data-st-record-menu-index]', function () {
                var row = this.parentElement && this.parentElement.querySelector('.st-record-mobile-row');
                if (row) openRecord(row);
            })
            .on('click' + namespace, '[data-st-record-action]', function () { invokeRecordAction(Number(this.getAttribute('data-st-record-action'))); })
            .on('click' + namespace, '[data-st-record-sort-save]', saveCategorySort)
            .on('click' + namespace, '[data-st-record-retry]', function () {
                var list = this.closest('.st-record-mobile-list');
                var presenter = allPresenters().find(function (item) { return item.list === list; });
                var retry = presenter && presenter.snapshot && presenter.snapshot.status && presenter.snapshot.status.retry;
                if (typeof retry === 'function') retry();
            })
            .on('click' + namespace, '[data-st-record-select]', function () {
                var source = sourceTableFor(this);
                var presenter = allPresenters().find(function (item) { return item.source === source; });
                var index = Number(this.getAttribute('data-st-record-select'));
                var row = presenter && presenter.snapshot.rows[index];
                if (!presenter || !row || this.disabled) return;
                presenter.pendingFocus = {type: 'selection', id: this.getAttribute('data-st-record-id') || ''};
                presenter.table.setRowSelected(row, this.getAttribute('aria-pressed') !== 'true');
            })
            .on('click' + namespace, '[data-st-record-select-all]', function () {
                var list = this.closest('.st-record-mobile-list');
                var presenter = allPresenters().find(function (item) { return item.list === list; });
                if (!presenter) return;
                var select = this.getAttribute('aria-pressed') !== 'true';
                presenter.pendingFocus = {type: 'all'};
                (presenter.snapshot.selection.rowStates || []).forEach(function (state) {
                    if (state.selectable !== false) presenter.table.setRowSelected(state.row, select);
                });
            })
            .on('click' + namespace, '[data-st-record-tree-id]', function () {
                toggleCategoryBranch(this);
            })
            .on('click' + namespace, '[data-st-sheet-close]', function () {
                if (!activeRecord) return;
                var restore = Boolean(this.closest('[data-st-sheet="record-actions"]') || this.classList.contains('st-sheet-backdrop'));
                window.setTimeout(function () {
                    if (!activeRecord) return;
                    var trigger = activeRecord.trigger;
                    activeRecord = null;
                    activeActions = [];
                    if (restore && trigger && trigger.isConnected) trigger.focus({preventScroll: true});
                }, 0);
            })
            .on('click' + namespace, '[data-st-sheet-open]:not([data-st-sheet-open="record-actions"])', function () {
                if (!activeRecord) return;
                activeRecord = null;
                activeActions = [];
            })
            .on('keydown' + namespace, '[data-st-sheet="record-actions"]', function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    closeRecordSheet(true);
                    return;
                }
                if (event.key !== 'Tab') return;
                var focusable = all('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', this).filter(function (node) {
                    return !node.hidden && Boolean(node.offsetWidth || node.offsetHeight || node.getClientRects().length);
                });
                if (!focusable.length) {
                    event.preventDefault();
                    return;
                }
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            })
            .on('pjax:send' + namespace + ' pjax:popstate' + namespace, clearPresenters);
    }

    if (mobileQuery) {
        if (typeof mobileQuery.addEventListener === 'function') {
            mobileQuery.addEventListener('change', syncViewports);
            window.__seattleMobileRecordsMediaCleanup = function () { mobileQuery.removeEventListener('change', syncViewports); };
        } else if (typeof mobileQuery.addListener === 'function') {
            mobileQuery.addListener(syncViewports);
            window.__seattleMobileRecordsMediaCleanup = function () { mobileQuery.removeListener(syncViewports); };
        }
    }

    window.__seattleMobileRecordsDestroy = function () {
        clearPresenters();
        if (window.jQuery) window.jQuery(document).off(namespace);
        if (window.__seattleMobileRecordsMediaCleanup) window.__seattleMobileRecordsMediaCleanup();
        window.__seattleMobileRecordsMediaCleanup = null;
    };
}());
