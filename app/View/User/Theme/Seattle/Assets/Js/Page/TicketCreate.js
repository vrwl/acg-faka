//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

!function () {
    const $page = $('[data-st-page="ticket-create"]').first();
    if (!$page.length) return;
    const pageNode = $page[0];
    const isPageCurrent = () => document.contains(pageNode) && $('[data-st-page="ticket-create"]').first()[0] === pageNode;

    $page.find('#ticket-commodity, #ticket-order').each(function () {
        const $select = $(this);
        if ($select.data('select2') && $.fn.select2) $select.select2('destroy');
        $select.siblings('.select2-container').remove();
        $select.removeClass('select2-hidden-accessible').removeAttr('data-select2-id aria-hidden tabindex');
        $select.find('[data-select2-id]').removeAttr('data-select2-id');
    });

    const state = {
        type: 0,
        priority: 1,
        orderMode: 'account',
        proofId: 0,
        proofPath: '',
        proofName: '',
        commodity: null,
        order: null,
        submitting: false
    };
    let editor = null;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    const safeInlineHtml = value => window.SeattleTheme && typeof window.SeattleTheme.safeInlineHtml === 'function'
        ? window.SeattleTheme.safeInlineHtml(value)
        : escapeHtml(value);
    const plainText = value => window.SeattleTheme && typeof window.SeattleTheme.plainText === 'function'
        ? window.SeattleTheme.plainText(value)
        : String(value == null ? '' : value).replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();

    function safeImageUrl(value, fallback = '/favicon.ico') {
        try {
            const url = new URL(String(value || ''), window.location.origin);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : fallback;
        } catch (error) {
            return fallback;
        }
    }

    function safeRedirect(value, fallback) {
        try {
            const url = new URL(String(value || ''), window.location.origin);
            if (url.origin !== window.location.origin || !/^\/user\/ticket\/detail(?:\/|$|\?)/.test(url.pathname + url.search)) return fallback;
            return url.pathname + url.search + url.hash;
        } catch (error) {
            return fallback;
        }
    }

    function createOptionNode(item, kind) {
        if (!item || item.loading) return item && item.text ? plainText(item.text) : '';
        const $row = $('<span class="st-ticket-option"></span>');
        const cover = safeImageUrl(item.cover);
        $('<img>').attr({src: cover, alt: ''}).appendTo($row);
        const $copy = $('<span class="st-ticket-option__copy"></span>').appendTo($row);
        $('<strong>').html(safeInlineHtml(item.display_name || item.text || '')).appendTo($copy);
        if (kind === 'commodity') {
            $('<small>').text([plainText(item.category_name || item.category || i18n('未分类')), `${i18n('商品')} ID ${item.id}`].join(' · ')).appendTo($copy);
        } else {
            const meta = [item.trade_no || '', item.amount != null ? `${acgCurrencySymbol()}${item.amount}` : '', item.pay_time || item.create_time || ''].filter(Boolean).join(' · ');
            $('<small>').text(meta).appendTo($copy);
        }
        return $row;
    }

    function initRemoteSelect(selector, endpoint, kind, placeholder) {
        const $select = $page.find(selector);
        if (!$select.length || !$.fn.select2) return;
        $select.select2({
            width: '100%',
            placeholder: placeholder,
            allowClear: true,
            minimumInputLength: 0,
            language: {
                inputTooShort: () => i18n('输入关键词可以更快找到'),
                searching: () => i18n('正在搜索…'),
                noResults: () => i18n('没有找到匹配结果'),
                errorLoading: () => i18n('加载失败，请稍后再试')
            },
            ajax: {
                url: endpoint,
                type: 'POST',
                dataType: 'json',
                delay: 250,
                data: params => ({keyword: params.term || '', page: params.page || 1, limit: 12}),
                processResults: (res, params) => {
                    const payload = res && res.code === 200 && res.data ? res.data : {};
                    const list = Array.isArray(payload.list) ? payload.list : [];
                    const page = params.page || 1;
                    return {
                        results: list.map(item => {
                            const displayName = kind === 'commodity' ? item.name : item.commodity_name;
                            return {...item, id: item.id, display_name: displayName, text: plainText(displayName)};
                        }),
                        pagination: {more: page * 12 < Number(payload.total || 0)}
                    };
                }
            },
            templateResult: item => createOptionNode(item, kind),
            templateSelection: item => $('<span></span>').text(plainText(item.text || placeholder))
        }).on('select2:select', event => {
            if (kind === 'commodity') state.commodity = event.params.data;
            else state.order = event.params.data;
            updateSummary();
            if (kind === 'order') renderPickedOrder();
        }).on('select2:clear', () => {
            if (kind === 'commodity') state.commodity = null;
            else state.order = null;
            updateSummary();
            if (kind === 'order') renderPickedOrder();
        });
    }

    function renderPickedOrder() {
        const $picked = $page.find('.st-ticket-order-picked');
        if (!state.order) {
            $picked.prop('hidden', true).empty();
            return;
        }
        const cover = escapeHtml(safeImageUrl(state.order.cover));
        const when = escapeHtml(state.order.pay_time || state.order.create_time || '');
        $picked.html(`<img src="${cover}" alt=""><span><small>${i18n('已选择订单')}</small><strong>${safeInlineHtml(state.order.commodity_name || i18n('商品订单'))}</strong><em>${escapeHtml(state.order.trade_no || '')}${state.order.amount != null ? ` · ${acgCurrencySymbol()}${escapeHtml(state.order.amount)}` : ''}${when ? ` · ${when}` : ''}</em></span><span class="material-icons-outlined">verified</span>`).prop('hidden', false);
    }

    function updateSummary() {
        const isAfter = state.type === 1;
        $page.find('.st-ticket-summary__type .material-icons-outlined').first().text(isAfter ? 'handyman' : 'question_answer');
        $page.find('.st-ticket-summary__type strong').text(isAfter ? i18n('售后支持') : i18n('售前咨询'));
        $page.find('[data-summary="priority"]').text([i18n('低'), i18n('中'), i18n('高')][state.priority] || i18n('中'));

        let relation = i18n('尚未选择');
        if (!isAfter && state.commodity) relation = plainText(state.commodity.text || state.commodity.name || i18n('已选择商品'));
        if (isAfter && state.orderMode === 'account' && state.order) relation = state.order.trade_no || i18n('已选择订单');
        if (isAfter && state.orderMode === 'manual') relation = $page.find('input[name="trade_no"]').val().trim() || i18n('等待输入订单号');
        $page.find('[data-summary="relation"]').text(relation).attr('title', relation);
        $page.find('[data-summary="proof"]').text(isAfter ? (state.proofPath ? i18n('已添加') : i18n('等待上传')) : i18n('无需上传'))
            .toggleClass('is-ready', isAfter && !!state.proofPath);
    }

    function switchType(type) {
        state.type = Number(type) === 1 ? 1 : 0;
        $page.find('.st-ticket-type').each(function () {
            const active = Number($(this).data('ticket-type')) === state.type;
            $(this).toggleClass('is-active', active).attr('aria-checked', String(active));
        });
        const isAfter = state.type === 1;
        $page.attr('data-ticket-type-state', isAfter ? 'aftersale' : 'presale')
            .toggleClass('is-ticket-aftersale', isAfter)
            .toggleClass('is-ticket-presale', !isAfter);
        $page.find('.st-ticket-relation--commodity').prop('hidden', isAfter).toggleClass('is-active', !isAfter).attr('aria-hidden', String(isAfter));
        $page.find('.st-ticket-relation--order').prop('hidden', !isAfter).toggleClass('is-active', isAfter).attr('aria-hidden', String(!isAfter));
        $page.find('.st-ticket-relation-title').text(isAfter ? i18n('关联订单与购买凭证') : i18n('关联商品'));
        $page.find('.st-ticket-relation-sub').text(isAfter ? i18n('选择本人订单或手动填写订单号，并上传购买凭证') : i18n('可选，关联后客服能更快理解你的问题'));
        updateSummary();
        setTimeout(() => {
            if (!isPageCurrent()) return;
            $page.find('#ticket-commodity, #ticket-order').trigger('change.select2');
            editor && editor.cm && editor.cm.refresh();
        }, 0);
    }

    function switchOrderMode(mode) {
        state.orderMode = mode === 'manual' ? 'manual' : 'account';
        $page.attr('data-ticket-order-mode', state.orderMode);
        $page.find('[data-order-mode]').each(function () {
            const active = $(this).data('order-mode') === state.orderMode;
            $(this).toggleClass('is-active', active).attr('aria-selected', String(active));
        });
        $page.find('[data-order-panel]').each(function () {
            const active = $(this).data('order-panel') === state.orderMode;
            $(this).prop('hidden', !active).toggleClass('is-active', active).attr('aria-hidden', String(!active));
        });
        updateSummary();
    }

    function setPriority(priority) {
        state.priority = Math.max(0, Math.min(2, Number(priority)));
        $page.attr('data-ticket-priority', String(state.priority));
        $page.find('[data-priority]').each(function () {
            const active = Number($(this).data('priority')) === state.priority;
            $(this).toggleClass('is-active', active).attr('aria-checked', String(active));
        });
        updateSummary();
    }

    function setProof(path, name, id) {
        state.proofId = Number(id) || 0;
        state.proofPath = path ? safeImageUrl(path, '') : '';
        state.proofName = name || '';
        $page.find('input[name="proof_upload_id"]').val(state.proofId || '');
        $page.find('input[name="proof_path"]').val(state.proofPath);
        const hasProof = state.proofPath !== '';
        $page.find('.st-ticket-proof__drop').prop('hidden', hasProof).removeClass('is-uploading').prop('disabled', false);
        $page.find('.st-ticket-proof__preview').prop('hidden', !hasProof);
        if (hasProof) {
            $page.find('.st-ticket-proof__preview img').attr('src', state.proofPath);
            $page.find('.st-ticket-proof__name').text(state.proofName || i18n('已上传的图片'));
        } else {
            $page.find('.st-ticket-proof__preview img').attr('src', '');
            $page.find('.st-ticket-proof__name').text('');
        }
        updateSummary();
    }

    function initProofUpload() {
        if (!layui.upload) return;
        layui.upload.render({
            elem: $page.find('.st-ticket-proof__drop')[0],
            url: '/user/api/ticket/upload',
            accept: 'images',
            acceptMime: 'image/jpeg,image/png,image/webp',
            exts: 'jpg|jpeg|png|webp',
            size: 10240,
            choose: obj => {
                const files = obj.pushFile();
                const first = files[Object.keys(files)[0]];
                state.proofName = first && first.name ? first.name : i18n('购买凭证');
            },
            before: () => {
                if (!isPageCurrent()) return;
                $page.find('.st-ticket-proof__drop').addClass('is-uploading').prop('disabled', true)
                    .find('.st-ticket-proof__copy strong').text(i18n('正在上传图片…'));
            },
            done: res => {
                if (!isPageCurrent()) return;
                $page.find('.st-ticket-proof__drop .st-ticket-proof__copy strong').text(i18n('上传购买凭证'));
                if (!res || res.code !== 200 || !res.data || !res.data.url) {
                    $page.find('.st-ticket-proof__drop').removeClass('is-uploading').prop('disabled', false);
                    message.error(res && res.msg ? res.msg : i18n('购买凭证上传失败'));
                    return;
                }
                setProof(res.data.url, state.proofName, res.data.upload_id);
            },
            error: () => {
                if (!isPageCurrent()) return;
                $page.find('.st-ticket-proof__drop').removeClass('is-uploading').prop('disabled', false)
                    .find('.st-ticket-proof__copy strong').text(i18n('上传购买凭证'));
                message.error(i18n('上传失败，请检查网络后重试'));
            }
        });
    }

    function editorContent() {
        if (!editor || !editor.cm) return '';
        const markdown = editor.cm.getValue().trim();
        if (!markdown) return '';
        return editor.getHTML();
    }

    function validationError() {
        const title = $page.find('input[name="title"]').val().trim();
        const content = editorContent();
        if (state.type === 1) {
            if (state.orderMode === 'account' && !state.order) return {message: i18n('请选择需要售后支持的订单'), selector: '#ticket-order', block: '.st-ticket-order-panel'};
            if (state.orderMode === 'manual' && !$page.find('input[name="trade_no"]').val().trim()) return {message: i18n('请输入需要售后支持的订单号'), selector: 'input[name="trade_no"]', block: '.st-ticket-order-panel'};
            if (!state.proofPath) return {message: i18n('请上传购买凭证'), selector: '.st-ticket-proof__drop', block: '.st-ticket-proof'};
        }
        const titleLength = Array.from(title).length;
        if (titleLength < 4) return {message: i18n('工单标题至少需要 4 个字'), selector: 'input[name="title"]', block: '.st-ticket-float-field'};
        if (titleLength > 100) return {message: i18n('工单标题不能超过 100 个字'), selector: 'input[name="title"]', block: '.st-ticket-float-field'};
        if (!content) return {message: i18n('请填写问题详情'), selector: '#ticket-content-editor', block: '.st-ticket-editor-field', editor: true};
        return null;
    }

    function clearValidationState() {
        $page.find('.is-validation-error').removeClass('is-validation-error');
        $page.find('[aria-invalid="true"]').removeAttr('aria-invalid');
    }

    function showValidationError(error) {
        if (!error) return;
        clearValidationState();
        message.error(error.message);

        const $source = $page.find(error.selector).first();
        const $block = $source.closest(error.block || '.st-ticket-form__section');
        const $scrollTarget = $block.length ? $block : $source;
        $scrollTarget.addClass('is-validation-error');
        $source.attr('aria-invalid', 'true');

        let $focus = $source;
        if ($source.is('select')) {
            $focus = $source.next('.select2-container').find('.select2-selection').first();
            $focus.attr('aria-invalid', 'true');
        } else if (error.editor) {
            $focus = $source.find('.CodeMirror textarea').first();
            $source.find('.CodeMirror').attr('aria-invalid', 'true');
        }

        const target = $scrollTarget.get(0);
        if (target && typeof target.scrollIntoView === 'function') target.scrollIntoView({behavior: 'smooth', block: 'center'});
        window.setTimeout(() => {
            if (!isPageCurrent()) return;
            if (error.editor && editor && editor.cm) editor.cm.focus();
            else if ($focus.length && typeof $focus.get(0).focus === 'function') $focus.get(0).focus({preventScroll: true});
        }, 220);
    }

    function submitTicket() {
        if (state.submitting) return;
        const error = validationError();
        if (error) {
            showValidationError(error);
            return;
        }
        state.submitting = true;
        const $button = $page.find('.st-ticket-submit');
        $button.prop('disabled', true).attr('aria-busy', 'true').addClass('is-loading').find('span:last').text(i18n('正在提交…'));
        util.post({
            url: '/user/api/ticket/create',
            data: {
                type: state.type,
                priority: state.priority,
                title: $page.find('input[name="title"]').val().trim(),
                commodity_id: state.type === 0 && state.commodity ? state.commodity.id : '',
                order_id: state.type === 1 && state.orderMode === 'account' && state.order ? state.order.id : '',
                trade_no: state.type === 1 && state.orderMode === 'manual' ? $page.find('input[name="trade_no"]').val().trim() : '',
                proof_upload_id: state.type === 1 ? state.proofId : '',
                proof_path: state.type === 1 ? state.proofPath : '',
                content: editorContent()
            },
            done: res => {
                if (!isPageCurrent()) return;
                const payload = res && res.data ? res.data : {};
                const id = Number(payload.id) || 0;
                if (!id && !payload.url) {
                    state.submitting = false;
                    $button.prop('disabled', false).attr('aria-busy', 'false').removeClass('is-loading').find('span:last').text(i18n('提交工单'));
                    message.error(i18n('工单已提交，但返回信息不完整，请到工单列表查看'));
                    return;
                }
                message.success(`${i18n('工单')} ${payload.ticket_no || ''} ${i18n('已创建')}`);
                const fallback = id ? `/user/ticket/detail?id=${encodeURIComponent(id)}` : '/user/ticket/index';
                const target = safeRedirect(payload.url, fallback);
                setTimeout(() => { if (isPageCurrent()) window.location.href = target; }, 650);
            },
            error: res => {
                if (!isPageCurrent()) return;
                state.submitting = false;
                $button.prop('disabled', false).attr('aria-busy', 'false').removeClass('is-loading').find('span:last').text(i18n('提交工单'));
                message.error(res && res.msg ? res.msg : i18n('工单提交失败'));
            },
            fail: () => {
                if (!isPageCurrent()) return;
                state.submitting = false;
                $button.prop('disabled', false).attr('aria-busy', 'false').removeClass('is-loading').find('span:last').text(i18n('提交工单'));
                message.error(i18n('网络连接失败，请稍后重试'));
            }
        });
    }

    $page.find('.st-ticket-type').on('click', function () { switchType($(this).data('ticket-type')); });
    $page.find('[data-order-mode]').on('click', function () { switchOrderMode($(this).data('order-mode')); });
    $page.find('[data-priority]').on('click', function () { setPriority($(this).data('priority')); });
    $page.find('input[name="trade_no"]').on('input', updateSummary);
    $page.find('input[name="title"]').on('input', function () { $page.find('.st-ticket-char-count i').text(Array.from(this.value).length); });
    $page.find('.st-ticket-proof__remove').on('click', () => setProof('', ''));
    $page.find('.st-ticket-submit').on('click', submitTicket);
    $page.on('input.seattleTicketValidation change.seattleTicketValidation', 'input, select', clearValidationState);
    $page.on('click.seattleTicketValidation', '.st-ticket-type, [data-order-mode], [data-priority], .st-ticket-proof__drop, .st-ticket-proof__remove', clearValidationState);

    initRemoteSelect('#ticket-commodity', '/user/api/ticket/commodityOptions', 'commodity', i18n('搜索并选择相关商品（选填）'));
    initRemoteSelect('#ticket-order', '/user/api/ticket/orderOptions', 'order', i18n('搜索商品名称或订单号'));
    initProofUpload();

    const $editor = $page.find('#ticket-content-editor');
    if (window.EditorV2 && $editor.length) {
        $editor.html(EditorV2.buildHtml({
            name: 'content',
            placeholder: i18n('请描述遇到的问题、出现问题的步骤，以及你希望得到的帮助…'),
            allowHtmlSource: false,
            allowRawHtml: false
        }));
        editor = EditorV2.register($editor.get(0), {
            name: 'content',
            uploadUrl: '/user/api/ticket/upload',
            height: 340,
            allowHtmlSource: false,
            allowRawHtml: false
        });
    } else {
        $editor.html('<div class="st-ticket-editor-error">' + i18n('编辑器加载失败，请刷新页面后再试。') + '</div>');
        $page.find('.st-ticket-submit').prop('disabled', true).attr('aria-disabled', 'true');
    }

    $(document)
        .off('pjax:send.seattleTicketCreate pjax:popstate.seattleTicketCreate')
        .on('pjax:send.seattleTicketCreate pjax:popstate.seattleTicketCreate', function () {
            $page.off('.seattleTicketValidation');
            $page.find('#ticket-commodity, #ticket-order').each(function () {
                const $select = $(this);
                if ($select.hasClass('select2-hidden-accessible') && $.fn.select2) {
                    $select.select2('destroy');
                }
            });
            $(document).off('.seattleTicketCreate');
        });

    switchType(0);
    switchOrderMode('account');
    setPriority(1);
}();
