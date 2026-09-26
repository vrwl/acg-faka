/* ============================================================
   次元留声机 · 液态玻璃音乐播放器
   MusicPlayer — Liquid Glass Edition
   纯原生实现，零依赖。
   形态：mini 圆珠 / dock 胶囊 / panel 面板 / immersive 沉浸
   ============================================================ */
(function () {
    'use strict';

    if (window.__ACG_MP_BOOTED) return;
    window.__ACG_MP_BOOTED = true;

    var CFG = window.__ACG_MP__ || {};
    var T = CFG.i18n || {};
    var STATE_KEY = 'acgmp:state:v1';
    var PALETTE_KEY = 'acgmp:palette:v1';
    var EXTRA_KEY = 'acgmp:extra:v1';
    var DEFAULT_PALETTE = [[138, 116, 249], [84, 190, 255]];

    /* ========================================================
       小工具
       ======================================================== */
    function clamp(v, lo, hi) { return v < lo ? lo : (v > hi ? hi : v); }

    function fmtTime(s) {
        if (!isFinite(s) || s < 0) s = 0;
        s = Math.floor(s);
        return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function throttle(fn, ms) {
        var last = 0, timer = null;
        return function () {
            var now = Date.now(), args = arguments, self = this;
            if (now - last >= ms) {
                last = now;
                fn.apply(self, args);
            } else if (!timer) {
                timer = setTimeout(function () {
                    timer = null;
                    last = Date.now();
                    fn.apply(self, args);
                }, ms - (now - last));
            }
        };
    }

    function storage(get, key, val) {
        try {
            if (get) {
                var raw = localStorage.getItem(key);
                return raw ? JSON.parse(raw) : null;
            }
            localStorage.setItem(key, JSON.stringify(val));
        } catch (e) { return null; }
    }

    /* ========================================================
       图标（原创绘制，stroke 统一 1.8 / 圆角端点）
       ======================================================== */
    function icon(name, extra) {
        var stroke = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
        var body = {
            play: '<path fill="currentColor" d="M8.2 5.9v12.2c0 1.26 1.38 2.03 2.45 1.37l9.86-6.1a1.6 1.6 0 0 0 0-2.73l-9.86-6.1A1.6 1.6 0 0 0 8.2 5.9z"/>',
            pause: '<rect fill="currentColor" x="6.3" y="5" width="4" height="14" rx="1.5"/><rect fill="currentColor" x="13.7" y="5" width="4" height="14" rx="1.5"/>',
            prev: '<path fill="currentColor" d="M7.5 5.6a1.1 1.1 0 0 0-2.2 0v12.8a1.1 1.1 0 0 0 2.2 0zM18.7 5.9a1.5 1.5 0 0 0-2.33-1.25l-8.1 5.35a1.5 1.5 0 0 0 0 2.5l8.1 5.35a1.5 1.5 0 0 0 2.33-1.25z"/>',
            next: '<path fill="currentColor" d="M16.5 5.6a1.1 1.1 0 0 1 2.2 0v12.8a1.1 1.1 0 0 1-2.2 0zM5.3 5.9a1.5 1.5 0 0 1 2.33-1.25l8.1 5.35a1.5 1.5 0 0 1 0 2.5l-8.1 5.35A1.5 1.5 0 0 1 5.3 18.1z"/>',
            list: '<g ' + stroke + '><path d="M3.5 7.8c2 0 2.6-.8 3.6-2.1 1.2-1.6 2.5-1.6 3.4-.3 1.4 2 3.6 5.3 4.9 7.2.9 1.3 2.1 1.3 3.1 0"/><path d="m17.2 9.9 3.3-.6.6 3.3"/><path d="M3.5 16.2c2 0 2.6.8 3.6 2.1"/><path d="M20.5 16.4c-1 1.3-2.2 1.3-3.1 0l-1.5-2.2"/><path d="m17.5 17.7 3.1 1.1 1.1-3.1"/></g>',
            single: '<g ' + stroke + '><path d="M20 12.6V11a5.6 5.6 0 0 0-5.6-5.6H4.9"/><path d="m7.6 2.7-2.7 2.7 2.7 2.7"/><path d="M4 11.4V13a5.6 5.6 0 0 0 5.6 5.6h9.5"/><path d="m16.4 21.3 2.7-2.7-2.7-2.7"/><path d="M11.4 10.2 12.7 9.4v5.3"/></g>',
            random: '<g ' + stroke + '><path d="M20 12.6V11a5.6 5.6 0 0 0-5.6-5.6H4.9"/><path d="m7.6 2.7-2.7 2.7 2.7 2.7"/><path d="M4 11.4V13a5.6 5.6 0 0 0 5.6 5.6h9.5"/><path d="m16.4 21.3 2.7-2.7-2.7-2.7"/></g>',
            volHigh: '<g ' + stroke + '><path fill="currentColor" stroke="none" d="M11.3 4.6 7.2 7.9H4.6a1.2 1.2 0 0 0-1.2 1.2v5.8a1.2 1.2 0 0 0 1.2 1.2h2.6l4.1 3.3c.8.64 1.98.07 1.98-.94V5.54c0-1.01-1.18-1.58-1.98-.94z"/><path d="M16.2 9.3a4.4 4.4 0 0 1 0 5.4"/><path d="M18.8 6.8a8.1 8.1 0 0 1 0 10.4"/></g>',
            volLow: '<g ' + stroke + '><path fill="currentColor" stroke="none" d="M11.3 4.6 7.2 7.9H4.6a1.2 1.2 0 0 0-1.2 1.2v5.8a1.2 1.2 0 0 0 1.2 1.2h2.6l4.1 3.3c.8.64 1.98.07 1.98-.94V5.54c0-1.01-1.18-1.58-1.98-.94z"/><path d="M16.2 9.3a4.4 4.4 0 0 1 0 5.4"/></g>',
            volMute: '<g ' + stroke + '><path fill="currentColor" stroke="none" d="M11.3 4.6 7.2 7.9H4.6a1.2 1.2 0 0 0-1.2 1.2v5.8a1.2 1.2 0 0 0 1.2 1.2h2.6l4.1 3.3c.8.64 1.98.07 1.98-.94V5.54c0-1.01-1.18-1.58-1.98-.94z"/><path d="m15.8 9.6 4.8 4.8m0-4.8-4.8 4.8"/></g>',
            up: '<g ' + stroke + '><path d="m6 14.5 6-5.5 6 5.5"/></g>',
            down: '<g ' + stroke + '><path d="m6 9.5 6 5.5 6-5.5"/></g>',
            minus: '<g ' + stroke + '><path d="M6.5 12h11"/></g>',
            close: '<g ' + stroke + '><path d="m6.5 6.5 11 11m0-11-11 11"/></g>',
            full: '<g ' + stroke + '><path d="M9 3.8H5.6A1.8 1.8 0 0 0 3.8 5.6V9"/><path d="M15 3.8h3.4a1.8 1.8 0 0 1 1.8 1.8V9"/><path d="M9 20.2H5.6a1.8 1.8 0 0 1-1.8-1.8V15"/><path d="M15 20.2h3.4a1.8 1.8 0 0 0 1.8-1.8V15"/></g>',
            search: '<g ' + stroke + '><circle cx="11" cy="11" r="6.2"/><path d="m19.5 19.5-3.6-3.6"/></g>',
            fx: '<g fill="currentColor"><path d="M12 3.4c.32 0 .6.21.68.52l.72 2.8a4.6 4.6 0 0 0 3.28 3.28l2.8.72a.7.7 0 0 1 0 1.36l-2.8.72a4.6 4.6 0 0 0-3.28 3.28l-.72 2.8a.7.7 0 0 1-1.36 0l-.72-2.8a4.6 4.6 0 0 0-3.28-3.28l-2.8-.72a.7.7 0 0 1 0-1.36l2.8-.72a4.6 4.6 0 0 0 3.28-3.28l.72-2.8a.7.7 0 0 1 .68-.52z"/><circle cx="18.8" cy="5.2" r="1.6"/><circle cx="5.4" cy="18.6" r="1.3"/></g>',
            orb: '<g ' + stroke + '><circle cx="12" cy="12" r="7.4"/><circle fill="currentColor" stroke="none" cx="12" cy="12" r="2"/></g>',
            net: '<g ' + stroke + '><circle cx="12" cy="12" r="8.2"/><path d="M3.8 12h16.4"/><path d="M12 3.8c2.5 2.3 3.7 5 3.7 8.2s-1.2 5.9-3.7 8.2c-2.5-2.3-3.7-5-3.7-8.2s1.2-5.9 3.7-8.2z"/></g>',
            plus: '<g ' + stroke + '><path d="M12 6.5v11M6.5 12h11"/></g>'
        }[name] || '';
        return '<svg viewBox="0 0 24 24" aria-hidden="true"' + (extra ? ' class="' + extra + '"' : '') + '>' + body + '</svg>';
    }

    /* 默认封面：氛围渐变 + 音符 */
    var FALLBACK_COVER = 'data:image/svg+xml,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><defs>' +
        '<linearGradient id="g" x1="0" y1="0" x2="1" y2="1">' +
        '<stop offset="0" stop-color="#8a74f9"/><stop offset="1" stop-color="#54beff"/></linearGradient></defs>' +
        '<rect width="200" height="200" fill="url(#g)"/>' +
        '<path fill="rgba(255,255,255,.92)" d="M128 52a6 6 0 0 0-7.3-5.9l-38 8.5A6 6 0 0 0 78 60.5v56.8a20.6 20.6 0 0 0-8-1.6c-9.9 0-18 6.7-18 15s8.1 15 18 15 18-6.7 18-15V78.9l32-7.1v34.5a20.6 20.6 0 0 0-8-1.6c-9.9 0-18 6.7-18 15s8.1 15 18 15 18-6.7 18-15z"/></svg>'
    );

    /* ========================================================
       持久化状态
       ======================================================== */
    var Store = {
        data: storage(true, STATE_KEY) || {},
        save: function (patch) {
            for (var k in patch) this.data[k] = patch[k];
            storage(false, STATE_KEY, this.data);
        }
    };

    /* ========================================================
       封面取色 → 氛围色
       ======================================================== */
    var Palette = {
        cache: storage(true, PALETTE_KEY) || {},
        current: DEFAULT_PALETTE,

        pick: function (track, done) {
            var self = this;
            var url = track.pic ? track.pic + '&proxy=1' : (track.cover || '');
            if (!url) return done(DEFAULT_PALETTE);

            if (this.cache[url]) return done(this.cache[url]);

            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () {
                var colors = null;
                try { colors = self.extract(img); } catch (e) { /* canvas 被污染等 */ }
                if (colors) {
                    var keys = Object.keys(self.cache);
                    if (keys.length > 90) self.cache = {};
                    self.cache[url] = colors;
                    storage(false, PALETTE_KEY, self.cache);
                }
                done(colors || DEFAULT_PALETTE);
            };
            img.onerror = function () { done(DEFAULT_PALETTE); };
            img.src = url;
        },

        /* 24×24 采样，饱和度×明度加分挑主色，再取一枚远色相的辅色 */
        extract: function (img) {
            var N = 24;
            var canvas = document.createElement('canvas');
            canvas.width = canvas.height = N;
            var ctx = canvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(img, 0, 0, N, N);
            var px = ctx.getImageData(0, 0, N, N).data;

            var best = [], i, r, g, b;
            for (i = 0; i < px.length; i += 4) {
                if (px[i + 3] < 120) continue;
                r = px[i] / 255; g = px[i + 1] / 255; b = px[i + 2] / 255;
                var max = Math.max(r, g, b), min = Math.min(r, g, b);
                var v = max, s = max === 0 ? 0 : (max - min) / max;
                if (v < 0.12 || v > 0.97 && s < 0.08) continue;
                var h = 0, d = max - min;
                if (d > 0) {
                    if (max === r) h = ((g - b) / d + 6) % 6;
                    else if (max === g) h = (b - r) / d + 2;
                    else h = (r - g) / d + 4;
                    h *= 60;
                }
                best.push([s * 1.25 + (1 - Math.abs(v - 0.62)), h, s, v]);
            }
            if (!best.length) return null;
            best.sort(function (a, b) { return b[0] - a[0]; });

            var main = best[0], second = null;
            for (i = 1; i < best.length; i++) {
                var dh = Math.abs(best[i][1] - main[1]);
                if (Math.min(dh, 360 - dh) > 45) { second = best[i]; break; }
            }
            if (!second) second = [0, (main[1] + 42) % 360, Math.max(main[2], 0.5), main[3]];

            return [this.tune(main), this.tune(second)];
        },

        /* 锁定亮度/饱和范围，保证在明暗玻璃上都亮眼 */
        tune: function (hsv) {
            var h = hsv[1], s = clamp(hsv[2], 0.42, 0.88), l = clamp(hsv[3] * (1 - hsv[2] / 2), 0.5, 0.68);
            var c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = l - c / 2;
            var rgb = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x]
                : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];
            return [Math.round((rgb[0] + m) * 255), Math.round((rgb[1] + m) * 255), Math.round((rgb[2] + m) * 255)];
        },

        /* 900ms 缓动过渡到新氛围色 */
        apply: function (target, roots) {
            var from = this.current.map(function (c) { return c.slice(); });
            var start = performance.now(), self = this;
            function frame(now) {
                var p = clamp((now - start) / 900, 0, 1);
                var e = 1 - Math.pow(1 - p, 3);
                var mix = from.map(function (f, i) {
                    return f.map(function (c, j) { return Math.round(c + (target[i][j] - c) * e); });
                });
                roots.forEach(function (el) {
                    el.style.setProperty('--mp-a1', mix[0].join(' '));
                    el.style.setProperty('--mp-a2', mix[1].join(' '));
                });
                if (p < 1) requestAnimationFrame(frame);
                else self.current = target;
            }
            requestAnimationFrame(frame);
        }
    };

    /* ========================================================
       歌词
       ======================================================== */
    var Lyrics = {
        cache: {},

        load: function (track, idx, done) {
            var self = this;
            if (!CFG.lyric || !track.lrc) return done(null);
            if (this.cache[idx]) return done(this.cache[idx]);
            fetch(track.lrc).then(function (r) { return r.ok ? r.text() : ''; }).then(function (text) {
                var lines = self.parse(text);
                self.cache[idx] = lines;
                done(lines);
            }).catch(function () { done(null); });
        },

        parse: function (text) {
            if (!text) return [];
            var lines = [], stamp = /\[(\d{1,2}):(\d{1,2}(?:[.:]\d{1,3})?)\]/g;
            text.split(/\r\n|\r|\n/).forEach(function (row) {
                var m, times = [];
                stamp.lastIndex = 0;
                while ((m = stamp.exec(row))) times.push(m[1] * 60 + parseFloat(m[2].replace(':', '.')));
                if (!times.length) return;
                var body = row.replace(/\[[^\]]*\]/g, '').trim();
                if (!body) return;
                var parts = body.split('\t');
                times.forEach(function (t) { lines.push({ t: t, txt: parts[0], tr: parts[1] || '' }); });
            });
            lines.sort(function (a, b) { return a.t - b.t; });
            return lines;
        },

        indexAt: function (lines, time) {
            var lo = 0, hi = lines.length - 1, ans = -1;
            while (lo <= hi) {
                var mid = (lo + hi) >> 1;
                if (lines[mid].t <= time + 0.25) { ans = mid; lo = mid + 1; }
                else hi = mid - 1;
            }
            return ans;
        }
    };

    /* ========================================================
       播放引擎
       ======================================================== */
    var Engine = {
        audio: null,
        tracks: [],
        index: 0,
        mode: 'list',
        history: [],
        bag: [],
        failStreak: 0,
        pendingSeek: 0,
        onTrackChange: null,
        onPlayState: null,

        init: function (tracks) {
            var self = this;
            this.tracks = tracks;
            this.audio = document.createElement('audio');
            this.audio.preload = 'metadata';
            this.audio.setAttribute('playsinline', '');

            this.audio.addEventListener('loadedmetadata', function () {
                if (self.pendingSeek > 0 && self.pendingSeek < self.audio.duration - 1) {
                    self.audio.currentTime = self.pendingSeek;
                }
                self.pendingSeek = 0;
            });
            this.audio.addEventListener('playing', function () {
                self.failStreak = 0;
                self.notify();
            });
            this.audio.addEventListener('pause', function () { self.notify(); });
            this.audio.addEventListener('ended', function () { self.next(true); });
            this.audio.addEventListener('error', function () {
                if (!self.audio.src) return;
                self.failStreak++;
                if (self.failStreak >= 6) { self.notify(); return; }
                UI.toast(T.loadFailed || '这首歌暂时无法播放，已自动跳过');
                setTimeout(function () { self.next(true); }, 700);
            });
        },

        current: function () { return this.tracks[this.index]; },

        load: function (idx, seek, thenPlay, noHistory) {
            idx = clamp(idx, 0, this.tracks.length - 1);
            if (!noHistory && this.index !== idx) this.history.push(this.index);
            if (this.history.length > 60) this.history.shift();
            this.index = idx;
            this.pendingSeek = seek || 0;
            this.audio.src = this.tracks[idx].url;
            this.audio.loop = this.mode === 'single';
            if (this.onTrackChange) this.onTrackChange(this.tracks[idx], idx);
            if (thenPlay) this.play();
        },

        play: function () {
            var self = this;
            if (!this.audio.src) return this.load(this.index, 0, true);
            var p = this.audio.play();
            if (p && p.catch) {
                p.then(function () { UI.pending(false); }).catch(function (err) {
                    //只有浏览器手势限制才进入「轻触恢复」；pause() 打断(AbortError)不算
                    if (err && err.name === 'NotAllowedError') UI.pending(true);
                });
            }
        },

        pause: function () { this.audio.pause(); },

        toggle: function () { this.audio.paused ? this.play() : this.pause(); },

        playAt: function (idx) { this.load(idx, 0, true); },

        next: function (auto) {
            if (!this.tracks.length) return;
            if (auto && this.mode === 'single') return; /* loop 属性已处理 */
            var idx;
            if (this.mode === 'random') {
                if (!this.bag.length) this.refillBag();
                idx = this.bag.shift();
                if (idx === undefined) idx = (this.index + 1) % this.tracks.length;
            } else {
                idx = (this.index + 1) % this.tracks.length;
            }
            this.load(idx, 0, true);
        },

        prev: function () {
            if (this.audio.currentTime > 3.5) { this.audio.currentTime = 0; return; }
            var idx = this.history.length ? this.history.pop() : (this.index - 1 + this.tracks.length) % this.tracks.length;
            this.load(idx, 0, true, true);
        },

        refillBag: function () {
            var self = this;
            this.bag = this.tracks.map(function (_, i) { return i; })
                .filter(function (i) { return i !== self.index; });
            for (var i = this.bag.length - 1; i > 0; i--) {
                var j = Math.floor(Math.random() * (i + 1)), t = this.bag[i];
                this.bag[i] = this.bag[j];
                this.bag[j] = t;
            }
        },

        cycleMode: function () {
            this.mode = { list: 'single', single: 'random', random: 'list' }[this.mode];
            this.audio.loop = this.mode === 'single';
            if (this.mode === 'random') this.refillBag();
            Store.save({ m: this.mode });
            return this.mode;
        },

        seekTo: function (t) {
            if (isFinite(this.audio.duration)) this.audio.currentTime = clamp(t, 0, this.audio.duration - 0.2);
        },

        setVolume: function (v) {
            this.audio.volume = clamp(v, 0, 1);
            if (v > 0) this.audio.muted = false;
            Store.save({ v: this.audio.volume });
        },

        duration: function () {
            var d = this.audio.duration;
            if (isFinite(d) && d > 0) return d;
            var t = this.current();
            return t && t.duration ? t.duration : 0;
        },

        notify: function () { if (this.onPlayState) this.onPlayState(!this.audio.paused); }
    };

    /* ========================================================
       UI
       ======================================================== */
    var UI = {
        root: null,
        imm: null,
        els: {},
        lyricLines: null,
        lyricIdx: -1,
        freeScroll: 0,
        freeOffset: 0,
        listLimit: 90,
        filterKey: '',
        netResults: null,
        netBusyKey: '',
        toastTimer: 0,
        isPending: false,

        build: function () {
            var root = document.createElement('div');
            root.id = 'acg-music-player';
            root.className = 'mp-root';
            root.dataset.pos = CFG.position === 'right' ? 'right' : 'left';
            /* 手机端默认迷你圆珠，免得挡住菜单和商品；访客自己的选择优先 */
            var isMobile = window.matchMedia('(max-width: 640px)').matches;
            root.dataset.ui = Store.data.ui || (isMobile ? 'mini' : (CFG.startState || 'dock'));

            root.innerHTML =
                /* —— mini 圆珠 —— */
                '<div class="mp-mini mp-glass" role="button" aria-label="' + esc(T.expand) + '">' +
                    '<div class="mp-mini-cover"><img class="mp-spin" alt=""></div>' +
                    '<div class="mp-mini-ring"></div>' +
                    '<div class="mp-mini-act">' + icon('pause') + '</div>' +
                '</div>' +

                /* —— dock 胶囊 —— */
                '<div class="mp-dock mp-glass">' +
                    '<button class="mp-dock-min" data-act="minimize" title="' + esc(T.minimize) + '">' + icon('minus') + '</button>' +
                    '<div class="mp-dock-cover" data-act="expand"><img class="mp-spin" alt=""></div>' +
                    '<div class="mp-dock-meta" data-act="expand">' +
                        '<div class="mp-dock-title"><span class="mp-marquee-inner"></span></div>' +
                        '<div class="mp-dock-sub"></div>' +
                    '</div>' +
                    '<div class="mp-dock-ctrl">' +
                        '<button class="mp-btn mp-btn-play" data-act="toggle" title="' + esc(T.play) + '">' + icon('play') + '</button>' +
                        '<button class="mp-btn" data-act="next" title="' + esc(T.next) + '">' + icon('next') + '</button>' +
                        '<button class="mp-btn" data-act="expand" title="' + esc(T.expand) + '">' + icon('up') + '</button>' +
                    '</div>' +
                    '<div class="mp-dock-seek"><div class="mp-dock-seek-track"><div class="mp-dock-seek-fill"></div></div></div>' +
                '</div>' +

                /* —— panel 面板 —— */
                '<div class="mp-panel mp-glass">' +
                    '<div class="mp-glow mp-glow-1"></div><div class="mp-glow mp-glow-2"></div>' +
                    '<div class="mp-head">' +
                        '<div class="mp-head-cover"><img class="mp-cover-main" alt=""></div>' +
                        '<div class="mp-head-meta">' +
                            '<div class="mp-head-title"><span class="mp-marquee-inner"></span></div>' +
                            '<div class="mp-head-artist"></div>' +
                            '<div class="mp-head-acts">' +
                                (CFG.stage && CFG.lyric
                                    ? '<button class="mp-btn' + (Store.data.fx !== false ? ' is-fx-on' : '') + '" data-act="fx" title="' + esc(T.fx) + '">' + icon('fx') + '</button>'
                                    : '') +
                                '<button class="mp-btn" data-act="immersive" title="' + esc(T.immersive) + '">' + icon('full') + '</button>' +
                                '<button class="mp-btn" data-act="minimize" title="' + esc(T.minimize) + '">' + icon('orb') + '</button>' +
                                '<button class="mp-btn" data-act="collapse" title="' + esc(T.collapse) + '">' + icon('down') + '</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    this.seekHtml('main') +
                    '<div class="mp-times"><span data-time="cur">0:00</span><span data-time="dur">0:00</span></div>' +
                    '<div class="mp-ctrl">' +
                        '<button class="mp-btn mp-btn-mode" data-act="mode"></button>' +
                        '<button class="mp-btn" data-act="prev" title="' + esc(T.prev) + '">' + icon('prev') + '</button>' +
                        '<button class="mp-btn mp-btn-play mp-ctrl-play" data-act="toggle">' + icon('play') + '</button>' +
                        '<button class="mp-btn" data-act="next" title="' + esc(T.next) + '">' + icon('next') + '</button>' +
                        '<div class="mp-vol">' +
                            '<button class="mp-btn" data-act="vol" title="' + esc(T.volume) + '">' + icon('volHigh') + '</button>' +
                            '<div class="mp-vol-pop mp-glass">' +
                                '<div class="mp-vol-slider"><div class="mp-vol-fill"></div></div>' +
                                '<div class="mp-vol-num">70</div>' +
                                '<button class="mp-btn mp-vol-mute" data-act="mute">' + icon('volHigh') + '</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="mp-tabs">' +
                        '<button class="mp-tab" data-tab="lyric">' + esc(T.lyrics) + '</button>' +
                        '<button class="mp-tab" data-tab="list">' + esc(T.playlist) + '<span class="mp-tab-badge"></span></button>' +
                    '</div>' +
                    '<div class="mp-view">' +
                        '<div class="mp-pane" data-pane="lyric"><div class="mp-lyrics"><div class="mp-lyr-scroll"></div></div></div>' +
                        '<div class="mp-pane" data-pane="list"><div class="mp-list">' +
                            '<div class="mp-search">' + icon('search') + '<input type="text" enterkeyhint="search" placeholder="' + esc(T.searchTracks) + '"></div>' +
                            '<div class="mp-rows"></div>' +
                        '</div></div>' +
                    '</div>' +
                '</div>' +

                /* —— immersive 沉浸 —— */
                '<div class="mp-imm">' +
                    '<div class="mp-imm-bg"></div><div class="mp-imm-veil"></div>' +
                    '<div class="mp-imm-blob mp-imm-blob-1"></div><div class="mp-imm-blob mp-imm-blob-2"></div>' +
                    '<div class="mp-imm-grain"></div>' +
                    '<button class="mp-imm-close" title="' + esc(T.exitImmersive) + '">' + icon('close') + '</button>' +
                    '<div class="mp-imm-stage">' +
                        '<div class="mp-imm-left">' +
                            '<div class="mp-imm-cover"><img alt=""></div>' +
                            '<div class="mp-imm-meta"><div class="mp-imm-title"></div><div class="mp-imm-artist"></div></div>' +
                        '</div>' +
                        '<div class="mp-imm-right"><div class="mp-lyr-scroll"></div></div>' +
                    '</div>' +
                    '<div class="mp-imm-ctrl mp-glass">' +
                        this.seekHtml('imm') +
                        '<div class="mp-imm-btns">' +
                            '<button class="mp-btn mp-btn-mode" data-act="mode"></button>' +
                            '<button class="mp-btn" data-act="prev">' + icon('prev') + '</button>' +
                            '<button class="mp-btn mp-btn-play mp-ctrl-play" data-act="toggle">' + icon('play') + '</button>' +
                            '<button class="mp-btn" data-act="next">' + icon('next') + '</button>' +
                            '<button class="mp-btn" data-act="mute">' + icon('volHigh') + '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +

                '<div class="mp-toast mp-glass"></div>';

            root.appendChild(Engine.audio);
            document.body.appendChild(root);
            this.root = root;
            this.imm = root.querySelector('.mp-imm');

            var q = function (s) { return root.querySelector(s); };
            var qa = function (s) { return Array.prototype.slice.call(root.querySelectorAll(s)); };
            this.els = {
                mini: q('.mp-mini'),
                miniRing: q('.mp-mini-ring'),
                miniAct: q('.mp-mini-act'),
                dock: q('.mp-dock'),
                dockTitle: q('.mp-dock-title'),
                dockSub: q('.mp-dock-sub'),
                dockFill: q('.mp-dock-seek-fill'),
                dockSeek: q('.mp-dock-seek'),
                panel: q('.mp-panel'),
                headCover: q('.mp-head-cover'),
                headTitle: q('.mp-head-title'),
                headArtist: q('.mp-head-artist'),
                covers: qa('.mp-mini-cover img, .mp-dock-cover img, .mp-head-cover img, .mp-imm-cover img'),
                playBtns: qa('[data-act="toggle"]'),
                modeBtns: qa('[data-act="mode"]'),
                muteBtns: qa('[data-act="mute"]'),
                volBtn: q('[data-act="vol"]'),
                vol: q('.mp-vol'),
                volFill: q('.mp-vol-fill'),
                volNum: q('.mp-vol-num'),
                volSlider: q('.mp-vol-slider'),
                timeCur: q('[data-time="cur"]'),
                timeDur: q('[data-time="dur"]'),
                tabs: qa('.mp-tab'),
                tabBadge: q('.mp-tab-badge'),
                panes: qa('.mp-pane'),
                lyricsBox: q('.mp-lyrics'),
                lyrScroll: q('.mp-lyrics .mp-lyr-scroll'),
                rows: q('.mp-rows'),
                search: q('.mp-search input'),
                immBg: q('.mp-imm-bg'),
                immTitle: q('.mp-imm-title'),
                immArtist: q('.mp-imm-artist'),
                immCover: q('.mp-imm-cover'),
                immRight: q('.mp-imm-right'),
                immLyr: q('.mp-imm-right .mp-lyr-scroll'),
                immStage: q('.mp-imm-stage'),
                toastEl: q('.mp-toast'),
                seeks: { main: this.seekEls(q('.mp-panel .mp-seek')), imm: this.seekEls(q('.mp-imm .mp-seek')) }
            };

            this.applyTint();
            this.setTab(Store.data.tab === 'list' ? 'list' : 'lyric');
            this.bind();
            Stage.build(root);

            /* 恢复访客拖动过的停靠位置 */
            if (typeof Store.data.px === 'number' && typeof Store.data.py === 'number') {
                this.moveTo(Store.data.px * window.innerWidth, Store.data.py * window.innerHeight);
            }
        },

        /* —— 自由拖动 —— */
        moveTo: function (x, y) {
            var rect = this.root.getBoundingClientRect();
            x = clamp(x, 6, Math.max(6, window.innerWidth - rect.width - 6));
            y = clamp(y, 6, Math.max(6, window.innerHeight - rect.height - 6));
            this.root.style.left = x + 'px';
            this.root.style.top = y + 'px';
            this.root.style.right = 'auto';
            this.root.style.bottom = 'auto';
        },

        savePos: function () {
            var rect = this.root.getBoundingClientRect();
            Store.save({ px: rect.left / window.innerWidth, py: rect.top / window.innerHeight });
        },

        /* 形态切换/转屏后把整体收回视口内（仅在拖动过之后生效） */
        ensureInView: function () {
            if (!this.root.style.left) return;
            var rect = this.root.getBoundingClientRect();
            this.moveTo(rect.left, rect.top);
        },

        seekHtml: function (key) {
            return '<div class="mp-seek" data-seek="' + key + '">' +
                '<div class="mp-seek-track">' +
                    '<div class="mp-seek-buffer"></div>' +
                    '<div class="mp-seek-fill"></div>' +
                    '<div class="mp-seek-thumb"></div>' +
                '</div>' +
                '<div class="mp-seek-bubble">0:00</div>' +
            '</div>';
        },

        seekEls: function (seek) {
            return {
                box: seek,
                buffer: seek.querySelector('.mp-seek-buffer'),
                fill: seek.querySelector('.mp-seek-fill'),
                thumb: seek.querySelector('.mp-seek-thumb'),
                bubble: seek.querySelector('.mp-seek-bubble')
            };
        },

        /* —— 玻璃色调 —— */
        applyTint: function () {
            var tint = CFG.tint;
            if (tint !== 'dark' && tint !== 'light') {
                tint = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            }
            this.root.dataset.tint = tint;
        },

        /* —— 事件绑定 —— */
        bind: function () {
            var self = this, els = this.els;

            /* 动作分发 */
            this.root.addEventListener('click', function (e) {
                var target = e.target.closest('[data-act]');
                if (!target || !self.root.contains(target)) return;
                e.stopPropagation();
                var act = target.dataset.act;
                if (act === 'toggle') Engine.toggle();
                else if (act === 'next') Engine.next(false);
                else if (act === 'prev') Engine.prev();
                else if (act === 'expand') self.setUI('panel');
                else if (act === 'collapse') self.setUI('dock');
                else if (act === 'minimize') self.setUI('mini');
                else if (act === 'immersive') self.openImm();
                else if (act === 'mode') self.renderMode(Engine.cycleMode(), true);
                else if (act === 'fx') {
                    var fxOn = Stage.toggle();
                    target.classList.toggle('is-fx-on', fxOn);
                    self.toast(fxOn ? T.fxOn : T.fxOff);
                }
                else if (act === 'vol') els.vol.classList.toggle('is-open');
                else if (act === 'mute') {
                    Engine.audio.muted = !Engine.audio.muted;
                    self.renderVolume();
                }
            });

            els.mini.addEventListener('click', function (e) {
                if (self.dragMoved) return; /* 刚拖完，不当作点击 */
                if (e.target.closest('.mp-mini-act')) { Engine.toggle(); return; }
                self.setUI('dock');
            });

            /* 圆珠拖动：位移超过阈值才算拖，尾随点击抑制 */
            (function () {
                var pid = null, dragging = false, startX = 0, startY = 0, origX = 0, origY = 0;
                els.mini.addEventListener('pointerdown', function (e) {
                    if (e.button) return;
                    var rect = self.root.getBoundingClientRect();
                    pid = e.pointerId;
                    dragging = false;
                    startX = e.clientX;
                    startY = e.clientY;
                    origX = rect.left;
                    origY = rect.top;
                    els.mini.setPointerCapture && els.mini.setPointerCapture(pid);
                });
                els.mini.addEventListener('pointermove', function (e) {
                    if (pid === null || e.pointerId !== pid) return;
                    var dx = e.clientX - startX, dy = e.clientY - startY;
                    if (!dragging && dx * dx + dy * dy > 49) {
                        dragging = true;
                        self.root.classList.add('is-drag-move');
                    }
                    if (dragging) self.moveTo(origX + dx, origY + dy);
                });
                function release(e) {
                    if (pid === null || e.pointerId !== pid) return;
                    pid = null;
                    if (!dragging) return;
                    dragging = false;
                    self.root.classList.remove('is-drag-move');
                    self.savePos();
                    self.dragMoved = true;
                    setTimeout(function () { self.dragMoved = false; }, 260);
                }
                els.mini.addEventListener('pointerup', release);
                els.mini.addEventListener('pointercancel', release);
            })();

            this.imm.querySelector('.mp-imm-close').addEventListener('click', function () { self.closeImm(); });

            /* 点击面板外收起 */
            document.addEventListener('pointerdown', function (e) {
                if (self.root.dataset.ui === 'panel' && !self.imm.classList.contains('is-open') && !self.root.contains(e.target)) {
                    self.setUI('dock');
                }
                if (els.vol.classList.contains('is-open') && !els.vol.contains(e.target)) {
                    els.vol.classList.remove('is-open');
                }
            });

            /* 标签页 */
            els.tabs.forEach(function (tab) {
                tab.addEventListener('click', function () { self.setTab(tab.dataset.tab); });
            });

            /* 搜索：即输即筛本地，回车搜全网 */
            els.search.addEventListener('input', throttle(function () {
                self.filterKey = els.search.value.trim().toLowerCase();
                self.listLimit = 90;
                self.netResults = null;
                self.renderList();
            }, 180));
            els.search.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && self.filterKey) {
                    e.preventDefault();
                    self.netSearch(els.search.value.trim());
                }
            });

            /* 列表：本地行播放 / 全网搜索行 / 网络结果入列播放 + 触底加载更多 */
            els.rows.addEventListener('click', function (e) {
                if (e.target.closest('[data-netgo]')) {
                    self.netSearch(els.search.value.trim());
                    return;
                }
                var net = e.target.closest('[data-net]');
                if (net && Array.isArray(self.netResults)) {
                    self.playNet(self.netResults[+net.dataset.net]);
                    return;
                }
                var row = e.target.closest('.mp-row');
                if (row && row.dataset.i !== undefined) Engine.playAt(+row.dataset.i);
            });
            els.rows.addEventListener('scroll', function () {
                if (els.rows.scrollTop + els.rows.clientHeight > els.rows.scrollHeight - 120) {
                    var total = self.filtered().length;
                    if (self.listLimit < total) {
                        self.listLimit += 90;
                        self.renderList(true);
                    }
                }
            });

            /* 进度条 */
            this.bindSeek(els.seeks.main);
            this.bindSeek(els.seeks.imm);
            this.bindSeek({ box: els.dockSeek, fill: els.dockFill }, true);

            /* 音量 */
            this.bindVolume();

            /* 歌词自由滚动 */
            this.bindLyricScroll(els.lyricsBox, false);
            this.bindLyricScroll(els.immRight, true);

            /* 歌词点击定位 */
            [els.lyrScroll, els.immLyr].forEach(function (box) {
                box.addEventListener('click', function (e) {
                    var line = e.target.closest('.mp-lyr-line');
                    if (line && self.lyricLines) {
                        Engine.seekTo(self.lyricLines[+line.dataset.i].t);
                        self.freeScroll = 0;
                        if (Engine.audio.paused) Engine.play();
                    }
                });
            });

            /* 指针流光 */
            this.root.addEventListener('pointermove', function (e) {
                var glass = e.target.closest('.mp-glass');
                if (!glass) return;
                var rect = glass.getBoundingClientRect();
                glass.style.setProperty('--mp-mx', ((e.clientX - rect.left) / rect.width * 100).toFixed(1) + '%');
                glass.style.setProperty('--mp-my', ((e.clientY - rect.top) / rect.height * 100).toFixed(1) + '%');
            });

            /* 沉浸封面视差 */
            this.els.immStage.addEventListener('pointermove', function (e) {
                if (e.pointerType === 'touch') return;
                var rect = self.els.immCover.getBoundingClientRect();
                var dx = (e.clientX - rect.left - rect.width / 2) / rect.width;
                var dy = (e.clientY - rect.top - rect.height / 2) / rect.height;
                self.els.immCover.style.setProperty('--mp-ty', clamp(dx * 7, -6, 6).toFixed(2) + 'deg');
                self.els.immCover.style.setProperty('--mp-tx', clamp(-dy * 7, -6, 6).toFixed(2) + 'deg');
            });
            this.els.immStage.addEventListener('pointerleave', function () {
                self.els.immCover.style.setProperty('--mp-tx', '0deg');
                self.els.immCover.style.setProperty('--mp-ty', '0deg');
            });

            /* 键盘（面板/沉浸打开时） */
            document.addEventListener('keydown', function (e) {
                var tag = (e.target.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea' || e.target.isContentEditable) return;
                var immOpen = self.imm.classList.contains('is-open');
                var panelOpen = self.root.dataset.ui === 'panel';
                if (!immOpen && !panelOpen) return;

                if (e.code === 'Space') { e.preventDefault(); Engine.toggle(); }
                else if (e.key === 'ArrowLeft') { e.preventDefault(); Engine.seekTo(Engine.audio.currentTime - 5); }
                else if (e.key === 'ArrowRight') { e.preventDefault(); Engine.seekTo(Engine.audio.currentTime + 5); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); Engine.setVolume(Engine.audio.volume + 0.05); self.renderVolume(); }
                else if (e.key === 'ArrowDown') { e.preventDefault(); Engine.setVolume(Engine.audio.volume - 0.05); self.renderVolume(); }
                else if (e.key === 'Escape') { immOpen ? self.closeImm() : self.setUI('dock'); }
            });

            /* 站点明暗切换跟随 */
            if (CFG.tint !== 'dark' && CFG.tint !== 'light') {
                new MutationObserver(function () { self.applyTint(); })
                    .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
            }

            /* 窗口尺寸变化重算跑马灯与歌词定位 */
            window.addEventListener('resize', throttle(function () {
                self.marquee(self.els.dockTitle);
                self.marquee(self.els.headTitle);
                self.syncLyric(true);
                self.ensureInView();
            }, 200));

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) self.syncLyric(true);
            });
        },

        /* —— 进度条交互 —— */
        bindSeek: function (seek, mini) {
            var box = seek.box, dragging = false;

            function pct(e) {
                var rect = (mini ? box.firstElementChild : box.querySelector('.mp-seek-track')).getBoundingClientRect();
                return clamp((e.clientX - rect.left) / rect.width, 0, 1);
            }

            box.addEventListener('pointerdown', function (e) {
                var dur = Engine.duration();
                if (!dur) return;
                dragging = true;
                box.classList.add('is-drag');
                box.setPointerCapture && box.setPointerCapture(e.pointerId);
                Engine.seekTo(pct(e) * dur);
            });
            box.addEventListener('pointermove', function (e) {
                var dur = Engine.duration();
                if (!dur) return;
                if (!mini && seek.bubble) {
                    var p = pct(e);
                    seek.bubble.textContent = fmtTime(p * dur);
                    seek.bubble.style.left = (p * 100).toFixed(2) + '%';
                }
                if (dragging) Engine.seekTo(pct(e) * dur);
            });
            ['pointerup', 'pointercancel'].forEach(function (ev) {
                box.addEventListener(ev, function () {
                    dragging = false;
                    box.classList.remove('is-drag');
                });
            });
        },

        /* —— 音量交互 —— */
        bindVolume: function () {
            var self = this, slider = this.els.volSlider, dragging = false;

            function apply(e) {
                var rect = slider.getBoundingClientRect();
                Engine.setVolume(1 - clamp((e.clientY - rect.top) / rect.height, 0, 1));
                self.renderVolume();
            }
            slider.addEventListener('pointerdown', function (e) {
                dragging = true;
                slider.setPointerCapture && slider.setPointerCapture(e.pointerId);
                apply(e);
            });
            slider.addEventListener('pointermove', function (e) { if (dragging) apply(e); });
            ['pointerup', 'pointercancel'].forEach(function (ev) {
                slider.addEventListener(ev, function () { dragging = false; });
            });
            this.els.vol.addEventListener('wheel', function (e) {
                e.preventDefault();
                Engine.setVolume(Engine.audio.volume + (e.deltaY < 0 ? 0.05 : -0.05));
                self.renderVolume();
            }, { passive: false });
        },

        /* —— 歌词自由滚动（滚轮/触摸暂停跟随） —— */
        bindLyricScroll: function (box, isImm) {
            var self = this, startY = 0, startOffset = 0;

            box.addEventListener('wheel', function (e) {
                e.preventDefault();
                self.enterFree();
                self.freeOffset += e.deltaY;
                self.syncLyric(true);
            }, { passive: false });

            box.addEventListener('touchstart', function (e) {
                startY = e.touches[0].clientY;
                startOffset = self.freeOffset;
            }, { passive: true });
            box.addEventListener('touchmove', function (e) {
                self.enterFree();
                self.freeOffset = startOffset + (startY - e.touches[0].clientY);
                self.syncLyric(true);
            }, { passive: true });
        },

        enterFree: function () {
            var self = this;
            this.els.lyricsBox.classList.add('is-free');
            this.els.immRight.classList.add('is-free');
            clearTimeout(this.freeScrollTimer);
            this.freeScroll = 1;
            this.freeScrollTimer = setTimeout(function () {
                self.freeScroll = 0;
                self.freeOffset = 0;
                self.els.lyricsBox.classList.remove('is-free');
                self.els.immRight.classList.remove('is-free');
                self.syncLyric(true);
            }, 2600);
        },

        /* —— 形态切换 —— */
        setUI: function (state) {
            var self = this;
            this.root.dataset.ui = state;
            Store.save({ ui: state === 'panel' ? 'dock' : state });
            requestAnimationFrame(function () {
                if (state === 'panel') {
                    self.syncLyric(true);
                    self.scrollToCurrent();
                    self.marquee(self.els.headTitle);
                } else if (state === 'dock') {
                    self.marquee(self.els.dockTitle);
                }
                self.ensureInView();
            });
        },

        setTab: function (tab) {
            this.els.tabs.forEach(function (t) { t.classList.toggle('is-active', t.dataset.tab === tab); });
            this.els.panes.forEach(function (p) { p.classList.toggle('is-show', p.dataset.pane === tab); });
            Store.save({ tab: tab });
            if (tab === 'list') this.scrollToCurrent();
            else this.syncLyric(true);
        },

        /* —— 沉浸模式 —— */
        openImm: function () {
            var self = this;
            this.imm.classList.add('is-open');
            this.root.classList.add('is-imm');
            document.documentElement.classList.add('mp-lock');
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    self.imm.classList.add('is-in');
                    self.syncLyric(true);
                });
            });
        },

        closeImm: function () {
            var self = this;
            this.imm.classList.remove('is-in');
            this.root.classList.remove('is-imm');
            document.documentElement.classList.remove('mp-lock');
            setTimeout(function () { self.imm.classList.remove('is-open'); }, 460);
        },

        /* —— 待恢复播放状态 —— */
        pending: function (on) {
            var wasOn = this.isPending;
            this.isPending = on;
            this.root.classList.toggle('is-pending', on);
            if (!on && wasOn) {
                var track = Engine.current();
                if (track) {
                    this.els.dockSub.textContent = track.artist;
                    this.els.dockSub.classList.remove('is-lyric');
                }
            }
            if (on) {
                var self = this;
                this.els.dockSub.textContent = T.tapResume || '轻触任意处继续播放';
                this.els.dockSub.classList.add('is-lyric');
                var resume = function () {
                    document.removeEventListener('pointerdown', resume, true);
                    document.removeEventListener('keydown', resume, true);
                    if (self.isPending) Engine.play();
                };
                document.addEventListener('pointerdown', resume, true);
                document.addEventListener('keydown', resume, true);
            }
        },

        /* —— 渲染：曲目切换 —— */
        renderTrack: function (track, idx) {
            var self = this, els = this.els;

            [els.dockTitle, els.headTitle].forEach(function (box) {
                var inner = box.querySelector('.mp-marquee-inner');
                inner.dataset.text = track.name;
                inner.textContent = track.name;
            });
            els.dockSub.textContent = track.artist;
            els.dockSub.classList.remove('is-lyric');
            els.headArtist.textContent = track.artist;
            els.immTitle.textContent = track.name;
            els.immArtist.textContent = track.artist;

            this.marquee(els.dockTitle);
            this.marquee(els.headTitle);

            /* 封面（预热一张，成功后同步到所有位置） */
            var url = track.cover || track.pic || FALLBACK_COVER;
            els.headCover.classList.add('is-loading');
            var warm = new Image();
            warm.onload = function () { self.setCovers(url); };
            warm.onerror = function () { self.setCovers(FALLBACK_COVER); };
            warm.src = url;

            /* 氛围色 */
            Palette.pick(track, function (colors) { Palette.apply(colors, [self.root]); });

            /* 歌词 */
            this.lyricLines = null;
            this.lyricIdx = -1;
            Stage.clear();
            this.renderLyricSkeleton(T.lyricsLoading || '');
            Lyrics.load(track, idx, function (lines) {
                if (Engine.index !== idx) return;
                self.lyricLines = lines && lines.length ? lines : null;
                self.renderLyrics();
            });

            /* 列表高亮 */
            this.markCurrentRow();
            this.renderTimes(0, Engine.duration());

            /* 系统媒体中心 */
            if ('mediaSession' in navigator) {
                try {
                    navigator.mediaSession.metadata = new MediaMetadata({
                        title: track.name,
                        artist: track.artist,
                        artwork: [{ src: url, sizes: '400x400' }]
                    });
                } catch (e) { }
            }
        },

        setCovers: function (url) {
            this.els.covers.forEach(function (img) { img.src = url; });
            this.els.headCover.classList.remove('is-loading');
            this.els.immBg.style.backgroundImage = 'url("' + url.replace(/"/g, '%22') + '")';
        },

        /* —— 渲染：播放状态 —— */
        renderPlay: function (playing) {
            var name = playing ? 'pause' : 'play';
            this.els.playBtns.forEach(function (b) { b.innerHTML = icon(name); });
            this.els.miniAct.innerHTML = icon(name);
            this.root.classList.toggle('is-playing', playing);
            if (playing) this.pending(false);
            if ('mediaSession' in navigator) {
                try { navigator.mediaSession.playbackState = playing ? 'playing' : 'paused'; } catch (e) { }
            }
            Store.save({ p: playing });
        },

        renderMode: function (mode, announce) {
            var label = { list: T.modeList, single: T.modeSingle, random: T.modeRandom }[mode];
            this.els.modeBtns.forEach(function (b) {
                b.innerHTML = icon(mode === 'list' ? 'list' : mode);
                b.title = label || '';
                b.classList.toggle('is-active', mode !== 'list');
            });
            if (announce) this.toast(label);
        },

        renderVolume: function () {
            var audio = Engine.audio;
            var v = audio.muted ? 0 : audio.volume;
            var name = v === 0 ? 'volMute' : (v < 0.5 ? 'volLow' : 'volHigh');
            if (this.els.volBtn) this.els.volBtn.innerHTML = icon(name);
            this.els.muteBtns.forEach(function (b) { b.innerHTML = icon(name); });
            this.els.volFill.style.height = (v * 100).toFixed(0) + '%';
            this.els.volNum.textContent = Math.round(v * 100);
        },

        renderTimes: function (cur, dur) {
            this.els.timeCur.textContent = fmtTime(cur);
            this.els.timeDur.textContent = fmtTime(dur);
        },

        /* —— 渲染：进度（rAF 驱动） —— */
        renderProgress: function () {
            var audio = Engine.audio;
            var dur = Engine.duration();
            var cur = audio.currentTime || 0;
            var p = dur ? clamp(cur / dur, 0, 1) : 0;
            var pctText = (p * 100).toFixed(2) + '%';

            var seeks = this.els.seeks;
            seeks.main.fill.style.width = pctText;
            seeks.main.thumb.style.left = pctText;
            seeks.imm.fill.style.width = pctText;
            seeks.imm.thumb.style.left = pctText;
            this.els.dockFill.style.width = pctText;
            this.els.miniRing.style.setProperty('--mp-p', (p * 100).toFixed(2));

            var buffered = 0;
            try {
                if (audio.buffered.length && dur) buffered = clamp(audio.buffered.end(audio.buffered.length - 1) / dur, 0, 1);
            } catch (e) { }
            seeks.main.buffer.style.width = (buffered * 100).toFixed(1) + '%';
            seeks.imm.buffer.style.width = (buffered * 100).toFixed(1) + '%';

            this.renderTimes(cur, dur);
        },

        /* —— 歌词渲染 —— */
        renderLyricSkeleton: function (text) {
            var hint = '<div class="mp-lyr-hint">' + esc(text || T.noLyrics || '') + '</div>';
            this.els.lyrScroll.innerHTML = hint;
            this.els.immLyr.innerHTML = hint;
            this.els.lyrScroll.style.transform = '';
            this.els.immLyr.style.transform = '';
        },

        renderLyrics: function () {
            if (!this.lyricLines) return this.renderLyricSkeleton(T.noLyrics || '纯音乐，请欣赏');
            var html = this.lyricLines.map(function (line, i) {
                return '<div class="mp-lyr-line" data-i="' + i + '">' + esc(line.txt) +
                    (line.tr ? '<span class="mp-lyr-trans">' + esc(line.tr) + '</span>' : '') + '</div>';
            }).join('');
            this.els.lyrScroll.innerHTML = html;
            this.els.immLyr.innerHTML = html;
            this.lyricIdx = -1;
            this.syncLyric(true);
        },

        syncLyric: function (force) {
            if (!this.lyricLines) return;
            var idx = Lyrics.indexAt(this.lyricLines, Engine.audio.currentTime || 0);
            var changed = idx !== this.lyricIdx;
            if (!changed && !force) return;
            this.lyricIdx = idx;
            if (changed && idx >= 0) {
                Stage.setLine(this.lyricLines[idx], this.lyricLines[idx + 1] || null, Engine.index + ':' + idx);
            }
            this.positionLyric(this.els.lyricsBox, this.els.lyrScroll, idx);
            if (this.imm.classList.contains('is-open')) {
                this.positionLyric(this.els.immRight, this.els.immLyr, idx);
            }
            /* dock 副标题跟随歌词 */
            if (idx >= 0 && !this.isPending) {
                var line = this.lyricLines[idx];
                if (line.txt) {
                    this.els.dockSub.textContent = line.txt;
                    this.els.dockSub.classList.add('is-lyric');
                }
            }
        },

        positionLyric: function (box, scroll, idx) {
            var lines = scroll.children;
            if (!lines.length) return;
            var active = idx >= 0 ? lines[idx] : null;

            for (var i = 0; i < lines.length; i++) {
                var el = lines[i];
                if (!el.classList) continue;
                var d = idx < 0 ? 2 : Math.min(Math.abs(i - idx), 5);
                el.classList.toggle('is-on', i === idx);
                el.style.setProperty('--mp-lb', (d * 0.55).toFixed(2) + 'px');
                el.style.setProperty('--mp-lo', String(Math.max(0.28, 1 - d * 0.16)));
            }

            var anchor = active || lines[0];
            var target = box.clientHeight / 2 - anchor.offsetTop - anchor.offsetHeight / 2;
            if (this.freeScroll) target -= this.freeOffset;
            var span = Math.max(0, scroll.scrollHeight - box.clientHeight / 2);
            scroll.style.transform = 'translateY(' + clamp(target, -span, box.clientHeight / 2) + 'px)';
        },

        /* —— 播放列表渲染 —— */
        filtered: function () {
            var key = this.filterKey;
            var out = [];
            Engine.tracks.forEach(function (t, i) {
                if (!key || (t.name + ' ' + t.artist).toLowerCase().indexOf(key) > -1) out.push(i);
            });
            return out;
        },

        renderList: function (append) {
            var els = this.els;
            els.tabBadge.textContent = Engine.tracks.length;

            /* —— 网络搜索态 —— */
            if (this.netResults !== null) {
                var head = '<div class="mp-list-sect">' + esc(T.netResults) + '</div>';
                if (this.netResults === 'loading') {
                    els.rows.innerHTML = head +
                        '<div class="mp-net-loading"><i></i><i></i><i></i>' + esc(T.netSearching) + '</div>';
                } else if (!this.netResults.length) {
                    els.rows.innerHTML = head + '<div class="mp-empty">' + esc(T.netEmpty) + '</div>';
                } else {
                    els.rows.innerHTML = head + this.netResults.map(function (t, i) {
                        return '<div class="mp-row mp-row-net" data-net="' + i + '">' +
                            '<div class="mp-row-idx">' + icon('net') + '</div>' +
                            '<div class="mp-row-meta">' +
                                '<div class="mp-row-name">' + esc(t.name) + '</div>' +
                                '<div class="mp-row-artist">' + esc(t.artist) + (t.duration ? ' · ' + fmtTime(t.duration) : '') + '</div>' +
                            '</div>' +
                            icon('plus', 'mp-row-plus') +
                        '</div>';
                    }).join('');
                }
                els.rows.scrollTop = 0;
                return;
            }

            /* —— 本地列表（输入中时顶部挂全网搜索入口）—— */
            var action = this.filterKey
                ? '<div class="mp-row mp-row-go" data-netgo="1">' +
                    '<div class="mp-row-idx">' + icon('net') + '</div>' +
                    '<div class="mp-row-meta"><div class="mp-row-name">' +
                        esc(T.netSearch) + '“' + esc(els.search.value.trim()) + '”' +
                    '</div></div>' +
                  '</div>'
                : '';
            var idxs = this.filtered().slice(0, this.listLimit);
            if (!idxs.length) {
                els.rows.innerHTML = action + '<div class="mp-empty">' + esc(T.empty) + '</div>';
                return;
            }
            var html = idxs.map(function (i) {
                var t = Engine.tracks[i];
                return '<div class="mp-row" data-i="' + i + '">' +
                    '<div class="mp-row-idx">' + (i + 1) + '</div>' +
                    '<div class="mp-row-meta">' +
                        '<div class="mp-row-name">' + esc(t.name) + '</div>' +
                        '<div class="mp-row-artist">' + esc(t.artist) + (t.duration ? ' · ' + fmtTime(t.duration) : '') + '</div>' +
                    '</div>' +
                '</div>';
            }).join('');
            els.rows.innerHTML = action + html;
            if (!append) els.rows.scrollTop = 0;
            this.markCurrentRow();
        },

        /* —— 全网搜索 —— */
        netSearch: function (keyword) {
            var self = this;
            if (!keyword || (this.netBusyKey === keyword && this.netResults === 'loading')) return;
            this.netBusyKey = keyword;
            this.netResults = 'loading';
            this.renderList();

            var url = CFG.api + (CFG.api.indexOf('?') > -1 ? '&' : '?') +
                'server=' + encodeURIComponent(CFG.server) +
                '&type=search&id=' + encodeURIComponent(keyword);
            fetch(url).then(function (r) { return r.json(); }).then(function (res) {
                if (self.netBusyKey !== keyword) return;
                /* 内置端点回 {code,data}，外部 Meting API 回裸数组，都兜住 */
                var list = res && res.data !== undefined ? res.data : res;
                if (!Array.isArray(list)) list = [];
                self.netResults = list.filter(function (t) { return t && t.name && t.url; }).slice(0, 30);
                self.renderList();
            }).catch(function () {
                if (self.netBusyKey !== keyword) return;
                self.netResults = [];
                self.renderList();
            });
        },

        /* 网络结果：去重入列（持久化），立即播放并定位 */
        playNet: function (track) {
            if (!track) return;
            var idx = -1;
            for (var i = 0; i < Engine.tracks.length; i++) {
                if (Engine.tracks[i].url === track.url) { idx = i; break; }
            }
            if (idx === -1) {
                Engine.tracks.push(track);
                idx = Engine.tracks.length - 1;
                var extras = storage(true, EXTRA_KEY) || [];
                extras.push(track);
                if (extras.length > 60) extras = extras.slice(-60);
                storage(false, EXTRA_KEY, extras);
                if (Engine.mode === 'random') Engine.refillBag();
                this.toast(T.addedToList);
            }
            this.els.search.value = '';
            this.filterKey = '';
            this.netResults = null;
            this.listLimit = Math.max(90, idx + 30); /* 保证新入列的行被渲染出来可定位 */
            Engine.playAt(idx);
            this.renderList();
            this.scrollToCurrent();
        },

        markCurrentRow: function () {
            var rows = this.els.rows.children;
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                if (!row.dataset) continue;
                var isCur = +row.dataset.i === Engine.index;
                row.classList.toggle('is-cur', isCur);
                var idxBox = row.firstElementChild;
                if (idxBox) {
                    idxBox.innerHTML = isCur ? '<div class="mp-eq"><i></i><i></i><i></i></div>' : (+row.dataset.i + 1);
                }
            }
        },

        /* 只滚列表容器；scrollIntoView 会波及 overflow:hidden 的面板本体 */
        scrollToCurrent: function () {
            var rows = this.els.rows;
            var cur = rows.querySelector('.is-cur');
            if (!cur) return;
            var delta = cur.getBoundingClientRect().top - rows.getBoundingClientRect().top;
            rows.scrollTop += delta - rows.clientHeight / 2 + cur.offsetHeight / 2;
        },

        /* —— 跑马灯 —— */
        marquee: function (box) {
            var inner = box.querySelector('.mp-marquee-inner');
            if (!inner) return;
            var text = inner.dataset.text != null ? inner.dataset.text : inner.textContent;
            box.classList.remove('is-marquee');
            inner.textContent = text;
            if (text && inner.scrollWidth > box.clientWidth + 2) {
                inner.textContent = text + '   ' + text + '   ';
                box.style.setProperty('--mp-mqs', clamp(inner.scrollWidth / 60, 7, 26).toFixed(1) + 's');
                box.classList.add('is-marquee');
            }
        },

        toast: function (message) {
            if (!message) return;
            var el = this.els.toastEl, self = this;
            el.textContent = message;
            el.classList.add('is-show');
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(function () { el.classList.remove('is-show'); }, 2400);
        }
    };

    /* ========================================================
       多标签互斥
       ======================================================== */
    var Mutex = {
        id: Math.random().toString(36).slice(2),
        bc: null,
        init: function () {
            if (typeof BroadcastChannel === 'undefined') return;
            var self = this;
            this.bc = new BroadcastChannel('acgmp');
            this.bc.onmessage = function (e) {
                if (e.data && e.data.t === 'claim' && e.data.id !== self.id && !Engine.audio.paused) {
                    Engine.pause();
                }
            };
        },
        claim: function () {
            if (this.bc) this.bc.postMessage({ t: 'claim', id: this.id });
        }
    };

    /* ========================================================
       舞台特效：巨幕歌词卡拉OK渐染 / 竖排词影 / 氛围光 / 光尘
       整层 pointer-events:none，只做视觉，绝不挡操作
       ======================================================== */
    var Stage = {
        root: null,
        el: null,
        base: null,
        fill: null,
        trans: null,
        line: null,
        sides: null,
        userOn: true,
        lineStart: 0,
        lineEnd: 1,
        lastKey: '',
        flip: false,
        swapTimer: 0,

        build: function (rootEl) {
            if (!CFG.stage || !CFG.lyric) return;
            this.root = rootEl;
            this.userOn = Store.data.fx !== false;

            var dust = '';
            for (var i = 0; i < 14; i++) {
                dust += '<i style="--x:' + (3 + Math.random() * 94).toFixed(1) + '%'
                    + ';--sz:' + (3 + Math.random() * 6).toFixed(1) + 'px'
                    + ';--dur:' + (9 + Math.random() * 10).toFixed(1) + 's'
                    + ';--delay:' + (-Math.random() * 16).toFixed(1) + 's'
                    + ';--o:' + (0.25 + Math.random() * 0.5).toFixed(2)
                    + ';--bl:' + (Math.random() * 2).toFixed(1) + 'px'
                    + ';--drift:' + (Math.random() * 120 - 60).toFixed(0) + 'px'
                    + ';--c:rgb(var(--mp-a' + (i % 2 + 1) + '))"></i>';
            }

            var el = document.createElement('div');
            el.className = 'mp-stage';
            el.setAttribute('aria-hidden', 'true');
            el.innerHTML =
                '<div class="mp-stage-aurora"><i></i><i></i></div>' +
                '<div class="mp-stage-sweep"></div>' +
                '<div class="mp-stage-side mp-stage-side-l"></div>' +
                '<div class="mp-stage-side mp-stage-side-r"></div>' +
                '<div class="mp-stage-dust">' + dust + '</div>' +
                '<div class="mp-stage-line">' +
                    '<div class="mp-stage-stack">' +
                        '<div class="mp-stage-base"></div>' +
                        '<div class="mp-stage-fill"></div>' +
                    '</div>' +
                    '<div class="mp-stage-trans"></div>' +
                '</div>';
            rootEl.insertBefore(el, rootEl.firstChild);

            this.el = el;
            this.base = el.querySelector('.mp-stage-base');
            this.fill = el.querySelector('.mp-stage-fill');
            this.trans = el.querySelector('.mp-stage-trans');
            this.line = el.querySelector('.mp-stage-line');
            this.sides = [el.querySelector('.mp-stage-side-l'), el.querySelector('.mp-stage-side-r')];
            this.refresh();
        },

        active: function () { return !!this.el && this.userOn; },

        refresh: function () {
            if (this.root) this.root.classList.toggle('is-fx', this.active());
        },

        toggle: function () {
            this.userOn = !this.userOn;
            Store.save({ fx: this.userOn });
            this.refresh();
            return this.userOn;
        },

        /* 换行：旧词化影漂走，新词逐字入场，氛围光一次心跳 */
        setLine: function (line, next, key) {
            if (!this.active() || key === this.lastKey || !line.txt) return;
            this.lastKey = key;

            var prev = this.base.textContent;
            if (prev) this.ghost(prev);

            var self = this;
            this.line.classList.add('is-out');
            clearTimeout(this.swapTimer);
            this.swapTimer = setTimeout(function () {
                var baseHtml = '';
                var fillHtml = '';
                var chars = Array.from(line.txt);
                var pal = Palette.current;
                var last = Math.max(chars.length - 1, 1);
                for (var i = 0; i < chars.length; i++) {
                    var ch = chars[i] === ' ' ? '\u00A0' : esc(chars[i]);
                    var delay = '--d:' + Math.min(i * 38, 950) + 'ms';
                    //\u6E10\u53D8\u9760\u9010\u5B57\u63D2\u503C\uFF1Aa1 \u2192 a2\uFF0C\u884C\u5C3E\u63D0\u4EAE
                    var k = i / last;
                    var lift = Math.max(0, (k - 0.72) / 0.28) * 0.65;
                    var rgb = [0, 1, 2].map(function (c) {
                        var v = pal[0][c] + (pal[1][c] - pal[0][c]) * k;
                        return Math.round(v + (255 - v) * lift);
                    });
                    baseHtml += '<span style="' + delay + '">' + ch + '</span>';
                    fillHtml += '<span style="' + delay + ';color:rgb(' + rgb.join(',') + ')">' + ch + '</span>';
                }
                self.base.innerHTML = baseHtml;
                self.fill.innerHTML = fillHtml;
                self.fill.style.clipPath = 'inset(0 100% 0 0)';
                self.trans.textContent = line.tr || '';
                self.trans.style.animation = 'none';
                void self.trans.offsetWidth;
                self.trans.style.animation = '';
                self.line.classList.remove('is-out');
            }, 240);

            this.lineStart = line.t;
            this.lineEnd = next ? Math.max(line.t + 0.8, next.t - 0.05) : line.t + 8;

            this.el.classList.remove('is-beat');
            void this.el.offsetWidth;
            this.el.classList.add('is-beat');
        },

        /* 卡拉OK渐染：clip 随播放时间推进 */
        tick: function (time) {
            if (!this.active() || !this.fill || !this.fill.firstChild) return;
            var p = clamp((time - this.lineStart) / (this.lineEnd - this.lineStart), 0, 1);
            this.fill.style.clipPath = 'inset(0 ' + ((1 - p) * 100).toFixed(2) + '% 0 0)';
        },

        /* 竖排词影：左右交替，随机落位，漂移消散 */
        ghost: function (text) {
            var side = this.sides[this.flip ? 1 : 0];
            this.flip = !this.flip;
            if (!side) return;
            while (side.children.length >= 3) side.removeChild(side.firstChild);
            var g = document.createElement('div');
            g.className = 'mp-stage-ghost';
            var glyphs = Array.from(text);
            g.textContent = glyphs.length > 16 ? glyphs.slice(0, 16).join('') + '…' : text;
            g.style.top = (4 + Math.random() * 40).toFixed(1) + '%';
            g.style.left = (Math.random() * 50).toFixed(1) + '%';
            g.style.animationDuration = (13 + Math.random() * 5).toFixed(1) + 's';
            side.appendChild(g);
            setTimeout(function () { if (g.parentNode) g.parentNode.removeChild(g); }, 19000);
        },

        clear: function () {
            if (!this.el) return;
            this.lastKey = '';
            this.base.textContent = '';
            this.fill.textContent = '';
            this.trans.textContent = '';
        }
    };

    /* ========================================================
       歌单加载（sessionStorage 缓存提升跨页速度）
       ======================================================== */
    function loadPlaylist(done) {
        var key = 'acgmp:pl:' + CFG.playlist;
        var cached = null;
        try { cached = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (e) { }
        if (cached && cached.data && cached.data.length && Date.now() - cached.ts < 600000) {
            return done(cached.data, cached.hash);
        }

        fetch(CFG.playlist).then(function (r) { return r.json(); }).then(function (res) {
            var data = (res && res.data) || [];
            if (data.length) {
                try { sessionStorage.setItem(key, JSON.stringify({ ts: Date.now(), hash: res.hash, data: data })); } catch (e) { }
                done(data, res.hash);
            } else if (cached && cached.data && cached.data.length) {
                done(cached.data, cached.hash);
            } else {
                done([], '');
            }
        }).catch(function () {
            if (cached && cached.data && cached.data.length) done(cached.data, cached.hash);
            else done([], '');
        });
    }

    /* ========================================================
       启动
       ======================================================== */
    function boot() {
        loadPlaylist(function (tracks, hash) {
            if (!tracks.length) return; /* 无歌可播，保持沉默 */

            /* 追加访客通过全网搜索收藏的曲目（排在歌单之后，索引稳定） */
            var extras = (storage(true, EXTRA_KEY) || []).filter(function (t) {
                return t && t.name && t.url;
            }).slice(-60);
            tracks = tracks.concat(extras);

            Engine.init(tracks);
            Mutex.init();
            UI.build();

            /* 恢复状态 */
            var saved = Store.data;
            var sameList = saved.h === hash;
            Engine.mode = ['list', 'single', 'random'].indexOf(saved.m) > -1 ? saved.m : CFG.order;
            Engine.audio.loop = Engine.mode === 'single';
            if (Engine.mode === 'random') Engine.refillBag();
            Engine.setVolume(typeof saved.v === 'number' ? saved.v : CFG.volume);

            var index = sameList && typeof saved.i === 'number' ? clamp(saved.i, 0, tracks.length - 1) : 0;
            var seek = sameList && typeof saved.t === 'number' ? saved.t : 0;
            Store.save({ h: hash, i: index });

            /* 引擎 → UI 装配 */
            Engine.onTrackChange = function (track, idx) {
                Store.save({ i: idx, t: 0 });
                UI.renderTrack(track, idx);
            };
            Engine.onPlayState = function (playing) {
                UI.renderPlay(playing);
                if (playing) Mutex.claim();
            };

            Engine.index = index;
            UI.renderMode(Engine.mode, false);
            UI.renderVolume();
            UI.renderList();
            UI.renderTrack(tracks[index], index);

            var shouldPlay = saved.p === true || (saved.p === undefined && CFG.autoplay);
            if (shouldPlay) {
                Engine.load(index, seek, true);
            } else {
                Engine.pendingSeek = seek;
                Engine.audio.src = tracks[index].url;
            }

            /* 播放位置持久化 */
            Engine.audio.addEventListener('timeupdate', throttle(function () {
                Store.save({ t: Engine.audio.currentTime });
            }, 600));
            window.addEventListener('pagehide', function () {
                Store.save({ t: Engine.audio.currentTime, p: !Engine.audio.paused });
            });

            /* 系统媒体键 */
            if ('mediaSession' in navigator) {
                try {
                    navigator.mediaSession.setActionHandler('play', function () { Engine.play(); });
                    navigator.mediaSession.setActionHandler('pause', function () { Engine.pause(); });
                    navigator.mediaSession.setActionHandler('previoustrack', function () { Engine.prev(); });
                    navigator.mediaSession.setActionHandler('nexttrack', function () { Engine.next(false); });
                    navigator.mediaSession.setActionHandler('seekto', function (d) {
                        if (d && typeof d.seekTime === 'number') Engine.seekTo(d.seekTime);
                    });
                } catch (e) { }
            }

            /* 渲染循环 */
            (function loop() {
                if (!document.hidden) {
                    UI.renderProgress();
                    UI.syncLyric(false);
                    Stage.tick(Engine.audio.currentTime || 0);
                }
                requestAnimationFrame(loop);
            })();

            /* 对外 API：主题或其它插件可编程控制 */
            window.AcgMusicPlayer = {
                play: function () { Engine.play(); },
                pause: function () { Engine.pause(); },
                toggle: function () { Engine.toggle(); },
                next: function () { Engine.next(false); },
                prev: function () { Engine.prev(); },
                playAt: function (i) { Engine.playAt(i); },
                seek: function (t) { Engine.seekTo(t); },
                volume: function (v) { Engine.setVolume(v); UI.renderVolume(); },
                open: function () { UI.setUI('panel'); },
                immersive: function () { UI.openImm(); },
                state: function () {
                    return {
                        index: Engine.index,
                        track: Engine.current(),
                        paused: Engine.audio.paused,
                        time: Engine.audio.currentTime,
                        duration: Engine.duration(),
                        mode: Engine.mode,
                        ui: UI.root.dataset.ui
                    };
                }
            };
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
