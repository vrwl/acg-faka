(() => {
    /**
     * 一套配置 = 一个微信收款账号 = 一台挂机手机。
     * 想用第二个微信号收款就新建第二套配置，把它的 Token 填进第二台手机。
     * 字段书写注意：name 与 type 必须相邻（服务端用正则扒字段名当写入白名单）。
     */
    const token = (assign && assign.app_key) ? String(assign.app_key) : '';
    const origin = window.location.origin || (window.location.protocol + '//' + window.location.host);
    const esc = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    return [
        {
            name: "收款账号",
            form: [
                {
                    title: false,
                    name: "wxp_guide_panel",
                    type: "custom",
                    submit: false,
                    complete: (_, dom) => {
                        dom.html(
                            '<div class="alert alert-info mb-4" style="line-height:1.9">' +
                            '<b>手机挂机端要填的两项：</b><br>' +
                            'API URL：<code>' + esc(origin) + '</code><br>' +
                            'TOKEN：<code>' + (token ? esc(token) : '保存本页后生成') + '</code><br>' +
                            '<span style="opacity:.85">一套配置对应一台手机、一个微信收款账号。多个微信号收款就新建多套配置，' +
                            '每台手机填各自的 TOKEN 即可，挂机端 App 无需升级。</span>' +
                            '</div>'
                        );
                    }
                },
                {
                    title: "挂机 Token",
                    name: "app_key", type: "input",
                    placeholder: "32 位字母+数字，填进手机 App 的 TOKEN 一栏",
                    tips: "手机挂机端用它给上报签名，本站据此认出是哪台设备收的款。每套配置请用不同的 Token。"
                },
                {
                    title: "微信收款码",
                    name: "qrcode", type: "image",
                    placeholder: "上传微信收款码图片",
                    width: 100,
                    tips: "支持个人收款码、小薇商家码、经营收款码。保存后首次使用时自动解析出支付链接。"
                },
                {
                    title: "收款链接（可选）",
                    name: "manual_url", type: "input",
                    placeholder: "wxp://f2f0...",
                    tips: "只有当上面的收款码图片识别不出来时才需要填。填了就以它为准，不再解析图片。"
                },
                {
                    title: "微信赞赏码",
                    name: "reward_url", type: "image",
                    placeholder: "上传赞赏码图片（用赞赏码通道才需要）",
                    width: 100
                },
                {
                    title: "微信手机号",
                    name: "phone", type: "input",
                    placeholder: "手机号转账通道用，不用可留空"
                },
                {
                    title: "微信真实姓名",
                    name: "real_name", type: "input",
                    placeholder: "大额转账需核对姓名，配合手机号转账使用"
                }
            ]
        }
    ];
})()
