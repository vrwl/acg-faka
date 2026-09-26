[
    {
        name: util.icon("/app/Pay/Epay/View/Assets/icon.png") + " 对接配置",
        form: [
            {
                title: "接口版本",
                name: "version",
                type: "radio",
                dict: [
                    {id: 0, name: "V1"},
                    {id: 1, name: "V2"}
                ],
                change: (form, val) => {
                    if (val == 1) {
                        form.show('platform_public_key');
                        form.show('private_key');
                        form.hide('key');
                    } else {
                        form.hide('platform_public_key');
                        form.hide('private_key');
                        form.show('key');
                    }
                },
                complete: (form, val) => {
                    form.triggerOtherPopupChange("version", val);
                }
            },
            {
                title: "接口地址",
                name: "url",
                type: "input",
                placeholder: "支付接口地址(如:https://abcedf.com)"
            },
            {
                title: "商户ID",
                name: "pid",
                type: "input",
                placeholder: "请输入商户ID"
            },
            {
                title: "商户密钥",
                name: "key",
                type: "input",
                placeholder: "请输入商户密钥",
                hide: assign?.version == 1
            },
            {
                title: "平台公钥",
                name: "platform_public_key",
                type: "textarea",
                placeholder: "请输入平台公钥",
                hide: assign?.version != 1,
                height: 60
            },
            {
                title: "商户私钥",
                name: "private_key",
                type: "textarea",
                placeholder: "请输入商户私钥",
                hide: assign?.version != 1,
                height: 60
            },
            {
                title: "自定义订单标题",
                name: "order_title",
                type: "input",
                placeholder: "自定义订单标题",
                tips: `自定义订单号，如：【商品订单号:$\{trade_no}】，最终显示为：【商品订单号:xxxxxxx】，【\${trade_no}】为订单号的变量`,
                default: `商品订单号:\${trade_no}`
            },
            {
                title: "付款页提示",
                name: "cashier_notice",
                type: "textarea",
                placeholder: "显示在支付宝、微信、QQ 扫码付款页上，留空则显示默认提示",
                tips: "例如：付款时请勿修改金额；付款后如未自动跳转，请联系客服并提供订单号。只支持纯文字，可换行，最多 500 字。",
                height: 80
            },
        ]
    }
]