!function () {
    let tradeNo = getVar("tradeNo");

    $('#qrcode').qrcode({
        render: "canvas",
        width: 200,
        height: 200,
        text: getVar("qrcode")
    });


    if (util.isMobile()) {
        $('.wap-show').show();
    } else {
        $('.pc-show').show();
    }

    function queryTimer(tradeNo, paid = null, error = null) {
        util.timer(() => {
            return new Promise(resolve => {
                $.get(route(`/api/order/state?tradeNo=${tradeNo}`), function (res) {
                    if (res.code !== 200) {
                        typeof error == "function" && error();
                        resolve(false);
                        return;
                    }

                    if (res.data.status == 1) {
                        typeof paid == "function" && paid();
                    }
                    resolve(true);
                }).fail(function (xhr, status, error) {
                    typeof error == "function" && error();
                    resolve(false);
                });
            });
        }, 1000, true);
    }

    function expireTimer(createTime, expire = 300, done = null) {
        util.timer(() => {
            return new Promise(resolve => {
                const date = new Date((Math.floor((new Date(createTime)).getTime() / 1000) + expire) * 1000);
                const abstractTimeout = util.getAbstractTimeout(date);
                typeof done === 'function' && done(abstractTimeout);
                resolve(true);
            });
        }, 1000, true);
    }

    queryTimer(tradeNo, res => {
        layer.msg('支付成功');
        setTimeout(() => {
            window.location.href = getVar("returnUrl");
        }, 1000);
    }, res => {
        layer.msg('订单已失效');
        setTimeout(() => {
            window.location.href = getVar("returnUrl");
        }, 1000);
    });

    expireTimer(getVar("createTime"), 300, res => {
        $('#hour_show').html(res.hour + "时");
        $('#minute_show').html(res.minute + "分");
        $('#second_show').html(res.second + "秒");
    });

    //点击小箭头事件
    $('#orderDetail a').click(function () {
        if ($('#orderDetail').hasClass('detail-open')) {
            $('#orderDetail .detail-ct').slideUp(500, function () {
                $('#orderDetail').removeClass('detail-open');
            });
        } else {
            $('#orderDetail .detail-ct').slideDown(500, function () {
                $('#orderDetail').addClass('detail-open');
            });
        }
    });
}();