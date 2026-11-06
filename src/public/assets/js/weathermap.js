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

    /** Peak and average of the period (or of the part from `min` to `max` seconds), under the graph */
    function wmTrafficStats(data, min, max) {
        const unit = wmUnit(data.units);
        const lo = isFinite(min) ? min : -Infinity;
        const hi = isFinite(max) ? max : Infinity;
        const stat = function (all) {
            const pts = (all || []).filter(p => p[0] >= lo && p[0] <= hi);
            if (!pts.length) return null;
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
     * uPlot (assets/vendor/uplot), loaded the first time a graph is drawn.
     * Its address comes from the data-uplot attributes of this script's tag.
     */
    const WM_SCRIPT = document.currentScript;
    let wmUplotPromise = null;

    function wmLoadUplot() {
        if (window.uPlot) return Promise.resolve(window.uPlot);
        if (wmUplotPromise) return wmUplotPromise;
        const js = WM_SCRIPT && WM_SCRIPT.getAttribute('data-uplot');
        if (!js) return Promise.reject(new Error('uPlot is not available'));
        wmUplotPromise = new Promise(function (resolve, reject) {
            const css = WM_SCRIPT.getAttribute('data-uplot-css');
            if (css && !document.querySelector('link[data-uplot]')) {
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = css;
                link.setAttribute('data-uplot', '');
                document.head.appendChild(link);
            }
            const s = document.createElement('script');
            s.src = js;
            s.async = true;
            s.onload = function () {
                if (window.uPlot) resolve(window.uPlot);
                else reject(new Error('uPlot is not available'));
            };
            s.onerror = function () {
                wmUplotPromise = null;
                reject(new Error('uPlot is not available'));
            };
            document.head.appendChild(s);
        });
        return wmUplotPromise;
    }

    /** Median step between the points of a series, times 5: a longer step is a gap */
    function wmGapOf(points) {
        if (points.length <= 2) return Infinity;
        const steps = [];
        for (let i = 1; i < points.length; i++) steps.push(points[i][0] - points[i - 1][0]);
        steps.sort((a, b) => a - b);
        return steps[Math.floor(steps.length / 2)] * 5;
    }

    /**
     * The series as uPlot wants them: [times, IN, -OUT] on one time line.
     * IN and OUT points a little apart (polled a few seconds apart) share a
     * time; a series without a point at a time keeps its previous value,
     * and a gap in a series (no point for 5 usual steps) is a null, where
     * uPlot breaks the line.
     */
    function wmAlign(data) {
        const series = [data.in, data.out].map(function (pts) {
            return (pts || []).map(p => [Number(p[0]), Number(p[1])])
                .filter(p => isFinite(p[0]) && isFinite(p[1]))
                .sort((a, b) => a[0] - b[0]);
        });
        const gaps = series.map(wmGapOf);
        const median = series.map(g => wmGapOf(g) / 5).filter(isFinite);
        const tol = median.length ? Math.min.apply(null, median) / 2 : 0;

        const all = [];
        series.forEach(function (pts, k) {
            pts.forEach(p => all.push([p[0], k + 1, (k ? -1 : 1) * Math.max(0, p[1])]));
        });
        all.sort((a, b) => a[0] - b[0]);
        let rows = [];
        all.forEach(function (p) {
            const r = rows[rows.length - 1];
            if (r && p[0] - r[0] <= tol && r[p[1]] === undefined) {
                r[p[1]] = p[2];
            } else {
                const n = [p[0], undefined, undefined];
                n[p[1]] = p[2];
                rows.push(n);
            }
        });

        // fill the holes: the previous value, or null over a gap
        const breaks = [];
        [1, 2].forEach(function (k) {
            let last = -1;
            rows.forEach(function (r, i) {
                if (r[k] === undefined) return;
                if (last >= 0) {
                    const gap = r[0] - rows[last][0] > gaps[k - 1];
                    for (let m = last + 1; m < i; m++) rows[m][k] = gap ? null : rows[last][k];
                    if (gap && i === last + 1) breaks.push([rows[last][0] + 1, k]);
                }
                last = i;
            });
            rows.forEach(function (r) { if (r[k] === undefined) r[k] = null; });
        });
        if (breaks.length) {
            breaks.forEach(function (b) {
                const n = [b[0], undefined, undefined];
                n[b[1]] = null;
                rows.push(n);
            });
            rows.sort((a, b) => a[0] - b[0]);
            // the other series keeps its line through a break of this one
            [1, 2].forEach(function (k) {
                rows.forEach(function (r, i) {
                    if (r[k] === undefined) r[k] = i > 0 ? rows[i - 1][k] : null;
                });
            });
        }
        return [rows.map(r => r[0]), rows.map(r => r[1]), rows.map(r => r[2])];
    }

    /** A colour of the theme as rgba() with this opacity (canvas has no color-mix) */
    function wmAlpha(color, alpha) {
        const c = document.createElement('canvas').getContext('2d');
        if (!c) return color;
        c.fillStyle = '#000';
        c.fillStyle = color;
        const v = String(c.fillStyle);
        let m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(v);
        if (m) return `rgba(${parseInt(m[1], 16)}, ${parseInt(m[2], 16)}, ${parseInt(m[3], 16)}, ${alpha})`;
        m = /^rgba?\(([^,]+),([^,]+),([^,)]+)/.exec(v);
        if (m) return `rgba(${m[1].trim()}, ${m[2].trim()}, ${m[3].trim()}, ${alpha})`;
        return color;
    }

    /** A time label for a span of `seconds`: hours, then days, then dates */
    function wmSpanLabel(sec, seconds, tz) {
        const range = seconds <= 1.5 * 86400 ? 'day' : seconds <= 10 * 86400 ? 'week' : 'month';
        return wmTimeLabel(sec, range, tz);
    }

    /** A full date and time, for the value under the pointer */
    function wmFullTime(sec, tz) {
        const opts = {weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'};
        const lang = document.documentElement.lang || undefined;
        try {
            return new Intl.DateTimeFormat(lang, tz ? Object.assign({timeZone: tz}, opts) : opts).format(new Date(sec * 1000));
        } catch (e) {
            return new Intl.DateTimeFormat(lang, opts).format(new Date(sec * 1000));
        }
    }

    /**
     * The traffic graph drawn with uPlot in `plot`: IN above the middle line,
     * OUT below it. Under the pointer, `readout` shows the time and both
     * values; dragging across the graph zooms on that period (double-click
     * or *Reset zoom* goes back), and `stats` gives the peak and average of
     * the period shown. Returns the uPlot instance.
     */
    function wmChart(U, plot, data, parts) {
        const cs = getComputedStyle(plot);
        const css = function (name, fallback) { return cs.getPropertyValue(name).trim() || fallback; };
        const cIn = css('--wm-hc-in', '#1f8a4c');
        const cOut = css('--wm-hc-out', '#2563eb');
        const muted = css('--wm-text-muted', '#666');
        const grid = css('--wm-border', '#ddd');
        // uPlot scales the size for the screen density: a whole number of px
        const font = '11px ' + css('--wm-font', 'sans-serif');
        const unit = wmUnit(data.units);
        const tz = data.tz || '';
        const from = Number(data.from);
        const to = Number(data.to) > from ? Number(data.to) : from + 1;
        const aligned = wmAlign(data);

        const value = function (v) {
            return v === null || v === undefined ? '–' : wmFormatNum(Math.abs(v)) + ' ' + unit;
        };
        const zoomed = function (u) {
            return u.scales.x.min > from + 1 || u.scales.x.max < to - 1;
        };
        const idle = function (u) {
            if (!zoomed(u)) {
                parts.readout.innerHTML = `<span class="wm-hc-tip">${wmEsc(t('Drag across the graph to zoom in'))}</span>`;
                return;
            }
            parts.readout.innerHTML = `<span>${wmEsc(wmFullTime(u.scales.x.min, tz))} – ${wmEsc(wmFullTime(u.scales.x.max, tz))}</span>`
                + `<button type="button" class="wm-hc-reset" data-act="reset-zoom" title="${wmEsc(t('Reset zoom (double-click)'))}">${wmEsc(t('Reset zoom'))}</button>`;
        };

        let tzDate;
        if (tz) {
            try {
                new Intl.DateTimeFormat('en', {timeZone: tz});
                tzDate = ts => U.tzDate(new Date(ts * 1000), tz);
            } catch (e) { tzDate = undefined; }
        }

        const opts = {
            width: parts.width(),
            height: 150,
            legend: {show: false},
            cursor: {
                y: false,
                drag: {x: true, y: false, setScale: true},
                points: {size: 7},
                // our own double-click: back to the whole period
                bind: {dblclick: function () { return function () { return null; }; }}
            },
            scales: {
                x: {time: true, min: from, max: to},
                y: {
                    // the same scale above and below the middle line
                    range: function (u, min, max) {
                        const m = Math.max(Math.abs(min || 0), Math.abs(max || 0)) || 1;
                        return [-m * 1.08, m * 1.08];
                    }
                }
            },
            series: [
                {},
                {label: t('IN'), stroke: cIn, fill: wmAlpha(cIn, 0.28), width: 1.5, fillTo: 0, points: {show: false}},
                {label: t('OUT'), stroke: cOut, fill: wmAlpha(cOut, 0.28), width: 1.5, fillTo: 0, points: {show: false}}
            ],
            axes: [
                {
                    stroke: muted, font: font, space: 70, size: 24,
                    grid: {stroke: grid, width: 1}, ticks: {stroke: grid, width: 1, size: 4},
                    values: function (u, splits) {
                        const span = u.scales.x.max - u.scales.x.min;
                        return splits.map(s => wmSpanLabel(s, span, tz));
                    }
                },
                {
                    stroke: muted, font: font, space: 28, size: 52,
                    grid: {stroke: grid, width: 1}, ticks: {show: false},
                    // 500M rather than 500.00M
                    values: function (u, splits) { return splits.map(s => wmFormatNum(Math.abs(s)).replace(/\.?0+(?=[KMGT]?$)/, '')); }
                }
            ],
            hooks: {
                draw: [function (u) {
                    // the middle line: IN above, OUT below
                    const ctx = u.ctx;
                    const y = Math.round(u.valToPos(0, 'y', true)) + 0.5;
                    ctx.save();
                    ctx.strokeStyle = muted;
                    ctx.globalAlpha = 0.7;
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(u.bbox.left, y);
                    ctx.lineTo(u.bbox.left + u.bbox.width, y);
                    ctx.stroke();
                    ctx.restore();
                }],
                setCursor: [function (u) {
                    const i = u.cursor.idx;
                    if (i === null || i === undefined) {
                        idle(u);
                        return;
                    }
                    parts.readout.innerHTML = `<span class="wm-hc-when">${wmEsc(wmFullTime(u.data[0][i], tz))}</span>`
                        + `<span><span class="wm-hc-k wm-hc-in">${wmEsc(t('IN'))}</span><b>${wmEsc(value(u.data[1][i]))}</b></span>`
                        + `<span><span class="wm-hc-k wm-hc-out">${wmEsc(t('OUT'))}</span><b>${wmEsc(value(u.data[2][i]))}</b></span>`;
                }],
                setScale: [function (u, key) {
                    if (key !== 'x') return;
                    parts.stats.innerHTML = wmTrafficStats(data, u.scales.x.min, u.scales.x.max);
                    if (u.cursor.idx === null || u.cursor.idx === undefined) idle(u);
                }]
            }
        };
        if (tzDate) opts.tzDate = tzDate;

        const u = new U(opts, aligned, plot);
        u.over.addEventListener('dblclick', function () { u.setScale('x', {min: from, max: to}); });
        u.wmReset = function () { u.setScale('x', {min: from, max: to}); };
        idle(u);
        return u;
    }

    /** Every traffic panel on the page, redrawn when the theme changes */
    const wmPanels = new Set();

    (function watchTheme() {
        const redraw = function () { wmPanels.forEach(p => p.redraw()); };
        new MutationObserver(redraw).observe(document.documentElement, {attributes: true, attributeFilter: ['data-theme']});
        if (window.matchMedia) {
            const mq = window.matchMedia('(prefers-color-scheme: dark)');
            if (mq.addEventListener) mq.addEventListener('change', redraw);
        }
    })();

    function wmRangeButtons(range) {
        return `<div class="wm-hc-ranges" role="group" aria-label="${wmEsc(t('Traffic period'))}">`
            + WM_RANGES.map(r => `<button type="button" data-range="${r[0]}" aria-pressed="${r[0] === range ? 'true' : 'false'}">${wmEsc(wmRangeText(r[0]))}</button>`).join('')
            + '</div>';
    }

    /**
     * The traffic part of a card in `box`: the period switch (1 day, 1 week,
     * 1 month, remembered in the browser), the graph and its peak and
     * average. options: {width: function giving the graph width in pixels
     * (else the graph follows the width of the box), changed: called when
     * the content changed size}. show({info, selection}) draws the traffic
     * of a link (see wmLoadTraffic), clear() empties the box.
     */
    function wmTrafficPanel(box, options) {
        const o = options || {};
        let src = null;
        let range = wmSavedRange();
        let chart = null;
        let data = null;
        let seq = 0;
        let resize = null;

        box.classList.add('wm-traffic');
        box.addEventListener('click', function (e) {
            const b = e.target.closest ? e.target.closest('[data-range], [data-act="reset-zoom"]') : null;
            if (!b || !src) return;
            e.preventDefault();
            e.stopPropagation();
            if (b.hasAttribute('data-act')) {
                if (chart) chart.wmReset();
                return;
            }
            const r = b.getAttribute('data-range');
            if (r === range) return;
            range = r;
            wmSaveRange(r);
            draw();
        });

        function width(plot) {
            return Math.max(200, Math.round(o.width ? o.width() : (plot.clientWidth || 260)));
        }

        function destroy() {
            if (resize) {
                resize.disconnect();
                resize = null;
            }
            if (chart) {
                chart.destroy();
                chart = null;
            }
        }

        function changed() {
            if (o.changed) o.changed();
        }

        /** Draws `data` in the box: uPlot, or the plain SVG graph if it cannot load */
        async function mount(token) {
            destroy();
            const empty = !(data.in && data.in.length) && !(data.out && data.out.length);
            if (empty) {
                box.innerHTML = wmRangeButtons(range) + `<div class="wm-hc-plot wm-hc-wait">${wmEsc(t('No traffic recorded for this period'))}</div>`;
                changed();
                return;
            }
            box.innerHTML = wmRangeButtons(range)
                + `<div class="wm-hc-plot" role="img" aria-label="${wmEsc(t('IN above, OUT below'))}"></div>`
                + '<div class="wm-hc-readout" aria-live="off"></div>'
                + `<div class="wm-hc-stats">${wmTrafficStats(data)}</div>`;
            const plot = box.querySelector('.wm-hc-plot');
            let U = null;
            try { U = await wmLoadUplot(); } catch (e) { U = null; }
            if (token !== seq) return;
            if (!U) {
                box.querySelector('.wm-hc-readout').remove();
                plot.innerHTML = wmTrafficSvg(data, range, width(plot));
                changed();
                return;
            }
            chart = wmChart(U, plot, data, {
                width: () => width(plot),
                readout: box.querySelector('.wm-hc-readout'),
                stats: box.querySelector('.wm-hc-stats')
            });
            if (!o.width && window.ResizeObserver) {
                let last = width(plot);
                resize = new ResizeObserver(function () {
                    const w = width(plot);
                    if (chart && w !== last) {
                        last = w;
                        chart.setSize({width: w, height: 150});
                    }
                });
                resize.observe(plot);
            }
            changed();
        }

        async function draw() {
            const token = ++seq;
            destroy();
            data = null;
            box.innerHTML = wmRangeButtons(range) + `<div class="wm-hc-plot wm-hc-wait">${wmEsc(t('Loading…'))}</div>`;
            changed();
            try {
                const d = await wmLoadTraffic(src.info, src.selection, range);
                if (token !== seq) return;
                data = d;
                await mount(token);
            } catch (err) {
                if (token !== seq) return;
                box.innerHTML = wmRangeButtons(range) + `<div class="wm-hc-plot wm-hc-wait wm-hc-error">${wmEsc(String(err.message || err))}</div>`;
                changed();
            }
        }

        const panel = {
            show: function (source) {
                src = source;
                range = wmSavedRange();
                wmPanels.add(panel);
                draw();
            },
            /** Draws the same data again (theme change) */
            redraw: function () {
                if (src && data) mount(++seq);
            },
            clear: function () {
                seq++;
                destroy();
                src = null;
                data = null;
                wmPanels.delete(panel);
                box.innerHTML = '';
            }
        };
        return panel;
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
        let current = null;      // {info, selection} when the card has a traffic graph
        let traffic = null;      // its wmTrafficPanel

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

                if (traffic) {
                    traffic.clear();
                    traffic = null;
                }
                if (series) {
                    current = {info: info, selection: selection};
                    el.innerHTML = top + (now ? `<div class="wm-hc-now">${now}</div>` : '') + '<div class="wm-hc-traffic"></div>' + hint;
                    if (e) this.move(e);
                    // a fixed width, so the values under the pointer do not widen the card
                    const graphWidth = Math.min(520, window.innerWidth - 50);
                    el.style.width = (graphWidth + 26) + 'px';
                    traffic = wmTrafficPanel(el.querySelector('.wm-hc-traffic'), {
                        width: () => graphWidth,
                        changed: keepInside
                    });
                    traffic.show(current);
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
                if (traffic) {
                    traffic.clear();
                    traffic = null;
                }
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
    WM.trafficPanel = wmTrafficPanel;
    window.WM = WM;
})();
