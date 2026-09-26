/* ============================================================================
   NewYork 纽约 — 手机购物 APP 主题运行时
   ----------------------------------------------------------------------------
   职责:
     1. 外观模式(白天/黑夜/自动,newyork.theme.preference)
     2. 底部面板与工作台
     3. 列表引擎:原 bootstrap-table 保留为数据与事件载体,
        按「每页配方」把行重组为 APP 卡片(按 th[data-field] 映射)
     4. 列表周边:快捷搜索、全屏筛选面板、选择模式与批量面板、分页计数
     5. 弹窗运行时:layui 表单 MUI 悬浮标签、卡密遮罩
     6. 商城:购买坞状态、支付面板、搜索
     7. 店铺页:任务页签与主站商品弹层
   所有原查询、分页、选择、编辑、锁定、删除、导出、发货事件均通过
   原控件代理触发,不改动任何共享控制器逻辑。
   ========================================================================== */
!function () {
    "use strict";

    var root = document.documentElement;
    var THEME_KEY = root.getAttribute("data-theme-storage-key") || "newyork.theme.preference";
    var THEME_DEFAULT = root.getAttribute("data-theme-default-preference") || "auto";
    var THEMES = ["auto", "light", "dark"];
    var media = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;

    /* ---------------------------------------------------------------- 工具 */

    function $q(sel, scope) {
        return (scope || document).querySelector(sel);
    }

    function $qa(sel, scope) {
        return Array.prototype.slice.call((scope || document).querySelectorAll(sel));
    }

    function text(value) {
        return String(value == null ? "" : value).replace(/\s+/g, " ").trim();
    }

    function el(tag, className, html) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (html !== undefined) node.innerHTML = html;
        return node;
    }

    function icon(name) {
        return '<span class="material-icons-outlined" aria-hidden="true">' + name + '</span>';
    }

    function copyText(value, tip) {
        var done = function () {
            if (window.message && window.message.success) window.message.success(tip || i18n("已复制"));
            else if (window.layer) window.layer.msg(tip || i18n("已复制"));
        };
        if (window.util && window.util.copyTextToClipboard) {
            window.util.copyTextToClipboard(value, done);
            return;
        }
        if (navigator.clipboard) navigator.clipboard.writeText(value).then(done);
    }

    /* ---------------------------------------------------- 1. 外观模式 */

    function readTheme() {
        try {
            var value = window.localStorage.getItem(THEME_KEY);
            return THEMES.indexOf(value) >= 0 ? value : null;
        } catch (error) {
            return null;
        }
    }

    function writeTheme(value) {
        try {
            window.localStorage.setItem(THEME_KEY, value);
        } catch (error) {
        }
    }

    function resolveTheme(preference) {
        if (preference === "light" || preference === "dark") return preference;
        return media && media.matches ? "dark" : "light";
    }

    function applyTheme(preference) {
        var safe = THEMES.indexOf(preference) >= 0 ? preference : "auto";
        var mode = resolveTheme(safe);
        root.setAttribute("data-theme-preference", safe);
        root.setAttribute("data-theme-mode", mode);
        root.setAttribute("data-theme", mode);
        root.style.colorScheme = mode;
        $qa("[data-theme-toggle]").forEach(function (button) {
            var active = button.getAttribute("data-theme-toggle") === safe;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-pressed", active ? "true" : "false");
        });
    }

    function syncTheme() {
        applyTheme(readTheme() || (THEMES.indexOf(THEME_DEFAULT) >= 0 ? THEME_DEFAULT : "auto"));
    }

    if (media) {
        var followSystem = function () {
            if ((readTheme() || THEME_DEFAULT) === "auto") applyTheme("auto");
        };
        if (typeof media.addEventListener === "function") media.addEventListener("change", followSystem);
        else if (typeof media.addListener === "function") media.addListener(followSystem);
    }

    /* ---------------------------------------------------- 2. 底部面板 */

    function shellRoot() {
        return $q(".ny-device-shell") || document.body;
    }

    /* 判断链接是否离开会员中心(前台商城/商品/查单/认证 使用不同布局与资源)。
       会员中心开启了 PJAX,若让它把异构页面塞进 #pjax-container 会串台:
       body 仍是会员布局、trade.js 等前台脚本缺失,导致商品加载不出并错位。 */
    function leavesMemberCenter(href) {
        if (!href) return false;
        if (/^(https?:)?\/\//i.test(href)) return false;
        if (href.charAt(0) === "#" || href.indexOf("javascript:") === 0 || href.indexOf("mailto:") === 0 || href.indexOf("tel:") === 0) return false;
        var path = href.split("#")[0].split("?")[0];
        if (path === "" || path === "/") return true;
        return /^\/(item|cat)\//.test(path) || /^\/user\/(index|authentication)(\/|$)/.test(path);
    }

    /* 面板与抽屉共用遮罩:任一开启则遮罩在场并锁定滚动 */
    function syncOverlay() {
        var any = !!$q("[data-ny-sheet].is-open, .ny-drawer.is-open");
        var backdrop = $q(".ny-sheet-backdrop");
        if (backdrop) backdrop.classList.toggle("is-open", any);
        $qa("[data-ny-sheet-open]").forEach(function (trigger) {
            var name = trigger.getAttribute("data-ny-sheet-open") || "";
            var sheet = name ? $q('[data-ny-sheet="' + name + '"]') : null;
            trigger.setAttribute("aria-expanded", sheet && sheet.classList.contains("is-open") ? "true" : "false");
        });
        document.body.classList.toggle("ny-sheet-open", any);
    }

    function closeSheets() {
        $qa("[data-ny-sheet].is-open").forEach(function (sheet) {
            sheet.classList.remove("is-open");
            sheet.setAttribute("aria-hidden", "true");
        });
        syncOverlay();
    }

    function openSheet(name) {
        var sheet = $q('[data-ny-sheet="' + name + '"]');
        if (!sheet) return;
        closeSheets();
        closeDrawers();
        sheet.classList.add("is-open");
        sheet.setAttribute("aria-hidden", "false");
        syncOverlay();
    }

    function closeDrawers() {
        $qa(".ny-drawer.is-open").forEach(function (drawer) {
            drawer.classList.remove("is-open");
            drawer.setAttribute("aria-hidden", "true");
        });
        syncOverlay();
    }

    function openDrawer(name) {
        var drawer = $q('[data-ny-drawer="' + name + '"]');
        if (!drawer) return;
        closeSheets();
        closeDrawers();
        drawer.classList.add("is-open");
        drawer.setAttribute("aria-hidden", "false");
        syncOverlay();
    }

    /* 店铺公告自动弹出(模板设置「默认弹窗公告」)。
       开关由服务端写在公告面板的 data-ny-notice-auto 上;查单页不渲染公告面板,自然不弹。
       只有用户亲手点公告面板右上角的 ❌ 关闭才开始计时,之后 30 分钟内不再自动弹;
       点遮罩、按 Esc、没关就刷新或离开页面,都不计时,下次打开照样弹。
       计时的点击监听在 bindShell() 里。 */
    var NOTICE_CLOSED_KEY = "newyork.notice.closedAt";
    var NOTICE_COOLDOWN = 30 * 60 * 1000;

    function autoOpenNotice() {
        var sheet = $q('[data-ny-sheet="notice"][data-ny-notice-auto="1"]');
        if (!sheet) return;
        /* 编辑器清空后会留下 <p><br></p> 这类空壳,弹一个空面板比不弹更糟 */
        var body = $q(".ny-notice-body", sheet);
        if (!body || (!text(body.textContent) && !$q("img, video, iframe, audio, svg, table, hr", body))) return;
        /* 带 #categories 进来(游客底栏「分类」)是要看分类:initStorefront 随后会打开分类抽屉,
           抽屉一开就把公告关掉,公告只会闪一下。这次先不弹,下次正常打开再弹 */
        if (window.location.hash === "#categories" && $q(".ny-store-drawer")) return;

        var now = Date.now();
        var last = 0;
        try {
            last = parseInt(window.localStorage.getItem(NOTICE_CLOSED_KEY), 10) || 0;
        } catch (error) {
            last = 0;
        }
        /* last > now 说明本机时钟被往回拨过,按过期处理,免得一直不弹 */
        if (last > 0 && last <= now && now - last < NOTICE_COOLDOWN) return;

        openSheet("notice");
    }

    function markNoticeClosed() {
        try {
            window.localStorage.setItem(NOTICE_CLOSED_KEY, String(Date.now()));
        } catch (error) {
        }
    }

    function createSheet(name, title, subtitle, variant) {
        var existing = $q('[data-ny-dynamic-sheet][data-ny-sheet="' + name + '"]');
        if (existing) return existing;
        var sheet = el("section", "ny-sheet ny-sheet--" + variant);
        sheet.setAttribute("data-ny-sheet", name);
        sheet.setAttribute("data-ny-dynamic-sheet", "1");
        sheet.setAttribute("aria-hidden", "true");
        sheet.setAttribute("aria-label", title);
        sheet.innerHTML =
            '<div class="ny-sheet-handle" aria-hidden="true"></div>' +
            '<div class="ny-sheet-head"><div><strong></strong><span></span></div>' +
            '<button type="button" data-ny-sheet-close aria-label="' + i18n("关闭") + '">' + icon("close") + "</button></div>" +
            '<div class="ny-sheet-body"></div>';
        $q(".ny-sheet-head strong", sheet).textContent = title;
        $q(".ny-sheet-head span", sheet).textContent = subtitle || "";
        shellRoot().appendChild(sheet);
        return sheet;
    }

    function removeDynamicSheets() {
        $qa("[data-ny-dynamic-sheet]").forEach(function (sheet) {
            sheet.remove();
        });
    }

    /* ------------------------------------------------ 3. 列表引擎:配方 */

    /*
     * 槽位:
     *   select 选择框  visual 图  hero 主内容  amount 尾随金额  status 状态
     *   chips 徽章行  meta 辅助行  switch 开关  act 操作  raw 保持可见(样式由 CSS 决定)
     *   x 折叠明细(展开后按 label/值 显示)
     * chrome: search 快捷搜索, filter 筛选面板, select 选择模式, count 结果计数
     */
    var RECIPES = {
        bill: {
            slots: {hero: ["log"], amount: ["amount"], meta: ["create_time"], chips: ["type", "currency"], x: ["balance"]},
            labels: {balance: i18n("变动后余额")},
            chrome: {search: 1, filter: 1, count: 1},
            decorate: function (row, data) {
                var amount = row.querySelector('td[data-f="amount"]');
                if (amount && data) {
                    amount.classList.toggle("ny-amount-neg", String(data.type) === "0");
                    amount.classList.toggle("ny-amount-pos", String(data.type) === "1");
                }
            }
        },
        "cash-record": {
            slots: {hero: ["card"], amount: ["amount"], status: ["status"], meta: ["create_time"], chips: ["type"], x: ["cost", "message", "arrive_time"]},
            labels: {cost: i18n("手续费"), message: i18n("处理说明"), arrive_time: i18n("到账时间")},
            chrome: {filter: 1, count: 1, caption: i18n("全部兑现申请")}
        },
        purchase: {
            slots: {meta: ["trade_no"], status: ["status"], hero: ["commodity"], amount: ["amount"], chips: ["delivery_status", "pay", "sku", "card_num"], act: ["secret"], x: []},
            chrome: {search: 1, filter: 1, count: 1}
        },
        recent: {
            slots: {meta: ["trade_no"], status: ["status"], hero: ["commodity"], amount: ["amount"], chips: ["delivery_status", "pay", "sku", "card_num"], act: ["secret"], x: []},
            chrome: {}
        },
        message: {
            slots: {hero: ["title"], status: ["read_time"], meta: ["create_time"], act: ["operation"], x: []},
            chrome: {select: 1, count: 1, caption: i18n("全部消息")},
            decorate: function (row, data) {
                row.classList.toggle("is-unread", !!data && !data.read_time);
            }
        },
        order: {
            slots: {meta: ["trade_no"], status: ["status"], hero: ["commodity"], amount: ["amount"], chips: ["delivery_status", "commodity.delivery_way", "pay", "sku", "card_num"], act: ["secret", "widget"], x: ["owner"]},
            labels: {owner: i18n("买家")},
            chrome: {search: 1, filter: 1, count: 1},
            extras: function (data) {
                return [
                    [i18n("联系方式"), data.contact],
                    [i18n("下单时间"), data.create_time],
                    [i18n("支付时间"), data.pay_time],
                    [i18n("客户 IP"), data.create_ip],
                    [i18n("优惠券"), data.coupon && data.coupon.code],
                    [i18n("预选卡密"), data.card && data.card.secret]
                ];
            }
        },
        commodity: {
            collapseActs: 1,
            slots: {hero: ["name"], switch: ["status"], raw: ["price", "user_price", "card_count"], chips: ["category.name"], act: ["share_url", "operation"], x: ["order_today_amount", "order_yesterday_amount", "order_week_amount", "order_all_amount", "sort"]},
            labels: {order_today_amount: i18n("今日销售"), order_yesterday_amount: i18n("昨日销售"), order_week_amount: i18n("本周销售"), order_all_amount: i18n("累计销售"), sort: i18n("排序值")},
            chrome: {search: 1, filter: 1, count: 1}
        },
        category: {
            collapseActs: 1,
            tree: 1,
            slots: {visual: ["icon"], hero: ["name"], switch: ["status"], act: ["share_url", "operation"], x: ["sort"]},
            labels: {sort: i18n("排序(越小越前)")},
            chrome: {search: 1, filter: 1, count: 1}
        },
        card: {
            collapseActs: 1,
            slots: {hero: ["secret"], status: ["status"], chips: ["commodity"], act: ["operation"], x: ["draft", "race", "create_time", "order.trade_no"]},
            labels: {draft: i18n("预选信息"), race: i18n("类别/SKU"), create_time: i18n("创建/出售"), "order.trade_no": i18n("订单/备注")},
            chrome: {search: 1, filter: 1, select: 1, count: 1},
            mask: "secret"
        },
        coupon: {
            collapseActs: 1,
            slots: {hero: ["code"], amount: ["money"], status: ["status"], chips: ["mode", "commodity", "expire_time"], raw: ["life", "use_life"], act: ["operation"], x: ["note"]},
            labels: {note: i18n("备注信息")},
            chrome: {search: 1, filter: 1, select: 1, count: 1},
            copyHero: "code"
        },
        member: {
            slots: {hero: ["avatar"], status: ["status"], chips: ["group"], raw: ["balance", "recharge", "coin"], act: ["operation"], x: ["id", "email", "phone", "qq", "create_time"]},
            labels: {id: i18n("会员 ID"), email: i18n("邮箱"), phone: i18n("手机号"), qq: "QQ", create_time: i18n("注册时间")},
            chrome: {search: 1, filter: 1, count: 1}
        },
        promote: {
            slots: {hero: ["name"], amount: ["profit"], chips: ["rate", "race", "sku_count"], raw: ["guest_price", "my_price"], x: []},
            chrome: {search: 1, filter: 1, count: 1}
        },
        "master-cate": {
            tree: 1,
            inlineActs: 1,
            slots: {visual: ["icon"], hero: ["name"], act: ["status", "operation"], drop: ["user_name"]},
            chrome: {}
        },
        "master-item": {
            inlineActs: 1,
            slots: {visual: ["cover"], hero: ["name"], act: ["status", "operation"], raw: ["price", "user_price"], drop: ["user_name", "premium"]},
            chrome: {}
        },
        draft: {
            slots: {hero: ["draft"], amount: ["draft_premium"]},
            chrome: {}
        }
    };

    function recipeNameFor(table) {
        var id = table.id || "";
        if (id === "bill-table") {
            var page = $q("[data-ny-page]");
            return page && page.getAttribute("data-ny-page") === "purchase" ? "purchase" : "bill";
        }
        var map = {
            "cash-table": "cash-record",
            "recent-buy-table": "recent",
            "message-table": "message",
            "order-table": "order",
            "commodity-table": "commodity",
            "category-table": "category",
            "user-card-table": "card",
            "coupon-table": "coupon",
            "member-table": "member",
            "promote-table": "promote",
            "master_category": "master-cate",
            "master_commodity": "master-item",
            "shop-selection-table": "draft"
        };
        return map[id] || null;
    }

    function headerFields(table) {
        var out = [];
        $qa("thead th", table).forEach(function (th) {
            out.push({
                field: th.getAttribute("data-field") || "",
                label: text(th.textContent),
                checkbox: th.classList.contains("bs-checkbox")
            });
        });
        return out;
    }

    function slotOfField(recipe, field) {
        var slots = recipe.slots;
        for (var name in slots) {
            if (slots[name].indexOf(field) >= 0) return name;
        }
        return "x";
    }

    var SLOT_CLASS = {
        select: "ny-s-select",
        visual: "ny-s-visual",
        hero: "ny-s-hero",
        amount: "ny-s-amount",
        status: "ny-s-status",
        chips: "ny-s-chips",
        meta: "ny-s-meta",
        switch: "ny-s-switch",
        act: "ny-s-act",
        raw: "ny-s-raw",
        x: "ny-s-x",
        drop: "ny-s-drop"
    };

    function rowData(table, row) {
        if (!window.jQuery) return null;
        var index = parseInt(row.getAttribute("data-index") || "", 10);
        if (!isFinite(index)) return null;
        try {
            var data = window.jQuery(table).bootstrapTable("getData");
            return data && data[index] ? data[index] : null;
        } catch (error) {
            return null;
        }
    }

    function bindRowToggle(row) {
        if (row.getAttribute("data-ny-row-bound") === "1") return;
        row.setAttribute("data-ny-row-bound", "1");
        row.addEventListener("click", function (event) {
            if (event.target.closest("a, button, input, select, textarea, label, .layui-form-switch, .layui-form-checkbox, .layui-form-radio, .treegrid-expander, .metadata-text, .metadata-select")) return;
            if (!row.querySelector(".ny-s-x, .ny-i-extras")) return;
            row.classList.toggle("is-open");
        });
    }

    function ensureExpand(row, recipe) {
        var has = row.querySelector(".ny-s-x, .ny-i-extras");
        var cell = row.querySelector(":scope > td.ny-s-expand");
        if (!has) {
            if (cell) cell.remove();
            return;
        }
        if (cell) return;
        cell = el("td", "ny-s-expand");
        var button = el("button", "ny-expand-btn", '<span>' + i18n("详情") + '</span>' + icon("expand_more"));
        button.type = "button";
        button.setAttribute("aria-label", i18n("展开详情"));
        button.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();
            row.classList.toggle("is-open");
        });
        cell.appendChild(button);
        row.appendChild(cell);
    }

    /* 有操作按钮的卡片:注入一个 100% 宽的零高断行元素,把操作/详情压到独立的一整行
       (页脚),避免它们被挤到内容行右侧、左边留大片空白。 */
    function ensureFooterBreak(row, recipe) {
        var hasAct = row.querySelector(":scope > td.ny-s-act");
        var existing = row.querySelector(":scope > td.ny-s-break");
        /* inlineActs 配方(主站分类/商品)的操作按钮是行内排布,没有页脚,不需要断行 */
        if (!hasAct || (recipe && recipe.inlineActs)) {
            if (existing) existing.remove();
            return;
        }
        if (existing) return;
        var br = el("td", "ny-s-break");
        br.setAttribute("aria-hidden", "true");
        row.appendChild(br);
    }

    /* 树形列表(分类):jquery-treegrid 只用 treegrid-<id> / treegrid-parent-<id> 记录父子,
       不写深度。这里顺父链算出真实层级,写入 data-ny-depth 供 CSS 逐级缩进。 */
    function applyTreeDepth(table, row) {
        var m = String(row.className).match(/treegrid-parent-(\d+)/);
        if (!m) {
            row.setAttribute("data-ny-depth", "0");
            row.style.setProperty("--ny-depth", "0");
            return;
        }
        var depth = 0;
        var parentId = m[1];
        var guard = 0;
        while (parentId && guard++ < 30) {
            depth++;
            var parent = $q('tbody > tr.treegrid-' + parentId, table);
            if (!parent) break;
            var pm = String(parent.className).match(/treegrid-parent-(\d+)/);
            parentId = pm ? pm[1] : null;
        }
        row.setAttribute("data-ny-depth", String(depth));
        /* 供 CSS 用 calc(var(--ny-depth) * step) 逐级缩进,自动适配任意深度 */
        row.style.setProperty("--ny-depth", String(depth));
    }

    function ensureExtras(row, recipe, data) {
        if (!recipe.extras || !data) return;
        var stamp = String(data.id || "") + ":" + String(row.getAttribute("data-index") || "");
        if (row.getAttribute("data-ny-extras") === stamp) return;
        row.setAttribute("data-ny-extras", stamp);
        var cell = row.querySelector(":scope > td.ny-i-extras");
        if (!cell) {
            cell = el("td", "ny-i-extras");
            row.appendChild(cell);
        }
        var pairs = recipe.extras(data).filter(function (pair) {
            return pair && pair[1] != null && text(pair[1]) !== "";
        });
        cell.innerHTML = "";
        pairs.forEach(function (pair) {
            var line = el("div");
            var k = el("span");
            var v = el("span");
            k.textContent = pair[0];
            v.textContent = text(pair[1]);
            line.appendChild(k);
            line.appendChild(v);
            cell.appendChild(line);
        });
        if (!pairs.length) cell.remove();
    }

    function ensureMaskControls(row, recipe, wrap) {
        if (!recipe.mask && !recipe.copyHero) return;
        var field = recipe.mask || recipe.copyHero;
        var heroCell = row.querySelector('td[data-f="' + field + '"]');
        if (!heroCell) return;
        var actCell = row.querySelector(":scope > td.ny-s-act");
        if (!actCell) {
            actCell = el("td", "ny-s-act");
            row.appendChild(actCell);
        }
        if (recipe.mask && !actCell.querySelector(".ny-reveal-btn")) {
            var reveal = el("a", "a-badge-glass ny-reveal-btn", '<i class="fa-duotone fa-regular fa-eye"></i><span class="btn-title">' + i18n("查看") + '</span>');
            reveal.setAttribute("role", "button");
            reveal.addEventListener("click", function (event) {
                event.preventDefault();
                event.stopPropagation();
                var revealed = row.classList.toggle("is-revealed");
                var title = reveal.querySelector(".btn-title");
                if (title) title.textContent = revealed ? i18n("隐藏") : i18n("查看");
            });
            actCell.insertBefore(reveal, actCell.firstChild);
        }
        if (!actCell.querySelector(".ny-copy-btn")) {
            var copy = el("a", "a-badge-glass ny-copy-btn", '<i class="fa-duotone fa-regular fa-copy"></i><span class="btn-title">' + i18n("复制") + '</span>');
            copy.setAttribute("role", "button");
            copy.addEventListener("click", function (event) {
                event.preventDefault();
                event.stopPropagation();
                copyText(text(heroCell.textContent), i18n("内容已复制"));
            });
            actCell.insertBefore(copy, actCell.firstChild);
        }
    }

    var ACTION_NAMES = [
        ["lock-keyhole-open", i18n("解锁")], ["lock-open", i18n("解锁")], ["lock-keyhole", i18n("锁定")],
        ["pen-to-square", i18n("编辑")], ["trash", i18n("删除")], ["copy", i18n("复制")],
        ["truck", i18n("发货")], ["eye-slash", i18n("隐藏")], ["eye", i18n("查看")],
        ["envelope-open-dollar", i18n("转账")], ["gear", i18n("设置")], ["download", i18n("导出")]
    ];

    function actionLabel(button) {
        var title = text((button.querySelector(".btn-title") || {}).textContent);
        if (title) return title;
        var classes = (button.querySelector("i") || button).className || "";
        for (var i = 0; i < ACTION_NAMES.length; i++) {
            if (String(classes).indexOf(ACTION_NAMES[i][0]) >= 0) return ACTION_NAMES[i][1];
        }
        return i18n("操作");
    }

    function fillActionTitles(row) {
        $qa("td.ny-s-act .a-badge-glass", row).forEach(function (button) {
            var title = button.querySelector(".btn-title");
            if (title && !text(title.textContent)) title.textContent = actionLabel(button);
            if (!button.getAttribute("aria-label")) button.setAttribute("aria-label", actionLabel(button));
        });
    }

    function openRowActionSheet(buttons, subtitle) {
        var sheet = createSheet("row-actions", i18n("更多操作"), subtitle || "", "row-actions");
        var body = $q(".ny-sheet-body", sheet);
        body.innerHTML = "";
        var list = el("div", "ny-row-actions-list");
        buttons.forEach(function (source) {
            var danger = /text-danger|trash/.test(source.className + (source.querySelector("i") ? source.querySelector("i").className : ""));
            var proxy = el("button", danger ? "is-danger" : "");
            proxy.type = "button";
            var ic = source.querySelector("i");
            proxy.innerHTML = (ic ? '<i class="' + ic.className + '"></i>' : "") + "<span>" + actionLabel(source) + "</span>";
            proxy.addEventListener("click", function () {
                closeSheets();
                window.setTimeout(function () {
                    source.click();
                }, 80);
            });
            list.appendChild(proxy);
        });
        body.appendChild(list);
        openSheet("row-actions");
    }

    function ensureActOverflow(row, recipe) {
        if (!recipe.collapseActs) return;
        var buttons = $qa("td.ny-s-act .a-badge-glass", row).filter(function (button) {
            return !button.classList.contains("ny-more-btn") && !button.classList.contains("ny-reveal-btn") && !button.classList.contains("ny-copy-btn");
        });
        if (buttons.length <= 2) return;
        var primary = buttons.filter(function (button) {
            return (button.innerHTML || "").indexOf("pen-to-square") >= 0;
        })[0] || buttons[0];
        var rest = buttons.filter(function (button) {
            return button !== primary;
        });
        rest.forEach(function (button) {
            button.classList.add("ny-act-hidden");
        });
        var actCell = primary.closest("td");
        if (!actCell.querySelector(".ny-more-btn")) {
            var more = el("a", "a-badge-glass ny-more-btn", '<i class="fa-duotone fa-regular fa-ellipsis"></i><span class="btn-title">' + i18n("更多") + '</span>');
            more.setAttribute("role", "button");
            more.addEventListener("click", function (event) {
                event.preventDefault();
                event.stopPropagation();
                /* 打码配方(卡密)不得把明文带进面板副标题 */
                var hero = recipe.mask ? null : row.querySelector("td.ny-s-hero");
                openRowActionSheet(rest, hero ? text(hero.textContent).slice(0, 24) : i18n("对当前记录执行"));
            });
            primary.insertAdjacentElement("afterend", more);
        }
    }

    function applyRecipeRow(table, row, recipe, fields) {
        if (row.classList.contains("no-records-found")) return;
        var cells = $qa(":scope > td", row).filter(function (td) {
            return !td.classList.contains("ny-s-expand") && !td.classList.contains("ny-i-extras") && !td.classList.contains("ny-s-break");
        });
        if (!cells.length) return;
        cells.forEach(function (td, index) {
            var head = fields[index] || {};
            var slot;
            if (head.checkbox || td.classList.contains("bs-checkbox") || td.querySelector('input[name="btSelectItem"]')) {
                slot = "select";
            } else {
                slot = slotOfField(recipe, head.field);
            }
            Object.keys(SLOT_CLASS).forEach(function (name) {
                td.classList.remove(SLOT_CLASS[name]);
            });
            td.classList.add(SLOT_CLASS[slot]);
            if (head.field) td.setAttribute("data-f", head.field);
            var label = (recipe.labels && recipe.labels[head.field]) || head.label || i18n("信息");
            td.setAttribute("data-label", label);
            var blankable = slot === "x" || slot === "chips" || slot === "raw" || slot === "meta";
            var content = text(td.textContent);
            if (blankable && (content === "-" || content === "") && !td.querySelector("img,a,button,input,.layui-form-switch")) {
                td.setAttribute("data-ny-blank", "1");
            } else {
                td.removeAttribute("data-ny-blank");
            }
        });
        var data = rowData(table, row);
        ensureExtras(row, recipe, data);
        ensureMaskControls(row, recipe);
        fillActionTitles(row);
        ensureActOverflow(row, recipe);
        ensureExpand(row, recipe);
        ensureFooterBreak(row, recipe);
        if (recipe.tree) applyTreeDepth(table, row);
        if (typeof recipe.decorate === "function") recipe.decorate(row, data);
        bindRowToggle(row);
    }

    /* -------------------------------------- 4. 列表周边(搜索/筛选/批量) */

    function pageTitle() {
        /* 顶栏标题已移除,改从正文页头取当前页名(位于 pjax 容器内,始终最新) */
        var heading = $q(".ny-page-intro h1, .ny-page-heading h1, .ny-appbar__context");
        return text(heading ? heading.textContent : "") || i18n("列表");
    }

    function quickSearchSource(form) {
        var inputs = $qa('input[type="text"][name]', form);
        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            if (/^betweenStart|^betweenEnd/.test(input.name)) continue;
            if (input.closest(".layui-select-title")) continue;
            var holder = input.closest(".layui-input-inline");
            if (holder && holder.classList.contains("hide")) continue;
            return input;
        }
        return null;
    }

    function activeFilterCount(form) {
        var groups = {};
        $qa("input[name], select[name], textarea[name]", form).forEach(function (field) {
            if (field.disabled || field.type === "button" || field.type === "submit") return;
            if (text(field.value) === "") return;
            groups[field.name.replace(/^betweenStart|^betweenEnd/, "between")] = 1;
        });
        return Object.keys(groups).length;
    }

    /* 除快捷搜索已代理的那个字段外,搜索表单里是否还有可筛选字段。
       只有一个字段(且已由快捷搜索呈现)时,筛选面板会完全重复,不值得挂载。 */
    function filterHasExtraFields(form, quickSource) {
        if (!form) return false;
        var fields = $qa("input[name], select[name], textarea[name]", form);
        for (var i = 0; i < fields.length; i++) {
            var field = fields[i];
            if (field === quickSource) continue;
            if (field.disabled || field.type === "button" || field.type === "submit" || field.type === "hidden") continue;
            var holder = field.closest(".layui-input-inline");
            if (holder && holder.classList.contains("hide")) continue;
            return true;
        }
        return false;
    }

    function resetFilterForm(form) {
        $qa("input[name], textarea[name]", form).forEach(function (field) {
            if (field.type !== "checkbox" && field.type !== "radio" && field.type !== "button") field.value = "";
        });
        $qa("select[name]", form).forEach(function (field) {
            field.value = "";
        });
        $qa(".layui-select-title input, [class*='tree-'] > input:not([name])", form).forEach(function (field) {
            field.value = "";
        });
        renderLayuiForms();
    }

    function ensureListbar(wrap) {
        var toolbar = $q(":scope > .fixed-table-toolbar", wrap);
        if (!toolbar) {
            toolbar = el("div", "fixed-table-toolbar");
            wrap.insertBefore(toolbar, wrap.firstChild);
        }
        var bar = $q(":scope > .ny-listbar", toolbar);
        if (!bar) {
            bar = el("div", "ny-listbar");
            toolbar.insertBefore(bar, toolbar.firstChild);
        }
        return bar;
    }

    function mountQuickSearch(bar, form, chromeConf) {
        if ($q(".ny-quick-search, .ny-listbar-caption", bar)) return;
        var query = form ? $q(".query-button", form) : null;
        var source = form ? quickSearchSource(form) : null;
        if (chromeConf.search && source) {
            var label = i18n("搜索");
            var holder = source.closest(".mui-sf");
            var muiLabel = holder ? $q(".mui-sf__label", holder) : null;
            if (muiLabel) label = text(muiLabel.textContent) || label;
            var field = el("label", "ny-quick-search",
                icon("search") + '<input type="search" enterkeyhint="search">' +
                '<button type="button" aria-label="' + i18n("执行搜索") + '">' + icon("arrow_forward") + "</button>");
            var input = $q("input", field);
            input.placeholder = label;
            input.value = source.value;
            input.addEventListener("input", function () {
                source.value = input.value;
                source.dispatchEvent(new Event("input", {bubbles: true}));
            });
            input.addEventListener("keydown", function (event) {
                if (event.key !== "Enter") return;
                event.preventDefault();
                if (query) query.click();
            });
            $q("button", field).addEventListener("click", function () {
                if (query) query.click();
            });
            bar.appendChild(field);
        } else {
            var caption = el("div", "ny-listbar-caption",
                icon("view_agenda") + '<span class="ny-listbar-caption__text">' + (chromeConf.caption || i18n("全部记录")) + "</span>" +
                '<b class="ny-list-total"></b>');
            bar.appendChild(caption);
        }
    }

    function mountFilter(table, wrap, bar, form) {
        if (!form || form.getAttribute("data-ny-filter-mounted") === "1") return;
        form.setAttribute("data-ny-filter-mounted", "1");
        var name = "filters-" + (table.id || "list");
        var sheet = createSheet(name, pageTitle() + i18n("筛选"), i18n("设置条件后查看结果"), "filters");
        var body = $q(".ny-sheet-body", sheet);
        var query = $q(".query-button", form);

        var trigger = el("button", "ny-tool-chip ny-filter-trigger", icon("tune") + '<span>' + i18n("筛选") + '</span><b hidden>0</b>');
        trigger.type = "button";
        trigger.setAttribute("data-ny-sheet-open", name);
        bar.appendChild(trigger);

        body.appendChild(form);

        var actions = el("div", "ny-filter-actions");
        var reset = el("button", "ny-filter-reset", icon("restart_alt") + i18n("清除"));
        reset.type = "button";
        var apply = el("button", "ny-primary-button ny-filter-apply", icon("check") + i18n("查看结果"));
        apply.type = "button";
        actions.appendChild(reset);
        actions.appendChild(apply);
        body.appendChild(actions);

        var syncBadge = function () {
            var badge = $q("b", trigger);
            var count = activeFilterCount(form);
            badge.textContent = String(count);
            badge.hidden = count === 0;
            trigger.classList.toggle("is-on", count > 0);
        };
        reset.addEventListener("click", function () {
            resetFilterForm(form);
            var quick = $q(".ny-quick-search input", bar);
            if (quick) quick.value = "";
            syncBadge();
            if (query) query.click();
        });
        apply.addEventListener("click", function () {
            if (query) query.click();
            window.setTimeout(closeSheets, 100);
        });
        form.addEventListener("input", syncBadge);
        form.addEventListener("change", syncBadge);
        syncBadge();
    }

    /* 选择模式与批量面板 */

    function selectedCount(table) {
        return $qa('tbody input[name="btSelectItem"]:checked', table).length;
    }

    function ensureSelectbar() {
        var bar = $q(".ny-selectbar");
        if (bar) return bar;
        bar = el("div", "ny-selectbar",
            '<button type="button" class="ny-selectbar__all">' + icon("select_all") + i18n("全选") + "</button>" +
            '<div class="ny-selectbar__count"><b>0</b><span>' + i18n("已选择") + '</span></div>' +
            '<button type="button" class="ny-selectbar__go">' + icon("bolt") + i18n("批量操作") + "</button>" +
            '<button type="button" class="ny-selectbar__exit" aria-label="' + i18n("退出选择") + '">' + icon("close") + "</button>");
        bar.setAttribute("data-ny-dynamic-sheet", "1");
        shellRoot().appendChild(bar);
        return bar;
    }

    function updateSelectionState(table) {
        var count = selectedCount(table);
        var bar = $q(".ny-selectbar");
        if (bar) {
            $q(".ny-selectbar__count b", bar).textContent = String(count);
            $q(".ny-selectbar__go", bar).disabled = count === 0;
        }
        var sheet = $q('[data-ny-sheet="bulk-' + table.id + '"]');
        if (sheet) {
            var head = $q(".ny-sheet-head span", sheet);
            if (head) head.textContent = count > 0 ? i18n("对已选择的 {n} 条记录执行").replace("{n}", count) : i18n("请先选择记录");
        }
    }

    function mountSelection(table, wrap, bar, toolbar) {
        if (!toolbar || wrap.getAttribute("data-ny-select-mounted") === "1") return;
        wrap.setAttribute("data-ny-select-mounted", "1");

        var name = "bulk-" + (table.id || "list");
        var sheet = createSheet(name, i18n("批量操作"), i18n("请先选择记录"), "bulk");
        var body = $q(".ny-sheet-body", sheet);
        var list = el("div", "ny-bulk-list");
        body.appendChild(list);

        $qa("button", toolbar).forEach(function (source) {
            var danger = source.classList.contains("ny-tool-button--danger") || source.classList.contains("button-del");
            var proxy = el("button", "ny-bulk-proxy" + (danger ? " is-danger" : ""));
            proxy.type = "button";
            proxy.innerHTML = source.innerHTML;
            proxy.addEventListener("click", function () {
                closeSheets();
                window.setTimeout(function () {
                    source.click();
                }, 60);
            });
            list.appendChild(proxy);
        });

        var toggle = el("button", "ny-tool-chip ny-select-toggle", icon("checklist") + '<span>' + i18n("选择") + '</span>');
        toggle.type = "button";
        bar.appendChild(toggle);

        var exitSelection = function () {
            document.body.classList.remove("ny-selecting");
            wrap.classList.remove("is-selecting");
            toggle.classList.remove("is-on");
            $q(".ny-select-toggle span", bar).textContent = i18n("选择");
            if (window.jQuery) {
                try {
                    window.jQuery(table).bootstrapTable("uncheckAll");
                } catch (error) {
                }
            }
        };

        toggle.addEventListener("click", function () {
            var on = !wrap.classList.contains("is-selecting");
            if (on) {
                var selectbar = ensureSelectbar();
                selectbar.setAttribute("data-ny-owner", table.id || "");
                document.body.classList.add("ny-selecting");
                wrap.classList.add("is-selecting");
                toggle.classList.add("is-on");
                toggle.querySelector("span").textContent = i18n("完成");
                updateSelectionState(table);
                var all = $q(".ny-selectbar__all");
                var go = $q(".ny-selectbar__go");
                var exit = $q(".ny-selectbar__exit");
                all.onclick = function () {
                    if (!window.jQuery) return;
                    var action = selectedCount(table) === 0 ? "checkAll" : "uncheckAll";
                    window.jQuery(table).bootstrapTable(action);
                    window.setTimeout(function () {
                        updateSelectionState(table);
                    }, 40);
                };
                go.onclick = function () {
                    openSheet(name);
                };
                exit.onclick = exitSelection;
            } else {
                exitSelection();
            }
        });

        if (table.getAttribute("data-ny-check-bound") !== "1") {
            table.setAttribute("data-ny-check-bound", "1");
            table.addEventListener("change", function () {
                window.setTimeout(function () {
                    updateSelectionState(table);
                }, 30);
            });
        }
    }

    function updateTotal(table, wrap) {
        if (!window.jQuery) return;
        try {
            var options = window.jQuery(table).bootstrapTable("getOptions");
            var total = options && options.totalRows;
            var badge = $q(".ny-list-total", wrap);
            if (badge && isFinite(total)) badge.textContent = i18n("共 {total} 条").replace("{total}", total);
        } catch (error) {
        }
    }

    function mountChrome(table, wrap, recipe) {
        var chromeConf = recipe.chrome || {};
        var hasChrome = chromeConf.search || chromeConf.filter || chromeConf.select || chromeConf.count || chromeConf.caption;
        if (!hasChrome) return;
        if (wrap.getAttribute("data-ny-chrome") !== "1") {
            wrap.setAttribute("data-ny-chrome", "1");
            var bar = ensureListbar(wrap);
            var form = $q(".table-search", wrap);
            mountQuickSearch(bar, form, chromeConf);
            if (chromeConf.filter) {
                var quickSource = (chromeConf.search && form) ? quickSearchSource(form) : null;
                if (filterHasExtraFields(form, quickSource)) {
                    mountFilter(table, wrap, bar, form);
                } else if (form && chromeConf.search) {
                    /* 唯一可筛字段已被快捷搜索代理,筛选面板只会重复它,故不挂载;
                       同时隐藏 component.css 强制 display:flex 的裸露原始表单 */
                    form.classList.add("ny-search-proxied");
                }
            }
            if (chromeConf.select) {
                var shellNode = table.closest(".ny-panel, .ny-page-shell, main") || document;
                mountSelection(table, wrap, bar, shellNode.querySelector(".ny-toolbar"));
            }
        }
        if (chromeConf.count) updateTotal(table, wrap);
    }

    function enhanceTable(table) {
        if (!table || !table.id) return;
        if (table.classList.contains("wholesale-table")) return;
        var name = recipeNameFor(table);
        if (!name) return;
        var recipe = RECIPES[name];
        var wrap = table.closest(".bootstrap-table");
        if (!wrap) return;
        var fields = headerFields(table);
        if (!fields.length) return;
        wrap.setAttribute("data-ny-list", name);
        table.classList.add("ny-listified");
        if (recipe.mask) wrap.classList.add("ny-mask-on");
        $qa(":scope > tbody > tr", table).forEach(function (row) {
            applyRecipeRow(table, row, recipe, fields);
        });
        mountChrome(table, wrap, recipe);
    }

    function enhanceTables(scope) {
        $qa("table[id]", scope || document).forEach(enhanceTable);
    }

    /* -------------------- ev2 Markdown 工具栏:响应式溢出菜单 --------------------
       不改动 ev2 组件本身;当格式按钮一行放不下时,末尾出现"更多",把溢出的按钮
       收进下拉,保持工具栏永远单行。用 ResizeObserver 处理隐藏标签页/宽度变化。 */
    function layoutEv2Toolbar(tools, buttons, wrap, more, menu) {
        if (!tools.offsetWidth) return;               /* 隐藏(display:none)时先跳过,可见后 RO 再触发 */
        buttons.forEach(function (b) { tools.insertBefore(b, wrap); });  /* 全部还原到工具栏(含菜单里的) */
        more.style.display = "";                       /* 先显示"更多"以便测量 */
        /* 用视口相对的 getBoundingClientRect 比较行位:more 在 position:relative 的 wrap 内,
           offsetParent 与其它按钮不同,offsetTop 不可比;rect.top 才一致可靠。 */
        var firstTop = buttons[0].getBoundingClientRect().top;
        var wrapped = function (elm) { return elm.getBoundingClientRect().top > firstTop + 2; };
        if (!wrapped(more) && !buttons.some(wrapped)) {  /* 全放得下,不需要"更多" */
            more.style.display = "none";
            return;
        }
        for (var i = buttons.length - 1; i >= 1; i--) {  /* 从末尾逐个移入菜单,直到"更多"回到首行 */
            if (wrapped(more)) {
                menu.insertBefore(buttons[i], menu.firstChild);
            } else {
                break;
            }
        }
        if (!menu.children.length) more.style.display = "none";
    }

    function enhanceEditorToolbar(bar) {
        if (bar.getAttribute("data-ny-ev2") === "1") return;
        var tools = $q(".ev2-tools", bar);
        if (!tools) return;
        var buttons = $qa(":scope > button", tools);
        if (buttons.length < 3) return;
        bar.setAttribute("data-ny-ev2", "1");

        var wrap = el("div", "ny-ev2-more-wrap");
        var more = el("button", "ev2-tb ny-ev2-more", icon("more_horiz"));
        more.type = "button";
        more.setAttribute("title", i18n("更多"));
        more.setAttribute("aria-label", i18n("更多工具"));
        var menu = el("div", "ny-ev2-menu");
        menu.hidden = true;
        wrap.appendChild(more);
        wrap.appendChild(menu);
        tools.appendChild(wrap);

        var setOpen = function (v) {
            menu.hidden = !v;
            more.classList.toggle("is-on", v);
        };
        more.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();
            setOpen(menu.hidden);
        });
        menu.addEventListener("click", function (event) {
            if (event.target.closest("button")) setOpen(false);  /* 选了工具即收起 */
        });
        document.addEventListener("click", function (event) {
            if (!menu.hidden && !wrap.contains(event.target)) setOpen(false);
        });

        var relayout = function () {
            setOpen(false);
            layoutEv2Toolbar(tools, buttons, wrap, more, menu);
        };
        relayout();
        if (window.ResizeObserver) {
            var ro = new ResizeObserver(function () {
                if (!bar.isConnected) { ro.disconnect(); return; }
                relayout();
            });
            ro.observe(bar);
        }
    }

    function enhanceEditors(scope) {
        $qa(".ev2-bar", scope || document).forEach(enhanceEditorToolbar);
    }

    /* ------------------------------------------------ 5. 弹窗运行时 */

    function renderLayuiForms() {
        if (!window.layui || typeof window.layui.use !== "function") return;
        try {
            window.layui.use("form", function () {
                if (window.layui.form && typeof window.layui.form.render === "function") {
                    window.layui.form.render("select");
                    window.layui.form.render("radio");
                    window.layui.form.render("checkbox");
                }
            });
        } catch (error) {
        }
    }

    function muiValue(item) {
        var selectInput = item.querySelector(".layui-select-title .layui-input");
        if (selectInput) return selectInput.value;
        var input = item.querySelector(".layui-input:not([type='hidden']), .layui-textarea");
        return input ? input.value : "";
    }

    function refreshMuiField(item) {
        if (!item) return;
        item.classList.toggle("mui-filled", text(muiValue(item)) !== "");
    }

    function tagMuiFields(scope) {
        $qa(".component-popup .layui-form-pane .layui-form-item", scope || document).forEach(function (item) {
            var label = item.querySelector(":scope > .layui-form-label");
            if (!label || text(label.textContent) === "" || label.classList.contains("hide")) {
                item.classList.remove("mui-float", "mui-focused", "mui-filled");
                item.classList.add("ny-control-stack");
                return;
            }
            var blocked = item.querySelector(".layui-form-switch, .layui-form-checkbox, .layui-form-radio, .image-render, .file-render, .layui-upload, .editor-wrapper, .w-e-text-container, .ace_editor, .treeCheckbox, xm-select, .CodeMirror");
            if (blocked) {
                item.classList.remove("mui-float", "mui-focused", "mui-filled");
                item.classList.add("ny-control-stack");
                return;
            }
            var field = item.querySelector(".layui-input:not([type='hidden']), .layui-textarea");
            if (!field) {
                item.classList.add("ny-control-stack");
                return;
            }
            item.classList.remove("ny-control-stack");
            item.classList.add("mui-float");
            refreshMuiField(item);
        });
    }

    function bindMuiRuntime() {
        if (root.getAttribute("data-ny-mui-bound") === "1") return;
        root.setAttribute("data-ny-mui-bound", "1");
        ["focusin", "focusout", "input", "change"].forEach(function (type) {
            document.addEventListener(type, function (event) {
                if (!event.target.closest) return;
                var item = event.target.closest(".component-popup .layui-form-item.mui-float");
                if (!item) return;
                if (type === "focusin") item.classList.add("mui-focused");
                if (type === "focusout") item.classList.remove("mui-focused");
                refreshMuiField(item);
            });
        });
    }

    /* ------------------------------------------------ 6. 商城 */

    function triggerStoreSearch() {
        var input = $q(".item-search-input");
        if (!input) return;
        if (window.jQuery) {
            var event = window.jQuery.Event("keypress");
            event.which = 13;
            window.jQuery(input).trigger(event);
            return;
        }
        input.dispatchEvent(new KeyboardEvent("keypress", {key: "Enter", bubbles: true}));
    }

    /* 售罄判定不能用「包含售罄」：隐藏库存时接口会返回「即将售罄」(还有货)，
       而且文案是就地翻译的，写死的中文子串在其它语言下又永远不命中。
       以平台 item.js 在 stock_state<=0 时打上的 badge-soft-danger 为准，
       再用整串相等兜住首屏(服务端渲染带「库存」前缀、接口返回不带)。 */
    function isStockSoldOut(node) {
        if (!node) return false;
        if (node.classList.contains("badge-soft-danger")) return true;
        var label = text(node.textContent);
        var prefixes = ["库存", i18n("库存")];
        for (var i = 0; i < prefixes.length; i++) {
            if (prefixes[i] && label.indexOf(prefixes[i]) === 0) {
                label = text(label.slice(prefixes[i].length)).replace(/^[：:]\s*/, "");
                break;
            }
        }
        return label === "0" || label === i18n("售罄") || label === i18n("已售罄");
    }

    function updatePurchaseDock() {
        var button = $q('[data-ny-sheet-open="payment"]');
        if (!button) return;
        var stock = $q(".item-stock");
        var snap = $q(".snap-up");
        var soldOut = isStockSoldOut(stock);
        /* 抢购进行中的徽章是「抢购结束还剩 x」，同样带「结束」二字；
           平台只在真正结束时把徽章换成 badge-soft-muted。 */
        var ended = !!snap && snap.classList.contains("badge-soft-muted");
        var payCount = $qa(".pay-list .pay").length;
        button.disabled = soldOut || ended || payCount === 0;
        var label = button.querySelector("span:not(.material-icons-outlined)");
        if (label) label.textContent = ended ? i18n("抢购已结束") : (soldOut ? i18n("商品已售罄") : (payCount ? i18n("选择支付方式") : i18n("正在加载支付")));
    }

    /* 分类树父级展开/收起 */
    function setCatExpanded(button, expanded) {
        var node = button.closest(".ny-cat-node--parent");
        if (!node) return;
        var children = node.querySelector(":scope > .ny-cat-children");
        button.classList.toggle("is-expanded", expanded);
        button.setAttribute("aria-expanded", expanded ? "true" : "false");
        if (children) children.hidden = !expanded;
    }

    /* 展开当前选中叶子(或首个叶子)的所有祖先父级 */
    function expandActiveBranch() {
        var drawer = $q(".ny-store-drawer");
        if (!drawer) return;
        var active = $q(".switch-category.is-primary", drawer) || $q(".switch-category", drawer);
        if (!active) return;
        var node = active.closest(".ny-cat-children");
        while (node) {
            var parent = node.closest(".ny-cat-node--parent");
            if (!parent) break;
            var button = parent.querySelector(":scope > .ny-cat-parent");
            if (button) setCatExpanded(button, true);
            node = parent.closest(".ny-cat-children");
        }
    }

    /* 筛选按钮反映当前分类:默认第一项(推荐)时显示"筛选",
       选中其他分类时显示分类名并高亮 */
    function syncStoreFilterChip() {
        var trigger = $q(".ny-store-filter-trigger");
        if (!trigger) return;
        var label = $q(".ny-store-filter-trigger__label", trigger);
        var items = $qa(".ny-store-drawer .switch-category");
        var active = $q(".ny-store-drawer .switch-category.is-primary");
        if (label && active && items.length && active !== items[0]) {
            var name = $q(".ny-drawer-item-name", active);
            label.textContent = text(name ? name.textContent : active.textContent) || i18n("筛选");
            trigger.classList.add("is-on");
        } else if (label) {
            label.textContent = i18n("筛选");
            trigger.classList.remove("is-on");
        }
    }

    function categoryCount(value) {
        var count = parseInt(value, 10);
        return isNaN(count) || count < 0 ? 0 : count;
    }

    function renderCategoryCount(badge, count) {
        if (!badge) return;
        badge.textContent = count > 99 ? "99+" : String(count);
        badge.setAttribute("data-ny-category-total", String(count));
        var label = i18n("{n} 件商品").replace("{n}", count);
        badge.setAttribute("aria-label", label);
        badge.setAttribute("title", label);
        badge.classList.toggle("is-empty", count === 0);
    }

    /* 叶子显示直属商品数；不可筛选的父级显示自身与全部后代的分支合计。 */
    function syncCategoryCounts() {
        var badges = $qa(".ny-store-drawer [data-ny-category-count]");
        badges.forEach(function (badge) {
            renderCategoryCount(badge, categoryCount(badge.getAttribute("data-ny-category-direct")));
        });

        $qa(".ny-store-drawer .ny-cat-node--parent").reverse().forEach(function (node) {
            var button = $q(":scope > .ny-cat-parent", node);
            var badge = button ? $q(":scope > [data-ny-category-count]", button) : null;
            var total = categoryCount(badge ? badge.getAttribute("data-ny-category-direct") : 0);
            var children = $q(":scope > .ny-cat-children", node);
            if (children) {
                Array.prototype.forEach.call(children.children, function (child) {
                    var row = child.classList.contains("ny-cat-node--parent")
                        ? $q(":scope > .ny-cat-parent", child)
                        : (child.classList.contains("switch-category") ? child : $q(":scope > .switch-category", child));
                    var childBadge = row ? $q(":scope > [data-ny-category-count]", row) : null;
                    total += categoryCount(childBadge ? childBadge.getAttribute("data-ny-category-total") : 0);
                });
            }
            renderCategoryCount(badge, total);
        });
    }

    /* 接口在隐藏精确库存时可能返回“已售罄”文字，旧商城脚本只判断数字 0。
       纽约主题在商品进入 DOM 后补齐售罄语义、遮罩和不可点击状态。 */
    function enhanceStoreProducts() {
        $qa(".ny-product-grid > a").forEach(function (link) {
            if (link.getAttribute("data-ny-product-bound") === "1") return;
            link.setAttribute("data-ny-product-bound", "1");
            var card = $q(".acg-card", link);
            var stock = $q(".stat-bottom span:first-child", link);
            if (!card || !stock) return;
            var delivery = $q(".tags .badge-soft-success", link);
            var featured = $q(".tags .badge-soft-primary", link);
            if (delivery && text(delivery.textContent).indexOf("在线") >= 0) {
                delivery.classList.add("is-online");
            }
            if (featured) featured.textContent = i18n("精选");
            var stockLabel = text(stock.textContent).replace(/^库存\s*[：:]?\s*/, "");
            if (/即将|紧张|少量/.test(stockLabel)) {
                link.classList.add("has-low-stock");
                stock.textContent = i18n("库存紧张");
                return;
            }
            if (["0", i18n("售罄"), i18n("已售罄")].indexOf(stockLabel) < 0) return;
            card.classList.add("soldout");
            link.setAttribute("href", "javascript:void(0);");
            link.setAttribute("aria-disabled", "true");
            link.setAttribute("tabindex", "-1");
            if (!$q(".soldout-ribbon", card)) {
                var ribbon = document.createElement("span");
                ribbon.className = "soldout-ribbon";
                ribbon.textContent = i18n("已售罄");
                card.appendChild(ribbon);
            }
        });
    }

    function enhanceStoreEmptyState() {
        var empty = $q(".ny-product-grid > div[style]");
        if (!empty) return;
        empty.removeAttribute("style");
        empty.className = "ny-product-empty";
        empty.setAttribute("role", "status");
        empty.textContent = "";

        var icon = document.createElement("span");
        icon.className = "material-icons-outlined";
        icon.setAttribute("aria-hidden", "true");
        icon.textContent = "inventory_2";

        var title = document.createElement("strong");
        title.textContent = i18n("没有找到商品");
        var hint = document.createElement("small");
        hint.textContent = i18n("换个关键词或分类试试");

        empty.appendChild(icon);
        empty.appendChild(title);
        empty.appendChild(hint);
    }

    function initStorefront() {
        $qa(".ny-search-submit").forEach(function (button) {
            if (button.getAttribute("data-ny-bound") === "1") return;
            button.setAttribute("data-ny-bound", "1");
            button.addEventListener("click", function () {
                triggerStoreSearch();
                window.setTimeout(syncStoreFilterChip, 80);
            });
        });
        $qa(".item-search-input").forEach(function (input) {
            if (input.getAttribute("data-ny-sync-bound") === "1") return;
            input.setAttribute("data-ny-sync-bound", "1");
            input.addEventListener("keydown", function (event) {
                if (event.key === "Enter") window.setTimeout(syncStoreFilterChip, 80);
            });
        });
        if (window.location.hash === "#categories" && $q(".ny-store-drawer")) {
            window.setTimeout(function () {
                openDrawer("store-filter");
            }, 120);
        }
        expandActiveBranch();
        syncStoreFilterChip();
        syncCategoryCounts();
        enhanceStoreProducts();
        enhanceStoreEmptyState();
        document.body.classList.toggle("ny-has-dock", !!$q(".ny-buy-dock"));
        $qa(".ny-purchase-section").forEach(function (section) {
            var visible = $qa(":scope > *", section).some(function (child) {
                return child.offsetHeight > 0 || child.offsetWidth > 0;
            });
            section.style.display = visible ? "" : "none";
        });
        updatePurchaseDock();
        window.setTimeout(updatePurchaseDock, 500);
        window.setTimeout(updatePurchaseDock, 1400);
    }

    /* ------------------------------------------------ 7. 店铺页 */

    var businessLayer = null;

    function resetTableView(selector, delay) {
        if (!window.jQuery || !window.jQuery.fn || typeof window.jQuery.fn.bootstrapTable !== "function") return;
        window.setTimeout(function () {
            var $table = window.jQuery(selector);
            if (!$table.length || !$table.data("bootstrap.table")) return;
            try {
                $table.bootstrapTable("resetView");
            } catch (error) {
            }
        }, delay || 0);
    }

    function switchBusinessTab(shell, key) {
        if (!shell) return;
        $qa("[data-ny-business-tab-trigger]", shell).forEach(function (button) {
            var active = button.getAttribute("data-ny-business-tab-trigger") === key;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-selected", active ? "true" : "false");
        });
        $qa("[data-ny-business-tab-panel]", shell).forEach(function (panel) {
            var active = panel.getAttribute("data-ny-business-tab-panel") === key;
            panel.classList.toggle("is-active", active);
            panel.setAttribute("aria-hidden", active ? "false" : "true");
        });
        if (key === "product") {
            resetTableView("#master_category", 60);
            resetTableView("#master_commodity", 140);
            window.setTimeout(function () {
                enhanceTables(shell);
            }, 200);
        }
        renderLayuiForms();
    }

    function layerApi() {
        if (window.layer && typeof window.layer.open === "function") return window.layer;
        if (window.layui && window.layui.layer && typeof window.layui.layer.open === "function") return window.layui.layer;
        return null;
    }

    function restoreCommoditySection(shell) {
        var anchor = $q("[data-ny-business-commodity-anchor]", shell);
        var section = $q("[data-ny-business-commodity-section]");
        if (!anchor || !section) return;
        if (anchor.nextElementSibling !== section) anchor.insertAdjacentElement("afterend", section);
        section.classList.remove("is-ny-business-popup-mounted");
        section.style.removeProperty("display");
    }

    function openCommoditySection(shell, title) {
        var api = layerApi();
        var section = $q("[data-ny-business-commodity-section]", shell);
        if (!api || !section || !window.jQuery) return;
        restoreCommoditySection(shell);
        section.classList.add("is-ny-business-popup-mounted");
        section.style.display = "block";
        if (businessLayer !== null && typeof api.close === "function") {
            api.close(businessLayer);
            businessLayer = null;
        }
        var small = window.innerWidth <= 560;
        businessLayer = api.open({
            type: 1,
            skin: "ny-business-popup-layer",
            title: title ? i18n("主站商品 · {name}").replace("{name}", function () { return title; }) : i18n("主站商品"),
            shadeClose: true,
            maxmin: false,
            area: small ? ["100%", "100%"] : ["440px", "86%"],
            content: window.jQuery(section),
            success: function () {
                resetTableView("#master_commodity", 100);
                window.setTimeout(function () {
                    enhanceTables(document);
                }, 180);
            },
            end: function () {
                businessLayer = null;
                restoreCommoditySection(shell);
                resetTableView("#master_commodity", 80);
            }
        });
    }

    function findCommodityButton(target, shell) {
        if (!target || !shell || typeof target.closest !== "function") return null;
        var button = target.closest(".a-badge-glass");
        if (!button) return null;
        var table = button.closest("#master_category");
        if (!table || !shell.contains(table)) return null;
        // 认 data-btn-title 的原文键,不能比对可见文字——按钮标题会被 i18n() 翻译掉。
        return button.getAttribute("data-btn-title") === "查看商品" ? button : null;
    }

    function categoryName(button) {
        var row = button ? button.closest("tr") : null;
        var cell = row ? row.querySelector('td[data-f="name"]') || row.querySelector("td") : null;
        return cell ? text(cell.textContent) : "";
    }

    function initBusiness() {
        $qa(".ny-page-shell-business").forEach(function (shell) {
            if (shell.getAttribute("data-ny-business-bound") !== "1") {
                shell.setAttribute("data-ny-business-bound", "1");
                shell.addEventListener("click", function (event) {
                    var tab = event.target.closest("[data-ny-business-tab-trigger]");
                    if (tab) switchBusinessTab(shell, tab.getAttribute("data-ny-business-tab-trigger") || "basic");
                });
                shell.addEventListener("click", function (event) {
                    var button = findCommodityButton(event.target, shell);
                    if (!button) return;
                    window.setTimeout(function () {
                        openCommoditySection(shell, categoryName(button));
                    }, 200);
                }, true);
            }
            var selected = $q("[data-ny-business-tab-trigger].is-active", shell);
            switchBusinessTab(shell, selected ? selected.getAttribute("data-ny-business-tab-trigger") : "basic");
        });
    }

    /* ------------------------------------------------ 8. 个人资料结算选择 */

    function initSettlement() {
        $qa(".ny-page-shell-personal").forEach(function (shell) {
            if (shell.getAttribute("data-ny-settlement-bound") === "1") return;
            var input = $q("[data-ny-settlement-input]", shell);
            var options = $qa("[data-ny-settlement]", shell);
            if (!input || !options.length) return;
            var sync = function (value) {
                options.forEach(function (option) {
                    option.classList.toggle("checked", option.getAttribute("data-ny-settlement") === String(value));
                });
            };
            shell.setAttribute("data-ny-settlement-bound", "1");
            sync(input.value);
            shell.addEventListener("click", function (event) {
                var option = event.target.closest("[data-ny-settlement]");
                if (!option || !shell.contains(option)) return;
                input.value = option.getAttribute("data-ny-settlement") || "";
                sync(input.value);
            });
        });
    }

    /* ------------------------------------------------ 9. 生命周期 */

    function bindShell() {
        if (root.getAttribute("data-ny-shell-bound") === "1") return;
        root.setAttribute("data-ny-shell-bound", "1");

        /* 捕获阶段抢在 PJAX(冒泡委托)之前:离开会员中心的链接阻止 PJAX 接管,
           交给浏览器整页跳转,避免跨主题串台。Theme.js 在 global.js 之后加载,
           普通冒泡监听会晚于 PJAX,故必须用 capture=true。 */
        document.addEventListener("click", function (event) {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            if (!document.body || !document.body.classList.contains("ny-member-body")) return;
            var link = event.target.closest ? event.target.closest("a[href]") : null;
            if (!link || link.target === "_blank") return;
            if (leavesMemberCenter(link.getAttribute("href"))) {
                event.stopImmediatePropagation();
            }
        }, true);

        document.addEventListener("click", function (event) {
            var open = event.target.closest("[data-ny-sheet-open]");
            var close = event.target.closest("[data-ny-sheet-close]");
            var drawerOpen = event.target.closest("[data-ny-drawer-open]");
            var drawerClose = event.target.closest("[data-ny-drawer-close]");
            if (open) {
                event.preventDefault();
                openSheet(open.getAttribute("data-ny-sheet-open") || "");
                return;
            }
            if (drawerOpen) {
                event.preventDefault();
                openDrawer(drawerOpen.getAttribute("data-ny-drawer-open") || "");
                return;
            }
            if (close) {
                event.preventDefault();
                closeSheets();
                return;
            }
            if (drawerClose) {
                event.preventDefault();
                closeDrawers();
            }
        });

        document.addEventListener("click", function (event) {
            if (event.target.classList && event.target.classList.contains("ny-sheet-backdrop")) {
                closeSheets();
                closeDrawers();
            }
        });

        /* 「默认弹窗公告」只认公告面板右上角的 ❌:遮罩身上也挂着 data-ny-sheet-close,
           所以必须限定在公告面板内部,点遮罩关掉不计时。开关没开(没有 data-ny-notice-auto)时不记 */
        document.addEventListener("click", function (event) {
            if (event.target.closest && event.target.closest('[data-ny-sheet="notice"][data-ny-notice-auto="1"] [data-ny-sheet-close]')) {
                markNoticeClosed();
            }
        });

        /* 底栏链接:点击即时高亮(PJAX 加载完成后 syncBottomNav 再校正);
           经营是打开面板的按钮,不在此即时切换 */
        document.addEventListener("click", function (event) {
            var link = event.target.closest ? event.target.closest(".ny-bottom-nav a[href]") : null;
            if (!link) return;
            var nav = link.closest(".ny-bottom-nav");
            $qa("a, button", nav).forEach(function (item) {
                item.classList.remove("is-active");
            });
            link.classList.add("is-active");
        });

        /* 首页分类抽屉:父级展开/收起、叶子选中后收起并同步筛选按钮;
           兼容旧 /#categories 锚点(游客底栏"分类") */
        document.addEventListener("click", function (event) {
            if (!event.target.closest) return;
            var toggle = event.target.closest(".ny-store-drawer [data-ny-cat-toggle]");
            if (toggle) {
                event.preventDefault();
                setCatExpanded(toggle, toggle.getAttribute("aria-expanded") !== "true");
                return;
            }
            var item = event.target.closest(".ny-store-drawer .switch-category");
            if (item) {
                window.setTimeout(function () {
                    closeDrawers();
                    syncStoreFilterChip();
                }, 120);
                return;
            }
            var catLink = event.target.closest('a[href="/#categories"], a[href="#categories"]');
            if (catLink && $q(".ny-store-drawer")) {
                event.preventDefault();
                openDrawer("store-filter");
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape") {
                closeSheets();
                closeDrawers();
            }
        });

        $qa("[data-theme-toggle]").length && bindThemeButtons();
    }

    function bindThemeButtons() {
        $qa("[data-theme-toggle]").forEach(function (button) {
            if (button.getAttribute("data-ny-bound") === "1") return;
            button.setAttribute("data-ny-bound", "1");
            button.addEventListener("click", function () {
                var preference = button.getAttribute("data-theme-toggle") || "auto";
                writeTheme(preference);
                applyTheme(preference);
                window.setTimeout(closeSheets, 120);
            });
        });
    }

    /* 底部导航高亮:导航条在 #pjax-container 外部,PJAX 换页后不会重渲染,
       服务端 $title 渲染的 is-active 会滞留在首个页面,故按当前 URL 重算。 */
    var NAV_GROUPS = [
        {sel: '[data-ny-sheet-open="workbench"]', paths: ["/user/business", "/user/category", "/user/commodity", "/user/card", "/user/coupon", "/user/order"]},
        {sel: 'a[href="/user/recharge/index"]', paths: ["/user/recharge", "/user/bill", "/user/cash"]},
        {sel: 'a[href="/user/dashboard/index"]', paths: ["/user/dashboard", "/user/security", "/user/ticket", "/user/agent", "/user/message"]},
        {sel: 'a[href="/user/personal/purchaseRecord"]', paths: ["/user/personal"]},
        {sel: 'a[href="/user/index/query"]', paths: ["/user/index/query"]},
        {sel: 'a[href="/user/authentication/login"]', paths: ["/user/authentication"]}
    ];

    function syncBottomNav() {
        var nav = $q(".ny-bottom-nav");
        if (!nav) return;
        var path = window.location.pathname || "/";
        $qa("a, button", nav).forEach(function (item) {
            item.classList.remove("is-active");
        });
        var matched = null;
        for (var i = 0; i < NAV_GROUPS.length; i++) {
            var hit = NAV_GROUPS[i].paths.some(function (prefix) {
                return path === prefix || path.indexOf(prefix + "/") === 0 || path.indexOf(prefix) === 0;
            });
            if (hit) {
                matched = nav.querySelector(NAV_GROUPS[i].sel);
                if (matched) break;
            }
        }
        if (!matched && (path === "/" || path === "")) matched = nav.querySelector('a[href="/"]');
        if (matched) matched.classList.add("is-active");
    }

    function hideLoading() {
        if (window.Loading && typeof window.Loading.hide === "function") window.Loading.hide();
        if (window.jQuery) window.jQuery(".net-loading").hide();
    }

    function exitSelectionGlobal() {
        document.body.classList.remove("ny-selecting");
        $qa(".is-selecting").forEach(function (node) {
            node.classList.remove("is-selecting");
        });
    }

    var scanTimer = null;

    function queueScan() {
        if (scanTimer !== null) return;
        scanTimer = window.setTimeout(function () {
            scanTimer = null;
            tagMuiFields(document);
            enhanceTables(document);
            enhanceEditors(document);
            updatePurchaseDock();
            enhanceStoreProducts();
            enhanceStoreEmptyState();
        }, 50);
    }

    function initPage() {
        syncTheme();
        bindThemeButtons();
        bindMuiRuntime();
        initBusiness();
        initSettlement();
        initStorefront();
        renderLayuiForms();
        tagMuiFields(document);
        enhanceTables(document);
        enhanceEditors(document);
        syncBottomNav();
        closeSheets();
        closeDrawers();
        hideLoading();
        /* 必须在上面的 closeSheets() 之后,否则刚弹出就被关掉 */
        autoOpenNotice();
    }

    bindShell();

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initPage);
    } else {
        initPage();
    }

    if (window.MutationObserver) {
        var observer = new MutationObserver(queueScan);
        var target = document.body || root;
        observer.observe(target, {childList: true, subtree: true});
    }

    if (window.jQuery) {
        var $document = window.jQuery(document);
        /* 清掉页面注入的列表工具与挂载标记:PJAX 缓存快照(含 back)
           会带着旧注入物回来,恢复后先清理再由 initPage 全量重建。 */
        var cleanupInjected = function () {
            $qa(".ny-listbar").forEach(function (node) {
                node.remove();
            });
            $qa("[data-ny-chrome]").forEach(function (node) {
                node.removeAttribute("data-ny-chrome");
            });
            $qa("[data-ny-select-mounted]").forEach(function (node) {
                node.removeAttribute("data-ny-select-mounted");
            });
            $qa("[data-ny-filter-mounted]").forEach(function (node) {
                node.removeAttribute("data-ny-filter-mounted");
            });
        };

        $document.on("pjax:send pjax:popstate", function () {
            closeSheets();
            removeDynamicSheets();
            exitSelectionGlobal();
            if (window.layer && typeof window.layer.closeAll === "function") {
                try {
                    window.layer.closeAll();
                } catch (error) {
                }
            }
        });
        $document.on("post-body.bs.table reset-view.bs.table load-success.bs.table", function () {
            window.setTimeout(function () {
                enhanceTables(document);
            }, 20);
        });
        $document.on("check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table", function (event) {
            window.setTimeout(function () {
                var table = event.target;
                if (table && table.tagName === "TABLE") updateSelectionState(table);
            }, 30);
        });
        $document.on("pjax:complete pjax:end pjax:error pjax:timeout", function () {
            removeDynamicSheets();
            cleanupInjected();
            window.setTimeout(initPage, 80);
        });
    }

    window.addEventListener("pageshow", hideLoading);
    window.addEventListener("load", hideLoading);
}();
