!function () {
    const $page = $('[data-st-page="message"]').first();
    if (!$page.length || !$page.find('#message-table').length) return;
    const pageNode = $page.get(0);
    const pageEpoch = (Number(window.__seattleMessagePageEpoch) || 0) + 1;
    window.__seattleMessagePageEpoch = pageEpoch;
    const isPageCurrent = () => Number(window.__seattleMessagePageEpoch) === pageEpoch
        && document.contains(pageNode)
        && $page.find('#message-table').length > 0;
    $page.find('[data-message-action="delete-selected"]')
        .attr({'aria-label': i18n('删除选中的消息'), title: i18n('删除选中的消息')});
    $page.find('[data-message-action="clear"]')
        .attr({'aria-label': i18n('清空全部消息'), title: i18n('清空全部消息')});
    $(document)
        .off('pjax:send.seattleMessagePageLifecycle pjax:popstate.seattleMessagePageLifecycle')
        .on('pjax:send.seattleMessagePageLifecycle pjax:popstate.seattleMessagePageLifecycle', () => {
            if (Number(window.__seattleMessagePageEpoch) === pageEpoch) {
                window.__seattleMessagePageEpoch = pageEpoch + 1;
            }
        });

    const escapeHtml = value => String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

    const normalizeMessage = row => {
        row = row || {};
        const source = row.message || row.system_message || row;
        return {
            id: row.id || row.user_message_id || source.user_message_id || 0,
            message_id: row.message_id || source.id || 0,
            title: row.title || source.title || i18n('未命名消息'),
            summary: row.summary || source.summary || '',
            content: row.content || source.content || '',
            jump_url: row.jump_url || source.jump_url || row.url || source.url || row.link_url || source.link_url || '',
            create_time: row.create_time || source.create_time || '',
            update_time: row.update_time || source.update_time || '',
            read_time: row.read_time || null,
            became_read: row.became_read,
            unread_count: row.unread_count
        };
    };

    const table = new Table('/user/api/message/data', '#message-table');
    let focusAfterReload = null;
    let mutationBusy = false;
    const $mutationButtons = $page.find('[data-message-action]');

    function setMutationBusy(busy) {
        mutationBusy = busy;
        $mutationButtons.prop('disabled', busy).attr('aria-busy', String(busy));
    }
    table.setPagination(10, [10]);
    table.setColumns([
        {checkbox: true, width: 48},
        {
            field: 'title',
            title: i18n('消息内容'),
            formatter: (_, row) => {
                const item = normalizeMessage(row);
                return `<div class="st-message-cell${item.read_time ? '' : ' is-unread'}">
                    <span class="st-message-cell__dot"></span>
                    <span class="st-message-cell__copy">
                        <strong>${escapeHtml(item.title)}</strong>
                        <small>${escapeHtml(item.summary || i18n('点击查看消息详情'))}</small>
                    </span>
                </div>`;
            }
        },
        {
            field: 'read_time',
            title: i18n('状态'),
            width: 92,
            formatter: value => value
                ? '<span class="st-message-status is-read"><span class="material-icons-outlined" aria-hidden="true">drafts</span>' + i18n('已读') + '</span>'
                : '<span class="st-message-status is-unread"><span class="material-icons-outlined" aria-hidden="true">mark_email_unread</span>' + i18n('未读') + '</span>'
        },
        {field: 'create_time', title: i18n('接收时间'), width: 170, formatter: value => escapeHtml(value || '—')},
        {
            field: 'operation',
            title: i18n('操作'),
            width: 152,
            type: 'button',
            buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-eye',
                    class: 'text-primary',
                    title: i18n('查看'),
                    click: (event, value, row) => openMessage(normalizeMessage(row).id, event.currentTarget)
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: 'text-danger',
                    title: i18n('删除'),
                    click: (event, value, row) => deleteMessages([normalizeMessage(row).id])
                }
            ]
        }
    ]);
    table.setState('status', [
        {id: 0, name: i18n('未读')},
        {id: 1, name: i18n('已读')}
    ]);
    table.onResponse(response => {
        const total = Math.max(0, Number(response && response.data && response.data.total) || 0);
        $page.find('.st-card__sub').text(total > 0 ? `${i18n('共')} ${total} ${i18n('条消息')} · ${i18n('打开后自动标记为已读')}` : i18n('打开消息后自动标记为已读'));
    });
    table.onComplete(() => {
        if (!focusAfterReload) return;
        const target = focusAfterReload;
        focusAfterReload = null;
        requestAnimationFrame(() => {
            if (target && document.contains(target) && typeof target.focus === 'function') target.focus();
        });
    });
    table.render();

    function syncMessageState(resetPage = false) {
        if (typeof window.seattleMessageNotifyChanged === 'function') {
            window.seattleMessageNotifyChanged({resetPage: resetPage});
        } else if (resetPage) {
            table.reload({pageNumber: 1});
        } else {
            table.refresh(false);
        }
    }

    function detailPayload(response) {
        return normalizeMessage(response && response.data && response.data.message ? response.data.message : response.data);
    }

    function openMessage(id, trigger) {
        const $trigger = $(trigger || []);
        if (!id || !isPageCurrent() || $trigger.hasClass('is-loading')) return;
        $trigger.addClass('is-loading');
        util.post({
            url: '/user/api/message/detail',
            data: {id: id},
            loader: false,
            done: response => {
                if (!isPageCurrent()) return;
                $trigger.removeClass('is-loading');
                const detail = detailPayload(response);
                if (typeof window.seattleMessageRefresh === 'function') window.seattleMessageRefresh();
                detail.onClose = () => {
                    if (!isPageCurrent()) return;
                    focusAfterReload = $page.find('.table-switch-state button.active').get(0)
                        || document.querySelector('.st-message-btn');
                    syncMessageState(false);
                };
                component.previewMessage(detail);
            },
            error: response => {
                if (!isPageCurrent()) return;
                $trigger.removeClass('is-loading');
                message.error((response && response.msg) || i18n('消息读取失败'));
            },
            fail: () => {
                if (!isPageCurrent()) return;
                $trigger.removeClass('is-loading');
                message.error(i18n('消息读取失败，请稍后重试'));
            }
        });
    }

    function deleteMessages(ids) {
        if (mutationBusy) return;
        ids = (Array.isArray(ids) ? ids : []).map(Number).filter(id => id > 0);
        if (!ids.length) {
            message.alert(i18n('请先选择需要删除的消息'), 'error');
            return;
        }
        message.ask(ids.length > 1 ? `${i18n('确定删除选中的')} ${ids.length} ${i18n('条消息吗？删除后无法恢复。')}` : i18n('确定删除这条消息吗？删除后无法恢复。'), () => {
            if (mutationBusy) return;
            setMutationBusy(true);
            util.post({
                url: '/user/api/message/del',
                data: {list: ids},
                done: () => {
                    if (!isPageCurrent()) return;
                    setMutationBusy(false);
                    message.success(i18n('消息已删除'));
                    syncMessageState();
                },
                error: response => {
                    if (!isPageCurrent()) return;
                    setMutationBusy(false);
                    message.error((response && response.msg) || i18n('消息删除失败，请重试'));
                },
                fail: () => {
                    if (!isPageCurrent()) return;
                    setMutationBusy(false);
                    message.error(i18n('网络连接失败，请稍后重试'));
                }
            });
        });
    }

    $page.find('[data-message-action="delete-selected"]').on('click', () => {
        deleteMessages(table.getSelectionIds());
    });

    $page.find('[data-message-action="clear"]').on('click', () => {
        if (mutationBusy) return;
        message.ask(i18n('确定清空全部消息吗？此操作无法恢复。'), () => {
            if (mutationBusy) return;
            setMutationBusy(true);
            util.post({
                url: '/user/api/message/clear',
                data: {},
                done: () => {
                    if (!isPageCurrent()) return;
                    setMutationBusy(false);
                    message.success(i18n('全部消息已清空'));
                    syncMessageState(true);
                },
                error: response => {
                    if (!isPageCurrent()) return;
                    setMutationBusy(false);
                    message.error((response && response.msg) || i18n('消息清空失败，请重试'));
                },
                fail: () => {
                    if (!isPageCurrent()) return;
                    setMutationBusy(false);
                    message.error(i18n('网络连接失败，请稍后重试'));
                }
            });
        });
    });

    $(document).off('seattle:message-changed.seattleMessagePage').on('seattle:message-changed.seattleMessagePage', (event, options = {}) => {
        if (!isPageCurrent()) return;
        if (options.resetPage) table.reload({pageNumber: 1});
        else table.refresh(false);
    });
}();
