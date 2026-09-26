(() => {
    //次元博客的完整设置在独立面板中管理（/plugin/Blog/panel/settings），
    //通用插件配置弹窗只留一个门面入口，避免两套表单打架。
    const T = (s) => (typeof i18n === "function" ? i18n(s) : s);
    return [{
        name: util.icon("fa-duotone fa-regular fa-book-open") + " " + T("次元博客"),
        form: [
            {
                title: false,
                name: "blog_gateway",
                type: "custom",
                complete: (form, dom) => {
                    dom.html(`<div style="padding:16px 6px;line-height:2">
    <div style="margin-bottom:10px">${T("次元博客的所有设置与内容管理都在独立面板中进行。")}</div>
    <a href="/plugin/Blog/panel/posts" class="btn btn-sm btn-primary me-2">${util.icon("fa-duotone fa-regular fa-pen-nib")} ${T("打开博客面板")}</a>
    <a href="/plugin/Blog/panel/settings" class="btn btn-sm btn-light-primary">${util.icon("fa-duotone fa-regular fa-gear")} ${T("博客设置")}</a>
</div>`);
                }
            }
        ]
    }];
})()
