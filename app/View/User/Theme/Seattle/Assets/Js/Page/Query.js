//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

(function ($) {
    "use strict";

    if (!$) {
        return;
    }

    var activeRequest = null;
    var secretRequests = [];
    var copyTimers = [];
    var requestVersion = 0;
    var currentKeyword = "";
    var currentPage = 0;
    var totalOrders = 0;
    var MAX_QUERY_LENGTH = 256;
    var PAGE_SIZE = 10;

    function text(value, fallback) {
        if (value === null || value === undefined || value === "") {
            return fallback === undefined ? "" : String(fallback);
        }
        return String(value);
    }

    function escapeHtml(value) {
        return text(value)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function safeInlineHtml(value) {
        return window.SeattleTheme && typeof window.SeattleTheme.safeInlineHtml === "function"
            ? window.SeattleTheme.safeInlineHtml(value)
            : escapeHtml(value);
    }

    function displayValue(value) {
        if (Array.isArray(value)) {
            return value.map(function (item) {
                return text(item);
            }).filter(Boolean).join("、") || "-";
        }
        if (value && typeof value === "object") {
            try {
                return JSON.stringify(value);
            } catch (error) {
                return "-";
            }
        }
        return text(value, "-");
    }

    function element(tag, className, content) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (content !== undefined) {
            node.textContent = text(content);
        }
        return node;
    }

    function icon(name, className) {
        var node = element("span", "material-icons-outlined" + (className ? " " + className : ""), name);
        node.setAttribute("aria-hidden", "true");
        return node;
    }

    function safeImageUrl(value, fallback) {
        var source = text(value).trim();
        if (!source || /[\\\u0000-\u001f]/.test(source)) {
            return fallback;
        }

        try {
            var parsed = new URL(source, window.location.origin + "/");
            return parsed.protocol === "http:" || parsed.protocol === "https:" ? parsed.href : fallback;
        } catch (error) {
            return fallback;
        }
    }

    function notify(message, type) {
        if (window.SeattleTheme && typeof window.SeattleTheme.notify === "function") {
            window.SeattleTheme.notify(message, {type: type || "info"});
            return;
        }
        if (window.message && typeof window.message[type || "info"] === "function") {
            window.message[type || "info"](message);
        }
    }

    function notifyError(message) {
        notify(message, "error");
    }

    function notifySuccess(message) {
        notify(message, "success");
    }

    function page() {
        return document.querySelector(".st-query-page");
    }

    function stateNode(name) {
        var root = page();
        return root ? root.querySelector('[data-st-query-state="' + name + '"]') : null;
    }

    function showState(name) {
        ["loading", "empty", "error", "results"].forEach(function (state) {
            var node = stateNode(state);
            if (!node) {
                return;
            }
            var visible = state === name;
            node.hidden = !visible;
            node.style.display = visible ? "" : "none";
        });
    }

    function setSearchBusy(busy) {
        var root = page();
        if (!root) {
            return;
        }
        var button = root.querySelector(".btn-search-query");
        var input = root.querySelector('[name="keywords"]');
        if (button) {
            button.disabled = Boolean(busy);
            button.setAttribute("aria-busy", busy ? "true" : "false");
            button.classList.toggle("is-loading", Boolean(busy));
            var buttonIcon = button.querySelector(".material-icons-outlined");
            if (buttonIcon) {
                buttonIcon.textContent = busy ? "" : "search";
            }
            var label = button.querySelector("[data-st-query-button-label]");
            if (label) {
                label.textContent = busy ? i18n("正在查询") : i18n("查询订单");
            }
        }
        if (input) {
            input.setAttribute("aria-busy", busy ? "true" : "false");
            input.readOnly = Boolean(busy);
        }
    }

    function setMoreBusy(busy) {
        var root = page();
        var button = root ? root.querySelector("[data-st-query-more]") : null;
        if (!button) {
            return;
        }
        button.disabled = Boolean(busy);
        button.setAttribute("aria-busy", busy ? "true" : "false");
        button.classList.toggle("is-loading", Boolean(busy));
        var buttonIcon = button.querySelector(".material-icons-outlined");
        var label = button.querySelector("[data-st-query-more-label]");
        if (buttonIcon) {
            buttonIcon.textContent = busy ? "" : "expand_more";
        }
        if (label) {
            label.textContent = busy ? i18n("正在加载") : i18n("加载更多");
        }
    }

    function setSecretBusy(form, busy) {
        var button = form ? form.querySelector("button[type=submit]") : null;
        if (!button) {
            return;
        }
        button.disabled = Boolean(busy);
        button.setAttribute("aria-busy", busy ? "true" : "false");
        button.classList.toggle("is-loading", Boolean(busy));
        var buttonIcon = button.querySelector("[data-st-secret-icon]");
        var buttonLabel = button.querySelector("[data-st-secret-label]");
        if (buttonIcon) {
            buttonIcon.textContent = busy ? "" : "visibility";
        }
        if (buttonLabel) {
            buttonLabel.textContent = busy ? i18n("正在读取") : i18n("查看卡密");
        }
    }

    function setDetailsBusy(button, busy) {
        if (!button) {
            return;
        }
        button.disabled = Boolean(busy);
        button.setAttribute("aria-busy", busy ? "true" : "false");
        button.classList.toggle("is-loading", Boolean(busy));
        var buttonIcon = button.querySelector("[data-st-details-icon]");
        var buttonLabel = button.querySelector("[data-st-details-label]");
        if (buttonIcon) {
            buttonIcon.textContent = busy ? "" : "manage_search";
        }
        if (buttonLabel) {
            buttonLabel.textContent = busy ? i18n("正在读取") : i18n("完整信息");
        }
    }

    function copyText(value) {
        var content = text(value);
        var browserNavigator = window.navigator;
        if (browserNavigator && browserNavigator.clipboard && window.isSecureContext) {
            return browserNavigator.clipboard.writeText(content);
        }
        if (window.clipboardData && typeof window.clipboardData.setData === "function") {
            return window.clipboardData.setData("Text", content)
                ? Promise.resolve()
                : Promise.reject(new Error("copy failed"));
        }

        return new Promise(function (resolve, reject) {
            var input = document.createElement("textarea");
            input.value = content;
            input.setAttribute("readonly", "");
            input.style.position = "fixed";
            input.style.left = "-9999px";
            input.style.opacity = "0";
            document.body.appendChild(input);
            input.select();
            input.setSelectionRange(0, input.value.length);
            try {
                if (!document.execCommand("copy")) {
                    throw new Error("copy failed");
                }
                resolve();
            } catch (error) {
                reject(error);
            } finally {
                input.remove();
            }
        });
    }

    function copyButton(value, label, compact) {
        var button = element("button", compact
            ? "st-query-copy-button st-query-copy-button--icon"
            : "st-button st-button-quiet st-query-copy-button");
        button.type = "button";
        button.setAttribute("data-st-query-copy", "");
        button.setAttribute("aria-label", label);
        button.title = label;
        button.__stCopyValue = text(value);
        button.__stCopyLabel = label;
        button.appendChild(icon("content_copy"));
        if (!compact) {
            var copyLabel = element("span", "", i18n("复制内容"));
            copyLabel.setAttribute("data-st-copy-label", "");
            button.appendChild(copyLabel);
        }
        if (!text(value)) {
            button.disabled = true;
        }
        return button;
    }

    function appendTradeHook(container) {
        var root = page();
        var template = root ? root.querySelector("[data-st-query-trade-hook]") : null;
        if (!template || !template.content || !template.content.childNodes.length) {
            return;
        }
        var hook = element("span", "st-query-trade-hook");
        hook.appendChild(template.content.cloneNode(true));
        container.appendChild(hook);
    }

    function metaItem(label, valueNode) {
        var item = element("div", "st-query-meta-item");
        item.appendChild(element("span", "st-query-meta-label", label));
        if (valueNode && valueNode.nodeType) {
            item.appendChild(valueNode);
        } else {
            item.appendChild(element("span", "st-query-meta-value", displayValue(valueNode)));
        }
        return item;
    }

    function appendChip(container, label, value, tone) {
        var chip = element("span", "st-query-chip" + (tone ? " " + tone : ""));
        chip.appendChild(element("span", "st-query-chip-label", label + "："));
        chip.appendChild(document.createTextNode(displayValue(value)));
        container.appendChild(chip);
    }

    function paymentNode(order) {
        var payment = element("span", "st-query-payment");
        var source = safeImageUrl(order && order.pay ? order.pay.icon : "", "");
        if (source) {
            var image = document.createElement("img");
            image.className = "st-query-payment-icon";
            image.src = source;
            image.alt = "";
            image.width = 20;
            image.height = 20;
            image.loading = "lazy";
            image.addEventListener("error", function () {
                image.remove();
            }, {once: true});
            payment.appendChild(image);
        }
        payment.appendChild(element("span", "st-query-payment-name", order && order.pay ? text(order.pay.name, "-") : "-"));
        return payment;
    }

    function widgetEntries(widget) {
        if (!widget || typeof widget !== "object") {
            return [];
        }
        return Object.keys(widget).map(function (key) {
            var item = widget[key];
            if (!item || typeof item !== "object") {
                return null;
            }
            return {
                label: text(item.cn || item.name || key, i18n("填写信息")),
                value: displayValue(item.value)
            };
        }).filter(Boolean);
    }

    function contentBlock(secret, leaveMessage, widget, tradeNo, allowDetailsLookup) {
        var wrapper = element("div", "st-query-content");
        var entries = widgetEntries(widget);
        if (entries.length) {
            var privateSection = element("section", "st-query-private-data");
            var privateTitle = element("div", "st-query-content-title");
            privateTitle.appendChild(icon("verified_user"));
            privateTitle.appendChild(element("strong", "", i18n("订单填写信息")));
            privateSection.appendChild(privateTitle);
            var privateList = element("dl", "st-query-private-list");
            entries.forEach(function (entry) {
                var row = element("div", "st-query-private-row");
                row.appendChild(element("dt", "", entry.label));
                row.appendChild(element("dd", "", entry.value));
                privateList.appendChild(row);
            });
            privateSection.appendChild(privateList);
            wrapper.appendChild(privateSection);
        }

        var secretValue = text(secret);
        var secretSection = element("section", "st-query-secret-block");
        var secretHead = element("div", "st-query-content-head");
        var secretTitle = element("div", "st-query-content-title");
        secretTitle.appendChild(icon("key"));
        secretTitle.appendChild(element("strong", "st-query-secret-title", i18n("交付内容")));
        secretHead.appendChild(secretTitle);
        var secretActions = element("div", "st-query-content-actions");
        if (allowDetailsLookup && text(tradeNo)) {
            var detailsButton = element("button", "st-button st-button-quiet st-query-details-button");
            detailsButton.type = "button";
            detailsButton.setAttribute("data-st-query-details", "");
            detailsButton.setAttribute("aria-label", i18n("查看订单填写信息"));
            detailsButton.title = i18n("查看订单填写信息");
            detailsButton.__stTradeNo = text(tradeNo);
            var detailsIcon = icon("manage_search");
            detailsIcon.setAttribute("data-st-details-icon", "");
            detailsButton.appendChild(detailsIcon);
            var detailsLabel = element("span", "", i18n("完整信息"));
            detailsLabel.setAttribute("data-st-details-label", "");
            detailsButton.appendChild(detailsLabel);
            secretActions.appendChild(detailsButton);
        }
        secretActions.appendChild(copyButton(secretValue, i18n("复制交付内容"), false));
        secretHead.appendChild(secretActions);
        secretSection.appendChild(secretHead);
        secretSection.appendChild(element("pre", "card-display st-query-card-display", secretValue || i18n("暂无交付内容")));
        wrapper.appendChild(secretSection);

        if (text(leaveMessage).trim()) {
            var note = element("section", "st-query-message");
            var noteTitle = element("div", "st-query-content-title");
            noteTitle.appendChild(icon("chat_bubble_outline"));
            noteTitle.appendChild(element("strong", "st-query-message-title", i18n("商品留言")));
            note.appendChild(noteTitle);
            note.appendChild(element("p", "st-query-message-text", leaveMessage));
            wrapper.appendChild(note);
        }
        return wrapper;
    }

    function passwordBlock(tradeNo) {
        var wrapper = element("div", "card-password-section st-query-password-section");
        var form = element("form", "password-form st-query-secret-form");
        form.setAttribute("data-st-secret-form", "");
        form.noValidate = true;
        form.dataset.tradeNo = tradeNo;

        var controls = element("div", "st-query-secret-controls");
        var label = element("label", "st-field");
        label.appendChild(element("span", "", i18n("查询密码")));
        var input = document.createElement("input");
        input.type = "password";
        input.className = "form-control card-password-input st-query-secret-input";
        input.name = "password";
        input.placeholder = i18n("请输入查询密码");
        input.autocomplete = "current-password";
        input.required = true;
        label.appendChild(input);
        controls.appendChild(label);

        var button = element("button", "st-button st-button-primary view-card-btn st-query-secret-button");
        button.type = "submit";
        var buttonIcon = icon("visibility");
        buttonIcon.setAttribute("data-st-secret-icon", "");
        button.appendChild(buttonIcon);
        var buttonLabel = element("span", "", i18n("查看卡密"));
        buttonLabel.setAttribute("data-st-secret-label", "");
        button.appendChild(buttonLabel);
        controls.appendChild(button);
        form.appendChild(controls);

        var status = element("p", "st-query-secret-status");
        status.setAttribute("data-st-secret-status", "");
        status.setAttribute("role", "status");
        status.setAttribute("aria-live", "polite");
        form.appendChild(status);
        wrapper.appendChild(form);
        return wrapper;
    }

    function deliveryBlock(order) {
        var section = element("section", "st-query-delivery");
        var header = element("header", "st-query-delivery-head");
        var title = element("div", "st-query-content-title");
        title.appendChild(icon("redeem"));
        title.appendChild(element("strong", "", i18n("宝贝内容")));
        header.appendChild(title);
        header.appendChild(element(
            "span",
            "st-query-delivery-badge " + (Number(order.delivery_status) === 1 ? "is-delivered" : "is-waiting"),
            Number(order.delivery_status) === 1 ? i18n("已发货") : i18n("等待发货")
        ));
        section.appendChild(header);
        section.appendChild(order.password === true
            ? passwordBlock(text(order.trade_no))
            : contentBlock(order.secret, order.commodity ? order.commodity.leave_message : "", null, order.trade_no, true));
        return section;
    }

    function orderNode(order) {
        var article = element("article", "st-query-order");
        article.dataset.tradeNo = text(order.trade_no);

        var paid = Number(order.status) === 1;
        var header = element("header", "st-query-order-head");
        var status = element("div", "st-query-order-status");
        status.appendChild(element("span", "st-query-order-eyebrow", i18n("订单状态")));
        var badge = element("span", "st-query-status-badge " + (paid ? "is-paid" : "is-pending"));
        badge.appendChild(icon(paid ? "check_circle" : "schedule"));
        badge.appendChild(document.createTextNode(" " + (paid ? i18n("已付款") : i18n("待付款"))));
        status.appendChild(badge);
        header.appendChild(status);

        var amount = element("div", "st-query-order-amount");
        amount.appendChild(element("span", "", i18n("订单金额")));
        var amountValue = element("strong", "", acgCurrencySymbol());
        amountValue.appendChild(element("span", "amount-number", text(order.amount, "0")));
        amount.appendChild(amountValue);
        header.appendChild(amount);
        article.appendChild(header);

        var basic = element("section", "st-query-meta-grid");
        var tradeValue = element("span", "st-query-meta-action");
        tradeValue.appendChild(element("span", "st-query-meta-value order-no-text trade_no", text(order.trade_no, "-")));
        tradeValue.appendChild(copyButton(text(order.trade_no), i18n("复制订单号"), true));
        appendTradeHook(tradeValue);
        basic.appendChild(metaItem(i18n("订单号"), tradeValue));
        basic.appendChild(metaItem(i18n("下单时间"), element("span", "st-query-meta-value order-time-text", text(order.create_time, "-"))));
        basic.appendChild(metaItem(i18n("付款时间"), element("span", "st-query-meta-value payment-time-text", text(order.pay_time, "-"))));
        basic.appendChild(metaItem(i18n("支付方式"), paymentNode(order)));
        article.appendChild(basic);

        var goods = element("section", "st-query-goods");
        var thumb = element("span", "st-query-goods-thumb");
        var image = document.createElement("img");
        image.className = "st-query-goods-image";
        image.src = safeImageUrl(order.commodity ? order.commodity.cover : "", "/favicon.ico");
        image.alt = "";
        image.width = 44;
        image.height = 44;
        image.loading = "lazy";
        image.addEventListener("error", function () {
            if (!image.dataset.fallback) {
                image.dataset.fallback = "true";
                image.src = "/favicon.ico";
            }
        });
        thumb.appendChild(image);
        goods.appendChild(thumb);

        var details = element("div", "st-query-goods-details");
        var goodsName = element("h3", "st-query-goods-name");
        goodsName.innerHTML = safeInlineHtml(order.commodity ? text(order.commodity.name, i18n("未知商品")) : i18n("未知商品"));
        details.appendChild(goodsName);
        var meta = element("div", "st-query-goods-meta");
        if (text(order.race).trim()) {
            appendChip(meta, i18n("商品类型"), order.race, "is-success");
        }
        var sku = order.sku;
        if (typeof sku === "string" && sku.trim()) {
            try {
                sku = JSON.parse(sku);
            } catch (error) {
                sku = null;
            }
        }
        if (sku && typeof sku === "object" && !Array.isArray(sku)) {
            Object.keys(sku).forEach(function (key) {
                appendChip(meta, text(key), sku[key], "is-primary");
            });
        }
        appendChip(meta, i18n("数量"), order.card_num, "is-warning");
        details.appendChild(meta);
        goods.appendChild(details);
        article.appendChild(goods);

        if (paid) {
            article.appendChild(deliveryBlock(order));
        }
        return article;
    }

    function updatePagination(total) {
        var root = page();
        var list = root ? root.querySelector(".order-list") : null;
        var footer = root ? root.querySelector("[data-st-query-pagination]") : null;
        var progress = root ? root.querySelector("[data-st-query-progress]") : null;
        if (!list || !footer) {
            return;
        }
        var shown = list.children.length;
        if (progress) {
            progress.textContent = i18n("已显示 {shown} / {total} 笔订单").replace("{shown}", shown).replace("{total}", total);
        }
        footer.hidden = shown >= total;
    }

    function renderOrders(orders, append) {
        var root = page();
        var list = root ? root.querySelector(".order-list") : null;
        if (!list) {
            return;
        }
        if (!append) {
            list.replaceChildren();
        }
        (Array.isArray(orders) ? orders : []).forEach(function (order) {
            list.appendChild(orderNode(order || {}));
        });
        showState("results");
        updatePagination(totalOrders);
    }

    function responseError(response, fallback) {
        return response && response.msg ? text(response.msg) : fallback;
    }

    function queryOrders(keywords, requestedPage, append) {
        if (!append) {
            requestVersion += 1;
        }
        var version = requestVersion;
        if (activeRequest && typeof activeRequest.abort === "function") {
            activeRequest.abort();
        }

        if (append) {
            setMoreBusy(true);
        } else {
            setMoreBusy(false);
            setSearchBusy(true);
            var pagination = page() ? page().querySelector("[data-st-query-pagination]") : null;
            if (pagination) {
                pagination.hidden = true;
            }
            showState("loading");
        }

        activeRequest = $.ajax({
            type: "POST",
            url: "/user/api/index/query",
            dataType: "json",
            data: {keywords: keywords, page: requestedPage, limit: PAGE_SIZE}
        }).done(function (response) {
            if (version !== requestVersion) {
                return;
            }
            if (!response || Number(response.code) !== 200) {
                var serverMessage = responseError(response, i18n("订单查询失败，请稍后重试。"));
                if (append) {
                    notifyError(serverMessage);
                    return;
                }
                var errorState = stateNode("error");
                var errorText = errorState ? errorState.querySelector("[data-st-query-error-text]") : null;
                if (errorText) {
                    errorText.textContent = serverMessage;
                }
                showState("error");
                notifyError(serverMessage);
                return;
            }

            var data = response.data || {};
            var orders = Array.isArray(data.list) ? data.list : [];
            var total = Math.max(0, Number(data.total) || 0);
            if (!append && (!total || !orders.length)) {
                currentKeyword = keywords;
                currentPage = 0;
                totalOrders = 0;
                showState("empty");
                return;
            }
            if (append && !orders.length) {
                totalOrders = Math.min(totalOrders, page() ? page().querySelectorAll(".st-query-order").length : totalOrders);
                updatePagination(totalOrders);
                notify(i18n("已经显示全部订单。"), "info");
                return;
            }

            currentKeyword = keywords;
            currentPage = requestedPage;
            totalOrders = total;
            renderOrders(orders, append);
        }).fail(function (_xhr, status) {
            if (version !== requestVersion || status === "abort") {
                return;
            }
            if (append) {
                notifyError(i18n("网络连接失败，暂时无法加载更多订单。"));
                return;
            }
            var errorState = stateNode("error");
            var errorText = errorState ? errorState.querySelector("[data-st-query-error-text]") : null;
            if (errorText) {
                errorText.textContent = i18n("网络连接失败，请检查网络后重试。");
            }
            showState("error");
            notifyError(i18n("网络连接失败，请稍后重试。"));
        }).always(function () {
            if (version === requestVersion) {
                activeRequest = null;
                if (append) {
                    setMoreBusy(false);
                } else {
                    setSearchBusy(false);
                }
            }
        });
    }

    function queryValue() {
        var root = page();
        var input = root ? root.querySelector('[name="keywords"]') : null;
        return input ? input.value.trim() : "";
    }

    function abortSecretRequests() {
        secretRequests.forEach(function (request) {
            if (request && typeof request.abort === "function") {
                request.abort();
            }
        });
        secretRequests = [];
    }

    function syncQueryInput() {
        var root = page();
        var pagination = root ? root.querySelector("[data-st-query-pagination]") : null;
        if (!pagination || activeRequest) {
            return;
        }
        if (!currentKeyword || queryValue() !== currentKeyword) {
            pagination.hidden = true;
            return;
        }
        updatePagination(totalOrders);
    }

    function submitQuery() {
        var keywords = queryValue();
        if (!keywords) {
            notifyError(i18n("请输入联系方式或订单号再查询"));
            var root = page();
            var input = root ? root.querySelector('[name="keywords"]') : null;
            if (input) {
                input.focus();
            }
            return;
        }
        if (keywords.length > MAX_QUERY_LENGTH) {
            notifyError(i18n("查询内容不能超过 {n} 个字符").replace("{n}", MAX_QUERY_LENGTH));
            var queryRoot = page();
            var queryInput = queryRoot ? queryRoot.querySelector('[name="keywords"]') : null;
            if (queryInput) {
                queryInput.focus();
            }
            return;
        }
        abortSecretRequests();
        currentKeyword = keywords;
        currentPage = 0;
        totalOrders = 0;
        queryOrders(keywords, 1, false);
    }

    function loadMore() {
        if (activeRequest || !currentKeyword || currentPage < 1) {
            return;
        }
        if (queryValue() !== currentKeyword) {
            notifyError(i18n("查询条件已更改，请重新查询后再加载更多。"));
            return;
        }
        queryOrders(currentKeyword, currentPage + 1, true);
    }

    function submitSecret(form) {
        if (form.dataset.loading === "true") {
            return;
        }
        var input = form.querySelector('[name="password"]');
        var status = form.querySelector("[data-st-secret-status]");
        var password = input ? input.value.trim() : "";
        if (!password) {
            if (status) {
                status.textContent = i18n("请输入查询密码。");
            }
            notifyError(i18n("请输入查询密码"));
            if (input) {
                input.focus();
            }
            return;
        }

        form.dataset.loading = "true";
        setSecretBusy(form, true);
        if (status) {
            status.textContent = i18n("正在验证并读取卡密…");
        }

        var version = requestVersion;
        var secretRequest = $.ajax({
            type: "POST",
            url: "/user/api/index/secret",
            dataType: "json",
            data: {tradeNo: form.dataset.tradeNo || "", password: password}
        }).done(function (response) {
            if (version !== requestVersion || !document.documentElement.contains(form)) {
                return;
            }
            if (!response || Number(response.code) !== 200) {
                var serverMessage = responseError(response, i18n("无法读取卡密，请稍后重试。"));
                if (status) {
                    status.textContent = serverMessage;
                }
                notifyError(serverMessage);
                return;
            }

            var data = response.data || {};
            var wrapper = form.closest(".card-password-section");
            if (wrapper) {
                wrapper.replaceChildren(contentBlock(data.secret, data.leave_message, data.widget, form.dataset.tradeNo, false));
            }
        }).fail(function (_xhr, requestStatus) {
            if (requestStatus === "abort" || version !== requestVersion) {
                return;
            }
            if (status) {
                status.textContent = i18n("网络连接失败，请稍后重试。");
            }
            notifyError(i18n("网络连接失败，请稍后重试。"));
        }).always(function () {
            secretRequests = secretRequests.filter(function (request) {
                return request !== secretRequest;
            });
            form.dataset.loading = "false";
            if (document.documentElement.contains(form)) {
                setSecretBusy(form, false);
            }
        });
        secretRequests.push(secretRequest);
    }

    function loadFullDetails(button) {
        if (!button || button.dataset.loading === "true") {
            return;
        }
        var tradeNo = text(button.__stTradeNo);
        if (!tradeNo) {
            notifyError(i18n("订单号无效，无法读取完整信息。"));
            return;
        }

        var version = requestVersion;
        button.dataset.loading = "true";
        setDetailsBusy(button, true);
        var detailsRequest = $.ajax({
            type: "POST",
            url: "/user/api/index/secret",
            dataType: "json",
            data: {tradeNo: tradeNo, password: ""}
        }).done(function (response) {
            if (version !== requestVersion || !document.documentElement.contains(button)) {
                return;
            }
            if (!response || Number(response.code) !== 200) {
                notifyError(responseError(response, i18n("无法读取完整信息，请稍后重试。")));
                return;
            }
            var data = response.data || {};
            var content = button.closest(".st-query-content");
            if (content) {
                content.replaceWith(contentBlock(data.secret, data.leave_message, data.widget, tradeNo, false));
                notifySuccess(i18n("完整交付信息已更新"));
            }
        }).fail(function (_xhr, requestStatus) {
            if (requestStatus === "abort" || version !== requestVersion) {
                return;
            }
            notifyError(i18n("网络连接失败，暂时无法读取完整信息。"));
        }).always(function () {
            secretRequests = secretRequests.filter(function (request) {
                return request !== detailsRequest;
            });
            button.dataset.loading = "false";
            if (document.documentElement.contains(button)) {
                setDetailsBusy(button, false);
            }
        });
        secretRequests.push(detailsRequest);
    }

    function handleCopy(button) {
        if (!button || button.disabled) {
            return;
        }
        var value = text(button.__stCopyValue);
        if (!value) {
            notifyError(i18n("当前没有可复制的内容。"));
            return;
        }
        button.disabled = true;
        copyText(value).then(function () {
            if (!document.documentElement.contains(button)) {
                return;
            }
            var buttonIcon = button.querySelector(".material-icons-outlined");
            var buttonLabel = button.querySelector("[data-st-copy-label]");
            button.classList.add("is-copied");
            if (buttonIcon) {
                buttonIcon.textContent = "check";
            }
            if (buttonLabel) {
                buttonLabel.textContent = i18n("已复制");
            }
            notifySuccess(button.__stCopyLabel + i18n("成功"));
            var timer = window.setTimeout(function () {
                if (document.documentElement.contains(button)) {
                    button.classList.remove("is-copied");
                    button.disabled = false;
                    if (buttonIcon) {
                        buttonIcon.textContent = "content_copy";
                    }
                    if (buttonLabel) {
                        buttonLabel.textContent = i18n("复制内容");
                    }
                }
            }, 1600);
            copyTimers.push(timer);
        }).catch(function () {
            button.disabled = false;
            notifyError(i18n("复制失败，请长按或选中文本复制。"));
        });
    }

    function cleanup() {
        requestVersion += 1;
        if (activeRequest && typeof activeRequest.abort === "function") {
            activeRequest.abort();
        }
        activeRequest = null;
        abortSecretRequests();
        copyTimers.forEach(function (timer) {
            window.clearTimeout(timer);
        });
        copyTimers = [];
        $(document).off(".seattleQuery");
    }

    if (typeof window.__seattleQueryCleanup === "function") {
        window.__seattleQueryCleanup();
    }
    window.__seattleQueryCleanup = cleanup;

    $(document)
        .off("submit.seattleQuery", ".st-query-page .order-query-form")
        .on("submit.seattleQuery", ".st-query-page .order-query-form", function (event) {
            event.preventDefault();
            submitQuery();
        })
        .off("click.seattleQuery", "[data-st-query-retry]")
        .on("click.seattleQuery", "[data-st-query-retry]", submitQuery)
        .off("click.seattleQuery", "[data-st-query-more]")
        .on("click.seattleQuery", "[data-st-query-more]", loadMore)
        .off("input.seattleQuery", ".st-query-page [name=keywords]")
        .on("input.seattleQuery", ".st-query-page [name=keywords]", syncQueryInput)
        .off("click.seattleQuery", "[data-st-query-details]")
        .on("click.seattleQuery", "[data-st-query-details]", function () {
            loadFullDetails(this);
        })
        .off("click.seattleQuery", "[data-st-query-copy]")
        .on("click.seattleQuery", "[data-st-query-copy]", function () {
            handleCopy(this);
        })
        .off("submit.seattleQuery", ".st-query-page [data-st-secret-form]")
        .on("submit.seattleQuery", ".st-query-page [data-st-secret-form]", function (event) {
            event.preventDefault();
            submitSecret(this);
        })
        .off("pjax:beforeReplace.seattleQuery")
        .on("pjax:beforeReplace.seattleQuery", cleanup);

    try {
        var tradeNo = new URLSearchParams(window.location.search).get("tradeNo") || "";
        if (/^\d{18}$/.test(tradeNo) && queryValue()) {
            submitQuery();
        }
    } catch (error) {
        // The form remains available when URLSearchParams is unavailable.
    }
}(window.jQuery));
