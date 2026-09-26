!function () {
    function _LoadTicketBadge() {
        util.post({
            url: "/admin/api/ticket/badge",
            loader: false,
            error: false,
            fail: false,
            done: res => {
                const count = Math.max(0, Number(res?.data?.count || 0));
                $('.ticket-admin-badge')
                    .text(count > 99 ? '99+' : count)
                    .prop('hidden', count < 1)
                    .attr('aria-label', count > 0 ? `${i18n('有')} ${count} ${i18n('张工单等待处理')}` : i18n('没有待处理工单'));
            }
        });
    }

    function _Pjax() {
        $(document).pjax('a[target!=_blank]', '#pjax-container', {fragment: '#pjax-container', timeout: 8000});
        $(document).on('pjax:send', function () {
            Loading.show();
            // 手机版:点菜单(pjax 导航)后自动收起侧栏抽屉；桌面态 aside 非 drawer-on,跳过
            var aside = document.querySelector('#kt_aside');
            if (aside && aside.classList.contains('drawer-on') && window.KTDrawer) {
                var drawer = KTDrawer.getInstance(aside);
                drawer && drawer.hide();
            }
        });
        $(document).on('pjax:complete', function () {
            Loading.hide();
        });
        $("a[target!=_blank]").click(function () {
            $('a[target!=_blank]').removeClass("active");
            $(this).addClass("active");
        });
    }

    _LoadTicketBadge();
    _Pjax();

    $(document).off('ticket:badge-refresh.admin').on('ticket:badge-refresh.admin', _LoadTicketBadge);
    if (window.__adminTicketBadgeTimer) {
        clearInterval(window.__adminTicketBadgeTimer);
    }
    window.__adminTicketBadgeTimer = setInterval(_LoadTicketBadge, 60000);
}();
