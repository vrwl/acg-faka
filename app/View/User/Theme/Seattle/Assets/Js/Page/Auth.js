(function () {
    "use strict";

    var submitPaths = [
        "/user/api/authentication/login",
        "/user/api/authentication/register",
        "/user/api/authentication/password"
    ];
    var captchaSelectors = {
        "/user/api/authentication/phoneRegisterCaptcha": ".send-phone-captcha",
        "/user/api/authentication/emailRegisterCaptcha": ".send-email-code",
        "/user/api/authentication/emailForgetCaptcha": ".send-email-captcha",
        "/user/api/authentication/phoneForgetCaptcha": ".send-phone-captcha"
    };
    var activeForm = null;
    var restoreTimer = null;
    var jqueryTimer = null;
    var promptService = null;
    var originalPrompt = null;
    var wrappedPrompt = null;

    function pathOf(url) {
        try {
            var parsed = new URL(String(url || ""), window.location.href);
            return parsed.origin === window.location.origin ? parsed.pathname : "";
        } catch (error) {
            return "";
        }
    }

    function messageApi() {
        try {
            if (typeof message !== "undefined" && message) {
                return message;
            }
        } catch (error) {
            // The shared lexical service may not have been initialized yet.
        }
        return window.message || null;
    }

    function withPopupClass(customClass, className) {
        var classes = Object.assign({}, customClass || {});
        var current = classes.popup;
        var names = Array.isArray(current)
            ? current.slice()
            : String(current || "").split(/\s+/);
        names = names.map(function (name) {
            return String(name || "").trim();
        }).filter(Boolean);
        if (names.indexOf(className) === -1) {
            names.push(className);
        }
        classes.popup = names.join(" ");
        return classes;
    }

    function installCaptchaPromptAdapter() {
        var api = messageApi();
        if (!api || typeof api.prompt !== "function") {
            return false;
        }
        if (api.prompt.__seattleAuthPrompt) {
            promptService = api;
            wrappedPrompt = api.prompt;
            originalPrompt = api.prompt.__seattleOriginal || null;
            return true;
        }

        promptService = api;
        originalPrompt = api.prompt;
        wrappedPrompt = function (options) {
            var settings = Object.assign({}, options || {});
            var html = String(settings.html || "");
            var body = document.body;
            var isAuthCaptcha = body
                && body.classList.contains("st-auth-body")
                && html.indexOf("prompt-image-code") !== -1;

            if (isAuthCaptcha) {
                settings.inputLabel = settings.inputLabel || i18n("图形验证码");
                settings.inputPlaceholder = settings.inputPlaceholder || i18n("输入图中字符");
                settings.inputAttributes = Object.assign({
                    autocomplete: "off",
                    autocapitalize: "off",
                    spellcheck: "false"
                }, settings.inputAttributes || {});
                settings.customClass = withPopupClass(settings.customClass, "st-auth-captcha-prompt");
            }
            return originalPrompt.call(this, settings);
        };
        wrappedPrompt.__seattleAuthPrompt = true;
        wrappedPrompt.__seattleOriginal = originalPrompt;
        api.prompt = wrappedPrompt;
        return true;
    }

    function sanitizeGoto() {
        var current;
        try {
            current = new URL(window.location.href);
        } catch (error) {
            return;
        }

        if (!current.searchParams.has("goto")) {
            return;
        }
        var target = current.searchParams.get("goto") || "/";
        var safe = target.charAt(0) === "/"
            && target.charAt(1) !== "/"
            && target.indexOf("\\") === -1
            && !/[\u0000-\u001f\u007f]/.test(target);
        current.searchParams.set("goto", safe ? target : "/");
        window.history.replaceState(window.history.state, "", current.pathname + current.search + current.hash);
    }

    function notify(text) {
        var api = messageApi();
        if (api && typeof api.error === "function") {
            api.error(text);
        }
    }

    function formError(form) {
        return form ? form.querySelector("[data-st-auth-error]") : null;
    }

    function clearError(form) {
        if (!form) {
            return;
        }
        form.querySelectorAll(".st-auth-field-invalid").forEach(function (field) {
            field.classList.remove("st-auth-field-invalid");
            field.removeAttribute("aria-invalid");
        });
        var error = formError(form);
        if (error) {
            error.textContent = "";
            error.hidden = true;
        }
    }

    function setError(form, input, message) {
        clearError(form);
        if (input) {
            input.classList.add("st-auth-field-invalid");
            input.setAttribute("aria-invalid", "true");
        }
        var error = formError(form);
        if (error) {
            error.textContent = message;
            error.hidden = false;
        }
        notify(message);
        if (input) {
            input.focus({preventScroll: true});
            input.scrollIntoView({behavior: "smooth", block: "center"});
        }
    }

    function fieldMessage(input) {
        var value = String(input.value || "").trim();
        var label = input.closest(".st-field");
        var labelText = label && label.querySelector(":scope > span, :scope > label")
            ? label.querySelector(":scope > span, :scope > label").textContent.trim()
            : i18n("此项");

        if (input.required && !value) {
            return i18n("请填写") + labelText + "。";
        }
        if (value && input.type === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            return i18n("请输入有效的邮箱地址。");
        }
        if (value && input.type === "tel" && !/^1[3-9][0-9]{9}$/.test(value)) {
            return i18n("请输入有效的手机号。");
        }
        if (value && input.type === "password" && value.length < 6) {
            return i18n("密码至少需要 6 位。");
        }
        return "";
    }

    function validateForm(form) {
        var inputs = form.querySelectorAll("input:not([type=checkbox]):not([type=hidden])");
        for (var index = 0; index < inputs.length; index += 1) {
            var message = fieldMessage(inputs[index]);
            if (message) {
                setError(form, inputs[index], message);
                return false;
            }
        }
        clearError(form);
        return true;
    }

    function submitButton(form) {
        return form ? form.querySelector(".st-auth-submit") : null;
    }

    function setSubmitBusy(form, busy) {
        var button = submitButton(form);
        if (!button) {
            return;
        }
        var label = button.querySelector("[data-st-auth-button-label]");
        var spinner = button.querySelector("[data-st-auth-button-spinner]");
        if (label && !label.dataset.defaultLabel) {
            label.dataset.defaultLabel = label.textContent;
        }
        button.disabled = Boolean(busy);
        button.classList.toggle("is-loading", Boolean(busy));
        button.setAttribute("aria-busy", busy ? "true" : "false");
        if (label) {
            label.textContent = busy ? i18n("正在处理") : label.dataset.defaultLabel;
        }
        if (spinner) {
            spinner.hidden = !busy;
        }
        form.dataset.stSubmitting = busy ? "true" : "false";
    }

    function restoreSubmit(form) {
        if (restoreTimer) {
            window.clearTimeout(restoreTimer);
            restoreTimer = null;
        }
        setSubmitBusy(form, false);
        if (activeForm === form) {
            activeForm = null;
        }
    }

    function submitCapture(event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches("[data-st-auth]")) {
            return;
        }
        if (form.dataset.stSubmitting === "true") {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        if (!validateForm(form)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        activeForm = form;
        setSubmitBusy(form, true);
        restoreTimer = window.setTimeout(function () {
            if (document.documentElement.contains(form)) {
                restoreSubmit(form);
                setError(form, null, i18n("请求等待时间过长，请重试。"));
            }
        }, 30000);
    }

    function fieldForCaptcha(button) {
        var form = button.closest("[data-st-auth]");
        if (!form) {
            return null;
        }
        if (button.classList.contains("send-email-code")) {
            return form.querySelector('input[name="email"]');
        }
        if (button.classList.contains("send-email-captcha")) {
            return form.querySelector('input[name="username"][type="email"]');
        }
        return form.querySelector('input[type="tel"]');
    }

    function captchaCapture(event) {
        var target = event.target;
        var button = target && target.closest
            ? target.closest(".send-email-code, .send-email-captcha, .send-phone-captcha")
            : null;
        if (!button || !button.closest("[data-st-auth]")) {
            return;
        }
        var form = button.closest("[data-st-auth]");
        var input = fieldForCaptcha(button);
        var message = input ? fieldMessage(input) : i18n("请先填写接收验证码的账号。");
        if (!input || message) {
            event.preventDefault();
            event.stopImmediatePropagation();
            setError(form, input, message || i18n("请先填写接收验证码的账号。"));
            return;
        }
        window.setTimeout(function () {
            var image = document.querySelector(".swal2-popup .prompt-image-code");
            if (!image) return;
            image.removeAttribute("onclick");
            image.setAttribute("role", "button");
            image.setAttribute("tabindex", "0");
            image.setAttribute("aria-label", i18n("更换图形验证码"));
        }, 0);
    }

    function syncPasswordToggle(button) {
        var wrapper = button.closest(".st-input-with-action");
        var input = wrapper ? wrapper.querySelector("input") : null;
        if (!input) {
            return;
        }
        var visible = input.type === "text";
        var label = visible ? i18n("隐藏密码") : i18n("显示密码");
        button.setAttribute("aria-pressed", visible ? "true" : "false");
        button.setAttribute("aria-label", label);
        button.title = label;
        var icon = button.querySelector(".material-icons-outlined");
        if (icon) {
            icon.textContent = visible ? "visibility_off" : "visibility";
        }
    }

    function documentClickCapture(event) {
        var target = event.target;
        if (!target || !target.closest) return;
        var promptImage = target.closest(".prompt-image-code");
        if (promptImage) {
            event.preventDefault();
            event.stopImmediatePropagation();
            refreshPromptCaptcha(promptImage);
            return;
        }
        captchaCapture(event);
        var captchaImage = target.closest("[data-st-captcha-action]");
        if (captchaImage) {
            var image = captchaImage.querySelector("img");
            var action = captchaImage.getAttribute("data-st-captcha-action");
            if (image && action) image.src = "/user/captcha/image?action=" + encodeURIComponent(action) + "&t=" + Date.now();
        }
        var toggle = target.closest(".st-password-toggle");
        if (toggle) {
            window.setTimeout(function () {
                syncPasswordToggle(toggle);
            }, 0);
        }
    }

    function refreshPromptCaptcha(image) {
        if (!image) return;
        try {
            var url = new URL(image.src, window.location.href);
            var action = url.searchParams.get("action") || "";
            if (action) image.src = "/user/captcha/image?action=" + encodeURIComponent(action) + "&t=" + Date.now();
        } catch (error) {
            // Keep the current CAPTCHA visible when its URL cannot be parsed.
        }
    }

    function documentKeydownCapture(event) {
        var image = event.target.closest ? event.target.closest(".prompt-image-code[role=button]") : null;
        if (!image || (event.key !== "Enter" && event.key !== " ")) return;
        event.preventDefault();
        refreshPromptCaptcha(image);
    }

    function inputCapture(event) {
        var form = event.target.closest ? event.target.closest("[data-st-auth]") : null;
        if (!form) {
            return;
        }
        event.target.classList.remove("st-auth-field-invalid");
        event.target.removeAttribute("aria-invalid");
        var error = formError(form);
        if (error) {
            error.textContent = "";
            error.hidden = true;
        }
    }

    function responsePayload(xhr) {
        if (xhr && xhr.responseJSON) {
            return xhr.responseJSON;
        }
        try {
            return JSON.parse(xhr.responseText || "{}");
        } catch (error) {
            return {};
        }
    }

    function requestSucceeded(xhr) {
        var payload = responsePayload(xhr);
        return xhr && xhr.status >= 200 && xhr.status < 300 && Number(payload.code) === 200;
    }

    function showRequestError(form, xhr) {
        var payload = responsePayload(xhr);
        var networkFailure = !xhr || xhr.status === 0;
        var message = networkFailure
            ? i18n("网络连接失败，请检查网络后重试。")
            : (payload && payload.msg ? String(payload.msg) : i18n("请求失败，请稍后重试。"));
        var error = formError(form);
        if (error) {
            error.textContent = message;
            error.hidden = false;
        }
        if (networkFailure) {
            notify(message);
        }
    }

    function setCaptchaBusy(button, busy) {
        if (!button) {
            return;
        }
        if (!button.dataset.stDefaultHtml) {
            button.dataset.stDefaultHtml = button.innerHTML;
        }
        button.classList.toggle("is-loading", Boolean(busy));
        button.setAttribute("aria-busy", busy ? "true" : "false");
        button.disabled = Boolean(busy);
        if (busy) {
            button.textContent = i18n("正在发送…");
        }
    }

    function bindAjax($) {
        $(document)
            .off("ajaxSend.seattleAuth ajaxComplete.seattleAuth")
            .on("ajaxSend.seattleAuth", function (_event, _xhr, settings) {
                var path = pathOf(settings.url);
                var selector = captchaSelectors[path];
                if (selector) {
                    setCaptchaBusy(document.querySelector("[data-st-auth] " + selector), true);
                }
            })
            .on("ajaxComplete.seattleAuth", function (_event, xhr, settings) {
                var path = pathOf(settings.url);
                if (submitPaths.indexOf(path) !== -1 && activeForm) {
                    if (!requestSucceeded(xhr)) {
                        var failedForm = activeForm;
                        restoreSubmit(failedForm);
                        showRequestError(failedForm, xhr);
                    } else if (restoreTimer) {
                        window.clearTimeout(restoreTimer);
                        restoreTimer = null;
                    }
                }

                var selector = captchaSelectors[path];
                if (!selector) {
                    return;
                }
                var button = document.querySelector("[data-st-auth] " + selector);
                if (!button) {
                    return;
                }
                button.classList.remove("is-loading");
                button.setAttribute("aria-busy", "false");
                if (!requestSucceeded(xhr)) {
                    button.disabled = false;
                    button.innerHTML = button.dataset.stDefaultHtml || i18n("获取验证码");
                    if (!xhr || xhr.status === 0) {
                        notify(i18n("网络连接失败，请检查网络后重试。"));
                    }
                }
            });
    }

    function waitForServices() {
        var ajaxBound = false;
        var promptBound = installCaptchaPromptAdapter();
        if (window.jQuery) {
            bindAjax(window.jQuery);
            ajaxBound = true;
        }
        if (ajaxBound && promptBound) {
            return;
        }
        var attempts = 0;
        jqueryTimer = window.setInterval(function () {
            attempts += 1;
            if (!ajaxBound && window.jQuery) {
                bindAjax(window.jQuery);
                ajaxBound = true;
            }
            if (!promptBound) {
                promptBound = installCaptchaPromptAdapter();
            }
            if ((ajaxBound && promptBound) || attempts >= 200) {
                window.clearInterval(jqueryTimer);
                jqueryTimer = null;
            }
        }, 25);
    }

    function cleanup() {
        document.removeEventListener("submit", submitCapture, true);
        document.removeEventListener("click", documentClickCapture, true);
        document.removeEventListener("input", inputCapture, true);
        document.removeEventListener("keydown", documentKeydownCapture, true);
        if (jqueryTimer) {
            window.clearInterval(jqueryTimer);
            jqueryTimer = null;
        }
        if (restoreTimer) {
            window.clearTimeout(restoreTimer);
            restoreTimer = null;
        }
        if (window.jQuery) {
            window.jQuery(document).off(".seattleAuth");
        }
        if (promptService && wrappedPrompt && originalPrompt && promptService.prompt === wrappedPrompt) {
            promptService.prompt = originalPrompt;
        }
        promptService = null;
        originalPrompt = null;
        wrappedPrompt = null;
    }

    if (typeof window.__seattleAuthCleanup === "function") {
        window.__seattleAuthCleanup();
    }
    window.__seattleAuthCleanup = cleanup;

    sanitizeGoto();
    document.addEventListener("submit", submitCapture, true);
    document.addEventListener("click", documentClickCapture, true);
    document.addEventListener("input", inputCapture, true);
    document.addEventListener("keydown", documentKeydownCapture, true);
    document.querySelectorAll(".st-password-toggle").forEach(syncPasswordToggle);
    waitForServices();
})();
