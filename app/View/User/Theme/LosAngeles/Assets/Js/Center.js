/* ============================================================================
   洛杉矶 · 会员中心运行时

   电脑端保留平台的 bootstrap-table（表格本来就适合看多列数据）；
   手机端把同一张表改渲成卡片流 —— 一行 8 列在 390px 宽的屏幕上是不可读的。

   平台为主题预留了正式接口：Table 会在 <table> 上派发
     admin:table:ready  / admin:table:update
   事件里带 snapshot（columns / rows / actions / selection / pagination / displayValue），
   所以不需要去解析 DOM，也不用改任何共享控制器。
   ========================================================================= */
(function (win, doc) {
    'use strict';

    var LA = win.LA;
    if (!LA) { return; }

    var qs = LA.qs, qsa = LA.qsa, on = LA.on, esc = LA.esc;
    var isApp = doc.body.classList.contains('la--app');

    // ------------------------------------------------------------ 表格 → 卡片 / 相册九宫格

    // 平台给可排序的列在 title 后面拼了一段排序按钮的 HTML（table.js: title + "<span class=btn-sort…>"），
    // 卡片里的字段名必须先把标签剥掉，否则整段 HTML 会被转义后当文字印出来。
    function textOf(html) {
        return String(html == null ? '' : html).replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
    }

    // 卡片里的内容是「渲染结果的副本」，它上面没有 bootstrap-table 绑的列事件（相册的选图、
    // 单元格里的代理按钮都是这么绑的）。点到可交互元素时，按同样的选择器 + 同样的序号，
    // 去隐藏着的真表格里找到那一个，替它点一下 —— 业务逻辑仍然只有平台那一份。
    function relay(table, rowIndex, scope, el) {
        var tr = table && table.tBodies[0] && table.tBodies[0].rows[rowIndex];
        if (!tr) { return false; }
        var cls = String(el.className || '').trim().split(/\s+/).filter(function (c) { return /^[A-Za-z_-][\w-]*$/.test(c); });
        var sel = el.tagName.toLowerCase() + (cls.length ? '.' + cls.join('.') : '');
        try {
            var mine = Array.prototype.slice.call(scope.querySelectorAll(sel));
            var pos = Math.max(0, mine.indexOf(el));
            var hits = tr.querySelectorAll(sel);
            var target = hits[pos] || hits[0];
            if (target) { target.click(); return true; }
        } catch (e) { /* 选择器不合法就放弃转发 */ }
        return false;
    }

    // 快照的 displayValue 给的是「纯文字」（平台特意把标签剥了）—— 拿它填卡片，徽章、商品胶囊、
    // 支付图标、金额的语义色就全丢了，一列列灰字堆在一起。这里直接从隐藏着的真表格里取那一格的
    // 渲染结果：卡片是 .bootstrap-table 的后代，Widget.css 那套胶囊皮原样生效，观感和电脑端一致。
    function cellHtml(table, index, field) {
        var ths = table.querySelectorAll('thead th');
        var ci = -1;
        for (var i = 0; i < ths.length; i++) {
            if (String(ths[i].getAttribute('data-field') || '') === String(field)) { ci = i; break; }
        }
        if (ci < 0) { return null; }
        var tr = table.querySelector('tbody > tr[data-index="' + index + '"]');
        var td = tr && tr.children[ci];
        if (!td) { return null; }
        var clone = td.cloneNode(true);
        qsa('script, style', clone).forEach(function (n) { n.remove(); });
        // 行内可编辑单元格：复制一份输入框会出现两个同名控件，换成它当前的值
        qsa('input.metadata-text, textarea.metadata-text', clone).forEach(function (n) {
            n.replaceWith(doc.createTextNode(n.value || ''));
        });
        qsa('select.metadata-select', clone).forEach(function (n) {
            n.replaceWith(doc.createTextNode(n.options[n.selectedIndex] ? n.options[n.selectedIndex].text : ''));
        });
        var html = clone.innerHTML.trim();
        return html === '' ? null : html;
    }

    function icon(d, w) {
        return '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (w || 1.75)
            + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>';
    }

    // 分页：平台那条是 PC 的页码条，手机端换成一枚「‹ 3 / 12 ›  共 51 条」的胶囊
    function pagerHtml(snapshot) {
        var p = snapshot.pagination || {};
        var total = Math.max(0, Number(p.total) || 0);
        var page = Math.max(1, Number(p.pageNumber) || 1);
        var pages = Math.max(1, Number(p.totalPages) || 1);
        if (!total) { return ''; }
        return '<div class="la-pager2">'
            + (pages > 1
                ? '<button type="button" class="la-pager2__btn" data-page="prev"' + (page <= 1 ? ' disabled' : '') + ' aria-label="' + esc(LA.t('上一页')) + '">'
                    + icon('<path d="m14.5 5.5-7 6.5 7 6.5"/>', 2) + '</button>'
                    + '<span class="la-pager2__now"><b>' + page + '</b> / ' + pages + '</span>'
                    + '<button type="button" class="la-pager2__btn" data-page="next"' + (page >= pages ? ' disabled' : '') + ' aria-label="' + esc(LA.t('下一页')) + '">'
                    + icon('<path d="m9.5 5.5 7 6.5-7 6.5"/>', 2) + '</button>'
                : '')
            + '<span class="la-pager2__total">' + esc(LA.t('共')) + ' ' + total + ' ' + esc(LA.t('条')) + '</span>'
            + '</div>';
    }

    function emptyHtml(label, hint) {
        return '<div class="la-empty la-empty--cards">'
            + icon('<path d="m12 3.2 8 4v9.6l-8 4-8-4V7.2z"/><path d="m4 7.2 8 4 8-4"/><path d="M12 11.2v9.6"/>', 1.2)
            + '<div class="la-empty__title">' + esc(label) + '</div>'
            + (hint ? '<div class="la-empty__hint">' + esc(hint) + '</div>' : '')
            + '</div>';
    }

    function cardList(snapshot) {
        var table = snapshot.element;
        if (!table) { return; }

        var wrap = table.closest('.bootstrap-table');
        if (!wrap) { return; }
        wrap.classList.add('la-mtable');

        var host = qs(':scope > .la-cards2', wrap);
        if (!host) {
            host = doc.createElement('div');
            host.className = 'la-cards2';
            var pager = qs('.fixed-table-pagination', wrap);
            pager ? wrap.insertBefore(host, pager) : wrap.appendChild(host);
        }
        // 事件处理器只绑一次，但每次重画都要拿"当前"快照：翻页 / 刷新 / 勾选后 rows 都换了，
        // 闭包里攥着第一份快照会把动作打到错误的行上
        host.__laSnap = snapshot;

        var rows = snapshot.rows || [];
        var status = snapshot.status || {};

        if (status.status === 'loading' && !rows.length) {
            host.innerHTML = '<div class="la-cards2__load"><span class="la-spinner"></span></div>';
            return;
        }
        if (status.status === 'error') {
            host.innerHTML = '<div class="la-empty la-empty--cards">'
                + icon('<circle cx="12" cy="12" r="8.4"/><path d="M12 8v4.6"/><path d="M12 15.8h.01"/>', 1.4)
                + '<div class="la-empty__title">' + esc(LA.t('加载失败')) + '</div>'
                + '<button type="button" class="la-cards2__retry" data-retry>' + esc(LA.t('重新加载')) + '</button></div>';
            return;
        }

        var actions = snapshot.actions || [];
        // 操作列已经变成卡片底部的按钮，正文里不该再出现一遍它的文字
        var actionFields = {};
        actions.forEach(function (a) { actionFields[a.field] = 1; });
        var columns = (snapshot.columns || []).filter(function (c) {
            return c && c.field && !c.mobileHidden && c.visible !== false && c.field !== 'state' && !actionFields[c.field];
        });
        // 一格的值：优先真表格的渲染结果（带徽章 / 胶囊 / 语义色），拿不到才退回快照的纯文字
        var valueOf = function (col, row, index) {
            var html = cellHtml(table, index, col.field);
            if (html !== null) { return html; }
            var text = snapshot.displayValue(col, row, index);
            return text === '' || text === null || text === undefined ? '' : esc(String(text));
        };

        // ---- 相册：一列图片的表，在手机上就该是九宫格，不是卡片流
        if (table.id === 'photo-album-table') {
            host.classList.add('la-cards2--photos');
            host.innerHTML = rows.length
                ? '<div class="la-photos">' + rows.map(function (row, index) {
                    var col = columns[0] || (snapshot.columns || [])[0] || {};
                    var html = String(cellHtml(table, index, col.field) || '');
                    var src = (html.match(/<img[^>]+src=["']([^"']+)["']/i) || [])[1] || '';
                    return '<button type="button" class="la-photos__cell" data-row="' + index + '">'
                        + (src ? '<img src="' + esc(src) + '" alt="" loading="lazy" decoding="async">' : '')
                        + '</button>';
                }).join('') + '</div>' + pagerHtml(snapshot)
                : emptyHtml(LA.t('相册还是空的'), LA.t('上传过的图片会出现在这里'));
            bind(host, snapshot);
            return;
        }

        if (!rows.length) {
            var value = (snapshot.search && snapshot.search.value) || {};
            var filtered = Object.keys(value).some(function (k) { return String(value[k] == null ? '' : value[k]) !== ''; });
            host.innerHTML = emptyHtml(
                filtered ? LA.t('没有符合条件的记录') : LA.t('暂时没有数据'),
                filtered ? LA.t('换个关键词或筛选条件试试') : ''
            );
            bind(host, snapshot);
            return;
        }

        // 标题列：第一个"有文字"的列。分类/商品这类表第一列是图标（标题 "#"、值是一张图，
        // 文本化之后只剩平台给图片的 title "放大图片"），拿它当标题就成了一排"放大图片"。
        // 图标列不丢：从渲染好的表格行里把 <img> 取出来当头像放到标题旁边。
        var decorative = function (col, row, index) {
            var title = textOf(col.title);
            if (title === '' || title === '#') { return true; }
            var value = String(valueOf(col, row, index) || '');
            var text = textOf(value);
            return text === '' || (/<img\b/i.test(value) && (text === title || text === '' || text === LA.t('放大图片')));
        };

        // 勾选：快照的 selection 带每行的 selectable / selected / id。卡片头上出一枚圆形勾选钮，
        // 勾选直接写回 bootstrap-table（checkBy / uncheckBy），平台随后派发 admin:table:update，
        // 卡片按新快照重画 —— 工具栏的「锁定选中 / 移除选中」这些批量按钮由此在手机上也能用。
        var pick = snapshot.selection && snapshot.selection.enabled && snapshot.selection.type === 'checkbox'
            ? snapshot.selection : null;
        var stateOf = function (row) {
            return pick ? ((pick.rowStates || []).filter(function (s) { return s.row === row; })[0] || null) : null;
        };
        var pickable = pick ? (pick.rowStates || []).filter(function (s) { return s.selectable; }) : [];
        var picked = pickable.filter(function (s) { return s.selected; });
        var allOn = pickable.length > 0 && picked.length === pickable.length;
        var selbar = pickable.length
            ? '<div class="la-cards2__selbar">'
                + '<button type="button" class="la-cards2__selall" data-tick-all="' + (allOn ? '0' : '1') + '">'
                + esc(LA.t(allOn ? '取消全选' : '全选本页')) + '</button>'
                + '<span class="la-cards2__selcount">' + esc(LA.t('已选')) + ' ' + picked.length + ' ' + esc(LA.t('条')) + '</span>'
                + '</div>'
            : '';

        // 标题列与状态胶囊列在整张表里只定一次 —— 按行各挑各的，列表会一行一个样子。
        // 标题优先取「这一行叫什么」的那一列（名称 / 单号 / 券码 / 卡密），否则第一个有文字的列。
        var TITLE_FIELDS = ['name', 'title', 'trade_no', 'code', 'secret', 'username', 'commodity.name', 'category.name'];
        var headIdx = 0;
        for (var hi = 0; hi < columns.length; hi++) {
            if (!decorative(columns[hi], rows[0], 0)) { headIdx = hi; break; }
        }
        var named = -1;
        for (var ti = 0; ti < TITLE_FIELDS.length && named < 0; ti++) {
            for (var ci = 0; ci < columns.length; ci++) {
                if (columns[ci].field === TITLE_FIELDS[ti] && !decorative(columns[ci], rows[0], 0)) { named = ci; break; }
            }
        }
        // 没有「叫什么」的列时（我的下级只有 ID + 一枚用户胶囊），把身份胶囊那列提上来当标题，
        // 总比让一串数字 ID 当标题好看也好认
        if (named < 0) {
            for (var pi = 0; pi < columns.length; pi++) {
                if (/table-item-name|md-user-cell/.test(String(valueOf(columns[pi], rows[0], 0) || ''))) { named = pi; break; }
            }
        }
        if (named >= 0) { headIdx = named; }
        var head = columns[headIdx];

        // 状态胶囊：整格只有一枚徽章、没有别的文字才算；优先叫 status/state 的那一列
        var isChipCell = function (col) {
            if (col === head) { return false; }
            var probe = String(valueOf(col, rows[0], 0) || '');
            if (!/^<(span|i|a|div)\b[^>]*class="[^"]*(a-badge|shipment-badge|uc-ticket-pill|badge-soft|uc-message-status)[^"]*"[^>]*>[\s\S]*<\/(span|i|a|div)>$/.test(probe.trim())) { return false; }
            return textOf(probe).length <= 8;
        };
        var chipIdx = -1;
        for (var si = 0; si < columns.length; si++) {
            if (/status|state/i.test(columns[si].field) && isChipCell(columns[si])) { chipIdx = si; break; }
        }
        if (chipIdx < 0) {
            for (var bi = 0; bi < columns.length; bi++) {
                if (isChipCell(columns[bi])) { chipIdx = bi; break; }
            }
        }

        host.innerHTML = selbar + rows.map(function (row, index) {
            var body = columns.filter(function (c, i) {
                if (i === headIdx || i === chipIdx) { return false; }
                return !(i < headIdx && decorative(c, row, index));
            });
            var chip = chipIdx >= 0 ? '<span class="la-cards2__chip">' + valueOf(columns[chipIdx], row, index) + '</span>' : '';

            var avatar = '';
            if (headIdx > 0) {
                var tr = table.tBodies[0] && table.tBodies[0].rows[index];
                var img = tr && tr.querySelector('td img');
                if (img && img.getAttribute('src')) {
                    avatar = '<img class="la-cards2__avatar" src="' + esc(img.getAttribute('src')) + '" alt="" loading="lazy" decoding="async">';
                }
            }

            var state = stateOf(row);
            var tick = state && state.selectable
                ? '<button type="button" class="la-cards2__tick' + (state.selected ? ' is-on' : '') + '" data-tick="' + index
                    + '" aria-pressed="' + (state.selected ? 'true' : 'false') + '" aria-label="' + esc(LA.t('选择')) + '"></button>'
                : '';

            var acts = actions.filter(function (a) {
                try { return a.show(row); } catch (e) { return false; }
            }).map(function (a) {
                return '<button type="button" class="la-cards2__act' + (a.danger ? ' is-danger' : '')
                    + '" data-act="' + esc(a.id) + '" data-row="' + index + '">' + esc(a.title) + '</button>';
            }).join('');

            var header = (tick || head || chip)
                ? '<header class="la-cards2__head">' + tick + avatar
                    + '<span class="la-cards2__title">' + (head ? valueOf(head, row, index) : '') + '</span>' + chip + '</header>'
                : '';

            return '<article class="la-cards2__item' + (state && state.selected ? ' is-selected' : '') + '" data-row="' + index + '">'
                + header
                + '<dl class="la-cards2__body">'
                + body.map(function (col) {
                    var value = valueOf(col, row, index);
                    if (!value) { return ''; }
                    var label = LA.t(textOf(col.title) || col.field);
                    return '<div><dt>' + esc(textOf(label)) + '</dt><dd>' + value + '</dd></div>';
                }).join('')
                + '</dl>'
                + (acts ? '<footer class="la-cards2__acts">' + acts + '</footer>' : '')
                + '</article>';
        }).join('') + pagerHtml(snapshot);

        bind(host, snapshot);
    }

    function bind(host, snapshot) {
        if (host.__laBound) { return; }
        host.__laBound = true;
        var snapOf = function () { return host.__laSnap || snapshot; };
        var apiOf = function (snap) {
            return win.jQuery && snap.element && win.jQuery(snap.element).bootstrapTable ? win.jQuery(snap.element) : null;
        };

        // 动作交给平台自己的 runAction，主题不复制任何业务逻辑
        on(host, 'click', '[data-act]', function (ev, el) {
            var snap = snapOf();
            var id = el.getAttribute('data-act');
            var index = parseInt(el.getAttribute('data-row'), 10) || 0;
            var action = (snap.actions || []).filter(function (a) { return a.id === id; })[0];
            action && action.invoke(ev, (snap.rows || [])[index], index);
        });

        // 勾选一行：写回 bootstrap-table，先乐观地翻一下钮，平台的 update 事件（下一拍）会整体重画
        on(host, 'click', '[data-tick]', function (ev, el) {
            var snap = snapOf(), api = apiOf(snap), s = snap.selection;
            if (!s || !api) { return; }
            var row = (snap.rows || [])[parseInt(el.getAttribute('data-tick'), 10) || 0];
            var st = (s.rowStates || []).filter(function (x) { return x.row === row; })[0];
            if (!st || !st.selectable) { return; }
            api.bootstrapTable(st.selected ? 'uncheckBy' : 'checkBy', {field: s.idField || 'id', values: [st.id]});
            el.classList.toggle('is-on', !st.selected);
            el.setAttribute('aria-pressed', st.selected ? 'false' : 'true');
        });

        on(host, 'click', '[data-tick-all]', function (ev, el) {
            var api = apiOf(snapOf());
            api && api.bootstrapTable(el.getAttribute('data-tick-all') === '1' ? 'checkAll' : 'uncheckAll');
        });

        // 相册选图：转发给隐藏表格里那张真图，平台的列事件照常触发
        on(host, 'click', '.la-photos__cell', function (ev, el) {
            var snap = snapOf();
            el.classList.add('is-picking');
            relay(snap.element, parseInt(el.getAttribute('data-row'), 10) || 0, el, el.querySelector('img') || el);
        });

        on(host, 'click', '[data-page]', function (ev, el) {
            var snap = snapOf(), api = apiOf(snap), p = snap.pagination || {};
            if (!api) { return; }
            var next = (Number(p.pageNumber) || 1) + (el.getAttribute('data-page') === 'prev' ? -1 : 1);
            api.bootstrapTable('selectPage', Math.max(1, Math.min(Number(p.totalPages) || 1, next)));
            var scroller = qs('[data-la-scroll]');
            scroller ? scroller.scrollTo({top: 0, behavior: 'smooth'}) : win.scrollTo({top: 0, behavior: 'smooth'});
        });

        on(host, 'click', '[data-retry]', function () {
            var s = snapOf().status;
            s && s.retry && s.retry();
        });

        // 卡片正文里的可交互元素（图片代理、单元格里的小按钮…）：转发到真表格
        on(host, 'click', '.la-cards2__body *, .la-cards2__title *', function (ev, el) {
            if (el.closest('[data-act],[data-tick],[data-tick-all],[data-page],[data-retry]')) { return; }
            var a = el.closest('a[href]');
            if (a && !/^(#|javascript:)/i.test(a.getAttribute('href') || '')) { return; }
            if (!/^(A|BUTTON|IMG|I|SPAN|SVG)$/.test(el.tagName)) { return; }
            if (el.tagName === 'SPAN' && !String(el.className || '').trim()) { return; }
            var item = el.closest('.la-cards2__item');
            if (!item) { return; }
            if (relay(snapOf().element, parseInt(item.getAttribute('data-row'), 10) || 0, item, el)) { ev.preventDefault(); }
        });
    }

    if (isApp) {
        // jQuery 触发的自定义事件会冒泡到 document，一次绑定覆盖所有表格
        if (win.jQuery) {
            win.jQuery(doc).on('admin:table:ready admin:table:update', function (ev, payload) {
                var snapshot = payload && payload.snapshot;
                if (snapshot) { cardList(snapshot); }
            });
        }
    }

    // ------------------------------------------------------------ 筛选条：起止日期收进一个盒子
    //
    // 平台 search.js 把一对日期吐成三个并列的 .layui-input-inline（起、"~"、止）。
    // Widget.css 把它们拼成了一个整体，但它们仍是三个独立的 flex 项目 —— 一行放不下时会从中间折行，
    // 左半截留在上一行、右半截掉到下一行（我的账单页就是这样）。这里把三件套包进一个 .la-daterange，
    // 折行只会整组一起折。输入框本身没动，laydate 的绑定（按 class 选）和表单取值（按 name）都不受影响。
    function wrapDateRanges() {
        qsa('.table-search .layui-input-inline.text-center').forEach(function (sep) {
            var prev = sep.previousElementSibling, next = sep.nextElementSibling;
            if (!prev || !next || sep.parentElement.classList.contains('la-daterange')) { return; }
            if (!prev.classList.contains('mui-sf') || !next.classList.contains('mui-sf')) { return; }
            var box = doc.createElement('div');
            box.className = 'layui-input-inline la-daterange';
            prev.parentNode.insertBefore(box, prev);
            box.appendChild(prev);
            box.appendChild(sep);
            box.appendChild(next);
        });
    }
    wrapDateRanges();
    if (win.jQuery) {
        win.jQuery(doc).on('admin:table:ready admin:table:update', wrapDateRanges);
    }

    // ------------------------------------------------------------ 筛选：搜索框 + 筛选抽屉（手机端）
    //
    // 平台 Table 的筛选表单（form.table-search）在手机上就是一叠 PC 输入框。APP 的做法：列表顶上一条
    // 「搜索框 + 筛选」—— 搜索框直接绑到表单里第一个文本条件，回车即查；点「筛选」把整张表单原样搬进
    // 底部抽屉（laydate / layui select / 查询按钮的绑定都跟着 DOM 走，不受影响），抽屉底部「重置 / 查询」。
    // 选了几个条件，筛选按钮上就标几。表单平时藏在 .la-fsheet-src 里，关掉抽屉后 LA.sheet 会把它送回去。
    var ICON = {
        search: '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.6 15.6 4.1 4.1"/></svg>',
        close: '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12"/><path d="m18 6-12 12"/></svg>',
        filter: '<svg class="la-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.6 5.4h16.8l-6.5 7.6v6.1l-3.8 1.9v-8z"/></svg>'
    };

    function filterBar(form) {
        if (form.__laBar || !LA.sheet) { return; }
        form.__laBar = true;

        var fields = function () {
            return qsa('.layui-input-inline.mui-sf', form).filter(function (el) { return !el.classList.contains('hide') && !el.closest('.hide'); });
        };
        var isDate = function (el) { return !!qs('input[class*="between-date-"]', el); };
        var textField = fields().filter(function (el) { return !el.classList.contains('mui-sf--select') && !isDate(el); })[0] || null;
        var textInput = textField ? qs('input.layui-input', textField) : null;
        var textLabel = textField && qs('.mui-sf__label', textField) ? qs('.mui-sf__label', textField).textContent.trim() : '';
        var runQuery = function () { var b = qs('.query-button', form); b && b.click(); };

        // 条件计数：文本 / 日期看有没有值，下拉看是否选了非首项；搜索框绑着的那个不算
        var count = function () {
            var n = 0;
            fields().forEach(function (el) {
                if (el === textField) { return; }
                if (el.classList.contains('mui-sf--select')) {
                    var s = qs('select', el);
                    if (s && s.selectedIndex > 0 && s.value !== '') { n++; }
                } else {
                    qsa('input.layui-input', el).forEach(function (i) { if (String(i.value || '').trim() !== '') { n++; } });
                }
            });
            return n;
        };

        var bar = doc.createElement('div');
        bar.className = 'la-fbar' + (textInput ? '' : ' la-fbar--solo');
        bar.innerHTML = (textInput
            ? '<label class="la-fbar__search">' + ICON.search
                + '<input type="search" enterkeyhint="search" autocomplete="off" placeholder="' + esc(textLabel) + '">'
                + '<button type="button" class="la-fbar__clear" hidden aria-label="' + esc(LA.t('清空')) + '">' + ICON.close + '</button></label>'
            : '')
            + '<button type="button" class="la-fbar__filter" aria-haspopup="dialog">' + ICON.filter
            + '<span>' + esc(LA.t('筛选')) + '</span><b class="la-fbar__badge" hidden>0</b></button>';

        // 表单收进一个可搬运的盒子：表单 + 吸底的「重置 / 查询」
        var holder = doc.createElement('div');
        holder.className = 'la-fsheet-src';
        var box = doc.createElement('div');
        box.className = 'la-fsheet';
        form.parentNode.insertBefore(bar, form);
        form.parentNode.insertBefore(holder, form);
        box.appendChild(form);
        box.insertAdjacentHTML('beforeend', '<div class="la-fsheet__foot">'
            + '<button type="button" class="la-fsheet__btn" data-la-freset>' + esc(LA.t('重置')) + '</button>'
            + '<button type="button" class="la-fsheet__btn la-fsheet__btn--go" data-la-fgo>' + esc(LA.t('查询')) + '</button></div>');
        holder.appendChild(box);

        var proxy = qs('input[type="search"]', bar);
        var clearBtn = qs('.la-fbar__clear', bar);
        var filterBtn = qs('.la-fbar__filter', bar);
        var badge = qs('.la-fbar__badge', bar);

        var sync = function () {
            var n = count();
            badge.hidden = n === 0;
            badge.textContent = String(n);
            filterBtn.classList.toggle('is-on', n > 0);
            if (proxy) { proxy.value = textInput.value; clearBtn.hidden = proxy.value === ''; }
        };

        if (proxy) {
            var push = function () {
                textInput.value = proxy.value;
                textInput.dispatchEvent(new Event('input', {bubbles: true}));
            };
            proxy.addEventListener('input', function () { clearBtn.hidden = proxy.value === ''; });
            proxy.addEventListener('keydown', function (ev) {
                if (ev.key !== 'Enter') { return; }
                ev.preventDefault();
                push(); runQuery(); proxy.blur();
            });
            proxy.addEventListener('search', function () { push(); runQuery(); });
            clearBtn.addEventListener('click', function () { proxy.value = ''; push(); clearBtn.hidden = true; runQuery(); });
        }

        filterBtn.addEventListener('click', function () { LA.sheet.open(LA.t('筛选'), box, {onClose: sync}); });
        on(box, 'click', '[data-la-fgo]', function () { runQuery(); LA.sheet.close(); });
        on(box, 'click', '[data-la-freset]', function () {
            qsa('input.layui-input', form).forEach(function (i) { i.value = ''; i.dispatchEvent(new Event('input', {bubbles: true})); });
            qsa('select', form).forEach(function (s) { s.selectedIndex = 0; });
            try { win.layui && win.layui.form && win.layui.form.render('select'); } catch (e) { /* 忽略 */ }
            runQuery();
            LA.sheet.close();
        });
        sync();
    }

    if (isApp) {
        var mountFilters = function () { qsa('form.table-search').forEach(filterBar); };
        mountFilters();
        if (win.jQuery) {
            win.jQuery(doc).on('admin:table:ready admin:table:update', mountFilters);
        }
    }

    // ------------------------------------------------------------ 会员中心杂项

    // 个人资料页的两个面板由平台控制器切换，这里只补一个「回到顶部」的滚动复位
    on(doc, 'click', '.uc-subtab a[data-ptab]', function () {
        var scroller = qs('[data-la-scroll]');
        scroller ? (scroller.scrollTop = 0) : win.scrollTo({top: 0, behavior: 'smooth'});
    });

    // 复制按钮：平台只在个别控制器里绑了 .clipboard，这里补一个通用出口
    on(doc, 'click', '[data-la-copy]', function (ev, el) {
        LA.copy(el.getAttribute('data-la-copy') || el.textContent, LA.t('已复制'));
    });

    LA.lazy();
    LA.reveal();

    // ------------------------------------------------------------ 我的店铺：分类 → 商品 联动
    //
    // 平台 business.js 里「查看商品」按钮（操作列第一个、class text-success）会把商品表按分类重载。
    // 商品表在另一个 Tab 里，用户点完看不到变化 —— 这里自动切过去，并在商品表上方标出当前分类。
    (function shopTabs() {
        var root = qs('[data-la-tabs="business"]');
        if (!root) { return; }
        var chip = qs('[data-la-shop-filter]', root);
        var chipName = qs('[data-la-shop-filter-name]', root);

        on(root, 'click', '[data-la-pane="category"] .a-badge-glass.text-success', function (ev, el) {
            var row = el.closest('tr');
            var name = row ? (qs('.table-item-name', row) || row.cells[1] || row).textContent.trim() : '';
            if (chip) {
                chip.hidden = false;
                chipName && (chipName.textContent = name);
            }
            LA.tabsGo(root, 'commodity');
        });

        // 「显示全部」：平台没暴露清除筛选的接口，整页回到商品 Tab 重载是最稳的
        on(root, 'click', '[data-la-shop-filter-clear]', function (ev) {
            ev.preventDefault();
            win.location.hash = '#tab-commodity';
            win.location.reload();
        });
    })();
})(window, document);
