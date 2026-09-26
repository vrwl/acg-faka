//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

!function () {
    "use strict";

    var currentDestroy = null;
    var currentPage = null;
    var currentResume = null;
    var badgeTimer = null;
    var toastTimer = null;

    function q(selector, scope) { return (scope || document).querySelector(selector); }
    function qa(selector, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(selector)); }
    function num(value, fallback) { var parsed = Number(value); return Number.isFinite(parsed) ? parsed : fallback; }
    function escapeHtml(value) {
        return String(value == null ? "" : value)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }
    function prettyTime(value) {
        if (!value) return i18n("刚刚");
        var time = new Date(String(value).replace(/-/g, "/")).getTime();
        if (!Number.isFinite(time)) return String(value);
        var seconds = Math.max(0, Math.floor((Date.now() - time) / 1000));
        if (seconds < 60) return i18n("刚刚");
        if (seconds < 3600) return i18n("{n} 分钟前").replace("{n}", Math.floor(seconds / 60));
        if (seconds < 86400) return i18n("{n} 小时前").replace("{n}", Math.floor(seconds / 3600));
        if (seconds < 604800) return i18n("{n} 天前").replace("{n}", Math.floor(seconds / 86400));
        return String(value).slice(0, 10);
    }
    function icon(name) { return '<span class="material-icons-outlined">' + name + "</span>"; }

    function request(url, data) {
        return new Promise(function (resolve, reject) {
            if (!window.jQuery) {
                reject(new Error(i18n("页面组件尚未加载")));
                return;
            }
            window.jQuery.ajax({type: "POST", url: url, data: data || {}, dataType: "json", global: false})
                .done(function (response) {
                    if (response && Number(response.code) === 200) resolve(response.data || {});
                    else reject(new Error(response && response.msg ? response.msg : i18n("请求失败，请稍后再试")));
                })
                .fail(function () { reject(new Error(i18n("网络连接失败，请稍后再试"))); });
        });
    }

    function uploadImage(file, progress) {
        return new Promise(function (resolve, reject) {
            if (!file) { reject(new Error(i18n("请选择图片"))); return; }
            if (!/^image\/(jpeg|png|webp)$/i.test(file.type || "")) { reject(new Error(i18n("仅支持 JPG、PNG、WebP 图片"))); return; }
            if (file.size > 10 * 1024 * 1024) { reject(new Error(i18n("图片大小不能超过 10 MB"))); return; }
            var form = new FormData();
            form.append("file", file);
            window.jQuery.ajax({
                type: "POST",
                url: "/user/api/ticket/upload",
                data: form,
                dataType: "json",
                processData: false,
                contentType: false,
                global: false,
                xhr: function () {
                    var xhr = window.jQuery.ajaxSettings.xhr();
                    if (xhr.upload && typeof progress === "function") {
                        xhr.upload.addEventListener("progress", function (event) {
                            if (event.lengthComputable) progress(Math.round(event.loaded / event.total * 100));
                        });
                    }
                    return xhr;
                }
            }).done(function (response) {
                if (response && Number(response.code) === 200 && response.data && response.data.url) resolve(response.data);
                else reject(new Error(response && response.msg ? response.msg : i18n("图片上传失败")));
            }).fail(function () { reject(new Error(i18n("图片上传失败，请检查网络"))); });
        });
    }

    function toast(text, type) {
        var node = q(".ny-ticket-toast");
        if (!node) {
            node = document.createElement("div");
            node.className = "ny-ticket-toast";
            node.setAttribute("role", "status");
            (q(".ny-device-shell") || document.body).appendChild(node);
        }
        window.clearTimeout(toastTimer);
        node.className = "ny-ticket-toast is-visible" + (type ? " is-" + type : "");
        node.textContent = text;
        toastTimer = window.setTimeout(function () { node.classList.remove("is-visible"); }, 2600);
    }

    function sanitizeHtml(html) {
        var template = document.createElement("template");
        template.innerHTML = String(html == null ? "" : html);
        var allowed = new Set(["P", "BR", "STRONG", "B", "EM", "I", "U", "S", "DEL", "BLOCKQUOTE", "UL", "OL", "LI", "H1", "H2", "H3", "H4", "H5", "H6", "HR", "PRE", "CODE", "A", "IMG", "TABLE", "THEAD", "TBODY", "TR", "TH", "TD"]);
        var dangerous = new Set(["SCRIPT", "STYLE", "IFRAME", "OBJECT", "EMBED", "SVG", "MATH", "FORM", "INPUT", "BUTTON"]);
        function cleanUrl(value) {
            try { var url = new URL(value, window.location.origin); return ["http:", "https:"].indexOf(url.protocol) !== -1 ? value : ""; }
            catch (error) { return ""; }
        }
        function walk(node) {
            Array.prototype.slice.call(node.childNodes).forEach(function (child) {
                if (child.nodeType === Node.COMMENT_NODE) { child.remove(); return; }
                if (child.nodeType !== Node.ELEMENT_NODE) return;
                var tag = child.tagName;
                if (!allowed.has(tag)) {
                    if (dangerous.has(tag)) child.remove();
                    else { walk(child); child.replaceWith.apply(child, Array.prototype.slice.call(child.childNodes)); }
                    return;
                }
                Array.prototype.slice.call(child.attributes).forEach(function (attribute) {
                    var name = attribute.name.toLowerCase();
                    var keep = (tag === "A" && ["href", "title"].indexOf(name) !== -1) ||
                        (tag === "IMG" && ["src", "alt", "title", "width", "height"].indexOf(name) !== -1) ||
                        (tag === "CODE" && name === "class" && /^language-[\w-]+$/.test(attribute.value));
                    if (!keep) child.removeAttribute(attribute.name);
                });
                if (tag === "A") {
                    var href = cleanUrl(child.getAttribute("href") || "");
                    if (href) child.setAttribute("href", href); else child.removeAttribute("href");
                    child.setAttribute("target", "_blank"); child.setAttribute("rel", "noopener noreferrer nofollow");
                }
                if (tag === "IMG") {
                    var src = cleanUrl(child.getAttribute("src") || "");
                    if (src) { child.setAttribute("src", src); child.setAttribute("loading", "lazy"); }
                    else child.remove();
                }
                walk(child);
            });
        }
        walk(template.content);
        return template.innerHTML;
    }

    function contentHtml(text, images) {
        var blocks = String(text || "").trim().replace(/\r\n/g, "\n").split(/\n{2,}/).filter(Boolean).map(function (block) {
            return "<p>" + escapeHtml(block).replace(/\n/g, "<br>") + "</p>";
        });
        (images || []).forEach(function (image) { blocks.push('<p><img src="' + escapeHtml(image.url) + '" alt="' + i18n("工单图片") + '"></p>'); });
        return blocks.join("");
    }

    function ensureViewer() {
        var viewer = q(".ny-ticket-viewer");
        if (viewer) return viewer;
        viewer = document.createElement("div");
        viewer.className = "ny-ticket-viewer";
        viewer.setAttribute("hidden", "");
        viewer.innerHTML = '<button type="button" class="ny-ticket-viewer__backdrop" data-ny-ticket-viewer-close aria-label="' + i18n("关闭图片") + '"></button>' +
            '<header><span>' + i18n("图片预览") + '</span><button type="button" data-ny-ticket-viewer-close aria-label="' + i18n("关闭") + '">' + icon("close") + "</button></header>" +
            '<div><img src="" alt="' + i18n("工单图片预览") + '"></div>';
        (q(".ny-device-shell") || document.body).appendChild(viewer);
        viewer.addEventListener("click", function (event) { if (event.target.closest("[data-ny-ticket-viewer-close]")) closeViewer(); });
        return viewer;
    }
    function openViewer(src) {
        if (!src) return;
        var viewer = ensureViewer();
        q("img", viewer).src = src;
        viewer.hidden = false;
        document.body.classList.add("ny-ticket-viewing");
    }
    function closeViewer() {
        var viewer = q(".ny-ticket-viewer");
        if (viewer) { viewer.hidden = true; q("img", viewer).removeAttribute("src"); }
        document.body.classList.remove("ny-ticket-viewing");
    }

    var STATUS = {
        0: {label: i18n("待客服回复"), icon: "schedule", className: "is-waiting"},
        1: {label: i18n("待我回复"), icon: "mark_chat_unread", className: "is-reply"},
        2: {label: i18n("已解决"), icon: "task_alt", className: "is-resolved"},
        3: {label: i18n("已关闭"), icon: "lock", className: "is-closed"}
    };
    var TYPE = {0: {label: i18n("售前咨询"), icon: "question_answer"}, 1: {label: i18n("售后支持"), icon: "handyman"}};
    var PRIORITY = {0: i18n("低优先级"), 1: i18n("中优先级"), 2: i18n("高优先级")};

    function refreshBadge() {
        var badges = qa("[data-ny-ticket-badge]");
        if (!badges.length && !q('[data-ny-ticket-screen="detail"]')) return Promise.resolve(0);
        return request("/user/api/ticket/badge", {}).then(function (payload) {
            var count = Math.max(0, num(payload.count, 0));
            badges.forEach(function (badge) {
                badge.textContent = count > 99 ? "99+" : String(count);
                badge.hidden = count < 1;
                badge.setAttribute("aria-label", count > 0 ? i18n("{n} 条未读工单消息").replace("{n}", count) : i18n("没有未读工单消息"));
            });
            return count;
        }).catch(function () { return 0; });
    }

    function bindBadge() {
        window.nyTicketRefreshBadge = refreshBadge;
        window.ucTicketRefreshBadge = refreshBadge;
        refreshBadge();
        if (badgeTimer !== null) return;
        badgeTimer = window.setInterval(function () { if (!document.hidden) refreshBadge(); }, 60000);
    }

    function ticketCard(row) {
        var status = STATUS[num(row.status, 0)] || STATUS[0];
        var type = TYPE[num(row.type, 0)] || TYPE[0];
        var unread = Math.max(0, num(row.user_unread, 0));
        var sender = num(row.last_sender_type, 0) === 1 ? i18n("客服") : (num(row.last_sender_type, 0) === 2 ? i18n("系统") : i18n("我"));
        var guestOrder = num(row.order_source, 0) === 2 || row.order_source === "guest";
        var context = guestOrder ? i18n("游客订单 · 待人工核验 · {trade_no}").replace("{trade_no}", function () { return row.order_trade_no || ""; }) : (row.order_trade_no || row.commodity_name || i18n("未关联商品或订单"));
        return '<a class="ny-ticket-card ' + status.className + (unread ? ' has-unread' : '') + '" href="/user/ticket/detail?id=' + encodeURIComponent(row.id) + '">' +
            '<div class="ny-ticket-card__top"><span class="ny-ticket-card__type">' + icon(type.icon) + type.label + '</span><span class="ny-ticket-card__number">' + escapeHtml(row.ticket_no || ("#" + row.id)) + '</span></div>' +
            '<h2>' + escapeHtml(row.title || i18n("未命名工单")) + '</h2>' +
            '<p><b>' + sender + '：</b>' + escapeHtml(row.last_message_excerpt || i18n("工单已创建，等待进一步沟通。")) + '</p>' +
            '<div class="ny-ticket-card__meta"><span>' + icon(status.icon) + status.label + '</span><span' + (guestOrder ? ' class="is-guest"' : '') + '>' + icon(guestOrder ? "pending_actions" : "link") + escapeHtml(context) + '</span></div>' +
            '<footer><time>' + prettyTime(row.last_message_time || row.update_time || row.create_time) + '</time>' +
            (unread ? '<em>' + i18n("{n} 条新回复").replace("{n}", unread > 99 ? "99+" : unread) + '</em>' : '') + icon("chevron_right") + '</footer></a>';
    }

    function initList(page) {
        var list = q("[data-ny-ticket-list]", page);
        var skeletons = q(".ny-ticket-skeletons", page);
        var empty = q("[data-ny-ticket-empty]", page);
        var error = q("[data-ny-ticket-error]", page);
        var more = q("[data-ny-ticket-more]", page);
        var search = q("[data-ny-ticket-search]", page);
        var clear = q("[data-ny-ticket-search-clear]", page);
        var filterTrigger = q(".ny-ticket-filter-trigger", page);
        var filterLabel = q("[data-ny-ticket-filter-label]", page);
        var filterCurrent = q("[data-ny-ticket-filter-current]", page);
        var initialStatus = q("[data-ny-ticket-status].is-active", page);
        var state = {
            page: 1,
            limit: 10,
            total: 0,
            status: initialStatus ? initialStatus.getAttribute("data-ny-ticket-status") : "",
            keyword: search.value.trim(),
            loading: false,
            requestId: 0
        };
        var searchTimer = null;

        function syncStatusControls() {
            var active = null;
            qa("[data-ny-ticket-status]", page).forEach(function (button) {
                var selected = button.getAttribute("data-ny-ticket-status") === state.status;
                button.classList.toggle("is-active", selected);
                button.setAttribute("aria-checked", selected ? "true" : "false");
                if (selected) active = button;
            });
            if (!active) {
                state.status = "";
                active = q('[data-ny-ticket-status=""]', page);
                if (active) { active.classList.add("is-active"); active.setAttribute("aria-checked", "true"); }
            }
            var title = active ? q("strong", active) : null;
            var label = title ? title.textContent.trim() : i18n("全部");
            filterLabel.textContent = state.status === "" ? i18n("筛选") : label;
            filterCurrent.textContent = i18n("当前：") + label;
            filterTrigger.classList.toggle("is-on", state.status !== "");
            filterTrigger.setAttribute("aria-label", i18n("筛选工单状态，当前：") + label);
        }

        function updateStats(stats) {
            stats = stats || {};
            var all = ["pending_admin", "pending_user", "resolved", "closed"].reduce(function (sum, key) { return sum + Math.max(0, num(stats[key], 0)); }, 0);
            var values = {
                all: all,
                active: Math.max(0, num(stats.pending_admin, 0)) + Math.max(0, num(stats.pending_user, 0)),
                pending_admin: Math.max(0, num(stats.pending_admin, 0)),
                pending_user: Math.max(0, num(stats.pending_user, 0)),
                resolved: Math.max(0, num(stats.resolved, 0)),
                closed: Math.max(0, num(stats.closed, 0))
            };
            Object.keys(values).forEach(function (key) { qa('[data-ny-ticket-stat="' + key + '"]', page).forEach(function (node) { node.textContent = values[key]; }); });
        }

        function showError(message) {
            skeletons.hidden = true; error.hidden = false; empty.hidden = true; more.hidden = true;
            var p = q("p", error); if (p) p.textContent = message || i18n("请检查网络后再试一次。");
        }

        function load(reset) {
            if (state.loading && !reset) return;
            state.loading = true;
            var requestId = ++state.requestId;
            if (reset) { state.page = 1; state.total = 0; list.innerHTML = ""; skeletons.hidden = false; }
            var pageNumber = state.page;
            q("span", more).textContent = i18n("加载更多");
            error.hidden = true; empty.hidden = true; more.hidden = true;
            request("/user/api/ticket/data", {page: pageNumber, limit: state.limit, status: state.status, keyword: state.keyword, type: "", priority: ""})
                .then(function (payload) {
                    if (requestId !== state.requestId) return;
                    if (payload.ready === false) throw new Error(i18n("工单功能尚未启用，请联系管理员完成数据库升级。"));
                    var rows = Array.isArray(payload.list) ? payload.list : [];
                    state.total = Math.max(0, num(payload.total, rows.length));
                    updateStats(payload.stats);
                    skeletons.hidden = true;
                    if (reset) list.innerHTML = "";
                    if (rows.length) list.insertAdjacentHTML("beforeend", rows.map(ticketCard).join(""));
                    var loaded = list.children.length;
                    empty.hidden = loaded > 0;
                    if (!loaded) {
                        q("strong", empty).textContent = state.keyword || state.status !== "" ? i18n("没有符合条件的工单") : i18n("还没有工单");
                        q("p", empty).textContent = state.keyword || state.status !== "" ? i18n("换一个关键词或状态看看。") : i18n("遇到疑问时，创建工单就能持续与客服沟通。");
                    }
                    state.page = pageNumber + 1;
                    more.hidden = loaded >= state.total;
                })
                .catch(function (reason) {
                    if (requestId !== state.requestId) return;
                    if (reset) showError(reason.message);
                    else { q("span", more).textContent = i18n("加载失败，轻触重试"); more.hidden = false; toast(reason.message, "error"); }
                })
                .finally(function () { if (requestId === state.requestId) state.loading = false; });
        }

        qa("[data-ny-ticket-status]", page).forEach(function (button) {
            button.addEventListener("click", function () {
                var status = button.getAttribute("data-ny-ticket-status");
                if (status === state.status) return;
                state.status = status;
                syncStatusControls();
                load(true);
            });
        });
        search.addEventListener("input", function () {
            clear.hidden = !search.value;
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () { state.keyword = search.value.trim(); load(true); }, 320);
        });
        search.addEventListener("keydown", function (event) {
            if (event.key === "Enter") { event.preventDefault(); window.clearTimeout(searchTimer); state.keyword = search.value.trim(); load(true); }
        });
        clear.addEventListener("click", function () { search.value = ""; clear.hidden = true; state.keyword = ""; search.focus(); load(true); });
        q(".ny-ticket-refresh", page).addEventListener("click", function () { load(true); });
        q("[data-ny-ticket-retry]", page).addEventListener("click", function () { load(true); });
        more.addEventListener("click", function () { load(false); });
        syncStatusControls();
        load(true);
        return function () { window.clearTimeout(searchTimer); state.requestId += 1; };
    }

    function initCreate(page) {
        var state = {step: 1, maxStep: 1, type: 0, priority: 1, orderMode: "account", commodity: null, order: null, proof: null, images: [], submitting: false, proofUploading: false, contentUploading: false, destroyed: false, redirectTimer: null, picker: null, pickerPage: 1, pickerTotal: 0, pickerItems: [], pickerLoading: false, pickerRequestId: 0};
        var labels = ["", i18n("选择服务类型"), i18n("关联商品或订单"), i18n("说明具体情况")];
        var picker = q("[data-ny-ticket-picker]", page);
        var pickerBackdrop = q("[data-ny-ticket-picker-close].ny-ticket-picker-backdrop", page);
        var pickerList = q("[data-ny-ticket-picker-list]", page);
        var pickerSearch = q("[data-ny-ticket-picker-search]", page);
        var pickerClear = q("[data-ny-ticket-picker-search-clear]", page);
        var pickerMore = q("[data-ny-ticket-picker-more]", page);
        var pickerTimer = null;

        function syncSummary() {
            q("[data-ny-ticket-summary-type]", page).textContent = state.type === 1 ? i18n("售后支持") : i18n("售前咨询");
            q("[data-ny-ticket-summary-priority]", page).textContent = PRIORITY[state.priority];
            var relation = i18n("未关联商品");
            if (state.type === 0 && state.commodity) relation = state.commodity.name;
            if (state.type === 1 && state.orderMode === "account") relation = state.order ? state.order.trade_no : i18n("未选择订单");
            if (state.type === 1 && state.orderMode === "manual") relation = q('input[name="trade_no"]', page).value.trim() || i18n("未输入订单号");
            q("[data-ny-ticket-summary-relation]", page).textContent = relation;
        }

        function syncType() {
            qa("[data-ny-ticket-type]", page).forEach(function (button) {
                var active = num(button.getAttribute("data-ny-ticket-type"), 0) === state.type;
                button.classList.toggle("is-active", active); button.setAttribute("aria-checked", active ? "true" : "false");
            });
            q("[data-ny-ticket-presale]", page).hidden = state.type !== 0;
            q("[data-ny-ticket-aftersale]", page).hidden = state.type !== 1;
            q("[data-ny-ticket-relation-title]", page).textContent = state.type === 1 ? i18n("关联订单") : i18n("关联商品");
            q("[data-ny-ticket-relation-copy]", page).textContent = state.type === 1 ? i18n("选择订单并添加购买凭证，方便客服核验。") : i18n("选填，关联后客服能更快理解你的问题。");
            syncSummary();
        }

        function syncPriority() {
            qa("[data-ny-ticket-priority]", page).forEach(function (button) {
                var active = num(button.getAttribute("data-ny-ticket-priority"), 1) === state.priority;
                button.classList.toggle("is-active", active); button.setAttribute("aria-checked", active ? "true" : "false");
            });
            syncSummary();
        }

        function syncOrderMode() {
            qa("[data-ny-ticket-order-mode]", page).forEach(function (button) { button.classList.toggle("is-active", button.getAttribute("data-ny-ticket-order-mode") === state.orderMode); });
            qa("[data-ny-ticket-order-panel]", page).forEach(function (panel) { panel.hidden = panel.getAttribute("data-ny-ticket-order-panel") !== state.orderMode; });
            syncSummary();
        }

        function showStep(step, smooth) {
            state.step = Math.max(1, Math.min(3, step));
            qa("[data-ny-ticket-step]", page).forEach(function (panel) { var active = num(panel.getAttribute("data-ny-ticket-step"), 0) === state.step; panel.hidden = !active; panel.classList.toggle("is-active", active); });
            qa("[data-ny-ticket-step-jump]", page).forEach(function (button) {
                var value = num(button.getAttribute("data-ny-ticket-step-jump"), 0);
                button.classList.toggle("is-active", value === state.step); button.classList.toggle("is-done", value < state.step);
            });
            q("[data-ny-ticket-step-label]", page).textContent = labels[state.step];
            q("[data-ny-ticket-step-count]", page).textContent = state.step;
            q("[data-ny-ticket-step-back]", page).hidden = state.step === 1;
            q("[data-ny-ticket-step-next]", page).hidden = state.step === 3;
            q("[data-ny-ticket-submit]", page).hidden = state.step !== 3;
            if (smooth) window.scrollTo({top: 0, behavior: "smooth"});
        }

        function validateStep(step) {
            if (step === 2 && state.type === 1) {
                if (state.orderMode === "account" && !state.order) { toast(i18n("请先选择需要售后的订单"), "error"); return false; }
                if (state.orderMode === "manual" && !q('input[name="trade_no"]', page).value.trim()) { toast(i18n("请输入游客订单号"), "error"); return false; }
                if (!state.proof) { toast(i18n("请添加购买凭证"), "error"); return false; }
            }
            if (step === 3) {
                var title = q('input[name="title"]', page).value.trim();
                var content = q('textarea[name="content"]', page).value.trim();
                if (Array.from(title).length < 4) { toast(i18n("工单标题至少填写 4 个字"), "error"); return false; }
                if (!content && !state.images.length) { toast(i18n("请描述问题或添加截图"), "error"); return false; }
            }
            return true;
        }

        function renderAttachments() {
            var target = q("[data-ny-ticket-attachments]", page);
            target.innerHTML = state.images.map(function (image, index) { return '<div><button type="button" data-ny-ticket-image-view="' + index + '"><img src="' + escapeHtml(image.url) + '" alt="' + i18n("补充图片") + '"></button><button type="button" data-ny-ticket-image-remove="' + index + '" aria-label="' + i18n("移除图片") + '">' + icon("close") + "</button></div>"; }).join("");
            q("[data-ny-ticket-image-count]", page).textContent = state.images.length;
            q("[data-ny-ticket-content-pick]", page).hidden = state.images.length >= 8;
        }

        function closePicker() {
            state.pickerRequestId += 1;
            state.pickerLoading = false;
            picker.hidden = true; pickerBackdrop.hidden = true; document.body.classList.remove("ny-ticket-picker-open");
        }
        function pickerItem(item, index) {
            var order = state.picker === "order";
            var title = order ? item.commodity_name : item.name;
            var meta = order ? [item.trade_no, item.amount != null ? acgCurrencySymbol() + item.amount : "", item.pay_time || item.create_time].filter(Boolean).join(" · ") : [item.category_name || i18n("未分类"), i18n("商品 ID {id}").replace("{id}", item.id)].join(" · ");
            return '<button type="button" class="ny-ticket-picker-item" data-ny-ticket-picker-index="' + index + '"><img src="' + escapeHtml(item.cover || "/favicon.ico") + '" alt=""><span><strong>' + escapeHtml(title || i18n("未命名")) + '</strong><small>' + escapeHtml(meta) + '</small></span>' + icon("chevron_right") + "</button>";
        }
        function loadPicker(reset) {
            if (!state.picker || (state.pickerLoading && !reset)) return;
            state.pickerLoading = true;
            if (reset) { state.pickerPage = 1; state.pickerItems = []; pickerList.innerHTML = '<div class="ny-ticket-picker-loading">' + icon("progress_activity") + i18n("正在加载") + "</div>"; }
            pickerMore.hidden = true;
            var kind = state.picker;
            var pageNumber = state.pickerPage;
            var requestId = ++state.pickerRequestId;
            var endpoint = kind === "order" ? "/user/api/ticket/orderOptions" : "/user/api/ticket/commodityOptions";
            request(endpoint, {page: pageNumber, limit: 15, keyword: pickerSearch.value.trim()}).then(function (payload) {
                if (requestId !== state.pickerRequestId || kind !== state.picker) return;
                var rows = Array.isArray(payload.list) ? payload.list : [];
                if (reset) { state.pickerItems = []; pickerList.innerHTML = ""; }
                var offset = state.pickerItems.length;
                state.pickerItems = state.pickerItems.concat(rows);
                pickerList.insertAdjacentHTML("beforeend", rows.map(function (item, index) { return pickerItem(item, offset + index); }).join(""));
                state.pickerTotal = Math.max(0, num(payload.total, state.pickerItems.length));
                if (!state.pickerItems.length) pickerList.innerHTML = '<div class="ny-ticket-picker-empty">' + icon("search_off") + '<strong>' + i18n("没有找到匹配内容") + '</strong><span>' + i18n("换一个关键词试试") + '</span></div>';
                state.pickerPage = pageNumber + 1; pickerMore.hidden = state.pickerItems.length >= state.pickerTotal;
            }).catch(function (reason) {
                if (requestId === state.pickerRequestId) pickerList.innerHTML = '<div class="ny-ticket-picker-empty">' + icon("cloud_off") + '<strong>' + i18n("加载失败") + '</strong><span>' + escapeHtml(reason.message) + "</span></div>";
            }).finally(function () { if (requestId === state.pickerRequestId) state.pickerLoading = false; });
        }
        function openPicker(kind) {
            state.picker = kind; pickerSearch.value = ""; pickerClear.hidden = true;
            q("[data-ny-ticket-picker-title]", page).textContent = kind === "order" ? i18n("选择订单") : i18n("选择商品");
            q("[data-ny-ticket-picker-subtitle]", page).textContent = kind === "order" ? i18n("仅显示当前账号已支付订单") : i18n("轻触商品即可关联");
            pickerSearch.placeholder = kind === "order" ? i18n("搜索商品名称或订单号") : i18n("搜索商品名称");
            picker.hidden = false; pickerBackdrop.hidden = false; document.body.classList.add("ny-ticket-picker-open"); loadPicker(true);
            window.setTimeout(function () { pickerSearch.focus(); }, 260);
        }

        qa("[data-ny-ticket-type]", page).forEach(function (button) { button.addEventListener("click", function () { state.type = num(button.getAttribute("data-ny-ticket-type"), 0); syncType(); }); });
        qa("[data-ny-ticket-priority]", page).forEach(function (button) { button.addEventListener("click", function () { state.priority = num(button.getAttribute("data-ny-ticket-priority"), 1); syncPriority(); }); });
        qa("[data-ny-ticket-order-mode]", page).forEach(function (button) { button.addEventListener("click", function () { state.orderMode = button.getAttribute("data-ny-ticket-order-mode") || "account"; syncOrderMode(); }); });
        qa("[data-ny-ticket-step-jump]", page).forEach(function (button) { button.addEventListener("click", function () { var step = num(button.getAttribute("data-ny-ticket-step-jump"), 1); if (step <= state.maxStep) showStep(step, true); }); });
        q("[data-ny-ticket-step-next]", page).addEventListener("click", function () { if (!validateStep(state.step)) return; state.maxStep = Math.max(state.maxStep, state.step + 1); showStep(state.step + 1, true); });
        q("[data-ny-ticket-step-back]", page).addEventListener("click", function () { showStep(state.step - 1, true); });
        qa("[data-ny-ticket-picker-open]", page).forEach(function (button) { button.addEventListener("click", function () { openPicker(button.getAttribute("data-ny-ticket-picker-open")); }); });
        qa("[data-ny-ticket-picker-close]", page).forEach(function (button) { button.addEventListener("click", closePicker); });
        pickerList.addEventListener("click", function (event) {
            var button = event.target.closest("[data-ny-ticket-picker-index]"); if (!button) return;
            var item = state.pickerItems[num(button.getAttribute("data-ny-ticket-picker-index"), -1)]; if (!item) return;
            if (state.picker === "commodity") { state.commodity = item; q("[data-ny-ticket-commodity-label]", page).textContent = item.name; q('[data-ny-ticket-clear="commodity"]', page).hidden = false; }
            else { state.order = item; q("[data-ny-ticket-order-label]", page).textContent = item.commodity_name + " · " + item.trade_no; }
            syncSummary(); closePicker();
        });
        q('[data-ny-ticket-clear="commodity"]', page).addEventListener("click", function () { state.commodity = null; q("[data-ny-ticket-commodity-label]", page).textContent = i18n("从店铺商品中选择"); this.hidden = true; syncSummary(); });
        pickerSearch.addEventListener("input", function () { pickerClear.hidden = !pickerSearch.value; window.clearTimeout(pickerTimer); pickerTimer = window.setTimeout(function () { loadPicker(true); }, 300); });
        pickerClear.addEventListener("click", function () { pickerSearch.value = ""; pickerClear.hidden = true; loadPicker(true); pickerSearch.focus(); });
        pickerMore.addEventListener("click", function () { loadPicker(false); });
        q('input[name="trade_no"]', page).addEventListener("input", syncSummary);

        var proofInput = q("[data-ny-ticket-proof-input]", page);
        q("[data-ny-ticket-proof-pick]", page).addEventListener("click", function () { proofInput.click(); });
        proofInput.addEventListener("change", function () {
            var file = proofInput.files && proofInput.files[0]; if (!file) return;
            if (state.proofUploading) { proofInput.value = ""; return; }
            state.proofUploading = true;
            var button = q("[data-ny-ticket-proof-pick]", page); button.disabled = true; button.classList.add("is-loading");
            q("i", button).textContent = i18n("上传中");
            uploadImage(file, function (progress) { q("i", button).textContent = progress + "%"; }).then(function (result) {
                if (state.destroyed) return;
                state.proof = {url: result.url, upload_id: result.upload_id, name: file.name};
                var preview = q("[data-ny-ticket-proof-preview]", page); preview.hidden = false; q("img", preview).src = result.url; q("small", preview).textContent = file.name; button.hidden = true; toast(i18n("购买凭证已添加"), "success");
            }).catch(function (reason) { if (!state.destroyed) toast(reason.message, "error"); }).finally(function () { state.proofUploading = false; button.disabled = false; button.classList.remove("is-loading"); q("i", button).textContent = i18n("选择图片"); proofInput.value = ""; });
        });
        q("[data-ny-ticket-proof-remove]", page).addEventListener("click", function () { state.proof = null; q("[data-ny-ticket-proof-preview]", page).hidden = true; q("[data-ny-ticket-proof-pick]", page).hidden = false; });
        q("[data-ny-ticket-proof-preview]", page).addEventListener("click", function (event) { if (!event.target.closest("button") && state.proof) openViewer(state.proof.url); });

        var contentInput = q("[data-ny-ticket-content-input]", page);
        q("[data-ny-ticket-content-pick]", page).addEventListener("click", function () { contentInput.click(); });
        contentInput.addEventListener("change", function () {
            var files = Array.prototype.slice.call(contentInput.files || []).slice(0, 8 - state.images.length); contentInput.value = "";
            if (!files.length) return;
            if (state.contentUploading) return;
            state.contentUploading = true;
            var button = q("[data-ny-ticket-content-pick]", page); var submit = q("[data-ny-ticket-submit]", page); button.disabled = true; submit.disabled = true; button.innerHTML = icon("progress_activity") + i18n("正在上传");
            files.reduce(function (chain, file) { return chain.then(function () { return uploadImage(file).then(function (result) { if (state.destroyed) return; state.images.push({url: result.url, upload_id: result.upload_id, name: file.name}); renderAttachments(); }); }); }, Promise.resolve())
                .then(function () { if (!state.destroyed) toast(i18n("图片已添加"), "success"); }).catch(function (reason) { if (!state.destroyed) toast(reason.message, "error"); })
                .finally(function () { state.contentUploading = false; button.disabled = false; submit.disabled = state.submitting; button.innerHTML = icon("add_photo_alternate") + i18n("添加截图"); });
        });
        q("[data-ny-ticket-attachments]", page).addEventListener("click", function (event) {
            var remove = event.target.closest("[data-ny-ticket-image-remove]"); var view = event.target.closest("[data-ny-ticket-image-view]");
            if (remove) { state.images.splice(num(remove.getAttribute("data-ny-ticket-image-remove"), -1), 1); renderAttachments(); }
            else if (view) { var image = state.images[num(view.getAttribute("data-ny-ticket-image-view"), -1)]; if (image) openViewer(image.url); }
        });
        q('input[name="title"]', page).addEventListener("input", function () { q("[data-ny-ticket-title-count]", page).textContent = Array.from(this.value).length; });

        q("[data-ny-ticket-submit]", page).addEventListener("click", function () {
            if (state.proofUploading || state.contentUploading) { toast(i18n("图片仍在上传，请稍候"), "error"); return; }
            if (state.submitting || !validateStep(2) || !validateStep(3)) return;
            state.submitting = true; var button = this; button.disabled = true; button.innerHTML = '<span>' + i18n("正在提交") + '</span>' + icon("progress_activity");
            request("/user/api/ticket/create", {
                type: state.type,
                priority: state.priority,
                title: q('input[name="title"]', page).value.trim(),
                commodity_id: state.type === 0 && state.commodity ? state.commodity.id : "",
                order_id: state.type === 1 && state.orderMode === "account" && state.order ? state.order.id : "",
                trade_no: state.type === 1 && state.orderMode === "manual" ? q('input[name="trade_no"]', page).value.trim() : "",
                proof_upload_id: state.type === 1 && state.proof ? state.proof.upload_id : "",
                proof_path: state.type === 1 && state.proof ? state.proof.url : "",
                content: contentHtml(q('textarea[name="content"]', page).value, state.images)
            }).then(function (result) {
                if (state.destroyed) return;
                toast(i18n("工单已创建"), "success"); state.redirectTimer = window.setTimeout(function () { if (!state.destroyed) window.location.href = result.url || ("/user/ticket/detail?id=" + result.id); }, 450);
            }).catch(function (reason) { if (state.destroyed) return; state.submitting = false; button.disabled = false; button.innerHTML = '<span>' + i18n("提交工单") + '</span>' + icon("send"); toast(reason.message, "error"); });
        });

        syncType(); syncPriority(); syncOrderMode(); syncSummary(); renderAttachments(); showStep(1, false);
        return function () { state.destroyed = true; window.clearTimeout(state.redirectTimer); window.clearTimeout(pickerTimer); closePicker(); };
    }

    function messageHtml(item) {
        var sender = num(item.sender_type, 0);
        var kind = num(item.kind, 0);
        if (sender === 2 || kind === 2) {
            return '<article class="ny-ticket-event' + (kind === 2 ? ' is-closed' : '') + '" data-message-id="' + num(item.id, 0) + '">' + icon(kind === 2 ? "lock" : "info") + '<div><strong>' + escapeHtml(item.sender_name || i18n("系统")) + '</strong><div class="ny-ticket-message-content">' + sanitizeHtml(item.content) + '</div><time>' + escapeHtml(item.create_time || "") + "</time></div></article>";
        }
        var user = sender === 0;
        var finalBadge = kind === 1 ? '<span class="ny-ticket-final">' + icon("task_alt") + i18n("最终答复") + '</span>' : "";
        return '<article class="ny-ticket-message ' + (user ? "is-user" : "is-admin") + '" data-message-id="' + num(item.id, 0) + '">' +
            '<div class="ny-ticket-message__meta"><strong>' + escapeHtml(item.sender_name || (user ? i18n("我") : i18n("客服"))) + '</strong>' + (!user ? '<span>' + i18n("官方客服") + '</span>' : "") + finalBadge + '<time>' + escapeHtml(item.create_time || "") + '</time></div>' +
            '<div class="ny-ticket-message__bubble"><div class="ny-ticket-message-content">' + sanitizeHtml(item.content) + "</div></div></article>";
    }

    function initDetail(page) {
        var ticketId = num(page.getAttribute("data-ticket-id"), 0);
        var messages = q("[data-ny-ticket-messages]", page);
        var loading = q("[data-ny-ticket-detail-loading]", page);
        var error = q("[data-ny-ticket-detail-error]", page);
        var content = q("[data-ny-ticket-detail-content]", page);
        var composer = q("[data-ny-ticket-composer]", page);
        var closedDock = q("[data-ny-ticket-closed]", page);
        var textarea = q("[data-ny-ticket-reply-text]", page);
        var send = q("[data-ny-ticket-reply-send]", page);
        var replyInput = q("[data-ny-ticket-reply-input]", page);
        var replyAttachments = q("[data-ny-ticket-reply-attachments]", page);
        var ids = new Set();
        var maxId = 0, minId = 0, pollTimer = null, destroyed = false, replying = false, uploadingReply = false, polling = false, currentTicket = null;
        var attachments = [];
        var dockObserver = null;

        function syncDockSpace() {
            var dock = !composer.hidden ? composer : (!closedDock.hidden ? closedDock : null);
            var height = dock ? Math.ceil(dock.getBoundingClientRect().height) : 0;
            page.style.setProperty("--ny-ticket-dock-height", Math.max(72, height) + "px");
        }

        function enhanceImages(scope) { qa(".ny-ticket-message-content img", scope).forEach(function (image) { image.setAttribute("role", "button"); image.setAttribute("tabindex", "0"); image.setAttribute("title", i18n("轻触查看大图")); }); }
        function appendRows(rows, initial) {
            var fresh = (Array.isArray(rows) ? rows : []).filter(function (item) { var id = num(item.id, 0); if (!id || ids.has(id)) return false; ids.add(id); maxId = Math.max(maxId, id); minId = minId ? Math.min(minId, id) : id; return true; });
            if (!fresh.length) return;
            var box = document.createElement("div"); box.innerHTML = fresh.map(messageHtml).join("");
            while (box.firstChild) messages.appendChild(box.firstChild);
            enhanceImages(messages);
            if (initial || window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 220) window.setTimeout(function () { messages.lastElementChild && messages.lastElementChild.scrollIntoView({block: "end"}); }, 60);
        }
        function prependRows(rows) {
            var fresh = (Array.isArray(rows) ? rows : []).filter(function (item) { var id = num(item.id, 0); if (!id || ids.has(id)) return false; ids.add(id); return true; });
            if (!fresh.length) return 0;
            var oldHeight = document.documentElement.scrollHeight;
            var box = document.createElement("div"); box.innerHTML = fresh.map(messageHtml).join("");
            var nodes = Array.prototype.slice.call(box.childNodes); nodes.reverse().forEach(function (node) { messages.insertBefore(node, messages.firstChild); });
            minId = Math.min.apply(Math, fresh.map(function (item) { return num(item.id, minId); })); enhanceImages(messages);
            window.scrollBy(0, document.documentElement.scrollHeight - oldHeight); return fresh.length;
        }
        function statusClass(status) { return (STATUS[num(status, 0)] || STATUS[0]).className; }
        function updateStatus(status) {
            if (!currentTicket) return;
            currentTicket.status = num(status, currentTicket.status);
            var info = STATUS[currentTicket.status] || STATUS[0];
            var badge = q("[data-ny-ticket-detail-status]", page); badge.className = "ny-ticket-status " + info.className; badge.textContent = info.label;
            q("[data-ny-ticket-context-status]", page).textContent = info.label;
            var terminal = currentTicket.status >= 2;
            composer.hidden = terminal; closedDock.hidden = !terminal;
            var live = q(".ny-ticket-live", page); live.classList.toggle("is-archived", terminal); live.innerHTML = "<i></i>" + (terminal ? i18n("记录已归档") : i18n("自动更新"));
            window.requestAnimationFrame(syncDockSpace);
            if (terminal) window.clearTimeout(pollTimer); else schedulePoll();
        }
        function renderRelated(ticket) {
            var context = ticket.context || {};
            var related = q("[data-ny-ticket-related]", page);
            var label = i18n("未关联"); var html = ""; var guestOrder = false;
            if (num(ticket.type, 0) === 0 && (ticket.commodity_name || context.commodity)) {
                var commodity = context.commodity || ticket.commodity || {};
                label = ticket.commodity_name || commodity.name || i18n("相关商品");
                html = '<img src="' + escapeHtml(commodity.cover || "/favicon.ico") + '" alt=""><span><small>' + i18n("相关商品") + '</small><strong>' + escapeHtml(label) + "</strong></span>";
            } else if (num(ticket.type, 0) === 1 && ticket.order_trade_no) {
                var order = context.order || ticket.order || {}; var commodityInfo = context.commodity || ticket.commodity || {};
                guestOrder = num(ticket.order_source, 0) === 2 || ticket.order_source === "guest" || ticket.order_verification_pending === true;
                label = ticket.order_trade_no; html = (commodityInfo.cover ? '<img src="' + escapeHtml(commodityInfo.cover) + '" alt="">' : icon(guestOrder ? "pending_actions" : "receipt_long")) + '<span><small>' + (guestOrder ? i18n("游客订单 · 待人工核验") : i18n("相关订单")) + '</small><strong>' + escapeHtml(guestOrder ? i18n("订单待人工核验") : (order.commodity_name || ticket.commodity_name || label)) + '</strong><em>' + escapeHtml(label) + "</em></span>";
            }
            q("[data-ny-ticket-related-label]", page).textContent = label;
            related.classList.toggle("is-guest", guestOrder);
            related.innerHTML = html; related.hidden = !html;
        }
        function renderTicket(ticket) {
            currentTicket = ticket;
            q("[data-ny-ticket-number]", page).textContent = ticket.ticket_no || (i18n("工单 #") + ticket.id);
            q("[data-ny-ticket-title]", page).textContent = ticket.title || i18n("未命名工单");
            q("[data-ny-ticket-type]", page).textContent = (TYPE[num(ticket.type, 0)] || TYPE[0]).label;
            q("[data-ny-ticket-priority]", page).textContent = PRIORITY[num(ticket.priority, 1)] || PRIORITY[1];
            q("[data-ny-ticket-updated]", page).textContent = prettyTime(ticket.update_time || ticket.last_message_time);
            q("[data-ny-ticket-created]", page).textContent = ticket.create_time || "—";
            q("[data-ny-ticket-context-updated]", page).textContent = ticket.update_time || ticket.last_message_time || "—";
            renderRelated(ticket);
            var proof = ticket.proof_path || (ticket.proof && ticket.proof.url) || "";
            var proofButton = q("[data-ny-ticket-proof-view]", page); proofButton.hidden = !proof; if (proof) q("img", proofButton).src = proof;
            updateStatus(ticket.status);
        }
        function renderReplyAttachments() {
            replyAttachments.hidden = !attachments.length;
            replyAttachments.innerHTML = attachments.map(function (image, index) { return '<div><button type="button" data-reply-view="' + index + '"><img src="' + escapeHtml(image.url) + '" alt="' + i18n("待发送图片") + '"></button><button type="button" data-reply-remove="' + index + '">' + icon("close") + "</button></div>"; }).join("");
            send.disabled = uploadingReply || (!textarea.value.trim() && !attachments.length);
            window.requestAnimationFrame(syncDockSpace);
        }
        function resizeTextarea() { textarea.style.height = "auto"; textarea.style.height = Math.min(104, Math.max(40, textarea.scrollHeight)) + "px"; send.disabled = uploadingReply || (!textarea.value.trim() && !attachments.length); window.requestAnimationFrame(syncDockSpace); }
        function schedulePoll() { if (destroyed || !currentTicket || currentTicket.status >= 2) return; window.clearTimeout(pollTimer); pollTimer = window.setTimeout(poll, 15000); }
        function poll() {
            if (destroyed || document.hidden || !currentTicket) { schedulePoll(); return; }
            if (polling) return;
            polling = true;
            request("/user/api/ticket/messages", {id: ticketId, after_id: maxId, limit: 50}).then(function (payload) { if (destroyed) return; appendRows(payload.list || [], false); if (payload.status != null) updateStatus(payload.status); if (payload.last_message_time) { q("[data-ny-ticket-updated]", page).textContent = prettyTime(payload.last_message_time); q("[data-ny-ticket-context-updated]", page).textContent = payload.last_message_time; } }).catch(function () {}).finally(function () { polling = false; schedulePoll(); });
        }
        function resume() { if (destroyed || !currentTicket || currentTicket.status >= 2) return; window.clearTimeout(pollTimer); poll(); }

        if (window.ResizeObserver) {
            dockObserver = new ResizeObserver(syncDockSpace);
            dockObserver.observe(composer); dockObserver.observe(closedDock);
        }

        q("[data-ny-ticket-context-toggle]", page).addEventListener("click", function () { var panel = q("[data-ny-ticket-context]", page); panel.hidden = !panel.hidden; this.setAttribute("aria-expanded", panel.hidden ? "false" : "true"); this.classList.toggle("is-open", !panel.hidden); });
        q("[data-ny-ticket-proof-view]", page).addEventListener("click", function () { openViewer(q("img", this).src); });
        messages.addEventListener("click", function (event) { var image = event.target.closest(".ny-ticket-message-content img"); if (image) openViewer(image.src); });
        messages.addEventListener("keydown", function (event) { var image = event.target.closest(".ny-ticket-message-content img"); if (image && (event.key === "Enter" || event.key === " ")) { event.preventDefault(); openViewer(image.src); } });
        q("[data-ny-ticket-history]", page).addEventListener("click", function () {
            var button = this; button.disabled = true;
            request("/user/api/ticket/messages", {id: ticketId, before_id: minId, limit: 50}).then(function (payload) { var count = prependRows(payload.list || []); button.hidden = payload.has_more != null ? !payload.has_more : count < 50; }).catch(function (reason) { toast(reason.message, "error"); }).finally(function () { button.disabled = false; });
        });
        textarea.addEventListener("input", resizeTextarea);
        textarea.addEventListener("keydown", function (event) { if (event.key === "Enter" && (event.metaKey || event.ctrlKey)) { event.preventDefault(); send.click(); } });
        q("[data-ny-ticket-reply-pick]", page).addEventListener("click", function () { replyInput.click(); });
        replyInput.addEventListener("change", function () {
            var files = Array.prototype.slice.call(replyInput.files || []).slice(0, 8 - attachments.length); replyInput.value = ""; if (!files.length) return;
            if (uploadingReply) return;
            uploadingReply = true; var pick = q("[data-ny-ticket-reply-pick]", page); pick.disabled = true; pick.classList.add("is-loading"); renderReplyAttachments();
            toast(i18n("正在上传图片"));
            files.reduce(function (chain, file) { return chain.then(function () { return uploadImage(file).then(function (result) { if (destroyed) return; attachments.push({url: result.url, upload_id: result.upload_id, name: file.name}); renderReplyAttachments(); }); }); }, Promise.resolve())
                .then(function () { if (!destroyed) toast(i18n("图片已添加"), "success"); }).catch(function (reason) { if (!destroyed) toast(reason.message, "error"); })
                .finally(function () { uploadingReply = false; pick.disabled = false; pick.classList.remove("is-loading"); renderReplyAttachments(); });
        });
        replyAttachments.addEventListener("click", function (event) { var remove = event.target.closest("[data-reply-remove]"); var view = event.target.closest("[data-reply-view]"); if (remove) { attachments.splice(num(remove.getAttribute("data-reply-remove"), -1), 1); renderReplyAttachments(); } else if (view) { var image = attachments[num(view.getAttribute("data-reply-view"), -1)]; if (image) openViewer(image.url); } });
        send.addEventListener("click", function () {
            if (uploadingReply) { toast(i18n("图片仍在上传，请稍候"), "error"); return; }
            if (replying || (!textarea.value.trim() && !attachments.length)) return;
            replying = true; send.disabled = true; send.classList.add("is-loading");
            request("/user/api/ticket/reply", {id: ticketId, content: contentHtml(textarea.value, attachments)}).then(function (payload) {
                appendRows(payload.message ? [payload.message] : [], false); textarea.value = ""; attachments = []; renderReplyAttachments(); resizeTextarea(); updateStatus(payload.status); toast(i18n("回复已发送"), "success"); refreshBadge();
            }).catch(function (reason) { toast(reason.message, "error"); }).finally(function () { replying = false; send.classList.remove("is-loading"); resizeTextarea(); });
        });

        var cleanup = function () { destroyed = true; window.clearTimeout(pollTimer); if (dockObserver) dockObserver.disconnect(); page.style.removeProperty("--ny-ticket-dock-height"); };
        cleanup.resume = resume;
        if (!ticketId) { loading.hidden = true; error.hidden = false; return cleanup; }
        request("/user/api/ticket/detail", {id: ticketId, limit: 100}).then(function (payload) {
            if (!payload.ticket) throw new Error(i18n("工单不存在"));
            renderTicket(payload.ticket); appendRows(payload.messages || [], true); q("[data-ny-ticket-history]", page).hidden = !payload.has_more;
            loading.hidden = true; error.hidden = true; content.hidden = false; refreshBadge(); schedulePoll();
        }).catch(function (reason) { loading.hidden = true; error.hidden = false; q("p", error).textContent = reason.message; });
        resizeTextarea(); renderReplyAttachments();
        return cleanup;
    }

    function destroyPage() {
        if (typeof currentDestroy === "function") currentDestroy();
        currentDestroy = null; currentResume = null; currentPage = null; closeViewer(); document.body.classList.remove("ny-ticket-picker-open", "ny-ticket-focus-active");
    }
    function initPage() {
        bindBadge();
        var page = q("[data-ny-ticket-screen]");
        if (!page) {
            destroyPage();
            return;
        }
        if (page === currentPage) {
            document.body.classList.toggle("ny-ticket-focus-active", !!q(".ny-ticket-focus-page"));
            return;
        }
        destroyPage(); currentPage = page; page.setAttribute("data-ny-ticket-bound", "1");
        document.body.classList.toggle("ny-ticket-focus-active", !!q(".ny-ticket-focus-page"));
        var screen = page.getAttribute("data-ny-ticket-screen");
        if (screen === "list") currentDestroy = initList(page);
        else if (screen === "create") currentDestroy = initCreate(page);
        else if (screen === "detail") currentDestroy = initDetail(page);
        currentResume = currentDestroy && typeof currentDestroy.resume === "function" ? currentDestroy.resume : null;
    }

    document.addEventListener("keydown", function (event) { if (event.key === "Escape") closeViewer(); });
    document.addEventListener("visibilitychange", function () { if (!document.hidden) { refreshBadge(); if (typeof currentResume === "function") currentResume(); } });
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initPage); else initPage();
    if (window.jQuery) {
        window.jQuery(document).on("pjax:complete pjax:end pjax:error pjax:timeout", function () { window.setTimeout(initPage, 90); });
    }
    window.addEventListener("pageshow", initPage);
}();
