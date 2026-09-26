(function () {
    'use strict';

    var requestVersion = 0;

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    function normalize(row) {
        var source = row && (row.message || row.system_message) || row || {};
        return {
            id: row && (row.id || row.user_message_id) || source.user_message_id || source.id || 0,
            title: row && row.title || source.title || i18n('未命名消息'),
            summary: row && row.summary || source.summary || i18n('点击查看消息详情'),
            readTime: row && row.read_time || source.read_time || null
        };
    }

    function setCount(selector, value) {
        var count = Math.max(0, parseInt(value, 10) || 0);
        document.querySelectorAll(selector).forEach(function (badge) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.classList.toggle('is-empty', count < 1);
        });
    }

    function refreshTicketBadge() {
        if (!window.jQuery || !document.querySelector('.st-ticket-badge')) return;
        window.jQuery.ajax({
            type: 'POST', url: '/user/api/ticket/badge', data: {}, global: false,
            success: function (response) {
                if (response && response.code === 200) setCount('.st-ticket-badge', response.data && response.data.count);
            }
        });
    }

    function renderMessages(rows) {
        var target = document.querySelector('.st-message-recent');
        if (!target) return;
        if (!rows.length) {
            target.innerHTML = '<div class="st-empty-compact"><span class="material-icons-outlined">notifications_none</span><span>' + i18n('暂时没有新消息') + '</span></div>';
            return;
        }
        target.innerHTML = rows.map(function (raw) {
            var item = normalize(raw);
            return '<button type="button" class="st-message-row' + (item.readTime ? '' : ' is-unread') + '" data-st-message-id="' + encodeURIComponent(item.id) + '">' +
                '<span class="material-icons-outlined">' + (item.readTime ? 'drafts' : 'mark_email_unread') + '</span>' +
                '<span><strong>' + escapeHtml(item.title) + '</strong><small>' + escapeHtml(item.summary) + '</small></span>' +
                '<span class="material-icons-outlined">chevron_right</span></button>';
        }).join('');
    }

    function renderMessageError() {
        var target = document.querySelector('.st-message-recent');
        if (!target) return;
        target.innerHTML = '<button type="button" class="st-message-row" data-st-message-retry><span class="material-icons-outlined">sync_problem</span><span><strong>' + i18n('消息同步失败') + '</strong><small>' + i18n('点击重新加载') + '</small></span><span class="material-icons-outlined">refresh</span></button>';
    }

    function refreshMessages() {
        if (!window.jQuery || !document.querySelector('.st-message-center')) return;
        var version = ++requestVersion;
        window.jQuery.ajax({
            type: 'POST', url: '/user/api/message/recent', data: {}, global: false,
            success: function (response) {
                if (version !== requestVersion) return;
                if (!response || response.code !== 200) {
                    renderMessageError();
                    return;
                }
                var payload = response.data || {};
                var rows = Array.isArray(payload.list) ? payload.list.slice(0, 6) : (Array.isArray(payload.recent) ? payload.recent.slice(0, 6) : []);
                setCount('.st-message-badge, .st-message-nav-badge', payload.count != null ? payload.count : payload.unread_count);
                renderMessages(rows);
            },
            error: function () { if (version === requestVersion) renderMessageError(); }
        });
    }

    function openMessage(id, button) {
        if (!window.jQuery || !window.component || typeof window.component.previewMessage !== 'function') {
            window.location.href = '/user/message/index';
            return;
        }
        button.disabled = true;
        window.jQuery.ajax({
            type: 'POST', url: '/user/api/message/detail', data: {id: id}, global: false,
            success: function (response) {
                if (!response || response.code !== 200) {
                    if (window.message && typeof window.message.error === 'function') window.message.error(response && response.msg ? response.msg : i18n('消息读取失败'));
                    return;
                }
                var source = response.data && response.data.message ? response.data.message : response.data;
                window.component.previewMessage(source);
                refreshMessages();
            },
            error: function () { if (window.message && typeof window.message.error === 'function') window.message.error(i18n('消息读取失败，请检查网络后重试')); },
            complete: function () { button.disabled = false; }
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-st-message-id]') : null;
        var retry = event.target.closest ? event.target.closest('[data-st-message-retry]') : null;
        if (retry) {
            event.preventDefault();
            retry.disabled = true;
            refreshMessages();
            return;
        }
        if (!button) return;
        event.preventDefault();
        openMessage(decodeURIComponent(button.getAttribute('data-st-message-id') || ''), button);
    });

    window.seattleTicketRefreshBadge = refreshTicketBadge;
    window.seattleMessageRefresh = refreshMessages;
    window.seattleMessageNotifyChanged = function (options) {
        refreshMessages();
        if (window.jQuery) window.jQuery(document).trigger('seattle:message-changed', [options || {}]);
    };

    function refreshAll() {
        refreshTicketBadge();
        refreshMessages();
    }
    document.addEventListener('seattle:page-ready', refreshAll);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshAll(); });
    window.setInterval(function () { if (!document.hidden) refreshAll(); }, 60000);
    refreshAll();
}());
