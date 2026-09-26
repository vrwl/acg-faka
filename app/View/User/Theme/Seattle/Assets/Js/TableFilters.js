(function () {
    'use strict';

    var namespace = '.seattleTableFilters';
    var mobileQuery = window.matchMedia
        ? window.matchMedia('(max-width: 767px), (max-height: 500px) and (max-width: 1024px)')
        : null;
    var presenters = new Map();

    if (typeof window.__seattleTableFiltersMediaCleanup === 'function') {
        window.__seattleTableFiltersMediaCleanup();
    }

    function isMobile() {
        return mobileQuery ? mobileQuery.matches : (window.innerWidth < 768 || (window.innerHeight <= 500 && window.innerWidth <= 1024));
    }

    function elementForSearch(snapshot) {
        var instance = snapshot && snapshot.search && snapshot.search.instance;
        if (!instance) return null;
        if (instance.$instance && instance.$instance.length) return instance.$instance.get(0);
        return snapshot.element && snapshot.element.closest('.bootstrap-table')
            ? snapshot.element.closest('.bootstrap-table').querySelector('.table-search')
            : null;
    }

    function fieldByName(form, name) {
        return Array.prototype.slice.call(form.querySelectorAll('[name]')).find(function (field) {
            return field.name === name;
        }) || null;
    }

    function quickSource(form, definitions) {
        var inputs = (definitions || []).filter(function (definition) {
            return definition && definition.type === 'input' && definition.hide !== true;
        });
        for (var index = 0; index < inputs.length; index += 1) {
            var field = fieldByName(form, inputs[index].name);
            if (field && field.type !== 'hidden' && !field.disabled) return field;
        }
        return Array.prototype.slice.call(form.querySelectorAll('input[type="text"][name]')).find(function (field) {
            return !field.disabled && !/^between(?:Start|End)/.test(field.name)
                && !field.closest('.layui-select-title')
                && !(field.closest('.layui-input-inline') || {}).classList?.contains('hide');
        }) || null;
    }

    function visibleDefinitions(definitions) {
        return (definitions || []).filter(function (definition) {
            return definition && definition.hide !== true;
        });
    }

    function hasExtraFields(definitions, source) {
        var sourceName = source ? source.name : '';
        return visibleDefinitions(definitions).some(function (definition) {
            return !sourceName || definition.name !== sourceName;
        });
    }

    function valueIsActive(value) {
        if (value == null || value === '') return false;
        if (Array.isArray(value)) return value.some(valueIsActive);
        if (typeof value === 'object') return Object.keys(value).some(function (key) { return valueIsActive(value[key]); });
        return String(value).trim() !== '';
    }

    function activeFilterCount(search) {
        var values = search && typeof search.value === 'function' ? search.value() : {};
        var groups = Object.create(null);
        Object.keys(values || {}).forEach(function (name) {
            if (!valueIsActive(values[name])) return;
            groups[name.replace(/^between(?:Start|End)/, 'between')] = true;
        });
        return Object.keys(groups).length;
    }

    function closeSheets() {
        if (window.SeattleTheme && typeof window.SeattleTheme.closeSheets === 'function') {
            window.SeattleTheme.closeSheets();
        }
    }

    function syncStateAccessibility(wrap) {
        if (!wrap) return;
        wrap.querySelectorAll('.table-switch-state').forEach(function (group) {
            group.setAttribute('role', 'group');
            group.setAttribute('aria-label', group.getAttribute('aria-label') || i18n('状态筛选'));
            group.querySelectorAll('button').forEach(function (button) {
                button.setAttribute('aria-pressed', button.classList.contains('active') ? 'true' : 'false');
            });
        });
    }

    function conciseSearchLabel(value) {
        var label = String(value || i18n('搜索')).replace(/[（(][^）)]*[）)]/g, '').trim();
        //label 来自 search.js 的 item.title = i18n(item.title),已经是译文。
        //下面的中文关键字只在中文语系成立;西文语系直接用译好的 label,
        //否则会拼出「SearchProduct Name」这种东西。
        if (!label) return i18n('搜索');
        if (!/[一-鿿]/.test(label)) return label;
        if (/商品名称/.test(label)) return i18n('搜索商品名称');
        if (/订单号|交易号/.test(label)) return i18n('搜索订单号');
        if (/卡密/.test(label)) return i18n('搜索卡密');
        if (/代券|券码/.test(label)) return i18n('搜索代券');
        return label ? i18n('搜索') + label.replace(/^搜索/, '') : i18n('搜索');
    }

    function enhanceFilterFields(form, prefix) {
        Array.prototype.slice.call(form.querySelectorAll('.mui-sf')).forEach(function (field, index) {
            var label = field.querySelector('.mui-sf__label');
            var text = label ? label.textContent.replace(/\s+/g, ' ').trim() : '';
            if (!text) return;
            var control = Array.prototype.slice.call(field.querySelectorAll('input[name], select[name], textarea[name]')).find(function (node) {
                return node.type !== 'hidden';
            });
            if (control) {
                if (!control.id) control.id = prefix + '-field-' + (index + 1);
                if (label && !label.getAttribute('for')) label.setAttribute('for', control.id);
                if (!control.getAttribute('aria-label')) control.setAttribute('aria-label', text);
            }
            field.querySelectorAll('.layui-select-title input, .xm-select-default, [class*="tree-"] input[type="text"]').forEach(function (visible) {
                visible.setAttribute('aria-label', text);
            });
        });
    }

    function createPresenter(table, snapshot) {
        var tableElement = snapshot && snapshot.element;
        var wrap = tableElement && tableElement.closest('.bootstrap-table');
        var form = elementForSearch(snapshot);
        if (!tableElement || !wrap || !form || !snapshot.search || !snapshot.search.instance) return null;

        var toolbar = wrap.querySelector(':scope > .fixed-table-toolbar');
        if (!toolbar) {
            toolbar = document.createElement('div');
            toolbar.className = 'fixed-table-toolbar';
            wrap.insertBefore(toolbar, wrap.firstChild);
        }

        var anchor = document.createComment('Seattle search form origin');
        form.parentNode.insertBefore(anchor, form);
        var sheetName = 'table-filters-' + String(snapshot.id || tableElement.id || Date.now()).replace(/[^a-zA-Z0-9_-]/g, '-');
        var definitions = snapshot.search.definitions || [];
        var source = quickSource(form, definitions);
        var extras = hasExtraFields(definitions, source);
        var bar = document.createElement('div');
        bar.className = 'st-mobile-listbar';
        bar.setAttribute('data-st-mobile-listbar', '');
        toolbar.insertBefore(bar, toolbar.firstChild);

        var quick = null;
        if (source) {
            var holder = source.closest('.mui-sf');
            var floatingLabel = holder && holder.querySelector('.mui-sf__label');
            var placeholder = conciseSearchLabel(floatingLabel ? floatingLabel.textContent.trim() : (source.getAttribute('placeholder') || i18n('搜索')));
            var quickWrap = document.createElement('div');
            quickWrap.className = 'st-mobile-quick-search';
            quickWrap.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">search</span><input type="search" enterkeyhint="search"><button type="button" aria-label="' + i18n('执行搜索') + '"><span class="material-icons-outlined" aria-hidden="true">arrow_forward</span></button>';
            quick = quickWrap.querySelector('input');
            quick.placeholder = placeholder || i18n('搜索');
            quick.setAttribute('aria-label', placeholder || i18n('搜索'));
            quick.value = source.value || '';
            bar.appendChild(quickWrap);
        }

        var sheet = null;
        var trigger = null;
        var badge = null;
        if (extras || !source) {
            trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'st-mobile-filter-trigger';
            trigger.setAttribute('data-st-sheet-open', sheetName);
            trigger.setAttribute('aria-expanded', 'false');
            trigger.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">tune</span><span>' + i18n('筛选') + '</span><b hidden>0</b>';
            badge = trigger.querySelector('b');
            bar.appendChild(trigger);

            sheet = document.createElement('section');
            sheet.className = 'st-sheet st-filter-sheet';
            sheet.setAttribute('data-st-sheet', sheetName);
            sheet.setAttribute('data-st-dynamic-sheet', 'table-filter');
            sheet.setAttribute('aria-hidden', 'true');
            sheet.setAttribute('aria-label', i18n('筛选条件'));
            sheet.setAttribute('role', 'dialog');
            sheet.setAttribute('aria-modal', 'true');
            sheet.setAttribute('tabindex', '-1');
            sheet.innerHTML = '<div class="st-sheet__handle" aria-hidden="true"></div>'
                + '<header><div><strong>' + i18n('筛选') + '</strong><small>' + i18n('设置条件后查看结果') + '</small></div><button type="button" class="st-icon-button" data-st-sheet-close aria-label="' + i18n('关闭筛选') + '"><span class="material-icons-outlined" aria-hidden="true">close</span></button></header>'
                + '<div class="st-filter-sheet__body"><div class="st-filter-form-host"></div><div class="st-filter-sheet__actions"><button type="button" class="st-filter-reset"><span class="material-icons-outlined" aria-hidden="true">restart_alt</span><span>' + i18n('清除') + '</span></button><button type="button" class="st-filter-apply"><span class="material-icons-outlined" aria-hidden="true">check</span><span>' + i18n('查看结果') + '</span></button></div></div>';
            document.body.appendChild(sheet);
            enhanceFilterFields(form, sheetName);
        }

        var presenter = {
            table: table,
            snapshot: snapshot,
            tableElement: tableElement,
            wrap: wrap,
            form: form,
            anchor: anchor,
            bar: bar,
            sheet: sheet,
            trigger: trigger,
            badge: badge,
            quick: quick,
            source: source,
            lastFocused: null,
            listeners: []
        };

        function listen(target, type, handler, options) {
            if (!target) return;
            target.addEventListener(type, handler, options);
            presenter.listeners.push(function () { target.removeEventListener(type, handler, options); });
        }

        function submit() {
            var search = presenter.snapshot.search && presenter.snapshot.search.instance;
            if (search && typeof search.submit === 'function') search.submit();
        }

        function sheetFocusable() {
            if (!presenter.sheet) return [];
            return Array.prototype.slice.call(presenter.sheet.querySelectorAll('button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function (node) {
                return !node.hidden && node.getAttribute('aria-hidden') !== 'true'
                    && Boolean(node.offsetWidth || node.offsetHeight || node.getClientRects().length);
            });
        }

        function restoreSheetFocus() {
            var target = presenter.lastFocused || presenter.trigger;
            presenter.lastFocused = null;
            if (target && target.isConnected && !target.hidden) target.focus({preventScroll: true});
        }

        function closeFilterSheet() {
            closeSheets();
            window.setTimeout(restoreSheetFocus, 0);
        }

        function syncQuickFromSource() {
            if (presenter.quick && presenter.source && document.activeElement !== presenter.quick) {
                presenter.quick.value = presenter.source.value || '';
            }
        }

        function syncBadge() {
            if (!presenter.badge) return;
            var search = presenter.snapshot.search && presenter.snapshot.search.instance;
            var count = activeFilterCount(search);
            presenter.badge.textContent = String(count);
            presenter.badge.hidden = count === 0;
            presenter.trigger.classList.toggle('is-active', count > 0);
        }

        function syncViewport() {
            var mobile = isMobile();
            presenter.bar.hidden = !mobile;
            presenter.form.classList.toggle('st-filter-form--proxied', mobile && !presenter.sheet);
            if (mobile && presenter.sheet) {
                var host = presenter.sheet.querySelector('.st-filter-form-host');
                if (host && presenter.form.parentNode !== host) host.appendChild(presenter.form);
                presenter.form.classList.add('st-filter-form--sheet');
            } else {
                if (presenter.anchor.parentNode && presenter.form.parentNode !== presenter.anchor.parentNode) {
                    presenter.anchor.parentNode.insertBefore(presenter.form, presenter.anchor.nextSibling);
                }
                presenter.form.classList.remove('st-filter-form--sheet');
                if (presenter.sheet && presenter.sheet.classList.contains('is-open')) closeSheets();
            }
            syncStateAccessibility(presenter.wrap);
        }

        if (quick) {
            listen(quick, 'input', function () {
                if (!presenter.source) return;
                presenter.source.value = quick.value;
                presenter.source.dispatchEvent(new Event('input', {bubbles: true}));
                syncBadge();
            });
            listen(quick, 'keydown', function (event) {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                submit();
            });
            listen(quick.parentElement.querySelector('button'), 'click', submit);
            listen(source, 'input', function () {
                syncQuickFromSource();
                syncBadge();
            });
        }

        listen(form, 'input', syncBadge);
        listen(form, 'change', function () {
            syncBadge();
            window.setTimeout(syncQuickFromSource, 0);
        });
        if (sheet) {
            listen(trigger, 'click', function () {
                presenter.lastFocused = trigger;
                window.requestAnimationFrame(function () {
                    if (!presenter.sheet.classList.contains('is-open')) return;
                    var focusable = sheetFocusable();
                    var field = focusable.find(function (node) { return node.closest('.st-filter-form-host'); });
                    (field || focusable[0] || presenter.sheet).focus({preventScroll: true});
                });
            });
            listen(sheet.querySelector('[data-st-sheet-close]'), 'click', function () {
                window.setTimeout(restoreSheetFocus, 0);
            });
            listen(document.querySelector('.st-sheet-backdrop'), 'click', function () {
                if (!presenter.lastFocused) return;
                window.setTimeout(function () {
                    if (!presenter.sheet.classList.contains('is-open')) restoreSheetFocus();
                }, 0);
            });
            listen(sheet, 'keydown', function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    closeFilterSheet();
                    return;
                }
                if (event.key !== 'Tab') return;
                var focusable = sheetFocusable();
                if (!focusable.length) {
                    event.preventDefault();
                    return;
                }
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });
            listen(sheet.querySelector('.st-filter-reset'), 'click', function () {
                var search = presenter.snapshot.search && presenter.snapshot.search.instance;
                if (search && typeof search.reset === 'function') search.reset(true);
                window.setTimeout(function () {
                    syncQuickFromSource();
                    syncBadge();
                    closeFilterSheet();
                }, 80);
            });
            listen(sheet.querySelector('.st-filter-apply'), 'click', function () {
                submit();
                window.setTimeout(closeFilterSheet, 100);
            });
        }

        presenter.sync = function (nextSnapshot) {
            presenter.snapshot = nextSnapshot || presenter.snapshot;
            syncQuickFromSource();
            syncBadge();
            syncViewport();
        };
        presenter.destroy = function () {
            presenter.listeners.splice(0).forEach(function (dispose) { dispose(); });
            if (presenter.anchor.parentNode && presenter.form.parentNode !== presenter.anchor.parentNode) {
                presenter.anchor.parentNode.insertBefore(presenter.form, presenter.anchor.nextSibling);
            }
            presenter.form.classList.remove('st-filter-form--sheet');
            presenter.form.classList.remove('st-filter-form--proxied');
            presenter.bar.remove();
            if (presenter.sheet) presenter.sheet.remove();
            presenter.anchor.remove();
        };
        presenter.sync(snapshot);
        return presenter;
    }

    function handleLifecycle(event, payload) {
        var detail = payload || event.detail;
        var table = detail && detail.table;
        var snapshot = detail && detail.snapshot;
        if (!table) return;
        if (event.type === 'admin:table:destroy') {
            var old = presenters.get(table);
            if (old) old.destroy();
            presenters.delete(table);
            return;
        }
        if (!snapshot || !snapshot.search) return;
        var presenter = presenters.get(table);
        if (!presenter) {
            presenter = createPresenter(table, snapshot);
            if (presenter) presenters.set(table, presenter);
        } else presenter.sync(snapshot);
    }

    function destroyAll() {
        closeSheets();
        presenters.forEach(function (presenter) { presenter.destroy(); });
        presenters.clear();
    }

    if (window.__seattleTableFiltersDestroy) window.__seattleTableFiltersDestroy();
    window.__seattleTableFiltersDestroy = destroyAll;

    if (window.jQuery) {
        window.jQuery(document)
            .off(namespace)
            .on('admin:table:ready' + namespace + ' admin:table:update' + namespace + ' admin:table:destroy' + namespace, handleLifecycle)
            .on('click' + namespace, '.table-switch-state button', function () {
                var wrap = this.closest('.bootstrap-table');
                window.setTimeout(function () { syncStateAccessibility(wrap); }, 0);
            })
            .on('pjax:send' + namespace + ' pjax:popstate' + namespace, destroyAll);
    }

    function syncAllViewports() {
        presenters.forEach(function (presenter) { presenter.sync(); });
    }
    if (mobileQuery) {
        if (typeof mobileQuery.addEventListener === 'function') {
            mobileQuery.addEventListener('change', syncAllViewports);
            window.__seattleTableFiltersMediaCleanup = function () {
                mobileQuery.removeEventListener('change', syncAllViewports);
            };
        } else if (typeof mobileQuery.addListener === 'function') {
            mobileQuery.addListener(syncAllViewports);
            window.__seattleTableFiltersMediaCleanup = function () {
                mobileQuery.removeListener(syncAllViewports);
            };
        }
    } else {
        window.__seattleTableFiltersMediaCleanup = null;
    }
}());
