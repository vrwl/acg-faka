/**
 * WkDock 网课对接前台脚本
 *
 * 功能：
 * 1. 商品详情页检测网课商品（/plugin/WkDock/api/query/check）
 * 2. 注入「查询课程」按钮，拉取学生可选课程并渲染可勾选列表
 * 3. 勾选数量联动购买数量（购买数量输入框锁定为只读）
 * 4. 勾选课程时实时把选中结果以 JSON 写入隐藏控件 courses，
 *    并在下单前（capture 阶段）校验字段完整性，兜底再写一次
 *
 * 适配策略：不依赖主题的容器 class（.vstack / [data-la-buy] / [data-sb-purchase] 等），
 * 而是直接按控件 name（school/user/pass/courses/num）定位字段，再向上找所属 form，
 * 因此所有 Smarty 主题（NewYork/LosAngeles/Shibuya/Seattle/Tokyo/Chiba/Nagoya/Cartoon）通用。
 *
 * 样式自包含：使用 wk- 前缀样式 + 内联 SVG 图标，不依赖主题 btn/form-check/图标字体，
 * 任何模板下都能正常显示。
 *
 * 软跳转适配：部分主题（东京等）用 pjax 替换 #pjax-container 内容而非整页刷新，
 * documentReady 不会二次触发，故在 pjax:complete 时重新 boot()，字段引用提升为模块级并按需重定位。
 */
!function () {
    "use strict";

    var API_BASE = "/plugin/WkDock/api/query";

    //内联 SVG，currentColor 跟随文字颜色，不依赖图标字体
    var ICON_SEARCH = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14z"/></svg>';

    //模块级状态：pjax 换页后 DOM 被替换，旧 jQuery 对象会失效，故每次 boot 重新定位
    var item = null;
    var $school, $user, $pass, $courses, $contact, $num, $form;
    var submitGuardBound = false;

    //仅商品详情页有效
    function isItemPage() {
        item = getVar("_var_item");
        return item && item.id > 0;
    }

    //按控件 name 全局定位（商品页仅一份），再向上推导所属表单，不依赖主题容器 class
    function locateFields() {
        $school = jQuery('input[name="school"]');
        $user = jQuery('input[name="user"]');
        $pass = jQuery('input[name="pass"]');
        $courses = jQuery('input[name="courses"]');
        //商城自带的「联系方式」控件（仅游客渲染），网课商品多余，隐藏后查单身份改用学生账号
        $contact = jQuery('input[name="contact"]');
        $num = jQuery('input[name="num"]');
        $form = $courses.closest("form");
    }

    function hasFields() {
        return $school.length > 0 && $user.length > 0 && $pass.length > 0 && $courses.length > 0;
    }

    //入口：初次加载与 pjax 换页后都会调用
    function boot() {
        if (!isItemPage()) {
            return;
        }
        locateFields();
        if (!hasFields()) {
            return;
        }
        //后端确认是否网课商品（站点可用且平台在售）
        util.post({
            url: API_BASE + "/check",
            data: {commodity_id: item.id},
            loader: false,
            done: function (res) {
                if (res && res.data && res.data.is_oc === 1) {
                    enhance();
                }
            }
        });
    }

    function injectStyle() {
        if (jQuery("#wk-style").length > 0) {
            return;
        }
        var css = [
            '.wk-course-box{margin-top:14px;font-size:13px;line-height:1.5;}',
            '.wk-course-box .wk-label{display:flex;align-items:center;gap:6px;margin-bottom:10px;font-weight:600;font-size:13px;}',
            '.wk-course-box .wk-label::before{content:"";width:4px;height:14px;border-radius:2px;background:#4d8dff;flex:none;}',
            '.wk-query-btn{display:flex;width:100%;justify-content:center;align-items:center;gap:8px;padding:11px 20px;border:1px solid transparent;border-radius:10px;background:linear-gradient(180deg,#4d8dff,#3568f0);color:#fff;font-size:13px;font-weight:600;line-height:1;cursor:pointer;box-shadow:0 2px 8px rgba(53,104,240,.3);transition:transform .12s ease,box-shadow .12s ease,filter .12s ease;}',
            '.wk-query-btn:hover{filter:brightness(1.06);box-shadow:0 4px 14px rgba(53,104,240,.36);transform:translateY(-1px);}',
            '.wk-query-btn:active{transform:translateY(0);box-shadow:0 2px 6px rgba(53,104,240,.28);}',
            '.wk-query-btn:disabled{background:#bfc7d4;box-shadow:none;cursor:not-allowed;transform:none;}',
            '.wk-query-btn svg{width:15px;height:15px;fill:currentColor;flex:none;}',
            '.wk-spin{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:wkspin .6s linear infinite;flex:none;}',
            '@keyframes wkspin{to{transform:rotate(360deg)}}',
            '.wk-course-list{margin-top:12px;max-height:340px;overflow:auto;display:flex;flex-direction:column;gap:8px;padding-right:2px;}',
            '.wk-course-list:not(:empty){border:1px solid rgba(128,138,150,.22);border-radius:10px;padding:12px;background:rgba(128,138,150,.04);}',
            '.wk-course-list::-webkit-scrollbar{width:8px;}',
            '.wk-course-list::-webkit-scrollbar-thumb{background:rgba(128,138,150,.35);border-radius:8px;}',
            '.wk-all-row,.wk-course-item{display:flex;align-items:flex-start;gap:10px;padding:11px 13px;border:1px solid rgba(128,138,150,.28);border-radius:10px;cursor:pointer;background:rgba(128,138,150,.06);transition:border-color .15s,background .15s,box-shadow .15s;}',
            '.wk-all-row:hover,.wk-course-item:hover{border-color:rgba(77,141,255,.6);}',
            '.wk-course-item.checked,.wk-all-row.checked{background:rgba(77,141,255,.12);border-color:rgba(77,141,255,.9);}',
            '.wk-course-item input[type=checkbox],.wk-all-row input[type=checkbox]{accent-color:#4d8dff;width:16px;height:16px;margin:1px 0 0;cursor:pointer;flex:none;}',
            '.wk-course-item .wk-name{flex:1;line-height:1.55;word-break:break-all;color:inherit;}',
            '.wk-all-row .wk-all-label{flex:1;font-weight:600;color:inherit;}',
            '.wk-all-row .wk-count{font-weight:500;color:#8a8f97;background:rgba(128,138,150,.16);border-radius:999px;padding:1px 9px;font-size:12px;line-height:1.6;}',
            '.wk-hint{display:block;color:#8a8f97;font-size:12px;padding:10px 12px;border:1px dashed rgba(128,138,150,.4);border-radius:10px;}',
            '.wk-err{display:block;color:#d9534f;font-size:12px;padding:10px 12px;border:1px dashed rgba(217,83,79,.5);border-radius:10px;}'
        ].join('\n');
        jQuery('head').append('<style id="wk-style">' + css + '</style>');
    }

    //隐藏一个控件连同它的 label：优先隐藏直接父容器（widget_render 的 <div><label>..<input></div>），
    //若父级正好是表单则退化为只隐藏 input，避免误隐藏整个表单
    function hideWidget($input) {
        var $wrap = $input.parent();
        if ($wrap.length > 0 && $wrap.get(0).tagName !== "FORM") {
            $wrap.hide();
        } else {
            $input.hide();
        }
    }

    function enhance() {
        //幂等：同一页面已注入过则不再重复
        if (jQuery("#wk-course-box").length > 0) {
            return;
        }
        //异步回包时页面可能已被 pjax 切换，重新定位并校验，确保作用于当前 DOM
        locateFields();
        if (!hasFields()) {
            return;
        }

        //锁定数量手改，由课程勾选数决定
        if ($num.length > 0) {
            $num.prop("readonly", true).val(1);
        }
        jQuery(".change-num-sub, .change-num-add").prop("disabled", true).css("opacity", ".45");

        injectStyle();

        //隐藏「下单课程」控件，其值由下方勾选自动填入
        hideWidget($courses);

        //隐藏「联系方式」控件，查单身份由学生账号替代
        if ($contact.length > 0) {
            hideWidget($contact);
        }

        //注入查询按钮与课程容器（紧跟密码控件之后）
        var box = jQuery(
            '<div class="wk-course-box">' +
            '<label class="wk-label">' + i18n("选择课程") + '</label>' +
            '<button type="button" id="wk-query-btn" class="wk-query-btn">' + ICON_SEARCH + i18n("查询可选课程") + '</button>' +
            '<div id="wk-course-list" class="wk-course-list"></div>' +
            '</div>'
        );
        var $anchor = $pass.parent();
        if ($anchor.length > 0 && $anchor.get(0).tagName !== "FORM") {
            $anchor.after(box);
        } else if ($form.length > 0) {
            $form.append(box);
        } else {
            $courses.parent().after(box);
        }

        jQuery("#wk-query-btn").click(function () {
            queryCourses();
        });

        bindSubmitGuard();
    }

    function queryCourses() {
        var it = getVar("_var_item");
        if (!it || !(it.id > 0)) {
            return;
        }
        var school = String($school.val() || "").trim();
        var user = String($user.val() || "").trim();
        var pass = String($pass.val() || "").trim();

        if (school === "" || user === "" || pass === "") {
            message.error(i18n("请先完整填写学校全称、学生账号和密码"));
            return;
        }

        var $btn = jQuery("#wk-query-btn");
        $btn.prop("disabled", true).html('<span class="wk-spin"></span>' + i18n("查询中..."));
        jQuery("#wk-course-list").html('<span class="wk-hint">' + i18n("正在查询，平台响应可能较慢...") + '</span>');

        util.post({
            url: API_BASE + "/courses",
            data: {
                commodity_id: it.id,
                school: school,
                user: user,
                pass: pass
            },
            loader: false,
            done: function (res) {
                $btn.prop("disabled", false).html(ICON_SEARCH + i18n("查询可选课程"));
                var list = (res && res.data && res.data.list) || [];
                renderCourses(list);
            },
            error: function (d) {
                $btn.prop("disabled", false).html(ICON_SEARCH + i18n("查询可选课程"));
                //错误文案可能直接来自上游平台，插入前必须转义，避免上游塞脚本进商品页
                jQuery("#wk-course-list").html('<span class="wk-err">' + escapeHtml((d && d.msg) || i18n("查询失败")) + '</span>');
            }
        });
    }

    function renderCourses(list) {
        var $list = jQuery("#wk-course-list");
        $list.empty();
        if (!list || list.length === 0) {
            $list.html('<span class="wk-hint">' + i18n("未查询到可选课程，请核对学校全称、账号密码") + '</span>');
            syncSelected();
            return;
        }

        //全选（label 包裹 checkbox，点击整行即切换）
        var $all = jQuery(
            '<label class="wk-all-row">' +
            '<input type="checkbox" id="wk-course-all">' +
            '<span class="wk-all-label">' + i18n("全选") + '</span>' +
            '<span class="wk-count">' + list.length + '</span>' +
            '</label>'
        );
        $list.append($all);
        $all.find("input").change(function () {
            var checked = jQuery(this).is(":checked");
            $list.find(".wk-course-check").prop("checked", checked);
            $list.find(".wk-course-item").toggleClass("checked", checked);
            $all.toggleClass("checked", checked);
            syncSelected();
        });

        for (var i = 0; i < list.length; i++) {
            var c = list[i];
            var row = jQuery(
                '<label class="wk-course-item">' +
                '<input type="checkbox" class="wk-course-check" data-id="' + escapeAttr(c.id) + '" data-name="' + escapeAttr(c.name) + '">' +
                '<span class="wk-name">' + escapeHtml(c.name) + '</span>' +
                '</label>'
            );
            $list.append(row);
        }
        $list.on("change", ".wk-course-check", function () {
            jQuery(this).closest(".wk-course-item").toggleClass("checked", jQuery(this).is(":checked"));
            var total = $list.find(".wk-course-check").length;
            var checked = $list.find(".wk-course-check:checked").length;
            $all.find("input").prop("checked", total === checked && total > 0);
            $all.toggleClass("checked", total === checked && total > 0);
            syncSelected();
        });

        syncSelected();
    }

    function collectSelected() {
        var arr = [];
        jQuery("#wk-course-list .wk-course-check:checked").each(function () {
            var $t = jQuery(this);
            arr.push({id: String($t.data("id") || ""), name: String($t.data("name") || "")});
        });
        return arr;
    }

    //勾选变化时同步：数量跟随勾选数、courses 控件实时写入 JSON（任意主题序列化表单都能带上）
    function syncSelected() {
        var count = jQuery("#wk-course-list .wk-course-check:checked").length;
        if ($num.length > 0) {
            $num.val(count > 0 ? count : 1).trigger("change");
        }
        $courses.val(JSON.stringify(collectSelected()));
        if ($contact.length > 0) {
            $contact.val(String($user.val() || "").trim());
        }
    }

    //capture 阶段拦截下单入口：校验字段完整性，未满足则阻止，满足则兜底再同步一次
    function bindSubmitGuard() {
        if (submitGuardBound) {
            return;
        }
        submitGuardBound = true;
        document.addEventListener("click", function (e) {
            var target = e.target;
            if (!target || !target.closest) {
                return;
            }

            var trigger = null;

            //标准主题（.pay-list .pay）与 Seattle（.st-pay-list .pay）：点击即下单
            var pay = target.closest(".pay");
            if (pay) {
                var inShibuya = pay.closest("[data-sb-item-page]");
                var inSeattle = pay.closest(".st-pay-list");
                var inStandard = pay.closest(".pay-list");
                //Shibuya 的 .pay 只是「选择支付方式」，真正下单走 [data-sb-buy]，这里不能拦
                if (inSeattle || (inStandard && !inShibuya)) {
                    trigger = pay;
                }
            }
            //LosAngeles：面板内 [data-pay] 点击即下单
            if (!trigger) {
                trigger = target.closest("[data-pay]");
            }
            //Shibuya：[data-sb-buy] 点击即下单
            if (!trigger) {
                trigger = target.closest("[data-sb-buy]");
            }
            if (!trigger) {
                return;
            }

            var school = String($school.val() || "").trim();
            var user = String($user.val() || "").trim();
            var pass = String($pass.val() || "").trim();

            if (school === "" || user === "" || pass === "") {
                e.preventDefault();
                e.stopPropagation();
                message.error(i18n("请先完整填写学校全称、学生账号和密码"));
                return;
            }

            var count = jQuery("#wk-course-list .wk-course-check:checked").length;
            if (count === 0) {
                e.preventDefault();
                e.stopPropagation();
                message.error(i18n("请先点击「查询可选课程」并勾选需要刷的课程"));
                return;
            }

            //兜底再同步一次，确保写入与最终数量一致
            syncSelected();
        }, true);
    }

    function escapeHtml(v) {
        return String(v == null ? "" : v).replace(/[&<>"']/g, function (c) {
            return {"&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"}[c];
        });
    }

    function escapeAttr(v) {
        return escapeHtml(v);
    }

    documentReady(function () {
        boot();
        //pjax 软跳转（东京等主题）不会触发 documentReady，换页后重新初始化
        if (window.jQuery) {
            jQuery(document).on("pjax:complete", boot);
        }
    });
}();