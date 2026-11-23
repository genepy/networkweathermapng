/**
 * NetworkWeathermapNG - zoomable SVG view of a map (/map/{id}).
 *
 * The map comes as SVG from /map/{id}/svg (cached on the server like the
 * PNG). Wheel or pinch zooms at the pointer, drag pans. Floating controls
 * over the map: the bar at the top (node search, Live / recorded hours,
 * fullscreen, more actions, Edit), the zoom at the bottom right, the state
 * at the bottom left (the legend is a card drawn on the map); a minimap shows where the view
 * is when the map does not fit. In fullscreen the controls fade out after
 * a few seconds without movement. Zoom and position live in the URL hash
 * (#z=2&x=..&y=.., x/y the map point at the centre), so a shared link opens
 * on the same view. Clicking a node (or finding it) zooms onto it and
 * pins its card: its links with their load, the nodes at their other end,
 * its page; the rest of the map dims and the minimap frames it (#n=<node>
 * in the hash). Hovering a node frames it in the minimap too. A side
 * panel on the left (P, remembered per browser) lists the nodes grouped by
 * site (SET site), the total traffic of the map with a graph of the
 * recorded hours; hovering a node on the map
 * highlights it in the list, hovering it in the list frames it on the map
 * and in the minimap. A map may show only some of these panels, in the
 * side panel or in the corners, in its own order (SET viewer_panels). The
 * saved map is never changed.
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
    const areaMenu = root.querySelector('.wmv-areas');
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
    const MAX_Z_BASEMAP = window.WMGeo ? window.WMGeo.MAX_ZOOM : 64;
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
        // on a basemap, no further out than it can follow (F64)
        const min = B.cfg && window.WMBasemap ? Math.max(MIN_Z, window.WMBasemap.minScale(B.cfg, vpSize().h)) : MIN_Z;
        return Math.min(B.cfg ? MAX_Z_BASEMAP : MAX_Z, Math.max(min, z));
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
        // on a basemap, the world covers the screen from top to bottom:
        // MapLibre would move its centre there, and the nodes with it (F64)
        if (B.cfg && window.WMBasemap) {
            const rows = window.WMBasemap.worldRows(B.cfg, S.h);
            if ((rows[1] - rows[0]) * S.z >= vp.h) {
                S.ty = Math.min(-rows[0] * S.z, Math.max(vp.h - rows[1] * S.z, S.ty));
            }
        }
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
        placeMapDock();
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
            // a focus area other than the one the map opens on ("-": the whole map)
            if (A.current !== A.def) hash += (hash ? '&' : '#') + 'a=' + encodeURIComponent(A.current === null ? '-' : A.current);
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
        if (p.has('a')) A.hash = p.get('a');
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

    /**
     * SET icon_spin 1: the icon of the node turns with the load of its
     * busiest link (item.spin, in %): stopped at 0, one turn a second at
     * 100 %. CSS stops it for less motion and while the tab is hidden.
     */
    function spinIcons(svg) {
        svg.querySelectorAll('g[data-kind="node"]').forEach(function (g) {
            const it = S.items[g.getAttribute('data-id')];
            const load = it && typeof it.spin === 'number' ? it.spin : 0;
            g.querySelectorAll('image.wm-icon').forEach(function (icon) {
                icon.classList.toggle('wmv-spin', load > 0);
                if (load > 0) icon.style.setProperty('--wmv-spin', Math.min(20, 100 / load).toFixed(2) + 's');
            });
        });
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
        spinIcons(svg);
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
        markArea();
    }

    // ---- Link width deep in a basemap ----
    // Located nodes keep their size at 100 % when zooming in (see below);
    // the links do the same, or a few zoom levels down they would cover
    // the city with huge arrows. Each arrow of the SVG carries its spine,
    // its half width, the half width and the length of its head
    // (data-spine, data-w, data-aw, data-as): it is drawn again from them
    // at 1 / zoom of its width. Its outline stays one pixel wide. The
    // bandwidth labels shrink as much around their centre (data-at), so
    // they keep their size at 100 % next to the nodes and the links.
    const L = {arrows: [], labels: [], k: 1};

    function setupLinks(svg) {
        L.arrows = window.WMGeo ? window.WMGeo.arrows(svg) : [];
        L.labels = window.WMGeo ? window.WMGeo.labels(svg) : [];
        L.k = 1;
        root.classList.remove('wmv-links-thin');
        root.style.removeProperty('--wm-geo-k');
    }

    /** Draw the arrows at k times their width (1: as the SVG has them) */
    function thinLinks(k) {
        k = Math.round(k * 1000) / 1000;
        if (k === L.k) return;
        L.k = k;
        root.classList.toggle('wmv-links-thin', k < 1);
        // the hover and focus glows shrink with them (viewer.css)
        root.style.setProperty('--wm-geo-k', String(k));
        if (window.WMGeo) {
            window.WMGeo.thin(L.arrows, k);
            window.WMGeo.scaleLabels(L.labels, k);
            reachClusters(true);
        }
    }

    /** The node each arrow starts from (the end of its link nearer its start), once the located nodes are known */
    function arrowEnds() {
        L.arrows.forEach(function (a) {
            a.from = null;
            if (a.sep || !a.el.closest) return;
            const g = a.el.closest('g.wm-link[data-id]');
            const e = g ? S.ends[g.getAttribute('data-id')] : null;
            const na = e ? G.byName[e.a] : null, nb = e ? G.byName[e.b] : null;
            if (!na && !nb) return;
            const p = a.pts[0];
            const da = na ? Math.hypot(na.x - p[0], na.y - p[1]) : Infinity;
            const db = nb ? Math.hypot(nb.x - p[0], nb.y - p[1]) : Infinity;
            a.from = (da <= db ? na : nb).name;
        });
    }

    /**
     * The links of a node in a cluster reach its badge: each arrow that
     * starts from such a node starts from the centre of the cluster, or it
     * would stop short of the badge (F64). `all` redraws every arrow that
     * did, after the links were drawn thinner.
     */
    function reachClusters(all) {
        if (!window.WMGeo) return;
        const at = {};
        G.clusters.forEach(function (c) {
            c.nodes.forEach(function (n) { at[n.name] = c; });
        });
        const k = L.k;
        L.arrows.forEach(function (a) {
            const c = a.from ? at[a.from] : null;
            if (!c) {
                if (a.reached) {
                    a.reached = false;
                    const pts = k < 1 ? window.WMGeo.arrowOutline(window.WMGeo.reachNodes(a.pts, a.cut, k), a.w * k, a.aw * k, a.as * k) : null;
                    a.el.setAttribute('points', pts || a.points);
                }
                return;
            }
            if (a.reached === c && !all) return;
            const spine = (k < 1 ? window.WMGeo.reachNodes(a.pts, a.cut, k) : a.pts).slice();
            spine.unshift([c.x, c.y]);
            const pts = window.WMGeo.arrowOutline(spine, a.w * k, a.aw * k, a.as * k);
            if (pts) {
                a.el.setAttribute('points', pts);
                a.reached = c;
            }
        });
    }

    // ---- Traffic flow (SET link_flow 1) ----
    // The SVG carries an invisible path per direction of each link
    // (path.wm-flow, from where the traffic leaves to the arrow head), its
    // load in % and the half width of its arrow. One light spot crosses
    // each of them at a time, as wide as the link and faster on busier
    // links; deep in a basemap it gets as much thinner as the link.
    // No spots under prefers-reduced-motion; paused while the tab is hidden.
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

    /** A band of light, clear at both ends and bright in the middle, the whole height of the spot */
    function flowGradient(svg) {
        const defs = document.createElementNS(SVG_NS, 'defs');
        const g = document.createElementNS(SVG_NS, 'linearGradient');
        g.id = 'wmv-flow-glow';
        [['0', '0'], ['0.3', '0.45'], ['0.5', '0.85'], ['0.7', '0.45'], ['1', '0']].forEach(function (s) {
            const stop = document.createElementNS(SVG_NS, 'stop');
            stop.setAttribute('offset', s[0]);
            stop.setAttribute('stop-color', '#fff');
            stop.setAttribute('stop-opacity', s[1]);
            g.appendChild(stop);
        });
        defs.appendChild(g);
        // the glow around the spot: fades out across the link and along it
        const halo = document.createElementNS(SVG_NS, 'radialGradient');
        halo.id = 'wmv-flow-halo';
        [['0', '0.5'], ['0.6', '0.3'], ['1', '0']].forEach(function (s) {
            const stop = document.createElementNS(SVG_NS, 'stop');
            stop.setAttribute('offset', s[0]);
            stop.setAttribute('stop-color', '#fff');
            stop.setAttribute('stop-opacity', s[1]);
            halo.appendChild(stop);
        });
        defs.appendChild(halo);
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
            // the half width of the arrow: the spot covers the whole link
            const half = parseFloat(path.getAttribute('data-width')) || 2;
            // stretched along the link, never longer than a third of it
            const long = Math.min(len / 3, Math.max(16, half * 6));
            // px per second: a quiet link crawls, a full one runs
            const speed = 18 + 1.6 * Math.min(load, 100);
            // a bar as wide as the link, and a soft glow a little past its
            // edges so the link looks less flat
            const extra = Math.max(1.5, half * 0.2);
            const dot = document.createElementNS(SVG_NS, 'g');
            dot.setAttribute('class', 'wmv-flow-dot');
            dot.setAttribute('pointer-events', 'none');
            [[half + extra, 'url(#wmv-flow-halo)'], [half, 'url(#wmv-flow-glow)']].forEach(function (r) {
                const rect = document.createElementNS(SVG_NS, 'rect');
                rect.setAttribute('x', String(-long / 2));
                rect.setAttribute('y', String(-r[0]));
                rect.setAttribute('width', String(long));
                rect.setAttribute('height', String(r[0] * 2));
                rect.setAttribute('fill', r[1]);
                dot.appendChild(rect);
            });
            path.after(dot);
            F.dots.push({el: dot, pts: pts, len: len, fade: long / 2, speed: speed, phase: (k * 0.618) % 1, at: 1});
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
            dot.el.setAttribute('opacity', Math.max(0, Math.min(1, d / dot.fade, (dot.len - d) / dot.fade)).toFixed(2));
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
        // the turning icons (SET icon_spin) rest too
        root.classList.toggle('wmv-tab-hidden', document.hidden);
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
        img.src = root.dataset.pngUrl + '&t=' + Date.now();
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
                setAreas(data.focus || null);
                installSvg(data.svg, data.items);
                // the kiosk has a layout of its own when the map sets one (SET kiosk_panels), with a side panel
                if (kioskFrame && data.kiosk_panels) setPanels(data.kiosk_panels, true);
                else setPanels(data.panels || null, false);
                startArea();
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

    // Time of a render, in the map's time zone and clock, in the language of the interface (WMUI.time, ui.js)
    function formatTime(seconds) {
        return window.WMUI.time(seconds, {zone: root.dataset.timezone || ''});
    }

    /** Name of a time zone for people: "Europe/Paris" stays, "UTC" too */
    function zoneLabel() {
        return root.dataset.timezone || '';
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

    const INSET_ZOOM = 11;
    // past the zoom where they would be this big on the screen at their size
    // at 100 % (they keep that size), bandwidth labels are hidden on a basemap
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
                // where the node is drawn (its point rounded to whole map
                // pixels): its links end there, so it shrinks around it, as
                // in the editor, or deep in the map its links would miss it
                const p = Array.isArray(it.at) && it.at.length === 2
                    ? [Number(it.at[0]), Number(it.at[1])]
                    : window.WMBasemap.project(B.cfg, S.w, S.h, it.geo[0], it.geo[1]);
                let size = 24;
                try {
                    const b = g.getBBox();
                    size = Math.max(12, Math.min(b.width, b.height));
                } catch (e) { /* not drawn */ }
                const n = {
                    name: g.getAttribute('data-name'), g: g, lat: it.geo[0], lon: it.geo[1],
                    x: p[0], y: p[1], size: size, site: typeof it.site === 'string' ? it.site : '',
                    group: typeof it.cluster === 'string' ? it.cluster : ''
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
        arrowEnds();
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

    /** Merge the nodes too close at this zoom, as the map's cluster mode says; the focused node stays apart */
    function regroup() {
        showClusters(window.WMGeo ? window.WMGeo.clusters(G.nodes, S.z, window.WMGeo.modeOf(B.cfg), S.focus) : []);
    }

    function clusterLabel(c) {
        const names = c.nodes.map(function (n) { return labelOf(n.g); }).join(', ');
        return c.group
            ? fmt(':group, :n nodes: :names', {group: c.group, n: c.nodes.length, names: names})
            : fmt(':n nodes close together: :names', {n: c.nodes.length, names: names});
    }

    /** Draw the badges of the clusters (only when they change) and hide their nodes */
    function showClusters(groups) {
        const key = groups.map(function (g) {
            return g.group + '\u0003' + g.nodes.map(function (n) { return n.name; }).join('\u0001');
        }).join('\u0002');
        if (key === G.key) return;
        G.key = key;
        closeClusterMenu();
        const hidden = new Set();
        G.clusters = groups.map(function (group) {
            const nodes = group.nodes;
            let x = 0, y = 0, load = -1;
            nodes.forEach(function (n) {
                x += n.x;
                y += n.y;
                load = Math.max(load, nodeLoad(n.name));
                hidden.add(n);
            });
            const c = {nodes: nodes, group: group.group, x: x / nodes.length, y: y / nodes.length, el: null};
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'wmv-cluster';
            b.dataset.level = levelOf(load);
            b.textContent = String(nodes.length);
            if (c.group) {
                // a group the author made (SET cluster_mode groups): its name under the badge
                const name = document.createElement('span');
                name.className = 'wmv-cluster-name';
                name.textContent = c.group;
                b.appendChild(name);
            }
            const label = clusterLabel(c);
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
        reachClusters(false);
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
        c.nodes.forEach(function (n) {
            x0 = Math.min(x0, n.x); y0 = Math.min(y0, n.y);
            x1 = Math.max(x1, n.x); y1 = Math.max(y1, n.y);
        });
        // the two closest apart, with some room (0: nodes on the same point never split, they are listed)
        const apart = window.WMGeo.splitZoom(c.nodes);
        if (!apart) {
            showClusterMenu(c);
            return;
        }
        const vp = vpSize();
        const pad = 100;
        // all of them in view too, unless a group must split as a whole
        const fitAll = Math.min((vp.w - 2 * pad) / Math.max(1, x1 - x0), (vp.h - 2 * pad) / Math.max(1, y1 - y0));
        const z = clampZ(c.group ? apart : Math.min(fitAll, apart));
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
        showSourceWarning(status);
        showTotal(status.total);
        dockSizes();
    }

    /**
     * A data source that failed in the last render (F49): a warning next
     * to the update time ("Observium unreachable: 12 links without data"),
     * and a small badge in the kiosk. Links without data keep their look.
     */
    function sourceWarning(status) {
        const failed = (status && status.sources) || [];
        if (!failed.length) return '';
        return failed.map(function (s) {
            const links = s.links === 1 ? t('1 link without data') : fmt(':n links without data', {n: s.links});
            const p = {source: s.name, links: links};
            return s.down ? fmt(':source unreachable: :links', p) : fmt(':source error: :links', p);
        }).join(' · ');
    }

    function showSourceWarning(status) {
        const text = sourceWarning(status);
        const warn = statusBox.querySelector('.wmv-status-warn');
        if (warn) {
            warn.hidden = text === '';
            warn.querySelector('.wmv-status-warn-text').textContent = text;
            warn.title = text;
        }
        const badge = root.querySelector('.wmv-warn-badge');
        if (badge) {
            badge.hidden = text === '';
            badge.querySelector('span').textContent = text;
            badge.title = text;
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
    // Side panel: sites, total traffic

    const PANEL_KEY = 'wmv-panel';
    const P = {
        open: {}, rows: {}, hover: null, total: null,
        // the total traffic graph (F29): its answer from /map/{id}/totals, for which period and group
        totals: null, totalsDemo: false, totalsAt: 0, totalsBusy: false, totalsKey: '',
        period: storedPeriod(), group: '', groups: [], periods: null, stats: null, aggregated: false
    };
    // where the total traffic graph comes from: recorded, demo or none (TotalTrafficSeries.php)
    const totalsSource = root.dataset.totals || 'none';

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
        const btn = panel && root.querySelector('[data-act="sites-all"]');
        if (!btn) return;
        const toggles = root.querySelectorAll('.wmv-sites .wmv-site-toggle');
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
        const toggles = panel ? root.querySelectorAll('.wmv-sites .wmv-site-toggle') : [];
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
        const sites = panel ? root.querySelector('.wmv-sites') : null;
        if (!inView(sites) || !S.el || !S.el.querySelectorAll) return;
        const list = sites.querySelector('.wmv-site-list');
        const title = sites.querySelector('#wmv-sites-title');
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
            sites.querySelector('.wmv-panel-count').textContent = String(count);
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
            sites.querySelector('.wmv-panel-count').textContent = String(count);
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
        const sites = panel ? root.querySelector('.wmv-sites') : null;
        if (!inView(sites)) return;
        sites.querySelectorAll('.wmv-row-hover').forEach(function (el) { el.classList.remove('wmv-row-hover'); });
        const row = name !== null ? P.rows[name] : null;
        if (!row) return;
        const nodes = row.closest('.wmv-site-nodes');
        // in a closed site, its heading stands out
        const target = nodes && nodes.hidden ? nodes.parentNode.querySelector('.wmv-site-toggle') : row;
        target.classList.add('wmv-row-hover');
        const list = sites.querySelector('.wmv-site-list');
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
    // Nine IX style card (F29): tabs for Total and the link groups (SET
    // traffic_group), a period switch (1 day, 1 week, 1 month, the ones
    // without history greyed out), IN above and OUT below the line, the
    // 95th percentile and peak of the period, and a click on the graph
    // opens it larger (WM.trafficPanel, drag to zoom in).

    // (storedPeriod() runs when P is made, before these lines: no constants in it)
    const PERIOD_KEY = 'wmv-total-period';

    function storedPeriod() {
        try {
            const v = localStorage.getItem('wmv-total-period');
            return v === 'week' || v === 'month' ? v : 'day';
        } catch (e) { return 'day'; }
    }

    function periodText(p) {
        return p === 'week' ? t('1 week') : p === 'month' ? t('1 month') : t('1 day');
    }

    /** A group name as a tab shows it: its first letter in capitals */
    function groupText(g) {
        return g === '' ? t('Total') : g.charAt(0).toLocaleUpperCase() + g.slice(1);
    }

    /** The traffic now of the links of a group, from the items of the map (as MapService::trafficTotal) */
    function groupNow(group) {
        let vin = null, vout = null, links = 0;
        Object.keys(S.items).forEach(function (id) {
            const it = S.items[id];
            if (!it || it.kind !== 'link' || (it.group || '') !== group) return;
            const hasIn = typeof it.in === 'number', hasOut = typeof it.out === 'number';
            if (!hasIn && !hasOut) return;
            links++;
            if (hasIn) vin = (vin || 0) + it.in;
            if (hasOut) vout = (vout || 0) + it.out;
        });
        return {in: vin, out: vout, links: links};
    }

    function showTotal(total) {
        P.total = total || null;
        if (!panel) return;
        const sec = root.querySelector('.wmv-total');
        if (!total || (total.in === null && total.out === null)) {
            sec.hidden = true;
            return;
        }
        sec.hidden = false;
        showNow();
        loadTotals();
        drawTotals();
    }

    /** IN and OUT now, and the link count, of the tab shown */
    function showNow() {
        const sec = root.querySelector('.wmv-total');
        const now = P.group === '' ? P.total : groupNow(P.group);
        if (!sec || !now) return;
        sec.querySelector('.wmv-total-in dd').textContent = rate(now.in);
        sec.querySelector('.wmv-total-out dd').textContent = rate(now.out);
        sec.querySelector('.wmv-total-links').textContent = now.links === 1 ? t('1 link') : fmt(':n links', {n: now.links});
    }

    function totalsQuery() {
        return 'period=' + encodeURIComponent(P.period) + (P.group ? '&group=' + encodeURIComponent(P.group) : '');
    }

    /**
     * The graph data of the period and group shown, again at most once per
     * refresh interval: the recorded frames, or a demo period for a map of
     * static data (data-totals, see TotalTrafficSeries.php). Without
     * either, a note says how to get it.
     */
    function loadTotals() {
        if (!panel || !inView(root.querySelector('.wmv-total')) || P.totalsBusy) return;
        if (totalsSource === 'none') return;
        const key = totalsQuery();
        if (P.totals && P.totalsKey === key && Date.now() - P.totalsAt < refreshMs) return;
        P.totalsBusy = true;
        fetch('/map/' + mapId + '/totals?' + key, {cache: 'no-store', credentials: 'same-origin'})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                P.totals = data && Array.isArray(data.frames) ? data.frames : [];
                P.totalsDemo = !!data && data.source === 'demo';
                P.periods = data && data.periods ? data.periods : null;
                // the period the graph spans, and the start of the recording
                P.span = data && typeof data.from === 'number' && typeof data.to === 'number' && data.to > data.from ? [data.from, data.to] : null;
                P.since = data && typeof data.since === 'number' ? data.since : null;
                P.groups = data && Array.isArray(data.groups) ? data.groups : [];
                P.stats = data && data.stats ? data.stats : null;
                P.aggregated = !!(data && data.aggregated);
                P.totalsAt = Date.now();
                P.totalsKey = key;
                if (data && typeof data.group === 'string' && data.group !== P.group) {
                    // the group is gone from the map: back to the total
                    P.group = data.group;
                    P.totalsKey = totalsQuery();
                    showNow();
                }
                drawTotals();
            })
            .catch(function () { P.totals = []; P.totalsAt = Date.now(); P.totalsKey = key; })
            .finally(function () { P.totalsBusy = false; });
    }

    function percentile(values, p) {
        if (!values.length) return null;
        const v = values.slice().sort(function (a, b) { return a - b; });
        return v[Math.min(v.length - 1, Math.ceil(p * v.length) - 1)];
    }

    /** The tabs Total and the link groups; none without groups */
    function drawTotalTabs(sec) {
        const tabs = sec.querySelector('.wmv-total-tabs');
        if (!tabs) return;
        const names = [''].concat(P.groups);
        tabs.hidden = names.length < 2;
        const sig = names.join('\n') + '\n' + P.group;
        if (tabs.dataset.sig === sig) return;
        tabs.dataset.sig = sig;
        tabs.replaceChildren.apply(tabs, names.map(function (g) {
            const b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('role', 'tab');
            b.dataset.group = g;
            b.textContent = g === '' ? (tabs.dataset.total || t('Total')) : groupText(g);
            b.setAttribute('aria-selected', g === P.group ? 'true' : 'false');
            b.tabIndex = g === P.group ? 0 : -1;
            return b;
        }));
    }

    /** The period switch: the period shown pressed, the ones without enough history greyed out */
    function drawPeriods(sec) {
        const box = sec.querySelector('.wmv-total-periods');
        if (!box) return;
        box.hidden = totalsSource === 'none' || !P.periods;
        // every period can be chosen, as in the zoomed graph: a short
        // history shows what exists, with since when it is recorded
        box.querySelectorAll('[data-period]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-period') === P.period ? 'true' : 'false');
        });
    }

    /** IN above the line, OUT below it, over the period shown; its 95th percentile and peak */
    function drawTotals() {
        if (!panel) return;
        const sec = root.querySelector('.wmv-total');
        const graph = sec.querySelector('.wmv-total-graph');
        const plot = sec.querySelector('.wmv-total-plot');
        const stats = sec.querySelector('.wmv-total-stats');
        const foot = sec.querySelector('.wmv-total-foot');
        drawTotalTabs(sec);
        drawPeriods(sec);
        const frames = (P.totals || []).filter(function (f) { return f[1] !== null || f[2] !== null; });
        if (frames.length < 2) {
            // an <svg> has no hidden property: set the attribute
            graph.setAttribute('hidden', '');
            stats.hidden = true;
            foot.hidden = true;
            zoomable(plot, false);
            // why there is no graph: recording is off, or it has only just started
            showTotalsNote(totalsSource === 'none' ? 'off' : (totalsSource === 'recorded' && P.totals ? 'wait' : null));
            return;
        }
        showTotalsNote(null);
        const W = 260, GH = 64, mid = GH / 2;
        const ins = frames.map(function (f) { return f[1] || 0; });
        const outs = frames.map(function (f) { return f[2] || 0; });
        const top = Math.max.apply(null, ins.concat(outs)) || 1;
        // the time axis spans the whole period, also when the history is shorter
        const t0 = P.span ? P.span[0] : frames[0][0], t1 = P.span ? P.span[1] : frames[frames.length - 1][0];
        const x = function (time) { return t1 > t0 ? Math.max(0, Math.min(W, (time - t0) / (t1 - t0) * W)) : 0; };
        const area = function (vals, sign) {
            let d = 'M' + x(frames[0][0]).toFixed(1) + ' ' + mid;
            frames.forEach(function (f, i) {
                d += ' L' + x(f[0]).toFixed(1) + ' ' + (mid - sign * vals[i] / top * (mid - 2)).toFixed(1);
            });
            return d + ' L' + x(frames[frames.length - 1][0]).toFixed(1) + ' ' + mid + ' Z';
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
            if (at >= t0 && at <= t1) parts.push(mk('wmv-total-now-mark', 'M' + x(at).toFixed(1) + ' 0 V' + GH));
        }
        graph.replaceChildren.apply(graph, parts);
        graph.setAttribute('aria-label', fmt('Total traffic over :period, IN above the line and OUT below', {period: periodText(P.period)}));
        graph.removeAttribute('hidden');
        zoomable(plot, true);
        // worked out by the server on every point of the period, before they are averaged down
        const st = P.stats || {};
        const val = function (dir, key, values, p) {
            return st[dir] && typeof st[dir][key] === 'number' ? st[dir][key] : (p === 1 ? Math.max.apply(null, values) : percentile(values, p));
        };
        stats.replaceChildren.apply(stats, []
            .concat(statLine(t('95th percentile'), val('in', 'p95', ins, 0.95), val('out', 'p95', outs, 0.95)))
            .concat(statLine(t('Peak'), val('in', 'peak', ins, 1), val('out', 'peak', outs, 1))));
        stats.hidden = false;
        const line = [];
        if (P.totalsDemo) line.push(t('Demo data'));
        if (P.since && P.span && P.since > P.span[0] + (P.span[1] - P.span[0]) / 100) {
            line.push(fmt('Recorded since :date', {date: new Date(P.since * 1000).toLocaleDateString(document.documentElement.lang || undefined, {day: 'numeric', month: 'short'})}));
        }
        if (P.aggregated) line.push(plot.dataset.zoom ? t('Aggregated data, click to zoom') : t('Aggregated data'));
        else if (plot.dataset.zoom) line.push(t('Click to zoom'));
        foot.textContent = line.join(' · ');
        foot.hidden = !line.length;
    }

    /** The graph opens larger on a click, outside the kiosk */
    function zoomable(plot, on) {
        if (!plot || !plot.dataset.zoom) return;
        if (on) {
            plot.setAttribute('role', 'button');
            plot.tabIndex = 0;
            plot.title = plot.dataset.zoom;
            plot.setAttribute('aria-label', plot.dataset.zoom);
        } else {
            plot.removeAttribute('role');
            plot.removeAttribute('tabindex');
            plot.removeAttribute('title');
            plot.removeAttribute('aria-label');
        }
    }

    let totalZoom = null;   // the WM.trafficPanel of the zoomed graph

    /** The graph of the tab and period shown, larger, in a dialog: drag across it to zoom in */
    function openTotalZoom() {
        const dlg = root.querySelector('.wmv-total-dialog');
        if (!dlg || !window.WM || !WM.trafficPanel || typeof dlg.showModal !== 'function') return;
        dlg.querySelector('h2').textContent = t('Total traffic') + (P.group ? ' · ' + groupText(P.group) : '');
        const box = dlg.querySelector('.wmv-total-dialog-plot');
        if (totalZoom) totalZoom.clear();
        totalZoom = WM.trafficPanel(box, {range: P.period});
        if (!dlg.open) dlg.showModal();
        totalZoom.show({info: {traffic: '/map/' + mapId + '/totals?format=series' + (P.group ? '&group=' + encodeURIComponent(P.group) : '')}, selection: null});
    }

    function closeTotalZoom() {
        const dlg = root.querySelector('.wmv-total-dialog');
        if (dlg && dlg.open) dlg.close();
    }

    (function wireTotal() {
        const sec = root.querySelector('.wmv-total');
        if (!sec) return;
        sec.addEventListener('click', function (e) {
            const tab = e.target.closest('[data-group]');
            if (tab && sec.contains(tab)) {
                if (tab.dataset.group === P.group) return;
                P.group = tab.dataset.group;
                showNow();
                drawTotalTabs(sec);
                loadTotals();
                return;
            }
            const per = e.target.closest('[data-period]');
            if (per && sec.contains(per)) {
                const p = per.getAttribute('data-period');
                if (p === P.period) return;
                P.period = p;
                try { localStorage.setItem(PERIOD_KEY, p); } catch (err) { /* private mode */ }
                drawPeriods(sec);
                loadTotals();
                return;
            }
            const plot = e.target.closest('.wmv-total-plot[role="button"]');
            if (plot) openTotalZoom();
        });
        sec.addEventListener('keydown', function (e) {
            const plot = e.target.closest && e.target.closest('.wmv-total-plot[role="button"]');
            if (plot && (e.key === 'Enter' || e.key === ' ')) {
                e.preventDefault();
                openTotalZoom();
                return;
            }
            // the arrow keys move between the tabs
            const tab = e.target.closest && e.target.closest('[role="tab"]');
            if (!tab || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
            const list = Array.prototype.slice.call(tab.parentNode.children);
            const next = list[(list.indexOf(tab) + (e.key === 'ArrowRight' ? 1 : list.length - 1)) % list.length];
            e.preventDefault();
            next.focus();
            next.click();
        });
        const dlg = root.querySelector('.wmv-total-dialog');
        if (dlg) {
            dlg.addEventListener('click', function (e) {
                // the close button, or a click on the backdrop
                if (e.target === dlg || e.target.closest('[data-act="total-close"]')) closeTotalZoom();
            });
            dlg.addEventListener('close', function () {
                if (totalZoom) totalZoom.clear();
                totalZoom = null;
            });
        }
    })();

    /** The note under the totals: 'off' (recording is off), 'wait' (too few images yet) or null */
    function showTotalsNote(kind) {
        const note = root.querySelector('.wmv-total-note');
        if (!note) return;
        note.hidden = kind === null;
        note.querySelector('.wmv-total-note-off').hidden = kind !== 'off';
        note.querySelector('.wmv-total-note-wait').hidden = kind !== 'wait';
        const link = note.querySelector('.wmv-total-note-link');
        if (link) link.hidden = kind !== 'off';
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

    /** Open the panel as the user left it: open on a wide screen until they say otherwise */
    function restorePanel() {
        const stored = storedPanel();
        setPanel(stored !== null ? stored === '1' : vpSize().w >= 1100, false);
    }

    // the kiosk has a side panel only when the map sets its kiosk panels (SET kiosk_panels)
    if (panel && !kioskFrame) restorePanel();

    // ------------------------------------------------------------------
    // Viewer panels (SET viewer_panels, see ViewerPanels.php): which of
    // the sites, the total traffic and the update time are
    // shown, and where: in the side panel, in a corner of the map, in their
    // order. A map without the hint keeps the usual layout untouched.

    const DOCKS = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
    const D = {
        applied: false,     // the panels were moved from their usual places
        list: null,         // the panels of the map: [{id, position, shown}], null for the usual layout
        kioskSide: false,   // the kiosk's own layout (SET kiosk_panels): it has a side panel
        docks: {},          // corner => its box
        mapDock: null,      // the box of the status bar placed on the map (updated:map:<x>,<y>, F58)
        mapAt: null,        // its point on the map, [x, y]
        off: null,          // the box that keeps the hidden panels
        home: null,         // where the update time, the inset and the panel close button sit in the usual layout
        sideEmpty: false,   // the map puts nothing in the side panel
        wasPhone: false,
        timer: 0
    };

    function panelEl(id) {
        switch (id) {
            case 'sites': return root.querySelector('.wmv-sites');
            case 'total': return root.querySelector('.wmv-total');
            case 'updated': return statusBox;
        }
        return null;
    }

    /** Whether a panel is in view: in a corner, or in the open side panel */
    function inView(el) {
        if (!el) return false;
        if (el.closest('.wmv-dock')) return true;
        return !!(panel && !panel.hidden && panel.contains(el));
    }

    function makeDocks() {
        if (D.off) return;
        const before = root.querySelector('.wmv-minimap');
        DOCKS.forEach(function (where) {
            const box = document.createElement('div');
            // no fading in fullscreen: a wall screen keeps the panels the map placed
            box.className = 'wmv-dock wmv-dock-' + where;
            box.hidden = true;
            root.insertBefore(box, before);
            D.docks[where] = box;
        });
        // the status bar on the map: in the viewport, moved and zoomed with the map
        D.mapDock = document.createElement('div');
        D.mapDock.className = 'wmv-dock-map';
        D.mapDock.hidden = true;
        viewport.appendChild(D.mapDock);
        D.off = document.createElement('div');
        D.off.className = 'wmv-dock-off';
        D.off.hidden = true;
        root.appendChild(D.off);
        const close = panel ? panel.querySelector('[data-act="panel-close"]') : null;
        D.home = {
            status: {parent: statusBox.parentNode, next: statusBox.nextSibling},
            inset: insetBox ? {parent: insetBox.parentNode, next: insetBox.nextSibling} : null,
            close: close ? {el: close, parent: close.parentNode, next: close.nextSibling} : null
        };
    }

    /** Back to the usual layout, as the page was drawn */
    function resetPanels() {
        if (!D.applied) return;
        ['sites', 'total'].forEach(function (id) {
            const el = panelEl(id);
            if (el && panel) panel.appendChild(el);
        });
        const h = D.home;
        h.status.parent.insertBefore(statusBox, h.status.next);
        if (h.inset) h.inset.parent.insertBefore(insetBox, h.inset.next);
        if (h.close) h.close.parent.insertBefore(h.close.el, h.close.next);
        DOCKS.forEach(function (where) { D.docks[where].hidden = true; });
        D.mapDock.hidden = true;
        D.mapAt = null;
        root.classList.remove('wmv-panels-set');
        kioskSidePanel(false);
        if (panelButton) panelButton.hidden = false;
        D.applied = false;
        if (D.sideEmpty && !kioskFrame) restorePanel();
        D.sideEmpty = false;
    }

    /** Put each panel where the map says, or back to the usual layout */
    function layoutPanels() {
        if (!D.list) {
            resetPanels();
            return;
        }
        makeDocks();
        D.applied = true;
        root.classList.add('wmv-panels-set');
        const phone = isPhone();
        const side = [];
        D.list.forEach(function (p) {
            const el = panelEl(p.id);
            if (!el) return;
            let where = p.shown ? p.position : 'off';
            // a phone has no room for cards in the corners: they go in the panel sheet, the update time stays small
            if (phone && where !== 'off' && where !== 'side' && p.id !== 'updated') where = 'side';
            // no side panel in the kiosk, unless it has its own layout
            if (where === 'side' && ((kioskFrame && !D.kioskSide) || !panel)) where = 'off';
            if (where === 'map' && !(p.at && p.at.length === 2)) where = 'bottom-left';
            if (where === 'off') D.off.appendChild(el);
            else if (where === 'map') {
                D.mapDock.appendChild(el);
                D.mapAt = p.at;
            } else if (where === 'side') side.push(el);
            else D.docks[where].appendChild(el);
        });
        side.forEach(function (el) { panel.appendChild(el); });
        // the city inset stays at the top right, under the panels placed there
        if (insetBox) {
            if (D.docks['top-right'].querySelector('.wmv-panel-sec, .wmv-status')) D.docks['top-right'].appendChild(insetBox);
            else D.home.inset.parent.insertBefore(insetBox, D.home.inset.next);
        }
        // the close button of the side panel heads its first panel
        const close = D.home.close;
        if (close) {
            const first = panel.firstElementChild;
            const head = first ? first.querySelector('.wmv-panel-head') : null;
            if (head) head.appendChild(close.el);
            else if (first) first.appendChild(close.el);
            else close.parent.insertBefore(close.el, close.next);
        }
        // the kiosk's side panel: always open, the map fitted beside it
        if (kioskFrame) kioskSidePanel(side.length > 0);
        DOCKS.forEach(function (where) { D.docks[where].hidden = !D.docks[where].children.length; });
        D.mapDock.hidden = !D.mapDock.children.length;
        if (D.mapDock.hidden) D.mapAt = null;
        placeMapDock();
        // nothing left for the side panel: no panel and no button for it
        if (panel && !kioskFrame) {
            if (panelButton) panelButton.hidden = !side.length;
            if (!side.length) {
                if (!panel.hidden) setPanel(false, false);
            } else if (D.sideEmpty) {
                restorePanel();
            }
            D.sideEmpty = !side.length;
        }
        D.wasPhone = phone;
        dockSizes();
        fillSites();
        loadTotals();
        drawTotals();
    }

    /**
     * The status bar placed on the map (F58): its top left corner at its
     * map point, scaled with the zoom, so it moves and zooms with the map.
     */
    function placeMapDock() {
        if (!D.mapDock || D.mapDock.hidden || !D.mapAt) return;
        D.mapDock.style.transform = 'translate(' + (S.tx + D.mapAt[0] * S.z) + 'px,' + (S.ty + D.mapAt[1] * S.z) + 'px) scale(' + S.z + ')';
    }

    /** The zoom buttons and the bottom left corner, measured, so the corners and the side panel make room */
    function dockSizes() {
        if (!D.applied) return;
        const zoom = root.querySelector('.wmv-zoombar');
        root.style.setProperty('--wmv-zoombar-h', (zoom && zoom.getClientRects().length ? zoom.offsetHeight : 0) + 'px');
        const bl = D.docks['bottom-left'];
        root.style.setProperty('--wmv-dock-bl-h', (bl.hidden ? 0 : bl.offsetHeight) + 'px');
    }

    function setPanels(list, kioskSide) {
        const next = Array.isArray(list) && list.length ? list : null;
        if (JSON.stringify(next) === JSON.stringify(D.list) && D.applied === !!next && D.kioskSide === !!kioskSide) return;
        D.list = next;
        D.kioskSide = !!kioskSide;
        layoutPanels();
    }

    /** Opens or closes the kiosk's side panel; the map is fitted again in the room left */
    function kioskSidePanel(open) {
        if (!kioskFrame || !panel) return;
        const was = root.classList.contains('wmv-kiosk-side');
        root.classList.toggle('wmv-kiosk-side', open);
        setPanel(open, false);
        if (was !== open && S.el) refit();
    }

    window.addEventListener('resize', function () {
        if (!D.list) return;
        clearTimeout(D.timer);
        D.timer = setTimeout(function () {
            // the corners change between a phone and a wider window
            if (isPhone() !== D.wasPhone) layoutPanels();
            else dockSizes();
        }, 150);
    });

    // ------------------------------------------------------------------
    // Find a node

    /** Nodes by what people know them by: their label, and their name (map notes are text, not nodes) */
    function nodeEntries(svg) {
        return Array.from(svg.querySelectorAll('g.wm-node[data-name]:not(.wm-note)')).map(function (g) {
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
    // Focus areas (SET focus_*, see FocusAreas.php): a part of a broad map
    // stands out, the rest dims behind a veil, or the view zooms onto it,
    // or both. The map opens on its default area, and so does the kiosk;
    // the menu of the toolbar picks another one or the whole map.

    const A = {
        list: [], byId: {},     // the areas: {id, title, style, box: [x1, y1, x2, y2], nodes, links}
        def: null,              // the area the map opens on
        current: null,          // the area shown
        hash: null,             // a= of the address: an area id, or "-" for the whole map
        started: false,
        view: null              // the view the area set, while nobody moved it: {z, x, y}
    };

    function areaTitle(a) {
        return a.title || fmt('Area :id', {id: a.id});
    }

    function setAreas(focus) {
        A.list = focus && Array.isArray(focus.areas) ? focus.areas : [];
        A.byId = {};
        A.list.forEach(function (a) { A.byId[a.id] = a; });
        A.def = focus && focus.default && A.byId[focus.default] ? focus.default : null;
        // the area shown is gone from the map
        if (A.current !== null && !A.byId[A.current]) {
            A.current = null;
            A.view = null;
        }
        fillAreaMenu();
    }

    /** Once the map is drawn the first time: the area of the address, or the default one */
    function startArea() {
        if (A.started) return;
        A.started = true;
        let id = A.def;
        if (!kioskFrame && A.hash !== null) {
            id = A.hash === '-' ? null : (A.byId[A.hash] ? A.hash : A.def);
        }
        if (id === null) {
            // the address asked for the whole map: keep it in the address
            if (A.def !== null) saveHash();
            return;
        }
        // a view in the address wins over the zoom of the area
        showArea(id, {zoom: !S.touched, animate: false});
    }

    /** Whether the view is still the one the area set */
    function areaViewKept() {
        if (!A.view) return false;
        const c = viewCentre();
        return Math.abs(S.z - A.view.z) < 1e-6 && Math.abs(c.x - A.view.x) < 1 && Math.abs(c.y - A.view.y) < 1;
    }

    /** Puts an area forward (null: the whole map); zoom onto it when its style says so */
    function showArea(id, opts) {
        const a = id !== null && A.byId[id] ? A.byId[id] : null;
        const zoomed = areaViewKept() || (A.view && !S.touched);
        A.current = a ? a.id : null;
        A.view = null;
        markArea();
        fillAreaMenu();
        if (a && a.style !== 'dim' && opts.zoom) {
            areaView(a, opts.animate);
        } else if (zoomed && opts.zoom) {
            // back from the zoom of an area: the whole map, dimmed around this one
            fit();
            return;
        }
        saveHash();
    }

    /** The part of the viewport the toolbar, the side panel and the status line leave free */
    function freeRect() {
        const vr = viewport.getBoundingClientRect();
        function rect(el) {
            return el && !el.hidden && el.getClientRects().length ? el.getBoundingClientRect() : null;
        }
        // with the viewer panels placed by the map, the bottom left corner is the line at the bottom
        const bottom = D.applied ? D.docks['bottom-left'] : statusBox;
        const pb = rect(panel), bb = rect(root.querySelector('.wmv-bar')), sb = rect(bottom);
        let x0 = 0, y0 = 0, x1 = vr.width, y1 = vr.height;
        if (pb && vr.width > 640) x0 = Math.max(0, pb.right - vr.left + 8);
        if (bb) y0 = Math.max(0, bb.bottom - vr.top + 8);
        if (sb) y1 = Math.min(y1, sb.top - vr.top - 8);
        // too little room left: the whole viewport
        if (x1 - x0 < 200) x0 = 0;
        if (y1 - y0 < 200) {
            y0 = 0;
            y1 = vr.height;
        }
        return {x0: x0, y0: y0, x1: x1, y1: y1};
    }

    /** Zooms onto an area, with a small margin around it, in the free part of the view */
    function areaView(a, animate) {
        if (!a) return;
        const m = 20;
        const b = a.box;
        const w = b[2] - b[0] + 2 * m, h = b[3] - b[1] + 2 * m;
        const vp = vpSize();
        const f = freeRect();
        const z = clampZ(Math.min((f.x1 - f.x0) / w, (f.y1 - f.y0) / h));
        // the centre of the view, so the centre of the area sits in the middle of the free part
        const x = (b[0] + b[2]) / 2 - ((f.x0 + f.x1) / 2 - vp.w / 2) / z;
        const y = (b[1] + b[3]) / 2 - ((f.y0 + f.y1) / 2 - vp.h / 2) / z;
        if (animate) {
            animateTo(z, x, y);
        } else {
            centreOn(x, y, z);
            draw();
        }
        // the address keeps the area rather than this view
        S.touched = false;
        A.view = {z: z, x: x, y: y};
    }

    /**
     * The veil over the rest of the map and the frame of the area, drawn
     * in the SVG over the nodes and links, under the legends and boxes
     * (they stay readable). Hovering and clicking go through it.
     */
    function markArea() {
        if (!S.el || !S.el.querySelector) return;
        const old = S.el.querySelector('.wmv-area-layer');
        if (old) old.remove();
        const a = A.current !== null ? A.byId[A.current] : null;
        S.el.classList.toggle('wmv-area-on', !!a);
        if (!a || a.style === 'zoom') return;
        const NS = 'http://www.w3.org/2000/svg';
        const layer = document.createElementNS(NS, 'g');
        layer.setAttribute('class', 'wmv-area-layer');
        layer.setAttribute('aria-hidden', 'true');
        layer.setAttribute('pointer-events', 'none');
        const b = a.box, W = S.w, H = S.h;
        const veil = document.createElementNS(NS, 'path');
        veil.setAttribute('class', 'wmv-area-veil');
        // the map's own background colour, so the rest fades into the map
        const bg = S.el.querySelector('rect.wm-bg');
        const fill = bg ? bg.getAttribute('fill') : null;
        if (fill && fill !== 'none') veil.style.fill = fill;
        veil.setAttribute('fill-rule', 'evenodd');
        veil.setAttribute('d', 'M' + (-W) + ' ' + (-H) + 'H' + (2 * W) + 'V' + (2 * H) + 'H' + (-W) + 'Z'
            + 'M' + b[0] + ' ' + b[1] + 'V' + b[3] + 'H' + b[2] + 'V' + b[1] + 'Z');
        const frame = document.createElementNS(NS, 'rect');
        frame.setAttribute('class', 'wmv-area-frame');
        frame.setAttribute('x', b[0]);
        frame.setAttribute('y', b[1]);
        frame.setAttribute('width', b[2] - b[0]);
        frame.setAttribute('height', b[3] - b[1]);
        frame.setAttribute('rx', 6);
        layer.appendChild(veil);
        layer.appendChild(frame);
        // right after the last node or link
        const items = S.el.querySelectorAll('g.wm-node, g.wm-link');
        const last = items.length ? items[items.length - 1] : null;
        if (last && last.parentNode) last.parentNode.insertBefore(layer, last.nextSibling);
        else S.el.appendChild(layer);
    }

    function fillAreaMenu() {
        if (!areaMenu) return;
        areaMenu.hidden = !A.list.length;
        if (!A.list.length) {
            areaMenu.open = false;
            return;
        }
        const box = areaMenu.querySelector('.row-menu-items');
        const rows = [{id: null, label: t('Whole map')}].concat(A.list.map(function (a) {
            return {id: a.id, label: a.id === A.def ? fmt(':name (default)', {name: areaTitle(a)}) : areaTitle(a)};
        }));
        box.replaceChildren.apply(box, rows.map(function (r) {
            const b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('role', 'menuitemradio');
            b.setAttribute('aria-checked', A.current === r.id ? 'true' : 'false');
            b.dataset.area = r.id === null ? '' : r.id;
            const mark = document.createElement('span');
            mark.className = 'wmv-area-check';
            mark.setAttribute('aria-hidden', 'true');
            mark.textContent = A.current === r.id ? '✓' : '';
            const label = document.createElement('span');
            label.textContent = r.label;
            b.append(mark, label);
            return b;
        }));
        const current = A.current !== null ? A.byId[A.current] : null;
        const summary = areaMenu.querySelector('summary');
        const title = current ? fmt('Focus area: :name', {name: areaTitle(current)}) : t('Focus areas');
        summary.title = title;
        summary.setAttribute('aria-label', title);
        summary.classList.toggle('wmv-on', !!current);
    }

    if (areaMenu) {
        areaMenu.addEventListener('click', function (e) {
            const b = e.target.closest ? e.target.closest('[data-area]') : null;
            if (!b) return;
            e.preventDefault();
            closeMenus();
            showArea(b.dataset.area === '' ? null : b.dataset.area, {zoom: true, animate: true});
        });
    }

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
        ['viewer.panel', function () { if (!panel || (panelButton && panelButton.hidden)) return false; setPanel(panel.hidden, true); }],
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
    window.addEventListener('resize', refit);

    /** The view after a change of the window, or of the room the kiosk's side panel leaves */
    function refit() {
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
        // the view of a focus area follows the window
        if (A.view && !S.touched) areaView(A.byId[A.current], false);
        apply();
    }

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
        // the zoomed total traffic graph keeps the keys (Esc closes it)
        if (root.querySelector('.wmv-total-dialog[open]')) return false;
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
        if (S.focus !== null && !el.closest('.wmv-card, .wmv-bar, .wmv-zoombar, .wmv-panel, .wmv-dock, .wmv-minimap, .wmv-history')) unfocus();
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
        return window.WMUI.time(seconds, {zone: root.dataset.timezone || '', date: 'weekday'});
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
