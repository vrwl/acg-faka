!function () {
    const namespace = '.seattleCash';
    const $cashPage = $('[data-st-page="cash"] .st-cash').first();
    if (!$cashPage.length) return;

    const $methods = $cashPage.find('.cash-wallet-btn');
    const $amount = $cashPage.find('input[name=amount]');
    const $submit = $cashPage.find('.payButton');
    const $status = $cashPage.find('.st-cash-receipt__status');
    const coin = Number($cashPage.data('coin')) || 0;
    const minimum = Number($cashPage.data('min')) || 0;
    const fee = Number($cashPage.data('fee')) || 0;
    const zeroBalance = coin <= 0;
    const isPageCurrent = () => document.contains($cashPage[0]);
    let cashWallet;
    let confirming = false;
    let submitting = false;
    let amountTouched = false;
    let currentSummary = null;

    function formatAmount(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return '0';
        return number.toLocaleString('zh-CN', {maximumFractionDigits: 2});
    }

    function isAppViewport() {
        if (window.SeattleTheme && typeof window.SeattleTheme.isAppViewport === 'function') {
            return window.SeattleTheme.isAppViewport();
        }
        return window.innerWidth < 768 || (window.innerHeight <= 500 && window.innerWidth <= 1024);
    }

    function syncMethodSheetViewport() {
        if (!isPageCurrent()) {
            $(window).off('resize' + namespace);
            return;
        }

        const $sheet = $cashPage.find('[data-st-sheet="cash-method"]').first();
        if (!$sheet.length) return;
        const $triggers = $cashPage.find('[data-st-sheet-open="cash-method"]');

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

    function isReady($method) {
        return Number($method.data('ready')) !== 0;
    }

    function selectedMethod() {
        return $methods.filter('.checked');
    }

    function updateMethodSummary() {
        const $selected = selectedMethod();
        const name = $selected.data('name') || $.trim($selected.text()) || i18n('待选择');
        const instant = $selected.data('speed') === 'instant';
        $cashPage.find('.st-cash-method-name').text(name);
        $cashPage.find('.st-cash-method-note').text(instant
            ? i18n('扣除手续费后即时转入站内钱包，可直接用于购买商品。')
            : i18n('提交申请后由管理员处理，到账时间以收款渠道为准。'));
    }

    function selectMethod($method, notify) {
        if (confirming || submitting) return false;
        if (!$method.length) {
            cashWallet = undefined;
            updateMethodSummary();
            return false;
        }

        if (!isReady($method)) {
            if (notify) message.error(i18n('请先在“修改个人信息”中完善对应的收款信息'));
            return false;
        }

        $methods
            .removeClass('checked')
            .attr('aria-checked', 'false')
            .attr('aria-pressed', 'false');
        $method
            .addClass('checked')
            .attr('aria-checked', 'true')
            .attr('aria-pressed', 'true');
        cashWallet = Number($method.data('id'));
        updateMethodSummary();
        return true;
    }

    function prepareInitialAmount() {
        if (zeroBalance) {
            $amount.val('').prop('disabled', true);
            return;
        }

        const current = Number($amount.val());
        if (Number.isFinite(current) && current >= minimum && current > fee && current <= coin) return;

        let suggested = Math.max(minimum, Math.floor(fee) + 1);
        if (suggested > coin && coin >= minimum && coin > fee) suggested = coin;
        if (suggested >= minimum && suggested > fee && suggested <= coin) {
            $amount.val(suggested);
        } else {
            $amount.val('');
        }
    }

    function evaluateAmount(forceFeedback) {
        if (forceFeedback) amountTouched = true;
        const amount = Number($amount.val());
        const safeAmount = Number.isFinite(amount) && amount > 0 ? amount : 0;
        const net = Math.max(safeAmount - fee, 0);
        let error = '';
        let amountInvalid = false;

        if (zeroBalance) {
            error = i18n('当前暂无可兑现硬币');
        } else if (!Number.isFinite(amount) || amount <= 0) {
            error = i18n('请输入有效的兑现数量');
            amountInvalid = true;
        } else if (amount < minimum) {
            error = `${i18n('最低可兑现')} ${formatAmount(minimum)} ${i18n('硬币')}`;
            amountInvalid = true;
        } else if (amount <= fee) {
            error = `${i18n('兑现数量需高于')} ${formatAmount(fee)} ${i18n('硬币手续费')}`;
            amountInvalid = true;
        } else if (amount > coin) {
            error = `${i18n('最多可兑现')} ${formatAmount(coin)} ${i18n('硬币')}`;
            amountInvalid = true;
        } else if (cashWallet === undefined) {
            error = i18n('请选择可用的到账方式');
        }

        currentSummary = {
            amount: safeAmount,
            fee: fee,
            net: net,
            valid: error === ''
        };

        const neutral = !amountTouched && !currentSummary.valid && !zeroBalance;
        const statusText = currentSummary.valid
            ? i18n('金额可用，确认后即可提交')
            : (neutral ? (cashWallet === undefined ? i18n('请选择到账方式') : i18n('输入兑现数量后查看预计到账')) : error);
        const disabled = !currentSummary.valid || confirming || submitting;

        $cashPage.find('.st-cash-gross').text(formatAmount(safeAmount));
        $cashPage.find('.st-cash-net').text(formatAmount(net));
        $cashPage.find('.st-cash-fee').text(`-${formatAmount(safeAmount > 0 ? fee : 0)} ${i18n('元')}`);
        $amount
            .toggleClass('st-field-invalid', amountTouched && amountInvalid)
            .attr('aria-invalid', String(amountTouched && amountInvalid));
        $status
            .toggleClass('is-error', !neutral && !zeroBalance && !currentSummary.valid)
            .toggleClass('is-ok', currentSummary.valid)
            .toggleClass('is-waiting', neutral || zeroBalance);
        $status.find('.material-icons-outlined').text(currentSummary.valid ? 'check_circle' : (zeroBalance ? 'account_balance_wallet' : 'info'));
        $cashPage.find('.st-cash-status-text').text(statusText);
        $submit
            .prop('disabled', disabled)
            .attr('aria-disabled', String(disabled))
            .attr('aria-busy', submitting ? 'true' : 'false');
        $submit.children('.material-icons-outlined').text(submitting ? 'sync' : 'arrow_outward');

        if (submitting) $cashPage.find('.st-cash-submit__label').text(i18n('正在提交'));
        else if (confirming) $cashPage.find('.st-cash-submit__label').text(i18n('等待确认'));
        else if (currentSummary.valid) $cashPage.find('.st-cash-submit__label').text(i18n('确认兑现'));
        else if (zeroBalance) $cashPage.find('.st-cash-submit__label').text(i18n('暂无可兑现'));
        else $cashPage.find('.st-cash-submit__label').text(i18n('填写数量'));

        return currentSummary;
    }

    $cashPage.off(namespace);
    $amount.off(namespace);
    $methods.each(function () {
        const $method = $(this);
        const ready = isReady($method);
        $method
            .attr('role', 'radio')
            .attr('aria-disabled', String(!ready))
            .attr('aria-checked', String($method.hasClass('checked') && ready))
            .attr('aria-pressed', String($method.hasClass('checked') && ready));
    });

    const $initial = selectedMethod().filter(function () { return isReady($(this)); }).first();
    if ($initial.length) {
        selectMethod($initial, false);
    } else {
        const $available = $methods.filter(function () { return isReady($(this)); }).first();
        selectMethod($available, false);
    }

    prepareInitialAmount();
    if (zeroBalance) {
        $methods.prop('disabled', true).attr('aria-disabled', 'true');
        $cashPage.find('.st-notice, .st-cash-security-hint').prop('hidden', true);
    }

    $amount.on('input' + namespace, function () {
        amountTouched = true;
        evaluateAmount(false);
    });

    $cashPage.on('click' + namespace, '.st-cash-all', function () {
        if (zeroBalance) return;
        amountTouched = true;
        $amount.val(coin).trigger('input');
    });

    $cashPage.on('click' + namespace, '.cash-wallet-btn', function () {
        if (!selectMethod($(this), true)) return;
        evaluateAmount(false);
        if (isAppViewport()
            && this.closest('[data-st-sheet="cash-method"]')
            && window.SeattleTheme
            && typeof window.SeattleTheme.closeSheets === 'function') {
            window.SeattleTheme.closeSheets();
        }
    });

    $cashPage.on('click' + namespace, '.payButton', function () {
        if (confirming || submitting) return;
        if (cashWallet === undefined) {
            message.error(i18n('请选择可用的到账方式'));
            return;
        }

        currentSummary = evaluateAmount(true);
        if (!currentSummary.valid) {
            message.error($cashPage.find('.st-cash-status-text').first().text());
            return;
        }

        const amount = $amount.val();
        const confirmText = `${i18n('确认兑现')} ${currentSummary.amount} ${i18n('硬币？扣除')} ${currentSummary.fee} ${i18n('元手续费后，预计到账')} ${currentSummary.net} ${i18n('元。')}`;

        confirming = true;
        evaluateAmount(false);
        Swal.fire({
            title: i18n('确认兑现'),
            text: confirmText,
            icon: 'warning',
            showCancelButton: true,
            cancelButtonText: i18n('取消'),
            confirmButtonText: i18n('确认兑现')
        }).then(result => {
            if (!result || result.isConfirmed !== true) {
                confirming = false;
                evaluateAmount(false);
                return;
            }

            confirming = false;
            submitting = true;
            $methods.prop('disabled', true);
            $cashPage.find('input[name=amount], .st-cash-all, .st-cash-method-trigger').prop('disabled', true);
            evaluateAmount(false);

            const restore = text => {
                if (!isPageCurrent()) return;
                submitting = false;
                $methods.prop('disabled', false);
                $cashPage.find('input[name=amount], .st-cash-all, .st-cash-method-trigger').prop('disabled', false);
                evaluateAmount(false);
                message.error(text);
            };

            util.post({
                url: '/user/api/cash/submit',
                data: {type: cashWallet, amount: amount},
                done: () => {
                    if (!isPageCurrent()) return;
                    message.success(i18n('兑现成功，请耐心等待到账。'));
                    setTimeout(() => {
                        if (isPageCurrent()) window.location.href = '/user/cash/record';
                    }, 1500);
                },
                error: response => restore(response && response.msg ? response.msg : i18n('兑现提交失败，请稍后重试')),
                fail: () => restore(i18n('网络连接失败，请稍后重试'))
            });
        });
    });

    $(window)
        .off('resize' + namespace)
        .on('resize' + namespace, syncMethodSheetViewport);
    $(document)
        .off('pjax:send' + namespace)
        .on('pjax:send' + namespace, function () {
            $(window).off('resize' + namespace);
        });

    updateMethodSummary();
    evaluateAmount(false);
    syncMethodSheetViewport();
}();
