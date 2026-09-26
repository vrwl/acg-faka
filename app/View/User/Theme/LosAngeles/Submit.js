/* ============================================================================
   洛杉矶 · 后台配置面板

   本文件的内容会被后台 eval，必须**求值为** [{name, form:[...]}]。
   自定义控件（type:'custom'）的契约：
     · 后台只渲染一个空的 .component-<name> 容器；
     · complete(builder, $block) 负责建 UI，并自己插入 <input name="<name>">；
     · 值必须写成 encodeURIComponent(JSON.stringify(...))
       —— 服务端 setThemeConfig 会对每个值做一次 urldecode。

   铁律（Seattle 踩过、这里同样适用）：卡片上不要加任何 transform 动画。
   非 none 的 transform 会让卡片成为绝对定位后代的包含块，
   卡片里 layui 下拉框的 dl 会被甩出视口，表现是"下拉点了不展开"。
   入场动画只用 opacity。
   ========================================================================= */
(() => {
    const setting = (values && values.setting) || {};
    const iconNames = String((values && values.info && values.info.ICON_NAMES) || '')
        .split('|').filter(n => /^[a-z0-9_]+$/.test(n));

    const AUDIENCES = [
        {id: 'all', name: i18n('所有人')},
        {id: 'member', name: i18n('仅登录会员')},
        {id: 'guest', name: i18n('仅访客')}
    ];

    const FLOOR_TYPES = [
        {id: 'seckill', name: i18n('限时秒杀'), desc: i18n('横向滑轨，展示正在秒杀的商品；「秒杀专区」关闭时不显示')},
        {id: 'recommend', name: i18n('店长推荐'), desc: i18n('后台勾选了"推荐"的商品')},
        {id: 'category', name: i18n('分类楼层'), desc: i18n('每个一级分类各占一层')},
        {id: 'waterfall', name: i18n('猜你喜欢'), desc: i18n('无限滚动的商品流，建议放最后')}
    ];

    const esc = v => String(v == null ? '' : v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    /** 读一个 JSON 型配置，脏数据一律当空 */
    const readList = key => {
        try {
            const raw = String(setting[key] || '').trim();
            if (!raw) { return []; }
            const data = JSON.parse(raw);
            return Array.isArray(data) ? data : [];
        } catch (e) { return []; }
    };

    // ------------------------------------------------------------ 面板样式

    const styleFor = unique => `<style>`
        + `.${unique}{display:grid;gap:12px;width:100%;}`
        + `.${unique} .la-cfg-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 14px;`
        + `border:1px solid var(--md-divider,#e5e7eb);border-radius:var(--md-radius-lg,12px);background:var(--md-surface-2,#f8fafc);}`
        + `.${unique} .la-cfg-bar div{display:grid;gap:3px;min-width:0;}`
        + `.${unique} .la-cfg-bar strong{font-size:14px;color:var(--md-on-surface,#111827);}`
        + `.${unique} .la-cfg-bar span{font-size:12px;color:var(--md-on-surface-med,#64748b);}`
        + `.${unique} .la-cfg-bar > button{flex:none;white-space:nowrap;}`
        // 清空：安静的描边胶囊，别和主按钮抢
        + `.${unique} .la-cfg-clear{height:30px;padding:0 14px;border:1px solid var(--md-outline,#cbd5e1);border-radius:999px;`
        + `background:var(--md-surface,#fff);color:var(--md-on-surface-med,#64748b);font-size:12px;font-weight:600;cursor:pointer;`
        + `transition:color .15s,border-color .15s,background-color .15s;}`
        + `.${unique} .la-cfg-clear:hover{color:var(--md-error,#ef4444);border-color:var(--md-error,#ef4444);background:rgba(var(--md-error-rgb,239,68,68),.06);}`
        // 楼层开关：状态胶囊，开=主色淡底，关=灰；宽度自适应且不换行
        + `.${unique} .la-cfg-acts .la-cfg-toggle{width:auto;height:28px;padding:0 12px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;`
        + `color:var(--md-primary,#1d9bf0);background:rgba(var(--md-primary-rgb,29,155,240),.12);}`
        + `.${unique} .la-cfg-acts .la-cfg-toggle:hover:not(:disabled){background:rgba(var(--md-primary-rgb,29,155,240),.2);color:var(--md-primary,#1d9bf0);}`
        + `.${unique} .la-cfg-row.is-off .la-cfg-toggle{color:var(--md-on-surface-med,#64748b);background:var(--md-surface-2,#f1f5f9);}`
        + `.${unique} .la-cfg-row.is-off .la-cfg-toggle:hover:not(:disabled){background:var(--md-hover-overlay,rgba(15,23,42,.08));color:var(--md-on-surface,#111827);}`
        + `.${unique} .la-cfg-list{display:grid;gap:10px;}`
        // 入场只做透明度：卡片一旦有 transform 就成了包含块，里面的 layui 下拉会被甩飞
        + `.${unique} .la-cfg-card{padding:12px 14px;border:1px solid var(--md-divider,#e5e7eb);`
        + `border-radius:var(--md-radius-lg,12px);background:var(--md-surface,#fff);animation:la-cfg-in .16s ease both;}`
        + `.${unique} .la-cfg-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;}`
        + `.${unique} .la-cfg-no{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;`
        + `border-radius:50%;background:rgba(var(--md-primary-rgb,29,155,240),.12);color:var(--md-primary,#1d9bf0);font-size:12px;}`
        + `.${unique} .la-cfg-acts{display:inline-flex;gap:4px;}`
        + `.${unique} .la-cfg-acts button{width:28px;height:28px;border:0;border-radius:8px;background:transparent;`
        + `color:var(--md-on-surface-med,#64748b);cursor:pointer;}`
        + `.${unique} .la-cfg-acts button:hover:not(:disabled){background:var(--md-hover-overlay,rgba(15,23,42,.06));color:var(--md-primary,#1d9bf0);}`
        + `.${unique} .la-cfg-acts button:disabled{opacity:.35;cursor:not-allowed;}`
        + `.${unique} .la-cfg-del:hover:not(:disabled){background:rgba(var(--md-error-rgb,239,68,68),.12);color:var(--md-error,#ef4444);}`
        + `.${unique} .la-cfg-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}`
        + `.${unique} .la-cfg-field{display:grid;gap:5px;min-width:0;}`
        + `.${unique} .la-cfg-field.is-wide{grid-column:1/-1;}`
        + `.${unique} .la-cfg-field label{font-size:12px;color:var(--md-on-surface-med,#64748b);}`
        + `.${unique} .la-cfg-field textarea{width:100%;min-height:96px;padding:8px 10px;line-height:1.5;resize:vertical;font:inherit;font-size:13px;`
        + `border:1px solid var(--md-outline,#cbd5e1);border-radius:var(--md-radius,8px);background:var(--md-surface,#fff);color:var(--md-on-surface,#111827);}`
        + `.${unique} .la-cfg-field textarea:focus{outline:0;border-color:var(--md-primary,#1d9bf0);box-shadow:0 0 0 3px rgba(var(--md-primary-rgb,29,155,240),.16);}`
        + `.${unique} .la-cfg-field input,.${unique} .la-cfg-field select{width:100%;height:38px;padding:0 10px;`
        + `border:1px solid var(--md-outline,#cbd5e1);border-radius:var(--md-radius,10px);`
        + `background:var(--md-surface,#fff);color:var(--md-on-surface,#111827);font-size:13px;}`
        + `.${unique} .la-cfg-img{display:flex;gap:10px;align-items:flex-start;}`
        + `.${unique} .la-cfg-img__pre{width:72px;height:72px;flex:none;border-radius:8px;object-fit:cover;background:var(--md-surface-2,#f1f5f9);`
        + `border:1px solid var(--md-divider,#e5e7eb);display:grid;place-items:center;font-size:11px;color:var(--md-on-surface-dis,#94a3b8);}`
        + `.${unique} .la-cfg-img__body{flex:1;min-width:0;display:grid;gap:6px;}`
        + `.${unique} .la-cfg-img__btn{display:inline-flex;align-items:center;justify-self:start;height:30px;padding:0 12px;cursor:pointer;`
        + `border-radius:999px;font-size:12px;font-weight:600;color:#fff;background:var(--md-primary,#1d9bf0);}`
        + `.${unique} .la-cfg-img__btn:hover{filter:brightness(1.06);}`
        + `.${unique} .la-cfg-field input:focus,.${unique} .la-cfg-field select:focus{outline:0;border-color:var(--md-primary,#1d9bf0);`
        + `box-shadow:0 0 0 3px rgba(var(--md-primary-rgb,29,155,240),.16);}`
        + `.${unique} .la-cfg-empty{display:grid;place-items:center;gap:4px;padding:26px 14px;text-align:center;`
        + `border:1px dashed var(--md-outline,#cbd5e1);border-radius:var(--md-radius-lg,12px);color:var(--md-on-surface-med,#64748b);}`
        + `.${unique} .la-cfg-empty[hidden]{display:none!important;}`
        + `.${unique} .la-cfg-empty strong{font-size:13px;color:var(--md-on-surface,#111827);}`
        + `.${unique} .la-cfg-empty span{font-size:12px;}`
        + `.${unique} .la-cfg-row{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--md-divider,#e5e7eb);`
        + `border-radius:var(--md-radius-lg,12px);background:var(--md-surface,#fff);}`
        + `.${unique} .la-cfg-row .la-cfg-copy{flex:1;min-width:0;display:grid;gap:2px;}`
        + `.${unique} .la-cfg-row strong{font-size:13px;color:var(--md-on-surface,#111827);}`
        + `.${unique} .la-cfg-row span{font-size:12px;color:var(--md-on-surface-med,#64748b);}`
        + `.${unique} .la-cfg-row.is-off{opacity:.5;}`
        // 分类挑选器
        + `.${unique} .la-cfg-find{width:100%;height:34px;padding:0 12px;border:1px solid var(--md-outline,#cbd5e1);`
        + `border-radius:var(--md-radius-lg,12px);background:var(--md-surface,#fff);color:var(--md-on-surface,#111827);font-size:13px;}`
        + `.${unique} .la-cfg-find:focus{outline:0;border-color:var(--md-primary,#1d9bf0);`
        + `box-shadow:0 0 0 3px rgba(var(--md-primary-rgb,29,155,240),.16);}`
        + `.${unique} .la-cfg-cats{display:flex;flex-wrap:wrap;gap:8px;max-height:280px;overflow-y:auto;padding:12px;`
        + `border:1px solid var(--md-divider,#e5e7eb);border-radius:var(--md-radius-lg,12px);background:var(--md-surface-2,#f8fafc);`
        + `font-size:12px;color:var(--md-on-surface-med,#64748b);}`
        + `.${unique} .la-cfg-cat{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;cursor:pointer;`
        + `border:1px solid var(--md-outline,#cbd5e1);border-radius:999px;background:var(--md-surface,#fff);`
        + `color:var(--md-on-surface,#111827);font-size:12px;line-height:1;}`
        + `.${unique} .la-cfg-cat.is-on{border-color:transparent;background:var(--md-primary,#1d9bf0);color:#fff;}`
        + `.${unique} .la-cfg-cat i{display:grid;place-items:center;min-width:16px;height:16px;padding:0 4px;border-radius:999px;`
        + `background:rgba(255,255,255,.26);font-style:normal;font-size:11px;}`
        + `.${unique} .la-cfg-cat em{font-style:normal;opacity:.6;font-size:11px;}`
        + `.${unique} .la-cfg-cat u{text-decoration:none;opacity:.45;font-size:11px;white-space:pre;}`
        + `@keyframes la-cfg-in{from{opacity:0;}to{opacity:1;}}`
        + `</style>`;

    // ------------------------------------------------------------ 通用重复器

    /**
     * 生成一个 custom 字段的 complete()：卡片式增删改 + 上下排序。
     * @param {Object} spec {name, label, hint, max, fields[], required[], defaults{}}
     */
    const repeater = spec => (builder, instance) => {
        const unique = `la-cfg-${spec.name}-${builder.getUnique()}`;
        const max = spec.max || 20;
        let rows = readList(spec.name);

        // 读入时的归一化：存量数据可能是结构化的（比如页脚链接是 [{name,url}] 对象数组），
        // 直接 String() 会变成 "[object Object]" 还会在下次保存时把它写回去。
        // 所以每个字段可以带 toText，把任何形状转成这个输入框能编辑的文本。
        const textOf = (field, raw) => {
            if (typeof field.toText === 'function') { return field.toText(raw); }
            if (raw == null) { return field.def || ''; }
            if (Array.isArray(raw) || typeof raw === 'object') { return ''; }
            return String(raw);
        };

        const fieldHtml = (field, row, index) => {
            const value = textOf(field, row[field.key] != null ? row[field.key] : (field.def || ''));
            const wide = field.wide ? ' is-wide' : '';
            let control;
            if (field.type === 'textarea') {
                control = `<textarea data-key="${esc(field.key)}" rows="${field.rows || 4}" placeholder="${esc(field.placeholder || '')}">${esc(value)}</textarea>`;
            } else if (field.type === 'select') {
                control = `<select data-key="${esc(field.key)}">`
                    + field.options.map(o => `<option value="${esc(o.id)}"${String(o.id) === String(value) ? ' selected' : ''}>${esc(o.name)}</option>`).join('')
                    + `</select>`;
            } else if (field.type === 'icon') {
                control = `<select data-key="${esc(field.key)}">`
                    + iconNames.map(n => `<option value="${esc(n)}"${n === String(value) ? ' selected' : ''}>${esc(n)}</option>`).join('')
                    + `</select>`;
            } else if (field.type === 'image') {
                // 图片字段：缩略图 + 地址框 + 上传。地址框留着，贴外链和上传两条路都通
                const src = String(value || '').trim();
                control = `<div class="la-cfg-img">`
                    + (src ? `<img class="la-cfg-img__pre" src="${esc(src)}" alt="">` : `<span class="la-cfg-img__pre la-cfg-img__pre--empty">${esc(i18n('无图'))}</span>`)
                    + `<div class="la-cfg-img__body">`
                    + `<input type="text" data-key="${esc(field.key)}" value="${esc(src)}" placeholder="${esc(field.placeholder || '')}">`
                    + `<label class="la-cfg-img__btn">${esc(i18n('上传图片'))}`
                    + `<input type="file" accept="image/*" data-upload-for="${esc(field.key)}" hidden></label>`
                    + `</div></div>`;
            } else {
                control = `<input type="text" data-key="${esc(field.key)}" value="${esc(value)}" placeholder="${esc(field.placeholder || '')}">`;
            }
            return `<div class="la-cfg-field${wide}"><label>${esc(field.label)}</label>${control}</div>`;
        };

        const cardHtml = (row, index) => `<div class="la-cfg-card" data-index="${index}">`
            + `<div class="la-cfg-head"><span class="la-cfg-no">${index + 1}</span>`
            + `<span class="la-cfg-acts">`
            + `<button type="button" class="la-cfg-up" title="${esc(i18n('上移'))}">↑</button>`
            + `<button type="button" class="la-cfg-down" title="${esc(i18n('下移'))}">↓</button>`
            + `<button type="button" class="la-cfg-del" title="${esc(i18n('删除'))}">✕</button>`
            + `</span></div>`
            + `<div class="la-cfg-grid">${spec.fields.map(f => fieldHtml(f, row, index)).join('')}</div>`
            + `</div>`;

        instance.html(styleFor(unique)
            + `<div class="${unique}">`
            + `<div class="la-cfg-bar"><div><strong>${esc(spec.label)}</strong><span>${esc(spec.hint || '')}</span></div>`
            + `<button type="button" class="layui-btn layui-btn-sm la-cfg-add">${esc(i18n('新增一项'))}</button></div>`
            + `<div class="la-cfg-list"></div>`
            + `<div class="la-cfg-empty" hidden><strong>${esc(i18n('还没有配置'))}</strong><span>${esc(i18n('点右上角「新增一项」开始'))}</span></div>`
            + `<input type="hidden" name="${esc(spec.name)}">`
            + `</div>`);

        const root = instance.find(`.${unique}`);
        const list = root.find('.la-cfg-list');
        const empty = root.find('.la-cfg-empty');
        const hidden = root.find(`input[name="${spec.name}"]`);

        const sync = () => {
            const clean = rows
                .map(row => {
                    const out = {};
                    spec.fields.forEach(f => { out[f.key] = textOf(f, row[f.key]).trim(); });
                    return out;
                })
                .filter(row => (spec.required || []).every(k => row[k] !== ''));
            hidden.val(encodeURIComponent(JSON.stringify(clean)));
        };

        const paint = () => {
            list.html(rows.map(cardHtml).join(''));
            empty.prop('hidden', rows.length > 0);
            root.find('.la-cfg-add').prop('disabled', rows.length >= max);
            root.find('.la-cfg-up').first().prop('disabled', true);
            root.find('.la-cfg-down').last().prop('disabled', true);
            if (window.layui && layui.form) { layui.form.render('select', builder.getUnique()); }
            sync();
        };

        root.on('click', '.la-cfg-add', () => {
            if (rows.length >= max) { return; }
            rows.push(Object.assign({}, spec.defaults || {}));
            paint();
        });

        root.on('click', '.la-cfg-del', function () {
            rows.splice(Number($(this).closest('.la-cfg-card').data('index')), 1);
            paint();
        });

        root.on('click', '.la-cfg-up', function () {
            const i = Number($(this).closest('.la-cfg-card').data('index'));
            if (i <= 0) { return; }
            [rows[i - 1], rows[i]] = [rows[i], rows[i - 1]];
            paint();
        });

        root.on('click', '.la-cfg-down', function () {
            const i = Number($(this).closest('.la-cfg-card').data('index'));
            if (i >= rows.length - 1) { return; }
            [rows[i + 1], rows[i]] = [rows[i], rows[i + 1]];
            paint();
        });

        root.on('input change', '[data-key]', function () {
            const card = $(this).closest('.la-cfg-card');
            rows[Number(card.data('index'))][$(this).data('key')] = $(this).val();
            sync();
        });

        // 图片上传：和站点 LOGO / 商品封面走同一个接口，返回 data.url 落回地址框。
        //
        // 上限的三层：应用层 Upload::send() 传给 handle() 的 1024000 是 **KB**（≈1000MB，等于不限）；
        // 真正卡人的是 nginx client_max_body_size 和 PHP upload_max_filesize/post_max_size
        // （随镜像发货的 docker 配置是 50M）。前端这道 10MB 只是为了让人在传之前就知道太大了，
        // 超出服务器闸口的情况在下面的 error 分支里单独提示。
        const IMAGE_MAX_BYTES = 10 * 1024 * 1024;
        root.on('change', 'input[type=file][data-upload-for]', function () {
            const fileInput = this;
            const file = fileInput.files && fileInput.files[0];
            if (!file) { return; }
            const key = String($(fileInput).data('upload-for'));
            const card = $(fileInput).closest('.la-cfg-card');
            const index = Number(card.data('index'));
            const msg = text => { window.layer ? layer.msg(text) : alert(text); };
            if (file.size > IMAGE_MAX_BYTES) { msg(i18n('图片不能超过 10MB')); fileInput.value = ''; return; }
            const fd = new FormData();
            fd.append('file', file);
            window.Loading && Loading.show();
            $.ajax({
                type: 'POST', url: '/admin/api/upload/send?mime=image', data: fd,
                contentType: false, processData: false, dataType: 'json',
                success: res => {
                    window.Loading && Loading.hide();
                    if (!res || res.code != 200 || !res.data || !res.data.url) { msg((res && res.msg) || i18n('上传失败')); return; }
                    rows[index][key] = res.data.url;
                    sync();
                    paint();
                    msg(i18n('上传成功，保存后生效'));
                },
                error: xhr => {
                    window.Loading && Loading.hide();
                    // 413 是 nginx 直接拒了整个请求体；PHP 超过 post_max_size 时 $_FILES 为空、接口一般回 500/非 JSON
                    if (xhr && (xhr.status === 413 || xhr.status === 500)) {
                        msg(i18n('服务器拒绝了这个大小的文件，请调大 nginx 的 client_max_body_size 与 PHP 的 upload_max_filesize / post_max_size'));
                        return;
                    }
                    msg(i18n('网络错误'));
                }
            });
            fileInput.value = '';
        });

        paint();
        return {destroy: () => root.off()};
    };

    // ------------------------------------------------------------ 分类挑选器（工厂）
    //
    // 大站有几百个分类，首页哪些位置放哪些分类必须让站长自己挑。
    // 同一个挑选器服务两处：
    //   · 分类楼层 floor_cats —— 只挑一级、不含「推荐」（它有专门的楼层）
    //   · 猜你喜欢 feed_cats  —— 任意层级都能挑、可含「推荐」（作为一个标签很合理）
    // 分类树直接问前台接口要 —— 它一次返回整棵树，不需要后台分页参数。
    // 保存的是有序 id 数组，顺序就是页面上的顺序。

    const catPickerFor = opts => (builder, instance) => {
        const unique = `la-cfg-cats-${builder.getUnique()}`;
        const norm = v => {
            const raw = typeof v === 'object' && v ? v.id : v;
            if (opts.allowRecommend && String(raw) === 'recommend') { return 'recommend'; }
            const n = Number(raw);
            return n > 0 ? n : null;
        };
        let picked = readList(opts.field).map(norm).filter(v => v !== null);
        let cats = [];
        let keyword = '';

        instance.html(styleFor(unique)
            + `<div class="${unique}">`
            + `<div class="la-cfg-bar"><div><strong>${esc(opts.title)}</strong>`
            + `<span>${esc(opts.hint)}</span></div>`
            + `<button type="button" class="la-cfg-clear" data-clear>${esc(i18n('清空'))}</button></div>`
            + `<input type="search" class="la-cfg-find" placeholder="${esc(i18n('筛选分类'))}">`
            + `<div class="la-cfg-cats">${esc(i18n('正在读取分类…'))}</div>`
            + `<input type="hidden" name="${opts.field}">`
            + `</div>`);

        const root = instance.find(`.${unique}`);
        const box = root.find('.la-cfg-cats');
        const hidden = root.find(`input[name="${opts.field}"]`);
        const sync = () => hidden.val(encodeURIComponent(JSON.stringify(picked)));
        const same = (a, b) => String(a) === String(b);

        const paint = () => {
            const kw = keyword.trim().toLowerCase();
            const list = kw ? cats.filter(c => c.name.toLowerCase().indexOf(kw) !== -1) : cats;
            if (!cats.length) { box.html(`<div class="la-cfg-empty">${esc(i18n('还没有任何分类'))}</div>`); return; }
            if (!list.length) { box.html(`<div class="la-cfg-empty">${esc(i18n('没有匹配的分类'))}</div>`); return; }
            // 已选的排最前（按选中顺序），其余保持树的顺序
            const rank = c => { const i = picked.findIndex(v => same(v, c.id)); return i === -1 ? Infinity : i; };
            const sorted = list.slice().sort((a, b) => rank(a) - rank(b) || 0);
            box.html(sorted.map(c => {
                const at = picked.findIndex(v => same(v, c.id));
                return `<button type="button" class="la-cfg-cat${at === -1 ? '' : ' is-on'}" data-id="${esc(String(c.id))}">`
                    + (at === -1 ? '' : `<i>${at + 1}</i>`)
                    + (c.depth ? `<u>${'└'.padStart(c.depth, '　')}</u>` : '')
                    + `<span>${esc(c.name)}</span>`
                    + (c.kids ? `<em>${c.kids}</em>` : '')
                    + `</button>`;
            }).join(''));
        };

        root.on('click', '.la-cfg-cat', function () {
            const id = norm($(this).data('id'));
            if (id === null) { return; }
            const at = picked.findIndex(v => same(v, id));
            at === -1 ? picked.push(id) : picked.splice(at, 1);
            sync(); paint();
        });
        root.on('click', '[data-clear]', () => { picked = []; sync(); paint(); });
        root.on('input', '.la-cfg-find', function () { keyword = String($(this).val() || ''); paint(); });

        // 把树拍平；deep=false 只留一级。任何一层不是数组都当空处理，脏数据不能让整个面板抛错
        const flatten = (nodes, depth, out) => {
            (Array.isArray(nodes) ? nodes : []).forEach(n => {
                if (!n || !n.name) { return; }
                const id = norm(n.id);
                if (id === null) { return; }
                const kids = Array.isArray(n.children) ? n.children : [];
                out.push({
                    id, depth,
                    // 分类名可能带站长塞的图标标签，只取纯文本
                    name: String(n.name).replace(/<[^>]*>/g, '').trim() || `#${n.id}`,
                    kids: kids.length
                });
                if (opts.deep && kids.length) { flatten(kids, depth + 1, out); }
            });
            return out;
        };

        // 平铺列表 → 树，与服务端 Tree::generate 同一口径：父级不在列表里的挂到根上
        const treeOf = rows => {
            const map = {};
            const roots = [];
            rows.forEach(r => { map[r.id] = Object.assign({}, r, {children: []}); });
            rows.forEach(r => {
                const node = map[r.id];
                const parent = Number(r.pid) > 0 ? map[r.pid] : null;
                (parent && parent !== node ? parent.children : roots).push(node);
            });
            return roots;
        };

        sync();
        // 走后台自己的分类接口。以前用的是商城前台的 /user/api/index/data，前台接口会被「反扫描拦截」这类
        // 人机验证插件拦下（后台路由一律放行）：验证过期后它返回 {code:403, data:{anti_kidnap:1}}，
        // data 是个对象，拍平时 forEach 直接抛错，整个模板设置面板就打不开了。
        // 后台接口不分页时返回全部主站分类 {list, total}，按 sort 升序。
        $.ajax({
            url: '/admin/api/category/data', type: 'POST', dataType: 'json',
            success: res => {
                const rows = res && res.code === 200 && res.data && Array.isArray(res.data.list) ? res.data.list : null;
                if (!rows) {
                    const why = res && res.msg ? `：${res.msg}` : '';
                    box.html(`<div class="la-cfg-empty">${esc(opts.failHint + why)}</div>`);
                    return;
                }
                // 停用的分类前台永远不出，挑了也没用
                const tree = treeOf(rows.filter(r => r && r.id != null && Number(r.status) === 1));
                // 「推荐」是前台建树时塞进去的伪分类，后台列表里没有，这里补回来
                if (opts.allowRecommend) { tree.unshift({id: 'recommend', name: i18n('推荐'), children: []}); }
                cats = flatten(tree, 0, []);
                paint();
            },
            error: () => box.html(`<div class="la-cfg-empty">${esc(opts.failHint)}</div>`)
        });

        return {destroy: () => root.off()};
    };

    const floorCatPicker = catPickerFor({
        field: 'floor_cats', deep: false, allowRecommend: false,
        title: i18n('首页分类楼层'),
        hint: i18n('挑选要在首页开楼层的一级分类；不挑就默认取前 6 个'),
        failHint: i18n('分类读取失败，保存后仍按默认取前 6 个')
    });

    const feedCatPicker = catPickerFor({
        field: 'feed_cats', deep: true, allowRecommend: true,
        title: i18n('猜你喜欢的分类标签'),
        hint: i18n('挑选标签条上要显示的分类，任意层级都可以，也可以放「推荐」；不挑就默认取前 12 个一级分类'),
        failHint: i18n('分类读取失败，保存后仍按默认取前 12 个')
    });

    // ------------------------------------------------------------ 首页楼层（固定四层，可开关可排序）

    const floorBuilder = (builder, instance) => {
        const unique = `la-cfg-floors-${builder.getUnique()}`;
        const saved = readList('floors');
        const savedMap = {};
        saved.forEach(item => {
            const type = typeof item === 'string' ? item : String(item.type || '');
            if (type) { savedMap[type] = item; }
        });

        // 已保存的先按原顺序排，没出现过的补在后面（新版本新增楼层不会丢）
        let rows = [];
        saved.forEach(item => {
            const type = typeof item === 'string' ? item : String(item.type || '');
            const meta = FLOOR_TYPES.filter(f => f.id === type)[0];
            if (meta && !rows.some(r => r.type === type)) {
                const on = typeof item === 'object' ? !['', '0', 'false', 'off', 0, false].includes(item.on) : true;
                rows.push({type: type, on: on});
            }
        });
        FLOOR_TYPES.forEach(f => { if (!rows.some(r => r.type === f.id)) { rows.push({type: f.id, on: true}); } });

        instance.html(styleFor(unique)
            + `<div class="${unique}">`
            + `<div class="la-cfg-bar"><div><strong>${esc(i18n('首页楼层'))}</strong>`
            + `<span>${esc(i18n('拖不动就用上下箭头调顺序，关掉的楼层不会渲染'))}</span></div></div>`
            + `<div class="la-cfg-list"></div>`
            + `<input type="hidden" name="floors">`
            + `</div>`);

        const root = instance.find(`.${unique}`);
        const list = root.find('.la-cfg-list');
        const hidden = root.find('input[name="floors"]');

        const sync = () => hidden.val(encodeURIComponent(JSON.stringify(
            rows.map(r => ({type: r.type, on: r.on ? 1 : 0}))
        )));

        const paint = () => {
            list.html(rows.map((row, index) => {
                const meta = FLOOR_TYPES.filter(f => f.id === row.type)[0] || {name: row.type, desc: ''};
                return `<div class="la-cfg-row${row.on ? '' : ' is-off'}" data-index="${index}">`
                    + `<span class="la-cfg-no">${index + 1}</span>`
                    + `<span class="la-cfg-copy"><strong>${esc(meta.name)}</strong><span>${esc(meta.desc)}</span></span>`
                    + `<span class="la-cfg-acts">`
                    + `<button type="button" class="la-cfg-toggle">${row.on ? esc(i18n('已开启')) : esc(i18n('已关闭'))}</button>`
                    + `<button type="button" class="la-cfg-up"${index === 0 ? ' disabled' : ''}>↑</button>`
                    + `<button type="button" class="la-cfg-down"${index === rows.length - 1 ? ' disabled' : ''}>↓</button>`
                    + `</span></div>`;
            }).join(''));
            sync();
        };

        root.on('click', '.la-cfg-toggle', function () {
            const i = Number($(this).closest('.la-cfg-row').data('index'));
            rows[i].on = !rows[i].on;
            paint();
        });
        root.on('click', '.la-cfg-up', function () {
            const i = Number($(this).closest('.la-cfg-row').data('index'));
            if (i <= 0) { return; }
            [rows[i - 1], rows[i]] = [rows[i], rows[i - 1]];
            paint();
        });
        root.on('click', '.la-cfg-down', function () {
            const i = Number($(this).closest('.la-cfg-row').data('index'));
            if (i >= rows.length - 1) { return; }
            [rows[i + 1], rows[i]] = [rows[i], rows[i + 1]];
            paint();
        });

        paint();
        return {destroy: () => root.off()};
    };

    // ------------------------------------------------------------ 表单定义

    const jsonRegex = {value: '^%5B.*%5D$', message: i18n('配置未填写完整，请检查必填项')};

    return [
        {
            name: i18n('外观'),
            form: [
                {
                    title: i18n('色彩模式'), name: 'theme_mode', type: 'radio',
                    dict: [
                        {id: 'auto', name: i18n('跟随系统')},
                        {id: 'light', name: i18n('固定白天')},
                        {id: 'dark', name: i18n('固定黑夜')}
                    ],
                    default: setting.theme_mode || 'auto'
                },
                {
                    title: i18n('主题强调色'), name: 'accent', type: 'radio',
                    dict: [
                        {id: 'sunset', name: i18n('烈焰红（默认）')},
                        {id: 'magenta', name: i18n('玫红')},
                        {id: 'pacific', name: i18n('靛蓝')},
                        {id: 'palm', name: i18n('松绿')},
                        {id: 'noir', name: i18n('墨黑')}
                    ],
                    default: setting.accent || 'sunset'
                },
                {
                    title: i18n('商品卡密度'), name: 'density', type: 'radio',
                    dict: [
                        {id: 'cozy', name: i18n('舒适')},
                        {id: 'compact', name: i18n('紧凑（每屏更多商品）')}
                    ],
                    default: setting.density || 'cozy'
                },
                {title: i18n('显示销量'), name: 'show_sold', type: 'switch', text: i18n('显示|隐藏'), default: setting.show_sold ?? '1'},
                {title: i18n('显示库存'), name: 'show_stock', type: 'switch', text: i18n('显示|隐藏'), default: setting.show_stock ?? '1'},
                {title: i18n('显示所属店铺'), name: 'show_owner', type: 'switch', text: i18n('显示|隐藏'), default: setting.show_owner ?? '1'},
                {
                    title: i18n('未登录时展示会员价'), name: 'show_member_price', type: 'switch',
                    text: i18n('显示|隐藏'), default: setting.show_member_price ?? '1'
                }
            ]
        },
        {
            name: i18n('首页运营'),
            form: [
                {
                    title: i18n('顶部滚动公告'), name: 'topbar_notice', type: 'input',
                    placeholder: i18n('留空则显示「欢迎来到 店铺名」'), default: setting.topbar_notice || ''
                },
                {
                    title: i18n('秒杀专区'), name: 'seckill_on', type: 'switch', text: i18n('开启|关闭'),
                    default: setting.seckill_on ?? '1',
                    tips: i18n('关闭后，手机底部导航不再出现「秒杀」，首页也不展示秒杀楼层和秒杀页；商品自己设置的秒杀价照常生效')
                },
                {title: i18n('首页楼层'), name: 'floors', type: 'custom', default: setting.floors || '', complete: floorBuilder},
                {
                    title: i18n('分类楼层选哪些'), name: 'floor_cats', type: 'custom',
                    default: setting.floor_cats || '', regex: jsonRegex, complete: floorCatPicker
                },
                {
                    title: i18n('猜你喜欢显示哪些分类'), name: 'feed_cats', type: 'custom',
                    default: setting.feed_cats || '', regex: jsonRegex, complete: feedCatPicker
                },
                {
                    title: i18n('轮播图'), name: 'banners', type: 'custom', default: setting.banners || '',
                    regex: jsonRegex,
                    complete: repeater({
                        name: 'banners', label: i18n('首页轮播图'), max: 8,
                        hint: i18n('不配置时首页会用一块纯 CSS 的红色海报位'),
                        required: ['image'],
                        fields: [
                            {key: 'image', label: i18n('图片（必填）'), type: 'image', placeholder: i18n('上传或粘贴图片地址'), wide: true},
                            {key: 'url', label: i18n('跳转链接'), placeholder: '/item/1'},
                            {key: 'title', label: i18n('标题')},
                            {key: 'sub', label: i18n('副标题'), wide: true}
                        ]
                    })
                },
                {
                    title: i18n('金刚区入口'), name: 'shortcuts', type: 'custom', default: setting.shortcuts || '',
                    regex: jsonRegex,
                    complete: repeater({
                        name: 'shortcuts', label: i18n('快捷入口'), max: 20,
                        hint: i18n('电脑端显示在轮播下方，手机端是可翻页的金刚区'),
                        required: ['name', 'url'],
                        defaults: {icon: 'sparkle', audience: 'all'},
                        fields: [
                            {key: 'name', label: i18n('名称（必填）')},
                            {key: 'icon', label: i18n('图标'), type: 'icon'},
                            {key: 'url', label: i18n('链接（必填）'), placeholder: '/user/index/query', wide: true},
                            {key: 'audience', label: i18n('可见范围'), type: 'select', options: AUDIENCES, wide: true}
                        ]
                    })
                }
            ]
        },
        {
            name: i18n('导航与页脚'),
            form: [
                {
                    title: i18n('手机底部导航'), name: 'tabbar', type: 'custom', default: setting.tabbar || '',
                    regex: jsonRegex,
                    complete: repeater({
                        name: 'tabbar', label: i18n('底部 TabBar'), max: 5,
                        hint: i18n('最多 5 格；留空则用出厂的「首页/分类/秒杀/订单/我的」，关闭「秒杀专区」后秒杀那一格会自动去掉'),
                        required: ['name', 'url'],
                        defaults: {icon: 'home'},
                        fields: [
                            {key: 'name', label: i18n('文字（必填，最多 6 字）')},
                            {key: 'icon', label: i18n('图标'), type: 'icon'},
                            {key: 'url', label: i18n('链接（必填）'), placeholder: '/user/index/query', wide: true},
                            {key: 'match', label: i18n('高亮路由前缀'), placeholder: '/user/index/query', wide: true}
                        ]
                    })
                },
                {
                    title: i18n('页脚栏目'), name: 'footer_groups', type: 'custom', default: setting.footer_groups || '',
                    regex: jsonRegex,
                    complete: repeater({
                        name: 'footer_groups', label: i18n('页脚栏目'), max: 5,
                        hint: i18n('每个栏目一列；链接用「名称|地址」的形式，一行一个，最多 10 条'),
                        required: ['title'],
                        fields: [
                            {key: 'title', label: i18n('栏目标题（必填）')},
                            {
                                key: 'links', type: 'textarea', rows: 4, wide: true,
                                label: i18n('链接（名称|地址，一行一个）'),
                                placeholder: '订单查询|/user/index/query\n充值中心|/user/recharge/index',
                                // 出厂值/手写 JSON 里 links 是 [{name,url}] 对象数组；旧版本面板还可能存过
                                // "名称|地址; 名称|地址" 的分号串 —— 都转成一行一个
                                toText: v => {
                                    if (Array.isArray(v)) {
                                        return v.filter(l => l && typeof l === 'object')
                                            .map(l => `${String(l.name || '').trim()}|${String(l.url || '').trim()}`)
                                            .filter(t => t !== '|').join('\n');
                                    }
                                    const str = v == null ? '' : String(v);
                                    // 被写坏的 "[object Object]" 不是链接，清掉
                                    return str.replace(/\[object Object\],?/g, '').split(/[;；\n]+/).map(t => t.trim()).filter(Boolean).join('\n');
                                }
                            }
                        ]
                    })
                },
                {
                    title: i18n('页脚版权 / 备案号'), name: 'footer_note', type: 'input',
                    placeholder: i18n('显示在页脚最底部，可留空'), default: setting.footer_note || ''
                }
            ]
        }
    ];
})()
