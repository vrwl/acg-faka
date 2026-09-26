//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

(function ($) {
    "use strict";

    if (!$) return;

    var page = document.querySelector("[data-st-item-page]");
    if (!page || page.getAttribute("data-st-item-ready") === "true") return;

    var item = typeof getVar === "function" ? getVar("_var_item") : null;
    if (!item) return;

    page.setAttribute("data-st-item-ready", "true");

    var namespace = ".seattleItem";
    var $page = $(page);
    var $form = $page.find("[data-st-purchase-form]").first();
    var $checkout = $page.find(".st-item-checkout").first();
    var $price = $checkout.find(".abacus .price").first();
    var $stock = $page.find(".item-stock").first();
    var $paymentTrigger = $checkout.find("[data-st-payment-open]").first();
    var $payList = $checkout.find(".st-pay-list").first();
    var $paymentState = $checkout.find("[data-st-payment-state]").first();
    var $runtimeState = $checkout.find("[data-st-item-status]").first();
    var $quantity = $form.find('input[name="num"]').first();
    var $coupon = $form.find('input[name="coupon"]').first();
    var memberOnly = $checkout.find(".st-member-only").length > 0;
    var minimum = Math.max(1, parseInt(page.getAttribute("data-st-minimum"), 10) || 1);
    var configuredMaximum = parseInt(page.getAttribute("data-st-maximum"), 10) || 0;
    var maximum = configuredMaximum > 0 ? Math.max(minimum, configuredMaximum) : Infinity;

    var state = {
        sequence: {valuation: 0, stock: 0, payment: 0, order: 0},
        requests: {valuation: null, stock: null, payment: null, order: null},
        issues: {valuation: "", stock: ""},
        priceReady: false,
        stockReady: false,
        stockAvailable: false,
        paymentMode: memberOnly ? "member" : "loading",
        submitting: false,
        seckillBlocked: false,
        seckillEnded: false,
        snapTimer: null,
        customFieldTimer: null,
        cardTable: null,
        cardPickerOpen: false,
        cardPickerLayerIndex: null,
        imageViewer: null,
        imageViewerSource: null,
        imageViewerBodyOverflow: null
    };

    function currentPage() {
        return document.documentElement.contains(page)
            && document.querySelector("[data-st-item-page]") === page;
    }

    function isAppViewport() {
        if (window.SeattleTheme && typeof window.SeattleTheme.isAppViewport === "function") {
            return window.SeattleTheme.isAppViewport();
        }
        return window.innerWidth < 768 || (window.innerHeight <= 500 && window.innerWidth <= 1024);
    }

    function escapeHtml(value) {
        return String(value == null ? "" : value).replace(/[&<>"']/g, function (character) {
            return {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;"}[character];
        });
    }

    function plainText(value) {
        var source = String(value == null ? "" : value);
        if (typeof window.DOMParser !== "function") {
            return source.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
        }
        var parsed = new window.DOMParser().parseFromString(source, "text/html");
        return String(parsed.body ? parsed.body.textContent : "").replace(/\s+/g, " ").trim();
    }

    function formatAmount(value) {
        if (window.format && typeof window.format.amountRemoveTrailingZeros === "function") {
            return window.format.amountRemoveTrailingZeros(value);
        }
        var number = Number(value);
        if (!Number.isFinite(number)) return String(value == null ? "0" : value);
        return number.toFixed(2).replace(/\.00$/, "").replace(/(\.\d)0$/, "$1");
    }

    function notifyError(text) {
        if (window.SeattleTheme && typeof window.SeattleTheme.notify === "function") {
            window.SeattleTheme.notify(text, {type: "error"});
        }
        else if (window.message && typeof window.message.error === "function") message.error(text);
        else if (window.layer && typeof window.layer.msg === "function") layer.msg(text);
    }

    function notifySuccess(text) {
        if (window.SeattleTheme && typeof window.SeattleTheme.notify === "function") {
            window.SeattleTheme.notify(text, {type: "success"});
        }
        else if (window.message && typeof window.message.success === "function") message.success(text);
        else if (window.layer && typeof window.layer.msg === "function") layer.msg(text);
    }

    function responseData(response) {
        if (!response || Number(response.code) !== 200) {
            var error = new Error(response && response.msg ? String(response.msg) : i18n("服务器暂时无法处理请求"));
            error.response = response;
            throw error;
        }
        return response.data;
    }

    function abortRequest(name) {
        var request = state.requests[name];
        if (request && request.readyState !== 4) request.abort();
        state.requests[name] = null;
    }

    function setPriceLoading() {
        $price.empty().append('<span class="st-spinner" aria-label="' + i18n('正在计算') + '"></span>');
    }

    function setPriceValue(value) {
        if (!Number.isFinite(Number(value))) {
            throw new Error(i18n("价格数据无效，请重试"));
        }
        $price.empty()
            .append($("<span>").addClass("unit").text(acgCurrencySymbol()))
            .append(document.createTextNode(formatAmount(value)));
    }

    function setPriceUnavailable() {
        $price.text("—");
    }

    function setPaymentMessage(mode, text, retry) {
        state.paymentMode = mode;
        if (!$paymentState.length) return;

        $paymentState.prop("hidden", mode === "ready");
        $paymentState.attr("data-state", mode);
        $paymentState.find("[data-st-payment-state-text]").text(text || "");
        $paymentState.find(".st-spinner").prop("hidden", mode !== "loading");
        $paymentState.find("[data-st-payment-retry]").prop("hidden", !retry);
    }

    function renderRuntimeState() {
        if (!$runtimeState.length) return;

        var issue = state.issues.stock || state.issues.valuation;
        var text = "";
        var icon = "info";
        var retry = false;

        if (issue) {
            text = issue;
            icon = "error_outline";
            retry = true;
        } else if (state.seckillEnded) {
            text = i18n("抢购已经结束");
            icon = "event_busy";
        } else if (state.seckillBlocked) {
            text = i18n("抢购尚未开始，请稍后再来");
            icon = "schedule";
        } else if (state.stockReady && !state.stockAvailable) {
            text = i18n("当前选择已售罄，请尝试其他规格");
            icon = "inventory_2";
        }

        $runtimeState.prop("hidden", !text);
        $runtimeState.find("[data-st-item-status-icon]").text(icon);
        $runtimeState.find("[data-st-item-status-text]").text(text);
        $runtimeState.find("[data-st-item-retry]").prop("hidden", !retry);
    }

    function updateControls() {
        var quoteReady = state.priceReady && state.stockReady && state.stockAvailable && !state.seckillBlocked && !state.seckillEnded;
        var paymentCanOpen = state.paymentMode === "ready"
            || state.paymentMode === "error"
            || state.paymentMode === "empty";
        var disabled = !quoteReady || !paymentCanOpen || state.submitting;

        if ($paymentTrigger.length) {
            $paymentTrigger.prop("disabled", disabled);
            var label = i18n("选择支付方式");
            if (state.submitting) label = i18n("正在创建订单");
            else if (!state.priceReady || !state.stockReady) label = i18n("正在确认价格与库存");
            else if (!state.stockAvailable || state.seckillBlocked || state.seckillEnded) label = i18n("当前不可购买");
            else if (state.paymentMode === "loading") label = i18n("正在加载支付方式");
            else if (state.paymentMode === "error" || state.paymentMode === "empty") label = i18n("查看支付方式状态");
            $paymentTrigger.find("span").first().text(label);
        }

        $payList.find(".pay").prop("disabled", state.submitting || !quoteReady);
        $checkout.attr("aria-busy", state.submitting ? "true" : "false");
        renderRuntimeState();
    }

    function selectedButton(selector, attribute, value) {
        return $form.find(selector + ".is-primary").filter(function () {
            return String($(this).data(attribute)) === String(value);
        }).first();
    }

    function purchaseData() {
        var serialized = $form.serializeArray();
        var data = window.util && typeof util.arrayToObject === "function"
            ? util.arrayToObject(serialized)
            : serialized.reduce(function (result, field) {
                var name = String(field.name || "");
                if (!name) return result;
                if (/\[\]$/.test(name)) {
                    name = name.slice(0, -2);
                    if (!Array.isArray(result[name])) result[name] = [];
                    result[name].push(field.value);
                } else {
                    result[name] = field.value;
                }
                return result;
            }, {});
        data.item_id = item.id;

        if (item.config && item.config.category && Object.keys(item.config.category).length) {
            data.race = $form.find(".switch-race.is-primary").first().data("sku");
        }

        if (item.config && item.config.sku && Object.keys(item.config.sku).length) {
            data.sku = {};
            Object.keys(item.config.sku).forEach(function (name) {
                var $choice = selectedButton(".switch-sku", "sku", name);
                data.sku[name] = $choice.data("value");
            });
        }
        return data;
    }

    function syncQuantityControls(value) {
        var quantity = Number(value);
        var atMinimum = Number.isFinite(quantity) && quantity <= minimum;
        var atMaximum = Number.isFinite(quantity) && Number.isFinite(maximum) && quantity >= maximum;

        $form.find(".change-num-sub")
            .prop("disabled", atMinimum)
            .attr("aria-disabled", String(atMinimum));
        $form.find(".change-num-add")
            .prop("disabled", atMaximum)
            .attr("aria-disabled", String(atMaximum));
    }

    function normalizeQuantity(reportChange) {
        var original = Number($quantity.val());
        var value = Number.isFinite(original) ? Math.trunc(original) : minimum;
        value = Math.max(minimum, value);
        if (Number.isFinite(maximum)) value = Math.min(maximum, value);
        $quantity.val(value);
        syncQuantityControls(value);

        if (reportChange && value !== original) {
            var range = Number.isFinite(maximum) ? minimum + "–" + maximum : i18n("不少于 {n}").replace("{n}", minimum);
            notifyError(i18n("购买数量已调整为允许范围（") + range + "）");
        }
        return value;
    }

    function renderWholesale() {
        $form.find(".wholesale-table").remove();
        var rows = null;
        var config = item.config || {};

        if (config.category && Object.keys(config.category).length) {
            var race = String($form.find(".switch-race.is-primary").first().data("sku") || "");
            if (config.category_wholesale && config.category_wholesale[race]) rows = config.category_wholesale[race];
        } else if (config.wholesale && Object.keys(config.wholesale).length) {
            rows = config.wholesale;
        }

        if (!rows || !Object.keys(rows).length) return;

        var $table = $("<table>").addClass("table wholesale-table");
        var $head = $("<thead>").append($("<tr>")
            .append($("<th>").attr("scope", "col").text(i18n("批发数量")))
            .append($("<th>").attr("scope", "col").text(i18n("单价"))));
        var $body = $("<tbody>");
        Object.keys(rows).forEach(function (quantity) {
            $body.append($("<tr>")
                .append($("<td>").text(quantity))
                .append($("<td>").text(acgCurrencySymbol() + formatAmount(rows[quantity]))));
        });
        $table.append($head, $body);
        $form.find(".qty-group").first().after($table);
    }

    function loadValuation(options) {
        var settings = options || {};
        var sequence = ++state.sequence.valuation;
        abortRequest("valuation");
        state.priceReady = false;
        state.issues.valuation = "";
        setPriceLoading();
        updateControls();

        state.requests.valuation = $.ajax({
            type: "POST",
            url: "/user/api/index/valuation",
            data: purchaseData(),
            dataType: "json"
        }).done(function (response) {
            if (sequence !== state.sequence.valuation || !currentPage()) return;
            try {
                var data = responseData(response);
                setPriceValue(data.price);
                state.priceReady = true;
                state.issues.valuation = "";
            } catch (error) {
                if (settings.couponAttempt && $coupon.length && String($coupon.val()).trim()) {
                    $coupon.val("").trigger("input").trigger("change");
                }
                state.issues.valuation = error.message || i18n("价格计算失败，请重试");
                setPriceUnavailable();
                notifyError(state.issues.valuation);
            }
        }).fail(function (xhr, status) {
            if (status === "abort" || sequence !== state.sequence.valuation || !currentPage()) return;
            state.issues.valuation = i18n("价格计算失败，请检查网络后重试");
            setPriceUnavailable();
        }).always(function () {
            if (sequence !== state.sequence.valuation || !currentPage()) return;
            state.requests.valuation = null;
            updateControls();
        });
    }

    function loadStock() {
        var sequence = ++state.sequence.stock;
        abortRequest("stock");
        state.stockReady = false;
        state.stockAvailable = false;
        state.issues.stock = "";
        updateControls();

        state.requests.stock = $.ajax({
            type: "POST",
            url: "/user/api/index/stock",
            data: purchaseData(),
            dataType: "json"
        }).done(function (response) {
            if (sequence !== state.sequence.stock || !currentPage()) return;
            try {
                var data = responseData(response);
                state.stockReady = true;
                state.stockAvailable = Number(data.stock_state) > 0;
                state.issues.stock = "";
                if (state.stockAvailable) {
                    $stock.removeClass("badge-soft-danger").addClass("badge-soft-success").text(i18n("库存 {n}").replace("{n}", plainText(data.stock)));
                } else {
                    $stock.removeClass("badge-soft-success").addClass("badge-soft-danger").text(i18n("已售罄"));
                }
            } catch (error) {
                state.issues.stock = error.message || i18n("库存读取失败，请重试");
            }
        }).fail(function (xhr, status) {
            if (status === "abort" || sequence !== state.sequence.stock || !currentPage()) return;
            state.issues.stock = i18n("库存读取失败，请检查网络后重试");
        }).always(function () {
            if (sequence !== state.sequence.stock || !currentPage()) return;
            state.requests.stock = null;
            updateControls();
        });
    }

    function refreshQuoteAndStock() {
        normalizeQuantity(false);
        renderWholesale();
        loadValuation();
        loadStock();
    }

    function safeImageUrl(value) {
        var source = String(value || "").trim();
        if (!source || /[\\\u0000-\u001f\u007f]/.test(source)) return "";
        try {
            var parsed = new URL(source, window.location.origin + "/");
            if (parsed.protocol === "http:" || parsed.protocol === "https:") return parsed.href;
        } catch (error) {
            return "";
        }
        return "";
    }

    function renderPaymentOptions(items) {
        $payList.empty();
        (Array.isArray(items) ? items : []).filter(function (payment) {
            return Boolean(payment && payment.id !== null && payment.id !== undefined);
        }).forEach(function (payment) {
            var $button = $("<button>", {
                type: "button",
                "class": "pay st-pay-option",
                "data-id": String(payment.id),
                title: plainText(payment.name)
            });
            var icon = safeImageUrl(payment.icon);
            if (icon) $button.append($("<img>", {src: icon, alt: "", width: 28, height: 28}));
            else $button.append($("<span>").addClass("material-icons-outlined").attr("aria-hidden", "true").text("payments"));
            $button.append($("<span>").text(plainText(payment.name) || i18n("支付方式")));
            $payList.append($button);
        });
    }

    function loadPayments() {
        if (memberOnly || !$payList.length) return;
        var sequence = ++state.sequence.payment;
        abortRequest("payment");
        renderPaymentOptions([]);
        setPaymentMessage("loading", i18n("正在加载支付方式"), false);
        updateControls();

        state.requests.payment = $.ajax({
            type: "POST",
            url: "/user/api/index/pay?itemId=" + encodeURIComponent(item.id),
            dataType: "json"
        }).done(function (response) {
            if (sequence !== state.sequence.payment || !currentPage()) return;
            try {
                var payments = responseData(response);
                if (!Array.isArray(payments) || !payments.length) {
                    setPaymentMessage("empty", i18n("当前没有可用的支付方式"), true);
                    return;
                }
                renderPaymentOptions(payments);
                setPaymentMessage("ready", "", false);
            } catch (error) {
                setPaymentMessage("error", error.message || i18n("支付方式加载失败"), true);
            }
        }).fail(function (xhr, status) {
            if (status === "abort" || sequence !== state.sequence.payment || !currentPage()) return;
            setPaymentMessage("error", i18n("支付方式加载失败，请检查网络后重试"), true);
        }).always(function () {
            if (sequence !== state.sequence.payment || !currentPage()) return;
            state.requests.payment = null;
            updateControls();
        });
    }

    function refreshCaptcha() {
        var $image = $form.find(".captcha-img").first();
        if (!$image.length) return;
        $image.attr("src", "/user/captcha/image?action=trade&_t=" + Date.now());
        $form.find(".captcha-input").val("").trigger("input").trigger("change");
    }

    function purchaseFieldContainer(field) {
        if (!field) return null;
        if (typeof field.closest === "function") {
            return field.closest(".st-field") || field.parentElement;
        }
        return field.parentElement;
    }

    function purchaseFieldName(field) {
        var name = String(field && field.name || "");
        var container = purchaseFieldContainer(field);
        var label = "";
        var labelNode;

        if (name === "captcha") return i18n("图形验证码");
        if (container) {
            labelNode = container.querySelector(".st-field__label, .form-label, span");
            if (labelNode) label = plainText(labelNode.textContent);
        }
        if (!label && field) label = plainText(field.getAttribute("aria-label"));
        //placeholder 现在是译文，中文前缀剥不掉（英文下会得到 "Please enterQQ"）；
        //剥不动就说明这个来源不可用，交给下面的具名兜底
        if (!label && field) {
            var placeholder = plainText(field.getAttribute("placeholder"));
            var stripped = placeholder.replace(/^(?:请)?(?:输入|填写|选择)/, "");
            label = stripped === placeholder ? "" : stripped;
        }
        if (!label && name === "contact") label = i18n("联系方式");
        if (!label && name === "num") label = i18n("购买数量");
        return label || i18n("此项信息");
    }

    function purchaseValidationMessage(field) {
        var validity = field && field.validity;
        var label = purchaseFieldName(field);
        var selectable = field && (/^(?:SELECT)$/.test(field.tagName) || /^(?:checkbox|radio)$/.test(field.type));

        if (!validity) return i18n("请检查") + label;
        if (validity.valueMissing) return (selectable ? i18n("请选择") : i18n("请输入")) + label;
        if (validity.typeMismatch) return i18n("请输入正确的") + label;
        if (validity.patternMismatch) return label + i18n("格式不正确");
        if (validity.tooShort) return label + i18n("内容过短");
        if (validity.tooLong) return label + i18n("内容过长");
        if (validity.rangeUnderflow) return label + i18n("不能小于 {n}").replace("{n}", field.min);
        if (validity.rangeOverflow) return label + i18n("不能大于 {n}").replace("{n}", field.max);
        if (validity.stepMismatch) return i18n("请填写有效的") + label;
        if (validity.badInput) return i18n("请输入有效的") + label;
        return i18n("请检查") + label;
    }

    function purchaseErrorId(field) {
        var controls = $form[0] ? Array.prototype.slice.call($form[0].elements || []) : [];
        var index = Math.max(0, controls.indexOf(field));
        return "st-purchase-error-" + String(item.id).replace(/[^a-zA-Z0-9_-]/g, "-") + "-" + index;
    }

    function removeDescriptionToken(field, id) {
        var tokens = String(field.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean);
        tokens = tokens.filter(function (token) { return token !== id; });
        if (tokens.length) field.setAttribute("aria-describedby", tokens.join(" "));
        else field.removeAttribute("aria-describedby");
    }

    function clearPurchaseFieldError(field) {
        if (!field) return;
        var id = field.getAttribute("data-st-purchase-error") || purchaseErrorId(field);
        var container = purchaseFieldContainer(field);
        var error = document.getElementById(id);

        field.removeAttribute("aria-invalid");
        field.removeAttribute("data-st-purchase-error");
        removeDescriptionToken(field, id);
        if (error) error.remove();
        if (container && !container.querySelector('[aria-invalid="true"]')) container.classList.remove("is-invalid");
    }

    function clearPurchaseErrors() {
        $form.find("[data-st-purchase-error]").each(function () { clearPurchaseFieldError(this); });
        $form.find("[data-st-field-error]").remove();
    }

    function markPurchaseFieldError(field, message) {
        var container = purchaseFieldContainer(field);
        var id = purchaseErrorId(field);
        var describedBy = String(field.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean);
        var error = document.createElement("small");

        field.setAttribute("aria-invalid", "true");
        field.setAttribute("data-st-purchase-error", id);
        if (describedBy.indexOf(id) < 0) describedBy.push(id);
        field.setAttribute("aria-describedby", describedBy.join(" "));
        if (container) container.classList.add("is-invalid");

        error.id = id;
        error.className = "st-field-error";
        error.setAttribute("data-st-field-error", "");
        error.textContent = message;
        (container || field.parentElement).appendChild(error);
    }

    function firstInvalidPurchaseField() {
        var controls = $form[0] ? $form[0].elements : null;
        if (!controls) return null;
        for (var index = 0; index < controls.length; index += 1) {
            var control = controls[index];
            if (control && control.willValidate && control.validity && !control.validity.valid) return control;
        }
        return null;
    }

    function focusPurchaseField(field) {
        var reduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        try { field.focus({preventScroll: true}); }
        catch (error) { field.focus(); }
        window.requestAnimationFrame(function () {
            if (!currentPage() || !document.documentElement.contains(field)) return;
            try {
                field.scrollIntoView({behavior: reduced ? "auto" : "smooth", block: "center", inline: "nearest"});
            } catch (error) {
                field.scrollIntoView();
            }
        });
    }

    function validatePurchase() {
        clearPurchaseErrors();
        normalizeQuantity(true);

        if (item.config && item.config.category && Object.keys(item.config.category).length
            && !$form.find(".switch-race.is-primary").length) {
            notifyError(i18n("请选择商品类型"));
            return false;
        }
        if (item.config && item.config.sku && Object.keys(item.config.sku).some(function (name) {
            return !selectedButton(".switch-sku", "sku", name).length;
        })) {
            notifyError(i18n("请完整选择商品规格"));
            return false;
        }

        var invalid = firstInvalidPurchaseField();
        if (invalid) {
            var validationMessage = purchaseValidationMessage(invalid);
            markPurchaseFieldError(invalid, validationMessage);
            focusPurchaseField(invalid);
            notifyError(validationMessage);
            return false;
        }
        if (!state.priceReady || !state.stockReady) {
            notifyError(i18n("价格或库存仍在确认，请稍候"));
            return false;
        }
        if (!state.stockAvailable || state.seckillBlocked || state.seckillEnded) {
            notifyError(state.seckillEnded ? i18n("抢购已经结束") : (state.seckillBlocked ? i18n("抢购尚未开始") : i18n("当前选择已售罄")));
            return false;
        }
        if (state.paymentMode !== "ready") {
            notifyError(i18n("支付方式尚未准备完成"));
            return false;
        }
        return true;
    }

    function showBalanceResult(tradeNo, secret, leaveMessage) {
        var safeTradeNo = String(tradeNo == null ? "" : tradeNo).trim();
        if (!safeTradeNo) {
            notifyError(i18n("订单已创建，但订单号读取失败，请前往购买记录查看"));
            window.location.assign("/user/personal/purchaseRecord");
            return;
        }
        if (!window.layer) {
            window.location.assign("/user/personal/purchaseRecord?tradeNo=" + encodeURIComponent(safeTradeNo));
            return;
        }

        var mobile = isAppViewport();
        var secretValue = String(secret == null ? "" : secret);
        var resultOpener = document.activeElement;
        var $resultLayer = null;
        layer.open({
            type: 1,
            title: i18n("购买成功"),
            area: mobile ? "100%" : "520px",
            offset: mobile ? "b" : "auto",
            skin: "st-purchase-success-layer",
            maxmin: false,
            move: false,
            resize: false,
            shadeClose: false,
            content: '<div class="st-balance-result">' +
                '<div class="st-balance-result__status"><span class="material-icons-outlined" aria-hidden="true">check_circle</span><span><strong>' + i18n('订单已支付') + '</strong><small>' + i18n('卡密内容已生成，请立即保存') + '</small></span></div>' +
                '<section class="st-balance-result__secret" aria-labelledby="st-balance-secret-title">' +
                    '<header><strong id="st-balance-secret-title">' + i18n('卡密内容') + '</strong><button type="button" data-st-balance-copy><span class="material-icons-outlined" aria-hidden="true">content_copy</span><span>' + i18n('复制') + '</span></button></header>' +
                    '<pre class="st-balance-result__code" tabindex="0"></pre>' +
                '</section>' +
                '<section class="st-balance-result__secret st-balance-result__note" hidden>' +
                        '<header><strong>' + i18n('使用说明') + '</strong></header>' +
                        '<div class="st-balance-result__note-body"></div>' +
                    '</section>' +
                '<p class="st-balance-result__trade"><span>' + i18n('订单号') + '</span><strong></strong></p>' +
            '</div>',
            btn: [i18n("查看购买记录"), i18n("关闭")],
            success: function (layerObject, layerIndex) {
                var $layerObject = $(layerObject);
                var hasSecret = Boolean(secretValue.trim());
                var $copy = $layerObject.find("[data-st-balance-copy]");
                var $record = $layerObject.find(".layui-layer-btn0");
                var $dismiss = $layerObject.find(".layui-layer-btn1");
                var $close = $layerObject.find(".layui-layer-close");
                $resultLayer = $layerObject;
                $layerObject.attr({role: "dialog", "aria-modal": "true", "aria-label": i18n("购买成功")});
                $record.attr({role: "button", tabindex: "0", "aria-label": i18n("查看购买记录")});
                $dismiss.attr({role: "button", tabindex: "0", "aria-label": i18n("关闭购买成功提示")});
                $close.attr({role: "button", tabindex: "0", "aria-label": i18n("关闭购买成功提示"), title: i18n("关闭")});
                $layerObject.find(".st-balance-result__code")
                    .text(hasSecret ? secretValue : i18n("暂无可立即展示的交付内容，请前往购买记录查看订单状态。"))
                    .toggleClass("is-empty", !hasSecret);
                $layerObject.find(".st-balance-result__status small").text(hasSecret ? i18n("卡密内容已生成，请立即保存") : i18n("交付内容正在准备，请查看购买记录"));
                $layerObject.find("#st-balance-secret-title").text(hasSecret ? i18n("卡密内容") : i18n("交付状态"));
                $layerObject.find(".st-balance-result__trade strong").text(safeTradeNo);
                    //发货留言：商家富文本，与购买记录页/查询页一样原样渲染；没配就整块不显示
                    var noteHtml = leaveMessage == null ? "" : String(leaveMessage).trim();
                    if (noteHtml) {
                        $layerObject.find(".st-balance-result__note-body").html(noteHtml);
                        $layerObject.find(".st-balance-result__note").removeAttr("hidden");
                    }
                $copy.prop("disabled", !hasSecret).attr("aria-disabled", String(!hasSecret));
                $copy.on("click.seattleBalanceResult", function () {
                    if (!hasSecret) return;
                    if (typeof util !== "undefined" && util && typeof util.copyTextToClipboard === "function") {
                        util.copyTextToClipboard(secretValue, function () { notifySuccess(i18n("卡密已复制")); }, function () { notifyError(i18n("复制失败，请手动选择卡密")); });
                    } else {
                        notifyError(i18n("当前浏览器不支持自动复制，请手动选择卡密"));
                    }
                });
                $layerObject.on("keydown.seattleBalanceResult", function (event) {
                    var $keyTarget = $(event.target).closest("[role='button']");
                    if (event.key === "Escape") {
                        event.preventDefault();
                        event.stopPropagation();
                        layer.close(layerIndex);
                        return;
                    }
                    if ($keyTarget.length && (event.key === "Enter" || event.key === " ")) {
                        event.preventDefault();
                        $keyTarget.get(0).click();
                        return;
                    }
                    if (event.key !== "Tab") return;
                    var $focusable = $layerObject.find("button:not(:disabled), a[tabindex='0'], [tabindex]:not([tabindex='-1'])").filter(":visible");
                    if (!$focusable.length) return;
                    var first = $focusable.get(0);
                    var last = $focusable.get($focusable.length - 1);
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                });
                window.setTimeout(function () {
                    var target = hasSecret ? $copy.get(0) : $record.get(0);
                    if (target && document.contains(target)) target.focus({preventScroll: true});
                }, 40);
            },
            yes: function (index) {
                layer.close(index);
                window.location.assign("/user/personal/purchaseRecord?tradeNo=" + encodeURIComponent(safeTradeNo));
            },
            end: function () {
                if ($resultLayer) $resultLayer.off(".seattleBalanceResult");
                $resultLayer = null;
                if (resultOpener && document.contains(resultOpener) && typeof resultOpener.focus === "function") {
                    try { resultOpener.focus({preventScroll: true}); } catch (error) { resultOpener.focus(); }
                }
            }
        });
    }

    function safeNavigationUrl(value) {
        try {
            var url = new URL(String(value || ""), window.location.origin);
            return /^(https?:)$/.test(url.protocol) ? url.href : "";
        } catch (error) {
            return "";
        }
    }

    function submitOrder(paymentId) {
        if (state.submitting) return;

        var data = purchaseData();
        data.pay_id = paymentId;
        var sequence = ++state.sequence.order;
        state.submitting = true;
        setPaymentMessage("submitting", i18n("正在创建订单，请勿重复操作"), false);
        updateControls();

        state.requests.order = $.ajax({
            type: "POST",
            url: "/user/api/order/trade",
            data: data,
            dataType: "json"
        }).done(function (response) {
            if (sequence !== state.sequence.order || !currentPage()) return;
            try {
                var result = responseData(response) || {};
                if (Number(paymentId) === 1) {
                    state.submitting = false;
                    setPaymentMessage("ready", "", false);
                    showBalanceResult(result.tradeNo, result.secret, result.leave_message);
                    refreshQuoteAndStock();
                    return;
                }

                var target = safeNavigationUrl(result.url);
                if (!target) throw new Error(i18n("支付地址无效，请重新选择支付方式"));
                window.location.assign(target);
            } catch (error) {
                state.submitting = false;
                setPaymentMessage("ready", "", false);
                notifyError(error.message || i18n("订单创建失败，请重试"));
                refreshCaptcha();
                refreshQuoteAndStock();
            }
        }).fail(function (xhr, status) {
            if (status === "abort" || sequence !== state.sequence.order || !currentPage()) return;
            state.submitting = false;
            setPaymentMessage("ready", "", false);
            notifyError(i18n("订单创建失败，请检查网络后重试"));
            refreshCaptcha();
            refreshQuoteAndStock();
        }).always(function () {
            if (sequence !== state.sequence.order || !currentPage()) return;
            state.requests.order = null;
            updateControls();
        });
    }

    function applyCardSelection(selection, refresh) {
        var $input = $form.find('input[name="card_id"]').first();
        var $button = $form.find(".optional-card").first();
        $button.empty().append($("<span>").addClass("material-icons-outlined").attr("aria-hidden", "true").text("touch_app"));

        if (!selection) {
            $input.val("");
            $button.append($("<span>").text(i18n("未自选，将随机发货"))).removeAttr("title");
        } else {
            var draft = plainText(selection.draft) || i18n("已选择卡密");
            var premium = Number(selection.draft_premium) > 0 ? Number(selection.draft_premium) : Number(item.draft_premium || 0);
            $input.val(String(selection.id == null ? "" : selection.id));
            var label = premium > 0 ? i18n("加价") + " " + acgCurrencySymbol() + formatAmount(premium) + " · " + draft : draft;
            $button.append($("<span>").text(label)).attr("title", label);
        }
        if (refresh !== false) refreshQuoteAndStock();
    }

    function openCardPicker() {
        if (state.cardPickerOpen) return;
        if (!window.layer || typeof Table !== "function") {
            notifyError(i18n("自选卡密组件暂时不可用，请稍后重试"));
            return;
        }

        var tableId = "st-card-picker-" + Date.now();
        var viewportWidth = Math.max(320, window.innerWidth || 320);
        var mobile = isAppViewport();
        var area = mobile ? ["100%", "min(74dvh, 620px)"] : Math.min(680, viewportWidth - 48) + "px";
        var trigger = document.activeElement;
        var cardPickerResizeObserver = null;
        var cardPickerClosing = false;

        state.cardPickerOpen = true;
        layer.open({
            type: 1,
            title: i18n("自选卡密"),
            area: area,
            offset: mobile ? "b" : "auto",
            skin: "st-item-card-layer",
            maxmin: false,
            move: false,
            resize: false,
            shadeClose: true,
            content: '<div class="component-popup st-item-card-picker"><div class="mcy-card"><table id="' + tableId + '"></table></div></div>',
            btn: [i18n("确认选择"), i18n("取消")],
            success: function (layerElement, layerIndex) {
                var table = new Table("/user/api/index/card", $("#" + tableId));
                var $table = $("#" + tableId);
                var $layer = $(layerElement);
                var $confirm = $layer.find(".layui-layer-btn0");
                var recenterFrame = 0;
                var mobileCardRowsBound = false;
                var syncConfirm = function () {
                    var selected = table.getSelections();
                    var disabled = !selected || !selected.length;
                    $confirm.toggleClass("is-disabled", disabled).attr("aria-disabled", String(disabled));
                };
                var syncMobileCardRows = function () {
                    if (!mobile) return;
                    var $rows = $layer.find(".st-item-card-picker tbody tr[data-index]");
                    $layer.find(".st-item-card-picker tbody").attr({role: "radiogroup", "aria-label": i18n("可选卡密")});
                    $rows.each(function () {
                        var $row = $(this);
                        var $input = $row.find('input[name="btSelectItem"]');
                        var label = plainText($row.find(".st-card-picker-option-copy").text());
                        var checked = Boolean($input.prop("checked"));
                        $row
                            .toggleClass("is-selected", checked)
                            .attr({role: "radio", tabindex: "0", "aria-checked": String(checked), "aria-label": i18n("选择 {name}").replace("{name}", label || i18n("当前卡密"))});
                        $input.attr({tabindex: "-1", "aria-hidden": "true"});
                    });
                    if (mobileCardRowsBound) return;
                    mobileCardRowsBound = true;
                    $layer
                        .off("click.seattleCardPickerRows keydown.seattleCardPickerRows")
                        .on("click.seattleCardPickerRows", ".st-item-card-picker tbody tr[data-index]", function (event) {
                            if ($(event.target).closest("a, button, input, label").length) return;
                            var $input = $(this).find('input[name="btSelectItem"]');
                            if ($input.length && !$input.prop("checked")) $input.trigger("click");
                        })
                        .on("keydown.seattleCardPickerRows", ".st-item-card-picker tbody tr[data-index]", function (event) {
                            var $rows = $layer.find(".st-item-card-picker tbody tr[data-index]");
                            var index = $rows.index(this);
                            if (event.key === "ArrowDown" || event.key === "ArrowUp") {
                                event.preventDefault();
                                var next = event.key === "ArrowDown" ? Math.min(index + 1, $rows.length - 1) : Math.max(index - 1, 0);
                                $rows.eq(next).trigger("focus");
                                return;
                            }
                            if (event.key !== "Enter" && event.key !== " ") return;
                            event.preventDefault();
                            var $input = $(this).find('input[name="btSelectItem"]');
                            if ($input.length && !$input.prop("checked")) $input.trigger("click");
                        });
                };
                var recenterCardPicker = function () {
                    if (isAppViewport() !== mobile) {
                        if (!cardPickerClosing && state.cardPickerLayerIndex !== null) {
                            cardPickerClosing = true;
                            var cardPickerIndex = state.cardPickerLayerIndex;
                            state.cardPickerLayerIndex = null;
                            layer.close(cardPickerIndex);
                        }
                        return;
                    }
                    if (mobile) return;
                    if (recenterFrame) window.cancelAnimationFrame(recenterFrame);
                    recenterFrame = window.requestAnimationFrame(function () {
                        var element = $layer.get(0);
                        var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
                        if (!element || !document.documentElement.contains(element)) return;
                        var height = element.offsetHeight || element.getBoundingClientRect().height;
                        if (!height || !viewportHeight) return;
                        $layer.css({top: Math.max(12, Math.round((viewportHeight - height) / 2)) + "px", bottom: "auto"});
                        recenterFrame = 0;
                    });
                };
                state.cardPickerLayerIndex = layerIndex;
                state.cardTable = table;
                $confirm.addClass("is-disabled").attr("aria-disabled", "true");
                table.setPagination(10, [10, 20, 30]);
                table.setColumns([
                    mobile ? {radio: true, class: "st-card-picker-option-select"} : {checkbox: true},
                    {field: "draft", title: i18n("可选内容"), class: "st-card-picker-option-copy", formatter: function (value) {
                        var draft = plainText(value);
                        if (!mobile) return escapeHtml(draft);
                        var parts = draft.split(/\s*--\s*/);
                        var title = parts.shift() || i18n("可选卡密");
                        var detail = parts.join(" · ") || i18n("点击整行选择");
                        return '<span class="st-card-picker-option-text"><strong>' + escapeHtml(title) + "</strong><small>" + escapeHtml(detail) + "</small></span>";
                    }},
                    {field: "draft_premium", title: i18n("溢价"), class: "st-card-picker-option-price", formatter: function (value) {
                        var premium = Number(value) > 0 ? Number(value) : Number(item.draft_premium || 0);
                        if (!mobile) return premium > 0 ? '<span class="a-badge a-badge-primary">' + acgCurrencySymbol() + escapeHtml(formatAmount(premium)) + "</span>" : "-";
                        return premium > 0 ? '<span class="st-card-picker-premium">+' + acgCurrencySymbol() + escapeHtml(formatAmount(premium)) + "</span>" : '<span class="st-card-picker-premium is-none">' + i18n('无加价') + '</span>';
                    }}
                ]);
                var data = purchaseData();
                Object.keys(data).forEach(function (key) { table.setWhere(key, data[key]); });
                table.setSearch([{title: i18n("搜索可选内容"), name: "search-draft", type: "input", width: mobile ? 220 : 300}]);
                table.enableSingleSelect();
                $table.on("check.bs.table.seattleCardPicker uncheck.bs.table.seattleCardPicker check-all.bs.table.seattleCardPicker uncheck-all.bs.table.seattleCardPicker load-success.bs.table.seattleCardPicker post-body.bs.table.seattleCardPicker", function () {
                    syncConfirm();
                    syncMobileCardRows();
                    recenterCardPicker();
                });
                $(window).off("resize.seattleCardPicker").on("resize.seattleCardPicker", recenterCardPicker);
                if (!mobile && typeof window.ResizeObserver === "function") {
                    cardPickerResizeObserver = new window.ResizeObserver(recenterCardPicker);
                    if ($layer.get(0)) cardPickerResizeObserver.observe($layer.get(0));
                }
                table.render();
                window.setTimeout(function () {
                    syncConfirm();
                    syncMobileCardRows();
                    recenterCardPicker();
                }, 0);
                window.setTimeout(recenterCardPicker, 80);
            },
            yes: function (index) {
                var selections = state.cardTable ? state.cardTable.getSelections() : [];
                if (!selections.length) {
                    notifyError(i18n("请先选择一条可用卡密"));
                    return false;
                }
                applyCardSelection(selections[0]);
                layer.close(index);
            },
            end: function () {
                $(window).off("resize.seattleCardPicker");
                $(".st-item-card-layer").off("click.seattleCardPickerRows keydown.seattleCardPickerRows");
                if (cardPickerResizeObserver) cardPickerResizeObserver.disconnect();
                cardPickerResizeObserver = null;
                if (state.cardTable && typeof state.cardTable.destroy === "function") state.cardTable.destroy();
                state.cardTable = null;
                state.cardPickerOpen = false;
                state.cardPickerLayerIndex = null;
                if (trigger && document.documentElement.contains(trigger) && typeof trigger.focus === "function") trigger.focus({preventScroll: true});
            }
        });
    }

    function restoreImageViewerState() {
        if (state.imageViewerBodyOverflow !== null) {
            document.body.style.overflow = state.imageViewerBodyOverflow;
            state.imageViewerBodyOverflow = null;
        }
        var source = state.imageViewerSource;
        state.imageViewerSource = null;
        if (source && document.documentElement.contains(source)) {
            source.focus({preventScroll: true});
        }
    }

    function closeImageViewer() {
        var dialog = state.imageViewer;
        if (!dialog) return;
        if (dialog.open && typeof dialog.close === "function") {
            dialog.close();
        } else {
            dialog.removeAttribute("open");
            restoreImageViewerState();
        }
    }

    function createImageViewer() {
        if (state.imageViewer) return state.imageViewer;

        var dialog = document.createElement("dialog");
        dialog.className = "st-item-image-viewer";
        dialog.setAttribute("aria-label", i18n("商品说明图片预览"));

        var close = document.createElement("button");
        close.type = "button";
        close.className = "st-icon-button st-item-image-viewer-close";
        close.setAttribute("aria-label", i18n("关闭图片预览"));
        close.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">close</span>';

        var image = document.createElement("img");
        image.alt = i18n("商品说明图片预览");
        image.addEventListener("error", function () {
            closeImageViewer();
            notifyError(i18n("图片加载失败，请稍后重试"));
        });

        close.addEventListener("click", closeImageViewer);
        dialog.addEventListener("cancel", function (event) {
            event.preventDefault();
            closeImageViewer();
        });
        dialog.addEventListener("close", restoreImageViewerState);
        dialog.addEventListener("click", function (event) {
            if (event.target === dialog) closeImageViewer();
        });
        dialog.appendChild(close);
        dialog.appendChild(image);
        document.body.appendChild(dialog);
        state.imageViewer = dialog;
        return dialog;
    }

    function openImageViewer(source) {
        var url = safeImageUrl(source.currentSrc || source.getAttribute("src"));
        if (!url) {
            notifyError(i18n("图片地址无效，无法预览"));
            return;
        }
        var dialog = createImageViewer();
        var image = dialog.querySelector("img");
        image.src = url;
        image.alt = plainText(source.alt) || i18n("商品说明图片预览");
        state.imageViewerSource = source;
        if (state.imageViewerBodyOverflow === null) {
            state.imageViewerBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = "hidden";
        }
        if (typeof dialog.showModal === "function") {
            if (!dialog.open) dialog.showModal();
        } else {
            dialog.setAttribute("open", "open");
        }
        var close = dialog.querySelector("button");
        if (close) close.focus({preventScroll: true});
    }

    function enhanceDescriptionImages() {
        $page.find(".st-item-description .st-rich-content img").each(function () {
            this.loading = "lazy";
            this.setAttribute("role", "button");
            this.setAttribute("tabindex", "0");
            this.setAttribute("aria-label", plainText(this.alt) ? i18n("放大查看：") + plainText(this.alt) : i18n("放大查看商品说明图片"));
            this.title = i18n("点击放大查看");
        });
    }

    function clearCardForVariantChange() {
        if ($form.find('input[name="card_id"]').val()) applyCardSelection(null, false);
    }

    function updateSnapUp() {
        if (Number(item.seckill_status) !== 1) return false;
        var $badge = $page.find(".snap-up").first();
        var now = Date.now();
        var starts = new Date(item.seckill_start_time).getTime();
        var ends = new Date(item.seckill_end_time).getTime();
        var text = "";
        state.seckillBlocked = false;
        state.seckillEnded = false;

        if (Number.isFinite(starts) && starts > now) {
            text = window.format && typeof window.format.expireTime === "function" ? i18n("离抢购开始还剩") + (window.format.expireTime(item.seckill_start_time) || "") : i18n("抢购即将开始");
            state.seckillBlocked = true;
            $badge.removeClass("badge-soft-primary badge-soft-muted").addClass("badge-soft-info");
        } else if (Number.isFinite(ends) && ends > now) {
            text = window.format && typeof window.format.expireTime === "function" ? i18n("抢购结束还剩") + (window.format.expireTime(item.seckill_end_time) || "") : i18n("抢购进行中");
            $badge.removeClass("badge-soft-info badge-soft-muted").addClass("badge-soft-primary");
        } else {
            text = i18n("抢购已结束");
            state.seckillEnded = true;
            $badge.removeClass("badge-soft-info badge-soft-primary").addClass("badge-soft-muted");
        }
        $badge.text(text).show();
        updateControls();
        return !state.seckillEnded;
    }

    function bindEvents() {
        $form.off(namespace)
            .on("click" + namespace, ".switch-race, .switch-sku", function () {
                var $button = $(this);
                $button.closest(".st-choice-list").find(".is-primary").removeClass("is-primary").attr("aria-pressed", "false");
                $button.addClass("is-primary").attr("aria-pressed", "true");
                clearCardForVariantChange();
                refreshQuoteAndStock();
            })
            .on("click" + namespace, ".change-num-sub, .change-num-add", function () {
                var delta = $(this).hasClass("change-num-add") ? 1 : -1;
                $quantity.val((parseInt($quantity.val(), 10) || minimum) + delta);
                normalizeQuantity(false);
                refreshQuoteAndStock();
            })
            .on("change" + namespace, 'input[name="num"]', function () {
                normalizeQuantity(true);
                refreshQuoteAndStock();
            })
            .on("input" + namespace, 'input[name="num"]', function () {
                syncQuantityControls(this.value);
            })
            .on("input" + namespace + " change" + namespace, "[data-st-purchase-error]", function () {
                if (!this.willValidate || !this.validity || this.validity.valid) clearPurchaseFieldError(this);
            })
            .on("change" + namespace, 'input[name="coupon"]', function () {
                loadValuation({couponAttempt: true});
            })
            .on("change" + namespace, "input, select, textarea", function () {
                var name = String(this.name || "");
                if (["num", "coupon", "contact", "password", "captcha", "card_id"].indexOf(name) >= 0) return;
                window.clearTimeout(state.customFieldTimer);
                state.customFieldTimer = window.setTimeout(refreshQuoteAndStock, 180);
            })
            .on("click" + namespace, ".optional-card", openCardPicker)
            .on("click" + namespace, ".captcha-img", refreshCaptcha)
            .on("keydown" + namespace, ".captcha-img", function (event) {
                if (event.key === "Enter" || event.key === " ") {
                    event.preventDefault();
                    refreshCaptcha();
                }
            })
            .on("keydown" + namespace, ".captcha-input", function (event) {
                if (event.key === "Enter") {
                    event.preventDefault();
                    if (validatePurchase()) $paymentTrigger.trigger("click");
                }
            })
            .on("submit" + namespace, function (event) {
                event.preventDefault();
                if (!memberOnly && validatePurchase()) $paymentTrigger.trigger("click");
            });

        $page.find(".shared-button").off(namespace).on("click" + namespace, function () {
            var url = String(item.share_url || window.location.href);
            if (typeof util !== "undefined" && util && typeof util.copyTextToClipboard === "function") {
                util.copyTextToClipboard(url, function () {
                    notifySuccess(i18n("商品链接已复制"));
                }, function () {
                    notifyError(i18n("复制失败，请手动复制地址栏链接"));
                });
            } else if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    notifySuccess(i18n("商品链接已复制"));
                }).catch(function () {
                    notifyError(i18n("复制失败，请手动复制地址栏链接"));
                });
            } else {
                notifyError(i18n("复制失败，请手动复制地址栏链接"));
            }
        });

        $page.off("click" + namespace, ".st-item-description .st-rich-content img")
            .on("click" + namespace, ".st-item-description .st-rich-content img", function (event) {
                event.preventDefault();
                event.stopPropagation();
                openImageViewer(this);
            })
            .off("keydown" + namespace, ".st-item-description .st-rich-content img")
            .on("keydown" + namespace, ".st-item-description .st-rich-content img", function (event) {
                if (event.key === "Enter" || event.key === " ") {
                    event.preventDefault();
                    openImageViewer(this);
                }
            });

        $checkout.off(namespace)
            .on("click" + namespace, "[data-st-item-retry]", function () {
                refreshQuoteAndStock();
                if (state.paymentMode === "error" || state.paymentMode === "empty") loadPayments();
            })
            .on("click" + namespace, "[data-st-payment-retry]", loadPayments)
            .on("click" + namespace, ".st-pay-list .pay", function (event) {
                if ($(this).prop("disabled") || state.submitting) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }
                if (!validatePurchase()) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (isAppViewport()) $checkout.find("[data-st-payment-close]").first().trigger("click");
                    return;
                }
                submitOrder($(this).data("id"));
            });

        $(document).off("pjax:send" + namespace).on("pjax:send" + namespace, destroy)
            .off("keydown" + namespace)
            .on("keydown" + namespace, function (event) {
                if (event.key === "Escape" && state.imageViewer
                    && (state.imageViewer.open || state.imageViewer.hasAttribute("open"))) {
                    event.preventDefault();
                    closeImageViewer();
                }
            });
        $(window).off("pagehide" + namespace).on("pagehide" + namespace, function (event) {
            var original = event.originalEvent || event;
            if (!original.persisted) destroy();
        });
    }

    function destroy() {
        Object.keys(state.requests).forEach(abortRequest);
        window.clearInterval(state.snapTimer);
        window.clearTimeout(state.customFieldTimer);
        if (state.cardPickerLayerIndex !== null && window.layer && typeof window.layer.close === "function") {
            var cardPickerIndex = state.cardPickerLayerIndex;
            state.cardPickerLayerIndex = null;
            window.layer.close(cardPickerIndex);
        }
        $(window).off(".seattleCardPicker");
        $form.off(namespace);
        $checkout.off(namespace);
        $page.find(".shared-button").off(namespace);
        $page.off(namespace);
        $(document).off(namespace);
        $(window).off(namespace);
        if (state.imageViewer) {
            closeImageViewer();
            restoreImageViewerState();
            state.imageViewer.remove();
            state.imageViewer = null;
        }
    }

    function boot() {
        $checkout.show();
        if ($form[0]) $form[0].noValidate = true;
        $form.find(".switch-race.is-primary, .switch-sku.is-primary").attr("aria-pressed", "true");
        $form.find(".switch-race:not(.is-primary), .switch-sku:not(.is-primary)").attr("aria-pressed", "false");
        normalizeQuantity(false);
        renderWholesale();
        enhanceDescriptionImages();
        bindEvents();
        loadValuation();
        loadStock();
        loadPayments();

        if (Number(item.seckill_status) === 1) {
            updateSnapUp();
            state.snapTimer = window.setInterval(function () {
                if (!updateSnapUp()) {
                    window.clearInterval(state.snapTimer);
                    state.snapTimer = null;
                }
            }, 1000);
        }
        updateControls();
    }

    boot();
}(window.jQuery));
