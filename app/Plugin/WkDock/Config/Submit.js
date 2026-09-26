(function () {

    const __form = [
        {
            "title": "使用流程",
            "name": "explainUsage",
            "type": "explain",
            "placeholder": "第一步【启动】：开启「网课对接」插件<br/>第二步【配置站点】：后台菜单「网课对接 - 对接站点」，新增站点，填写平台地址、账号(uid)、密钥(key)，保存后连接状态显示「正常」即可<br/>第三步【采集】：后台菜单「网课对接 - 商品采集」，点击「立即同步」拉取平台商品列表（售价=平台成本x站点价格倍率）<br/>第四步【生成商品】：勾选商品池中的商品，点击「批量生成商品」选择商城分类后生成。生成后商品默认下架，请到商品管理中编辑上架<br/>第五步【售卖】：买家进入商品页填写学校全称、学生账号、密码，点击「查询可选课程」勾选课程后支付，支付成功后插件自动向平台交单<br/>第六步【售后】：可在订单列表查看「网课对接」状态列；交单明细记录在插件日志中"
        },
        {
            "title": "字段说明",
            "name": "explainFields",
            "type": "explain",
            "placeholder": "站点「价格倍率」：生成商品时 售价 = 平台成本价 x 倍率，修改倍率只影响之后生成的商品<br/>生成商品后请勿修改商品购买表单中的「学校/学生账号/学生密码」三个控件，前台选课功能依赖这三个字段<br/>删除站点会清空其生成商品与商品池的关联（商品不会被删除，但会变为普通商品）"
        },
        {
            "title": "交单规则",
            "name": "explainOrder",
            "type": "explain",
            "placeholder": "买家支付成功后，插件按订单勾选的课程逐门向平台提交（接口act=add）。全部提交成功则订单标记已发货并写入说明；部分成功标记「部分对接」；全部失败标记「对接出错」。失败原因可在插件日志(runtime.log)与订单的对接详情中查看，站长可人工补交或退款。"
        }
    ];

    const explainToCustom = (item) => {
        const text = String(item.placeholder == null ? "" : item.placeholder);
        return {
            title: item.title || false,
            name: item.name,
            type: "custom",
            complete: (form, dom) => {
                dom.html('<div style="line-height:1.9;padding-top:7px;word-break:break-word;">' + text + '</div>');
            }
        };
    };

    return [
        {
            name: util.icon("fa-duotone fa-regular fa-graduation-cap") + " 使用说明",
            form: __form.map((item) => item && item.type === "explain" ? explainToCustom(item) : item)
        }
    ];
})();
