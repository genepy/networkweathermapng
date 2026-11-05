/**
 * Zabbix Weathermap - Main JavaScript
 */

(function() {
    'use strict';

    // Translations exposed by the page (window.WM_T), English otherwise
    function t(s) { return (window.WM_T && window.WM_T[s]) || s; }
    
    // Utility functions
    const WM = {
        /**
         * Make an API request
         */
        api: async function(endpoint, options = {}) {
            const defaults = {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                },
            };
            
            const config = { ...defaults, ...options };
            
            if (config.body && typeof config.body === 'object') {
                config.body = JSON.stringify(config.body);
            }
            
            try {
                const response = await fetch('/api' + endpoint, config);
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.error || t('API request failed'));
                }
                
                return data;
            } catch (error) {
                throw error;
            }
        },
        
        /**
         * Format bytes to human readable
         */
        formatBytes: function(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
            
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        },
        
        /**
         * Format bits per second
         */
        formatBps: function(bps, decimals = 2) {
            if (bps === 0) return '0 bps';
            
            const k = 1000;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
            
            const i = Math.floor(Math.log(bps) / Math.log(k));
            
            return parseFloat((bps / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        },
        
        /**
         * Debounce function
         */
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },
        
        /**
         * Show a toast (see ui.js); type: success, error, info
         */
        notify: function(message, type = 'info') {
            if (window.WMUI) {
                return window.WMUI.toast(message, type);
            }
            window.alert(message);
        },

        /**
         * Confirmation dialog (see ui.js): a promise of true or false
         */
        confirm: function(message, options = {}) {
            if (window.WMUI) {
                return window.WMUI.confirm(Object.assign({ message: message }, options));
            }
            return Promise.resolve(window.confirm(message));
        }
    };

    function wmCreateHoverPanel() {
        let el = document.getElementById('wm-ds-hover');
        if (el) return el;

        el = document.createElement('div');
        el.id = 'wm-ds-hover';
        el.className = 'wm-hovercard';
        el.hidden = true;
        document.body.appendChild(el);
        return el;
    }

    function wmFormatNum(n) {
        if (!isFinite(n)) return '-';
        const abs = Math.abs(n);
        if (abs >= 1e12) return (n / 1e12).toFixed(2) + 'T';
        if (abs >= 1e9) return (n / 1e9).toFixed(2) + 'G';
        if (abs >= 1e6) return (n / 1e6).toFixed(2) + 'M';
        if (abs >= 1e3) return (n / 1e3).toFixed(2) + 'K';
        return n.toFixed(2);
    }

    /** A link speed as a short badge: 10G, 100M, 2.5G */
    function wmFormatSpeed(bps) {
        const n = Number(bps);
        if (!isFinite(n) || n <= 0) return '';
        const steps = [[1e12, 'T'], [1e9, 'G'], [1e6, 'M'], [1e3, 'K']];
        for (let i = 0; i < steps.length; i++) {
            if (n >= steps[i][0]) {
                return String(Math.round(n / steps[i][0] * 100) / 100) + steps[i][1];
            }
        }
        return String(Math.round(n));
    }

    /** The unit shown after a rate: bit/s for the bps of the data sources */
    function wmUnit(units) {
        const u = String(units || '').trim();
        return (u === '' || u.toLowerCase() === 'bps') ? 'bit/s' : u;
    }

    function wmEsc(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    /** The periods of the traffic graph: name, minutes */
    const WM_RANGES = [['day', 1440], ['week', 10080], ['month', 43200]];

    function wmRangeText(range) {
        return range === 'week' ? t('1 week') : range === 'month' ? t('1 month') : t('1 day');
    }

    function wmRangeMinutes(range) {
        for (let i = 0; i < WM_RANGES.length; i++) {
            if (WM_RANGES[i][0] === range) return WM_RANGES[i][1];
        }
        return 1440;
    }

    /** The period last picked, remembered in this browser */
    function wmSavedRange() {
        try {
            const r = window.localStorage.getItem('wm-hover-range');
            if (r && WM_RANGES.some(x => x[0] === r)) return r;
        } catch (e) { /* storage blocked */ }
        return 'day';
    }

    function wmSaveRange(range) {
        try { window.localStorage.setItem('wm-hover-range', range); } catch (e) { /* storage blocked */ }
    }

    /**
     * The traffic of a link over a period, as {from, to, units, tz, in:
     * [[t, v]...], out: [...]}: from the map's own route (info.traffic, see
     * MapController::linkTraffic) or from a data source selection (the PNG
     * imagemap). Answers are kept 5 minutes.
     */
    const wmTrafficCache = {};

    async function wmLoadTraffic(info, selection, range) {
        const key = (info.traffic || JSON.stringify(selection)) + '|' + range;
        const hit = wmTrafficCache[key];
        if (hit && Date.now() - hit.at < 300000) return hit.data;

        let data;
        if (info.traffic) {
            const res = await fetch(info.traffic + (info.traffic.indexOf('?') >= 0 ? '&' : '?') + 'range=' + encodeURIComponent(range), {credentials: 'same-origin'});
            if (!res.ok) throw new Error(t('Failed to load'));
            data = await res.json();
        } else {
            const sourceId = Number(selection.sourceId || 0);
            if (!sourceId) throw new Error(t('Invalid data source'));
            const minutes = wmRangeMinutes(range);
            const params = new URLSearchParams();
            params.set('selection', JSON.stringify({
                hostId: selection.hostId,
                inKey: selection.inKey,
                outKey: selection.outKey,
                interfaceId: selection.interfaceId
            }));
            params.set('minutes', String(minutes));
            const res = await fetch('/api/data-sources/' + encodeURIComponent(String(sourceId)) + '/link-bandwidth?' + params.toString());
            const body = await res.json();
            if (!res.ok || !body || !body.success) {
                throw new Error((body && body.error) ? body.error : t('Failed to load'));
            }
            const to = Math.round(Date.now() / 1000);
            const from = to - minutes * 60;
            const pts = function (dir) {
                const s = body.data && body.data[dir] && Array.isArray(body.data[dir].series) ? body.data[dir].series : [];
                return s.map(p => [Number(p.t), Number(p.v)])
                    .filter(p => isFinite(p[0]) && isFinite(p[1]) && p[0] >= from);
            };
            data = {
                from: from, to: to,
                units: (body.data && body.data.in && body.data.in.units) || '',
                in: pts('in'), out: pts('out')
            };
        }
        wmTrafficCache[key] = {at: Date.now(), data: data};
        return data;
    }

    /** SVG path of an area between the middle line and the series, broken over gaps */
    function wmAreaPath(points, x, y, mid) {
        if (!points.length) return '';
        let gap = Infinity;
        if (points.length > 2) {
            const steps = [];
            for (let i = 1; i < points.length; i++) steps.push(points[i][0] - points[i - 1][0]);
            steps.sort((a, b) => a - b);
            gap = steps[Math.floor(steps.length / 2)] * 5;
        }
        let d = '';
        let run = [];
        const flush = function () {
            if (!run.length) return;
            const x0 = x(run[0][0]).toFixed(1);
            const x1 = x(run[run.length - 1][0]).toFixed(1);
            d += 'M' + x0 + ' ' + mid.toFixed(1);
            run.forEach(p => { d += 'L' + x(p[0]).toFixed(1) + ' ' + y(p[1]).toFixed(1); });
            d += 'L' + x1 + ' ' + mid.toFixed(1) + 'Z';
            run = [];
        };
        for (let i = 0; i < points.length; i++) {
            if (run.length && points[i][0] - run[run.length - 1][0] > gap) flush();
            run.push(points[i]);
        }
        flush();
        return d;
    }

    /** The line along the top of an area (same breaks) */
    function wmLinePath(points, x, y) {
        let d = '';
        let gap = Infinity;
        if (points.length > 2) {
            const steps = [];
            for (let i = 1; i < points.length; i++) steps.push(points[i][0] - points[i - 1][0]);
            steps.sort((a, b) => a - b);
            gap = steps[Math.floor(steps.length / 2)] * 5;
        }
        for (let i = 0; i < points.length; i++) {
            const move = i === 0 || points[i][0] - points[i - 1][0] > gap;
            d += (move ? 'M' : 'L') + x(points[i][0]).toFixed(1) + ' ' + y(points[i][1]).toFixed(1);
        }
        return d;
    }

    /** Time labels under the graph, in the map's time zone when known */
    function wmTimeLabel(sec, range, tz) {
        const opts = range === 'day' ? {hour: '2-digit', minute: '2-digit'}
            : range === 'week' ? {weekday: 'short', day: 'numeric'}
                : {day: 'numeric', month: 'short'};
        const lang = document.documentElement.lang || undefined;
        try {
            return new Intl.DateTimeFormat(lang, tz ? Object.assign({timeZone: tz}, opts) : opts).format(new Date(sec * 1000));
        } catch (e) {
            return new Intl.DateTimeFormat(lang, opts).format(new Date(sec * 1000));
        }
    }

    /**
     * The traffic graph: IN above the middle line, OUT below it, on the same
     * scale, with the largest value and a few times as labels.
     */
    function wmTrafficSvg(data, range, width) {
        const W = Math.max(240, Math.round(width));
        const H = 150;
        const padL = 64, padR = 6, padT = 8, padB = 20;
        const mid = padT + (H - padT - padB) / 2;
        const half = (H - padT - padB) / 2;
        const ins = data.in || [];
        const outs = data.out || [];
        let max = 0;
        ins.concat(outs).forEach(p => { if (p[1] > max) max = p[1]; });
        if (max <= 0) max = 1;
        const from = Number(data.from), to = Number(data.to) > from ? Number(data.to) : from + 1;
        const x = t0 => padL + (t0 - from) / (to - from) * (W - padL - padR);
        const yIn = v => mid - Math.max(0, v) / max * half;
        const yOut = v => mid + Math.max(0, v) / max * half;

        let ticks = '';
        for (let i = 0; i <= 3; i++) {
            const tt = from + (to - from) * (i + 0.5) / 4;
            ticks += `<line class="wm-hc-grid" x1="${x(tt).toFixed(1)}" x2="${x(tt).toFixed(1)}" y1="${padT}" y2="${H - padB}"/>`
                + `<text class="wm-hc-axis" x="${x(tt).toFixed(1)}" y="${H - 6}" text-anchor="middle">${wmEsc(wmTimeLabel(tt, range, data.tz))}</text>`;
        }
        // the scale; the unit is in the peak and average lines
        const top = wmFormatNum(max);
        return `<svg class="wm-hc-chart" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-label="${wmEsc(t('IN above, OUT below'))}">
            ${ticks}
            <line class="wm-hc-zero" x1="${padL}" x2="${W - padR}" y1="${mid}" y2="${mid}"/>
            <path class="wm-hc-area-in" d="${wmAreaPath(ins, x, yIn, mid)}"/>
            <path class="wm-hc-line-in" d="${wmLinePath(ins, x, yIn)}"/>
            <path class="wm-hc-area-out" d="${wmAreaPath(outs, x, yOut, mid)}"/>
            <path class="wm-hc-line-out" d="${wmLinePath(outs, x, yOut)}"/>
            <text class="wm-hc-axis" x="${padL - 6}" y="${padT + 8}" text-anchor="end">${wmEsc(top)}</text>
            <text class="wm-hc-axis wm-hc-axis-in" x="${padL - 6}" y="${mid - 4}" text-anchor="end">${wmEsc(t('IN'))} ▲</text>
            <text class="wm-hc-axis wm-hc-axis-out" x="${padL - 6}" y="${mid + 12}" text-anchor="end">${wmEsc(t('OUT'))} ▼</text>
            <text class="wm-hc-axis" x="${padL - 6}" y="${H - padB}" text-anchor="end">${wmEsc(top)}</text>
        </svg>`;
    }

    /** Peak and average of the period, under the graph */
    function wmTrafficStats(data) {
        const unit = wmUnit(data.units);
        const stat = function (pts) {
            if (!pts || !pts.length) return null;
            let peak = 0, sum = 0;
            pts.forEach(p => { sum += p[1]; if (p[1] > peak) peak = p[1]; });
            return {peak: peak, avg: sum / pts.length};
        };
        const cell = function (label, cls, s) {
            if (!s) return '';
            return `<div class="wm-hc-stat"><span class="wm-hc-k ${cls}">${wmEsc(label)}</span>`
                + `<span>${wmEsc(t('Peak'))} <b>${wmFormatNum(s.peak)} ${wmEsc(unit)}</b></span>`
                + `<span>${wmEsc(t('Average'))} <b>${wmFormatNum(s.avg)} ${wmEsc(unit)}</b></span></div>`;
        };
        return cell(t('IN'), 'wm-hc-in', stat(data.in)) + cell(t('OUT'), 'wm-hc-out', stat(data.out));
    }

    /**
     * How wide the traffic graph of the hover panel is drawn: a share of the
     * window, so it stays readable on large screens without zooming the map.
     * Our own graphs (/map/{id}/link/{id}/graph) are then asked for at a
     * larger scale so they stay sharp.
     */
    function wmGraphSize(url) {
        const width = Math.round(Math.max(500, Math.min(1400, window.innerWidth * 0.4, window.innerWidth - 42)));
        let out = url;
        const scale = Math.min(3, Math.ceil(width * (window.devicePixelRatio || 1) / 600));
        if (scale > 1 && /^\/map\/\d+\/link\/\d+\/graph(\?|$)/.test(url)) {
            out += (url.indexOf('?') >= 0 ? '&' : '?') + 'scale=' + scale;
        }
        return {width: Math.min(width, window.innerWidth - 42), url: out};
    }

    /**
     * The hover card of a link: its speed, "A ↔ B", its sites, IN and OUT
     * now, then the traffic of the last day, week or month (IN above, OUT
     * below) when the map can read its history, or the OVERLIBGRAPH picture.
     * info: {title (link name), a, b (node names), sites ([site of A, site
     * of B]), speed ([in, out] bit/s), in, out, inpct, outpct (values from
     * the last map render), traffic (series URL), ds (data source
     * selection, JSON text), graph (picture URL), hint (a line under it)}.
     * Used by the PNG imagemap (area.link) and the SVG view (viewer.js).
     *
     * The card follows the pointer. With a period switch it can be reached:
     * leave() keeps it in place for a moment, and it stays while the
     * pointer is on it.
     */
    const wmHover = (function() {
        let hover = null;
        let lastKey = null;
        let visible = false;
        let following = true;
        let over = false;
        let leaveTimer = 0;
        let lastPos = null;
        let current = null;      // {info, selection, range}

        function panel() {
            if (!hover) {
                hover = wmCreateHoverPanel();
                document.addEventListener('mousemove', function(e) {
                    if (!visible || !following) return;
                    wmHover.move(e);
                });
                hover.addEventListener('mouseenter', function () {
                    over = true;
                    following = false;
                    clearTimeout(leaveTimer);
                });
                hover.addEventListener('mouseleave', function () {
                    over = false;
                    wmHover.hide();
                });
                hover.addEventListener('click', function (e) {
                    const b = e.target.closest ? e.target.closest('[data-range]') : null;
                    if (!b || !current) return;
                    e.preventDefault();
                    e.stopPropagation();
                    const range = b.getAttribute('data-range');
                    if (range === current.range) return;
                    wmSaveRange(range);
                    current.range = range;
                    drawTraffic();
                });
                document.addEventListener('keydown', function (e) {
                    if (visible && e.key === 'Escape') wmHover.hide();
                });
            }
            return hover;
        }

        /** Keep the card inside the window after its size changed */
        function keepInside() {
            if (!hover || !visible) return;
            if (following && lastPos) {
                wmHover.move(lastPos);
                return;
            }
            const r = hover.getBoundingClientRect();
            if (r.right > window.innerWidth - 8) hover.style.left = Math.max(8, window.innerWidth - 8 - r.width) + 'px';
            if (r.bottom > window.innerHeight - 8) hover.style.top = Math.max(8, window.innerHeight - 8 - r.height) + 'px';
        }

        function nowLine(label, cls, value, pct) {
            if (value === null || value === undefined || !isFinite(Number(value))) return '';
            const p = (pct !== null && pct !== undefined && isFinite(Number(pct))) ? `<span class="wm-hc-pct">${Math.round(Number(pct))} %</span>` : '';
            const bar = p ? `<span class="wm-hc-bar"><span class="${cls}" style="width:${Math.max(0, Math.min(100, Number(pct)))}%"></span></span>` : '';
            return `<div class="wm-hc-rate"><span class="wm-hc-k ${cls}">${wmEsc(label)}</span>`
                + `<b>${wmFormatNum(Number(value))} bit/s</b>${bar}${p}</div>`;
        }

        function head(info) {
            let speed = '';
            if (Array.isArray(info.speed) && info.speed.length === 2) {
                const a = wmFormatSpeed(info.speed[0]);
                const b = wmFormatSpeed(info.speed[1]);
                const text = a === b ? a : a + ' / ' + b;
                if (text) speed = `<span class="wm-hc-speed" title="${wmEsc(t('Link speed'))}">${wmEsc(text)}</span>`;
            }
            const ends = info.a && info.b;
            const title = ends ? `${wmEsc(info.a)} <span class="wm-hc-arrow">↔</span> ${wmEsc(info.b)}` : wmEsc(info.title || '');
            const sub = [];
            if (ends && info.title) sub.push(wmEsc(info.title));
            const sites = Array.isArray(info.sites) ? info.sites.filter(Boolean) : [];
            if (sites.length === 2 && sites[0] !== sites[1]) sub.push(wmEsc(sites[0]) + ' ↔ ' + wmEsc(sites[1]));
            else if (sites.length) sub.push(wmEsc(sites[0]));
            if (!speed && !title) return '';
            return `<div class="wm-hc-head">${speed}<div class="wm-hc-title">${title}</div></div>`
                + (sub.length ? `<div class="wm-hc-sub">${sub.join(' · ')}</div>` : '');
        }

        function rangeButtons(range) {
            return `<div class="wm-hc-ranges" role="group" aria-label="${wmEsc(t('Traffic period'))}">`
                + WM_RANGES.map(r => `<button type="button" data-range="${r[0]}" aria-pressed="${r[0] === range ? 'true' : 'false'}">${wmEsc(wmRangeText(r[0]))}</button>`).join('')
                + '</div>';
        }

        /** Fills the graph part of the card for the period picked */
        async function drawTraffic() {
            const c = current;
            const el = panel();
            const box = el.querySelector('.wm-hc-traffic');
            if (!c || !box) return;
            box.innerHTML = rangeButtons(c.range) + `<div class="wm-hc-plot wm-hc-wait">${wmEsc(t('Loading…'))}</div>`;
            const width = Math.min(520, window.innerWidth - 50);
            try {
                const data = await wmLoadTraffic(c.info, c.selection, c.range);
                if (current !== c || !visible) return;
                const empty = !(data.in && data.in.length) && !(data.out && data.out.length);
                box.innerHTML = rangeButtons(c.range) + (empty
                    ? `<div class="wm-hc-plot wm-hc-wait">${wmEsc(t('No traffic recorded for this period'))}</div>`
                    : `<div class="wm-hc-plot">${wmTrafficSvg(data, c.range, width)}</div><div class="wm-hc-stats">${wmTrafficStats(data)}</div>`);
            } catch (err) {
                if (current !== c || !visible) return;
                box.innerHTML = rangeButtons(c.range) + `<div class="wm-hc-plot wm-hc-wait wm-hc-error">${wmEsc(String(err.message || err))}</div>`;
            }
            keepInside();
        }

        return {
            move: function(e) {
                const el = panel();
                lastPos = {clientX: e.clientX, clientY: e.clientY};
                const w = el.offsetWidth || 340;
                const h = el.offsetHeight || 120;
                let x = e.clientX + 18;
                let y = e.clientY + 18;
                if (x + w > window.innerWidth - 8) x = Math.max(8, e.clientX - w - 18);
                if (y + h > window.innerHeight - 8) y = Math.max(8, e.clientY - h - 18);
                el.style.left = x + 'px';
                el.style.top = y + 'px';
            },
            show: function(info, e) {
                const el = panel();
                const key = JSON.stringify(info);
                clearTimeout(leaveTimer);
                if (visible && key === lastKey) {
                    // back on the same link: follow the pointer again
                    following = !over;
                    return;
                }
                lastKey = key;
                following = true;
                over = false;

                let selection = null;
                if (info.ds && !info.traffic) {
                    try { selection = JSON.parse(info.ds); } catch (err) { selection = null; }
                }
                const now = nowLine(t('IN'), 'wm-hc-in', info.in, info.inpct) + nowLine(t('OUT'), 'wm-hc-out', info.out, info.outpct);
                const top = head(info);
                const series = !!(info.traffic || selection);

                if (!series && !info.graph && !now) {
                    this.hide();
                    return;
                }

                visible = true;
                el.hidden = false;
                el.classList.toggle('wm-hc-wide', series || !!info.graph);
                const hint = info.hint ? `<div class="wm-hc-hint">${wmEsc(info.hint)}</div>` : '';
                el.style.width = '';

                if (series) {
                    current = {info: info, selection: selection, range: wmSavedRange()};
                    el.innerHTML = top + (now ? `<div class="wm-hc-now">${now}</div>` : '') + '<div class="wm-hc-traffic"></div>' + hint;
                    if (e) this.move(e);
                    drawTraffic();
                    return;
                }

                current = null;
                let graph = '';
                if (info.graph) {
                    const size = wmGraphSize(info.graph);
                    el.style.width = (size.width + 26) + 'px';
                    graph = `<img src="${wmEsc(size.url)}" alt="" class="wm-hc-picture">`;
                }
                el.innerHTML = top + (now ? `<div class="wm-hc-now">${now}</div>` : '') + graph + hint;
                if (e) this.move(e);
                const img = el.querySelector('img');
                if (img) {
                    // the panel grows when the picture arrives: keep it on screen
                    img.addEventListener('load', keepInside);
                    img.addEventListener('error', () => { img.remove(); });
                }
            },
            /**
             * The pointer left the link: a card with a period switch waits a
             * moment in place, so the pointer can reach it; others go at once.
             */
            leave: function() {
                if (!visible) return;
                if (!current) {
                    this.hide();
                    return;
                }
                following = false;
                clearTimeout(leaveTimer);
                leaveTimer = setTimeout(() => { if (!over) this.hide(); }, 450);
            },
            hide: function() {
                clearTimeout(leaveTimer);
                visible = false;
                lastKey = null;
                current = null;
                over = false;
                following = true;
                if (hover) {
                    hover.hidden = true;
                    hover.innerHTML = '';
                }
            }
        };
    })();

    function wmAttachDatasourceHover() {
        document.querySelectorAll('area.link').forEach(function(area) {
            area.addEventListener('mouseenter', function(e) {
                const selectionRaw = area.getAttribute('data-datasource') || '';
                if (!selectionRaw) return;
                wmHover.show({ds: selectionRaw}, e);
            });
            area.addEventListener('mouseleave', function() {
                wmHover.leave();
            });
        });
    }
    
    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        // Add any global initialization here
        wmAttachDatasourceHover();
    });
    
    // Expose to global scope
    WM.hover = wmHover;
    window.WM = WM;
})();
