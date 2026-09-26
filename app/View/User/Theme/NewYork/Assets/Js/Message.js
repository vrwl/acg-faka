/* NewYork 纽约 — 手机消息通知、底部收件箱与抽屉内阅读器 */
!function () {
    "use strict";

    var doc = document;
    var requestVersion = 0;
    var detailVersion = 0;
    var recentRows = [];
    var pendingRows = null;
    var recentSignature = "";
    var recentLoaded = false;
    var newestId;
    var newestTime = 0;
    var currentDetail = null;
    var currentDetailClosed = true;
    var lastTrigger = null;
    var lastMessageId = "";
    var messageOpen = false;

    function $q(selector, scope) {
        return (scope || doc).querySelector(selector);
    }

    function $qa(selector, scope) {
        return Array.prototype.slice.call((scope || doc).querySelectorAll(selector));
    }

    function sheet() {
        return $q('[data-ny-sheet="messages"]');
    }

    function signedIn() {
        return !!sheet() && !!doc.body && doc.body.getAttribute("data-ny-role") !== "guest";
    }

    function escapeHtml(value) {
        return String(value == null ? "" : value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function normalizeMessage(row) {
        row = row || {};
        var source = row.message || row.system_message || row;
        return {
            id: row.id || row.user_message_id || source.user_message_id || 0,
            message_id: row.message_id || source.id || 0,
            title: row.title || source.title || i18n("未命名消息"),
            summary: row.summary || source.summary || "",
            content: row.content || source.content || "",
            jump_url: row.jump_url || source.jump_url || row.url || source.url || row.link_url || source.link_url || "",
            create_time: row.create_time || source.create_time || "",
            update_time: row.update_time || source.update_time || "",
            read_time: row.read_time || null,
            became_read: row.became_read,
            unread_count: row.unread_count,
            onClose: typeof row.onClose === "function" ? row.onClose : null
        };
    }

    function signature(rows) {
        return JSON.stringify((rows || []).map(function (row) {
            var item = normalizeMessage(row);
            return [item.id, item.read_time || "", item.title, item.summary, item.create_time];
        }));
    }

    function relativeTime(value) {
        if (!value) return i18n("刚刚");
        var stamp = new Date(String(value).replace(/-/g, "/")).getTime();
        if (!Number.isFinite(stamp)) return String(value);
        var seconds = Math.max(0, Math.floor((Date.now() - stamp) / 1000));
        if (seconds < 60) return i18n("刚刚");
        if (seconds < 3600) return i18n("{n} 分钟前").replace("{n}", Math.floor(seconds / 60));
        if (seconds < 86400) return i18n("{n} 小时前").replace("{n}", Math.floor(seconds / 3600));
        if (seconds < 604800) return i18n("{n} 天前").replace("{n}", Math.floor(seconds / 86400));
        return String(value);
    }

    function showError(text) {
        if (typeof message !== "undefined" && message && typeof message.error === "function") {
            message.error(text);
            return;
        }
        var subtitle = $q("[data-ny-message-subtitle]", sheet());
        if (subtitle) subtitle.textContent = text;
    }

    function setUnreadCount(value) {
        var count = Math.max(0, parseInt(value, 10) || 0);
        $qa(".uc-message-badge").forEach(function (badge) {
            badge.textContent = count > 99 ? "99+" : String(count);
            badge.classList.toggle("is-empty", count < 1);
            badge.setAttribute("aria-hidden", count < 1 ? "true" : "false");
        });
        $qa(".uc-message-btn").forEach(function (button) {
            button.setAttribute("aria-label", count > 0 ? i18n("{n} 条未读消息").replace("{n}", count) : i18n("没有未读消息"));
            var icon = $q(".uc-message-btn__icon", button);
            if (icon) icon.textContent = count > 0 ? "notifications_active" : "notifications_none";
        });
        var countNode = $q("[data-ny-message-count]", sheet());
        if (countNode) {
            countNode.hidden = false;
            countNode.textContent = count > 0 ? i18n("{n} 条未读").replace("{n}", count) : i18n("暂无未读");
            countNode.classList.toggle("is-empty", count < 1);
        }
    }

    function skeletonHtml() {
        return '<div class="ny-message-skeletons" aria-label="' + i18n("正在加载消息") + '">' +
            '<div class="ny-message-skeleton"><i></i><span><b></b><small></small><em></em></span></div>' +
            '<div class="ny-message-skeleton"><i></i><span><b></b><small></small><em></em></span></div>' +
            '<div class="ny-message-skeleton"><i></i><span><b></b><small></small><em></em></span></div></div>';
    }

    function stateHtml(icon, title, copy, retry) {
        return '<div class="ny-message-state">' +
            '<span class="material-icons-outlined" aria-hidden="true">' + icon + '</span>' +
            '<strong>' + escapeHtml(title) + '</strong><small>' + escapeHtml(copy) + '</small>' +
            (retry ? '<button type="button" data-ny-message-retry><span class="material-icons-outlined">refresh</span>' + i18n("重新加载") + '</button>' : "") +
            '</div>';
    }

    function renderRecent(rows) {
        var container = $q("[data-ny-message-recent]", sheet());
        if (!container) return;
        if (!rows.length) {
            container.innerHTML = stateHtml("notifications_off", i18n("暂时没有消息"), i18n("新的通知会在这里出现"), false);
            container.setAttribute("aria-busy", "false");
            return;
        }
        container.innerHTML = rows.map(function (raw) {
            var item = normalizeMessage(raw);
            return '<button type="button" class="ny-message-item' + (item.read_time ? "" : " is-unread") + '" data-ny-message-id="' + encodeURIComponent(item.id) + '">' +
                '<span class="ny-message-item__icon"><i></i><span class="material-icons-outlined" aria-hidden="true">' + (item.read_time ? "drafts" : "mark_email_unread") + '</span></span>' +
                '<span class="ny-message-item__copy"><strong>' + escapeHtml(item.title) + '</strong><small>' + escapeHtml(item.summary || i18n("点击查看消息详情")) + '</small><time>' + escapeHtml(relativeTime(item.create_time)) + '</time></span>' +
                '<span class="material-icons-outlined ny-message-item__arrow" aria-hidden="true">chevron_right</span>' +
                '</button>';
        }).join("");
        container.setAttribute("aria-busy", "false");
    }

    function commitRecent(rows) {
        recentRows = rows.slice(0, 6);
        recentSignature = signature(recentRows);
        pendingRows = null;
        renderRecent(recentRows);
    }

    function ringForNewMessage(rows) {
        var newest = rows.length ? normalizeMessage(rows[0]) : null;
        var id = newest ? String(newest.message_id || newest.id || "") : "";
        var numeric = /^\d+$/.test(id) ? Number(id) : 0;
        var previousNumeric = /^\d+$/.test(String(newestId == null ? "" : newestId)) ? Number(newestId) : 0;
        var stamp = newest ? new Date(String(newest.create_time || "").replace(/-/g, "/")).getTime() || 0 : 0;
        var isNew = newestId !== undefined && id && (numeric && previousNumeric ? numeric > previousNumeric : stamp > newestTime);
        if (isNew) {
            $qa(".uc-message-btn").forEach(function (button) {
                button.classList.remove("is-ringing");
                void button.offsetWidth;
                button.classList.add("is-ringing");
                window.setTimeout(function () {
                    button.classList.remove("is-ringing");
                }, 900);
            });
        }
        if (newestId === undefined || numeric > previousNumeric || (!numeric && stamp > newestTime)) {
            newestId = id;
            newestTime = stamp;
        }
    }

    function refreshRecent(options) {
        if (!signedIn() || !window.jQuery) return;
        options = options || {};
        var container = $q("[data-ny-message-recent]", sheet());
        if (!recentLoaded && options.loading !== false && container) {
            container.innerHTML = skeletonHtml();
            container.setAttribute("aria-busy", "true");
        }
        var subtitle = $q("[data-ny-message-subtitle]", sheet());
        if (subtitle && !recentLoaded) subtitle.textContent = i18n("正在同步消息");
        var version = ++requestVersion;
        window.jQuery.ajax({
            type: "POST",
            url: "/user/api/message/recent",
            data: {},
            global: false,
            success: function (response) {
                if (version !== requestVersion) return;
                if (!response || response.code !== 200) {
                    if (!recentLoaded && container) container.innerHTML = stateHtml("cloud_off", i18n("消息加载失败"), (response && response.msg) || i18n("请稍后再试一次"), true);
                    if (container) container.setAttribute("aria-busy", "false");
                    if (subtitle) subtitle.textContent = i18n("同步失败");
                    return;
                }
                var payload = response.data || {};
                var rows = Array.isArray(payload.list) ? payload.list.slice(0, 6) : [];
                setUnreadCount(payload.count != null ? payload.count : payload.unread_count);
                ringForNewMessage(rows);
                if (signature(rows) !== recentSignature) {
                    var active = container && container.contains(doc.activeElement);
                    var currentSheet = sheet();
                    if (active && currentSheet && currentSheet.classList.contains("is-open") && !currentSheet.classList.contains("is-reading")) {
                        pendingRows = rows;
                    } else {
                        commitRecent(rows);
                    }
                }
                recentLoaded = true;
                if (subtitle) subtitle.textContent = rows.length ? i18n("最近 {n} 条消息").replace("{n}", rows.length) : i18n("收件箱已读完");
            },
            error: function () {
                if (version !== requestVersion) return;
                if (!recentLoaded && container) container.innerHTML = stateHtml("cloud_off", i18n("消息加载失败"), i18n("请检查网络后再试一次"), true);
                if (container) container.setAttribute("aria-busy", "false");
                if (subtitle) subtitle.textContent = i18n("同步失败");
            }
        });
    }

    function safeContent(value) {
        if (typeof component !== "undefined" && component && typeof component.sanitizeRichHtml === "function") {
            return component.sanitizeRichHtml(value || "");
        }
        return '<p>' + escapeHtml(value || i18n("暂无正文")) + '</p>';
    }

    function safeUrl(value) {
        if (typeof component !== "undefined" && component && typeof component.safeNavigationUrl === "function") {
            return component.safeNavigationUrl(value || "");
        }
        return "";
    }

    function finishCurrentDetail() {
        if (!currentDetail || currentDetailClosed) return;
        currentDetailClosed = true;
        var callback = currentDetail.onClose;
        if (typeof callback === "function") {
            try {
                callback();
            } catch (error) {
                console.error(error);
            }
        }
    }

    function showList(restoreItemFocus) {
        var currentSheet = sheet();
        if (!currentSheet) return;
        finishCurrentDetail();
        currentDetail = null;
        currentSheet.classList.remove("is-reading");
        var listView = $q("[data-ny-message-list-view]", currentSheet);
        var reader = $q("[data-ny-message-reader]", currentSheet);
        if (listView) listView.hidden = false;
        if (reader) reader.hidden = true;
        if (pendingRows) commitRecent(pendingRows);
        if (restoreItemFocus && lastMessageId) {
            window.requestAnimationFrame(function () {
                var item = $qa("[data-ny-message-id]", currentSheet).filter(function (candidate) {
                    return decodeURIComponent(candidate.getAttribute("data-ny-message-id") || "") === lastMessageId;
                })[0];
                if (item && typeof item.focus === "function") item.focus();
            });
        }
    }

    function ensureSheetOpen() {
        var currentSheet = sheet();
        if (!currentSheet) return;
        if (currentSheet.classList.contains("is-open")) {
            messageOpen = true;
            return;
        }
        var trigger = $q('.uc-message-btn[data-ny-sheet-open="messages"]');
        if (trigger) {
            lastTrigger = trigger;
            trigger.click();
            return;
        }
        $qa("[data-ny-sheet].is-open, .ny-drawer.is-open").forEach(function (node) {
            node.classList.remove("is-open");
            node.setAttribute("aria-hidden", "true");
        });
        currentSheet.classList.add("is-open");
        currentSheet.setAttribute("aria-hidden", "false");
        messageOpen = true;
        var backdrop = $q(".ny-sheet-backdrop");
        if (backdrop) backdrop.classList.add("is-open");
        if (doc.body) doc.body.classList.add("ny-sheet-open");
    }

    function presentDetail(raw) {
        var currentSheet = sheet();
        if (!currentSheet) return false;
        var detail = normalizeMessage(raw);
        if (!detail.id && !detail.message_id) return false;
        finishCurrentDetail();
        ensureSheetOpen();
        currentDetail = detail;
        currentDetailClosed = false;

        var title = $q("[data-ny-message-detail-title]", currentSheet);
        var time = $q("[data-ny-message-detail-time]", currentSheet);
        var content = $q("[data-ny-message-detail-content]", currentSheet);
        var link = $q("[data-ny-message-detail-link]", currentSheet);
        var footer = link ? link.closest(".ny-message-reader__footer") : null;
        if (title) title.textContent = detail.title;
        if (time) time.textContent = detail.create_time || detail.update_time || "";
        if (content) content.innerHTML = safeContent(detail.content);
        var url = safeUrl(detail.jump_url);
        if (link) {
            link.hidden = !url;
            if (footer) footer.hidden = !url;
            if (url) {
                link.href = url;
                try {
                    var parsed = new URL(url, window.location.origin);
                    link.target = parsed.origin === window.location.origin ? "_self" : "_blank";
                    link.rel = "noopener noreferrer";
                } catch (error) {
                    link.hidden = true;
                    if (footer) footer.hidden = true;
                }
            } else {
                link.removeAttribute("href");
            }
        }
        if (detail.unread_count != null) setUnreadCount(detail.unread_count);

        currentSheet.classList.add("is-reading");
        messageOpen = true;
        var listView = $q("[data-ny-message-list-view]", currentSheet);
        var reader = $q("[data-ny-message-reader]", currentSheet);
        if (listView) listView.hidden = true;
        if (reader) reader.hidden = false;
        var back = $q("[data-ny-message-back]", currentSheet);
        if (back) window.requestAnimationFrame(function () { back.focus(); });
        return true;
    }

    function markRecentRead(id, unreadCount) {
        var changed = false;
        recentRows = recentRows.map(function (raw) {
            var item = normalizeMessage(raw);
            if (String(item.id) === String(id) && !item.read_time) {
                item.read_time = new Date().toISOString();
                changed = true;
            }
            return item;
        });
        if (changed) commitRecent(recentRows);
        if (unreadCount != null) setUnreadCount(unreadCount);
    }

    function notifyChanged(options) {
        refreshRecent({loading: false});
        if (window.jQuery) window.jQuery(doc).trigger("uc:message-changed", [options || {}]);
    }

    function openDetail(id, trigger) {
        if (!id || !window.jQuery || !signedIn()) return;
        var button = trigger;
        if (button && button.classList.contains("is-loading")) return;
        if (button) {
            button.classList.add("is-loading");
            button.disabled = true;
        }
        lastMessageId = String(id);
        var version = ++detailVersion;
        window.jQuery.ajax({
            type: "POST",
            url: "/user/api/message/detail",
            data: {id: id},
            global: false,
            success: function (response) {
                if (version !== detailVersion) return;
                if (!response || response.code !== 200) {
                    showError((response && response.msg) || i18n("消息读取失败"));
                    return;
                }
                var detail = normalizeMessage(response.data && response.data.message ? response.data.message : response.data);
                markRecentRead(id, detail.unread_count);
                presentDetail(detail);
                notifyChanged();
            },
            error: function () {
                if (version === detailVersion) showError(i18n("消息读取失败，请稍后重试"));
            },
            complete: function () {
                if (button && doc.contains(button)) {
                    button.classList.remove("is-loading");
                    button.disabled = false;
                }
            }
        });
    }

    function closeAndReset(restoreFocus) {
        window.setTimeout(function () {
            showList(false);
            if (restoreFocus && lastTrigger && doc.contains(lastTrigger) && typeof lastTrigger.focus === "function") lastTrigger.focus();
        }, 40);
    }

    function bindSwipe(currentSheet) {
        if (!currentSheet) return;
        if (currentSheet.__nyMessageTouchStart) {
            currentSheet.removeEventListener("touchstart", currentSheet.__nyMessageTouchStart);
            currentSheet.removeEventListener("touchmove", currentSheet.__nyMessageTouchMove);
            currentSheet.removeEventListener("touchend", currentSheet.__nyMessageTouchEnd);
            currentSheet.removeEventListener("touchcancel", currentSheet.__nyMessageTouchEnd);
        }
        var drag = null;
        var start = function (event) {
            if (event.touches.length !== 1) return;
            var target = event.target;
            if (!target.closest(".ny-sheet-handle, .ny-message-sheet__head, .ny-message-reader__head") || target.closest("button, a")) return;
            drag = {y: event.touches[0].clientY, time: Date.now(), delta: 0};
        };
        var move = function (event) {
            if (!drag || event.touches.length !== 1) return;
            drag.delta = Math.max(0, event.touches[0].clientY - drag.y);
            if (!drag.delta) return;
            currentSheet.classList.add("is-dragging");
            currentSheet.style.setProperty("--ny-message-drag-y", drag.delta + "px");
            event.preventDefault();
        };
        var end = function () {
            if (!drag) return;
            var velocity = drag.delta / Math.max(1, Date.now() - drag.time);
            var dismiss = drag.delta > 80 || velocity > .55;
            drag = null;
            currentSheet.classList.remove("is-dragging");
            currentSheet.style.removeProperty("--ny-message-drag-y");
            if (dismiss) {
                var close = $q("[data-ny-sheet-close]", currentSheet);
                if (close) close.click();
            }
        };
        currentSheet.__nyMessageTouchStart = start;
        currentSheet.__nyMessageTouchMove = move;
        currentSheet.__nyMessageTouchEnd = end;
        currentSheet.addEventListener("touchstart", start, {passive: true});
        currentSheet.addEventListener("touchmove", move, {passive: false});
        currentSheet.addEventListener("touchend", end, {passive: true});
        currentSheet.addEventListener("touchcancel", end, {passive: true});
    }

    function init() {
        var currentSheet = sheet();
        window.ucMessageRefresh = refreshRecent;
        window.ucMessageNotifyChanged = notifyChanged;
        window.nyMessagePresentDetail = presentDetail;
        if (!currentSheet || !window.jQuery) return;

        var $document = window.jQuery(doc);
        $document.off(".nyMessageCenter")
            .on("click.nyMessageCenter", ".uc-message-btn[data-ny-sheet-open=messages]", function () {
                lastTrigger = this;
                messageOpen = true;
                showList(false);
                refreshRecent({loading: false});
            })
            .on("click.nyMessageCenter", "[data-ny-message-id]", function (event) {
                event.preventDefault();
                openDetail(decodeURIComponent(this.getAttribute("data-ny-message-id") || ""), this);
            })
            .on("click.nyMessageCenter", "[data-ny-message-retry]", function (event) {
                event.preventDefault();
                recentLoaded = false;
                refreshRecent({loading: true});
            })
            .on("click.nyMessageCenter", "[data-ny-message-back]", function (event) {
                event.preventDefault();
                showList(true);
            })
            .on("click.nyMessageCenter", "[data-ny-message-detail-content] img", function (event) {
                event.preventDefault();
                if (typeof component === "undefined" || !component || typeof component.previewImage !== "function") return;
                var src = typeof component.safeMessageImageUrl === "function" ? component.safeMessageImageUrl(this.getAttribute("src")) : "";
                if (src) component.previewImage(src);
            })
            .on("keydown.nyMessageCenter", "[data-ny-message-detail-content] img", function (event) {
                if (event.key !== "Enter" && event.key !== " ") return;
                event.preventDefault();
                this.click();
            })
            .on("click.nyMessageCenter", "[data-ny-message-detail-link], [data-ny-message-all]", function () {
                finishCurrentDetail();
            })
            .on("click.nyMessageCenter", "[data-ny-sheet-close], .ny-sheet-backdrop", function () {
                var closesMessage = currentSheet.contains(this) || (this.classList.contains("ny-sheet-backdrop") && messageOpen);
                if (!closesMessage) return;
                detailVersion++;
                messageOpen = false;
                if (currentDetail || currentSheet.classList.contains("is-reading")) {
                    closeAndReset(true);
                } else {
                    if (pendingRows) commitRecent(pendingRows);
                    window.setTimeout(function () {
                        if (lastTrigger && doc.contains(lastTrigger) && typeof lastTrigger.focus === "function") lastTrigger.focus();
                    }, 40);
                }
            })
            .on("click.nyMessageCenter", "[data-ny-sheet-open]:not([data-ny-sheet-open=messages])", function () {
                if (messageOpen) {
                    detailVersion++;
                    messageOpen = false;
                    if (currentDetail) closeAndReset(false);
                    else if (pendingRows) commitRecent(pendingRows);
                }
            })
            .on("pjax:send.nyMessageCenter pjax:popstate.nyMessageCenter", function () {
                var pageEpoch = Number(window.__ucMessagePageEpoch);
                if (Number.isFinite(pageEpoch)) window.__ucMessagePageEpoch = pageEpoch + 1;
                requestVersion++;
                detailVersion++;
                messageOpen = false;
                showList(false);
            })
            .on("pjax:complete.nyMessageCenter pjax:end.nyMessageCenter", function () {
                window.setTimeout(function () {
                    if (signedIn()) refreshRecent({loading: false});
                }, 100);
            });

        if (window.__nyMessageKeyHandler) doc.removeEventListener("keydown", window.__nyMessageKeyHandler, true);
        window.__nyMessageKeyHandler = function (event) {
            var activeSheet = sheet();
            if (event.key === "Escape" && activeSheet && activeSheet.classList.contains("is-open") && activeSheet.classList.contains("is-reading")) {
                event.preventDefault();
                event.stopImmediatePropagation();
                showList(true);
            } else if (event.key === "Escape" && activeSheet && activeSheet.classList.contains("is-open") && lastTrigger) {
                detailVersion++;
                messageOpen = false;
                window.setTimeout(function () {
                    if (doc.contains(lastTrigger) && typeof lastTrigger.focus === "function") lastTrigger.focus();
                }, 40);
            }
        };
        doc.addEventListener("keydown", window.__nyMessageKeyHandler, true);

        bindSwipe(currentSheet);

        if (window.__nyMessageTimer) window.clearInterval(window.__nyMessageTimer);
        if (signedIn()) {
            refreshRecent({loading: true});
            window.__nyMessageTimer = window.setInterval(function () {
                if (!doc.hidden) refreshRecent({loading: false});
            }, 60000);
        }

        if (window.__nyMessageVisibilityHandler) doc.removeEventListener("visibilitychange", window.__nyMessageVisibilityHandler);
        window.__nyMessageVisibilityHandler = function () {
            if (!doc.hidden && signedIn()) refreshRecent({loading: false});
        };
        doc.addEventListener("visibilitychange", window.__nyMessageVisibilityHandler);

        if (window.__nyMessagePageShowHandler) window.removeEventListener("pageshow", window.__nyMessagePageShowHandler);
        window.__nyMessagePageShowHandler = function () {
            if (signedIn()) refreshRecent({loading: false});
        };
        window.addEventListener("pageshow", window.__nyMessagePageShowHandler);
    }

    if (doc.readyState === "loading") doc.addEventListener("DOMContentLoaded", init, {once: true});
    else init();
}();
