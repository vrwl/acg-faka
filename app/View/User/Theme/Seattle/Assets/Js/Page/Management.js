!function () {
    'use strict';

    const managementSource = document.currentScript?.getAttribute('data-ready-src') || '';
    const controllerVersion = (() => {
        try {
            return new URL(managementSource, window.location.href).searchParams.get('v') || '';
        } catch (error) {
            return '';
        }
    })();
    const page = document.querySelector('.st-management-page[data-st-controller]');
    if (!page || typeof Table !== 'function') return;

    const controller = page.getAttribute('data-st-controller') || '';
    const tableSelector = page.getAttribute('data-st-table') || '';
    let tableElement = tableSelector ? page.querySelector(tableSelector) : null;
    const main = page.querySelector('.st-main');
    const epoch = (Number(window.__seattleManagementEpoch) || 0) + 1;
    window.__seattleManagementEpoch = epoch;

    const isCurrent = () => Number(window.__seattleManagementEpoch) === epoch
        && document.contains(page)
        && document.querySelector('.st-management-page[data-st-controller]') === page;

    // PJAX keeps a DOM snapshot for browser back/forward. If the snapshot was
    // captured after bootstrap-table rendered, it contains the generated table
    // wrapper and rows but not the plugin's jQuery instance. Reusing that DOM
    // makes bootstrap-table deep-merge its own rendered metadata and can recurse
    // indefinitely. Always give the legacy controller a clean table mount.
    function resetTableMount() {
        if (!tableElement) return;
        const wrap = tableElement.closest('.st-table-wrap');
        if (!wrap || !page.contains(wrap)) return;
        const cleanTable = tableElement.cloneNode(false);
        wrap.replaceChildren(cleanTable);
        tableElement = cleanTable;
    }

    resetTableMount();
    const syncStateFilters = () => {
        page.querySelectorAll('.table-switch-state').forEach(group => {
            group.setAttribute('role', 'group');
            if (!group.hasAttribute('aria-label')) group.setAttribute('aria-label', i18n('状态筛选'));
            group.querySelectorAll('button').forEach(button => {
                button.setAttribute('aria-pressed', String(button.classList.contains('active')));
            });
        });
    };
    const escapeHtml = value => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const safeUrl = value => {
        try {
            const url = new URL(String(value || ''), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
        } catch (error) {
            return '';
        }
    };

    function sanitizeTableHtml(value) {
        if (value == null) return '';
        const template = document.createElement('template');
        template.innerHTML = String(value);
        const allowedTags = new Set(['A', 'B', 'BR', 'BUTTON', 'CODE', 'DEL', 'DIV', 'EM', 'FONT', 'I', 'IMG', 'INPUT', 'MARK', 'OPTION', 'S', 'SELECT', 'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP', 'U']);
        const dangerousTags = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'FORM', 'TEXTAREA']);
        const allowedTextStyles = new Set(['color', 'background-color', 'font-weight', 'font-style', 'text-decoration']);
        const safeStyleValue = value => {
            const text = String(value == null ? '' : value).trim();
            return text.length <= 80 && !/[<>"'`\\]/.test(text) && !/(?:url|expression|javascript|data)\s*\(/i.test(text) ? text : '';
        };
        const walk = node => {
            Array.from(node.childNodes).forEach(child => {
                if (child.nodeType === Node.COMMENT_NODE) {
                    child.remove();
                    return;
                }
                if (child.nodeType !== Node.ELEMENT_NODE) return;
                if (!allowedTags.has(child.tagName)) {
                    if (dangerousTags.has(child.tagName)) child.remove();
                    else {
                        walk(child);
                        child.replaceWith(...Array.from(child.childNodes));
                    }
                    return;
                }
                const textStyles = [];
                if (child.hasAttribute('style')) {
                    Array.from(child.style).forEach(property => {
                        property = String(property || '').toLowerCase();
                        if (!allowedTextStyles.has(property)) return;
                        const styleValue = safeStyleValue(child.style.getPropertyValue(property));
                        if (styleValue) textStyles.push(`${property}: ${styleValue}`);
                    });
                }
                Array.from(child.attributes).forEach(attribute => {
                    const name = attribute.name.toLowerCase();
                    const keep = name === 'class'
                        || name === 'role'
                        || name === 'tabindex'
                        || name === 'title'
                        || name === 'type'
                        || name === 'value'
                        || name === 'name'
                        || name === 'checked'
                        || name === 'selected'
                        || name === 'disabled'
                        || name === 'reload'
                        || name.startsWith('aria-')
                        || name.startsWith('data-')
                        || name.startsWith('lay-')
                        || (child.tagName === 'A' && ['href', 'target', 'rel'].includes(name))
                        || (child.tagName === 'IMG' && ['src', 'alt', 'loading'].includes(name));
                    if (!keep || name.startsWith('on')) child.removeAttribute(attribute.name);
                });
                if (textStyles.length) child.setAttribute('style', textStyles.join('; '));
                if (child.tagName === 'A' && child.hasAttribute('href')) {
                    const href = safeUrl(child.getAttribute('href'));
                    if (href) {
                        child.setAttribute('href', href);
                        child.setAttribute('rel', 'noopener noreferrer');
                    } else child.removeAttribute('href');
                }
                if (child.tagName === 'IMG') {
                    const src = safeUrl(child.getAttribute('src'));
                    if (!src) {
                        child.remove();
                        return;
                    }
                    child.setAttribute('src', src);
                    child.setAttribute('loading', 'lazy');
                }
                if (child.tagName === 'INPUT') {
                    const type = String(child.getAttribute('type') || 'text').toLowerCase();
                    if (!['text', 'number', 'checkbox', 'hidden'].includes(type)) child.setAttribute('type', 'text');
                }
                walk(child);
            });
        };
        walk(template.content);
        return template.innerHTML;
    }

    const patchState = window.__seattleManagementTablePatch || {
        baseSetColumns: Table.prototype.setColumns,
        owner: 0
    };
    window.__seattleManagementTablePatch = patchState;
    const originalSetColumns = patchState.baseSetColumns;
    function secureSetColumns(columns) {
        (Array.isArray(columns) ? columns : []).forEach(column => {
            if (!column || column.checkbox || column.type || column.dict || typeof column.formatter === 'function') return;
            column.formatter = value => escapeHtml(value == null || value === '' ? '—' : value);
        });
        const start = this.columns.length;
        originalSetColumns.call(this, columns);
        this.columns.slice(start).forEach(column => {
            const formatter = column && column.formatter;
            if (typeof formatter === 'function') {
                column.formatter = function () {
                    try {
                        return sanitizeTableHtml(formatter.apply(this, arguments));
                    } catch (error) {
                        return '<span class="text-gray">—</span>';
                    }
                };
            }
            (column && Array.isArray(column.buttons) ? column.buttons : []).forEach(button => {
                if (typeof button.show === 'function') {
                    const show = button.show;
                    button.show = function () {
                        try { return !!show.apply(this, arguments); } catch (error) { return false; }
                    };
                }
                if (typeof button.click === 'function') {
                    const click = button.click;
                    button.click = function () {
                        try { return click.apply(this, arguments); } catch (error) {
                            if (typeof message !== 'undefined' && typeof message.error === 'function') message.error(i18n('当前记录数据异常，暂时无法执行此操作'));
                        }
                    };
                }
            });
        });
    }
    patchState.owner = epoch;
    Table.prototype.setColumns = secureSetColumns;

    function restoreTable() {
        if (patchState.owner !== epoch) return;
        if (Table.prototype.setColumns === secureSetColumns) Table.prototype.setColumns = originalSetColumns;
        patchState.owner = 0;
    }

    function showLoadError() {
        if (!isCurrent()) return;
        if (main) main.setAttribute('aria-busy', 'false');
        if (!tableElement) return;
        const wrap = tableElement.closest('.st-table-wrap') || tableElement.parentElement;
        if (!wrap) return;
        const error = document.createElement('div');
        error.className = 'st-empty';
        error.setAttribute('role', 'alert');
        error.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">cloud_off</span><strong>' + i18n('管理功能加载失败') + '</strong><p>' + i18n('请检查网络后重新加载。') + '</p><button type="button" class="st-button st-button--primary">' + i18n('重新加载') + '</button>';
        error.querySelector('button').addEventListener('click', () => window.location.reload());
        wrap.replaceChildren(error);
    }

    if (!/^\/assets\/user\/controller\/business\/[a-z.]+\.js$/.test(controller)) {
        restoreTable();
        showLoadError();
        return;
    }

    if (main) main.setAttribute('aria-busy', 'true');
    document.querySelectorAll('script[data-st-management-controller]').forEach(script => script.remove());
    const script = document.createElement('script');
    const controllerUrl = new URL(controller, window.location.origin);
    if (controllerVersion) controllerUrl.searchParams.set('v', controllerVersion);
    script.src = controllerUrl.pathname + controllerUrl.search;
    script.async = false;
    script.setAttribute('data-st-management-controller', 'true');
    script.addEventListener('load', () => {
        restoreTable();
        if (isCurrent()) {
            if (main) main.setAttribute('aria-busy', 'false');
            syncStateFilters();
        }
    }, {once: true});
    script.addEventListener('error', () => {
        restoreTable();
        showLoadError();
    }, {once: true});
    document.body.appendChild(script);

    $(page)
        .off('click.seattleManagementFilters')
        .on('click.seattleManagementFilters', '.table-switch-state button', function () {
            window.setTimeout(syncStateFilters, 0);
        });

    $(document)
        .off('pjax:send.seattleManagementPage pjax:popstate.seattleManagementPage')
        .on('pjax:send.seattleManagementPage pjax:popstate.seattleManagementPage', function () {
            if (Number(window.__seattleManagementEpoch) === epoch) window.__seattleManagementEpoch = epoch + 1;
            restoreTable();
            script.remove();
            $(page).off('.seattleManagementFilters');
            $(document).off('.seattleManagementPage');
        });
}();
