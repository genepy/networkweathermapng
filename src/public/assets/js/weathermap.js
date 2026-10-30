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
         * Show notification
         */
        notify: function(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 3000);
        },
        
        /**
         * Confirm dialog
         */
        confirm: function(message) {
            return window.confirm(message);
        }
    };

    function wmCreateHoverPanel() {
        let el = document.getElementById('wm-ds-hover');
        if (el) return el;

        el = document.createElement('div');
        el.id = 'wm-ds-hover';
        el.style.position = 'fixed';
        el.style.zIndex = '9999';
        el.style.pointerEvents = 'none';
        el.style.minWidth = 'min(320px, calc(100vw - 16px))';
        // a traffic graph widens the panel, see wmGraphSize()
        el.style.maxWidth = 'calc(100vw - 16px)';
        el.style.boxSizing = 'border-box';
        el.style.background = 'rgba(15, 18, 24, 0.92)';
        el.style.border = '1px solid rgba(255,255,255,0.12)';
        el.style.borderRadius = '10px';
        el.style.padding = '10px 12px';
        el.style.color = '#e9eefb';
        el.style.fontFamily = 'Arial, sans-serif';
        el.style.fontSize = '12px';
        el.style.boxShadow = '0 10px 30px rgba(0,0,0,0.35)';
        el.style.display = 'none';

        document.body.appendChild(el);
        return el;
    }

    function wmFormatNum(n) {
        if (!isFinite(n)) return '-';
        const abs = Math.abs(n);
        if (abs >= 1e9) return (n / 1e9).toFixed(2) + 'G';
        if (abs >= 1e6) return (n / 1e6).toFixed(2) + 'M';
        if (abs >= 1e3) return (n / 1e3).toFixed(2) + 'K';
        return n.toFixed(2);
    }

    function wmSparkline(series, width, height, color) {
        const pts = Array.isArray(series) ? series : [];
        if (pts.length < 2) {
            return `<svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"></svg>`;
        }

        const values = pts.map(p => Number(p.v) || 0);
        let min = Math.min(...values);
        let max = Math.max(...values);
        if (min === max) {
            max = min + 1;
        }

        const dx = width / (pts.length - 1);
        let d = '';
        for (let i = 0; i < pts.length; i++) {
            const x = i * dx;
            const v = Number(pts[i].v) || 0;
            const y = height - ((v - min) / (max - min)) * height;
            d += (i === 0 ? 'M' : 'L') + x.toFixed(2) + ' ' + y.toFixed(2) + ' ';
        }

        return `<svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
            <path d="${d}" fill="none" stroke="${color}" stroke-width="2" />
        </svg>`;
    }

    async function wmLoadDatasourceHover(selection) {
        const sourceId = Number(selection.sourceId || 0);
        if (!sourceId) {
            throw new Error(t('Invalid data source'));
        }

        const params = new URLSearchParams();
        params.set('selection', JSON.stringify({
            hostId: selection.hostId,
            inKey: selection.inKey,
            outKey: selection.outKey,
        }));
        params.set('minutes', '1440');

        const res = await fetch('/api/data-sources/' + encodeURIComponent(String(sourceId)) + '/link-bandwidth?' + params.toString());
        const data = await res.json();
        if (!res.ok || !data || !data.success) {
            throw new Error((data && data.error) ? data.error : t('Failed to load'));
        }
        return data.data;
    }

    function wmEsc(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
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
     * The hover panel of a link: in/out now and the last 24 h.
     * info: {title, ds (data source selection, JSON text), in, out, inpct,
     * outpct (values from the last map render), graph (picture URL),
     * hint (a line under it, e.g. what a click does)}.
     * Used by the PNG imagemap (area.link) and the SVG view (viewer.js).
     */
    const wmHover = (function() {
        let hover = null;
        let lastKey = null;
        let visible = false;
        let lastPos = null;

        function panel() {
            if (!hover) {
                hover = wmCreateHoverPanel();
                document.addEventListener('mousemove', function(e) {
                    if (!visible) return;
                    wmHover.move(e);
                });
            }
            return hover;
        }

        function nowLine(label, value, pct, colour) {
            if (value === null || value === undefined || !isFinite(Number(value))) return '';
            const p = (pct !== null && pct !== undefined && isFinite(Number(pct))) ? ` <span style="opacity:0.75">(${Math.round(Number(pct))} %)</span>` : '';
            return `<div style="display:flex;gap:10px;align-items:center;margin:4px 0;">
                <div style="width:70px;opacity:0.9;">${label}</div>
                <div style="font-weight:600;color:${colour};">${wmFormatNum(Number(value))}bit/s${p}</div>
            </div>`;
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
            show: async function(info, e) {
                const el = panel();
                const key = JSON.stringify(info);
                if (visible && key === lastKey) return;
                lastKey = key;

                const title = info.title ? `<div style="font-weight:600;margin-bottom:4px;">${wmEsc(info.title)}</div>` : '';
                const now = nowLine(t('IN'), info.in, info.inpct, '#59d185') + nowLine(t('OUT'), info.out, info.outpct, '#6aa7ff');

                let selection = null;
                if (info.ds) {
                    try { selection = JSON.parse(info.ds); } catch (err) { selection = null; }
                }

                if (!selection && !info.graph && !now) {
                    this.hide();
                    return;
                }

                visible = true;
                el.style.display = 'block';
                if (e) this.move(e);

                const hint = info.hint ? `<div style="opacity:0.75;margin-top:6px;">${wmEsc(info.hint)}</div>` : '';

                el.style.width = '';
                if (!selection) {
                    let graph = '';
                    if (info.graph) {
                        const size = wmGraphSize(info.graph);
                        el.style.width = (size.width + 26) + 'px';
                        graph = `<img src="${wmEsc(size.url)}" alt="" style="display:block;width:100%;height:auto;margin-top:6px;border-radius:4px;">`;
                    }
                    el.innerHTML = title + now + graph + hint;
                    const img = el.querySelector('img');
                    if (img) {
                        // the panel grows when the picture arrives: keep it on screen
                        img.addEventListener('load', () => { if (visible && lastPos) this.move(lastPos); });
                        img.addEventListener('error', () => { img.remove(); });
                    }
                    return;
                }

                el.innerHTML = title + now + '<div style="opacity:0.85;margin-top:4px;">' + t('Loading…') + '</div>';

                try {
                    const payload = await wmLoadDatasourceHover(selection);
                    if (lastKey !== key || !visible) return;

                    const inLast = Number(payload?.in?.lastvalue || 0);
                    const outLast = Number(payload?.out?.lastvalue || 0);
                    const inUnits = wmEsc(payload?.in?.units || '');
                    const outUnits = wmEsc(payload?.out?.units || '');

                    const w = 460;
                    const h = 70;
                    const inSvg = wmSparkline(payload?.in?.series || [], w, h, '#59d185');
                    const outSvg = wmSparkline(payload?.out?.series || [], w, h, '#6aa7ff');

                    el.innerHTML = title + `
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:6px;">
                            <div style="font-weight:600;">${t('Link bandwidth (24h)')}</div>
                            <div style="opacity:0.75;">${new Date().toLocaleString()}</div>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center;margin:6px 0;">
                            <div style="width:70px;opacity:0.9;">${t('IN')}</div>
                            <div style="font-weight:600;color:#59d185;">${wmFormatNum(inLast)} ${inUnits}</div>
                        </div>
                        <div>${inSvg}</div>
                        <div style="display:flex;gap:10px;align-items:center;margin:10px 0 6px;">
                            <div style="width:70px;opacity:0.9;">${t('OUT')}</div>
                            <div style="font-weight:600;color:#6aa7ff;">${wmFormatNum(outLast)} ${outUnits}</div>
                        </div>
                        <div>${outSvg}</div>
                    ` + hint;
                } catch (err) {
                    if (lastKey !== key || !visible) return;
                    el.innerHTML = title + now + '<div style="color:#ff8b8b">' + wmEsc(String(err.message || err)) + '</div>';
                }
            },
            hide: function() {
                visible = false;
                lastKey = null;
                if (hover) {
                    hover.style.display = 'none';
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
                wmHover.hide();
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
