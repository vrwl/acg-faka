/**
 * WkDock 网课订单进度前台脚本
 *
 * 功能：
 * 1. 在订单列表（购买记录/最近购买）操作列注入「进度」按钮，仅针对已付款的网课订单
 * 2. 游客查单页（订单卡片）内注入同款「进度」入口，单号是否为网课订单由开关接口确认
 * 3. 点击后弹出面板，展示该学生账号在上游平台的全部课程记录（不限制本店课程），
 *    支持补刷、暂停、改密，改密成功后回写订单中的学生密码
 *
 * 适配策略：不改商城核心文件与主题模板。
 * - 订单列表：通过表格容器上的 adminTable 实例（jQuery data）识别订单列表，
 *   从实例行数据里读取 trade_no / status / wk_status，不依赖列顺序与单元格文本。
 * - 游客查单页：各主题卡片结构不同，以「订单号落点」为锚点定位卡片；
 *   查单接口出参不含对接字段，故批量问一次开关接口后才注入（缓存结果，避免重复请求）。
 * - 表格渲染是异步且会随分页重绘，故用 MutationObserver 去抖后重复注入（幂等）。
 */
!function () {
    "use strict";

    var API = "/plugin/WkDock/api/order";
    var MARK_API = "/plugin/WkDock/api/query/mark";
    var ORDER_URL = "purchaseRecord/data";
    var ICON = '<i class="fa-duotone fa-regular fa-list-check"></i> ';
    //补刷/暂停的确认文案（整句，便于翻译）
    var CONFIRM = {
        budan: "确定要补刷该课程吗？",
        zt: "确定要暂停该课程吗？"
    };

    var bootTimer = null;
    //游客查单页的网课判定结果（true=网课订单，false=非网课订单），以及待确认/请求中的单号
    var ocMark = {};
    var ocPending = {};
    var ocBusy = false;

    function esc(value) {
        return String(value == null ? "" : value).replace(/[&<>"']/g, function (c) {
            return {"&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"}[c];
        });
    }

    function injectStyle() {
        if (jQuery("#wk-order-style").length > 0) {
            return;
        }
        //面板配色：统一走主题令牌。新版主题（洛杉矶/涩谷/西雅图/富士/纽约）把 --md-* 映射到自己的明暗令牌上，
        //跟随夜间模式自动换色；老主题没有这些变量时回退到原来的浅色值，观感不变。
        //主题给 layer 套了半透明玻璃皮肤（白60%+25px圆角+灰标题），面板会发灰发虚，这里单独覆盖
        var css = [
            ':root{--wk-surface:var(--md-surface,#fff);--wk-soft:var(--md-surface-2,#f7f8fa);--wk-bg:var(--md-bg,#f5f6f8);--wk-fg:var(--md-on-surface,#1f2430);--wk-fg-2:var(--md-on-surface-med,#5b6472);--wk-fg-3:var(--md-on-surface-dis,#98a1b0);--wk-line:var(--md-divider,#eceef2);--wk-line-2:var(--md-outline,#dfe3ea);--wk-ok:var(--md-success,#1f9e54);--wk-warn:var(--md-warning,#b4790d);--wk-err:var(--md-error,#d9534f);--wk-brand:#3568f0;--wk-brand-rgb:53,104,240;--wk-brand-soft:rgba(53,104,240,.1);--wk-on-brand:#fff;}',
            //夜间模式：中性色跟随主题令牌，主色单独提亮一档（深底上用 #3568f0 会发暗）
            '[data-theme="dark"]{--wk-brand:#6a9bff;--wk-brand-rgb:106,155,255;--wk-brand-soft:rgba(106,155,255,.16);--wk-on-brand:#0e0e10;}',

            '.wk-layer{background:var(--wk-surface) !important;border-radius:14px !important;overflow:hidden;box-shadow:0 20px 50px -16px rgba(16,24,40,.32) !important;}',
            '.layui-layer.wk-layer .layui-layer-title{height:52px;line-height:52px;padding:0 60px 0 20px;background:var(--wk-surface) !important;border-bottom:1px solid var(--wk-line) !important;border-radius:0;box-shadow:none;color:var(--wk-fg);font-size:16px !important;font-weight:600;}',
            '.layui-layer.wk-layer .layui-layer-title i{color:var(--wk-brand);}',
            '.layui-layer.wk-layer .layui-layer-content{background:var(--wk-surface);}',

            '.wk-panel{display:flex;flex-direction:column;background:var(--wk-bg);color:var(--wk-fg);font-size:13px;border-radius:inherit;}',
            '.wk-panel__hd{flex:none;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;background:var(--wk-surface);border-bottom:1px solid var(--wk-line);}',
            '.wk-panel__acct{display:flex;align-items:baseline;gap:6px;min-width:0;}',
            '.wk-panel__acct-k{flex:none;color:var(--wk-fg-3);font-size:12px;}',
            '.wk-panel__acct-v{color:var(--wk-fg);font-size:14px;font-weight:600;word-break:break-all;}',
            '.wk-panel__count{flex:none;padding:3px 10px;border-radius:999px;background:var(--wk-brand-soft);color:var(--wk-brand);font-size:12px;font-weight:500;line-height:1.5;white-space:nowrap;}',
            '.wk-panel__list{flex:none;height:66vh;max-height:700px;overflow-y:auto;overscroll-behavior:contain;padding:14px 16px 16px;display:flex;flex-direction:column;gap:10px;}',
            '@media (max-width:767px){.wk-panel__list{height:72vh;max-height:none;}}',
            '.wk-panel__list::-webkit-scrollbar{width:6px;}',
            '.wk-panel__list::-webkit-scrollbar-track{background:transparent;}',
            '.wk-panel__list::-webkit-scrollbar-thumb{background:var(--md-on-surface-dis,rgba(31,36,48,.18));border-radius:999px;}',
            '.wk-panel__list::-webkit-scrollbar-thumb:hover{background:var(--md-on-surface-med,rgba(31,36,48,.32));}',

            '.wk-rec{padding:12px 14px;border-radius:12px;background:var(--wk-surface);border:1px solid var(--wk-line);box-shadow:0 1px 2px rgba(16,24,40,.05);transition:box-shadow .2s ease;}',
            '.wk-rec:hover{box-shadow:0 6px 18px -4px rgba(16,24,40,.13);}',
            '.wk-rec__head{display:flex;align-items:flex-start;gap:10px;}',
            '.wk-rec__name{flex:1;min-width:0;font-size:14px;font-weight:600;line-height:1.5;color:var(--wk-fg);word-break:break-word;}',
            '.wk-rec__status{flex:none;padding:2px 10px;border-radius:999px;background:var(--wk-brand-soft);color:var(--wk-brand);font-size:12px;font-weight:500;line-height:1.6;white-space:nowrap;}',
            '.wk-rec__status--ok{background:rgba(31,158,84,.12);color:var(--wk-ok);}',
            '.wk-rec__status--err{background:rgba(217,83,79,.12);color:var(--wk-err);}',
            '.wk-rec__status--paused{background:rgba(212,138,13,.14);color:var(--wk-warn);}',

            '.wk-rec__progress{display:flex;align-items:center;gap:10px;margin-top:10px;}',
            '.wk-rec__progress-k{flex:none;color:var(--wk-fg-3);font-size:12px;}',
            '.wk-rec__bar{flex:1;min-width:0;height:6px;border-radius:999px;background:var(--wk-line);overflow:hidden;}',
            '.wk-rec__bar i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,rgba(var(--wk-brand-rgb),.55),var(--wk-brand));transition:width .3s ease;}',
            '.wk-rec__pct{flex:none;min-width:38px;text-align:right;color:var(--wk-brand);font-size:12px;font-weight:600;font-variant-numeric:tabular-nums;}',

            '.wk-rec__meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px 16px;margin-top:8px;font-size:12px;line-height:1.5;}',
            '@media (max-width:767px){.wk-rec__meta{grid-template-columns:minmax(0,1fr);}}',
            '.wk-meta{display:flex;gap:6px;min-width:0;}',
            '.wk-meta__k{flex:none;color:var(--wk-fg-3);white-space:nowrap;}',
            '.wk-meta__v{color:var(--wk-fg-2);word-break:break-word;min-width:0;}',
            '.wk-rec__remark{margin-top:8px;padding:8px 10px;border-radius:8px;background:var(--wk-soft);color:var(--wk-fg-2);font-size:12px;line-height:1.6;word-break:break-word;}',
            '.wk-rec__remark--err{background:rgba(217,83,79,.07);color:var(--wk-err);}',

            '.wk-rec__ops{display:flex;gap:8px;margin-top:10px;}',
            '.wk-rec__btn{flex:1;min-width:0;height:34px;padding:0 12px;border:1px solid transparent;border-radius:8px;background:var(--wk-brand);color:var(--wk-on-brand);font-family:inherit;font-size:13px;font-weight:500;line-height:1;cursor:pointer;transition:background .15s,border-color .15s,color .15s,box-shadow .15s;}',
            '.wk-rec__btn:hover{filter:brightness(1.12);box-shadow:0 2px 8px -2px rgba(var(--wk-brand-rgb),.5);}',
            '.wk-rec__btn:active{transform:translateY(1px);}',
            '.wk-rec__btn--warn{background:rgba(212,138,13,.12);color:var(--wk-warn);border-color:rgba(212,138,13,.32);}',
            '.wk-rec__btn--warn:hover{background:rgba(212,138,13,.22);border-color:rgba(212,138,13,.32);box-shadow:none;}',
            '.wk-rec__btn--ghost{background:var(--wk-surface);color:var(--wk-fg-2);border-color:var(--wk-line-2);}',
            '.wk-rec__btn--ghost:hover{background:var(--wk-soft);color:var(--wk-fg);border-color:var(--wk-line-2);box-shadow:none;}',

            //游客查单页入口：描边胶囊按钮（.order-right 是 align-items:flex-end 的纵向 flex，靠 gap 留白，故不再加 margin-top）
            '.wk-entry{display:inline-flex !important;align-items:center;justify-content:center;gap:5px;padding:0 12px;height:30px;box-sizing:border-box;border:1px solid var(--wk-brand) !important;border-radius:999px !important;background:var(--wk-surface) !important;color:var(--wk-brand) !important;font-size:13px;font-weight:500;line-height:1;text-decoration:none !important;cursor:pointer;transition:background .15s,border-color .15s,color .15s;}',
            '.wk-entry:hover{background:var(--wk-brand) !important;color:var(--wk-on-brand) !important;}',
            '.wk-entry i{color:inherit;font-size:13px;}',

            '.wk-empty{display:flex;flex-direction:column;align-items:center;gap:10px;padding:56px 0;color:var(--wk-fg-3);}',
            '.wk-empty i{font-size:28px;opacity:.55;}',

            //补刷/暂停/改密的 layer 确认框/输入框加插件专用标记类 wk-dlg：
            //layer.prompt 会用调用方传入的 skin 覆盖它自带的类名，所以用 success 回调追加，
            //深色模式样式只作用于带该标记的弹窗，不碰主题自己的弹窗
            '[data-theme="dark"] .layui-layer.wk-dlg{background:var(--wk-surface) !important;border:1px solid var(--wk-line) !important;color:var(--wk-fg) !important;}',
            '[data-theme="dark"] .layui-layer.wk-dlg .layui-layer-title{background:var(--wk-surface) !important;border-bottom:1px solid var(--wk-line) !important;color:var(--wk-fg) !important;}',
            '[data-theme="dark"] .layui-layer.wk-dlg .layui-layer-content{color:var(--wk-fg) !important;}',
            '[data-theme="dark"] .layui-layer.wk-dlg .layui-layer-input{background:var(--wk-soft) !important;border-color:var(--wk-line-2) !important;color:var(--wk-fg) !important;}',
            '[data-theme="dark"] .layui-layer.wk-dlg .layui-layer-btn a{background:var(--wk-soft) !important;border-color:var(--wk-line-2) !important;color:var(--wk-fg) !important;}',
            '[data-theme="dark"] .layui-layer.wk-dlg .layui-layer-btn .layui-layer-btn0{background:var(--wk-brand) !important;border-color:var(--wk-brand) !important;color:var(--wk-on-brand) !important;}',
            //关闭按钮是深色雪碧图，深底上要反相才看得见
            '[data-theme="dark"] .layui-layer.wk-layer .layui-layer-setwin span,[data-theme="dark"] .layui-layer.wk-dlg .layui-layer-setwin span{filter:invert(1) brightness(1.1);}'
        ].join('\n');
        jQuery('head').append('<style id="wk-order-style">' + css + '</style>');
    }

    //订单列表：从表格实例取行数据，按订单状态与对接状态判定是否注入
    function injectTableButtons() {
        jQuery("table").each(function () {
            var $table = jQuery(this);
            var inst = $table.data("adminTable");
            if (!inst || typeof inst.queryUrl !== "string" || inst.queryUrl.indexOf(ORDER_URL) === -1) {
                return;
            }
            var rows;
            try {
                rows = inst.getRows() || [];
            } catch (e) {
                return;
            }
            $table.find("tbody > tr[data-index]").each(function () {
                var $tr = jQuery(this);
                var row = rows[parseInt($tr.attr("data-index"), 10)];
                if (!row || Number(row.status) !== 1 || !(Number(row.wk_status) > 0)) {
                    return;
                }
                var tradeNo = String(row.trade_no || "");
                var $cell = $tr.children("td").last();
                if (tradeNo === "" || $cell.length === 0 || $cell.find("[data-wk-order]").length > 0) {
                    return;
                }
                //操作列可能只有占位符「-」，注入前清掉
                if (jQuery.trim($cell.text()) === "-") {
                    $cell.empty();
                }
                $cell.append('<a type="button" role="button" tabindex="0" data-wk-order="1" data-trade="' + esc(tradeNo) + '" class="a-badge-glass text-primary me-1 mb-1">' + ICON + '<span class="btn-title">' + i18n('查询进度') + '</span></a>');
            });
        });
    }

    //查单结果页各主题卡片结构不同，以「订单号落点」为锚点定位卡片，再挑卡片内的操作区放按钮
    //新增主题只需往这三个选择器里补一项
    var GUEST_ANCHOR = ".order-no-text, .la-ocard__no, [data-trade-no], [data-sb-order]";
    var GUEST_CARD = ".order-item, .la-ocard, .sb-order, .st-query-order, article, li";
    var GUEST_HOST = ".order-right, .la-ocard__foot, .sb-order__actions, .st-query-order-head, .order-header";

    //统一取单号：优先 data 属性（洛杉矶/涩谷的卡片带了单号属性），其次文本（通用/西雅图）
    function guestTradeNo($anchor) {
        var value = jQuery.trim(String($anchor.attr("data-trade-no") || $anchor.attr("data-sb-order") || ""));
        if (value === "") {
            value = jQuery.trim($anchor.text());
        }
        value = value.replace(/^[#＃]\s*/, "").replace(/^订单号[:：]?\s*/, "");
        return /^[0-9A-Za-z_-]{6,}$/.test(value) ? value : "";
    }

    //游客查单页：订单卡片内注入入口（仅限已确认是网课订单的单号）
    function injectGuestButtons() {
        var queue = [];
        jQuery(GUEST_ANCHOR).each(function () {
            var $anchor = jQuery(this);
            var $card = $anchor.closest(GUEST_CARD);
            if ($card.length === 0) {
                $card = $anchor.parent();
            }
            if ($card.find("[data-wk-order]").length > 0) {
                return;
            }
            var tradeNo = guestTradeNo($anchor);
            if (tradeNo === "") {
                return;
            }
            if (ocMark[tradeNo] === undefined) {
                //还不知道是不是网课订单，攒起来一次问完，避免非网课订单也露出入口
                queue.push(tradeNo);
                return;
            }
            if (ocMark[tradeNo] !== true) {
                return;
            }
            var $host = null;
            jQuery.each(GUEST_HOST.split(","), function (i, selector) {
                var $hit = $card.find(selector).first();
                if ($hit.length > 0) {
                    $host = $hit;
                    return false;
                }
            });
            if (!$host) {
                //主题没有自带操作区时，直接放卡片末尾
                $host = $card;
            }
            $host.append('<a type="button" role="button" tabindex="0" data-wk-order="1" data-trade="' + esc(tradeNo) + '" data-wk-guest="1" class="wk-entry">' + ICON + i18n('查询进度') + '</a>');
        });
        markOrders(queue);
    }

    //批量确认单号是否为网课订单：结果缓存，同一单号只问一次
    function markOrders(tradeNos) {
        if (ocBusy) {
            return;
        }
        var todo = [];
        for (var i = 0; i < tradeNos.length; i++) {
            if (ocMark[tradeNos[i]] === undefined && !ocPending[tradeNos[i]]) {
                ocPending[tradeNos[i]] = 1;
                todo.push(tradeNos[i]);
            }
        }
        if (todo.length === 0) {
            return;
        }
        ocBusy = true;

        //收尾：confirmed 为命中的单号数组，null 表示这次没拿到结果（不定论，等下次重试）
        function finish(confirmed) {
            for (var j = 0; j < todo.length; j++) {
                if (confirmed) {
                    ocMark[todo[j]] = confirmed.indexOf(todo[j]) !== -1;
                }
                delete ocPending[todo[j]];
            }
            ocBusy = false;
            if (confirmed) {
                schedule();
            }
        }

        //静默请求：失败不提示，也不注入入口
        util.post({
            url: MARK_API,
            loader: false,
            data: {trade_no: todo.join(",")},
            done: function (res) {
                var list = (res && res.data && res.data.list) || [];
                var confirmed = [];
                for (var i = 0; i < list.length; i++) {
                    confirmed.push(String(list[i]));
                }
                finish(confirmed);
            },
            error: false,
            fail: function () {
                finish(null);
            }
        });
    }

    function injectAll() {
        injectStyle();
        injectTableButtons();
        injectGuestButtons();
    }

    //游客页面：单号对应的查单密码（已输入过则复用）
    function guestPassword(tradeNo) {
        var $input = jQuery(".passin-" + tradeNo).first();
        return $input.length > 0 ? String($input.val() || "") : "";
    }

    function openPanel(tradeNo, password) {
        util.post({
            url: API + "/list",
            data: {trade_no: tradeNo, password: password},
            done: function (res) {
                showPanel(tradeNo, password, res && res.data ? res.data : {});
            }
        });
    }

    function showPanel(tradeNo, password, data) {
        layer.open({
            type: 1,
            skin: 'wk-layer',
            title: ICON + i18n('课程进度'),
            area: util.isPc() ? '640px' : '96%',
            shadeClose: true,
            content: '<div class="wk-panel"><div class="wk-panel__hd"></div><div class="wk-panel__list"></div></div>',
            success: function (layero) {
                fillPanel(layero, tradeNo, password, data);
            }
        });
    }

    function fillPanel(layero, tradeNo, password, data) {
        var list = (data && data.list) || [];
        layero.find(".wk-panel__hd").html(
            '<div class="wk-panel__acct"><span class="wk-panel__acct-k">' + i18n('学生账号') + '</span>'
            + '<span class="wk-panel__acct-v">' + esc(data && data.account) + '</span></div>'
            + '<span class="wk-panel__count">' + list.length + ' ' + i18n('条记录') + '</span>'
        );

        var $list = layero.find(".wk-panel__list");
        if (list.length === 0) {
            $list.html('<div class="wk-empty">' + ICON + i18n('暂未查询到课程记录') + '</div>');
            return;
        }
        var html = "";
        for (var i = 0; i < list.length; i++) {
            html += cardHtml(list[i]);
        }
        $list.html(html);

        $list.off("click.wk").on("click.wk", "[data-wk-act]", function () {
            var $btn = jQuery(this);
            var act = String($btn.attr("data-wk-act") || "");
            var yid = String($btn.closest(".wk-rec").attr("data-yid") || "");
            if (yid === "") {
                return;
            }
            if (act === "xgmm") {
                layer.prompt({formType: 1, title: i18n('请输入该学生账号的新密码'), maxlength: 64, success: markDialog}, function (value, index) {
                    layer.close(index);
                    doAction(layero, tradeNo, password, yid, act, String(value || "").trim());
                });
                return;
            }
            layer.confirm(i18n(CONFIRM[act] || '确定要执行该操作吗？'), {icon: 3, title: i18n('提示'), success: markDialog}, function (index) {
                layer.close(index);
                doAction(layero, tradeNo, password, yid, act, "");
            });
        });
    }

    //给插件自己的 layer 弹窗打标记类（见 injectStyle 中 wk-dlg 的用法）
    function markDialog(layero) {
        layero.addClass("wk-dlg");
    }

    //状态徽标：异常/失败→红，暂停→橙，完成→绿，其余（已提交/补刷中…）→蓝
    function statusClass(status) {
        if (/异常|失败|错误/.test(status)) {
            return " wk-rec__status--err";
        }
        if (/暂停|停止/.test(status)) {
            return " wk-rec__status--paused";
        }
        if (/未完成/.test(status)) {
            return "";
        }
        if (/完成|成功/.test(status)) {
            return " wk-rec__status--ok";
        }
        return "";
    }

    function cardHtml(rec) {
        var status = String(rec.status || "");
        var cls = "wk-rec__status" + statusClass(status);
        var isErr = /异常|失败|错误/.test(status);

        //平台未说明进度量纲：<=1 视为比例，否则视为百分数
        var process = String(rec.process || "");
        var percent = 0;
        if (process !== "") {
            var num = parseFloat(process);
            if (!isNaN(num)) {
                percent = num <= 1 ? num * 100 : num;
            }
            percent = Math.max(0, Math.min(100, percent));
        }

        //元信息两列排布：学校/平台同一行，提交时间另起一行
        function metaCell(key, value) {
            return '<span class="wk-meta"><span class="wk-meta__k">' + i18n(key) + '</span><span class="wk-meta__v">' + esc(value) + '</span></span>';
        }

        var meta = "";
        if (rec.school) {
            meta += metaCell('学校', rec.school);
        }
        if (rec.platform) {
            meta += metaCell('平台', rec.platform);
        }
        if (rec.addtime) {
            meta += metaCell('提交时间', rec.addtime);
        }

        return '<div class="wk-rec" data-yid="' + esc(rec.yid) + '">'
            + '<div class="wk-rec__head"><div class="wk-rec__name">' + esc(rec.name) + '</div>'
            + (status !== "" ? '<span class="' + cls + '">' + esc(status) + '</span>' : '')
            + '</div>'
            + (process !== ""
                ? '<div class="wk-rec__progress"><span class="wk-rec__progress-k">' + i18n('进度') + '</span>'
                    + '<span class="wk-rec__bar"><i style="width:' + percent + '%"></i></span>'
                    + '<span class="wk-rec__pct">' + Math.round(percent) + '%</span></div>'
                : '')
            + (meta !== "" ? '<div class="wk-rec__meta">' + meta + '</div>' : '')
            + (rec.remark ? '<div class="wk-rec__remark' + (isErr ? ' wk-rec__remark--err' : '') + '">' + esc(rec.remark) + '</div>' : '')
            + '<div class="wk-rec__ops">'
            + '<button type="button" class="wk-rec__btn" data-wk-act="budan">' + i18n('补刷') + '</button>'
            + '<button type="button" class="wk-rec__btn wk-rec__btn--warn" data-wk-act="zt">' + i18n('暂停') + '</button>'
            + '<button type="button" class="wk-rec__btn wk-rec__btn--ghost" data-wk-act="xgmm">' + i18n('改密') + '</button>'
            + '</div></div>';
    }

    function doAction(layero, tradeNo, password, yid, act, newPassword) {
        var payload = {trade_no: tradeNo, password: password, yid: yid, act: act};
        if (act === "xgmm") {
            payload.new_password = newPassword;
        }
        util.post({
            url: API + "/action",
            data: payload,
            done: function (res) {
                message.success((res && res.data && res.data.message) || i18n('操作成功'));
                util.post({
                    url: API + "/list",
                    data: {trade_no: tradeNo, password: password},
                    done: function (res2) {
                        fillPanel(layero, tradeNo, password, res2 && res2.data ? res2.data : {});
                    }
                });
            }
        });
    }

    function schedule() {
        clearTimeout(bootTimer);
        bootTimer = setTimeout(function () {
            bootTimer = null;
            injectAll();
        }, 200);
    }

    documentReady(function () {
        schedule();
        if (!window.jQuery) {
            return;
        }
        //入口在表格里，表格渲染/分页/换页都会重建 DOM
        jQuery(document).on("click", "a[data-wk-order]", function () {
            var tradeNo = String(jQuery(this).attr("data-trade") || "");
            if (tradeNo === "") {
                return;
            }
            openPanel(tradeNo, guestPassword(tradeNo));
        });
        jQuery(document).on("pjax:complete", schedule);
        if (window.MutationObserver && document.body) {
            new MutationObserver(schedule).observe(document.body, {childList: true, subtree: true});
        }
    });
}();
