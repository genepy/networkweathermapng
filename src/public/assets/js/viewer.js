/**
 * NetworkWeathermapNG - zoomable SVG view of a map (/map/{id}).
 *
 * The map comes as SVG from /map/{id}/svg (cached on the server like the
 * PNG). Wheel or pinch zooms at the pointer, drag pans. Floating controls
 * over the map: the bar at the top (node search, Live / recorded hours,
 * fullscreen, more actions, Edit), the zoom at the bottom right, the state
 * and the colour legend at the bottom left; a minimap shows where the view
 * is when the map does not fit. In fullscreen the controls fade out after
 * a few seconds without movement. Zoom and position live in the URL hash
 * (#z=2&x=..&y=.., x/y the map point at the centre), so a shared link opens
 * on the same view. Clicking a node (or finding it) zooms onto it and
 * pins its card: its links with their load, the nodes at their other end,
 * its page; the rest of the map dims and the minimap frames it (#n=<node>
 * in the hash). Hovering a node frames it in the minimap too. A side
 * panel on the left (P, remembered per browser) lists the nodes grouped by
 * site (SET site), the total traffic of the map with a graph of the
 * recorded hours, and the colour legend; hovering a node on the map
 * highlights it in the list, hovering it in the list frames it on the map
 * and in the minimap. The saved map is never changed.
 * With ?kiosk=1 the page is the bare map that the kiosk (kiosk.js) shows in
 * a frame: fitted (or at the kiosk's fixed zoom), no toolbar, no keys.
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
    const statusBox = root.querySelector('.wmv-status');
    const badge = root.querySelector('.wmv-badge');
    const fsButton = root.querySelector('[data-act="fullscreen"]');
    const card = root.querySelector('.wmv-card');
    const minimapMark = minimap.querySelector('.wmv-minimap-mark');
    const panel = root.querySelector('.wmv-panel');
    const panelButton = root.querySelector('[data-act="panel"]');
    const basemapBox = root.querySelector('.wmv-basemap');
    const clusterBox = root.querySelector('.wmv-clusters');
    const insetBox = root.querySelector('.wmv-inset');

    const mapId = root.dataset.mapId;
    // the fullscreen page (/map/{id}?fullscreen=1, the kiosk link)
    const standalone = root.dataset.fullscreen === '1';
    // a map of the kiosk (?kiosk=1): the kiosk page around it keeps the keys
    const kioskFrame = root.dataset.kiosk === '1';
    const kioskZoom = (parseInt(root.dataset.zoom || '0', 10) || 0) / 100;
    function isFullscreen() {
        return standalone || document.fullscreenElement === root;
    }
    const refreshMs = Math.max(30, parseInt(root.dataset.refresh || '300', 10)) * 1000;
    const svgUrl = '/map/' + mapId + '/svg';
    const versionUrl = '/map/' + mapId + '/version';

    // Below this on-screen size, link bandwidth labels are hidden
    const LABEL_MIN_PX = 7;
    const MIN_Z = 0.05;
    const MAX_Z = 10;
    // on a basemap, zoom in further: 64 is six web map zoom levels
    const MAX_Z_BASEMAP = 64;
    // above this size on the screen, the SVG covers only what is in view
    const MAX_SVG_PX = 4096;

    const S = {
        z: 1, tx: 0, ty: 0,      // screen = t + z * map
        w: 0, h: 0,              // map size
        el: null,                // the <svg> (or the fallback <img>)
        items: {},
        labelFont: 0,            // smallest bandwidth label font size, map units
        touched: false,          // the user moved the view (or the URL said where)
        version: null,
        minimapUrl: null,
        focus: null,             // name of the node whose card is pinned
        focusZoom: false,        // zoom onto it when the map comes (#n= without a view)
        ends: {},                // link id -> {a, b} node names (kept across frames)
        sites: {},               // node name -> its site (SET site), for the hover card
        loading: false,
        again: false,
        playOnLoad: false        // open the playback once the map is drawn (#play)
    };

    // the geographic basemap under the map (basemap.js), when the map has one
    const B = {inst: null, cfg: null};
    // located nodes on the basemap (SET geo), their clusters and the city inset
    const G = {
        nodes: [], byName: {},   // {name, g, lat, lon, x, y, size, site}
        clusters: [], key: null, z: 0,
        menu: null,              // the list of a cluster that cannot split
        inset: null, insetKey: null, hover: false, timer: 0
    };

    // ------------------------------------------------------------------
    // View geometry

    function vpSize() {
        return {w: viewport.clientWidth || 1, h: viewport.clientHeight || 1};
    }

    function clampZ(z) {
        return Math.min(B.cfg ? MAX_Z_BASEMAP : MAX_Z, Math.max(MIN_Z, z));
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
        // it sharp at every zoom. The SVG only covers the part of the map
        // on the screen (its viewBox): deep in a zoom, an element tens of
        // thousands of pixels wide goes past what the graphics card draws
        // in one piece, and seams flicker across the view. A smaller map
        // stays whole, so that moving it does not draw it again.
        let x0 = 0, y0 = 0, x1 = S.w, y1 = S.h;
        if (S.el instanceof SVGSVGElement && Math.max(S.w, S.h) * S.z > MAX_SVG_PX) {
            const vp = vpSize();
            x0 = Math.max(0, Math.floor(-S.tx / S.z));
            y0 = Math.max(0, Math.floor(-S.ty / S.z));
            x1 = Math.max(x0 + 1, Math.min(S.w, Math.ceil((vp.w - S.tx) / S.z)));
            y1 = Math.max(y0 + 1, Math.min(S.h, Math.ceil((vp.h - S.ty) / S.z)));
        }
        if (S.el instanceof SVGSVGElement) S.el.setAttribute('viewBox', x0 + ' ' + y0 + ' ' + (x1 - x0) + ' ' + (y1 - y0));
        S.el.style.width = ((x1 - x0) * S.z) + 'px';
        S.el.style.height = ((y1 - y0) * S.z) + 'px';
        stage.style.transform = 'translate(' + (S.tx + x0 * S.z) + 'px,' + (S.ty + y0 * S.z) + 'px)';
        zoomLabel.textContent = Math.round(S.z * 100) + ' %';
        if (B.inst) B.inst.sync(S.z, S.tx, S.ty, S.w, S.h);
        updateGeo();
        root.classList.toggle('wmv-lod-far', S.labelFont > 0 && S.labelFont * S.z < LABEL_MIN_PX);
        drawMinimap();
    }

    // ------------------------------------------------------------------
    // URL state

    let hashTimer = 0;
    function saveHash() {
        clearTimeout(hashTimer);
        if (kioskFrame) return;
        hashTimer = setTimeout(function () {
            const base = location.pathname + location.search;
            let hash = '';
            if (S.touched) {
                const c = viewCentre();
                hash = '#z=' + (Math.round(S.z * 1000) / 1000) + '&x=' + Math.round(c.x) + '&y=' + Math.round(c.y);
            }
            if (S.focus !== null) hash += (hash ? '&' : '#') + 'n=' + encodeURIComponent(S.focus);
            history.replaceState(null, '', base + hash);
            const exit = root.querySelector('[data-act="exit"]');
            if (exit) exit.href = root.dataset.normalUrl + hash;
        }, 250);
    }

    function readHash() {
        const p = new URLSearchParams(location.hash.replace(/^#/, ''));
        const z = parseFloat(p.get('z') || '');
        const x = parseFloat(p.get('x') || '');
        const y = parseFloat(p.get('y') || '');
        if (p.get('n') && S.focus === null) S.focus = p.get('n');
        if (p.has('play')) S.playOnLoad = true;
        if (!isFinite(z) || !isFinite(x) || !isFinite(y) || z <= 0) {
            // a link to a node without a view: zoom onto it once it is drawn
            S.focusZoom = S.focus !== null;
            return null;
        }
        return {z: z, x: x, y: y};
    }

    function initialView() {
        if (kioskFrame) {
            centreOn(S.w / 2, S.h / 2, kioskZoom > 0 ? clampZ(kioskZoom) : fitZ());
            return;
        }
        const h = readHash();
        if (h) {
            S.touched = true;
            centreOn(h.x, h.y, h.z);
        } else {
            // On the page, never enlarge a small map; in fullscreen, fill the screen
            centreOn(S.w / 2, S.h / 2, isFullscreen() ? fitZ() : Math.min(1, fitZ()));
            besidePanel();
        }
    }

    /** Move the map right, as far as the window allows, so the open side panel does not cover it */
    function besidePanel() {
        if (!panel || panel.hidden || vpSize().w <= 640) return;
        const room = panel.offsetLeft + panel.offsetWidth + 8 - S.tx;
        const spare = vpSize().w - (S.tx + S.w * S.z);
        if (room > 0) S.tx += Math.max(0, Math.min(room, spare));
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
        S.sites = {};
        Object.keys(S.items).forEach(function (id) {
            const d = S.items[id];
            if (d && d.kind === 'link' && typeof d.a === 'string' && typeof d.b === 'string') S.ends[id] = {a: d.a, b: d.b};
            // the site of each node, for the hover card of its links
            if (d && d.kind === 'node' && typeof d.site === 'string') S.sites[d.name] = d.site;
        });
        let font = 0;
        svg.querySelectorAll('text.wm-bwlabel-in, text.wm-bwlabel-out').forEach(function (n) {
            const f = parseFloat(n.getAttribute('font-size'));
            if (f > 0 && (!font || f < font)) font = f;
        });
        S.labelFont = font;
        // the located nodes of the previous drawing are gone
        G.nodes = [];
        G.byName = {};

        svg.querySelectorAll('g.wm-link, g.wm-node').forEach(function (g) {
            const d = S.items[g.getAttribute('data-id')];
            if (d && d.url) g.classList.add('wmv-clickable');
            if (g.classList.contains('wm-link') && d) g.classList.add('wmv-hoverable');
        });

        install(svg, w, h);
        fillNodeList(svg);
        updateMinimapImage(text);
        setupFlow(svg);
        setupLinks(svg);
        setupGeo();
        refocus();
        fillSites();
    }

    // ---- Link width deep in a basemap ----
    // Located nodes keep their size at 100 % when zooming in (see below);
    // the links do the same, or a few zoom levels down they would cover
    // the city with huge arrows. Each arrow of the SVG carries its spine,
    // its half width, the half width and the length of its head
    // (data-spine, data-w, data-aw, data-as): it is drawn again from them
    // at 1 / zoom of its width. Its outline stays one pixel wide.
    const L = {arrows: [], k: 1};

    function setupLinks(svg) {
        L.arrows = [];
        L.k = 1;
        root.classList.remove('wmv-links-thin');
        svg.querySelectorAll('polygon[data-spine]').forEach(function (el) {
            const nums = (el.getAttribute('data-spine').match(/-?\d+(?:\.\d+)?(?:e-?\d+)?/g) || []).map(Number);
            const pts = [];
            for (let i = 0; i + 1 < nums.length; i += 2) pts.push([nums[i], nums[i + 1]]);
            const w = parseFloat(el.getAttribute('data-w'));
            if (pts.length < 2 || !(w > 0)) return;
            const cut = (el.getAttribute('data-cut') || '').split(' ').map(Number);
            L.arrows.push({
                el: el, pts: pts, points: el.getAttribute('points'), w: w,
                aw: parseFloat(el.getAttribute('data-aw')) || w,
                as: parseFloat(el.getAttribute('data-as')) || 0,
                cut: [cut[0] > 0 ? cut[0] : 0, cut[1] > 0 ? cut[1] : 0]
            });
        });
    }

    /** The outline of an arrow along pts: body of half width w, head of half width aw and length as */
    function arrowOutline(pts, w, aw, as) {
        const d = [0];
        for (let i = 1; i < pts.length; i++) d.push(d[i - 1] + Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]));
        const len = d[d.length - 1];
        if (len <= 0) return null;
        as = Math.min(as, len / 1.2);
        const at = len - as;
        // the spine up to where the head starts
        const body = [pts[0]];
        let i = 1;
        while (i < pts.length - 1 && d[i] < at) body.push(pts[i++]);
        const a = pts[i - 1], b = pts[i];
        const f = d[i] > d[i - 1] ? (at - d[i - 1]) / (d[i] - d[i - 1]) : 0;
        const base = [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f];
        if (Math.hypot(base[0] - body[body.length - 1][0], base[1] - body[body.length - 1][1]) > 0.01 || body.length < 2) body.push(base);
        if (body.length < 2) return null;
        function normal(p, q) {
            const l = Math.hypot(q[0] - p[0], q[1] - p[1]) || 1;
            return [(q[1] - p[1]) / l, -(q[0] - p[0]) / l];
        }
        const left = [], right = [];
        for (let j = 0; j < body.length; j++) {
            const n1 = normal(body[Math.max(0, j - 1)], body[Math.max(1, j)]);
            const n2 = normal(body[Math.min(j, body.length - 2)], body[Math.min(j + 1, body.length - 1)]);
            let nx = n1[0] + n2[0], ny = n1[1] + n2[1];
            const nl = Math.hypot(nx, ny);
            if (nl < 1e-6) { nx = n1[0]; ny = n1[1]; } else { nx /= nl; ny /= nl; }
            // a mitred corner, not too long on a sharp turn
            const m = w / Math.max(0.25, nx * n1[0] + ny * n1[1]);
            const p = body[j];
            left.push([p[0] + nx * m, p[1] + ny * m]);
            right.push([p[0] - nx * m, p[1] - ny * m]);
        }
        const tip = pts[pts.length - 1];
        const hn = normal(base, tip);
        const out = left.concat([
            [base[0] + hn[0] * aw, base[1] + hn[1] * aw],
            tip,
            [base[0] - hn[0] * aw, base[1] - hn[1] * aw]
        ], right.reverse());
        return out.map(function (p) { return p[0].toFixed(3) + ',' + p[1].toFixed(3); }).join(' ');
    }

    /**
     * The engine stops each link where its end is just hidden under its
     * node (data-cut: the length cut at the start and at the tip). A
     * thinner link drawn under a node shrunk as much ends that much closer
     * to the centre, or it would show between the node and its old edge.
     */
    function reachNodes(pts, cut, k) {
        if (!(cut[0] > 0) && !(cut[1] > 0)) return pts;
        function stretch(p, q, d) {
            const l = Math.hypot(p[0] - q[0], p[1] - q[1]);
            return l > 0 ? [p[0] + (p[0] - q[0]) / l * d, p[1] + (p[1] - q[1]) / l * d] : p;
        }
        const out = pts.slice();
        if (cut[0] > 0) out[0] = stretch(pts[0], pts[1], cut[0] * (1 - k));
        if (cut[1] > 0) out[out.length - 1] = stretch(pts[pts.length - 1], pts[pts.length - 2], cut[1] * (1 - k));
        return out;
    }

    /** Draw the arrows at k times their width (1: as the SVG has them) */
    function thinLinks(k) {
        k = Math.round(k * 1000) / 1000;
        if (k === L.k) return;
        L.k = k;
        root.classList.toggle('wmv-links-thin', k < 1);
        L.arrows.forEach(function (a) {
            const pts = k < 1 ? arrowOutline(reachNodes(a.pts, a.cut, k), a.w * k, a.aw * k, a.as * k) : null;
            a.el.setAttribute('points', pts || a.points);
        });
    }

    // ---- Traffic flow (SET link_flow 1) ----
    // The SVG carries an invisible path per direction of each link
    // (path.wm-flow, from where the traffic leaves to the arrow head) and
    // its load in %. Glowing dots run along it, faster on busier links.
    // No dots under prefers-reduced-motion; paused while the tab is hidden.
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const reduceMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const F = {dots: [], frame: 0, t0: performance.now()};

    function flowPoints(d) {
        const nums = (d.match(/-?\d+(?:\.\d+)?/g) || []).map(Number);
        const pts = [];
        let len = 0;
        for (let i = 0; i + 1 < nums.length; i += 2) {
            const x = nums[i], y = nums[i + 1];
            if (pts.length) {
                const p = pts[pts.length - 1];
                len += Math.hypot(x - p[0], y - p[1]);
            }
            pts.push([x, y, len]);
        }
        return pts;
    }

    function flowGradient(svg) {
        const defs = document.createElementNS(SVG_NS, 'defs');
        const g = document.createElementNS(SVG_NS, 'radialGradient');
        g.id = 'wmv-flow-glow';
        [['0', '1'], ['0.3', '0.9'], ['0.6', '0.35'], ['1', '0']].forEach(function (s) {
            const stop = document.createElementNS(SVG_NS, 'stop');
            stop.setAttribute('offset', s[0]);
            stop.setAttribute('stop-color', '#fff');
            stop.setAttribute('stop-opacity', s[1]);
            g.appendChild(stop);
        });
        defs.appendChild(g);
        svg.insertBefore(defs, svg.firstChild);
    }

    function setupFlow(svg) {
        stopFlow();
        F.dots = [];
        const paths = svg.querySelectorAll('path.wm-flow');
        if (!paths.length) return;
        flowGradient(svg);
        paths.forEach(function (path, k) {
            const load = parseFloat(path.getAttribute('data-load')) || 0;
            if (load <= 0) return;
            const pts = flowPoints(path.getAttribute('d') || '');
            const len = pts.length ? pts[pts.length - 1][2] : 0;
            if (len < 12) return;
            const width = parseFloat(path.getAttribute('data-width')) || 4;
            // a short comet, stretched along the link
            const r = Math.max(3, Math.min(10, width * 0.8));
            // px per second: a quiet link crawls, a full one runs
            const speed = 18 + 1.6 * Math.min(load, 100);
            const count = Math.max(1, Math.round(len / 160));
            let after = path;
            for (let i = 0; i < count; i++) {
                const dot = document.createElementNS(SVG_NS, 'ellipse');
                dot.setAttribute('class', 'wmv-flow-dot');
                dot.setAttribute('rx', String(r * 2));
                dot.setAttribute('ry', String(r));
                dot.setAttribute('fill', 'url(#wmv-flow-glow)');
                dot.setAttribute('pointer-events', 'none');
                after.after(dot);
                after = dot;
                F.dots.push({el: dot, pts: pts, len: len, speed: speed, phase: (i / count + k * 0.618) % 1, at: 1});
            }
        });
        startFlow();
    }

    function flowAt(dot, d) {
        const pts = dot.pts;
        let i = dot.at < pts.length && pts[dot.at - 1][2] <= d ? dot.at : 1;
        while (i < pts.length - 1 && pts[i][2] < d) i++;
        dot.at = i;
        const a = pts[i - 1], b = pts[i];
        const f = b[2] > a[2] ? (d - a[2]) / (b[2] - a[2]) : 0;
        return [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f, Math.atan2(b[1] - a[1], b[0] - a[0]) * 180 / Math.PI];
    }

    function flowTick(now) {
        const t = (now - F.t0) / 1000;
        F.dots.forEach(function (dot) {
            const d = ((t * dot.speed / dot.len + dot.phase) % 1) * dot.len;
            const p = flowAt(dot, d);
            dot.el.setAttribute('transform', 'translate(' + p[0].toFixed(1) + ' ' + p[1].toFixed(1) + ') rotate(' + p[2].toFixed(1) + ')'
                + (L.k < 1 ? ' scale(' + L.k + ')' : ''));
            // fade in out of the node, fade out at the arrow head
            dot.el.setAttribute('opacity', Math.max(0, Math.min(1, d / 14, (dot.len - d) / 14)).toFixed(2));
        });
        F.frame = requestAnimationFrame(flowTick);
    }

    function startFlow() {
        const still = reduceMotion && reduceMotion.matches;
        F.dots.forEach(function (dot) { dot.el.style.display = still ? 'none' : ''; });
        if (!F.dots.length || F.frame || still || document.hidden) return;
        F.frame = requestAnimationFrame(flowTick);
    }

    function stopFlow() {
        if (F.frame) cancelAnimationFrame(F.frame);
        F.frame = 0;
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) stopFlow(); else startFlow();
    });
    if (reduceMotion) {
        const onMotion = function () { stopFlow(); startFlow(); };
        if (reduceMotion.addEventListener) reduceMotion.addEventListener('change', onMotion);
        else if (reduceMotion.addListener) reduceMotion.addListener(onMotion);
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
                // first, so the view from the address may zoom as far as a basemap allows
                if (data.status) setBasemap(data.status.basemap || null);
                installSvg(data.svg, data.items);
                showState(data.status);
                if (data.version) S.version = data.version;
                showMessage('');
                // #play (the link of the map settings): straight to the recorded hours
                if (S.playOnLoad) {
                    S.playOnLoad = false;
                    if (H.button && !H.on) openHistory();
                    // a reload does not start it again
                    saveHash();
                }
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

    /** Name of a time zone for people: "Europe/Paris" stays, "UTC" too */
    function zoneLabel() {
        return root.dataset.timezone || '';
    }

    /**
     * The colour legend: the map's DEFAULT scale as one bar, each band at its
     * place between the lowest and the highest value
     */
    function showLegend(scale) {
        const box = statusBox.querySelector('.wmv-legend');
        if (!Array.isArray(scale) || !scale.length) {
            box.hidden = true;
            if (panel) panel.querySelector('.wmv-panel-legend').hidden = true;
            return;
        }
        let lo = Infinity, hi = -Infinity;
        scale.forEach(function (b) { lo = Math.min(lo, b.bottom); hi = Math.max(hi, b.top); });
        const span = hi > lo ? hi - lo : 1;
        const pos = function (v) { return (Math.round((v - lo) / span * 1000) / 10) + '%'; };
        const stops = [];
        scale.forEach(function (b) {
            stops.push(b.colour + ' ' + pos(b.bottom));
            stops.push((b.colour2 || b.colour) + ' ' + pos(b.top));
        });
        const num = function (v) { return Math.round(v * 10) / 10; };
        box.querySelector('.wmv-legend-bar').style.background = 'linear-gradient(to right, ' + stops.join(', ') + ')';
        box.querySelector('.wmv-legend-bar').setAttribute('aria-label', fmt('Colour scale from :min to :max %', {min: num(lo), max: num(hi)}));
        box.querySelector('.wmv-legend-min').textContent = num(lo);
        box.querySelector('.wmv-legend-max').textContent = num(hi) + ' %';
        box.hidden = false;
        // the same legend, larger, in the side panel: one row per band
        if (panel) {
            const sec = panel.querySelector('.wmv-panel-legend');
            const rows = scale.slice().reverse().map(function (b) {
                const row = document.createElement('div');
                row.className = 'wmv-scale-row';
                const sw = document.createElement('span');
                sw.className = 'wmv-scale-swatch';
                sw.style.background = b.colour2 ? 'linear-gradient(to right, ' + b.colour + ', ' + b.colour2 + ')' : b.colour;
                const label = document.createElement('span');
                label.textContent = num(b.bottom) + ' – ' + num(b.top) + ' %';
                row.append(sw, label);
                return row;
            });
            const box2 = sec.querySelector('.wmv-panel-scale');
            box2.replaceChildren.apply(box2, rows);
            sec.hidden = false;
        }
    }

    // ------------------------------------------------------------------
    // Geographic basemap

    /** Draw the basemap of the map's summary (null: none) under the map */
    function setBasemap(cfg) {
        if (!basemapBox || !window.WMBasemap) return;
        if (!cfg) {
            if (B.inst) B.inst.destroy();
            B.inst = null;
            B.cfg = null;
            basemapBox.hidden = true;
            root.classList.remove('wmv-on-basemap');
            dropInset();
            setupGeo();
            return;
        }
        // tiles that cannot load: keep the plain background until the page reloads
        if (B.inst && B.inst.failed) return;
        if (B.inst && window.WMBasemap.same(B.cfg, cfg)) return;
        B.cfg = cfg;
        basemapBox.hidden = false;
        // a new frame or style: the inset is made again on the next hover
        dropInset();
        if (B.inst) {
            B.inst.setConfig(cfg);
        } else {
            B.inst = window.WMBasemap.create(basemapBox, cfg, {
                onReady: function () { root.classList.add('wmv-on-basemap'); },
                onFail: function () {
                    root.classList.remove('wmv-on-basemap');
                    B.cfg = null;
                    dropInset();
                    setupGeo();
                    if (S.z > MAX_Z) {
                        const c = viewCentre();
                        centreOn(c.x, c.y, MAX_Z);
                        apply();
                    }
                }
            });
        }
        if (S.el) B.inst.sync(S.z, S.tx, S.ty, S.w, S.h);
    }

    // ------------------------------------------------------------------
    // Located nodes on a basemap: clusters and the city inset
    //
    // A node with a point (SET geo) keeps, when the view zooms in past
    // 100 %, the size it has at 100 %, so zooming in pulls close sites
    // apart. Located nodes too close to tell apart at the current zoom
    // merge into one badge with their number; a click on it zooms until
    // they split (or lists them when they cannot). Hovering a site, a
    // cluster or a row of the side panel shows its city in a small web
    // map in the top right corner.

    // two nodes closer than this on the screen merge (or than most of their size)
    const CLUSTER_PX = 32;
    const INSET_ZOOM = 11;
    // above this on-screen size, bandwidth labels are hidden on a basemap
    const LABEL_MAX_PX = 40;

    function geoOn() {
        return !!(B.cfg && G.nodes.length);
    }

    /** Find the located nodes of the drawing (after each new drawing, or a new basemap) */
    function setupGeo() {
        closeClusterMenu();
        G.nodes = [];
        G.byName = {};
        if (B.cfg && window.WMBasemap && S.el && S.el.querySelectorAll) {
            S.el.querySelectorAll('g.wm-node[data-id]').forEach(function (g) {
                const it = S.items[g.getAttribute('data-id')];
                if (!it || !Array.isArray(it.geo) || it.geo.length !== 2) return;
                const p = window.WMBasemap.project(B.cfg, S.w, S.h, it.geo[0], it.geo[1]);
                let size = 24;
                try {
                    const b = g.getBBox();
                    size = Math.max(12, Math.min(b.width, b.height));
                } catch (e) { /* not drawn */ }
                const n = {
                    name: g.getAttribute('data-name'), g: g, lat: it.geo[0], lon: it.geo[1],
                    x: p[0], y: p[1], size: size, site: typeof it.site === 'string' ? it.site : ''
                };
                // it shrinks around its point
                g.classList.add('wmv-geo');
                g.style.transformOrigin = p[0] + 'px ' + p[1] + 'px';
                G.nodes.push(n);
                G.byName[n.name] = n;
            });
        } else if (S.el && S.el.querySelectorAll) {
            S.el.querySelectorAll('g.wmv-geo').forEach(function (g) {
                g.classList.remove('wmv-geo', 'wmv-clustered');
                g.style.transformOrigin = '';
            });
        }
        root.classList.toggle('wmv-geo-on', geoOn());
        // fresh link colours in the inset at its next showing
        G.insetKey = null;
        if (!geoOn()) hideInset();
        G.key = null;
        G.z = 0;
        updateGeo();
    }

    /** On each new view: the size of the located nodes, the clusters and where their badges are */
    function updateGeo() {
        if (!S.el || !S.el.style) return;
        const on = geoOn();
        S.el.style.setProperty('--wmv-geo-k', on && S.z > 1 ? String(1 / S.z) : '1');
        thinLinks(on && S.z > 1 ? 1 / S.z : 1);
        // deep in a basemap, the bandwidth labels would cover the city: they go
        root.classList.toggle('wmv-lod-near', on && S.labelFont > 0 && S.labelFont * S.z > LABEL_MAX_PX);
        if (!on) {
            if (G.clusters.length || G.key !== '') showClusters([]);
            return;
        }
        if (S.z !== G.z) {
            G.z = S.z;
            regroup();
        }
        G.clusters.forEach(function (c) {
            c.el.style.left = (S.tx + c.x * S.z) + 'px';
            c.el.style.top = (S.ty + c.y * S.z) + 'px';
        });
        if (G.menu) placeClusterMenu();
    }

    /** Whether two located nodes are too close on the screen at the current zoom */
    function tooClose(a, b) {
        // their size on the screen: as on the map up to 100 %, then no bigger
        const size = Math.min(a.size, b.size) * Math.min(S.z, 1);
        return Math.hypot(a.x - b.x, a.y - b.y) * S.z < Math.max(CLUSTER_PX, 0.8 * size);
    }

    /** Merge the nodes too close at this zoom; the focused node always stays apart */
    function regroup() {
        const free = G.nodes.filter(function (n) { return n.name !== S.focus; });
        const taken = new Set();
        const groups = [];
        free.forEach(function (n) {
            if (taken.has(n)) return;
            taken.add(n);
            const group = [n];
            free.forEach(function (m) {
                if (!taken.has(m) && tooClose(n, m)) {
                    taken.add(m);
                    group.push(m);
                }
            });
            if (group.length > 1) groups.push(group);
        });
        showClusters(groups);
    }

    function clusterLabel(nodes) {
        return fmt(':n nodes close together: :names', {
            n: nodes.length,
            names: nodes.map(function (n) { return labelOf(n.g); }).join(', ')
        });
    }

    /** Draw the badges of the clusters (only when they change) and hide their nodes */
    function showClusters(groups) {
        const key = groups.map(function (g) {
            return g.map(function (n) { return n.name; }).join('\u0001');
        }).join('\u0002');
        if (key === G.key) return;
        G.key = key;
        closeClusterMenu();
        const hidden = new Set();
        G.clusters = groups.map(function (nodes) {
            let x = 0, y = 0, load = -1;
            nodes.forEach(function (n) {
                x += n.x;
                y += n.y;
                load = Math.max(load, nodeLoad(n.name));
                hidden.add(n);
            });
            const c = {nodes: nodes, x: x / nodes.length, y: y / nodes.length, el: null};
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'wmv-cluster';
            b.dataset.level = levelOf(load);
            b.textContent = String(nodes.length);
            const label = clusterLabel(nodes);
            b.title = label;
            b.setAttribute('aria-label', label);
            // not a drag of the map
            b.addEventListener('pointerdown', function (e) { e.stopPropagation(); });
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                openCluster(c);
            });
            const enter = function () { highlightNodes(nodes); };
            const leave = function () { highlightNodes(null); };
            b.addEventListener('mouseenter', enter);
            b.addEventListener('mouseleave', leave);
            b.addEventListener('focus', enter);
            b.addEventListener('blur', leave);
            c.el = b;
            return c;
        });
        G.nodes.forEach(function (n) { n.g.classList.toggle('wmv-clustered', hidden.has(n)); });
        if (clusterBox) {
            clusterBox.replaceChildren.apply(clusterBox, G.clusters.map(function (c) { return c.el; }));
            G.clusters.forEach(function (c) {
                c.el.style.left = (S.tx + c.x * S.z) + 'px';
                c.el.style.top = (S.ty + c.y * S.z) + 'px';
            });
        }
    }

    /** A cluster stands out while its nodes are highlighted (side panel) */
    function markClusters(names) {
        G.clusters.forEach(function (c) {
            c.el.classList.toggle('wmv-cluster-hl', c.nodes.some(function (n) { return names.has(n.name); }));
        });
    }

    /** Zoom so the nodes of a cluster split; list them when the view cannot zoom more */
    function openCluster(c) {
        let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
        let near = Infinity, size = Infinity;
        c.nodes.forEach(function (n, i) {
            x0 = Math.min(x0, n.x); y0 = Math.min(y0, n.y);
            x1 = Math.max(x1, n.x); y1 = Math.max(y1, n.y);
            size = Math.min(size, n.size);
            c.nodes.slice(i + 1).forEach(function (m) {
                // nodes on the same point never split: they are listed
                const d = Math.hypot(n.x - m.x, n.y - m.y);
                if (d > 0.01) near = Math.min(near, d);
            });
        });
        if (near === Infinity) {
            showClusterMenu(c);
            return;
        }
        const vp = vpSize();
        const pad = 100;
        // all of them in view...
        const fitAll = Math.min((vp.w - 2 * pad) / Math.max(1, x1 - x0), (vp.h - 2 * pad) / Math.max(1, y1 - y0));
        // ...and the two closest apart, with some room
        const apart = 1.5 * Math.max(CLUSTER_PX, 0.8 * size) / near;
        const z = clampZ(Math.min(fitAll, apart));
        if (z <= S.z * 1.05) {
            showClusterMenu(c);
            return;
        }
        animateTo(z, (x0 + x1) / 2, (y0 + y1) / 2);
        S.touched = true;
        saveHash();
    }

    function showClusterMenu(c) {
        closeClusterMenu();
        const menu = document.createElement('div');
        menu.className = 'wmv-cluster-menu';
        menu.setAttribute('role', 'dialog');
        const title = document.createElement('p');
        title.className = 'wmv-cluster-menu-title';
        title.textContent = fmt(':n nodes at the same place', {n: c.nodes.length});
        menu.setAttribute('aria-label', title.textContent);
        const list = document.createElement('ul');
        c.nodes.slice().sort(function (a, b) { return labelOf(a.g).localeCompare(labelOf(b.g)); }).forEach(function (n) {
            const li = document.createElement('li');
            const b = document.createElement('button');
            b.type = 'button';
            const label = document.createElement('span');
            label.className = 'wmv-site-label';
            label.textContent = labelOf(n.g);
            b.append(label, loadBadge(nodeLoad(n.name)));
            b.addEventListener('click', function () {
                closeClusterMenu();
                focusNode(n.g, false);
            });
            li.appendChild(b);
            list.appendChild(li);
        });
        menu.append(title, list);
        menu.addEventListener('pointerdown', function (e) { e.stopPropagation(); });
        menu.addEventListener('wheel', function (e) { e.stopPropagation(); });
        G.menu = {el: menu, cluster: c};
        (clusterBox || root).appendChild(menu);
        placeClusterMenu();
        const first = menu.querySelector('button');
        if (first) first.focus();
    }

    function placeClusterMenu() {
        const c = G.menu.cluster;
        G.menu.el.style.left = (S.tx + c.x * S.z) + 'px';
        G.menu.el.style.top = (S.ty + c.y * S.z + 24) + 'px';
    }

    function closeClusterMenu() {
        if (!G.menu) return false;
        G.menu.el.remove();
        G.menu = null;
        return true;
    }

    // ---- City inset ----

    /** The colours of the points of the inset, from the theme */
    function insetColours() {
        const cs = getComputedStyle(root);
        const v = function (name, other) { return cs.getPropertyValue(name).trim() || other; };
        return {on: v('--wm-accent', '#2563eb'), off: v('--wm-text-muted', '#64748b'), ring: v('--wm-surface', '#ffffff')};
    }

    /** The colour of a link on the map (its first arrow), or of its load level when it has no arrow */
    function linkColour(id) {
        const g = S.el.querySelector('g.wm-link[data-id="' + CSS.escape(id) + '"] .wm-arrow');
        const c = g ? g.getAttribute('fill') : null;
        if (c && c !== 'none' && c.indexOf('url(') !== 0) return c;
        const level = levelOf(loadOf(S.items[id] || null));
        if (level === 'none') return null;
        const name = {ok: '--wm-success', warn: '--wm-warning', crit: '--wm-danger'}[level];
        return getComputedStyle(root).getPropertyValue(name).trim() || null;
    }

    /** The located nodes to show for some nodes: one node shows its whole site */
    function insetNodes(names) {
        const nodes = names.map(function (name) { return G.byName[name]; }).filter(Boolean);
        if (nodes.length === 1 && nodes[0].site !== '') {
            const site = nodes[0].site;
            return G.nodes.filter(function (n) { return n.site === site; });
        }
        return nodes;
    }

    function insetTitle(nodes) {
        const sites = Array.from(new Set(nodes.map(function (n) { return n.site; })));
        if (sites.length === 1 && sites[0] !== '') return sites[0];
        if (nodes.length === 1) return labelOf(nodes[0].g);
        return fmt(':n nodes', {n: nodes.length});
    }

    /** Show the city of some nodes (their names) in the inset */
    function showInset(names) {
        clearTimeout(G.timer);
        if (!insetBox || !geoOn() || !window.WMBasemap || isPhone()) return;
        const nodes = insetNodes(names);
        if (!nodes.length) {
            hideInset();
            return;
        }
        const key = nodes.map(function (n) { return n.name; }).join('\u0001');
        insetBox.hidden = false;
        if (key === G.insetKey && G.inset) return;
        G.insetKey = key;
        if (!G.inset) {
            G.inset = window.WMBasemap.inset(insetBox.querySelector('.wmv-inset-map'), B.cfg, {
                colours: insetColours,
                onFail: function () { insetBox.hidden = true; }
            });
        }
        if (G.inset.failed) {
            insetBox.hidden = true;
            return;
        }
        const on = new Set(nodes);
        const features = G.nodes.map(function (n) {
            return {type: 'Feature', properties: {on: on.has(n)}, geometry: {type: 'Point', coordinates: [n.lon, n.lat]}};
        });
        Object.keys(S.ends).forEach(function (id) {
            const a = G.byName[S.ends[id].a], b = G.byName[S.ends[id].b];
            if (!a || !b || a === b) return;
            features.unshift({
                type: 'Feature', properties: {colour: linkColour(id)},
                geometry: {type: 'LineString', coordinates: [[a.lon, a.lat], [b.lon, b.lat]]}
            });
        });
        insetBox.querySelector('.wmv-inset-title').textContent = insetTitle(nodes);
        const count = insetBox.querySelector('.wmv-inset-count');
        count.textContent = nodes.length > 1 ? String(nodes.length) : '';
        G.inset.show({
            points: nodes.map(function (n) { return [n.lat, n.lon]; }),
            maxZoom: INSET_ZOOM,
            features: {type: 'FeatureCollection', features: features}
        });
    }

    function hideInset() {
        clearTimeout(G.timer);
        if (insetBox) insetBox.hidden = true;
        G.insetKey = null;
    }

    /** What the inset shows when nothing is hovered: the focused node's city, or nothing */
    function restInset() {
        if (G.hover) return;
        if (S.focus !== null && G.byName[S.focus]) showInset([S.focus]);
        else hideInset();
    }

    /** Hovering something located shows its city; leaving it goes back after a moment */
    function hoverInset(names) {
        if (names && names.length && names.some(function (n) { return G.byName[n]; })) {
            G.hover = true;
            showInset(names);
            return;
        }
        if (!G.hover) return;
        G.hover = false;
        clearTimeout(G.timer);
        G.timer = setTimeout(restInset, 1200);
    }

    function dropInset() {
        if (G.inset) G.inset.destroy();
        G.inset = null;
        G.insetKey = null;
        if (insetBox) {
            insetBox.hidden = true;
            insetBox.querySelector('.wmv-inset-map').replaceChildren();
        }
    }

    function showState(status) {
        if (status) setBasemap(status.basemap || null);
        if (!statusBox || !status) return;
        statusBox.hidden = false;
        statusBox.className = 'wmv-status wmv-level-' + (status.level || 'none');
        statusBox.querySelector('.wmv-dot').style.background = status.colour || '';
        const when = status.time ? formatTime(status.time) : '';
        const zone = zoneLabel();
        statusBox.querySelector('.wmv-updated').textContent = when
            ? (H.on ? fmt('Recorded at :time', {time: formatWhen(status.time)}) : fmt('Updated at :time', {time: when})) + (zone ? ' · ' + zone : '')
            : '';
        const max = statusBox.querySelector('.wmv-status-max');
        if (status.max === null || status.max === undefined) {
            max.textContent = t('No data');
            max.title = '';
        } else {
            const pct = Math.round(status.max);
            max.textContent = status.link
                ? fmt('Max load :pct % (:link)', {pct: pct, link: status.link})
                : fmt('Max load :pct %', {pct: pct});
            max.title = fmt('Most loaded link: :link (:pct %)', {link: status.link || '', pct: status.max});
        }
        showLegend(status.scale);
        showTotal(status.total);
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
        closeClusterMenu();
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
        const sites = typeof d.a === 'string' && typeof d.b === 'string' ? [S.sites[d.a] || '', S.sites[d.b] || ''] : [];
        WM.hover.show({
            // the series of the last day, week or month when the map can read
            // them, else the OVERLIBGRAPH picture, else the data source's
            title: d.name, a: (d.labels && d.labels[0]) || d.a || '', b: (d.labels && d.labels[1]) || d.b || '', sites: sites, speed: d.speed || null,
            traffic: d.traffic || '', ds: d.traffic || d.graph ? '' : (d.ds || ''),
            graph: d.traffic ? '' : (d.graph || ''),
            in: d.in, out: d.out, inpct: d.inpct, outpct: d.outpct, hint: hint
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
            // a card with a period switch waits a moment so the pointer can reach it
            if (window.WM && WM.hover) WM.hover.leave();
            return;
        }
        showPanel(g, it.data, e);
    });
    viewport.addEventListener('mouseleave', function () {
        if (!touch) {
            hovered = null;
            if (window.WM && WM.hover) WM.hover.leave();
        }
        markRow(null);
        hoverInset(null);
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
        const node = e.target && e.target.closest ? e.target.closest('g.wm-node') : null;
        if (node && stage.contains(node)) {
            // its page is one click away, in its card
            focusNode(node, true);
            return;
        }
        // a click anywhere but on a node closes the pinned card
        if (S.focus !== null) unfocus();
        if (it && it.data && it.data.url) window.open(it.data.url, '_blank', 'noopener');
    }, true);

    // Hovering a node frames it in the minimap, and shows its city on a basemap
    viewport.addEventListener('mouseover', function (e) {
        const node = e.target && e.target.closest ? e.target.closest('g.wm-node') : null;
        const name = node && stage.contains(node) ? node.getAttribute('data-name') : null;
        markMinimap(name !== null ? node : focusedNode());
        markRow(name);
        // a cluster badge says itself what it shows
        if (!(e.target.closest && e.target.closest('.wmv-clusters'))) hoverInset(name !== null ? [name] : null);
    });

    // ------------------------------------------------------------------
    // Side panel: sites, total traffic, legend

    const PANEL_KEY = 'wmv-panel';
    const P = {open: {}, rows: {}, hover: null, totals: null, totalsAt: 0, totalsBusy: false, total: null};

    // Every site open or closed at once (the button in the panel heading),
    // remembered in this browser; a site opened or closed by hand stays so
    // until the next "every site"
    const SITES_KEY = 'wmv-sites-open';
    function storedSites() {
        try {
            const v = localStorage.getItem(SITES_KEY);
            return v === 'all' || v === 'none' ? v : null;
        } catch (e) { return null; }
    }

    function siteOpen(key, count) {
        if (P.open[key] !== undefined) return P.open[key];
        const all = storedSites();
        return all ? all === 'all' : count <= 6;
    }

    /** The heading button: collapse when a site is open, expand when all are closed */
    function updateSitesAll() {
        const btn = panel && panel.querySelector('[data-act="sites-all"]');
        if (!btn) return;
        const toggles = panel.querySelectorAll('.wmv-site-toggle');
        btn.hidden = toggles.length < 2;
        const anyOpen = Array.prototype.some.call(toggles, function (b) { return b.getAttribute('aria-expanded') === 'true'; });
        const label = anyOpen ? btn.dataset.collapse : btn.dataset.expand;
        btn.title = label;
        btn.setAttribute('aria-label', label);
        btn.setAttribute('aria-pressed', anyOpen ? 'false' : 'true');
        const use = btn.querySelector('use');
        use.setAttribute('href', iconHref(anyOpen ? 'chevrons-down-up' : 'chevrons-up-down'));
    }

    function setAllSites() {
        const toggles = panel ? panel.querySelectorAll('.wmv-site-toggle') : [];
        const open = !Array.prototype.some.call(toggles, function (b) { return b.getAttribute('aria-expanded') === 'true'; });
        P.open = {};
        try { localStorage.setItem(SITES_KEY, open ? 'all' : 'none'); } catch (e) { /* private mode */ }
        fillSites();
    }

    function storedPanel() {
        try { return localStorage.getItem(PANEL_KEY); } catch (e) { return null; }
    }

    /** Open or close the panel; a choice of the user is remembered in this browser */
    function setPanel(open, remember) {
        if (!panel) return;
        panel.hidden = !open;
        root.classList.toggle('wmv-panel-open', open);
        if (panelButton) panelButton.setAttribute('aria-pressed', open ? 'true' : 'false');
        if (remember) {
            try { localStorage.setItem(PANEL_KEY, open ? '1' : '0'); } catch (e) { /* private window */ }
        }
        if (open) {
            fillSites();
            loadTotals();
            drawTotals();
        }
    }

    /** The node groups of the map (notes left out), by site; '' gathers the nodes without one */
    function siteGroups() {
        const bySite = {};
        let named = 0;
        nodeEntries(S.el).forEach(function (n) {
            if (n.g.querySelector('.wm-note')) return;
            const it = S.items[n.g.getAttribute('data-id')];
            const site = it && typeof it.site === 'string' ? it.site : '';
            if (!bySite[site]) {
                bySite[site] = [];
                if (site !== '') named++;
            }
            bySite[site].push(n);
        });
        const names = Object.keys(bySite).sort(function (a, b) {
            // the nodes without a site come last
            if (a === '' || b === '') return a === '' ? 1 : -1;
            return a.localeCompare(b);
        });
        return {named: named, groups: names.map(function (site) {
            return {site: site, nodes: bySite[site].sort(function (a, b) { return a.label.localeCompare(b.label); })};
        })};
    }

    /** The highest load of the links of a node, -1 without data */
    function nodeLoad(name) {
        let max = -1;
        linksOf(name).forEach(function (l) { max = Math.max(max, loadOf(l.data)); });
        return max;
    }

    function levelOf(load) {
        return load < 0 ? 'none' : (load >= 80 ? 'crit' : (load >= 50 ? 'warn' : 'ok'));
    }

    function loadBadge(load) {
        const span = document.createElement('span');
        span.className = 'wmv-site-load';
        span.dataset.level = levelOf(load);
        span.textContent = load < 0 ? '' : Math.round(load) + ' %';
        return span;
    }

    /** Frame some nodes on the map: zoom so they fill the middle of the view */
    function frameNodes(nodes) {
        let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
        nodes.forEach(function (n) {
            let b;
            try { b = n.g.getBBox(); } catch (e) { b = null; }
            if (!b) return;
            x0 = Math.min(x0, b.x); y0 = Math.min(y0, b.y);
            x1 = Math.max(x1, b.x + b.width); y1 = Math.max(y1, b.y + b.height);
        });
        if (!isFinite(x0)) return;
        const vp = vpSize();
        const pad = 80;
        const z = clampZ(Math.min(4, (vp.w - 2 * pad) / Math.max(40, x1 - x0), (vp.h - 2 * pad) / Math.max(40, y1 - y0)));
        animateTo(z, (x0 + x1) / 2, (y0 + y1) / 2);
        S.touched = true;
        saveHash();
    }

    function highlightNodes(nodes) {
        if (!S.el || !S.el.querySelectorAll) return;
        S.el.querySelectorAll('.wmv-hl').forEach(function (n) { n.classList.remove('wmv-hl'); });
        (nodes || []).forEach(function (n) { n.g.classList.add('wmv-hl'); });
        markMinimap(nodes && nodes.length === 1 ? nodes[0].g : focusedNode());
        // on a basemap: the cluster they are in, and their city in the inset
        const names = (nodes || []).map(function (n) { return n.name || n.g.getAttribute('data-name'); });
        markClusters(new Set(names));
        hoverInset(names);
    }

    function isPhone() {
        return vpSize().w <= 640;
    }

    function nodeRow(n) {
        const li = document.createElement('li');
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'wmv-site-node';
        b.dataset.name = n.name;
        const label = document.createElement('span');
        label.className = 'wmv-site-label';
        label.textContent = n.label;
        b.title = n.name !== n.label ? n.label + ' (' + n.name + ')' : n.label;
        b.append(label, loadBadge(nodeLoad(n.name)));
        b.addEventListener('click', function () {
            const g = nodeByName(n.name);
            if (!g) return;
            // on a phone the panel would cover the card
            if (isPhone()) setPanel(false, false);
            focusNode(g, true);
        });
        b.addEventListener('mouseenter', function () { highlightNodes([{g: n.g}]); });
        b.addEventListener('mouseleave', function () { highlightNodes(null); });
        b.addEventListener('focus', function () { highlightNodes([{g: n.g}]); });
        b.addEventListener('blur', function () { highlightNodes(null); });
        P.rows[n.name] = b;
        li.appendChild(b);
        return li;
    }

    /** Fill the list of nodes by site (again after each new drawing of the map) */
    function fillSites() {
        if (!panel || panel.hidden || !S.el || !S.el.querySelectorAll) return;
        const list = panel.querySelector('.wmv-site-list');
        const title = panel.querySelector('#wmv-sites-title');
        const scroll = list.scrollTop;
        const info = siteGroups();
        P.rows = {};
        let count = 0;
        const items = [];
        if (!info.named) {
            // no site on this map: one plain list of the nodes
            title.textContent = title.dataset.nodes;
            info.groups.forEach(function (grp) {
                grp.nodes.forEach(function (n) { items.push(nodeRow(n)); count++; });
            });
            panel.querySelector('.wmv-sites .wmv-panel-count').textContent = String(count);
        } else {
            title.textContent = title.dataset.sites;
            info.groups.forEach(function (grp) {
                const key = grp.site;
                const open = siteOpen(key, info.groups.length);
                const li = document.createElement('li');
                li.className = 'wmv-site';
                const head = document.createElement('div');
                head.className = 'wmv-site-head';
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'wmv-site-toggle';
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                const chevron = document.createElementNS(SVG_NS, 'svg');
                chevron.setAttribute('class', 'icon wmv-site-chevron');
                chevron.setAttribute('aria-hidden', 'true');
                const use = document.createElementNS(SVG_NS, 'use');
                use.setAttribute('href', iconHref('chevron-down'));
                chevron.appendChild(use);
                const name = document.createElement('span');
                name.className = 'wmv-site-name';
                name.textContent = key === '' ? t('No site') : key;
                const n = document.createElement('span');
                n.className = 'wmv-site-count';
                n.textContent = String(grp.nodes.length);
                let load = -1;
                grp.nodes.forEach(function (x) { load = Math.max(load, nodeLoad(x.name)); });
                toggle.append(chevron, name, n, loadBadge(load));
                const nodes = document.createElement('ul');
                nodes.className = 'wmv-site-nodes';
                nodes.hidden = !open;
                grp.nodes.forEach(function (x) { nodes.appendChild(nodeRow(x)); });
                toggle.addEventListener('click', function () {
                    const now = nodes.hidden;
                    nodes.hidden = !now;
                    P.open[key] = now;
                    toggle.setAttribute('aria-expanded', now ? 'true' : 'false');
                    updateSitesAll();
                });
                toggle.addEventListener('mouseenter', function () { highlightNodes(grp.nodes); });
                toggle.addEventListener('mouseleave', function () { highlightNodes(null); });
                head.appendChild(toggle);
                const locate = document.createElement('button');
                locate.type = 'button';
                locate.className = 'wmv-icon-btn wmv-site-frame';
                const label = fmt('Show :site on the map', {site: name.textContent});
                locate.title = label;
                locate.setAttribute('aria-label', label);
                const icon = chevron.cloneNode(true);
                icon.setAttribute('class', 'icon');
                icon.firstChild.setAttribute('href', iconHref('locate'));
                locate.appendChild(icon);
                locate.addEventListener('click', function () {
                    if (isPhone()) setPanel(false, false);
                    frameNodes(grp.nodes);
                });
                locate.addEventListener('mouseenter', function () { highlightNodes(grp.nodes); });
                locate.addEventListener('mouseleave', function () { highlightNodes(null); });
                head.appendChild(locate);
                li.append(head, nodes);
                items.push(li);
                count++;
            });
            panel.querySelector('.wmv-sites .wmv-panel-count').textContent = String(count);
        }
        list.replaceChildren.apply(list, items);
        list.scrollTop = scroll;
        updateSitesAll();
        markCurrentRow();
        if (P.hover) markRow(P.hover);
    }

    /** The address of an icon of the sprite the page uses (versioned) */
    function iconHref(name) {
        const any = root.querySelector('svg.icon use');
        const base = any ? (any.getAttribute('href') || '').split('#')[0] : '/assets/icons/ui.svg';
        return base + '#' + name;
    }

    /** The node hovered on the map stands out in the list */
    function markRow(name) {
        P.hover = name;
        if (!panel || panel.hidden) return;
        panel.querySelectorAll('.wmv-row-hover').forEach(function (el) { el.classList.remove('wmv-row-hover'); });
        const row = name !== null ? P.rows[name] : null;
        if (!row) return;
        const nodes = row.closest('.wmv-site-nodes');
        // in a closed site, its heading stands out
        const target = nodes && nodes.hidden ? nodes.parentNode.querySelector('.wmv-site-toggle') : row;
        target.classList.add('wmv-row-hover');
        const list = panel.querySelector('.wmv-site-list');
        const lr = list.getBoundingClientRect(), r = target.getBoundingClientRect();
        if (r.top < lr.top) list.scrollTop -= lr.top - r.top + 4;
        else if (r.bottom > lr.bottom) list.scrollTop += r.bottom - lr.bottom + 4;
    }

    /** The focused node is the current row */
    function markCurrentRow() {
        if (!panel) return;
        Object.keys(P.rows).forEach(function (name) {
            if (name === S.focus) P.rows[name].setAttribute('aria-current', 'true');
            else P.rows[name].removeAttribute('aria-current');
        });
    }

    // ---- Total traffic ----

    function showTotal(total) {
        P.total = total || null;
        if (!panel) return;
        const sec = panel.querySelector('.wmv-total');
        if (!total || (total.in === null && total.out === null)) {
            sec.hidden = true;
            return;
        }
        sec.hidden = false;
        sec.querySelector('.wmv-total-in dd').textContent = rate(total.in);
        sec.querySelector('.wmv-total-out dd').textContent = rate(total.out);
        sec.querySelector('.wmv-total-links').textContent = total.links === 1 ? t('1 link') : fmt(':n links', {n: total.links});
        loadTotals();
        drawTotals();
    }

    /** The totals of the recorded frames, at most once per refresh interval */
    function loadTotals() {
        if (!panel || panel.hidden || root.dataset.history !== '1' || P.totalsBusy) return;
        if (P.totals && Date.now() - P.totalsAt < refreshMs) return;
        P.totalsBusy = true;
        fetch('/map/' + mapId + '/totals', {cache: 'no-store', credentials: 'same-origin'})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                P.totals = data && Array.isArray(data.frames) ? data.frames : [];
                P.totalsAt = Date.now();
                drawTotals();
            })
            .catch(function () { P.totals = []; P.totalsAt = Date.now(); })
            .finally(function () { P.totalsBusy = false; });
    }

    function percentile(values, p) {
        if (!values.length) return null;
        const v = values.slice().sort(function (a, b) { return a - b; });
        return v[Math.min(v.length - 1, Math.ceil(p * v.length) - 1)];
    }

    /** IN above the line, OUT below it, over the recorded hours; peak and 95th percentile */
    function drawTotals() {
        if (!panel) return;
        const sec = panel.querySelector('.wmv-total');
        const graph = sec.querySelector('.wmv-total-graph');
        const stats = sec.querySelector('.wmv-total-stats');
        const frames = (P.totals || []).filter(function (f) { return f[1] !== null || f[2] !== null; });
        if (frames.length < 2) {
            // an <svg> has no hidden property: set the attribute
            graph.setAttribute('hidden', '');
            stats.hidden = true;
            return;
        }
        const W = 260, H = 64, mid = H / 2;
        const ins = frames.map(function (f) { return f[1] || 0; });
        const outs = frames.map(function (f) { return f[2] || 0; });
        const top = Math.max.apply(null, ins.concat(outs)) || 1;
        const t0 = frames[0][0], t1 = frames[frames.length - 1][0];
        const x = function (time) { return t1 > t0 ? (time - t0) / (t1 - t0) * W : 0; };
        const area = function (vals, sign) {
            let d = 'M0 ' + mid;
            frames.forEach(function (f, i) {
                d += ' L' + x(f[0]).toFixed(1) + ' ' + (mid - sign * vals[i] / top * (mid - 2)).toFixed(1);
            });
            return d + ' L' + W + ' ' + mid + ' Z';
        };
        const mk = function (cls, d) {
            const p = document.createElementNS(SVG_NS, 'path');
            p.setAttribute('class', cls);
            p.setAttribute('d', d);
            return p;
        };
        const parts = [mk('wmv-total-area-in', area(ins, 1)), mk('wmv-total-area-out', area(outs, -1)), mk('wmv-total-axis', 'M0 ' + mid + ' H' + W)];
        if (H_on()) {
            // the frame shown during the playback
            const at = H.frames[H.i];
            if (at >= t0 && at <= t1) parts.push(mk('wmv-total-now-mark', 'M' + x(at).toFixed(1) + ' 0 V' + H));
        }
        graph.replaceChildren.apply(graph, parts);
        const hours = Math.max(1, Math.round((t1 - t0) / 3600));
        graph.setAttribute('aria-label', fmt('Total traffic over the last :hours h, IN above the line and OUT below', {hours: hours}));
        graph.removeAttribute('hidden');
        const peakIn = Math.max.apply(null, ins), peakOut = Math.max.apply(null, outs);
        stats.replaceChildren.apply(stats, []
            .concat(statLine(t('Peak'), peakIn, peakOut))
            .concat(statLine(t('95th percentile'), percentile(ins, 0.95), percentile(outs, 0.95)))
            .concat([cell('wmv-total-span', fmt('Last :hours h', {hours: hours}))]));
        stats.hidden = false;
    }

    function cell(cls, text, title) {
        const c = document.createElement('span');
        c.className = cls;
        c.textContent = text;
        if (title) c.title = title;
        return c;
    }

    /** One row of the grid under IN and OUT: its label, then the IN and OUT values */
    function statLine(label, vin, vout) {
        return [cell('wmv-total-key', label), cell('', rate(vin), t('IN')), cell('', rate(vout), t('OUT'))];
    }

    function H_on() {
        // H is declared further down: not there yet while the page starts
        try { return H.on; } catch (e) { return false; }
    }

    if (panel) {
        const stored = storedPanel();
        // open on a wide screen until the user says otherwise
        setPanel(stored !== null ? stored === '1' : vpSize().w >= 1100, false);
    }

    // ------------------------------------------------------------------
    // Find a node

    /** Nodes by what people know them by: their label, and their name */
    function nodeEntries(svg) {
        return Array.from(svg.querySelectorAll('g.wm-node[data-name]')).map(function (g) {
            const texts = g.querySelectorAll('text');
            // a badge node keeps its label in its title; otherwise the last
            // text is the label itself (a shadow comes first)
            const title = g.querySelector('title');
            const label = title ? title.textContent.trim() : (texts.length ? texts[texts.length - 1].textContent.trim() : '');
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
        focusNode(g, true);
        S.el.querySelectorAll('.wmv-found').forEach(function (n) { n.classList.remove('wmv-found'); });
        g.classList.add('wmv-found');
        setTimeout(function () { g.classList.remove('wmv-found'); }, 2400);
    }

    // ------------------------------------------------------------------
    // Node focus: zoom onto a node and pin its card

    function nodeByName(name) {
        if (!S.el || !S.el.querySelector || name === null) return null;
        return S.el.querySelector('g.wm-node[data-name="' + CSS.escape(name) + '"]');
    }

    function focusedNode() {
        return S.focus === null ? null : nodeByName(S.focus);
    }

    function labelOf(g) {
        const hit = nodeEntries(S.el).find(function (n) { return n.g === g; });
        return hit ? hit.label : g.getAttribute('data-name');
    }

    /** The links of a node, each with the node at its other end */
    function linksOf(name) {
        const out = [];
        Object.keys(S.ends).forEach(function (id) {
            const e = S.ends[id];
            if (e.a !== name && e.b !== name) return;
            out.push({id: id, other: e.a === name ? e.b : e.a, data: S.items[id] || null});
        });
        return out;
    }

    /** The map point to centre on, so the node sits in the part of the view the card leaves free */
    function focusCentre(box, z) {
        const cx = box.x + box.width / 2, cy = box.y + box.height / 2;
        const vp = vpSize();
        // the side panel on the left takes its share too
        const left = panel && !panel.hidden && vp.w > 640 ? panel.offsetWidth + 16 : 0;
        if (!card || card.hidden) return {x: cx - left / 2 / z, y: cy};
        // a sheet at the bottom on a phone, a card on the right elsewhere
        if (vp.w <= 640) return {x: cx, y: cy + (card.offsetHeight + 8) / 2 / z};
        return {x: cx + (card.offsetWidth + 16 - left) / 2 / z, y: cy};
    }

    function focusNode(g, zoom) {
        S.focus = g.getAttribute('data-name');
        markFocus();
        fillCard(g);
        markMinimap(g);
        markCurrentRow();
        if (zoom) {
            let box;
            try { box = g.getBBox(); } catch (e) { box = null; }
            if (box) {
                const vp = vpSize();
                let z = Math.max(S.z, 2);
                if (box.width > 0 && box.height > 0) {
                    z = Math.min(z, 0.4 * vp.w / box.width, 0.4 * vp.h / box.height);
                }
                z = clampZ(z);
                const c = focusCentre(box, z);
                animateTo(z, c.x, c.y);
                S.touched = true;
            }
        }
        saveHash();
    }

    function unfocus() {
        if (S.focus === null) return;
        S.focus = null;
        markFocus();
        if (card) card.hidden = true;
        clearCardTraffic();
        markMinimap(null);
        markCurrentRow();
        saveHash();
    }

    /** After a reload of the map: the same node again, with fresh numbers */
    function refocus() {
        if (S.focus === null) return;
        const g = focusedNode();
        if (!g) {
            // the node is gone from the map
            unfocus();
            return;
        }
        if (S.focusZoom) {
            S.focusZoom = false;
            focusNode(g, true);
            return;
        }
        markFocus();
        fillCard(g);
        markMinimap(g);
    }

    /** The focused node, its links and their other ends stand out, the rest dims */
    function markFocus() {
        if (!S.el || !S.el.querySelectorAll) return;
        S.el.querySelectorAll('.wmv-focus, .wmv-near').forEach(function (n) {
            n.classList.remove('wmv-focus', 'wmv-near');
        });
        const g = focusedNode();
        S.el.classList.toggle('wmv-focusing', !!g);
        // the focused node leaves its cluster; its city shows in the inset
        if (geoOn()) {
            G.z = 0;
            updateGeo();
        }
        restInset();
        if (!g) return;
        g.classList.add('wmv-focus');
        linksOf(S.focus).forEach(function (l) {
            S.el.querySelectorAll('g.wm-link[data-id="' + CSS.escape(l.id) + '"]').forEach(function (n) { n.classList.add('wmv-near'); });
            const other = nodeByName(l.other);
            if (other && other !== g) other.classList.add('wmv-near');
        });
    }

    function pct(v) {
        return typeof v === 'number' ? (Math.round(v * 10) / 10) + ' %' : '–';
    }

    function rate(v) {
        return typeof v === 'number' && window.WM && WM.formatBps ? WM.formatBps(v, 1) : '–';
    }

    function loadOf(d) {
        return d ? Math.max(d.inpct || 0, d.outpct || 0) : -1;
    }

    function fillCard(g) {
        if (!card) return;
        const name = g.getAttribute('data-name');
        const label = labelOf(g);
        card.querySelector('.wmv-card-title').textContent = label;
        const sub = card.querySelector('.wmv-card-sub');
        sub.textContent = name !== label ? name : '';
        sub.hidden = name === label;

        // the busiest first
        const links = linksOf(name).sort(function (a, b) { return loadOf(b.data) - loadOf(a.data); });
        card.querySelector('.wmv-card-count').textContent = links.length === 1 ? t('1 link') : fmt(':n links', {n: links.length});
        const list = card.querySelector('.wmv-card-links');
        list.replaceChildren.apply(list, links.map(function (l) {
            const li = document.createElement('li');
            const b = document.createElement('button');
            b.type = 'button';
            const other = nodeByName(l.other);
            b.disabled = !other || other === g;
            const to = document.createElement('span');
            to.className = 'wmv-card-to';
            to.textContent = other ? labelOf(other) : l.other;
            const via = document.createElement('span');
            via.className = 'wmv-card-via';
            via.textContent = l.data ? l.data.name : '';
            const load = document.createElement('span');
            load.className = 'wmv-card-load';
            if (l.data) {
                const max = loadOf(l.data);
                load.dataset.level = max >= 80 ? 'crit' : (max >= 50 ? 'warn' : 'ok');
                load.textContent = t('IN') + ' ' + pct(l.data.inpct) + ' · ' + t('OUT') + ' ' + pct(l.data.outpct);
                load.title = t('IN') + ' ' + rate(l.data.in) + ' · ' + t('OUT') + ' ' + rate(l.data.out);
            }
            b.append(to, via, load);
            if (!b.disabled) {
                b.title = fmt('Go to :node', {node: to.textContent});
                b.addEventListener('click', function () { focusNode(other, true); });
                b.addEventListener('mouseenter', function () { markMinimap(other); });
                b.addEventListener('mouseleave', function () { markMinimap(focusedNode()); });
            }
            li.appendChild(b);
            return li;
        }));
        card.querySelector('.wmv-card-none').hidden = links.length > 0;
        fillCardTraffic(name, links);

        const it = S.items[g.getAttribute('data-id')];
        const open = card.querySelector('.wmv-card-open');
        if (it && it.url) {
            open.href = it.url;
            open.hidden = false;
        } else {
            open.hidden = true;
            open.removeAttribute('href');
        }
        card.hidden = false;
    }

    /**
     * The traffic graph of the node card: one of its links that has a
     * history (the busiest first), picked in the list above the graph. The
     * graph stays as it is when the map refreshes with the same node.
     */
    const cardTraffic = {panel: null, key: null};

    function fillCardTraffic(name, links) {
        const box = card.querySelector('.wmv-card-traffic');
        if (!box) return;
        const select = box.querySelector('select');
        const withTraffic = links.filter(function (l) { return l.data && l.data.traffic; });
        if (!withTraffic.length || !(window.WM && WM.trafficPanel)) {
            clearCardTraffic();
            return;
        }
        const ids = withTraffic.map(function (l) { return l.id; }).join(',');
        const keep = cardTraffic.key && cardTraffic.key.name === name && cardTraffic.key.ids === ids;
        if (!keep) {
            select.replaceChildren.apply(select, withTraffic.map(function (l) {
                const o = document.createElement('option');
                const other = nodeByName(l.other);
                o.value = l.id;
                o.textContent = fmt('To :node', {node: other ? labelOf(other) : l.other}) + (l.data.name ? ' · ' + l.data.name : '');
                return o;
            }));
            select.value = withTraffic[0].id;
            cardTraffic.key = {name: name, ids: ids};
        }
        box.hidden = false;
        if (!cardTraffic.panel) {
            cardTraffic.panel = WM.trafficPanel(box.querySelector('.wmv-card-plot'));
            select.addEventListener('change', drawCardTraffic);
        }
        if (!keep) drawCardTraffic();
    }

    function drawCardTraffic() {
        const select = card.querySelector('.wmv-card-traffic select');
        const d = S.items[select.value];
        if (cardTraffic.panel && d && d.traffic) cardTraffic.panel.show({info: {traffic: d.traffic}, selection: null});
    }

    function clearCardTraffic() {
        const box = card ? card.querySelector('.wmv-card-traffic') : null;
        if (box) box.hidden = true;
        if (cardTraffic.panel) cardTraffic.panel.clear();
        cardTraffic.key = null;
    }

    // A link to another node of the same map (#n=<node>) typed or followed on this page
    window.addEventListener('hashchange', function () {
        const n = new URLSearchParams(location.hash.replace(/^#/, '')).get('n');
        const g = n ? nodeByName(n) : null;
        if (g) focusNode(g, true);
        else if (!n) unfocus();
    });

    if (card) {
        card.querySelector('[data-act="unfocus"]').addEventListener('click', unfocus);
        card.querySelector('[data-act="zoom-focus"]').addEventListener('click', function () {
            const g = focusedNode();
            if (g) focusNode(g, true);
        });
    }

    /** A ring on the minimap where the node is */
    function markMinimap(g) {
        if (!minimapMark) return;
        let box = null;
        if (g && S.w) {
            try { box = g.getBBox(); } catch (e) { box = null; }
        }
        if (!box) {
            minimapMark.hidden = true;
            return;
        }
        const k = minimapScale();
        const r = Math.max(5, Math.max(box.width, box.height) * k / 2 + 3);
        minimapMark.style.left = ((box.x + box.width / 2) * k - r) + 'px';
        minimapMark.style.top = ((box.y + box.height / 2) * k - r) + 'px';
        minimapMark.style.width = minimapMark.style.height = (2 * r) + 'px';
        minimapMark.hidden = false;
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
                case 'fullscreen': toggleFullscreen(); break;
                case 'copy-kiosk': copyKioskLink(); closeMenus(); break;
                case 'panel': setPanel(panel ? panel.hidden : false, true); break;
                case 'panel-close': setPanel(false, true); break;
                case 'sites-all': setAllSites(); break;
                default: return;
            }
            e.preventDefault();
        });
    });

    // the keys come from the user's profile (shortcuts.js, /account/shortcuts)
    const KEYS = window.WMKeys || {is: function () { return false; }};
    const keyActions = [
        ['viewer.zoom-in', function () { zoomBy(1.4); }],
        ['viewer.zoom-out', function () { zoomBy(1 / 1.4); }],
        ['viewer.fit', function () { fit(); }],
        ['viewer.actual-size', function () { actualSize(); }],
        ['viewer.pan-left', function () { S.tx += 60; userMoved(); }],
        ['viewer.pan-right', function () { S.tx -= 60; userMoved(); }],
        ['viewer.pan-up', function () { S.ty += 60; userMoved(); }],
        ['viewer.pan-down', function () { S.ty -= 60; userMoved(); }],
        ['viewer.find', function () { findInput.focus(); }],
        ['viewer.panel', function () { if (!panel) return false; setPanel(panel.hidden, true); }],
        ['viewer.fullscreen', function () { toggleFullscreen(); }],
        ['viewer.edit', function () {
            const edit = root.querySelector('.wmv-edit');
            if (!edit) return false;
            window.location.href = edit.href;
        }]
    ];

    document.addEventListener('keydown', function (e) {
        if (kioskFrame) return;
        const tag = (e.target && e.target.tagName) || '';
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag)) {
            if (e.key === 'Escape' && e.target === findInput) findInput.blur();
            return;
        }
        // The map is the whole page: the keys act unless another control
        // outside the map (the user menu...) has the focus
        if (!keysForMap()) return;
        for (const [id, run] of keyActions) {
            if (KEYS.is(e, id)) {
                if (run() !== false) e.preventDefault();
                return;
            }
        }
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        switch (e.key) {
            case 'Escape':
                if (closeClusterMenu()) break;
                if (root.querySelector('.wmv-more[open]')) {
                    closeMenus();
                    break;
                }
                if (S.focus !== null) {
                    unfocus();
                    break;
                }
                if (standalone) {
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
        if (kioskFrame) {
            initialView();
        } else if (!S.touched) {
            centreOn(S.w / 2, S.h / 2, isFullscreen() ? fitZ() : Math.min(1, fitZ()));
            besidePanel();
        } else {
            centreOn(c.x, c.y, S.z);
        }
        apply();
    });

    // In fullscreen the controls fade out after 3 s without movement; any
    // movement, touch or key brings them back
    let idle = 0;
    function wake() {
        root.classList.remove('wmv-idle');
        clearTimeout(idle);
        if (!isFullscreen()) return;
        idle = setTimeout(function () {
            // not while typing a search (a key brings the controls back anyway)
            if (document.activeElement === findInput) return;
            if (root.querySelector('.wmv-more[open]') || H.on) return;
            root.classList.add('wmv-idle');
        }, 3000);
    }
    ['mousemove', 'pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, wake, {passive: true});
    });

    // ------------------------------------------------------------------
    // Fullscreen: one mode, the browser's fullscreen on the map with the
    // current zoom. The fullscreen page (?fullscreen=1) stays for kiosk
    // links and for browsers without the Fullscreen API.

    function fullscreenUrl() {
        return root.dataset.kioskUrl + (S.touched ? location.hash : '');
    }

    function toggleFullscreen() {
        if (standalone) {
            window.location.href = root.dataset.normalUrl + location.hash;
            return;
        }
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(function () {});
            return;
        }
        if (!root.requestFullscreen) {
            window.location.href = fullscreenUrl();
            return;
        }
        root.requestFullscreen().catch(function () { window.location.href = fullscreenUrl(); });
    }

    function fullscreenChanged() {
        const on = isFullscreen();
        root.classList.toggle('wmv-fullscreen', on);
        if (fsButton) {
            fsButton.setAttribute('aria-pressed', on ? 'true' : 'false');
            const fsKey = window.WMKeys ? window.WMKeys.first('viewer.fullscreen') : '';
            fsButton.title = (on ? fsButton.dataset.leave : fsButton.dataset.enter) + (fsKey ? ' (' + fsKey + ')' : '');
            const use = fsButton.querySelector('use');
            use.setAttribute('href', use.getAttribute('href').split('#')[0] + '#' + (on ? 'minimize' : 'maximize'));
        }
        wake();
    }
    document.addEventListener('fullscreenchange', fullscreenChanged);
    fullscreenChanged();

    function keysForMap() {
        const a = document.activeElement;
        return isFullscreen() || !a || a === document.body || root.contains(a);
    }

    // ------------------------------------------------------------------
    // More actions

    function closeMenus() {
        root.querySelectorAll('.wmv-more[open]').forEach(function (d) { d.open = false; });
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest || !e.target.closest('.wmv-more')) closeMenus();
    });

    // A box that stays open (the hover card tapped on a touch screen, the
    // list of a cluster, the card of a node) closes on a click outside it.
    // The map handles its own clicks: an item shows its box, the empty map
    // closes it. The controls that move the view keep the node card.
    document.addEventListener('pointerdown', function (e) {
        const el = e.target;
        if (!el || !el.closest || viewport.contains(el)) return;
        if (!el.closest('.wm-hovercard')) hidePanel();
        closeClusterMenu();
        if (S.focus !== null && !el.closest('.wmv-card, .wmv-bar, .wmv-zoombar, .wmv-panel, .wmv-minimap, .wmv-history')) unfocus();
    }, true);

    /** Address of the fullscreen page on the current view, for a wall screen */
    function copyKioskLink() {
        const url = location.origin + fullscreenUrl();
        const done = function () { if (window.WMUI) WMUI.toast(t('Kiosk link copied'), 'success'); };
        const fallback = function () {
            const area = document.createElement('textarea');
            area.value = url;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            let ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            area.remove();
            if (ok) done();
            else if (window.WMUI) WMUI.toast(fmt('Copy this link: :url', {url: url}), 'info');
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(done, fallback);
        } else {
            fallback();
        }
    }

    // ------------------------------------------------------------------
    // Refresh: new data every refresh interval, right away after an edit

    function checkVersion() {
        if (document.hidden) return;
        fetch(versionUrl, {cache: 'no-store', credentials: 'same-origin'})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.version) return;
                if (S.version !== null && data.version !== S.version && !H.on) load();
                S.version = data.version;
            })
            .catch(function () {});
    }

    // ------------------------------------------------------------------
    // History: play back the frames the server recorded (Admin > Recording)

    const H = {
        on: false, frames: [], i: 0, playing: false, timer: 0, cache: {}, want: null, busy: false,
        bar: root.querySelector('.wmv-history'),
        button: root.querySelector('[data-act="history"]'),
        live: root.querySelector('[data-act="live"]')
    };

    /** Live / recorded hours switch and the badge next to the title */
    function showMode(history) {
        if (H.button) H.button.setAttribute('aria-pressed', history ? 'true' : 'false');
        if (H.live) H.live.setAttribute('aria-pressed', history ? 'false' : 'true');
        if (badge) {
            badge.textContent = history ? badge.dataset.playback : badge.dataset.live;
            badge.classList.toggle('wmv-badge-playback', history);
        }
        root.classList.toggle('wmv-in-history', history);
    }

    function formatWhen(seconds) {
        const options = {weekday: 'short', hour: '2-digit', minute: '2-digit'};
        if (root.dataset.timezone) options.timeZone = root.dataset.timezone;
        try {
            return new Date(seconds * 1000).toLocaleString([], options);
        } catch (e) {
            delete options.timeZone;
            return new Date(seconds * 1000).toLocaleString([], options);
        }
    }

    function frameData(time) {
        if (!H.cache[time]) {
            H.cache[time] = fetch('/map/' + mapId + '/history/' + time, {credentials: 'same-origin'})
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                });
            H.cache[time].catch(function () { delete H.cache[time]; });
        }
        return H.cache[time];
    }

    /** Show frame i; while one loads, only the last one asked for is shown next */
    function showFrame(i) {
        H.i = Math.max(0, Math.min(H.frames.length - 1, i));
        const slider = H.bar.querySelector('.wmv-history-slider');
        slider.value = H.i;
        const time = H.frames[H.i];
        H.bar.querySelector('.wmv-history-time').textContent = formatWhen(time);
        H.want = time;
        if (H.busy) return;
        H.busy = true;
        frameData(time).then(function (data) {
            if (!H.on) return;
            installSvg(data.svg, data.items);
            // the time of the frame, rather than when it was drawn
            showState(Object.assign({}, data.status, {time: data.time || time}));
            showMessage('');
            // load the next one ahead, for a smooth playback
            if (H.frames[H.i + 1]) frameData(H.frames[H.i + 1]);
        }).catch(function () {
            showMessage(t('This frame could not be loaded.'), true);
        }).finally(function () {
            H.busy = false;
            if (H.on && H.want !== time) showFrame(H.frames.indexOf(H.want));
        });
    }

    function setPlaying(on) {
        H.playing = on && H.frames.length > 1;
        clearInterval(H.timer);
        const b = H.bar.querySelector('[data-hist="play"]');
        const use = b.querySelector('use');
        // keep the versioned sprite address the page gave
        use.setAttribute('href', use.getAttribute('href').split('#')[0] + '#' + (H.playing ? 'pause' : 'play'));
        b.setAttribute('aria-label', H.playing ? t('Pause') : t('Play'));
        if (!H.playing) return;
        if (H.i >= H.frames.length - 1) showFrame(0);
        H.timer = setInterval(function () {
            if (H.busy) return;
            if (H.i >= H.frames.length - 1) {
                setPlaying(false);
                return;
            }
            showFrame(H.i + 1);
        }, parseInt(H.bar.querySelector('.wmv-history-speed').value, 10));
    }

    function openHistory() {
        fetch('/map/' + mapId + '/history', {cache: 'no-store', credentials: 'same-origin'})
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                H.frames = data.frames || [];
                if (!H.frames.length) {
                    showMessage(t('Nothing recorded yet: the first frames come within a few minutes.'), true);
                    setTimeout(function () { showMessage(''); }, 5000);
                    return;
                }
                H.on = true;
                H.bar.hidden = false;
                showMode(true);
                const slider = H.bar.querySelector('.wmv-history-slider');
                slider.max = H.frames.length - 1;
                showFrame(0);
                setPlaying(true);
            })
            .catch(function () { showMessage(t('The recording could not be loaded.'), true); });
    }

    function closeHistory() {
        if (!H.on) return;
        setPlaying(false);
        H.on = false;
        H.bar.hidden = true;
        showMode(false);
        load();
    }

    if (H.bar && H.button) {
        H.button.addEventListener('click', function () { if (!H.on) openHistory(); });
        if (H.live) H.live.addEventListener('click', closeHistory);
        H.bar.querySelector('[data-hist="play"]').addEventListener('click', function () { setPlaying(!H.playing); });
        H.bar.querySelector('.wmv-history-slider').addEventListener('input', function (e) {
            setPlaying(false);
            showFrame(parseInt(e.target.value, 10));
        });
        H.bar.querySelector('.wmv-history-speed').addEventListener('change', function () {
            if (H.playing) setPlaying(true);
        });
        document.addEventListener('keydown', function (e) {
            const tag = (e.target && e.target.tagName) || '';
            if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) && e.target.type !== 'range') return;
            if (!keysForMap()) return;
            const KEYS = window.WMKeys || {is: function () { return false; }};
            if (KEYS.is(e, 'viewer.history')) {
                if (H.on) closeHistory(); else openHistory();
            } else if (H.on && KEYS.is(e, 'viewer.play')) {
                setPlaying(!H.playing);
            } else if (H.on && e.key === 'Escape') {
                closeHistory();
            } else {
                return;
            }
            e.preventDefault();
            e.stopImmediatePropagation();
        }, true);
    }

    // the link of the map settings drawer, on this very page
    window.addEventListener('hashchange', function () {
        if (!/(^#|&)play(&|=|$)/.test(location.hash)) return;
        history.replaceState(null, '', location.pathname + location.search);
        if (H.button && !H.on && S.el) openHistory();
    });

    load();
    setInterval(function () { if (!document.hidden && !H.on) load(); }, refreshMs);
    setInterval(checkVersion, 10000);
    document.addEventListener('visibilitychange', checkVersion);
})();
