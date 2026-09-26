//站点货币符号：读 Helper 注入的 CURRENCY（部分旧主题不加载 ready.js，探测兜底 ¥）
function acgCurrencySymbol() {
    var currency = typeof getVar === 'function' ? getVar('CURRENCY') : null;
    return (currency && currency.symbol) || '¥';
}

!function () {
    let _PayId;
    const $topup = $('[data-st-page="recharge"] .st-topup').first();
    if (!$topup.length) return;
    const isTopupPage = true;
    const isPageCurrent = () => document.contains($topup[0]);
    let paymentState = 'loading';
    let paymentError = '';
    let tradeState = 'idle';
    let tradeError = '';

    function formatAmount(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '0';
        }
        return number.toLocaleString('zh-CN', {maximumFractionDigits: 2});
    }

    function welfareFor(amount) {
        let matchedThreshold = -1;
        let bonus = 0;
        $topup.find('.st-topup-bonus').each(function () {
            const threshold = Number($(this).data('threshold'));
            const currentBonus = Number($(this).data('bonus'));
            if (Number.isFinite(threshold) && amount >= threshold && threshold > matchedThreshold) {
                matchedThreshold = threshold;
                bonus = Number.isFinite(currentBonus) ? currentBonus : 0;
            }
        });
        return bonus;
    }

    function isAppViewport() {
        if (window.SeattleTheme && typeof window.SeattleTheme.isAppViewport === 'function') {
            return window.SeattleTheme.isAppViewport();
        }
        return window.innerWidth < 768 || (window.innerHeight <= 500 && window.innerWidth <= 1024);
    }

    function syncPaymentSheetViewport() {
        if (!isPageCurrent()) {
            $(window).off('resize.seattleRecharge');
            return;
        }

        const $sheet = $topup.find('[data-st-sheet="topup-payment"]').first();
        if (!$sheet.length) return;
        const $triggers = $topup.find('[data-st-sheet-open="topup-payment"]');

        if (isAppViewport()) {
            const open = $sheet.hasClass('is-open');
            $sheet
                .attr('role', 'dialog')
                .attr('aria-modal', 'true')
                .attr('aria-hidden', String(!open));
            $triggers.attr('aria-expanded', String(open));
            return;
        }

        $sheet
            .removeClass('is-open')
            .attr('role', 'region')
            .removeAttr('aria-modal')
            .attr('aria-hidden', 'false');
        $triggers.attr('aria-expanded', 'false');
        if (!$('[data-st-sheet].is-open').length) {
            $('body').removeClass('st-sheet-open');
            $('.st-sheet-backdrop').attr('aria-hidden', 'true');
        }
    }

    function updateSummary() {
        if (!isTopupPage) {
            return true;
        }

        const amount = Number($topup.find('input[name=amount]').val());
        const safeAmount = Number.isFinite(amount) && amount > 0 ? amount : 0;
        const minimum = Number($topup.data('min')) || 0;
        const configuredMax = Number($topup.data('max')) || 0;
        const maximum = configuredMax > minimum ? configuredMax : 0;
        const gift = welfareFor(safeAmount);
        const total = safeAmount + gift;
        let error = '';
        let errorState = false;
        let amountInvalid = false;

        if (!Number.isFinite(amount) || amount <= 0) {
            error = i18n('请输入有效的充值金额');
            errorState = true;
            amountInvalid = true;
        } else if (amount < minimum) {
            error = `${i18n('单次最低充值')} ${acgCurrencySymbol()}${formatAmount(minimum)}`;
            errorState = true;
            amountInvalid = true;
        } else if (maximum > 0 && amount > maximum) {
            error = `${i18n('单次最高充值')} ${acgCurrencySymbol()}${formatAmount(maximum)}`;
            errorState = true;
            amountInvalid = true;
        } else if (paymentState === 'loading') {
            error = i18n('正在加载支付方式');
        } else if (paymentState === 'error') {
            error = paymentError || i18n('支付方式加载失败，请重试');
            errorState = true;
        } else if (paymentState === 'empty') {
            error = i18n('暂无可用支付方式');
            errorState = true;
        } else if (_PayId === undefined) {
            error = $topup.find('.btn-pay').length ? i18n('请选择一种支付方式') : i18n('暂无可用的支付方式');
            errorState = !$topup.find('.btn-pay').length;
        }

        $topup.find('.st-topup-principal').text(formatAmount(safeAmount));
        $topup.find('.st-topup-gift').text(`+${acgCurrencySymbol()}${formatAmount(gift)}`);
        $topup.find('.st-topup-energy').text(formatAmount(total));
        $topup.find('.st-topup-total').text(formatAmount(total));
        $topup.find('input[name=amount]')
            .toggleClass('st-field-invalid', amountInvalid)
            .attr('aria-invalid', String(amountInvalid));

        const valid = error === '';
        const canSubmit = valid && tradeState !== 'submitting';
        const $status = $topup.find('.st-topup-summary__status');
        let statusClass = valid ? 'is-ready' : (errorState ? 'is-error' : 'is-waiting');
        let statusIcon = valid ? 'check_circle' : (errorState ? 'error' : 'touch_app');
        let statusText = valid ? i18n('信息已确认，可以前往支付') : error;
        let submitText = i18n('请选择支付方式');
        if (valid) submitText = i18n('前往支付');
        else if (paymentState === 'loading') submitText = i18n('正在加载支付方式');
        else if (paymentState === 'error') submitText = i18n('支付方式加载失败');
        else if (paymentState === 'empty') submitText = i18n('暂无可用支付方式');
        else if (!$topup.find('.btn-pay').length) submitText = i18n('暂无可用支付方式');

        if (tradeState === 'submitting') {
            statusClass = 'is-waiting';
            statusIcon = 'sync';
            statusText = i18n('正在创建支付订单，请稍候');
            submitText = i18n('正在创建支付订单');
        } else if (tradeState === 'error' && valid) {
            statusClass = 'is-error';
            statusIcon = 'error';
            statusText = tradeError;
            submitText = i18n('重试前往支付');
        }

        $status.removeClass('is-waiting is-ready is-error is-ok').addClass(statusClass);
        $status.find('.material-icons-outlined').text(statusIcon);
        $topup.find('.st-topup-status-text').text(statusText);
        $topup.find('.payButton')
            .prop('disabled', !canSubmit)
            .attr('aria-disabled', String(!canSubmit))
            .attr('aria-busy', String(tradeState === 'submitting'));
        $topup.find('.st-topup-submit__label').text(submitText);
        $topup.find('.payButton > .material-icons-outlined').text(tradeState === 'submitting' ? 'sync' : 'arrow_forward');
        $topup.find('.st-topup-retry-wrap').prop('hidden', paymentState !== 'error' && paymentState !== 'empty');
        $topup.find('.st-topup-retry').prop('disabled', paymentState === 'loading');
        $topup.find('.st-topup-methods')
            .attr('aria-busy', String(paymentState === 'loading'))
            .attr('role', 'radiogroup')
            .attr('aria-label', i18n('支付方式'));

        return canSubmit;
    }

    function _GetPayList() {
        _PayId = undefined;
        paymentState = 'loading';
        paymentError = '';
        tradeState = 'idle';
        tradeError = '';
        $topup.find('.pay-list').empty();
        $topup.find('.st-topup-method-name').text(i18n('待选择'));
        updateSummary();

        const failPaymentList = text => {
            if (!isPageCurrent()) return;
            paymentState = 'error';
            paymentError = text;
            updateSummary();
            message.error(text);
        };

        util.post({
            url: '/user/api/recharge/pay',
            loader: false,
            done: res => {
                if (!isPageCurrent()) return;
                const methods = Array.isArray(res && res.data) ? res.data : [];
                if (!methods.length) {
                    paymentState = 'empty';
                    $topup.find('.st-topup-method-name').text(i18n('暂无可用'));
                    $('<div class="st-topup-payment-empty" role="status"></div>')
                        .append('<span class="material-icons-outlined" aria-hidden="true">payments</span>')
                        .append('<strong>' + i18n('暂无可用支付方式') + '</strong>')
                        .append('<small>' + i18n('请稍后重试') + '</small>')
                        .appendTo($topup.find('.pay-list'));
                    updateSummary();
                    return;
                }
                methods.forEach(item => {
                    const $button = $('<button type="button" class="button-click btn-pay" role="radio" aria-checked="false" aria-pressed="false"></button>');
                    if (item.icon) {
                        $('<img class="pay-icon" alt="">')
                            .attr('src', item.icon)
                            .one('error', function () {
                                $(this).replaceWith('<span class="st-payment-fallback material-icons-outlined" aria-hidden="true">payments</span>');
                            })
                            .appendTo($button);
                    } else {
                        $('<span class="st-payment-fallback material-icons-outlined" aria-hidden="true">payments</span>').appendTo($button);
                    }
                    $('<span class="st-payment-name"></span>').text(item.name || i18n('支付方式')).appendTo($button);
                    $('<span class="st-payment-check material-icons-outlined" aria-hidden="true">check</span>').appendTo($button);
                    $button.attr('data-id', item.id);
                    $topup.find('.pay-list').append($button);
                });
                paymentState = 'ready';
                updateSummary();
            },
            error: res => failPaymentList(res && res.msg ? res.msg : i18n('支付方式加载失败，请重试')),
            fail: () => failPaymentList(i18n('网络连接失败，无法加载支付方式'))
        });
    }

    function _Presets() {
        const $presets = $topup.find('.st-topup-preset');
        if (!$presets.length) {
            return;
        }

        const $input = $topup.find('input[name=amount]');
        const sync = () => {
            if (tradeState === 'error') {
                tradeState = 'idle';
                tradeError = '';
            }
            const current = String($input.val()).trim();
            $presets.each(function () {
                const active = String($(this).data('amount')) === current;
                $(this).toggleClass('active', active).attr('aria-pressed', String(active));
            });
            updateSummary();
        };

        $presets.off('click.seattleRecharge').on('click.seattleRecharge', function () {
            $input.val($(this).data('amount')).trigger('input').trigger('change');
        });
        $input.off('input.seattleRecharge').on('input.seattleRecharge', sync);
        sync();
    }

    function _Recharge() {
        $(window)
            .off('resize.seattleRecharge')
            .on('resize.seattleRecharge', syncPaymentSheetViewport);
        syncPaymentSheetViewport();

        $topup.on('click.seattleRecharge', '.btn-pay', function () {
            _PayId = $(this).data('id');
            tradeState = 'idle';
            tradeError = '';
            $topup.find('.btn-pay.checked')
                .removeClass('checked')
                .attr('aria-checked', 'false')
                .attr('aria-pressed', 'false');
            $(this)
                .addClass('checked')
                .attr('aria-checked', 'true')
                .attr('aria-pressed', 'true');
            $topup.find('.st-topup-method-name').text($.trim($(this).find('.st-payment-name').text()));
            updateSummary();

            const paymentSheet = this.closest('[data-st-sheet="topup-payment"]');
            if (isAppViewport()
                && paymentSheet
                && window.SeattleTheme
                && typeof window.SeattleTheme.closeSheets === 'function') {
                window.SeattleTheme.closeSheets();
            }
        });

        $topup.on('click.seattleRecharge', '.st-topup-retry', function () {
            _GetPayList();
        });

        $topup.on('click.seattleRecharge', '.payButton', function () {
            if (isTopupPage && !updateSummary()) {
                if (tradeState !== 'submitting') message.error($topup.find('.st-topup-status-text').text());
                return;
            }
            if (_PayId === undefined) {
                message.error(i18n('请选择支付方式'));
                return;
            }

            tradeState = 'submitting';
            tradeError = '';
            updateSummary();

            const failTrade = text => {
                if (!isPageCurrent()) return;
                tradeState = 'error';
                tradeError = text;
                updateSummary();
                message.error(text);
            };

            util.post({
                url: '/user/api/recharge/trade',
                data: {
                    pay_id: _PayId,
                    amount: $topup.find('input[name=amount]').val()
                },
                done: res => {
                    const url = res && res.data ? res.data.url : '';
                    if (!url) {
                        failTrade(i18n('支付订单创建失败，请重试'));
                        return;
                    }
                    window.location.href = url;
                },
                error: res => failTrade(res && res.msg ? res.msg : i18n('支付订单创建失败，请重试')),
                fail: () => failTrade(i18n('网络连接失败，请稍后重试'))
            });
        });
    }

    $topup.off('.seattleRecharge');
    _GetPayList();
    _Presets();
    _Recharge();
}();
