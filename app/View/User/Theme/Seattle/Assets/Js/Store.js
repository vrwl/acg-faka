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

    var NOTICE_KEY = "seattle.notice.ack.v1";
    var NOTICE_TTL = 60 * 60 * 1000;
    var MOTION_FALLBACK = 240;
    var activeStore = null;
    var noticeTimer = null;
    var noticeMotionCancel = null;
    var payObserver = null;
    var storeScrollLock = null;

    function escapeHtml(value) {
        return String(value == null ? "" : value).replace(/[&<>"']/g, function (character) {
            return {
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                "\"": "&quot;",
                "'": "&#39;"
            }[character];
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

    function safeImageUrl(value, fallback) {
        var safeFallback = String(fallback || "/favicon.ico");
        var source = String(value || safeFallback).trim();
        if (!source || /[\\\u0000-\u001f\u007f]/.test(source)) {
            return escapeHtml(safeFallback);
        }
        try {
            var parsed = new URL(source, window.location.origin + "/");
            return escapeHtml(parsed.protocol === "http:" || parsed.protocol === "https:" ? parsed.href : safeFallback);
        } catch (error) {
            return escapeHtml(safeFallback);
        }
    }

    function formatPrice(value) {
        if (window.format && typeof window.format.amountRemoveTrailingZeros === "function") {
            return window.format.amountRemoveTrailingZeros(value);
        }

        var number = Number(value);
        if (!Number.isFinite(number)) {
            return String(value == null ? "0" : value);
        }

        return number % 1 === 0 ? String(number) : number.toFixed(2).replace(/0+$/, "").replace(/\.$/, "");
    }

    function notifyError(text) {
        if (window.SeattleTheme && typeof window.SeattleTheme.notify === "function") {
            window.SeattleTheme.notify(text, {type: "error"});
        } else if (window.message && typeof window.message.error === "function") {
            window.message.error(text);
        } else if (window.layer && typeof window.layer.msg === "function") {
            window.layer.msg(text);
        }
    }

    function request(url, data) {
        return new Promise(function (resolve, reject) {
            $.ajax({
                url: url,
                method: "GET",
                data: data || {},
                dataType: "json"
            }).done(function (response) {
                if (response && response.code !== undefined && Number(response.code) !== 200) {
                    reject(response);
                    return;
                }

                resolve(response && response.data !== undefined ? response.data : response);
            }).fail(function (xhr) {
                reject(xhr && xhr.responseJSON ? xhr.responseJSON : xhr);
            });
        });
    }

    function errorText(error, fallback) {
        return error && error.msg ? String(error.msg) : fallback;
    }

    function isMobile() {
        return window.matchMedia
            ? window.matchMedia("(max-width: 767px), (max-height: 500px) and (max-width: 1024px)").matches
            : (window.innerWidth < 768 || (window.innerHeight <= 500 && window.innerWidth <= 1024));
    }

    function hashText(value) {
        var input = String(value || "");
        var hash = 0;

        for (var index = 0; index < input.length; index += 1) {
            hash = ((hash << 5) - hash) + input.charCodeAt(index);
            hash |= 0;
        }

        return String(hash);
    }

    function createStoreState(page) {
        return {
            page: page,
            tree: page.querySelector("[data-st-category-tree]"),
            list: page.querySelector(".item-list"),
            fallbackCover: page.getAttribute("data-fallback-cover") || "/favicon.ico",
            defaultCategory: String(page.getAttribute("data-default-category") || "").trim(),
            loggedIn: page.getAttribute("data-user-logged-in") === "1",
            showSold: page.getAttribute("data-show-sold") === "1",
            categories: [],
            categoryMap: Object.create(null),
            parentMap: Object.create(null),
            expanded: Object.create(null),
            activeCategory: "",
            mode: "category",
            requestId: 0,
            categoryRequestId: 0
        };
    }

    function walkCategories(state, nodes, parentId) {
        (Array.isArray(nodes) ? nodes : []).forEach(function (node) {
            if (!node || node.id === null || node.id === undefined) return;
            var id = String(node.id);
            state.categoryMap[id] = node;
            state.parentMap[id] = parentId || "";
            walkCategories(state, node.children, id);
        });
    }

    function hasChildren(node) {
        return Boolean(node && Array.isArray(node.children) && node.children.length);
    }

    function firstLeaf(nodes) {
        var list = Array.isArray(nodes) ? nodes : [];

        for (var index = 0; index < list.length; index += 1) {
            if (!hasChildren(list[index])) {
                return String(list[index].id);
            }

            var child = firstLeaf(list[index].children);
            if (child) {
                return child;
            }
        }

        return "";
    }

    function resolveLeafCategory(state, id) {
        var node = state && state.categoryMap ? state.categoryMap[String(id)] : null;
        if (!node) return "";
        return hasChildren(node) ? firstLeaf(node.children) : String(node.id);
    }

    function expandParents(state, id) {
        var parent = state.parentMap[String(id)];

        while (parent) {
            state.expanded[parent] = true;
            parent = state.parentMap[parent];
        }
    }

    function categoryIcon(state, node, parent) {
        if (String(node.id) === "recommend") {
            return '<span class="material-icons-outlined" aria-hidden="true">recommend</span>';
        }
        if (node.icon) {
            return '<img src="' + safeImageUrl(node.icon, state.fallbackCover) + '" alt="">';
        }
        return '<span class="material-icons-outlined" aria-hidden="true">' + (parent ? "folder" : "label") + "</span>";
    }

    function categoryMarkup(state, nodes, depth) {
        var currentDepth = Number(depth) || 0;

        return (Array.isArray(nodes) ? nodes : []).map(function (node) {
            if (!node || node.id === null || node.id === undefined) return "";
            var id = String(node.id);
            var parent = hasChildren(node);
            var count = node.commodity_count == null ? 0 : node.commodity_count;
            var icon = categoryIcon(state, node, parent);
            var name = escapeHtml(plainText(node.name));

            if (parent) {
                var expanded = Boolean(state.expanded[id]);
                return '<div class="st-category-node' + (expanded ? ' is-expanded' : '') + '" data-category-node="' + escapeHtml(id) + '" data-st-category-depth="' + currentDepth + '">' +
                    '<div class="st-category-parent-row">' +
                        '<button type="button" class="st-category-link st-category-parent-link st-category-toggle" data-st-category-toggle="' + escapeHtml(id) + '" data-st-category-depth="' + currentDepth + '" aria-expanded="' + (expanded ? "true" : "false") + '" aria-controls="st-category-children-' + escapeHtml(id) + '" aria-label="' + (expanded ? i18n("收起分类：") : i18n("展开分类：")) + name + '" title="' + name + '">' +
                            '<span class="st-category-icon">' + icon + "</span>" +
                            '<span class="st-category-name" data-st-category-name title="' + name + '">' + name + "</span>" +
                            '<span class="st-category-count">' + escapeHtml(count) + "</span>" +
                            '<span class="material-icons-outlined st-category-chevron" aria-hidden="true">expand_more</span>' +
                        "</button>" +
                    "</div>" +
                    '<div class="st-category-children" id="st-category-children-' + escapeHtml(id) + '" data-st-category-depth="' + (currentDepth + 1) + '" aria-hidden="' + (expanded ? 'false' : 'true') + '"' + (expanded ? "" : " hidden") + ">" + categoryMarkup(state, node.children, currentDepth + 1) + "</div>" +
                    "</div>";
            }

            var active = state.mode === "category" && state.activeCategory === id;
            return '<a class="switch-category st-category-link' + (active ? " is-primary" : "") + '" data-id="' + escapeHtml(id) + '" data-st-category-depth="' + currentDepth + '" href="/cat/' + encodeURIComponent(id) + '" title="' + name + '"' + (active ? ' aria-current="page"' : "") + ">" +
                '<span class="st-category-icon">' + icon + "</span>" +
                '<span class="st-category-name" data-st-category-name title="' + name + '">' + name + "</span>" +
                '<span class="st-category-count">' + escapeHtml(count) + "</span>" +
                '<span class="material-icons-outlined st-category-check" aria-hidden="true">check</span>' +
                "</a>";
        }).join("");
    }

    function renderCategories(state) {
        if (!state.tree || !document.documentElement.contains(state.page)) {
            return;
        }

        state.tree.innerHTML = state.categories.length
            ? categoryMarkup(state, state.categories, 0)
            : '<div class="st-feed-state"><span class="material-icons-outlined" aria-hidden="true">category</span><span>' + i18n('暂无可用分类') + '</span></div>';
    }

    function waitForMotion(element, done, transitionProperty) {
        var finished = false;
        var timeout;

        function cleanup() {
            if (finished) return;
            finished = true;
            window.clearTimeout(timeout);
            element.removeEventListener("animationend", finish);
            element.removeEventListener("transitionend", finish);
        }

        function finish(event) {
            if (event && event.target !== element) return;
            if (event && transitionProperty && (event.type !== "transitionend" || event.propertyName !== transitionProperty)) return;
            cleanup();
            if (typeof done === "function") done();
        }

        element.addEventListener("animationend", finish);
        element.addEventListener("transitionend", finish);
        timeout = window.setTimeout(finish, MOTION_FALLBACK);

        return cleanup;
    }

    function findCategoryNode(state, id) {
        var matched = null;
        if (!state || !state.tree) return null;
        state.tree.querySelectorAll("[data-category-node]").forEach(function (node) {
            if (!matched && node.getAttribute("data-category-node") === String(id)) matched = node;
        });
        return matched;
    }

    function setCategoryExpanded(state, id, expanded, animate) {
        var node = findCategoryNode(state, id);
        if (!node) return;

        var toggle = node.querySelector(":scope > .st-category-parent-row [data-st-category-toggle]");
        var children = node.querySelector(":scope > .st-category-children");
        if (!toggle || !children) return;

        var currentHeight = children.hidden ? 0 : Math.max(0, children.getBoundingClientRect().height);

        if (typeof children._stCategoryMotionCancel === "function") {
            children._stCategoryMotionCancel();
            children._stCategoryMotionCancel = null;
        }

        children.style.maxHeight = Math.ceil(currentHeight) + "px";
        children.classList.remove("is-opening", "is-closing");
        node.classList.remove("is-opening", "is-closing");
        toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
        var categoryName = node.querySelector(":scope > .st-category-parent-row [data-st-category-name]");
        toggle.setAttribute("aria-label", (expanded ? i18n("收起分类：") : i18n("展开分类：")) + (categoryName ? categoryName.textContent.trim() : i18n("子分类")));

        if (expanded) {
            children.hidden = false;
            children.setAttribute("aria-hidden", "false");
            node.classList.add("is-expanded");
            if (!animate) {
                children.style.removeProperty("max-height");
                return;
            }

            children.classList.add("is-opening");
            node.classList.add("is-opening");
            void children.offsetHeight;
            children.style.maxHeight = Math.ceil(Math.max(0, children.scrollHeight)) + "px";
            children._stCategoryMotionCancel = waitForMotion(children, function () {
                children.classList.remove("is-opening");
                node.classList.remove("is-opening");
                children.style.removeProperty("max-height");
                children._stCategoryMotionCancel = null;
            }, "max-height");
            return;
        }

        children.setAttribute("aria-hidden", "true");
        if (children.contains(document.activeElement)) {
            toggle.focus({preventScroll: true});
        }
        node.classList.remove("is-expanded");
        if (!animate || children.hidden) {
            children.hidden = true;
            children.style.removeProperty("max-height");
            return;
        }

        void children.offsetHeight;
        children.classList.add("is-closing");
        node.classList.add("is-closing");
        children.style.maxHeight = "0px";
        children._stCategoryMotionCancel = waitForMotion(children, function () {
            children.hidden = true;
            children.style.removeProperty("max-height");
            children.classList.remove("is-closing");
            node.classList.remove("is-closing");
            children._stCategoryMotionCancel = null;
        }, "max-height");
    }

    function syncCategoryTree(state, animateParents) {
        if (!state || !state.tree) return;

        state.tree.querySelectorAll(".switch-category[data-id]").forEach(function (link) {
            var id = link.getAttribute("data-id");
            var node = state.categoryMap[id];
            var active = !hasChildren(node) && state.mode === "category" && id === state.activeCategory;
            link.classList.toggle("is-primary", active);
            if (active) link.setAttribute("aria-current", "page");
            else link.removeAttribute("aria-current");
        });

        state.tree.querySelectorAll("[data-st-category-toggle]").forEach(function (toggle) {
            var id = toggle.getAttribute("data-st-category-toggle");
            setCategoryExpanded(state, id, Boolean(state.expanded[id]), Boolean(animateParents));
        });
    }

    function toggleCategory(state, id) {
        var categoryId = String(id);
        var node = state && state.categoryMap ? state.categoryMap[categoryId] : null;
        if (!state || (node ? !hasChildren(node) : !findCategoryNode(state, categoryId))) return false;

        state.expanded[categoryId] = !Boolean(state.expanded[categoryId]);
        setCategoryExpanded(state, categoryId, state.expanded[categoryId], true);
        return true;
    }

    function setCatalogTitle(state, title) {
        state.page.querySelectorAll("[data-st-catalog-title]").forEach(function (element) {
            element.textContent = title;
        });
    }

    //busy 用显式参数传：原来靠 title.indexOf("正在")===0 判断加载态，
    //title 翻译之后这个嗅探永远为 false，英文下屏幕阅读器读不到"正在加载"
    function renderListState(state, icon, title, detail, retry, busy) {
        if (!state.list) {
            return;
        }

        state.list.setAttribute("aria-busy", busy === undefined ? (icon === "spinner") : (busy ? "true" : "false"));
        state.list.innerHTML = '<div class="st-feed-state">' +
            (icon === "spinner" ? '<span class="st-spinner" aria-hidden="true"></span>' : '<span class="material-icons-outlined" aria-hidden="true">' + icon + "</span>") +
            "<div><strong>" + escapeHtml(title) + "</strong>" + (detail ? "<p>" + escapeHtml(detail) + "</p>" : "") + "</div>" +
            (retry ? '<button type="button" class="st-button st-button-quiet" data-st-store-retry>' + i18n('重新加载') + '</button>' : "") +
            "</div>";
    }

    function soldOut(item) {
        var stockState = Number(item.stock_state);
        return (Number.isFinite(stockState) && stockState <= 0)
            || String(item.stock) === "0"
            //后端 getHideStock() 已就地翻译,不能用中文子串判断;
            //而且 /售罄/ 会把「即将售罄」(还有货)也误判成售罄,这里改成整串相等。
            || String(item.stock || "") === i18n("已售罄");
    }

    //商品标签（#807）+ 推荐徽章，合并进同一行标签区
    function labelsMarkup(item) {
        var chips = "";
        var tags = item && Array.isArray(item.tags) ? item.tags : [];
        for (var i = 0; i < tags.length; i++) {
            var text = tags[i] && tags[i].text ? String(tags[i].text).trim() : "";
            if (!text) {
                continue;
            }
            var color = tags[i].color ? String(tags[i].color) : "red";
            chips += '<span class="st-tag st-tag--' + escapeHtml(color) + '">' + escapeHtml(text) + "</span>";
        }
        if (Number(item.recommend) === 1) {
            chips += '<span class="st-chip st-chip--primary">' + i18n('推荐') + "</span>";
        }
        return chips ? '<span class="st-product-labels">' + chips + "</span>" : "";
    }

    function productMarkup(state, item) {
        var unavailable = soldOut(item);
        var deliveryWay = Number(item.delivery_way);
        var stockLabel = plainText(item.stock) || i18n("待确认");
        var soldCount = Number(item.order_sold);
        var deliveryBadge = deliveryWay === 0
            ? '<span class="st-product-badge is-auto">' + i18n('自动发货') + '</span>'
            : (deliveryWay === 1 ? '<span class="st-product-badge is-online">' + i18n('在线发货') + '</span>' : "");
        soldCount = Number.isFinite(soldCount) && soldCount >= 0 ? Math.floor(soldCount) : 0;
        var price = Number(item.price);
        var memberPrice = Number(item.user_price);
        var showMemberPrice = !state.loggedIn
            && Number.isFinite(price)
            && Number.isFinite(memberPrice)
            && memberPrice < price;
        var itemId = String(item.id);
        var content = '<span class="st-product-icon"><img src="' + safeImageUrl(item.cover, state.fallbackCover) + '" alt="" width="44" height="44" loading="lazy"></span>' +
            '<span class="st-product-copy">' +
                labelsMarkup(item) +
                '<strong class="st-product-name">' + escapeHtml(plainText(item.name)) + "</strong>" +
                '<span class="st-product-meta">' +
                    deliveryBadge +
                    '<span class="st-product-badge is-stock' + (unavailable ? " is-empty" : "") + '">' + i18n('库存') + ' ' + escapeHtml(stockLabel) + "</span>" +
                    (state.showSold ? '<span class="st-product-badge is-sold">' + i18n('已售') + ' ' + escapeHtml(soldCount) + "</span>" : "") +
                "</span>" +
            "</span>" +
            '<span class="st-product-price"><strong><small>' + acgCurrencySymbol() + '</small>' + escapeHtml(formatPrice(item.price)) + "</strong>" +
                (showMemberPrice ? "<small>" + i18n("登录后") + " " + acgCurrencySymbol() + escapeHtml(formatPrice(item.user_price)) + "</small>" : "") +
                (unavailable ? '<span class="st-soldout">' + i18n('售罄') + '</span>' : "") +
            "</span>" +
            '<span class="st-product-action">' + (unavailable ? '<span class="material-icons-outlined" aria-hidden="true">block</span>' : '<span class="material-icons-outlined" aria-hidden="true">chevron_right</span>') + "</span>";

        if (unavailable) {
            return '<div class="st-product-row is-soldout" data-id="' + escapeHtml(itemId) + '" aria-disabled="true">' + content + "</div>";
        }

        return '<a class="st-product-row" data-id="' + escapeHtml(itemId) + '" href="/item/' + encodeURIComponent(itemId) + '">' + content + "</a>";
    }

    function renderProducts(state, items) {
        if (!state.list) {
            return;
        }

        var products = (Array.isArray(items) ? items : []).filter(function (item) {
            return Boolean(item && item.id !== null && item.id !== undefined);
        });
        if (!products.length) {
            renderListState(state, "inventory_2", i18n("这里还没有商品"), i18n("可切换分类或尝试其他搜索词。"), false);
            return;
        }

        state.list.setAttribute("aria-busy", "false");
        state.list.innerHTML = products.map(function (item) {
            return productMarkup(state, item);
        }).join("");
    }

    function loadProducts(state, parameters, title, done) {
        var requestId = ++state.requestId;
        setCatalogTitle(state, title);
        renderListState(state, "spinner", i18n("正在读取商品"), i18n("请稍候。"), false);

        return request("/user/api/index/commodity", parameters).then(function (items) {
            if (requestId !== state.requestId || activeStore !== state || !document.documentElement.contains(state.page)) {
                return;
            }

            renderProducts(state, items);
            if (typeof done === "function") {
                done();
            }
        }).catch(function (error) {
            if (requestId !== state.requestId || activeStore !== state) {
                return;
            }

            var text = errorText(error, i18n("商品加载失败，请稍后重试。"));
            renderListState(state, "error_outline", i18n("无法读取商品"), text, true);
            notifyError(text);
        });
    }

    function categoryRoute(id) {
        return id && id !== "0" ? "/cat/" + encodeURIComponent(id) : "/";
    }

    function syncSearchClear(scope) {
        var root = scope && scope.querySelector ? scope : document;
        var forms = Array.prototype.slice.call(root.querySelectorAll("[data-st-store-search]"));
        if (root.matches && root.matches("[data-st-store-search]")) forms.unshift(root);
        forms.forEach(function (form) {
            var input = form.querySelector(".item-search-input");
            var clear = form.querySelector(".st-search-clear");
            if (input && clear) clear.hidden = String(input.value || "").length === 0;
        });
    }

    function loadCategory(state, id, pushHistory) {
        var categoryId = state.categoryMap[String(id)] ? String(id) : "";
        var node = categoryId ? state.categoryMap[categoryId] : null;
        if (!node || hasChildren(node)) return false;

        var searchInput = state.page.querySelector(".item-search-input");
        if (searchInput) {
            searchInput.value = "";
            syncSearchClear(state.page);
        }
        state.mode = "category";
        state.activeCategory = categoryId;
        expandParents(state, categoryId);
        syncCategoryTree(state, false);

        loadProducts(state, {categoryId: categoryId}, plainText(node.name) || i18n("全部商品"), function () {
            if (pushHistory) {
                window.history.pushState({seattleCategory: categoryId}, "", categoryRoute(categoryId));
            }
        });
        return true;
    }

    function searchProducts(state, keywords, pushHistory) {
        var value = String(keywords || "").trim();
        if (!value) {
            notifyError(i18n("请输入要搜索的商品名称"));
            return;
        }

        state.mode = "search";
        syncCategoryTree(state, false);
        loadProducts(state, {keywords: value}, i18n("搜索结果"), function () {
            if (pushHistory) {
                window.history.pushState({seattleSearch: value}, "", "/?q=" + encodeURIComponent(value));
            }
        });
    }

    function locationCategory() {
        var matched = window.location.pathname.match(/^\/cat\/([^/]+)\/?$/);
        if (!matched) return "";
        try {
            return decodeURIComponent(matched[1]);
        } catch (error) {
            return "";
        }
    }

    function locationSearch() {
        try {
            return new URLSearchParams(window.location.search).get("q") || "";
        } catch (error) {
            return "";
        }
    }

    function loadLocation(state) {
        var keywords = locationSearch();
        if (keywords) {
            var input = state.page.querySelector(".item-search-input");
            if (input) {
                input.value = keywords;
                syncSearchClear(state.page);
            }
            searchProducts(state, keywords, false);
            return;
        }

        var routeCategory = locationCategory();
        var requested = routeCategory || state.defaultCategory;
        var initial = resolveLeafCategory(state, requested) || firstLeaf(state.categories);
        if (initial) {
            loadCategory(state, initial, false);
            if (routeCategory && initial !== routeCategory) {
                window.history.replaceState({seattleCategory: initial}, "", categoryRoute(initial));
            }
        } else {
            setCatalogTitle(state, i18n("全部商品"));
            renderListState(state, "inventory_2", i18n("暂无可用商品"), i18n("店铺还没有建立商品分类。"), false);
        }
    }

    function loadCategories(state) {
        if (!state.tree) {
            return;
        }

        var categoryRequestId = ++state.categoryRequestId;
        state.tree.setAttribute("aria-busy", "true");
        state.tree.inert = true;
        request("/user/api/index/data").then(function (categories) {
            if (categoryRequestId !== state.categoryRequestId
                || activeStore !== state
                || !document.documentElement.contains(state.page)) {
                return;
            }

            state.categories = Array.isArray(categories) ? categories : [];
            state.categoryMap = Object.create(null);
            state.parentMap = Object.create(null);
            state.expanded = Object.create(null);
            walkCategories(state, state.categories, "");
            state.tree.setAttribute("aria-busy", "false");
            state.tree.inert = false;
            renderCategories(state);
            loadLocation(state);
        }).catch(function (error) {
            if (categoryRequestId !== state.categoryRequestId || activeStore !== state) {
                return;
            }

            var text = errorText(error, i18n("分类加载失败，请刷新页面重试。"));
            state.tree.setAttribute("aria-busy", "false");
            state.tree.inert = false;
            state.tree.innerHTML = '<div class="st-feed-state"><span class="material-icons-outlined" aria-hidden="true">error_outline</span><div><strong>' + i18n('无法读取分类') + '</strong><p>' + escapeHtml(text) + '</p></div><button type="button" class="st-button st-button-quiet" data-st-category-retry>' + i18n('重新加载') + '</button></div>';
            renderListState(state, "error_outline", i18n("无法读取商品"), text, true);
        });
    }

    function syncStoreOverlayLock() {
        var body = document.body;
        var open = body.classList.contains("st-filter-open")
            || body.classList.contains("st-payment-open")
            || body.classList.contains("st-notice-open");

        body.classList.toggle("st-store-overlay-open", open);
        if (open && !storeScrollLock) {
            storeScrollLock = {overflow: body.style.overflow};
            body.style.overflow = "hidden";
        } else if (!open && storeScrollLock) {
            body.style.overflow = storeScrollLock.overflow;
            storeScrollLock = null;
        }
    }

    function setFilterOpen(open, options) {
        var settings = options || {};
        var state = activeStore;
        var mobileOpen = Boolean(state && open && isMobile());

        if (mobileOpen) {
            setPaymentOpen(false, {skipLock: true});
            closeNotice({immediate: true, skipLock: true});
        }

        var drawer = state ? state.page.querySelector(".st-category-rail") : null;
        var triggers = document.querySelectorAll(".st-filter-trigger");
        if (state) state.page.classList.toggle("is-filter-open", mobileOpen);
        document.body.classList.toggle("st-filter-open", mobileOpen);
        if (drawer) {
            drawer.classList.toggle("is-open", mobileOpen);
            drawer.setAttribute("aria-hidden", isMobile() && !mobileOpen ? "true" : "false");
            drawer.inert = Boolean(isMobile() && !mobileOpen);
        }
        triggers.forEach(function (trigger) {
            trigger.setAttribute("aria-expanded", mobileOpen ? "true" : "false");
        });
        document.querySelectorAll("[data-st-category-nav]").forEach(function (link) {
            link.classList.toggle("is-active", mobileOpen);
            if (drawer && drawer.id) {
                link.setAttribute("aria-controls", drawer.id);
                link.setAttribute("aria-expanded", mobileOpen ? "true" : "false");
            } else {
                link.removeAttribute("aria-controls");
                link.removeAttribute("aria-expanded");
            }
        });
        document.querySelectorAll('[data-st-bottom="store"]').forEach(function (link) {
            if (!link.hasAttribute("data-st-store-current")) {
                var path = window.location.pathname || "/";
                link.setAttribute("data-st-store-current", path === "/" || /^\/(cat|item)\//.test(path) ? "1" : "0");
            }
            var current = link.getAttribute("data-st-store-current") === "1";
            link.classList.toggle("is-active", current && !mobileOpen);
            if (current && !mobileOpen) link.setAttribute("aria-current", "page");
            else link.removeAttribute("aria-current");
        });
        if (!settings.skipLock) syncStoreOverlayLock();
    }

    function setPaymentOpen(open, options) {
        var settings = options || {};
        var checkout = document.querySelector(".st-item-checkout");
        var mobileOpen = Boolean(open && checkout && isMobile());

        if (mobileOpen) {
            setFilterOpen(false, {skipLock: true});
            closeNotice({immediate: true, skipLock: true});
        }

        if (!checkout) {
            document.body.classList.remove("st-payment-open");
            if (!settings.skipLock) syncStoreOverlayLock();
            return;
        }

        var drawer = checkout.querySelector(".st-payment-drawer");
        var trigger = checkout.querySelector("[data-st-payment-open]");
        checkout.classList.toggle("is-payment-open", mobileOpen);
        document.body.classList.toggle("st-payment-open", mobileOpen);
        if (drawer) {
            drawer.setAttribute("aria-hidden", isMobile() && !mobileOpen ? "true" : "false");
            drawer.inert = Boolean(isMobile() && !mobileOpen);
        }
        if (trigger) {
            trigger.setAttribute("aria-expanded", mobileOpen ? "true" : "false");
        }
        if (!settings.skipLock) syncStoreOverlayLock();
    }

    document.addEventListener("seattle:close-store-overlays", function () {
        setFilterOpen(false, {skipLock: true});
        setPaymentOpen(false, {skipLock: true});
        closeNotice({immediate: true, skipLock: true});
        syncStoreOverlayLock();
    });

    function noticeDialog() {
        return document.querySelector("[data-st-notice]");
    }

    function noticeSignature(dialog) {
        var content = dialog ? dialog.querySelector("[data-st-notice-content]") : null;
        return hashText(content ? content.innerHTML : "");
    }

    function noticeSuppressed(dialog) {
        try {
            var stored = JSON.parse(window.localStorage.getItem(NOTICE_KEY) || "null");
            return Boolean(stored
                && stored.signature === noticeSignature(dialog)
                && Number(stored.expiresAt) > Date.now());
        } catch (error) {
            return false;
        }
    }

    function rememberNotice(dialog) {
        try {
            window.localStorage.setItem(NOTICE_KEY, JSON.stringify({
                signature: noticeSignature(dialog),
                expiresAt: Date.now() + NOTICE_TTL
            }));
        } catch (error) {
        }
    }

    function openNotice(force) {
        var dialog = noticeDialog();
        if (!dialog || (!force && noticeSuppressed(dialog))) {
            return;
        }

        setFilterOpen(false, {skipLock: true});
        setPaymentOpen(false, {skipLock: true});

        if (typeof noticeMotionCancel === "function") noticeMotionCancel();
        noticeMotionCancel = null;
        dialog.classList.remove("is-closing");

        var wasOpen = Boolean(dialog.open || dialog.hasAttribute("open"));

        if (typeof dialog.showModal === "function") {
            if (!dialog.open) dialog.showModal();
        } else {
            dialog.setAttribute("open", "open");
        }
        dialog.classList.add("is-open");
        document.body.classList.add("st-notice-open");

        if (!wasOpen) {
            dialog.classList.add("is-opening");
            noticeMotionCancel = waitForMotion(dialog, function () {
                dialog.classList.remove("is-opening");
                noticeMotionCancel = null;
            });
        }
        syncStoreOverlayLock();
    }

    function finalizeNoticeClose(dialog, skipLock) {
        if (typeof dialog.close === "function" && dialog.open) dialog.close();
        else dialog.removeAttribute("open");
        dialog.classList.remove("is-open", "is-opening", "is-closing");
        document.body.classList.remove("st-notice-open");
        noticeMotionCancel = null;
        if (!skipLock) syncStoreOverlayLock();
    }

    function closeNotice(options) {
        var settings = options && options.type ? {} : (options || {});
        var dialog = noticeDialog();
        if (!dialog) {
            document.body.classList.remove("st-notice-open");
            if (!settings.skipLock) syncStoreOverlayLock();
            return;
        }

        if (typeof noticeMotionCancel === "function") noticeMotionCancel();
        noticeMotionCancel = null;
        dialog.classList.remove("is-opening");

        if (settings.immediate || (!dialog.open && !dialog.hasAttribute("open"))) {
            finalizeNoticeClose(dialog, settings.skipLock);
            return;
        }

        dialog.classList.add("is-closing");
        noticeMotionCancel = waitForMotion(dialog, function () {
            finalizeNoticeClose(dialog, settings.skipLock);
        });
    }

    function syncPayOptions() {
        document.querySelectorAll(".st-pay-list .pay").forEach(function (option) {
            option.classList.add("st-pay-option");
            option.setAttribute("role", "button");
            option.setAttribute("tabindex", "0");
            var image = option.querySelector("img");
            if (image) {
                image.alt = "";
                image.width = 28;
                image.height = 28;
            }
        });
    }

    function enhanceItemPage() {
        document.querySelectorAll(".st-item-fields > div").forEach(function (field) {
            var directControl = field.querySelector(":scope > input, :scope > select, :scope > textarea");
            var choiceControl = field.querySelector(":scope > div input[type='checkbox'], :scope > div input[type='radio']");
            field.classList.toggle("st-field", Boolean(directControl));
            field.classList.toggle("st-widget-field", Boolean(directControl));
            field.classList.toggle("st-widget-choice-field", Boolean(!directControl && choiceControl));
        });
        if (window.SeattleTheme && typeof window.SeattleTheme.enhanceFields === "function") {
            window.SeattleTheme.enhanceFields(document.querySelector(".st-item-fields") || document);
        }

        if (payObserver) {
            payObserver.disconnect();
            payObserver = null;
        }

        var payList = document.querySelector(".st-pay-list");
        var paymentDrawer = document.querySelector(".st-item-checkout .st-payment-drawer");
        if (paymentDrawer) {
            var paymentOpen = Boolean(document.querySelector(".st-item-checkout.is-payment-open"));
            paymentDrawer.setAttribute("aria-hidden", isMobile() && !paymentOpen ? "true" : "false");
            paymentDrawer.inert = Boolean(isMobile() && !paymentOpen);
        }
        if (payList && window.MutationObserver) {
            syncPayOptions();
            payObserver = new MutationObserver(syncPayOptions);
            payObserver.observe(payList, {childList: true, subtree: true});
        }
    }

    function bootPage() {
        if (noticeTimer) {
            window.clearTimeout(noticeTimer);
            noticeTimer = null;
        }

        var page = document.querySelector("[data-st-store-page]");
        if (page && page.getAttribute("data-st-store-ready") !== "true") {
            page.setAttribute("data-st-store-ready", "true");
            activeStore = createStoreState(page);
            syncSearchClear(page);
            loadCategories(activeStore);
            setFilterOpen(window.location.hash === "#categories");
            if (noticeDialog() && window.location.hash !== "#categories") {
                noticeTimer = window.setTimeout(function () {
                    openNotice(false);
                }, 240);
            }
        } else if (!page) {
            activeStore = null;
            setFilterOpen(false, {skipLock: true});
            if (!noticeDialog()) closeNotice({immediate: true, skipLock: true});
        }

        if (!document.querySelector("[data-st-item-page]")) {
            setPaymentOpen(false, {skipLock: true});
        }
        enhanceItemPage();
        syncStoreOverlayLock();
    }

    function bindEvents() {
        $(document)
            .off("click.seattleStoreCategory", ".switch-category[data-id]")
            .on("click.seattleStoreCategory", ".switch-category[data-id]", function (event) {
                if (!activeStore || !activeStore.page.contains(this)) {
                    return;
                }
                event.preventDefault();
                var id = String(this.getAttribute("data-id"));
                var node = activeStore.categoryMap[id];
                if (hasChildren(node)) {
                    toggleCategory(activeStore, id);
                    return;
                }
                if (loadCategory(activeStore, id, true)) {
                    setFilterOpen(false);
                }
            })
            .off("click.seattleStoreToggle", "[data-st-category-toggle]")
            .on("click.seattleStoreToggle", "[data-st-category-toggle]", function (event) {
                if (!activeStore || !activeStore.page.contains(this)) {
                    return;
                }
                event.preventDefault();
                toggleCategory(activeStore, String(this.getAttribute("data-st-category-toggle")));
            })
            .off("submit.seattleStoreSearch", "[data-st-store-search]")
            .on("submit.seattleStoreSearch", "[data-st-store-search]", function (event) {
                event.preventDefault();
                if (activeStore) {
                    searchProducts(activeStore, $(this).find(".item-search-input").val(), true);
                    setFilterOpen(false);
                }
            })
            .off("input.seattleStoreSearchClear", "[data-st-store-search] .item-search-input")
            .on("input.seattleStoreSearchClear", "[data-st-store-search] .item-search-input", function () {
                syncSearchClear(this.closest("[data-st-store-search]"));
            })
            .off("click.seattleStoreSearchClear", "[data-st-store-search] .st-search-clear")
            .on("click.seattleStoreSearchClear", "[data-st-store-search] .st-search-clear", function () {
                var form = this.closest("[data-st-store-search]");
                var input = form && form.querySelector(".item-search-input");
                if (!input) return;
                input.value = "";
                syncSearchClear(form);
                input.focus();
                if (activeStore && activeStore.mode === "search") {
                    window.history.pushState({seattleSearch: ""}, "", "/");
                    loadLocation(activeStore);
                }
            })
            .off("click.seattleStoreFilterOpen", ".st-filter-trigger")
            .on("click.seattleStoreFilterOpen", ".st-filter-trigger", function () {
                setFilterOpen(true);
            })
            .off("click.seattleStoreCategoryNav", "[data-st-category-nav]")
            .on("click.seattleStoreCategoryNav", "[data-st-category-nav]", function (event) {
                if (!activeStore) return;
                event.preventDefault();
                setFilterOpen(true);
            })
            .off("click.seattleStoreFilterClose", "[data-st-filter-close]")
            .on("click.seattleStoreFilterClose", "[data-st-filter-close]", function () {
                setFilterOpen(false);
            })
            .off("click.seattlePaymentOpen", "[data-st-payment-open]")
            .on("click.seattlePaymentOpen", "[data-st-payment-open]", function () {
                setPaymentOpen(true);
            })
            .off("click.seattlePaymentClose", "[data-st-payment-close]")
            .on("click.seattlePaymentClose", "[data-st-payment-close]", function () {
                setPaymentOpen(false);
            })
            .off("click.seattlePaymentOption", ".st-pay-list .pay")
            .on("click.seattlePaymentOption", ".st-pay-list .pay", function () {
                setPaymentOpen(false);
            })
            .off("click.seattlePaymentChoice", ".switch-race, .switch-sku")
            .on("click.seattlePaymentChoice", ".switch-race, .switch-sku", function () {
                setPaymentOpen(false);
            })
            .off("click.seattleStoreRetry", "[data-st-store-retry]")
            .on("click.seattleStoreRetry", "[data-st-store-retry]", function () {
                if (!activeStore) {
                    return;
                }
                if (activeStore.mode === "search") {
                    searchProducts(activeStore, activeStore.page.querySelector(".item-search-input").value, false);
                } else if (activeStore.activeCategory) {
                    loadCategory(activeStore, activeStore.activeCategory, false);
                } else {
                    loadCategories(activeStore);
                }
            })
            .off("click.seattleCategoryRetry", "[data-st-category-retry]")
            .on("click.seattleCategoryRetry", "[data-st-category-retry]", function () {
                if (activeStore) {
                    loadCategories(activeStore);
                }
            })
            .off("click.seattleNoticeOpen", "[data-st-notice-open]")
            .on("click.seattleNoticeOpen", "[data-st-notice-open]", function () {
                openNotice(true);
            })
            .off("click.seattleNoticeClose", "[data-st-notice-close]")
            .on("click.seattleNoticeClose", "[data-st-notice-close]", closeNotice)
            .off("click.seattleNoticeAck", "[data-st-notice-ack]")
            .on("click.seattleNoticeAck", "[data-st-notice-ack]", function () {
                var dialog = noticeDialog();
                if (dialog) {
                    rememberNotice(dialog);
                }
                closeNotice();
            })
            .off("click.seattlePassword", ".st-password-toggle")
            .on("click.seattlePassword", ".st-password-toggle", function () {
                var input = this.parentElement ? this.parentElement.querySelector("input") : null;
                if (!input) {
                    return;
                }
                var show = input.type === "password";
                input.type = show ? "text" : "password";
                this.setAttribute("aria-label", show ? i18n("隐藏密码") : i18n("显示密码"));
                var icon = this.querySelector(".material-icons-outlined");
                if (icon) {
                    icon.textContent = show ? "visibility_off" : "visibility";
                }
            })
            .off("keydown.seattlePay", ".st-pay-list .pay")
            .on("keydown.seattlePay", ".st-pay-list .pay", function (event) {
                if (event.key === "Enter" || event.key === " ") {
                    event.preventDefault();
                    this.click();
                }
            })
            .off("keydown.seattleStoreOverlay")
            .on("keydown.seattleStoreOverlay", function (event) {
                if (event.key === "Escape") {
                    if (noticeDialog() && (noticeDialog().open || noticeDialog().hasAttribute("open"))) {
                        event.preventDefault();
                    }
                    setFilterOpen(false);
                    setPaymentOpen(false);
                    closeNotice();
                }
            })
            .off("cancel.seattleNotice", "[data-st-notice]")
            .on("cancel.seattleNotice", "[data-st-notice]", function (event) {
                event.preventDefault();
                closeNotice();
            })
            .off("close.seattleNotice", "[data-st-notice]")
            .on("close.seattleNotice", "[data-st-notice]", function () {
                this.classList.remove("is-open", "is-opening", "is-closing");
                document.body.classList.remove("st-notice-open");
                syncStoreOverlayLock();
            })
            .off("click.seattleDialogBackdrop", "[data-st-notice]")
            .on("click.seattleDialogBackdrop", "[data-st-notice]", function (event) {
                if (event.target === this) {
                    closeNotice();
                }
            })
            .off("click.seattleThemeMenu", "[data-theme-toggle]")
            .on("click.seattleThemeMenu", "[data-theme-toggle]", function () {
                var menu = this.closest ? this.closest("details") : null;
                if (menu) {
                    menu.removeAttribute("open");
                }
            })
            .off("click.seattleMenus")
            .on("click.seattleMenus", function (event) {
                if (!event.target.closest || !event.target.closest(".st-theme-menu")) {
                    document.querySelectorAll(".st-theme-menu[open]").forEach(function (menu) {
                        menu.removeAttribute("open");
                    });
                }
                if (!event.target.closest || !event.target.closest(".st-account-menu")) {
                    document.querySelectorAll(".st-account-menu[open]").forEach(function (menu) {
                        menu.removeAttribute("open");
                    });
                }
            });

        $(window)
            .off("resize.seattleStore")
            .on("resize.seattleStore", function () {
                setPaymentOpen(false);
                if (!isMobile()) {
                    setFilterOpen(false);
                }
                if (activeStore) syncCategoryTree(activeStore, false);
            })
            .off("popstate.seattleStore")
            .on("popstate.seattleStore", function () {
                if (activeStore) {
                    loadLocation(activeStore);
                }
            })
            .off("hashchange.seattleStore")
            .on("hashchange.seattleStore", function () {
                if (activeStore) {
                    setFilterOpen(window.location.hash === "#categories");
                }
            });

        $(document)
            .off("pjax:complete.seattleStore pjax:end.seattleStore")
            .on("pjax:end.seattleStore", bootPage);
    }

    bindEvents();
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", bootPage, {once: true});
    } else {
        bootPage();
    }
}(window.jQuery));
