//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

!function () {
    const $page = $('[data-st-page="ticket-detail"]').first();
    if (!$page.length) return;
    $page.find('.st-ticket-messages').empty();

    const TYPE = {0: {label: i18n('售前咨询'), className: 'is-presale'}, 1: {label: i18n('售后支持'), className: 'is-aftersale'}};
    const PRIORITY = {0: {label: i18n('低优先级'), className: 'is-low'}, 1: {label: i18n('中优先级'), className: 'is-medium'}, 2: {label: i18n('高优先级'), className: 'is-high'}};
    const STATUS = {
        0: {label: i18n('待客服回复'), className: 'is-waiting', icon: 'schedule', hint: i18n('客服查看后会在这里回复')},
        1: {label: i18n('待我回复'), className: 'is-reply', icon: 'mark_chat_unread', hint: i18n('补充信息后客服会继续处理')},
        2: {label: i18n('已解决'), className: 'is-resolved', icon: 'task_alt', hint: i18n('完整沟通记录已为你归档')},
        3: {label: i18n('已关闭'), className: 'is-closed', icon: 'lock', hint: i18n('历史沟通记录仍可随时查看')}
    };
    const SENDER = {
        0: {label: i18n('我'), icon: 'person', className: 'is-user'},
        1: {label: i18n('客服'), icon: 'support_agent', className: 'is-admin'},
        2: {label: i18n('系统'), icon: 'info', className: 'is-system'}
    };

    const params = new URLSearchParams(window.location.search);
    const ticketId = Number(params.get('id')) || 0;
    const messageIds = new Set();
    let maxMessageId = 0;
    let minMessageId = 0;
    let currentTicket = null;
    let replyEditor = null;
    let replying = false;
    let polling = false;
    let detailLoading = false;
    let pollTimer = null;
    let destroyed = false;

    function threadBody() {
        return $page.find('.st-ticket-thread__body').get(0) || null;
    }

    function isThreadNearBottom(threshold = 120) {
        const body = threadBody();
        if (!body) return true;
        return body.scrollHeight - body.scrollTop - body.clientHeight <= threshold;
    }

    function scrollThreadToBottom(behavior = 'auto') {
        const body = threadBody();
        if (!body) return;
        const top = body.scrollHeight;
        if (typeof body.scrollTo === 'function') body.scrollTo({top: top, behavior: behavior});
        else body.scrollTop = top;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    const safeInlineHtml = value => window.SeattleTheme && typeof window.SeattleTheme.safeInlineHtml === 'function'
        ? window.SeattleTheme.safeInlineHtml(value)
        : escapeHtml(value);

    function safeImageUrl(value) {
        const raw = String(value == null ? '' : value).trim();
        if (!raw || raw === '/') return '';
        try {
            const url = new URL(raw, window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    function number(value, fallback) {
        const result = Number(value);
        return Number.isFinite(result) ? result : fallback;
    }

    function sanitizeHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = String(html == null ? '' : html);
        const allowedTags = new Set(['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'DEL', 'BLOCKQUOTE', 'UL', 'OL', 'LI', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HR', 'PRE', 'CODE', 'A', 'IMG', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD']);
        const dangerousTags = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'FORM', 'INPUT', 'BUTTON']);
        const cleanUrl = (value) => {
            try {
                const url = new URL(value, window.location.origin);
                return ['http:', 'https:'].includes(url.protocol) ? value : '';
            } catch (error) {
                return '';
            }
        };
        const walk = node => {
            Array.from(node.childNodes).forEach(child => {
                if (child.nodeType === Node.COMMENT_NODE) {
                    child.remove();
                    return;
                }
                if (child.nodeType !== Node.ELEMENT_NODE) return;
                const tag = child.tagName;
                if (!allowedTags.has(tag)) {
                    if (dangerousTags.has(tag)) child.remove();
                    else {
                        walk(child);
                        child.replaceWith(...Array.from(child.childNodes));
                    }
                    return;
                }
                Array.from(child.attributes).forEach(attribute => {
                    const name = attribute.name.toLowerCase();
                    let keep = false;
                    if (tag === 'A' && ['href', 'title'].includes(name)) keep = true;
                    if (tag === 'IMG' && ['src', 'alt', 'title', 'width', 'height'].includes(name)) keep = true;
                    if (tag === 'CODE' && name === 'class' && /^language-[\w-]+$/.test(attribute.value)) keep = true;
                    if (!keep) child.removeAttribute(attribute.name);
                });
                if (tag === 'A') {
                    const href = cleanUrl(child.getAttribute('href') || '');
                    if (href) child.setAttribute('href', href); else child.removeAttribute('href');
                    child.setAttribute('rel', 'noopener noreferrer nofollow');
                }
                if (tag === 'IMG') {
                    const src = cleanUrl(child.getAttribute('src') || '');
                    if (src) child.setAttribute('src', src); else child.remove();
                }
                walk(child);
            });
        };
        walk(template.content);
        return template.innerHTML;
    }

    function pill($element, item) {
        $element.removeClass('is-presale is-aftersale is-low is-medium is-high is-waiting is-reply is-resolved is-closed')
            .addClass(item.className).text(item.label);
    }

    function formatMoney(value) {
        const result = Number(value);
        return Number.isFinite(result) ? `${acgCurrencySymbol()}${result.toLocaleString('zh-CN', {maximumFractionDigits: 2})}` : '';
    }

    function messageHtml(item) {
        const sender = SENDER[number(item.sender_type, 0)] || SENDER[0];
        const kind = number(item.kind, 0);
        if (sender.className === 'is-system' || kind === 2) {
            const icon = kind === 2 ? 'lock' : 'info';
            const kindClass = kind === 2 ? ' is-closed' : '';
            return `<article class="st-ticket-event${kindClass}" data-message-id="${number(item.id, 0)}"><span class="material-icons-outlined">${icon}</span><div><strong>${escapeHtml(item.sender_name || sender.label)}</strong><div class="st-ticket-markdown">${sanitizeHtml(item.content)}</div><time>${escapeHtml(item.create_time || '')}</time></div></article>`;
        }
        const name = item.sender_name || sender.label;
        const finalBadge = kind === 1 ? '<span class="st-ticket-final"><span class="material-icons-outlined">task_alt</span>' + i18n('最终答复') + '</span>' : '';
        return `<article class="st-ticket-message ${sender.className}" data-message-id="${number(item.id, 0)}">
            <span class="st-ticket-message__avatar"><span class="material-icons-outlined">${sender.icon}</span></span>
            <div class="st-ticket-message__main">
                <div class="st-ticket-message__meta"><strong>${escapeHtml(name)}</strong>${sender.className === 'is-admin' ? '<span>' + i18n('官方客服') + '</span>' : ''}${finalBadge}<time>${escapeHtml(item.create_time || '')}</time></div>
                <div class="st-ticket-message__bubble"><div class="st-ticket-markdown">${sanitizeHtml(item.content)}</div></div>
            </div>
        </article>`;
    }

    function enhanceMessageContent($scope) {
        $scope.find('.st-ticket-markdown img').attr('loading', 'lazy').each(function () {
            $(this).attr('role', 'button').attr('tabindex', '0').attr('title', i18n('点击查看大图'));
        });
        $scope.find('.st-ticket-markdown a').each(function () {
            if (this.hostname && this.hostname !== window.location.hostname) $(this).attr({target: '_blank', rel: 'noopener noreferrer'});
        });
    }

    function appendMessages(list, initial) {
        if (!Array.isArray(list) || !list.length) return;
        const nearBottom = isThreadNearBottom();
        const fresh = list.filter(item => {
            const id = number(item.id, 0);
            if (!id || messageIds.has(id)) return false;
            messageIds.add(id);
            maxMessageId = Math.max(maxMessageId, id);
            minMessageId = minMessageId === 0 ? id : Math.min(minMessageId, id);
            return true;
        });
        if (!fresh.length) return;
        const $content = $(fresh.map(messageHtml).join(''));
        $page.find('.st-ticket-messages').append($content);
        enhanceMessageContent($content);
        if (initial) {
            setTimeout(() => scrollThreadToBottom('auto'), 80);
        } else if (nearBottom) {
            $page.find('.st-ticket-new-messages').prop('hidden', true);
            requestAnimationFrame(() => scrollThreadToBottom('smooth'));
        } else if (fresh.some(item => number(item.sender_type, 0) !== 0)) {
            $page.find('.st-ticket-new-messages').prop('hidden', false);
        }
    }

    function prependMessages(list) {
        if (!Array.isArray(list) || !list.length) return 0;
        const body = threadBody();
        const oldHeight = body ? body.scrollHeight : 0;
        const oldScrollTop = body ? body.scrollTop : 0;
        const boundary = minMessageId;
        const fresh = list.filter(item => {
            const id = number(item.id, 0);
            if (!id || messageIds.has(id) || (boundary > 0 && id >= boundary)) return false;
            messageIds.add(id);
            return true;
        }).sort((a, b) => number(a.id, 0) - number(b.id, 0));
        if (!fresh.length) return 0;
        minMessageId = Math.min(...fresh.map(item => number(item.id, boundary || Number.MAX_SAFE_INTEGER)));
        const $content = $(fresh.map(messageHtml).join(''));
        $page.find('.st-ticket-messages').prepend($content);
        enhanceMessageContent($content);
        if (body) body.scrollTop = oldScrollTop + Math.max(0, body.scrollHeight - oldHeight);
        return fresh.length;
    }

    function renderRelated(ticket) {
        const context = ticket.context || {};
        const $card = $page.find('.st-ticket-related-card');
        const $target = $page.find('.st-ticket-related');
        let html = '';

        if (number(ticket.type, 0) === 0 && (ticket.commodity_name || context.commodity_name || context.commodity)) {
            const commodity = context.commodity || context;
            const name = ticket.commodity_name || commodity.name || context.commodity_name || i18n('相关商品');
            const cover = safeImageUrl(commodity.cover || context.cover || '/favicon.ico');
            html = `<div class="st-ticket-related__item">${cover ? `<img src="${escapeHtml(cover)}" alt="">` : '<span class="st-ticket-related__order-icon"><span class="material-icons-outlined">inventory_2</span></span>'}<span><small>${i18n('相关商品')}</small><strong>${safeInlineHtml(name)}</strong>${commodity.id ? `<em>${i18n('商品')} ID ${escapeHtml(commodity.id)}</em>` : ''}</span></div>`;
        } else if (number(ticket.type, 0) === 1 && (ticket.order_trade_no || context.trade_no || context.order)) {
            const order = context.order || context;
            const commodity = context.commodity || ticket.commodity || {};
            const tradeNo = ticket.order_trade_no || order.trade_no || context.trade_no;
            const guest = number(ticket.order_source, number(ticket.order_source_code, 0)) === 2 || ticket.order_source === 'guest';
            const cover = safeImageUrl(order.cover || commodity.cover);
            const commodityName = guest ? i18n('订单待人工核验') : (order.commodity_name || ticket.commodity_name || commodity.name || i18n('商品订单'));
            html = `<div class="st-ticket-related__item${guest ? ' is-guest' : ''}">${cover ? `<img src="${escapeHtml(cover)}" alt="">` : '<span class="st-ticket-related__order-icon"><span class="material-icons-outlined">receipt_long</span></span>'}<span><small>${guest ? i18n('游客订单 · 待人工核验') : i18n('相关订单')}</small><strong>${safeInlineHtml(commodityName)}</strong><em>${escapeHtml(tradeNo || '')}${order.amount != null ? ` · ${formatMoney(order.amount)}` : ''}${order.pay_time || order.create_time ? ` · ${escapeHtml(order.pay_time || order.create_time)}` : ''}</em></span></div>`;
        }

        $target.html(html);
        $card.prop('hidden', html === '');
    }

    function renderProof(ticket) {
        const proofPath = safeImageUrl(ticket.proof_path || (ticket.proof && ticket.proof.url) || '');
        const hasProof = !!proofPath;
        $page.find('.st-ticket-proof-card').prop('hidden', !hasProof);
        $page.find('.st-ticket-proof-view img').attr('src', hasProof ? proofPath : '');
    }

    function updateTicketStatus(status) {
        if (!currentTicket) return;
        currentTicket.status = number(status, currentTicket.status);
        const statusInfo = STATUS[currentTicket.status] || STATUS[0];
        pill($page.find('.st-ticket-detail-status'), statusInfo);
        $page.find('.st-ticket-detail-state')
            .removeClass('is-waiting is-reply is-resolved is-closed')
            .addClass(statusInfo.className)
            .attr('aria-label', `${statusInfo.label}，${statusInfo.hint}`);
        $page.find('.st-ticket-detail-state__icon .material-icons-outlined').text(statusInfo.icon);
        $page.find('.st-ticket-detail-state__hint').text(statusInfo.hint);
        $page.find('[data-context="status"]').text(statusInfo.label);
        const terminal = currentTicket.status >= 2;
        if (terminal) {
            clearTimeout(pollTimer);
            $page.find('.st-ticket-reply').remove();
            replyEditor = null;
        } else {
            $page.find('.st-ticket-reply').prop('hidden', false);
        }
        $page.find('.st-ticket-live').toggleClass('is-archived', terminal).html(`<i></i>${terminal ? i18n('记录已归档') : i18n('自动更新')}`);
        $page.find('.st-ticket-readonly').prop('hidden', !terminal);
    }

    function renderTicket(ticket) {
        currentTicket = ticket;
        const type = TYPE[number(ticket.type, 0)] || TYPE[0];
        const priority = PRIORITY[number(ticket.priority, 1)] || PRIORITY[1];
        $page.find('.st-ticket-detail-number').text(ticket.ticket_no || `${i18n('工单')} #${ticket.id}`);
        $page.find('.st-ticket-detail-title').text(ticket.title || i18n('未命名工单'));
        pill($page.find('.st-ticket-detail-type'), type);
        pill($page.find('.st-ticket-detail-priority'), priority);
        $page.find('[data-context="create_time"]').text(ticket.create_time || '—');
        $page.find('[data-context="update_time"]').text(ticket.update_time || ticket.last_message_time || '—');
        $page.find('[data-context="priority"]').text(priority.label);
        renderRelated(ticket);
        renderProof(ticket);
        updateTicketStatus(ticket.status);
    }

    function editorContent() {
        if (!replyEditor || !replyEditor.cm) return '';
        const markdown = replyEditor.cm.getValue().trim();
        if (!markdown) return '';
        return replyEditor.getHTML();
    }

    function submitReply() {
        if (replying || !currentTicket || currentTicket.status >= 2) return;
        const content = editorContent();
        if (!content) {
            message.error(i18n('请先填写回复内容'));
            return;
        }
        replying = true;
        const $button = $page.find('.st-ticket-reply__submit');
        $button.prop('disabled', true).attr('aria-busy', 'true').addClass('is-loading').find('span:last').text(i18n('发送中…'));
        util.post({
            url: '/user/api/ticket/reply',
            data: {id: ticketId, content: content},
            done: res => {
                if (destroyed) return;
                replying = false;
                $button.prop('disabled', false).attr('aria-busy', 'false').removeClass('is-loading').find('span:last').text(i18n('发送回复'));
                const payload = res.data || {};
                appendMessages(payload.message ? [payload.message] : [], false);
                updateTicketStatus(payload.status);
                if (payload.last_message_time) $page.find('[data-context="update_time"]').text(payload.last_message_time);
                replyEditor.setHTML('');
                message.success(i18n('回复已发送'));
                requestAnimationFrame(() => scrollThreadToBottom('smooth'));
                if (window.seattleTicketRefreshBadge) window.seattleTicketRefreshBadge();
            },
            error: res => {
                if (destroyed) return;
                replying = false;
                $button.prop('disabled', false).attr('aria-busy', 'false').removeClass('is-loading').find('span:last').text(i18n('发送回复'));
                message.error(res && res.msg ? res.msg : i18n('回复发送失败'));
            },
            fail: () => {
                if (destroyed) return;
                replying = false;
                $button.prop('disabled', false).attr('aria-busy', 'false').removeClass('is-loading').find('span:last').text(i18n('发送回复'));
                message.error(i18n('网络连接失败，请稍后重试'));
            }
        });
    }

    function schedulePoll() {
        if (destroyed || !currentTicket || currentTicket.status >= 2) return;
        clearTimeout(pollTimer);
        pollTimer = setTimeout(pollMessages, 15000);
    }

    function loadEarlier() {
        if (destroyed || !minMessageId) return;
        const $button = $page.find('.st-ticket-history-more');
        $button.prop('disabled', true).addClass('is-loading').find('span:last').text(i18n('正在加载…'));
        util.post({
            url: '/user/api/ticket/messages',
            data: {id: ticketId, before_id: minMessageId, limit: 50},
            loader: false,
            done: res => {
                if (destroyed) return;
                const payload = res.data || {};
                const count = prependMessages(payload.list || []);
                const hasMore = payload.has_more != null ? !!payload.has_more : count >= 50;
                const body = threadBody();
                const heightBeforeButton = body ? body.scrollHeight : 0;
                const scrollBeforeButton = body ? body.scrollTop : 0;
                $button.prop('disabled', false).removeClass('is-loading').prop('hidden', !hasMore).find('span:last').text(i18n('加载更早的沟通记录'));
                if (body) body.scrollTop = scrollBeforeButton + (body.scrollHeight - heightBeforeButton);
            },
            error: () => {
                if (destroyed) return;
                $button.prop('disabled', false).removeClass('is-loading').find('span:last').text(i18n('加载失败，点击重试'));
            },
            fail: () => {
                if (destroyed) return;
                $button.prop('disabled', false).removeClass('is-loading').find('span:last').text(i18n('加载失败，点击重试'));
            }
        });
    }

    function pollMessages() {
        if (destroyed || (currentTicket && currentTicket.status >= 2)) return;
        if (document.hidden || polling || !currentTicket) {
            schedulePoll();
            return;
        }
        polling = true;
        util.post({
            url: '/user/api/ticket/messages',
            data: {id: ticketId, after_id: maxMessageId},
            loader: false,
            done: res => {
                if (destroyed) return;
                polling = false;
                const payload = res.data || {};
                appendMessages(payload.list || [], false);
                if (payload.status != null) updateTicketStatus(payload.status);
                if (payload.last_message_time) $page.find('[data-context="update_time"]').text(payload.last_message_time);
                schedulePoll();
            },
            error: () => { if (!destroyed) { polling = false; schedulePoll(); } },
            fail: () => { if (!destroyed) { polling = false; schedulePoll(); } }
        });
    }

    function showError(response) {
        $page.find('.st-ticket-detail-loading').hide();
        $page.find('.st-ticket-detail').prop('hidden', true);
        $page.find('.st-ticket-detail-error p').text(response && response.msg ? response.msg : i18n('工单可能不存在，或你没有查看权限。'));
        $page.find('.st-ticket-detail-error').prop('hidden', false);
    }

    function loadDetail() {
        if (destroyed || detailLoading) return;
        if (!ticketId) {
            showError();
            return;
        }
        detailLoading = true;
        $page.find('[data-st-ticket-retry]').prop('disabled', true).attr('aria-busy', 'true');
        $page.find('.st-ticket-detail-loading').show();
        $page.find('.st-ticket-detail-error, .st-ticket-detail').prop('hidden', true);
        util.post({
            url: '/user/api/ticket/detail',
            data: {id: ticketId, limit: 100},
            loader: false,
            done: res => {
                if (destroyed) return;
                detailLoading = false;
                $page.find('[data-st-ticket-retry]').prop('disabled', false).attr('aria-busy', 'false');
                const payload = res.data || {};
                if (!payload.ticket) {
                    showError();
                    return;
                }
                renderTicket(payload.ticket);
                appendMessages(payload.messages || [], true);
                $page.find('.st-ticket-history-more').prop('hidden', !payload.has_more);
                $page.find('.st-ticket-detail-loading').hide();
                $page.find('.st-ticket-detail-error').prop('hidden', true);
                $page.find('.st-ticket-detail').prop('hidden', false);
                if (replyEditor && replyEditor.cm) setTimeout(() => replyEditor.cm.refresh(), 0);
                if (window.seattleTicketRefreshBadge) window.seattleTicketRefreshBadge();
                schedulePoll();
            },
            error: response => {
                if (destroyed) return;
                detailLoading = false;
                $page.find('[data-st-ticket-retry]').prop('disabled', false).attr('aria-busy', 'false');
                showError(response);
            },
            fail: () => {
                if (destroyed) return;
                detailLoading = false;
                $page.find('[data-st-ticket-retry]').prop('disabled', false).attr('aria-busy', 'false');
                showError();
            }
        });
    }

    const $editor = $page.find('#ticket-reply-editor');
    if (window.EditorV2 && $editor.length) {
        $editor.html(EditorV2.buildHtml({
            name: 'content',
            placeholder: i18n('补充新的信息，或直接粘贴截图…'),
            allowHtmlSource: false,
            allowRawHtml: false
        }));
        replyEditor = EditorV2.register($editor.get(0), {
            name: 'content',
            uploadUrl: '/user/api/ticket/upload',
            height: 230,
            allowHtmlSource: false,
            allowRawHtml: false
        });
    } else {
        $editor.html('<div class="st-ticket-editor-error">' + i18n('回复编辑器加载失败，请刷新页面后重试。') + '</div>');
        $page.find('.st-ticket-reply__submit').prop('disabled', true).attr('aria-disabled', 'true');
    }

    $page.find('.st-ticket-reply__submit').on('click', submitReply);
    $page.find('.st-ticket-history-more').on('click', loadEarlier);
    $page.find('[data-st-ticket-retry]').on('click', loadDetail);
    $page.find('.st-ticket-new-messages').on('click', function () {
        $(this).prop('hidden', true);
        scrollThreadToBottom('smooth');
    });
    $page.find('.st-ticket-thread__body').on('scroll.seattleTicketDetail', function () {
        if (isThreadNearBottom(72)) $page.find('.st-ticket-new-messages').prop('hidden', true);
    });
    $page.find('.st-ticket-proof-view').on('click', function () {
        const src = $(this).find('img').attr('src');
        component.previewImage(src);
    });
    $(document).off('.seattleTicketDetail');
    $(document).on('click.seattleTicketDetail keydown.seattleTicketDetail', '[data-st-page="ticket-detail"] .st-ticket-markdown img', function (event) {
        if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        const src = $(this).attr('src');
        component.previewImage(src);
    });
    $(document).on('visibilitychange.seattleTicketDetail', function () {
        if (!document.hidden && currentTicket) {
            clearTimeout(pollTimer);
            pollMessages();
        }
    });
    $(document).on('pjax:send.seattleTicketDetail pjax:popstate.seattleTicketDetail', function () {
        destroyed = true;
        clearTimeout(pollTimer);
        $page.find('.st-ticket-thread__body').off('.seattleTicketDetail');
        $(document).off('.seattleTicketDetail');
    });

    loadDetail();
}();
