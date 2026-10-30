/**
 * NetworkWeathermapNG - zoomable SVG view of a map (/map/{id}).
 *
 * The map comes as SVG from /map/{id}/svg (cached on the server like the
 * PNG). Wheel or pinch zooms at the pointer, drag pans, the toolbar has
 * + / − / Fit / 100 % and a node search; a minimap shows where the view is
 * when the map does not fit. Zoom and position live in the URL hash
 * (#z=2&x=..&y=.., x/y the map point at the centre), so a shared link opens
 * on the same view. The saved map is never changed.
 */
(function () {
    'use strict';

    function t(s) { return (window.WM_T && window.WM_T[s]) || s; }
    function fmt(s, params) {
        return t(s).replace(/:([a-z]+)/g, function (m, k) { return params[k] !== undefined ? params[k] : m; });
    }

    const root = document.getElementById('wmv');
    if (!root) return;

    const viewport = root.querySelector('.wmv-viewport');
    const stage = root.querySelector('.wmv-stage');
    const message = root.querySelector('.wmv-message');
    const zoomLabel = root.querySelector('.wmv-zoom');
    const findForm = root.querySelector('.wmv-find');
    const findInput = root.querySelector('.wmv-find-input');
    const nodeList = document.getElementById('wmv-nodes');
    const minimap = root.querySelector('.wmv-minimap');
    const minimapImg = minimap.querySelector('img');
    const minimapView = minimap.querySelector('.wmv-minimap-view');
    const stateBox = root.querySelector('.wmv-state');

    const mapId = root.dataset.mapId;
    const fullscreen = root.dataset.fullscreen === '1';
    const refreshMs = Math.max(30, parseInt(root.dataset.refresh || '300', 10)) * 1000;
    const svgUrl = '/map/' + mapId + '/svg';
    const versionUrl = '/map/' + mapId + '/version';

    // Below this on-screen size, link bandwidth labels are hidden
    const LABEL_MIN_PX = 7;
    const MIN_Z = 0.05;
    const MAX_Z = 10;

    const S = {
        z: 1, tx: 0, ty: 0,      // screen = t + z * map
        w: 0, h: 0,              // map size
        el: null,                // the <svg> (or the fallback <img>)
        items: {},
        labelFont: 0,            // smallest bandwidth label font size, map units
        touched: false,          // the user moved the view (or the URL said where)
        version: null,
        minimapUrl: null,
        loading: false,
        again: false
    };

    // ------------------------------------------------------------------
    // View geometry

    function vpSize() {
        return {w: viewport.clientWidth || 1, h: viewport.clientHeight || 1};
    }

    function clampZ(z) {
        return Math.min(MAX_Z, Math.max(MIN_Z, z));
    }

    function fitZ() {
        const vp = vpSize();
        if (!S.w || !S.h) return 1;
        return clampZ(Math.min(vp.w / S.w, vp.h / S.h) * 0.98);
    }

    function centreOn(mx, my, z) {
        const vp = vpSize();
        S.z = clampZ(z);
        S.tx = vp.w / 2 - mx * S.z;
        S.ty = vp.h / 2 - my * S.z;
    }

    function viewCentre() {
        const vp = vpSize();
        return {x: (vp.w / 2 - S.tx) / S.z, y: (vp.h / 2 - S.ty) / S.z};
    }

    function zoomAt(z, sx, sy) {
        z = clampZ(z);
        const mx = (sx - S.tx) / S.z;
        const my = (sy - S.ty) / S.z;
        S.z = z;
        S.tx = sx - mx * z;
        S.ty = sy - my * z;
    }

    /** Keep at least part of the map on screen */
    function keepInView() {
        const vp = vpSize();
        const mw = S.w * S.z, mh = S.h * S.z;
        const margin = 40;
        S.tx = Math.min(vp.w - margin, Math.max(margin - mw, S.tx));
        S.ty = Math.min(vp.h - margin, Math.max(margin - mh, S.ty));
    }

    let frame = 0;
    function apply() {
        if (frame) return;
        frame = requestAnimationFrame(function () {
            frame = 0;
            draw();
        });
    }

    function draw() {
        if (!S.el) return;
        keepInView();
        // Resizing the SVG (rather than scaling it with a transform) keeps
        // it sharp at every zoom
        S.el.style.width = (S.w * S.z) + 'px';
        S.el.style.height = (S.h * S.z) + 'px';
        stage.style.transform = 'translate(' + S.tx + 'px,' + S.ty + 'px)';
        zoomLabel.textContent = Math.round(S.z * 100) + ' %';
        root.classList.toggle('wmv-lod-far', S.labelFont > 0 && S.labelFont * S.z < LABEL_MIN_PX);
        drawMinimap();
    }

    // ------------------------------------------------------------------
    // URL state

    let hashTimer = 0;
    function saveHash() {
        clearTimeout(hashTimer);
        hashTimer = setTimeout(function () {
            const base = location.pathname + location.search;
            let hash = '';
            if (S.touched) {
                const c = viewCentre();
                hash = '#z=' + (Math.round(S.z * 1000) / 1000) + '&x=' + Math.round(c.x) + '&y=' + Math.round(c.y);
            }
            history.replaceState(null, '', base + hash);
            const fsLink = document.getElementById('wmv-fullscreen-link');
            if (fsLink) fsLink.href = '/map/' + mapId + '?fullscreen=1' + hash;
            const exit = root.querySelector('[data-act="exit"]');
            if (exit) exit.href = root.dataset.normalUrl + hash;
        }, 250);
    }

    function readHash() {
        const p = new URLSearchParams(location.hash.replace(/^#/, ''));
        const z = parseFloat(p.get('z') || '');
        const x = parseFloat(p.get('x') || '');
        const y = parseFloat(p.get('y') || '');
        if (!isFinite(z) || !isFinite(x) || !isFinite(y) || z <= 0) return null;
        return {z: z, x: x, y: y};
    }

    function initialView() {
        const h = readHash();
        if (h) {
            S.touched = true;
            centreOn(h.x, h.y, h.z);
        } else {
            // On the page, never enlarge a small map; in fullscreen, fill the screen
            centreOn(S.w / 2, S.h / 2, fullscreen ? fitZ() : Math.min(1, fitZ()));
        }
    }

    function userMoved() {
        S.touched = true;
        apply();
        saveHash();
    }

    // ------------------------------------------------------------------
    // Animation (search, buttons)

    let anim = 0;
    function animateTo(z, mx, my) {
        cancelAnimationFrame(anim);
        const from = {z: S.z, c: viewCentre()};
        const to = {z: clampZ(z), x: mx, y: my};
        const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const start = performance.now();
        const dur = reduce ? 0 : 260;
        function step(now) {
            const k = dur ? Math.min(1, (now - start) / dur) : 1;
            const e = k < 0.5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
            // zoom geometrically, so the movement looks even
            const zz = from.z * Math.pow(to.z / from.z, e);
            centreOn(from.c.x + (to.x - from.c.x) * e, from.c.y + (to.y - from.c.y) * e, zz);
            draw();
            if (k < 1) anim = requestAnimationFrame(step);
        }
        anim = requestAnimationFrame(step);
    }

    function zoomBy(factor) {
        const c = viewCentre();
        animateTo(S.z * factor, c.x, c.y);
        S.touched = true;
        saveHash();
    }

    function fit() {
        animateTo(fitZ(), S.w / 2, S.h / 2);
        S.touched = false;
        saveHash();
    }

    function actualSize() {
        const c = viewCentre();
        animateTo(1, c.x, c.y);
        S.touched = true;
        saveHash();
    }

    // ------------------------------------------------------------------
    // Loading

    function showMessage(text, isError) {
        message.textContent = text;
        message.hidden = !text;
        message.classList.toggle('wmv-error', !!isError);
    }

    function itemOf(target) {
        const g = target && target.closest ? target.closest('g.wm-link, g.wm-node') : null;
        if (!g || !stage.contains(g)) return null;
        return {g: g, data: S.items[g.getAttribute('data-id')] || null};
    }

    function install(el, w, h) {
        const first = !S.el;
        stage.replaceChildren(el);
        S.el = el;
        S.w = w;
        S.h = h;
        if (first) initialView();
        draw();
    }

    function installSvg(text, items) {
        const tpl = document.createElement('template');
        tpl.innerHTML = text;
        const svg = tpl.content.querySelector('svg');
        if (!svg) throw new Error('no svg');
        const w = parseFloat(svg.getAttribute('width')) || 800;
        const h = parseFloat(svg.getAttribute('height')) || 600;
        svg.setAttribute('preserveAspectRatio', 'xMinYMin meet');
        svg.classList.add('wmv-svg');

        S.items = items || {};
        let font = 0;
        svg.querySelectorAll('text.wm-bwlabel-in, text.wm-bwlabel-out').forEach(function (n) {
            const f = parseFloat(n.getAttribute('font-size'));
            if (f > 0 && (!font || f < font)) font = f;
        });
        S.labelFont = font;

        svg.querySelectorAll('g.wm-link, g.wm-node').forEach(function (g) {
            const d = S.items[g.getAttribute('data-id')];
            if (d && d.url) g.classList.add('wmv-clickable');
            if (g.classList.contains('wm-link') && d) g.classList.add('wmv-hoverable');
        });

        install(svg, w, h);
        fillNodeList(svg);
        updateMinimapImage(text);
    }

    function fallbackToPng() {
        const img = new Image();
        img.alt = '';
        img.className = 'wmv-png';
        img.onload = function () {
            install(img, img.naturalWidth, img.naturalHeight);
            showMessage(t('The zoomable view is not available, showing the picture.'), true);
            setTimeout(function () { showMessage(''); }, 6000);
        };
        img.onerror = function () { showMessage(t('The map could not be loaded.'), true); };
        img.src = root.dataset.pngUrl + '?t=' + Date.now();
    }

    function load() {
        if (S.loading) {
            S.again = true;
            return;
        }
        S.loading = true;
        if (!S.el) showMessage(t('Loading the map…'));
        fetch(svgUrl, {cache: 'no-store', credentials: 'same-origin'})
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                installSvg(data.svg, data.items);
                showState(data.status);
                if (data.version) S.version = data.version;
                showMessage('');
            })
            .catch(function () {
                if (!S.el) fallbackToPng();
            })
            .finally(function () {
                S.loading = false;
                if (S.again) {
                    S.again = false;
                    load();
                }
            });
    }

    // Time of a render, in the map's time zone (the browser's own if it does not know it)
    function formatTime(seconds) {
        const options = {hour: '2-digit', minute: '2-digit'};
        if (root.dataset.timezone) options.timeZone = root.dataset.timezone;
        try {
            return new Date(seconds * 1000).toLocaleTimeString([], options);
        } catch (e) {
            delete options.timeZone;
            return new Date(seconds * 1000).toLocaleTimeString([], options);
        }
    }

    function showState(status) {
        if (!stateBox || !status) return;
        const dot = stateBox.querySelector('.wmv-dot');
        const text = stateBox.querySelector('.wmv-state-text');
        stateBox.hidden = false;
        stateBox.className = 'wmv-state wmv-level-' + (status.level || 'none');
        dot.style.background = status.colour || '';
        const when = status.time ? formatTime(status.time) : '';
        if (status.max === null || status.max === undefined) {
            text.textContent = t('No data');
            stateBox.title = when ? fmt('Updated at :time', {time: when}) : '';
        } else {
            text.textContent = fmt('Max :pct %', {pct: Math.round(status.max)});
            stateBox.title = fmt('Most loaded link: :link (:pct %)', {link: status.link || '', pct: status.max})
                + (when ? ' — ' + fmt('Updated at :time', {time: when}) : '');
        }
    }

    // ------------------------------------------------------------------
    // Minimap

    function updateMinimapImage(svgText) {
        try {
            if (S.minimapUrl) URL.revokeObjectURL(S.minimapUrl);
            S.minimapUrl = URL.createObjectURL(new Blob([svgText], {type: 'image/svg+xml'}));
            minimapImg.src = S.minimapUrl;
        } catch (e) {
            minimapImg.removeAttribute('src');
        }
    }

    function minimapScale() {
        const maxW = 180, maxH = 130;
        return Math.min(maxW / S.w, maxH / S.h);
    }

    function drawMinimap() {
        const vp = vpSize();
        const overflow = S.w * S.z > vp.w + 2 || S.h * S.z > vp.h + 2;
        const show = overflow && S.w > 0 && vp.w > 420;
        minimap.hidden = !show;
        if (!show) return;
        const k = minimapScale();
        minimap.style.width = (S.w * k) + 'px';
        minimap.style.height = (S.h * k) + 'px';
        let x0 = -S.tx / S.z, y0 = -S.ty / S.z;
        let x1 = x0 + vp.w / S.z, y1 = y0 + vp.h / S.z;
        x0 = Math.max(0, x0); y0 = Math.max(0, y0);
        x1 = Math.min(S.w, x1); y1 = Math.min(S.h, y1);
        minimapView.style.left = (x0 * k) + 'px';
        minimapView.style.top = (y0 * k) + 'px';
        minimapView.style.width = Math.max(2, (x1 - x0) * k) + 'px';
        minimapView.style.height = Math.max(2, (y1 - y0) * k) + 'px';
    }

    function minimapGoto(e) {
        const r = minimap.getBoundingClientRect();
        const k = minimapScale();
        centreOn((e.clientX - r.left) / k, (e.clientY - r.top) / k, S.z);
        userMoved();
    }

    let mmDrag = false;
    minimap.addEventListener('pointerdown', function (e) {
        mmDrag = true;
        minimap.setPointerCapture(e.pointerId);
        minimapGoto(e);
        e.preventDefault();
    });
    minimap.addEventListener('pointermove', function (e) { if (mmDrag) minimapGoto(e); });
    minimap.addEventListener('pointerup', function () { mmDrag = false; });
    minimap.addEventListener('pointercancel', function () { mmDrag = false; });

    // ------------------------------------------------------------------
    // Wheel, drag, pinch

    viewport.addEventListener('wheel', function (e) {
        if (!S.el) return;
        e.preventDefault();
        const r = viewport.getBoundingClientRect();
        const unit = e.deltaMode === 1 ? 40 : (e.deltaMode === 2 ? 400 : 1);
        const k = e.ctrlKey ? 0.01 : 0.0018;   // ctrl+wheel is a trackpad pinch
        zoomAt(S.z * Math.exp(-e.deltaY * unit * k), e.clientX - r.left, e.clientY - r.top);
        cancelAnimationFrame(anim);
        userMoved();
    }, {passive: false});

    const pointers = new Map();
    let dragging = false;
    let suppressClick = false;
    let downAt = null;
    let pinch = null;

    function local(e) {
        const r = viewport.getBoundingClientRect();
        return {x: e.clientX - r.left, y: e.clientY - r.top};
    }

    function pinchInfo() {
        const pts = Array.from(pointers.values());
        const a = pts[0], b = pts[1];
        return {d: Math.hypot(a.x - b.x, a.y - b.y) || 1, x: (a.x + b.x) / 2, y: (a.y + b.y) / 2};
    }

    viewport.addEventListener('pointerdown', function (e) {
        if (e.button !== 0 || !S.el) return;
        pointers.set(e.pointerId, local(e));
        cancelAnimationFrame(anim);
        if (pointers.size === 1) {
            downAt = local(e);
            dragging = false;
        } else if (pointers.size === 2) {
            pinch = pinchInfo();
            dragging = true;
            viewport.setPointerCapture(e.pointerId);
        }
    });

    viewport.addEventListener('pointermove', function (e) {
        if (!pointers.has(e.pointerId)) return;
        const prev = pointers.get(e.pointerId);
        const cur = local(e);
        pointers.set(e.pointerId, cur);

        if (pointers.size >= 2 && pinch) {
            const now = pinchInfo();
            zoomAt(S.z * now.d / pinch.d, now.x, now.y);
            S.tx += now.x - pinch.x;
            S.ty += now.y - pinch.y;
            pinch = now;
            userMoved();
            return;
        }

        if (!dragging && downAt && Math.hypot(cur.x - downAt.x, cur.y - downAt.y) > 4) {
            dragging = true;
            root.classList.add('wmv-dragging');
            viewport.setPointerCapture(e.pointerId);
            hidePanel();
        }
        if (dragging) {
            S.tx += cur.x - prev.x;
            S.ty += cur.y - prev.y;
            userMoved();
        }
    });

    function endPointer(e) {
        if (!pointers.has(e.pointerId)) return;
        pointers.delete(e.pointerId);
        if (pointers.size < 2) pinch = null;
        if (pointers.size === 0) {
            if (dragging) suppressClick = true;
            dragging = false;
            downAt = null;
            root.classList.remove('wmv-dragging');
        } else if (pointers.size === 1) {
            downAt = Array.from(pointers.values())[0];
        }
    }
    viewport.addEventListener('pointerup', endPointer);
    viewport.addEventListener('pointercancel', endPointer);

    // ------------------------------------------------------------------
    // Hover panel and clicks on the map items

    // On a touch screen there is no hover: the first tap on a link shows
    // its panel (traffic graph), a second tap opens its page
    let hovered = null;
    let touch = false;
    let tapped = null;

    viewport.addEventListener('pointerdown', function (e) {
        touch = e.pointerType === 'touch' || e.pointerType === 'pen';
    }, true);

    function showPanel(g, d, e) {
        if (!window.WM || !WM.hover) return false;
        let hint = '';
        if (d.url) hint = touch ? t('Tap again to open its page in a new tab') : t('Click to open its page in a new tab');
        WM.hover.show({
            // the traffic graph, when there is one, rather than the sparklines
            title: d.name, ds: d.graph ? '' : (d.ds || ''), in: d.in, out: d.out,
            inpct: d.inpct, outpct: d.outpct, graph: d.graph || '', hint: hint
        }, e);
        return true;
    }

    function hidePanel() {
        hovered = null;
        tapped = null;
        if (window.WM && WM.hover) WM.hover.hide();
    }

    viewport.addEventListener('mouseover', function (e) {
        if (dragging || touch) return;
        const it = itemOf(e.target);
        const g = it && it.g.classList.contains('wm-link') ? it.g : null;
        // a link and its labels are two groups with the same id
        const id = g ? g.getAttribute('data-id') : null;
        if (id === (hovered ? hovered.getAttribute('data-id') : null)) return;
        hovered = g;
        if (!g || !it.data) {
            if (window.WM && WM.hover) WM.hover.hide();
            return;
        }
        showPanel(g, it.data, e);
    });
    viewport.addEventListener('mouseleave', function () {
        if (!touch) hidePanel();
    });

    viewport.addEventListener('click', function (e) {
        if (suppressClick) {
            suppressClick = false;
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        const it = itemOf(e.target);
        if (touch) {
            const link = it && it.data && it.g.classList.contains('wm-link') ? it.g : null;
            if (link && (!tapped || link.getAttribute('data-id') !== tapped.getAttribute('data-id'))) {
                hidePanel();
                if (showPanel(link, it.data, e)) {
                    tapped = link;
                    return;
                }
            }
            if (!link) hidePanel();
        }
        if (it && it.data && it.data.url) {
            window.open(it.data.url, '_blank', 'noopener');
        }
    }, true);

    // ------------------------------------------------------------------
    // Find a node

    /** Nodes by what people know them by: their label, and their name */
    function nodeEntries(svg) {
        return Array.from(svg.querySelectorAll('g.wm-node[data-name]')).map(function (g) {
            const texts = g.querySelectorAll('text');
            // the last text is the label itself (a shadow comes first)
            const label = texts.length ? texts[texts.length - 1].textContent.trim() : '';
            const name = g.getAttribute('data-name');
            return {g: g, label: label || name, name: name};
        });
    }

    function fillNodeList(svg) {
        const seen = {};
        const options = [];
        nodeEntries(svg).sort(function (a, b) { return a.label.localeCompare(b.label); }).forEach(function (n) {
            if (seen[n.label]) return;
            seen[n.label] = 1;
            const o = document.createElement('option');
            o.value = n.label;
            if (n.name !== n.label) o.label = n.name;
            options.push(o);
        });
        nodeList.replaceChildren.apply(nodeList, options);
    }

    function findNode(query) {
        query = query.trim().toLowerCase();
        if (!query || !S.el || !S.el.querySelectorAll) return null;
        const nodes = nodeEntries(S.el);
        const tests = [
            function (s) { return s === query; },
            function (s) { return s.indexOf(query) === 0; },
            function (s) { return s.indexOf(query) !== -1; }
        ];
        for (let i = 0; i < tests.length; i++) {
            const hit = nodes.find(function (n) { return tests[i](n.label.toLowerCase()) || tests[i](n.name.toLowerCase()); });
            if (hit) return hit.g;
        }
        return null;
    }

    function goToNode(query) {
        const g = findNode(query);
        if (!g) {
            showMessage(fmt('No node matches “:q”', {q: query.trim()}), true);
            setTimeout(function () { showMessage(''); }, 2500);
            return;
        }
        let box;
        try { box = g.getBBox(); } catch (e) { return; }
        const vp = vpSize();
        let z = Math.max(S.z, 1.5);
        if (box.width > 0 && box.height > 0) {
            z = Math.min(z, 0.5 * vp.w / box.width, 0.5 * vp.h / box.height);
        }
        animateTo(z, box.x + box.width / 2, box.y + box.height / 2);
        S.touched = true;
        saveHash();
        S.el.querySelectorAll('.wmv-found').forEach(function (n) { n.classList.remove('wmv-found'); });
        g.classList.add('wmv-found');
        setTimeout(function () { g.classList.remove('wmv-found'); }, 2400);
    }

    findForm.addEventListener('submit', function (e) {
        e.preventDefault();
        goToNode(findInput.value);
    });
    findInput.addEventListener('change', function () {
        // picking a name from the list
        if (findInput.value && findNode(findInput.value)) goToNode(findInput.value);
    });

    // ------------------------------------------------------------------
    // Toolbar and keyboard

    root.querySelectorAll('[data-act]').forEach(function (b) {
        b.addEventListener('click', function (e) {
            switch (b.dataset.act) {
                case 'in': zoomBy(1.4); break;
                case 'out': zoomBy(1 / 1.4); break;
                case 'fit': fit(); break;
                case 'actual': actualSize(); break;
                default: return;
            }
            e.preventDefault();
        });
    });

    document.addEventListener('keydown', function (e) {
        const tag = (e.target && e.target.tagName) || '';
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || e.ctrlKey || e.metaKey || e.altKey) {
            if (e.key === 'Escape' && e.target === findInput) findInput.blur();
            return;
        }
        // On the page, the keys act only while the map has the focus
        if (!fullscreen && !root.contains(document.activeElement)) return;
        const step = 60;
        switch (e.key) {
            case '+': case '=': zoomBy(1.4); break;
            case '-': case '_': zoomBy(1 / 1.4); break;
            case '0': fit(); break;
            case '1': actualSize(); break;
            case 'ArrowLeft': S.tx += step; userMoved(); break;
            case 'ArrowRight': S.tx -= step; userMoved(); break;
            case 'ArrowUp': S.ty += step; userMoved(); break;
            case 'ArrowDown': S.ty -= step; userMoved(); break;
            case '/': findInput.focus(); break;
            case 'Escape':
                if (fullscreen) {
                    window.location.href = root.dataset.normalUrl + location.hash;
                }
                return;
            default: return;
        }
        e.preventDefault();
    });

    // ------------------------------------------------------------------
    // Window size, fullscreen idle toolbar

    let lastVp = vpSize();
    window.addEventListener('resize', function () {
        if (!S.el) return;
        const c = {x: (lastVp.w / 2 - S.tx) / S.z, y: (lastVp.h / 2 - S.ty) / S.z};
        lastVp = vpSize();
        if (!S.touched) {
            centreOn(S.w / 2, S.h / 2, fullscreen ? fitZ() : Math.min(1, fitZ()));
        } else {
            centreOn(c.x, c.y, S.z);
        }
        apply();
    });

    if (fullscreen) {
        let idle = 0;
        const wake = function () {
            root.classList.remove('wmv-idle');
            clearTimeout(idle);
            idle = setTimeout(function () {
                if (document.activeElement !== findInput) root.classList.add('wmv-idle');
            }, 3000);
        };
        ['mousemove', 'pointerdown', 'keydown', 'wheel'].forEach(function (ev) {
            document.addEventListener(ev, wake, {passive: true});
        });
        wake();
    }

    // ------------------------------------------------------------------
    // Refresh: new data every refresh interval, right away after an edit

    function checkVersion() {
        if (document.hidden) return;
        fetch(versionUrl, {cache: 'no-store', credentials: 'same-origin'})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.version) return;
                if (S.version !== null && data.version !== S.version) load();
                S.version = data.version;
            })
            .catch(function () {});
    }

    load();
    setInterval(function () { if (!document.hidden) load(); }, refreshMs);
    setInterval(checkVersion, 10000);
    document.addEventListener('visibilitychange', checkVersion);
})();
