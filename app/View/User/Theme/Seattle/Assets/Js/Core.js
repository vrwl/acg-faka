(function () {
    'use strict';

    var root = document.documentElement;
    var body = document.body;
    var storageKey = root.getAttribute('data-theme-storage-key') || 'seattle.theme.preference';
    var allowedThemes = ['auto', 'light', 'dark'];
    var systemTheme = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var themeTransitionTimer = null;
    var pageTransitionTimer = null;
    var pageInitTimer = null;
    var notificationTimer = null;
    var notificationRemovalTimer = null;
    var fieldEnhanceFrame = null;
    var fieldObserver = null;
    var navScrollKey = 'seattle.member.nav.scroll.v1';
    var navScrollFrame = null;
    var activeSheetOpener = null;

    function isAppViewport() {
        return window.innerWidth < 768 || (window.innerHeight <= 500 && window.innerWidth <= 1024);
    }

    function query(selector, scope) {
        return (scope || document).querySelector(selector);
    }

    function queryAll(selector, scope) {
        return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
    }

    function sanitizeInlineHtml(value) {
        var template = document.createElement('template');
        var allowedTags = ['B', 'STRONG', 'I', 'EM', 'U', 'S', 'DEL', 'SMALL', 'SUB', 'SUP', 'BR', 'SPAN', 'MARK', 'FONT', 'CODE'];
        var blockedTags = ['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'FORM', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA', 'LINK', 'META', 'IMG', 'VIDEO', 'AUDIO', 'CANVAS'];
        var allowedStyles = ['color', 'background-color', 'font-weight', 'font-style', 'text-decoration'];
        template.innerHTML = String(value == null ? '' : value);

        function safeStyleValue(value) {
            var text = String(value == null ? '' : value).trim();
            return text.length <= 80 && !/[<>"'`\\]/.test(text) && !/(?:url|expression|javascript|data)\s*\(/i.test(text) ? text : '';
        }

        function walk(node) {
            Array.prototype.slice.call(node.childNodes).forEach(function (child) {
                if (child.nodeType === Node.COMMENT_NODE) {
                    child.remove();
                    return;
                }
                if (child.nodeType !== Node.ELEMENT_NODE) return;
                if (blockedTags.indexOf(child.tagName) >= 0) {
                    child.remove();
                    return;
                }
                if (allowedTags.indexOf(child.tagName) < 0) {
                    walk(child);
                    child.replaceWith.apply(child, Array.prototype.slice.call(child.childNodes));
                    return;
                }

                var styles = [];
                if (child.hasAttribute('style')) {
                    Array.prototype.slice.call(child.style).forEach(function (property) {
                        property = String(property || '').toLowerCase();
                        if (allowedStyles.indexOf(property) < 0) return;
                        var styleValue = safeStyleValue(child.style.getPropertyValue(property));
                        if (styleValue) styles.push(property + ': ' + styleValue);
                    });
                }
                if (child.tagName === 'FONT' && child.hasAttribute('color')) {
                    var colorProbe = document.createElement('span');
                    colorProbe.style.color = String(child.getAttribute('color') || '');
                    var fontColor = safeStyleValue(colorProbe.style.color);
                    if (fontColor) styles.push('color: ' + fontColor);
                }
                Array.prototype.slice.call(child.attributes).forEach(function (attribute) {
                    child.removeAttribute(attribute.name);
                });
                if (styles.length) child.setAttribute('style', styles.join('; '));
                walk(child);
            });
        }

        walk(template.content);
        return template.innerHTML;
    }

    function inlinePlainText(value) {
        var template = document.createElement('template');
        template.innerHTML = sanitizeInlineHtml(value);
        return String(template.content.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function enhanceInlineHtml(scope) {
        queryAll('[data-st-inline-html]:not([data-st-inline-ready])', scope || document).forEach(function (element) {
            element.innerHTML = sanitizeInlineHtml(element.textContent || '');
            element.setAttribute('data-st-inline-ready', 'true');
        });
    }

    function currentPreference() {
        var value = root.getAttribute('data-theme-preference');
        return allowedThemes.indexOf(value) >= 0 ? value : 'auto';
    }

    function resolveTheme(preference) {
        return preference === 'dark' || (preference === 'auto' && systemTheme && systemTheme.matches) ? 'dark' : 'light';
    }

    function applyTheme(preference, persist) {
        if (allowedThemes.indexOf(preference) < 0) preference = 'auto';
        var theme = resolveTheme(preference);
        if (persist) {
            root.classList.add('st-theme-transition');
            window.clearTimeout(themeTransitionTimer);
            themeTransitionTimer = window.setTimeout(function () { root.classList.remove('st-theme-transition'); }, 220);
        }
        root.setAttribute('data-theme-preference', preference);
        root.setAttribute('data-theme', theme);
        root.style.colorScheme = theme;
        queryAll('[data-theme-toggle]').forEach(function (button) {
            var active = button.getAttribute('data-theme-toggle') === preference;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        if (persist) {
            try { window.localStorage.setItem(storageKey, preference); } catch (error) {}
        }
    }

    function sheetFocusables(sheet) {
        return queryAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', sheet).filter(function (element) {
            return element.getClientRects().length > 0 && element.getAttribute('aria-hidden') !== 'true';
        });
    }

    function focusSheet(sheet) {
        if (!sheet || !sheet.classList.contains('is-open')) return;
        var selected = query('[data-theme-toggle].is-active', sheet);
        var focusable = selected || sheetFocusables(sheet)[0];
        if (focusable) focusable.focus({preventScroll: true});
    }

    function setSheet(name, open, opener) {
        var sheet = query('[data-st-sheet="' + name + '"]');
        if (!sheet) return;
        if (open) {
            document.dispatchEvent(new CustomEvent('seattle:close-store-overlays'));
            activeSheetOpener = opener || document.activeElement;
        }
        closeSheets(sheet);
        sheet.classList.toggle('is-open', open);
        sheet.setAttribute('aria-hidden', open ? 'false' : 'true');
        queryAll('[data-st-sheet-open="' + name + '"]').forEach(function (button) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        body.classList.toggle('st-sheet-open', open);
        var backdrop = query('.st-sheet-backdrop');
        if (backdrop) backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) window.setTimeout(function () { focusSheet(sheet); }, 40);
    }

    function closeSheets(except) {
        var closed = false;
        queryAll('[data-st-sheet].is-open').forEach(function (sheet) {
            if (sheet === except) return;
            closed = true;
            sheet.classList.remove('is-open');
            sheet.setAttribute('aria-hidden', 'true');
            var name = sheet.getAttribute('data-st-sheet');
            if (name) queryAll('[data-st-sheet-open="' + name + '"]').forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
        });
        if (!except) {
            body.classList.remove('st-sheet-open');
            var backdrop = query('.st-sheet-backdrop');
            if (backdrop) backdrop.setAttribute('aria-hidden', 'true');
            if (closed && activeSheetOpener && activeSheetOpener.isConnected) {
                var opener = activeSheetOpener;
                window.setTimeout(function () { opener.focus({preventScroll: true}); }, 0);
            }
            activeSheetOpener = null;
        }
    }

    function syncNavigationAccessibility(open) {
        var mobile = isAppViewport();
        queryAll('.st-nav').forEach(function (navigation) {
            var hidden = mobile && !open;
            navigation.setAttribute('aria-hidden', hidden ? 'true' : 'false');
            navigation.inert = hidden;
        });
    }

    function setNavigation(open) {
        var expanded = Boolean(open && isAppViewport());
        body.classList.toggle('st-nav-open', expanded);
        queryAll('[data-st-nav-toggle]').forEach(function (button) {
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
        syncNavigationAccessibility(expanded);
    }

    function readNavScroll() {
        try {
            var raw = window.sessionStorage.getItem(navScrollKey);
            if (raw === null) return null;
            var value = Number(raw);
            return Number.isFinite(value) && value >= 0 ? value : null;
        } catch (error) {
            return null;
        }
    }

    function saveNavScroll(navigation) {
        var nav = navigation || query('.st-nav');
        if (!nav) return;
        try { window.sessionStorage.setItem(navScrollKey, String(Math.max(0, nav.scrollTop || 0))); } catch (error) {}
    }

    function initialNavScroll(navigation) {
        var stored = readNavScroll();
        if (stored !== null) return stored;
        var active = query('.st-nav__group > a.is-active', navigation);
        if (!active) return 0;
        var inset = 16;
        var top = active.offsetTop - inset;
        var bottom = active.offsetTop + active.offsetHeight + inset;
        if (top < navigation.scrollTop) return top;
        if (bottom > navigation.scrollTop + navigation.clientHeight) return bottom - navigation.clientHeight;
        return navigation.scrollTop;
    }

    function applyNavScroll(navigation, value) {
        if (!navigation || !navigation.isConnected) return;
        var maximum = Math.max(0, navigation.scrollHeight - navigation.clientHeight);
        navigation.scrollTop = Math.max(0, Math.min(Number(value) || 0, maximum));
    }

    function restoreNavScroll(navigation) {
        if (!navigation) return;
        var target = initialNavScroll(navigation);
        applyNavScroll(navigation, target);
        window.requestAnimationFrame(function () {
            applyNavScroll(navigation, target);
            window.requestAnimationFrame(function () { applyNavScroll(navigation, target); });
        });
    }

    function bindNavScroll(navigation) {
        if (!navigation || navigation.getAttribute('data-st-scroll-bound') === '1') return;
        navigation.setAttribute('data-st-scroll-bound', '1');
        navigation.addEventListener('scroll', function () {
            var current = navigation;
            if (navScrollFrame) window.cancelAnimationFrame(navScrollFrame);
            navScrollFrame = window.requestAnimationFrame(function () {
                navScrollFrame = null;
                saveNavScroll(current);
            });
        }, {passive: true});
    }

    function syncBrandSubtitle() {
        var subtitle = query('.st-brand small');
        if (!subtitle) return;
        var pageTitle = String(document.title || '').split(/\s+-\s+/)[0].trim();
        subtitle.textContent = pageTitle || i18n('会员中心');
    }

    function setMessagePopover(open) {
        var popover = query('[data-st-message-popover]');
        var button = query('[data-st-message-toggle]');
        if (!popover || !button) return;
        popover.classList.toggle('is-open', open);
        popover.setAttribute('aria-hidden', open ? 'false' : 'true');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closeHeaderMenus(except) {
        queryAll('.st-theme-menu[open], .st-account-menu[open]').forEach(function (menu) {
            if (menu !== except) menu.open = false;
        });
    }

    function routeGroup(pathname) {
        if (pathname.indexOf('/user/index/query') === 0) return 'query';
        if (pathname.indexOf('/user/authentication') === 0) return 'account';
        if (pathname === '/' || /^\/(cat|item)\//.test(pathname)) return 'store';
        if (pathname.indexOf('/user/personal') === 0) return 'orders';
        if (/^\/user\/(recharge|bill|cash)/.test(pathname)) return 'wallet';
        if (/^\/user\/(business|category|commodity|card|coupon|order)/.test(pathname)) return 'business';
        if (/^\/user\/(dashboard|security|agent|message|ticket)/.test(pathname)) return 'account';
        return query('[data-st-bottom="account"]') ? 'account' : 'store';
    }

    function syncNavigation() {
        var group = routeGroup(window.location.pathname);
        if (!query('[data-st-bottom="' + group + '"]')) {
            group = '';
        }
        queryAll('[data-st-bottom]').forEach(function (link) {
            var active = link.getAttribute('data-st-bottom') === group;
            link.classList.toggle('is-active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    }

    function isCrossShellLink(link) {
        if (!link || !link.href || link.target === '_blank' || link.hasAttribute('download')) return false;
        var url;
        try { url = new URL(link.href, window.location.href); } catch (error) { return false; }
        if (url.origin !== window.location.origin) return false;
        var member = body.classList.contains('st-member-body');
        var store = body.classList.contains('st-store-body');
        var auth = body.classList.contains('st-auth-body');
        var targetStore = url.pathname === '/' || /^\/(cat|item)\//.test(url.pathname) || url.pathname.indexOf('/user/index') === 0;
        var targetAuth = url.pathname.indexOf('/user/authentication') === 0;
        var targetMember = url.pathname.indexOf('/user/') === 0 && !targetStore && !targetAuth;
        return (member && (targetStore || targetAuth)) || (store && (targetAuth || targetMember)) || (auth && !targetAuth);
    }

    function hideLoading() {
        if (window.Loading && typeof window.Loading.hide === 'function') window.Loading.hide();
        if (window.jQuery) window.jQuery('.net-loading').hide();
    }

    function removeNotification(notification) {
        if (notification && notification.parentNode) notification.parentNode.removeChild(notification);
    }

    function closeNotify(options) {
        var settings = options || {};
        var immediate = settings === true || settings.immediate === true;
        var notification = query('[data-st-notification]');

        window.clearTimeout(notificationTimer);
        window.clearTimeout(notificationRemovalTimer);
        notificationTimer = null;
        notificationRemovalTimer = null;
        if (!notification) return;

        notification.classList.remove('is-visible');
        notification.classList.add('is-leaving');
        notification.setAttribute('aria-hidden', 'true');

        if (immediate || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
            removeNotification(notification);
            return;
        }

        notificationRemovalTimer = window.setTimeout(function () {
            removeNotification(notification);
            notificationRemovalTimer = null;
        }, 200);
    }

    function notify(text, options) {
        var settings = typeof options === 'string' ? {type: options} : (options || {});
        var value = String(text == null ? '' : text).replace(/\s+/g, ' ').trim();
        var allowedTypes = ['info', 'success', 'warning', 'error'];
        var type = allowedTypes.indexOf(settings.type) >= 0 ? settings.type : 'info';
        var icons = {info: 'info', success: 'check', warning: 'priority_high', error: 'priority_high'};
        var duration = Number(settings.duration);
        var notification;
        var icon;
        var message;
        var close;

        closeNotify({immediate: true});
        if (!value || !body) return null;

        if (!Number.isFinite(duration)) duration = type === 'success' ? 2800 : (type === 'info' ? 3400 : 4400);
        duration = Math.max(0, duration);

        notification = document.createElement('section');
        notification.className = 'st-notification';
        notification.setAttribute('data-st-notification', '');
        notification.setAttribute('data-type', type);
        notification.setAttribute('role', type === 'error' || type === 'warning' ? 'alert' : 'status');
        notification.setAttribute('aria-live', type === 'error' || type === 'warning' ? 'assertive' : 'polite');
        notification.setAttribute('aria-atomic', 'true');
        notification.setAttribute('aria-hidden', 'false');

        icon = document.createElement('span');
        icon.className = 'st-notification__icon material-icons-outlined';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = icons[type];

        message = document.createElement('span');
        message.className = 'st-notification__message';
        message.textContent = value;

        close = document.createElement('button');
        close.type = 'button';
        close.className = 'st-notification__close';
        close.setAttribute('data-st-notification-close', '');
        close.setAttribute('aria-label', i18n('关闭提示'));
        close.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">close</span>';

        notification.appendChild(icon);
        notification.appendChild(message);
        notification.appendChild(close);
        body.appendChild(notification);

        window.requestAnimationFrame(function () {
            if (document.documentElement.contains(notification)) notification.classList.add('is-visible');
        });

        if (duration > 0) {
            notificationTimer = window.setTimeout(function () {
                if (document.documentElement.contains(notification)) closeNotify();
            }, duration);
        }
        return notification;
    }

    function bridgeLegacyMessages() {
        if (typeof message === 'undefined' || !message || message.__seattleNotifications === true) return;
        ['success', 'error', 'warning', 'info'].forEach(function (type) {
            message[type] = function (text) {
                var value = typeof i18n === 'function' ? i18n(text) : text;
                return notify(value, {type: type});
            };
        });
        message.__seattleNotifications = true;
    }

    function fieldValues(field) {
        return queryAll('input:not([type="hidden"]), textarea, select', field);
    }

    function syncOutlinedField(field) {
        if (!field) return;
        var controls = fieldValues(field);
        var filled = controls.some(function (control) {
            if (control.tagName === 'SELECT') return control.selectedIndex >= 0 && String(control.value || '') !== '';
            if (control.type === 'checkbox' || control.type === 'radio') return control.checked;
            return String(control.value == null ? '' : control.value).trim() !== '';
        });
        field.classList.toggle('is-filled', filled);
    }

    function enhanceOutlinedFields(scope) {
        queryAll('.st-field', scope || document).forEach(function (field) {
            var children = Array.prototype.slice.call(field.children || []);
            var label = children[0];
            var control = null;
            if (!label || !/^(LABEL|SPAN)$/.test(label.tagName) || label.classList.contains('st-code-field') || label.classList.contains('st-captcha-input')) return;
            children.slice(1).some(function (candidate) {
                if (candidate.matches && candidate.matches('input, select, textarea, .st-input-with-action, .st-captcha-input, .st-code-field')) {
                    control = candidate;
                    return true;
                }
                return false;
            });
            if (!control) return;
            field.classList.add('st-outlined-field');
            label.classList.add('st-outline-label');
            control.classList.add('st-outline-control');
            syncOutlinedField(field);
        });
    }

    function popupControl(item) {
        var block = query(':scope > .layui-input-block.component-content, :scope > .layui-input-block', item);
        if (!block) return null;
        if (query('input[type="checkbox"], input[type="radio"], .layui-form-switch, .image-render, .file-render, .editor-wrapper, .ev2-editor, .treeCheckbox, .widget-block, .ace_editor', block)) return null;
        var control = query(':scope > input.layui-input:not([type="hidden"]), :scope > textarea.layui-textarea', block);
        if (control && /display\s*:\s*none/i.test(control.getAttribute('style') || '')) control = null;
        if (!control) control = query('.layui-form-select .layui-input, .layui-treeSelect .layui-input', block);
        return control;
    }

    function syncPopupField(item) {
        if (!item) return;
        var control = query('.st-popup-control', item);
        var active = document.activeElement;
        item.classList.toggle('st-popup-focused', Boolean(control && (control === active || control.contains && control.contains(active))));
        item.classList.toggle('st-popup-filled', Boolean(control && String(control.value == null ? '' : control.value).trim() !== ''));
    }

    function enhancePopupFields(scope) {
        queryAll('.component-popup .layui-form-item', scope || document).forEach(function (item) {
            if (item.classList.contains('hide')) {
                item.classList.remove('st-popup-field', 'st-popup-focused', 'st-popup-filled', 'st-popup-complex');
                queryAll('.st-popup-control, .st-popup-label', item).forEach(function (node) {
                    node.classList.remove('st-popup-control', 'st-popup-label');
                });
                return;
            }
            var label = query(':scope > .layui-form-label', item);
            var control = popupControl(item);
            if (!control) {
                item.classList.remove('st-popup-field', 'st-popup-focused', 'st-popup-filled');
                item.classList.add('st-popup-complex');
                return;
            }
            item.classList.remove('st-popup-complex');
            item.classList.add('st-popup-field');
            control.classList.add('st-popup-control');
            if (label && !label.classList.contains('hide')) label.classList.add('st-popup-label');
            syncPopupField(item);
        });

        queryAll('.layui-layer-tab.component-popup .layui-layer-title', scope || document).forEach(function (tabs) {
            var active = query('.layui-this', tabs);
            if (!active || tabs.scrollWidth <= tabs.clientWidth) return;
            var left = active.offsetLeft;
            var right = left + active.offsetWidth;
            if (left < tabs.scrollLeft) tabs.scrollLeft = Math.max(0, left - 8);
            else if (right > tabs.scrollLeft + tabs.clientWidth) tabs.scrollLeft = right - tabs.clientWidth + 8;
        });
    }

    var legacyIconMap = {
        'fa-eye': 'visibility',
        'fa-eye-slash': 'visibility_off',
        'fa-gear': 'settings',
        'fa-gears': 'settings',
        'fa-money-bill-transfer': 'payments',
        'fa-trash-can': 'delete',
        'fa-trash': 'delete',
        'fa-folder-arrow-up': 'upload_file',
        'fa-pen-to-square': 'edit',
        'fa-pen-field': 'tune',
        'fa-lock-keyhole': 'lock',
        'fa-lock-keyhole-open': 'lock_open',
        'fa-file-export': 'download',
        'fa-download': 'download',
        'fa-copy': 'content_copy',
        'fa-circle-plus': 'add_circle',
        'fa-circle-check': 'check_circle',
        'fa-circle-info': 'info',
        'fa-ticket': 'confirmation_number',
        'fa-truck': 'local_shipping',
        'fa-truck-ramp-box': 'local_shipping',
        'fa-shop-lock': 'lock',
        'fa-rectangle-list': 'receipt_long',
        'fa-arrow-up-right-from-square': 'open_in_new',
        'fa-floppy-disk': 'save',
        'fa-xmark': 'close',
        'fa-bold': 'format_bold',
        'fa-italic': 'format_italic',
        'fa-heading': 'title',
        'fa-list-ul': 'format_list_bulleted',
        'fa-list-ol': 'format_list_numbered',
        'fa-quote-right': 'format_quote',
        'fa-code': 'code',
        'fa-link': 'link',
        'fa-image': 'image',
        'fa-table': 'table_chart',
        'fa-pen-paintbrush': 'edit_note',
        'fa-camera': 'photo_camera',
        'fa-plus': 'add',
        'fa-minus': 'remove',
        'fa-magnifying-glass': 'search',
        'fa-chevrons-down': 'expand_more',
        'fa-chevrons-right': 'chevron_right',
        'fa-chevron-down': 'expand_more',
        'fa-chevron-right': 'chevron_right',
        'fa-arrow-up-arrow-down': 'swap_vert',
        'fa-sort': 'swap_vert'
    };

    function normalizeLegacyIcons(scope) {
        queryAll('i[class*="fa-"]', scope || document).forEach(function (icon) {
            var classes;
            var legacy;
            var ligature;
            if (!icon.closest('.ev2-editor') && icon.closest('.CodeMirror, .editor-wrapper')) return;
            classes = Array.prototype.slice.call(icon.classList || []);
            legacy = classes.find(function (name) { return Object.prototype.hasOwnProperty.call(legacyIconMap, name); });
            if (!legacy && classes.indexOf('fa-spinner') < 0) return;
            ligature = legacy ? legacyIconMap[legacy] : '';
            classes.filter(function (name) { return name.indexOf('fa-') === 0 || name === 'fa'; }).forEach(function (name) {
                icon.classList.remove(name);
            });
            icon.classList.add('material-icons-outlined', 'st-normalized-icon');
            icon.textContent = ligature;
            icon.setAttribute('aria-hidden', 'true');
            if (!ligature) icon.classList.add('st-icon-spinner');
        });
    }

    function enhanceAccessibleControls(scope) {
        queryAll('.ev2-editor button[title], .editor-wrapper button[title]', scope || document).forEach(function (button) {
            var title = String(button.getAttribute('title') || '').trim();
            if (title && !button.getAttribute('aria-label')) button.setAttribute('aria-label', title);
            queryAll('i[class*="fa-"]', button).forEach(function (icon) { icon.setAttribute('aria-hidden', 'true'); });
        });
        queryAll('.bootstrap-table .th-inner.sortable, .bootstrap-table .btn-sort', scope || document).forEach(function (control) {
            var clone;
            var label;
            if (!control.getAttribute('aria-label')) {
                clone = (control.classList.contains('btn-sort') && control.closest('th') ? control.closest('th') : control).cloneNode(true);
                queryAll('i, .material-icons, .material-icons-outlined, .btn-sort', clone).forEach(function (icon) { icon.remove(); });
                label = String(clone.textContent || '').replace(/\s+/g, ' ').trim();
                if (label) control.setAttribute('aria-label', i18n('按') + label + i18n('排序'));
            }
            control.setAttribute('role', 'button');
            control.setAttribute('tabindex', '0');
            control.setAttribute('data-st-sort-control', '');
            queryAll('i[class*="fa-"]', control).forEach(function (icon) { icon.setAttribute('aria-hidden', 'true'); });
        });
    }

    function widgetOutlinedControl(field) {
        return query(':scope > input.layui-input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), :scope > textarea.layui-textarea, :scope > .widget-general > .layui-form-select .layui-input', field);
    }

    function syncWidgetOutlinedField(field) {
        var control = field && query('.st-widget-outline-control', field);
        var active = document.activeElement;
        if (!field) return;
        field.classList.toggle('st-widget-focused', Boolean(control && (control === active || control.contains && control.contains(active))));
        field.classList.toggle('st-widget-filled', Boolean(control && String(control.value == null ? '' : control.value).trim() !== ''));
    }

    function enhanceWidgetOutlinedFields(scope) {
        queryAll('.component-popup .widget-block .widget-field:not(.widget-data-field)', scope || document).forEach(function (field) {
            var label = query(':scope > label', field);
            var control = widgetOutlinedControl(field);
            if (!label || !control) return;
            field.classList.add('st-widget-outlined');
            label.classList.add('st-widget-outline-label');
            control.classList.add('st-widget-outline-control');
            syncWidgetOutlinedField(field);
        });
    }

    function enhanceFields(scope) {
        enhanceInlineHtml(scope || document);
        enhanceOutlinedFields(scope || document);
        enhancePopupFields(scope || document);
        enhanceWidgetOutlinedFields(scope || document);
        normalizeLegacyIcons(scope || document);
        enhanceAccessibleControls(scope || document);
        if (body) body.classList.toggle('st-component-popup-open', Boolean(document.querySelector('.layui-layer.component-popup, .layui-layer.st-item-card-layer')));
    }

    function queueFieldEnhance() {
        if (fieldEnhanceFrame) return;
        fieldEnhanceFrame = window.requestAnimationFrame(function () {
            fieldEnhanceFrame = null;
            enhanceFields(document);
        });
    }

    function ensureFieldObserver() {
        if (!window.MutationObserver || !document.body) return;
        if (fieldObserver) fieldObserver.disconnect();
        fieldObserver = new MutationObserver(queueFieldEnhance);
        fieldObserver.observe(document.body, {childList: true, subtree: true});
    }

    function copyFallback(text, success, fail) {
        var area = document.createElement('textarea');
        var completed = false;
        area.value = String(text == null ? '' : text);
        area.setAttribute('readonly', '');
        area.setAttribute('aria-hidden', 'true');
        area.style.position = 'fixed';
        area.style.top = '0';
        area.style.left = '-9999px';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.focus({preventScroll: true});
        area.select();
        try {
            area.setSelectionRange(0, area.value.length);
            completed = document.execCommand('copy') === true;
        } catch (error) {}
        area.remove();
        if (completed) {
            if (typeof success === 'function') success();
        } else if (typeof fail === 'function') fail();
        return completed;
    }

    function bridgeClipboard() {
        if (typeof util === 'undefined' || !util || util.copyTextToClipboard && util.copyTextToClipboard.__seattleClipboard) return;
        var copy = function (text, success, fail) {
            var value = String(text == null ? '' : text);
            if (window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(value).then(function () {
                    if (typeof success === 'function') success();
                }).catch(function () { copyFallback(value, success, fail); });
                return;
            }
            copyFallback(value, success, fail);
        };
        copy.__seattleClipboard = true;
        util.copyTextToClipboard = copy;
    }

    function bridgeComponentPopups() {
        if (typeof component === 'undefined' || !component || typeof component.popup !== 'function' || component.popup.__seattlePopup) return;
        var original = component.popup;
        var wrapped = function (options) {
            var settings = Object.assign({}, options || {});
            var renderComplete = settings.renderComplete;
            var requested = parseFloat(settings.width || '680');
            settings.maxmin = false;
            settings.move = false;
            if (!isAppViewport() && Number.isFinite(requested)) {
                settings.width = Math.max(320, Math.min(requested, window.innerWidth - 32)) + 'px';
            }
            settings.renderComplete = function () {
                if (typeof renderComplete === 'function') renderComplete.apply(this, arguments);
                queueFieldEnhance();
                window.setTimeout(queueFieldEnhance, 60);
            };
            return original.call(component, settings);
        };
        wrapped.__seattlePopup = true;
        wrapped.__seattleOriginal = original;
        component.popup = wrapped;
    }

    function initPage() {
        body = document.body;
        bridgeLegacyMessages();
        bridgeClipboard();
        bridgeComponentPopups();
        enhanceFields(document);
        window.setTimeout(queueFieldEnhance, 80);
        window.setTimeout(queueFieldEnhance, 700);
        ensureFieldObserver();
        syncNavigation();
        syncBrandSubtitle();
        syncNavigationAccessibility(body.classList.contains('st-nav-open'));
        applyTheme(currentPreference(), false);
        queryAll('.st-nav__group > a').forEach(function (link) {
            var label = query('span:not(.material-icons-outlined)', link);
            if (!label) return;
            if (!link.getAttribute('aria-label')) link.setAttribute('aria-label', label.textContent.trim());
            if (!link.getAttribute('title')) link.setAttribute('title', label.textContent.trim());
        });
        var memberNavigation = query('.st-nav');
        restoreNavScroll(memberNavigation);
        bindNavScroll(memberNavigation);
        queryAll('.st-subnav').forEach(function (nav) {
            var active = query('[aria-current="page"], .is-active, .active', nav);
            if (!active || nav.scrollWidth <= nav.clientWidth) return;
            var inset = 8;
            var viewStart = nav.scrollLeft + inset;
            var viewEnd = nav.scrollLeft + nav.clientWidth - inset;
            var activeStart = active.offsetLeft;
            var activeEnd = activeStart + active.offsetWidth;
            var target = nav.scrollLeft;
            if (activeStart < viewStart) target = activeStart - inset;
            if (activeEnd > viewEnd) target = activeEnd - nav.clientWidth + inset;
            nav.scrollLeft = Math.max(0, Math.min(target, nav.scrollWidth - nav.clientWidth));
        });
        var page = query('#pjax-container > .st-page, #pjax-container > .st-page-shell, #pjax-container > main, #pjax-container > div');
        var transitionTarget = page && (page.matches('main, .st-main') ? page : query('main, .st-main', page));
        if (transitionTarget) {
            window.clearTimeout(pageTransitionTimer);
            transitionTarget.classList.remove('st-page-enter', 'is-visible');
            transitionTarget.classList.add('st-page-enter');
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () { if (document.contains(transitionTarget)) transitionTarget.classList.add('is-visible'); });
            });
            pageTransitionTimer = window.setTimeout(function () { if (document.contains(transitionTarget)) transitionTarget.classList.remove('st-page-enter', 'is-visible'); }, 260);
        }
        if (window.util && typeof window.util.isPc === 'function' && !window.util.isPc.__seattleViewport) {
            var seattleIsPc = function () { return !isAppViewport(); };
            seattleIsPc.__seattleViewport = true;
            window.util.isPc = seattleIsPc;
        }
        hideLoading();
        if (window.layui && typeof window.layui.use === 'function') {
            try { window.layui.use('form', function () { if (window.layui.form) window.layui.form.render(); }); } catch (error) {}
        }
        document.dispatchEvent(new CustomEvent('seattle:page-ready'));
    }

    function queueInitPage() {
        window.clearTimeout(pageInitTimer);
        pageInitTimer = window.setTimeout(initPage, 32);
    }

    if (root.getAttribute('data-seattle-core-bound') !== '1') {
        root.setAttribute('data-seattle-core-bound', '1');
        document.addEventListener('click', function (event) {
            var target = event.target;
            var theme = target.closest ? target.closest('[data-theme-toggle]') : null;
            var openSheet = target.closest ? target.closest('[data-st-sheet-open]') : null;
            var closeSheet = target.closest ? target.closest('[data-st-sheet-close]') : null;
            var navToggle = target.closest ? target.closest('[data-st-nav-toggle]') : null;
            var navClose = target.closest ? target.closest('[data-st-nav-close]') : null;
            var messageToggle = target.closest ? target.closest('[data-st-message-toggle]') : null;
            var notificationClose = target.closest ? target.closest('[data-st-notification-close]') : null;
            var pjaxLogout = target.closest ? target.closest('#pjax-container .logout') : null;
            var headerMenu = target.closest ? target.closest('.st-theme-menu, .st-account-menu') : null;
            var link = target.closest ? target.closest('a[href]') : null;
            var bottomLink = target.closest ? target.closest('.st-bottom-nav a[href]') : null;

            if (target.closest) {
                var field = target.closest('.st-outlined-field');
                var popupField = target.closest('.st-popup-field');
                if (field) window.setTimeout(function () { syncOutlinedField(field); }, 0);
                if (popupField) window.setTimeout(function () { syncPopupField(popupField); }, 0);
            }

            if (notificationClose) {
                event.preventDefault();
                closeNotify();
                return;
            }
            if (pjaxLogout) {
                event.preventDefault();
                event.stopImmediatePropagation();
                if (typeof message !== 'undefined' && message && typeof message.ask === 'function') {
                    message.ask(i18n('您是否要注销登录？'), function () { window.location.href = '/user/authentication/logout'; });
                } else if (window.confirm(i18n('您是否要注销登录？'))) {
                    window.location.href = '/user/authentication/logout';
                }
                return;
            }
            if (bottomLink) {
                queryAll('.st-bottom-nav [data-st-bottom]').forEach(function (item) {
                    item.classList.remove('is-active');
                    item.removeAttribute('aria-current');
                });
                bottomLink.classList.add('is-active');
                bottomLink.setAttribute('aria-current', 'page');
            }
            if (theme) {
                event.preventDefault();
                applyTheme(theme.getAttribute('data-theme-toggle'), true);
                closeSheets();
                closeHeaderMenus();
                return;
            }
            if (openSheet) {
                event.preventDefault();
                setSheet(openSheet.getAttribute('data-st-sheet-open'), true, openSheet);
                return;
            }
            if (closeSheet) {
                event.preventDefault();
                closeSheets();
                return;
            }
            if (navToggle) {
                event.preventDefault();
                setNavigation(!body.classList.contains('st-nav-open'));
                return;
            }
            if (navClose) {
                event.preventDefault();
                setNavigation(false);
                return;
            }
            if (messageToggle) {
                event.preventDefault();
                setMessagePopover(messageToggle.getAttribute('aria-expanded') !== 'true');
                return;
            }
            if (link && isCrossShellLink(link)) {
                event.preventDefault();
                event.stopImmediatePropagation();
                window.location.assign(link.href);
                return;
            }
            if (link && isAppViewport() && link.closest('.st-nav')) setNavigation(false);
            if (!target.closest || !target.closest('.st-message-center')) setMessagePopover(false);
            closeHeaderMenus(headerMenu);
        }, true);

        document.addEventListener('keydown', function (event) {
            var sortControl = event.target.closest ? event.target.closest('[data-st-sort-control]') : null;
            if (sortControl && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                sortControl.click();
                return;
            }
            if (event.key === 'Tab') {
                var activeSheet = query('[data-st-sheet].is-open');
                if (activeSheet) {
                    var focusable = sheetFocusables(activeSheet);
                    if (!focusable.length) {
                        event.preventDefault();
                        return;
                    }
                    var first = focusable[0];
                    var last = focusable[focusable.length - 1];
                    if (event.shiftKey && (document.activeElement === first || !activeSheet.contains(document.activeElement))) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && (document.activeElement === last || !activeSheet.contains(document.activeElement))) {
                        event.preventDefault();
                        first.focus();
                    }
                    return;
                }
            }
            if (event.key !== 'Escape') return;
            closeSheets();
            setNavigation(false);
            setMessagePopover(false);
            closeHeaderMenus();
            closeNotify();
        });

        document.addEventListener('input', function (event) {
            var field = event.target.closest ? event.target.closest('.st-outlined-field') : null;
            var popupField = event.target.closest ? event.target.closest('.st-popup-field') : null;
            var widgetField = event.target.closest ? event.target.closest('.st-widget-outlined') : null;
            if (field) syncOutlinedField(field);
            if (popupField) syncPopupField(popupField);
            if (widgetField) syncWidgetOutlinedField(widgetField);
        }, true);
        document.addEventListener('change', function (event) {
            var field = event.target.closest ? event.target.closest('.st-outlined-field') : null;
            var popupField = event.target.closest ? event.target.closest('.st-popup-field') : null;
            var widgetField = event.target.closest ? event.target.closest('.st-widget-outlined') : null;
            if (field) syncOutlinedField(field);
            if (popupField) syncPopupField(popupField);
            if (widgetField) syncWidgetOutlinedField(widgetField);
            queueFieldEnhance();
        }, true);
        document.addEventListener('focusin', function (event) {
            var field = event.target.closest ? event.target.closest('.st-outlined-field') : null;
            var popupField = event.target.closest ? event.target.closest('.st-popup-field') : null;
            var widgetField = event.target.closest ? event.target.closest('.st-widget-outlined') : null;
            if (field) syncOutlinedField(field);
            if (popupField) syncPopupField(popupField);
            if (widgetField) syncWidgetOutlinedField(widgetField);
        }, true);
        document.addEventListener('focusout', function (event) {
            var field = event.target.closest ? event.target.closest('.st-outlined-field') : null;
            var popupField = event.target.closest ? event.target.closest('.st-popup-field') : null;
            var widgetField = event.target.closest ? event.target.closest('.st-widget-outlined') : null;
            if (field) window.setTimeout(function () { syncOutlinedField(field); }, 0);
            if (popupField) window.setTimeout(function () { syncPopupField(popupField); }, 0);
            if (widgetField) window.setTimeout(function () { syncWidgetOutlinedField(widgetField); }, 0);
        }, true);

        window.addEventListener('pageshow', queueFieldEnhance);
        window.addEventListener('pagehide', function () { saveNavScroll(); });

        if (systemTheme) {
            var systemChange = function () {
                if (currentPreference() === 'auto') applyTheme('auto', false);
            };
            if (typeof systemTheme.addEventListener === 'function') systemTheme.addEventListener('change', systemChange);
            else if (typeof systemTheme.addListener === 'function') systemTheme.addListener(systemChange);
        }

        window.addEventListener('resize', function () {
            closeHeaderMenus();
            if (!isAppViewport()) {
                setNavigation(false);
                closeSheets();
            } else {
                syncNavigationAccessibility(body.classList.contains('st-nav-open'));
            }
        });

        if (window.visualViewport) {
            var syncKeyboard = function () {
                var focused = document.activeElement && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
                var reduced = window.visualViewport.height < window.innerHeight * .76;
                body.classList.toggle('st-keyboard-open', Boolean(isAppViewport() && focused && reduced));
            };
            window.visualViewport.addEventListener('resize', syncKeyboard);
            window.visualViewport.addEventListener('scroll', syncKeyboard);
            document.addEventListener('focusin', function () { window.setTimeout(syncKeyboard, 80); });
            document.addEventListener('focusout', function () { window.setTimeout(syncKeyboard, 80); });
        }

        if (window.jQuery) {
            window.jQuery(document).on('pjax:send pjax:popstate', function () {
                if (navScrollFrame) {
                    window.cancelAnimationFrame(navScrollFrame);
                    navScrollFrame = null;
                }
                saveNavScroll();
                window.clearTimeout(pageInitTimer);
                closeSheets();
                setNavigation(false);
                setMessagePopover(false);
                closeHeaderMenus();
                closeNotify({immediate: true});
            });
            window.jQuery(document).on('pjax:end', function () {
                window.clearTimeout(pageInitTimer);
                pageInitTimer = null;
                initPage();
            });
            window.jQuery(document).on('pjax:complete pjax:error pjax:timeout', queueInitPage);
        }
    }

    window.SeattleTheme = {
        initPage: initPage,
        enhanceFields: enhanceFields,
        applyTheme: applyTheme,
        closeSheets: closeSheets,
        isAppViewport: isAppViewport,
        safeInlineHtml: sanitizeInlineHtml,
        sanitizeInlineHtml: sanitizeInlineHtml,
        plainText: inlinePlainText,
        notify: notify,
        closeNotify: closeNotify
    };
    initPage();
}());
