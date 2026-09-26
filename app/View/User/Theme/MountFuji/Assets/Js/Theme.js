!function () {
    "use strict";

    const root = document.documentElement;
    const themeKey = root.getAttribute("data-theme-storage-key") || "mountfuji.theme.preference";
    const configuredTheme = root.getAttribute("data-theme-default-preference") || "auto";
    const themeMedia = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;
    const allowedThemes = ["auto", "light", "dark"];
    let businessCommodityLayer = null;
    let muiScanTimer = null;
    let muiObserver = null;
    let ticketBadgeTimer = null;
    let messageBadgeTimer = null;
    let messageRequestVersion = 0;
    let messageRecentSignature = "";
    let messagePendingRecent = null;
    let messageNewestId;
    let messageNewestTime = 0;

    function readTheme() {
        try {
            const value = window.localStorage.getItem(themeKey);
            return allowedThemes.includes(value) ? value : null;
        } catch (error) {
            return null;
        }
    }

    function writeTheme(value) {
        try {
            window.localStorage.setItem(themeKey, value);
        } catch (error) {
        }
    }

    function resolveTheme(preference) {
        if (preference === "light" || preference === "dark") {
            return preference;
        }
        return themeMedia && themeMedia.matches ? "dark" : "light";
    }

    function applyTheme(preference) {
        const safePreference = allowedThemes.includes(preference) ? preference : "auto";
        const resolved = resolveTheme(safePreference);

        root.setAttribute("data-theme-preference", safePreference);
        root.setAttribute("data-theme-mode", resolved);
        root.setAttribute("data-theme", resolved);
        root.style.colorScheme = resolved;

        document.querySelectorAll("[data-theme-toggle]").forEach(button => {
            const active = button.getAttribute("data-theme-toggle") === safePreference;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-pressed", active ? "true" : "false");
        });
    }

    function syncTheme() {
        const fallback = allowedThemes.includes(configuredTheme) ? configuredTheme : "auto";
        applyTheme(readTheme() || fallback);
    }

    function bindThemeButtons() {
        document.querySelectorAll("[data-theme-toggle]").forEach(button => {
            if (button.getAttribute("data-mf-bound") === "1") return;
            button.setAttribute("data-mf-bound", "1");
            button.addEventListener("click", () => {
                const preference = button.getAttribute("data-theme-toggle") || "auto";
                writeTheme(preference);
                applyTheme(preference);
            });
        });
    }

    function userMenuElements() {
        return {
            button: document.querySelector("[data-mf-user-menu-toggle]"),
            menu: document.querySelector("[data-mf-user-menu]")
        };
    }

    function closeUserMenu() {
        const state = userMenuElements();
        if (!state.button || !state.menu) return;
        state.button.classList.remove("is-open");
        state.menu.classList.remove("is-open");
        state.button.setAttribute("aria-expanded", "false");
        state.menu.setAttribute("aria-hidden", "true");
    }

    function toggleUserMenu() {
        const state = userMenuElements();
        if (!state.button || !state.menu) return;
        const open = !state.menu.classList.contains("is-open");
        if (open) closeMessageCenter();
        state.button.classList.toggle("is-open", open);
        state.menu.classList.toggle("is-open", open);
        state.button.setAttribute("aria-expanded", open ? "true" : "false");
        state.menu.setAttribute("aria-hidden", open ? "false" : "true");
    }

    function messageCenterElements() {
        return {
            center: document.querySelector(".mf-message-center"),
            button: document.querySelector("[data-mf-message-toggle]"),
            popover: document.querySelector("[data-mf-message-popover]")
        };
    }

    function commitRecentMessages(rows) {
        renderRecentMessages(rows);
        messageRecentSignature = recentMessageSignature(rows);
        messagePendingRecent = null;
    }

    function closeMessageCenter(restoreFocus) {
        const state = messageCenterElements();
        const wasOpen = !!(state.center && state.center.classList.contains("is-open"));
        if (state.center) state.center.classList.remove("is-open");
        if (state.button) {
            state.button.setAttribute("aria-expanded", "false");
            if (restoreFocus && wasOpen) state.button.focus();
        }
        if (state.popover) state.popover.setAttribute("aria-hidden", "true");
        if (messagePendingRecent) commitRecentMessages(messagePendingRecent);
    }

    function toggleMessageCenter() {
        const state = messageCenterElements();
        if (!state.center || !state.button || !state.popover) return;
        const open = !state.center.classList.contains("is-open");
        closeUserMenu();
        state.center.classList.toggle("is-open", open);
        state.button.setAttribute("aria-expanded", open ? "true" : "false");
        state.popover.setAttribute("aria-hidden", open ? "false" : "true");
        if (open) refreshMessageCenter();
    }

    function setDrawer(open) {
        document.body.classList.toggle("site-mobile", open);
        document.querySelectorAll("[data-mf-sidebar-toggle]").forEach(button => {
            button.setAttribute("aria-expanded", open ? "true" : "false");
        });
    }

    function bindShellInteractions() {
        if (root.getAttribute("data-mf-shell-bound") === "1") return;
        root.setAttribute("data-mf-shell-bound", "1");

        document.addEventListener("click", event => {
            const target = event.target;
            const userButton = target.closest("[data-mf-user-menu-toggle]");
            const userMenu = target.closest("[data-mf-user-menu]");
            const drawerButton = target.closest("[data-mf-sidebar-toggle]");
            const drawerClose = target.closest("[data-mf-sidebar-close]");
            const sidebarLink = target.closest(".mf-sidebar a");
            const messageButton = target.closest("[data-mf-message-toggle]");
            const messageItem = target.closest("[data-mf-message-id]");
            const messageRetry = target.closest("[data-mf-message-retry]");
            const messageAll = target.closest("[data-mf-message-all]");
            const messageCenter = target.closest(".mf-message-center");

            if (messageButton) {
                event.preventDefault();
                event.stopPropagation();
                toggleMessageCenter();
                return;
            }
            if (messageItem) {
                event.preventDefault();
                event.stopPropagation();
                openRecentMessage(decodeURIComponent(messageItem.getAttribute("data-mf-message-id") || ""), messageItem);
                return;
            }
            if (messageRetry) {
                event.preventDefault();
                event.stopPropagation();
                refreshMessageCenter();
                return;
            }
            if (messageAll) {
                closeMessageCenter();
                return;
            }
            if (!messageCenter) closeMessageCenter();

            if (userButton) {
                event.preventDefault();
                toggleUserMenu();
                return;
            }
            if (!userMenu) closeUserMenu();

            if (drawerButton) {
                event.preventDefault();
                closeUserMenu();
                closeMessageCenter();
                setDrawer(!document.body.classList.contains("site-mobile"));
                return;
            }
            if (drawerClose || (sidebarLink && window.innerWidth <= 1199)) {
                setDrawer(false);
            }
        });

        document.addEventListener("keydown", event => {
            if (event.key === "Escape") {
                closeUserMenu();
                closeMessageCenter(true);
                setDrawer(false);
            }
        });

        window.addEventListener("resize", () => {
            if (window.innerWidth > 1199) setDrawer(false);
        });
    }

    function refreshTicketBadge() {
        const badges = document.querySelectorAll(".mf-ticket-nav-badge");
        if (!badges.length || !window.jQuery) return;

        window.jQuery.ajax({
            type: "POST",
            url: "/user/api/ticket/badge",
            data: {},
            global: false,
            success: response => {
                if (!response || response.code !== 200) return;
                const count = Math.max(0, parseInt(response.data && response.data.count, 10) || 0);
                badges.forEach(badge => {
                    badge.textContent = count > 99 ? "99+" : String(count);
                    badge.classList.toggle("is-empty", count < 1);
                    badge.setAttribute("aria-label", count > 0 ? `${count} ${i18n('条未读工单消息')}` : i18n("没有未读工单消息"));
                });
            }
        });
    }

    function bindTicketBadge() {
        window.ucTicketRefreshBadge = refreshTicketBadge;
        refreshTicketBadge();
        if (root.getAttribute("data-mf-ticket-badge-bound") === "1") return;

        root.setAttribute("data-mf-ticket-badge-bound", "1");
        ticketBadgeTimer = window.setInterval(() => {
            if (!document.hidden) refreshTicketBadge();
        }, 60000);
        document.addEventListener("visibilitychange", () => {
            if (!document.hidden) refreshTicketBadge();
        });
    }

    function escapeMessage(value) {
        return String(value == null ? "" : value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function relativeMessageTime(value) {
        if (!value) return i18n("刚刚");
        const time = new Date(String(value).replace(/-/g, "/")).getTime();
        if (!Number.isFinite(time)) return escapeMessage(value);
        const seconds = Math.max(0, Math.floor((Date.now() - time) / 1000));
        if (seconds < 60) return i18n("刚刚");
        if (seconds < 3600) return i18n("{minutes} 分钟前").replace("{minutes}", Math.floor(seconds / 60));
        if (seconds < 86400) return i18n("{hours} 小时前").replace("{hours}", Math.floor(seconds / 3600));
        if (seconds < 604800) return i18n("{days} 天前").replace("{days}", Math.floor(seconds / 86400));
        return escapeMessage(value);
    }

    function normalizeMessage(row) {
        const safeRow = row || {};
        const source = safeRow.message || safeRow.system_message || safeRow;
        return {
            id: safeRow.id || safeRow.user_message_id || source.user_message_id || 0,
            messageId: safeRow.message_id || source.id || 0,
            title: safeRow.title || source.title || i18n("未命名消息"),
            summary: safeRow.summary || source.summary || "",
            content: safeRow.content || source.content || "",
            jumpUrl: safeRow.jump_url || source.jump_url || safeRow.url || source.url || safeRow.link_url || source.link_url || "",
            createTime: safeRow.create_time || source.create_time || "",
            updateTime: safeRow.update_time || source.update_time || "",
            readTime: safeRow.read_time || null,
            becameRead: safeRow.became_read,
            unreadCount: safeRow.unread_count
        };
    }

    function recentMessageSignature(rows) {
        return JSON.stringify((rows || []).map(raw => {
            const item = normalizeMessage(raw);
            return [item.id, item.readTime || "", item.title, item.summary, item.createTime];
        }));
    }

    function setMessageCount(value) {
        const count = Math.max(0, parseInt(value, 10) || 0);
        document.querySelectorAll(".mf-message-badge, .mf-message-nav-badge").forEach(badge => {
            badge.textContent = count > 99 ? "99+" : String(count);
            badge.classList.toggle("is-empty", count < 1);
            badge.setAttribute("aria-label", count > 0 ? i18n("{count} 条未读消息").replace("{count}", count) : i18n("没有未读消息"));
        });
        const button = document.querySelector("[data-mf-message-toggle]");
        if (button) {
            button.setAttribute("aria-label", count > 0 ? i18n("{count} 条未读消息").replace("{count}", count) : i18n("没有未读消息"));
            const icon = button.querySelector(".mf-message-button__icon");
            if (icon) icon.textContent = count > 0 ? "notifications_active" : "notifications_none";
        }
        const label = document.querySelector(".mf-message-popover__count");
        if (label) label.textContent = count > 0 ? i18n("{count} 条未读").replace("{count}", count) : i18n("暂无未读");
    }

    function renderRecentMessages(rows) {
        const container = document.querySelector(".mf-message-recent");
        if (!container) return;
        if (!rows.length) {
            container.innerHTML = '<div class="mf-message-recent__empty"><span class="material-icons-outlined" aria-hidden="true">notifications_none</span><strong>' + i18n("此刻很安静") + '</strong><small>' + i18n("新的店铺通知会出现在这里") + '</small></div>';
            return;
        }
        container.innerHTML = rows.map(raw => {
            const item = normalizeMessage(raw);
            return '<button type="button" class="mf-message-recent__item' + (item.readTime ? "" : " is-unread") + '" data-mf-message-id="' + encodeURIComponent(item.id) + '">' +
                '<span class="mf-message-recent__mark"><i></i><span class="material-icons-outlined" aria-hidden="true">' + (item.readTime ? "drafts" : "mark_email_unread") + '</span></span>' +
                '<span class="mf-message-recent__copy"><strong>' + escapeMessage(item.title) + '</strong><small>' + escapeMessage(item.summary || i18n("点击查看消息详情")) + '</small><time>' + relativeMessageTime(item.createTime) + '</time></span>' +
                '<span class="material-icons-outlined mf-message-recent__arrow" aria-hidden="true">chevron_right</span>' +
            '</button>';
        }).join("");
    }

    function renderMessageError() {
        const container = document.querySelector(".mf-message-recent");
        if (!container || container.querySelector(".mf-message-recent__item")) return;
        messageRecentSignature = "";
        container.innerHTML = '<button type="button" class="mf-message-recent__retry" data-mf-message-retry><span class="material-icons-outlined">cloud_off</span><span><strong>' + i18n("暂时无法读取消息") + '</strong><small>' + i18n("点击这里重新加载") + '</small></span></button>';
    }

    function refreshMessageCenter() {
        if (!window.jQuery || !document.querySelector(".mf-message-center")) return;
        const version = ++messageRequestVersion;
        window.jQuery.ajax({
            type: "POST",
            url: "/user/api/message/recent",
            data: {},
            global: false,
            success: response => {
                if (version !== messageRequestVersion) return;
                if (!response || response.code !== 200) {
                    renderMessageError();
                    return;
                }
                const payload = response.data || {};
                const rows = Array.isArray(payload.list)
                    ? payload.list.slice(0, 6)
                    : (Array.isArray(payload.recent) ? payload.recent.slice(0, 6) : []);
                const count = payload.count != null ? payload.count : payload.unread_count;
                setMessageCount(count);

                const signature = recentMessageSignature(rows);
                if (signature !== messageRecentSignature) {
                    const recent = document.querySelector(".mf-message-recent");
                    const center = document.querySelector(".mf-message-center");
                    const preserveFocus = !!(recent && center && center.classList.contains("is-open") && recent.contains(document.activeElement));
                    if (preserveFocus) messagePendingRecent = rows;
                    else commitRecentMessages(rows);
                }

                const newestItem = rows.length ? normalizeMessage(rows[0]) : null;
                const newest = newestItem ? String(newestItem.messageId || newestItem.id) : "";
                const newestNumber = /^\d+$/.test(newest) ? Number(newest) : 0;
                const previousNumber = /^\d+$/.test(String(messageNewestId || "")) ? Number(messageNewestId) : 0;
                const newestTime = newestItem ? new Date(String(newestItem.createTime || "").replace(/-/g, "/")).getTime() || 0 : 0;
                const hasNewMessage = messageNewestId !== undefined && newest &&
                    (newestNumber && previousNumber ? newestNumber > previousNumber : newestTime > messageNewestTime);
                if (hasNewMessage) {
                    const button = document.querySelector("[data-mf-message-toggle]");
                    if (button) {
                        button.classList.remove("is-ringing");
                        void button.offsetWidth;
                        button.classList.add("is-ringing");
                        window.setTimeout(() => button.classList.remove("is-ringing"), 900);
                    }
                }
                if (messageNewestId === undefined || newestNumber > previousNumber || (!newestNumber && newestTime > messageNewestTime)) {
                    messageNewestId = newest;
                    messageNewestTime = newestTime;
                }
            },
            error: () => {
                if (version === messageRequestVersion) renderMessageError();
            }
        });
    }

    function notifyMessageChanged(options) {
        refreshMessageCenter();
        if (window.jQuery) window.jQuery(document).trigger("uc:message-changed", [options || {}]);
    }

    function openRecentMessage(id, trigger) {
        if (!window.jQuery || !id) return;
        if (typeof component === "undefined" || typeof component.previewMessage !== "function") {
            if (typeof message !== "undefined") message.error(i18n("消息阅读组件尚未加载，请刷新页面后重试"));
            return;
        }
        const $trigger = window.jQuery(trigger);
        if ($trigger.hasClass("is-loading")) return;
        $trigger.addClass("is-loading").prop("disabled", true);
        window.jQuery.ajax({
            type: "POST",
            url: "/user/api/message/detail",
            data: {id: id},
            global: false,
            success: response => {
                if (!response || response.code !== 200) {
                    if (typeof message !== "undefined") message.error((response && response.msg) || i18n("消息读取失败"));
                    return;
                }
                const source = response.data && response.data.message ? response.data.message : response.data;
                const item = normalizeMessage(source);
                const detail = {
                    id: item.id,
                    message_id: item.messageId,
                    title: item.title,
                    summary: item.summary,
                    content: item.content,
                    jump_url: item.jumpUrl,
                    create_time: item.createTime,
                    update_time: item.updateTime,
                    read_time: item.readTime,
                    became_read: item.becameRead,
                    unread_count: item.unreadCount,
                    onClose: () => {
                        if (window.jQuery) window.jQuery(document).trigger("uc:message-changed");
                    }
                };
                closeMessageCenter(true);
                refreshMessageCenter();
                component.previewMessage(detail);
            },
            error: () => {
                if (typeof message !== "undefined") message.error(i18n("消息读取失败，请稍后重试"));
            },
            complete: () => $trigger.removeClass("is-loading").prop("disabled", false)
        });
    }

    function bindMessageCenter() {
        window.ucMessageRefresh = refreshMessageCenter;
        window.ucMessageNotifyChanged = notifyMessageChanged;
        refreshMessageCenter();
        if (root.getAttribute("data-mf-message-bound") === "1") return;
        root.setAttribute("data-mf-message-bound", "1");
        messageBadgeTimer = window.setInterval(() => {
            if (!document.hidden) refreshMessageCenter();
        }, 60000);
        document.addEventListener("visibilitychange", () => {
            if (!document.hidden) refreshMessageCenter();
        });
    }

    function renderLayuiForms() {
        if (!window.layui || typeof window.layui.use !== "function") return;
        try {
            window.layui.use("form", () => {
                if (!window.layui.form || typeof window.layui.form.render !== "function") return;
                window.layui.form.render("select");
                window.layui.form.render("radio");
                window.layui.form.render("checkbox");
            });
        } catch (error) {
        }
    }

    function muiFieldValue(item) {
        const selectInput = item.querySelector(".layui-select-title .layui-input");
        if (selectInput) return selectInput.value;
        const input = item.querySelector(".layui-input:not([type='hidden']), .layui-textarea");
        return input ? input.value : "";
    }

    function refreshMuiField(item) {
        if (!item) return;
        const value = muiFieldValue(item);
        item.classList.toggle("mui-filled", value !== null && String(value).trim() !== "");
    }

    function tagMuiFields(scope) {
        const rootScope = scope || document;
        rootScope.querySelectorAll(".component-popup .layui-form-pane .layui-form-item").forEach(item => {
            const label = item.querySelector(":scope > .layui-form-label");
            if (!label || !label.textContent.replace(/\s+/g, "")) return;

            const blocked = item.querySelector(".layui-form-switch, .layui-form-checkbox, .layui-form-radio, .image-render, .file-render, .layui-upload, .editor-wrapper, .w-e-text-container, .ace_editor, .treeCheckbox");
            if (blocked) {
                item.classList.remove("mui-float", "mui-focused", "mui-filled");
                item.classList.add("mf-control-stack");
                return;
            }

            const textField = item.querySelector(".layui-input:not([type='hidden']), .layui-textarea");
            if (!textField) return;
            item.classList.remove("mf-control-stack");
            item.classList.add("mui-float");
            refreshMuiField(item);
        });
    }

    function createSearchOutline(label) {
        const fieldset = document.createElement("fieldset");
        const legend = document.createElement("legend");
        const text = document.createElement("span");
        fieldset.className = "mf-search-outline";
        fieldset.setAttribute("aria-hidden", "true");
        text.textContent = label;
        legend.appendChild(text);
        fieldset.appendChild(legend);
        return fieldset;
    }

    function initSearchOutlines(scope) {
        const rootScope = scope || document;
        rootScope.querySelectorAll(".mf-panel .table-search .mui-sf").forEach(field => {
            if (field.querySelector(":scope > .mf-search-outline")) return;
            const label = field.querySelector(":scope > .mui-sf__label");
            if (!label || !label.textContent.replace(/\s+/g, "")) return;
            field.classList.add("mf-search-field");
            field.appendChild(createSearchOutline(label.textContent.trim()));
        });
    }

    function scanMuiComponents(scope) {
        tagMuiFields(scope);
        initSearchOutlines(scope);
    }

    function queueMuiScan() {
        if (muiScanTimer !== null) return;
        muiScanTimer = window.setTimeout(() => {
            muiScanTimer = null;
            scanMuiComponents(document);
        }, 40);
    }

    function bindMuiComponents() {
        if (root.getAttribute("data-mf-mui-bound") === "1") return;
        root.setAttribute("data-mf-mui-bound", "1");

        document.addEventListener("focusin", event => {
            const item = event.target.closest ? event.target.closest(".component-popup .layui-form-item.mui-float") : null;
            if (item) item.classList.add("mui-focused");
        });
        document.addEventListener("focusout", event => {
            const item = event.target.closest ? event.target.closest(".component-popup .layui-form-item.mui-float") : null;
            if (!item) return;
            item.classList.remove("mui-focused");
            refreshMuiField(item);
        });
        document.addEventListener("input", event => {
            const item = event.target.closest ? event.target.closest(".component-popup .layui-form-item.mui-float") : null;
            if (item) refreshMuiField(item);
        });
        document.addEventListener("change", event => {
            const item = event.target.closest ? event.target.closest(".component-popup .layui-form-item.mui-float") : null;
            if (item) refreshMuiField(item);
        });

        if (window.MutationObserver && document.body) {
            muiObserver = new MutationObserver(queueMuiScan);
            muiObserver.observe(document.body, {childList: true, subtree: true});
        }
    }

    function resetTable(selector, delay) {
        if (!window.jQuery || !window.jQuery.fn || typeof window.jQuery.fn.bootstrapTable !== "function") return;
        window.setTimeout(() => {
            const $table = window.jQuery(selector);
            if (!$table.length || !$table.data("bootstrap.table")) return;
            try { $table.bootstrapTable("resetView"); } catch (error) {}
        }, delay || 0);
    }

    function switchBusinessTab(shell, key) {
        if (!shell) return;
        shell.querySelectorAll("[data-mf-business-tab-trigger]").forEach(button => {
            const active = button.getAttribute("data-mf-business-tab-trigger") === key;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-selected", active ? "true" : "false");
        });
        shell.querySelectorAll("[data-mf-business-tab-panel]").forEach(panel => {
            const active = panel.getAttribute("data-mf-business-tab-panel") === key;
            panel.classList.toggle("is-active", active);
            panel.setAttribute("aria-hidden", active ? "false" : "true");
        });
        if (key === "product") {
            resetTable("#master_category", 60);
            resetTable("#master_commodity", 120);
        }
        renderLayuiForms();
    }

    function businessLayerApi() {
        if (window.layer && typeof window.layer.open === "function") return window.layer;
        if (window.layui && window.layui.layer && typeof window.layui.layer.open === "function") return window.layui.layer;
        return null;
    }

    function restoreCommoditySection(shell) {
        if (!shell) return;
        const anchor = shell.querySelector("[data-mf-business-commodity-anchor]");
        const section = document.querySelector("[data-mf-business-commodity-section]");
        if (!anchor || !section) return;
        if (anchor.nextElementSibling !== section) anchor.insertAdjacentElement("afterend", section);
        section.classList.remove("is-mf-business-popup-mounted");
        section.style.removeProperty("display");
    }

    function categoryName(button) {
        const row = button ? button.closest("tr") : null;
        // 第一列是分类图标，没有文字；取第一个有文字的单元格才是分类名。
        const cell = row ? Array.from(row.querySelectorAll("td")).find(td => td.textContent.trim() !== "") : null;
        return cell ? cell.textContent.replace(/\s+/g, " ").trim() : "";
    }

    function centerCommodityLayer(layero) {
        // layer 只在打开瞬间按当时的高度算一次 top；商品表格的数据比弹窗晚到，每次渲染完都要重新居中。
        if (!layero || !layero.length || !layero[0].isConnected) return;
        if (layero.find(".layui-layer-max").hasClass("layui-layer-maxmin")) return;
        const minButton = layero.find(".layui-layer-min");
        if (minButton.length && minButton.is(":hidden")) return;
        const $window = window.jQuery(window);
        layero.css({
            top: Math.max(0, ($window.height() - layero.outerHeight()) / 2),
            left: Math.max(0, ($window.width() - layero.outerWidth()) / 2)
        });
    }

    function openCommoditySection(shell, name, attempt) {
        const api = businessLayerApi();
        const section = document.querySelector("[data-mf-business-commodity-section]");
        if (!api || !section || !shell || !window.jQuery) return;

        if (section.closest(".layui-layer")) {
            // 上一个弹窗还没解包（layer 关闭动画 200ms 后才把内容还回来），先关掉它，等归位后再开。
            if (businessCommodityLayer !== null && typeof api.close === "function") api.close(businessCommodityLayer);
            businessCommodityLayer = null;
            if ((attempt || 0) < 3) window.setTimeout(() => openCommoditySection(shell, name, (attempt || 0) + 1), 260);
            return;
        }

        // layer 对 type:1 的 DOM 内容是原地 .wrap() 包裹，弹窗节点会留在 .mf-panel 里面；
        // 面板的 backdrop-filter 会让它成为 position:fixed 的包含块，overflow:hidden 再把弹窗裁掉，
        // 于是弹窗贴着面板右下角出现。打开前先把 section 挂到 body 下，弹窗才真正相对视口居中；
        // end 回调再把它送回锚点。
        document.body.appendChild(section);
        section.classList.add("is-mf-business-popup-mounted");
        section.style.display = "block";

        const mobile = window.innerWidth <= 720;
        const width = Math.min(980, Math.max(720, window.innerWidth - 40));
        const $table = window.jQuery("#master_commodity");
        const recenterEvent = "post-body.bs.table.mfBusinessPopup";
        businessCommodityLayer = api.open({
            type: 1,
            skin: "mf-business-popup-layer",
            title: name ? i18n("主站商品 · {name}").replace("{name}", function () { return name; }) : i18n("主站商品"),
            shadeClose: true,
            maxmin: !mobile,
            // 高度留空让 layer 跟随内容自适应；数组里写 "auto" 会被当成固定值，把内容区冻结在打开瞬间的高度。
            area: mobile ? ["100%", "100%"] : [width + "px", ""],
            content: window.jQuery(section),
            success: layero => {
                $table.off(recenterEvent).on(recenterEvent, () => centerCommodityLayer(layero));
                resetTable("#master_commodity", 100);
            },
            full: () => resetTable("#master_commodity", 100),
            restore: () => resetTable("#master_commodity", 100),
            end: () => {
                $table.off(recenterEvent);
                businessCommodityLayer = null;
                restoreCommoditySection(shell);
                resetTable("#master_commodity", 80);
            }
        });
    }

    function findCommodityButton(target, shell) {
        if (!target || !shell || typeof target.closest !== "function") return null;
        const button = target.closest(".a-badge-glass");
        const table = button ? button.closest("#master_category") : null;
        if (!button || !table || !shell.contains(table)) return null;
        // 认 data-btn-title 的原文键,不能比对可见文字——按钮标题会被 i18n() 翻译掉。
        return button.getAttribute("data-btn-title") === "查看商品" ? button : null;
    }

    function initBusiness() {
        document.querySelectorAll(".mf-page-shell-business").forEach(shell => {
            if (shell.getAttribute("data-mf-business-bound") !== "1") {
                shell.setAttribute("data-mf-business-bound", "1");
                shell.addEventListener("click", event => {
                    const tab = event.target.closest("[data-mf-business-tab-trigger]");
                    if (tab) {
                        switchBusinessTab(shell, tab.getAttribute("data-mf-business-tab-trigger") || "basic");
                        return;
                    }
                });
                shell.addEventListener("click", event => {
                    const button = findCommodityButton(event.target, shell);
                    if (!button) return;
                    window.setTimeout(() => openCommoditySection(shell, categoryName(button)), 220);
                }, true);
            }
            const selected = shell.querySelector("[data-mf-business-tab-trigger].is-active");
            switchBusinessTab(shell, selected ? selected.getAttribute("data-mf-business-tab-trigger") : "basic");
        });
    }

    function initSettlement() {
        document.querySelectorAll(".mf-page-shell-personal").forEach(shell => {
            if (shell.getAttribute("data-mf-settlement-bound") === "1") return;
            const input = shell.querySelector("[data-mf-settlement-input]");
            const options = Array.from(shell.querySelectorAll("[data-mf-settlement]"));
            if (!input || !options.length) return;

            const sync = value => options.forEach(option => {
                option.classList.toggle("checked", option.getAttribute("data-mf-settlement") === String(value));
            });

            shell.setAttribute("data-mf-settlement-bound", "1");
            sync(input.value);
            shell.addEventListener("click", event => {
                const option = event.target.closest("[data-mf-settlement]");
                if (!option || !shell.contains(option)) return;
                input.value = option.getAttribute("data-mf-settlement") || "";
                sync(input.value);
            });
        });
    }

    function initCredential() {
        document.querySelectorAll(".mf-page-shell-personal").forEach(shell => {
            if (shell.getAttribute("data-mf-credential-bound") === "1") return;
            if (!shell.querySelector("[data-mf-copy]")) return;
            shell.setAttribute("data-mf-credential-bound", "1");
            shell.addEventListener("click", event => {
                const button = event.target.closest("[data-mf-copy]");
                if (!button || !shell.contains(button)) return;
                // 值取同一行的 code 节点，不缓存：重置密钥后 personal.js 会改写 .app-key 的文本，这里读到的始终是最新值。
                const row = button.closest(".mf-credential");
                const value = row ? row.querySelector("code") : null;
                const text = value ? value.textContent.trim() : "";
                if (!text || typeof util === "undefined" || typeof util.copyTextToClipboard !== "function") return;
                util.copyTextToClipboard(
                    text,
                    () => { if (typeof message !== "undefined") message.success(i18n("已复制")); },
                    () => { if (typeof message !== "undefined") message.error(i18n("复制失败，请重试")); }
                );
            });
        });
    }

    function hideLoading() {
        if (window.Loading && typeof window.Loading.hide === "function") window.Loading.hide();
        if (window.jQuery) window.jQuery(".net-loading").hide();
    }

    function syncPageContext() {
        const context = document.querySelector(".mf-topbar-context");
        const match = String(document.title || "").match(/^(.+?)\s+-\s+/);
        if (context && match && match[1]) context.textContent = match[1].trim();
    }

    function initPage() {
        syncTheme();
        bindThemeButtons();
        bindMuiComponents();
        initBusiness();
        initSettlement();
        initCredential();
        bindTicketBadge();
        bindMessageCenter();
        renderLayuiForms();
        scanMuiComponents(document);
        syncPageContext();
        closeUserMenu();
        setDrawer(false);
        hideLoading();
    }

    bindShellInteractions();
    bindThemeButtons();

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initPage);
    } else {
        initPage();
    }

    if (window.jQuery) {
        const $document = window.jQuery(document);
        $document.on("pjax:send", () => {
            closeUserMenu();
            closeMessageCenter();
            setDrawer(false);
        });
        $document.on("pjax:complete pjax:end pjax:error pjax:timeout", () => {
            window.setTimeout(initPage, 80);
        });
    }

    if (themeMedia) {
        const followSystem = () => {
            const preference = readTheme() || (allowedThemes.includes(configuredTheme) ? configuredTheme : "auto");
            if (preference === "auto") applyTheme("auto");
        };
        if (typeof themeMedia.addEventListener === "function") themeMedia.addEventListener("change", followSystem);
        else if (typeof themeMedia.addListener === "function") themeMedia.addListener(followSystem);
    }

    window.addEventListener("pageshow", hideLoading);
    window.addEventListener("load", hideLoading);
}();
