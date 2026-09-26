!function () {
    /**
     * 次元博客 · 全屏写作页
     * CodeMirror(markdown) + marked 实时预览 + hljs 高亮；正文 base64 提交；
     * 自动保存：localStorage 5s 兜底 + 服务端 60s（仅草稿）；粘贴/拖拽图片直传。
     */
    const namespace = '.blogWriteController';
    let controllerActive = true;
    if (typeof window.__blogWriteDestroy === 'function') window.__blogWriteDestroy();

    const API = (p) => '/plugin/Blog/admin/' + p;
    const b64 = (s) => btoa(unescape(encodeURIComponent(s)));
    const unb64 = (s) => { try { return decodeURIComponent(escape(atob(s))); } catch (e) { return ''; } };
    const $app = $('#blog-write-app');
    if (!$app.length) return;

    const params = new URLSearchParams(window.location.search);
    const state = {
        id: parseInt(params.get('id') || '0', 10) || 0,
        type: (params.get('type') === 'page') ? 'page' : 'post',
        savedStatus: null,        //服务端最后已知状态
        selectedStatus: 0,        //侧栏选择的目标状态
        slugEdited: false,
        dirty: false,
        timers: []
    };
    const draftKey = () => 'blog_draft_' + (state.id > 0 ? state.id : 'new_' + state.type);

    /* ---------- CodeMirror ---------- */
    const cm = CodeMirror.fromTextArea(document.getElementById('blog-md-editor'), {
        mode: 'markdown',
        lineWrapping: true,
        lineNumbers: false,
        viewportMargin: 30,
        placeholder: '',
        extraKeys: {
            'Ctrl-B': () => cmd('bold'), 'Cmd-B': () => cmd('bold'),
            'Ctrl-I': () => cmd('italic'), 'Cmd-I': () => cmd('italic'),
            'Ctrl-K': () => cmd('link'), 'Cmd-K': () => cmd('link'),
            'Ctrl-S': () => { save(); return false; }, 'Cmd-S': () => { save(); return false; }
        }
    });

    /* ---------- 预览 ---------- */
    const mdParse = (s) => {
        if (!window.marked) return $('<i></i>').text(s).html();
        try { return marked.parse ? marked.parse(s) : marked(s); } catch (e) { return ''; }
    };
    //提示框 ::: 语法的预览近似（最终以服务端 Parsedown 为准）
    function admonTransform(md) {
        const lines = String(md).split('\n');
        const out = [];
        let open = false, inFence = false;
        for (const line of lines) {
            if (/^```/.test(line)) inFence = !inFence;
            if (!inFence) {
                const m = line.match(/^:::\s*(tip|info|warning|danger|note)\s*$/i);
                if (m) { out.push('<div class="blog-admon blog-admon--' + m[1].toLowerCase() + '">', ''); open = true; continue; }
                if (open && /^:::\s*$/.test(line)) { out.push('', '</div>'); open = false; continue; }
            }
            out.push(line);
        }
        if (open) out.push('', '</div>');
        return out.join('\n');
    }
    const $preview = $('#blog-md-preview');
    let previewTimer = null;
    function renderPreview() {
        const html = mdParse(admonTransform(cm.getValue()));
        $preview.html(html);
        if (window.hljs) {
            $preview.find('pre code').each(function () { try { hljs.highlightElement(this); } catch (e) {} });
        }
        updateCounters();
    }
    cm.on('change', () => {
        state.dirty = true;
        clearTimeout(previewTimer);
        previewTimer = setTimeout(renderPreview, 200);
    });

    function updateCounters() {
        const md = cm.getValue();
        const chars = md.replace(/\s/g, '').length;
        $('[data-blog-count-chars]').text(chars + ' ' + i18n('字'));
        $('[data-blog-count-reading]').text(chars ? '≈ ' + Math.max(1, Math.ceil(chars / 300)) + ' ' + i18n('分钟阅读') : '');
    }

    /* ---------- 工具栏命令 ---------- */
    function wrapSelection(before, after, placeholder) {
        const sel = cm.getSelection() || placeholder || '';
        cm.replaceSelection(before + sel + after);
        if (!cm.getSelection()) {
            const cur = cm.getCursor();
            cm.setCursor({line: cur.line, ch: cur.ch - after.length});
        }
        cm.focus();
    }
    function prefixLines(prefix, numbered) {
        const from = cm.getCursor('from'), to = cm.getCursor('to');
        for (let i = from.line, n = 1; i <= to.line; i++, n++) {
            const p = numbered ? (n + '. ') : prefix;
            cm.replaceRange(p, {line: i, ch: 0});
        }
        cm.focus();
    }
    function insertBlock(text) {
        const cur = cm.getCursor();
        const lineContent = cm.getLine(cur.line) || '';
        const nl = lineContent.trim() === '' ? '' : '\n\n';
        cm.replaceRange(nl + text + '\n', {line: cur.line, ch: lineContent.length});
        cm.focus();
    }
    function cmd(name) {
        switch (name) {
            case 'bold': return wrapSelection('**', '**', i18n('加粗文本'));
            case 'italic': return wrapSelection('*', '*', i18n('斜体文本'));
            case 'strike': return wrapSelection('~~', '~~', '');
            case 'h2': return prefixLines('## ');
            case 'h3': return prefixLines('### ');
            case 'link': return wrapSelection('[', '](https://)', i18n('链接文字'));
            case 'image': return $file.trigger('click');
            case 'codeinline': return wrapSelection('`', '`', 'code');
            case 'codeblock': return insertBlock('```bash\n' + (cm.getSelection() || '') + '\n```');
            case 'quote': return prefixLines('> ');
            case 'ul': return prefixLines('- ');
            case 'ol': return prefixLines('', true);
            case 'task': return prefixLines('- [ ] ');
            case 'table': return insertBlock('| ' + i18n('列') + '1 | ' + i18n('列') + '2 | ' + i18n('列') + '3 |\n| --- | --- | --- |\n|  |  |  |');
            case 'tip': return insertBlock(':::tip\n' + (cm.getSelection() || i18n('小技巧内容')) + '\n:::');
            case 'warning': return insertBlock(':::warning\n' + (cm.getSelection() || i18n('注意事项')) + '\n:::');
            case 'hr': return insertBlock('---');
        }
    }
    $app.find('[data-md-cmd]').off(namespace).on('click' + namespace, function (e) {
        e.preventDefault();
        cmd($(this).data('md-cmd'));
    });

    /* ---------- 图片上传（按钮/粘贴/拖拽） ---------- */
    const $file = $('<input type="file" accept="image/*" multiple class="d-none">').appendTo($app);
    function replaceInEditor(find, replace) {
        const value = cm.getValue();
        const idx = value.indexOf(find);
        if (idx < 0) return;
        const cur = cm.getCursor();
        cm.setValue(value.replace(find, replace));
        try { cm.setCursor(cur); } catch (e) {}
    }
    function uploadImage(file) {
        if (!file || !/^image\//.test(file.type || '')) return;
        const ph = '![⏳](uploading-' + Date.now() + '-' + Math.floor(Math.random() * 1e4) + ')';
        insertBlock(ph);
        const fd = new FormData();
        fd.append('file', file, file.name || 'paste.png');
        $.ajax({
            url: '/admin/api/upload/send?mime=image&thumb_height=300',
            type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: res => {
                if (res.code === 200 && res.data && res.data.url) {
                    replaceInEditor(ph, '![](' + res.data.url + ')');
                    renderPreview();
                } else {
                    replaceInEditor(ph, '');
                    message.error((res && res.msg) || i18n('图片上传失败'));
                }
            },
            error: () => { replaceInEditor(ph, ''); message.error(i18n('图片上传失败')); }
        });
    }
    $file.off(namespace).on('change' + namespace, function () {
        Array.from(this.files || []).forEach(uploadImage);
        this.value = '';
    });
    cm.on('paste', (instance, e) => {
        const items = (e.clipboardData || {}).items || [];
        for (const item of items) {
            if (item.kind === 'file' && /^image\//.test(item.type)) {
                e.preventDefault();
                uploadImage(item.getAsFile());
            }
        }
    });
    cm.on('drop', (instance, e) => {
        const files = (e.dataTransfer || {}).files || [];
        if (files.length) {
            e.preventDefault();
            Array.from(files).forEach(uploadImage);
        }
    });

    /* ---------- 视图切换（编辑/预览/分屏） ---------- */
    const $panes = $app.find('.blog-write__panes');
    function setMode(mode) {
        $panes.attr('data-blog-mode', mode);
        $app.find('[data-blog-pane]').removeClass('active').filter('[data-blog-pane="' + mode + '"]').addClass('active');
        if (mode !== 'edit') renderPreview();
        setTimeout(() => cm.refresh(), 60);
    }
    $app.find('[data-blog-pane]').off(namespace).on('click' + namespace, function () {
        setMode($(this).data('blog-pane'));
    });
    setMode(window.innerWidth < 992 ? 'edit' : 'split');

    /* ---------- 侧栏（移动端浮层） ---------- */
    const sideOpen = (open) => $app.toggleClass('blog-side-open', open);
    $app.find('.blog-btn-side-toggle').off(namespace).on('click' + namespace, () => sideOpen(!$app.hasClass('blog-side-open')));
    $app.find('.blog-btn-side-close, [data-blog-side-shade]').off(namespace).on('click' + namespace, () => sideOpen(false));

    /* ---------- 状态段控件 ---------- */
    function setSelectedStatus(status) {
        state.selectedStatus = status;
        $app.find('[data-blog-status-seg] button').removeClass('active')
            .filter('[data-status="' + status + '"]').addClass('active');
        refreshChips();
    }
    $app.find('[data-blog-status-seg] button').off(namespace).on('click' + namespace, function () {
        setSelectedStatus(parseInt($(this).data('status'), 10));
        state.dirty = true;
    });

    function refreshChips() {
        const chip = $('[data-blog-status-chip]');
        if (state.savedStatus === null) chip.text(i18n('新草稿')).attr('data-tone', 'draft');
        else if (state.savedStatus === 1) chip.text(i18n('已发布')).attr('data-tone', 'published');
        else if (state.savedStatus === 2) chip.text(i18n('已隐藏')).attr('data-tone', 'hidden');
        else chip.text(i18n('草稿')).attr('data-tone', 'draft');
        $('[data-blog-publish-label]').text(' ' + (state.savedStatus === 1 ? i18n('更新') : i18n('发布')));
        const $link = $('[data-blog-front-link]');
        const slug = $('#blog-field-slug').val();
        if (state.savedStatus === 1 && slug) {
            const url = '/plugin/Blog/' + (state.type === 'page' ? 'page' : 'post') + '/detail?slug=' + encodeURIComponent(slug);
            $link.show().find('[data-blog-front-url]').attr('href', url).attr('target', '_blank');
        } else {
            $link.hide();
        }
    }

    /* ---------- 字段：分类 / 标签 / 封面 / 摘要 / slug ---------- */
    if (state.type === 'page') {
        $app.find('[data-blog-post-only]').addClass('d-none');
        $app.find('[data-blog-page-only]').removeClass('d-none');
    }

    util.post({
        url: API('category/data'), loader: false,
        done: res => {
            const $sel = $('#blog-field-category').empty();
            $sel.append(new Option(i18n('未分类'), '0'));
            (res.data.list || []).forEach(c => $sel.append(new Option('　'.repeat(c.depth || 0) + c.name, c.id)));
            if (state.pendingCategory) $sel.val(String(state.pendingCategory));
        }
    });

    const $tags = $('#blog-field-tags');
    const hasSelect2 = typeof $.fn.select2 === 'function';
    if (hasSelect2 && state.type === 'post') {
        $tags.select2({
            tags: true, multiple: true, width: '100%',
            placeholder: i18n('输入或选择标签'),
            ajax: {
                url: API('tag/suggest'), type: 'POST', dataType: 'json', delay: 250,
                data: p => ({q: p.term || ''}),
                processResults: d => ({results: ((d.data && d.data.list) || []).map(x => ({id: x.id, text: x.text}))})
            }
        });
    }
    function setTags(names) {
        if (hasSelect2) {
            $tags.empty();
            (names || []).forEach(n => $tags.append(new Option(n, n, true, true)));
            $tags.trigger('change');
        } else {
            $tags.replaceWith('<input class="form-control" id="blog-field-tags" placeholder="' + i18n('逗号分隔多个标签') + '" value="' + (names || []).join(',') + '">');
        }
    }
    function getTags() {
        const $el = $('#blog-field-tags');
        if (hasSelect2 && $el.is('select')) return ($el.val() || []);
        return String($el.val() || '').split(/[,，]/).map(s => s.trim()).filter(Boolean);
    }

    /* ---------- 绑定商品 ---------- */
    function setCommodity(item) {
        const $picked = $('[data-blog-goods-picked]');
        const $empty = $('[data-blog-goods-empty]');
        if (!item || !item.id) {
            $('#blog-field-commodity').val('0');
            $picked.attr('hidden', true);
            $empty.removeAttr('hidden');
        } else {
            $('#blog-field-commodity').val(String(item.id));
            $('[data-blog-goods-thumb]').html(item.cover
                ? '<img src="' + esc(item.cover) + '" alt="">'
                : '<i class="blogi blogi-cart"></i>');
            $('[data-blog-goods-name]').text(item.name || '');
            $('[data-blog-goods-price]').text(item.price ? (item.price + '') : '');
            $picked.removeAttr('hidden');
            $empty.attr('hidden', true);
        }
        state.dirty = true;
    }

    const esc = (str) => $('<i></i>').text(str == null ? '' : String(str)).html();

    //商品选择弹层：搜索 + 列表点选
    function openCommodityPicker() {
        const uid = 'bg' + Date.now();
        const html = '<div class="blog-goodsdlg" id="' + uid + '">'
            + '<div class="blog-goodsdlg__search"><i class="blogi blogi-search"></i>'
            + '<input type="search" placeholder="' + i18n('搜索商品名称或 ID') + '" autocomplete="off"></div>'
            + '<div class="blog-goodsdlg__list"></div></div>';
        const index = layer.open({
            type: 1,
            title: util.icon('blogi blogi-cart') + ' ' + i18n('选择商品'),
            area: [util.isPc() ? '460px' : '92%', '540px'],
            shadeClose: true,
            content: html,
            success: () => {
                const $root = $('#' + uid);
                const $input = $root.find('input');
                const $list = $root.find('.blog-goodsdlg__list');
                let timer = null;

                const render = (items) => {
                    if (!items.length) {
                        $list.html('<div class="blog-goodsdlg__empty">' + i18n('没有找到商品') + '</div>');
                        return;
                    }
                    $list.empty();
                    items.forEach(item => {
                        const $row = $('<a class="blog-goodsdlg__item" href="#" data-acg-noop>'
                            + (item.cover ? '<span class="blog-goodsdlg__thumb"><img src="' + esc(item.cover) + '" alt=""></span>'
                                : '<span class="blog-goodsdlg__thumb"><i class="blogi blogi-cart"></i></span>')
                            + '<span class="blog-goodsdlg__body"><b>' + esc(item.name) + '</b>'
                            + '<span>#' + item.id + ' · ' + esc(item.price) + '</span></span></a>');
                        $row.on('click', () => {
                            setCommodity(item);
                            layer.close(index);
                        });
                        $list.append($row);
                    });
                };

                const search = (q) => util.post({
                    url: API('post/commoditySearch'),
                    data: {q: q || ''},
                    loader: false,
                    done: res => render((res.data && res.data.list) || []),
                    error: () => render([])
                });

                search('');
                $input.on('input', function () {
                    clearTimeout(timer);
                    const q = this.value;
                    timer = setTimeout(() => search(q), 260);
                });
                setTimeout(() => { try { $input.focus(); } catch (e) {} }, 120);
            }
        });
    }

    $app.find('.blog-goods-open').off(namespace).on('click' + namespace, openCommodityPicker);
    $app.find('.blog-goods-clear').off(namespace).on('click' + namespace, () => setCommodity(null));

    //封面
    const $coverPreview = $('[data-blog-cover-preview]');
    function setCover(url) {
        $('#blog-field-cover').val(url || '');
        $coverPreview.html(url ? '<img src="' + url + '" alt="">' : '<i class="blogi blogi-image"></i>');
        state.dirty = true;
    }
    $app.find('.blog-cover-upload').off(namespace).on('click' + namespace, () => $app.find('[data-blog-cover-file]').trigger('click'));
    $app.find('.blog-cover-clear').off(namespace).on('click' + namespace, () => setCover(''));
    $app.find('[data-blog-cover-file]').off(namespace).on('change' + namespace, function () {
        const file = (this.files || [])[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('file', file, file.name);
        $.ajax({
            url: '/admin/api/upload/send?mime=image&thumb_height=400',
            type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: res => {
                if (res.code === 200 && res.data && res.data.url) setCover(res.data.url);
                else message.error((res && res.msg) || i18n('上传失败'));
            },
            error: () => message.error(i18n('上传失败'))
        });
        this.value = '';
    });
    $('#blog-field-cover').off(namespace).on('change' + namespace, function () { setCover(String($(this).val() || '').trim()); });

    $('#blog-field-summary').off(namespace).on('input' + namespace, function () {
        $('[data-blog-summary-count]').text(String($(this).val() || '').length + '/500');
        state.dirty = true;
    });

    //slug 可用性
    $('#blog-field-slug').off(namespace).on('input' + namespace, () => { state.slugEdited = true; state.dirty = true; })
        .on('blur' + namespace, function () {
            const slug = String($(this).val() || '').trim();
            const $hint = $('[data-blog-slug-hint]');
            if (!slug) { $hint.text(''); return; }
            util.post({
                url: API('post/slugCheck'), data: {slug: slug, id: state.id}, loader: false,
                done: res => {
                    if (res.data.available) {
                        $hint.html('<span class="text-success">✓ ' + i18n('可用') + '：/' + res.data.slug + '</span>');
                    } else {
                        $hint.html('<span class="text-danger">✗ ' + i18n('已被占用，保存时将自动使用') + ' /' + res.data.suggest + '</span>');
                    }
                }
            });
        });

    $('#blog-title-input').off(namespace).on('input' + namespace, () => { state.dirty = true; });

    /* ---------- 时间转换 ---------- */
    const toLocalInput = (s) => (s ? String(s).replace(' ', 'T').slice(0, 16) : '');
    const toServerTime = (v) => (v ? String(v).replace('T', ' ') + (v.length === 16 ? ':00' : '') : '');

    /* ---------- 载入 / 本地草稿 ---------- */
    function fillForm(d) {
        $('#blog-title-input').val(d.title || '');
        cm.setValue(unb64(d.content_md_b64 || '') || '');
        $('#blog-field-slug').val(d.slug || '');
        $('#blog-field-publish-time').val(toLocalInput(d.publish_time));
        $('#blog-field-summary').val(d.summary || '').trigger('input');
        setCover(d.cover || '');
        $('#blog-field-seo-title').val(d.seo_title || '');
        $('#blog-field-seo-keywords').val(d.seo_keywords || '');
        $('#blog-field-seo-description').val(d.seo_description || '');
        $('#blog-field-top').prop('checked', d.top == 1);
        $('#blog-field-allow-comment').prop('checked', d.allow_comment == 1);
        $('#blog-field-allow-comment-page').prop('checked', d.allow_comment == 1);
        $('#blog-field-weight').val(d.weight || 0);
        state.pendingCategory = d.category_id || 0;
        $('#blog-field-category').val(String(d.category_id || 0));
        setTags(d.tag_names || []);
        setCommodity(d.commodity || null);
        state.type = d.type === 'page' ? 'page' : 'post';
        state.savedStatus = parseInt(d.status, 10);
        setSelectedStatus(state.savedStatus);
        state.dirty = false;
        refreshChips();
        renderPreview();
    }

    function maybeRestoreLocal() {
        let raw = null;
        try { raw = localStorage.getItem(draftKey()); } catch (e) {}
        if (!raw) return;
        let draft = null;
        try { draft = JSON.parse(raw); } catch (e) { return; }
        if (!draft || typeof draft.md !== 'string') return;
        if (state.id === 0) {
            if (draft.md.trim() || (draft.title || '').trim()) {
                $('#blog-title-input').val(draft.title || '');
                cm.setValue(draft.md);
                message.info && message.info(i18n('已恢复未保存的本地草稿'));
                renderPreview();
            }
            return;
        }
        //编辑已有：本地快照比服务端新才提示
        if (draft.t && state.serverUpdatedAt && draft.t > state.serverUpdatedAt && draft.md !== cm.getValue()) {
            message.ask(i18n('检测到更新的本地未保存草稿，是否恢复？'), () => {
                $('#blog-title-input').val(draft.title || $('#blog-title-input').val());
                cm.setValue(draft.md);
                renderPreview();
            }, i18n('恢复本地草稿'), i18n('恢复'));
        }
    }

    if (state.id > 0) {
        util.post(API('post/get'), {id: state.id}, res => {
            state.serverUpdatedAt = res.data.update_time ? Date.parse(String(res.data.update_time).replace(' ', 'T')) : 0;
            fillForm(res.data);
            maybeRestoreLocal();
        });
    } else {
        state.savedStatus = null;
        setSelectedStatus(0);
        setTags([]);
        setCommodity(null);
        refreshChips();
        renderPreview();
        maybeRestoreLocal();
    }

    /* ---------- 保存 ---------- */
    function collectPayload() {
        return {
            id: state.id,
            type: state.type,
            title: String($('#blog-title-input').val() || '').trim(),
            slug: String($('#blog-field-slug').val() || '').trim(),
            category_id: parseInt($('#blog-field-category').val() || '0', 10) || 0,
            commodity_id: parseInt($('#blog-field-commodity').val() || '0', 10) || 0,
            tags: getTags(),
            cover: String($('#blog-field-cover').val() || '').trim(),
            summary: String($('#blog-field-summary').val() || ''),
            seo_title: String($('#blog-field-seo-title').val() || ''),
            seo_keywords: String($('#blog-field-seo-keywords').val() || ''),
            seo_description: String($('#blog-field-seo-description').val() || ''),
            status: state.selectedStatus,
            top: $('#blog-field-top').prop('checked') ? 1 : 0,
            allow_comment: (state.type === 'page'
                ? $('#blog-field-allow-comment-page').prop('checked')
                : $('#blog-field-allow-comment').prop('checked')) ? 1 : 0,
            publish_time: toServerTime($('#blog-field-publish-time').val()),
            weight: parseInt($('#blog-field-weight').val() || '0', 10) || 0,
            template: '',
            content_md_b64: b64(cm.getValue()),
            autosave: 0
        };
    }

    let saving = false;
    function save(autosave = false) {
        if (saving) return;
        const payload = collectPayload();
        if (autosave) {
            if (!(state.id > 0 && state.savedStatus === 0)) return; //服务端自动保存仅限已存在的草稿
            payload.autosave = 1;
            payload.status = 0;
        } else if (!payload.title) {
            message.error(i18n('请先填写标题'));
            $('#blog-title-input').trigger('focus');
            return;
        }
        saving = true;
        util.post({
            url: API('post/save'),
            data: payload,
            loader: !autosave,
            done: res => {
                saving = false;
                const d = res.data || {};
                const isNew = state.id === 0;
                state.id = d.id || state.id;
                state.savedStatus = (typeof d.status === 'number') ? d.status : parseInt(d.status || 0, 10);
                setSelectedStatus(state.savedStatus);
                if (d.slug) $('#blog-field-slug').val(d.slug);
                if (d.publish_time) $('#blog-field-publish-time').val(toLocalInput(d.publish_time));
                state.serverUpdatedAt = Date.now();
                state.dirty = false;
                try { localStorage.removeItem('blog_draft_new_' + state.type); localStorage.removeItem(draftKey()); } catch (e) {}
                $('[data-blog-save-hint]').text((autosave ? i18n('已自动保存') : i18n('已保存')) + ' ' + new Date().toLocaleTimeString());
                refreshChips();
                if (isNew && d.id && window.history && history.replaceState) {
                    history.replaceState(null, '', '/plugin/Blog/panel/write?id=' + d.id);
                }
                if (!autosave) message.success(state.savedStatus === 1 ? i18n('已发布') : i18n('已保存'));
            },
            error: res => {
                saving = false;
                message.error((res && res.msg) || i18n('保存失败'));
            },
            fail: () => { saving = false; }
        });
    }

    $app.find('.blog-btn-save-draft').off(namespace).on('click' + namespace, () => {
        setSelectedStatus(0);
        save();
    });
    $app.find('.blog-btn-publish').off(namespace).on('click' + namespace, () => {
        if (state.selectedStatus !== 1) setSelectedStatus(1);
        save();
    });
    $app.find('.blog-write-back').off(namespace).on('click' + namespace, () => {
        const url = '/plugin/Blog/panel/' + (state.type === 'page' ? 'pages' : 'posts');
        //route() 是 URL 构造器不是跳转函数；后台导航走 pjax
        if (window.AdminMobile && typeof AdminMobile.navigate === 'function') { AdminMobile.navigate(url); return; }
        if (window.jQuery && $.pjax) { $.pjax({url: url, container: '#pjax-container', fragment: '#pjax-container', timeout: 8000}); return; }
        window.location.href = url;
    });

    /* ---------- 服务端最终预览 ---------- */
    $app.find('.blog-btn-server-preview').off(namespace).on('click' + namespace, () => {
        util.post(API('post/preview'), {content_md_b64: b64(cm.getValue())}, res => {
            const html = '<div class="blog-mdb" style="padding:20px 24px;line-height:1.85;word-break:break-word">' + (res.data.html || '<p class="text-muted">' + i18n('（空）') + '</p>') + '</div>';
            layer.open({
                type: 1,
                title: i18n('最终预览（服务端渲染，与前台一致）') + ' · ≈' + (res.data.reading || 1) + i18n('分钟'),
                area: [Math.min(860, window.innerWidth - 20) + 'px', Math.min(680, window.innerHeight - 40) + 'px'],
                shadeClose: true,
                content: html,
                success: (layero) => {
                    if (window.hljs) $(layero).find('pre code').each(function () { try { hljs.highlightElement(this); } catch (e) {} });
                }
            });
        });
    });

    /* ---------- 自动保存 ---------- */
    state.timers.push(setInterval(() => {
        if (!state.dirty) return;
        try {
            localStorage.setItem(draftKey(), JSON.stringify({
                title: String($('#blog-title-input').val() || ''),
                md: cm.getValue(),
                t: Date.now()
            }));
        } catch (e) {}
    }, 5000));
    state.timers.push(setInterval(() => { if (state.dirty) save(true); }, 60000));

    const beforeUnload = (e) => {
        if (!state.dirty) return;
        e.preventDefault();
        e.returnValue = '';
    };
    window.addEventListener('beforeunload', beforeUnload);

    /* ---------- 销毁 ---------- */
    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        state.timers.forEach(clearInterval);
        clearTimeout(previewTimer);
        window.removeEventListener('beforeunload', beforeUnload);
        $app.find('*').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (window.__blogWriteDestroy === destroy) delete window.__blogWriteDestroy;
    }
    window.__blogWriteDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
