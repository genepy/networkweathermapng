/**
 * SVG map editor.
 *
 * The server draws the map (WeatherMapSvg) and sends it with a model of the
 * nodes and links. Every change is sent as a batch of operations to
 * /editor/ops/{id}; the answer is the new drawing. One batch = one undo step.
 */
(function () {
    'use strict';

    var cfg = window.WM_EDITOR;
    var SVGNS = 'http://www.w3.org/2000/svg';
    var GRID = 10;
    var ANCHORS = ['NW', 'N', 'NE', 'W', 'C', 'E', 'SW', 'S', 'SE'];
    /** Map furniture: drawn by the map itself, placed by KEYPOS, TIMEPOS, TITLEPOS... */
    var FURNITURE = ['legend', 'timestamp', 'title', 'widthlegend'];
    /** Distance, in screen pixels, at which a moved object snaps to a guide */
    var GUIDE_SNAP = 6;

    /** Translation of an interface text (window.WM_T), :name placeholders replaced */
    function t(s, params) {
        var out = (window.WM_T && window.WM_T[s]) || s;
        if (params) {
            // longest first, so :n does not eat the start of :nodes
            Object.keys(params).sort(function (a, b) { return b.length - a.length; }).forEach(function (k) {
                out = out.split(':' + k).join(String(params[k]));
            });
        }
        return out;
    }

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    var stage = $('#stage');
    var mapLayer = $('#map');
    var overlay = $('#overlay');
    var paper = $('#paper');
    var gridLayer = $('#grid-layer');
    var resizeHandle = $('#resize-handle');
    var canvas = $('#canvas');
    var inspector = $('#inspector-body');

    var lastVersion = null;

    var state = {
        model: null,
        nodes: new Map(),
        links: new Map(),
        zones: new Map(),
        furniture: new Map(),     // by key: "legend:DEFAULT", "timestamp:", "title:"...
        selection: new Set(),     // "node:NAME", "link:NAME", "zone:NAME" or a furniture key
        view: { x: 0, y: 0 },
        zoom: 1,
        tool: 'select',
        snap: false,
        linkFrom: null,
        history: { undo: 0, redo: 0 },
        queue: Promise.resolve(),
        drag: null,
        nudge: null,
        spaceDown: false,
        first: true,
        mouse: null,              // last pointer position on the map, for paste
        overlaps: new Set(),      // names of nodes whose boxes overlap another
        textMode: false,
        placing: false,           // a new zone follows the pointer until a click drops it
        layers: loadLayers()
    };

    // ------------------------------------------------------------------
    // Server

    function api(method, url, body) {
        var opt = { method: method, headers: { 'Accept': 'application/json' } };
        if (body) {
            opt.headers['Content-Type'] = 'application/json';
            opt.body = JSON.stringify(body);
        }
        return fetch(url, opt).then(function (r) {
            return r.json().catch(function () { return null; }).then(function (data) {
                if (!r.ok) {
                    throw new Error((data && data.error) || t('The server answered :status', { status: r.status }));
                }
                if (!data) {
                    throw new Error(t('The server sent an unreadable answer'));
                }
                return data;
            });
        });
    }

    function run(task) {
        state.queue = state.queue.then(function () {
            busy(true);
            return task();
        }).catch(function (e) {
            toast(e.message, true);
            return load();
        }).finally(function () {
            busy(false);
        });
        return state.queue;
    }

    function load() {
        return api('GET', '/editor/render/' + cfg.mapId).then(function (d) { applyRender(d); });
    }

    /**
     * Send operations. `select` picks what is selected afterwards: an array of
     * keys, or a function given the server's result.
     */
    function send(ops, select) {
        if (!ops.length) {
            return Promise.resolve();
        }
        return run(function () {
            return api('POST', '/editor/ops/' + cfg.mapId, { ops: ops }).then(function (d) {
                var keys = typeof select === 'function' ? select(d.result || {}) : select;
                applyRender(d, keys);
            });
        });
    }

    function historyStep(direction) {
        if (!state.history[direction]) {
            return;
        }
        run(function () {
            return api('POST', '/editor/' + direction + '/' + cfg.mapId).then(function (d) { applyRender(d); });
        });
    }

    function applyRender(d, selectKeys) {
        var doc = new DOMParser().parseFromString(d.svg, 'image/svg+xml');
        var root = doc.documentElement;
        if (root.nodeName !== 'svg') {
            throw new Error(t('The map could not be drawn'));
        }
        var children = Array.prototype.map.call(root.childNodes, function (n) { return document.importNode(n, true); });
        mapLayer.replaceChildren.apply(mapLayer, children);

        state.model = d.model;
        if (d.version) { lastVersion = d.version; }
        state.history = d.history || { undo: 0, redo: 0 };
        state.nodes = new Map(d.model.nodes.map(function (n) { return [n.name, n]; }));
        state.links = new Map(d.model.links.map(function (l) { return [l.name, l]; }));
        state.zones = new Map((d.model.zones || []).map(function (z) { return [z.name, z]; }));
        state.furniture = new Map((d.model.furniture || []).map(function (f) { return [key(f.kind, f.name), f]; }));
        computeOverlaps();

        var w = d.model.map.width, h = d.model.map.height;
        [paper, gridLayer].forEach(function (r) { r.setAttribute('width', w); r.setAttribute('height', h); });

        if (selectKeys) {
            state.selection = new Set(selectKeys);
        }
        pruneSelection();

        if (state.first) {
            state.first = false;
            zoomFit();
        } else {
            updateView();
        }

        renderTemplates();
        refresh();
    }

    function pruneSelection() {
        state.selection.forEach(function (key) {
            var p = parseKey(key);
            if (!itemOf(p.kind, p.name)) {
                state.selection.delete(key);
            }
        });
    }

    // ------------------------------------------------------------------
    // Helpers

    function key(kind, name) { return kind + ':' + name; }
    function itemOf(kind, name) {
        if (isFurniture(kind)) {
            return state.furniture.get(key(kind, name));
        }
        var m = kind === 'node' ? state.nodes : kind === 'link' ? state.links : kind === 'zone' ? state.zones : null;
        return m ? m.get(name) : undefined;
    }
    function isFurniture(kind) { return FURNITURE.indexOf(kind) >= 0; }

    /** The selected legends, timestamps and title */
    function selectedFurniture() {
        var out = [];
        state.selection.forEach(function (k) {
            var p = parseKey(k);
            if (isFurniture(p.kind) && itemOf(p.kind, p.name)) {
                out.push(itemOf(p.kind, p.name));
            }
        });
        return out;
    }

    /** [x1, y1, x2, y2] of a node, zone or piece of furniture */
    function boxOf1(kind, item) {
        if (kind === 'zone') {
            return [item.x, item.y, item.x + item.width, item.y + item.height];
        }
        return item.box;
    }

    /**
     * What can be moved, aligned and snapped: unlocked nodes and zones, and
     * the furniture. Each entry has its kind, item, key and box.
     */
    function selectedObjects() {
        var out = [];
        selected('node').forEach(function (n) { if (!n.locked) { out.push({ kind: 'node', item: n, key: key('node', n.name), box: n.box }); } });
        selected('zone').forEach(function (z) { if (!z.locked) { out.push({ kind: 'zone', item: z, key: key('zone', z.name), box: boxOf1('zone', z) }); } });
        selectedFurniture().forEach(function (f) { out.push({ kind: f.kind, item: f, key: key(f.kind, f.name), box: f.box }); });
        return out;
    }
    function parseKey(k) { var i = k.indexOf(':'); return { kind: k.slice(0, i), name: k.slice(i + 1) }; }

    function selected(kind) {
        var out = [];
        state.selection.forEach(function (k) {
            var p = parseKey(k);
            if (p.kind === kind) {
                out.push(itemOf(kind, p.name));
            }
        });
        return out.filter(Boolean);
    }

    function el(tag, attrs, parent) {
        var e = document.createElementNS(SVGNS, tag);
        Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        if (parent) {
            parent.appendChild(e);
        }
        return e;
    }

    function h(tag, attrs, children) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') {
                e.textContent = attrs[k];
            } else if (k.slice(0, 2) === 'on') {
                e.addEventListener(k.slice(2), attrs[k]);
            } else if (attrs[k] !== false && attrs[k] != null) {
                e.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
            }
        });
        (children || []).forEach(function (c) { if (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return e;
    }

    function toMap(evt) {
        var pt = stage.createSVGPoint();
        pt.x = evt.clientX;
        pt.y = evt.clientY;
        return pt.matrixTransform(stage.getScreenCTM().inverse());
    }

    function snap(v) { return state.snap ? Math.round(v / GRID) * GRID : Math.round(v); }

    function busy(on) { $('#busy').hidden = !on; }

    var toastTimer = null;
    function toast(msg, isError) {
        var box = $('#toast');
        box.textContent = msg;
        box.className = isError ? 'error' : '';
        box.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { box.hidden = true; }, isError ? 6000 : 2500);
    }

    function groupOf(item) { return item && item.group ? item.group : ''; }

    /** The selection grows to whole groups, unless Alt is held. */
    function withGroups(keys, alt) {
        if (alt) {
            return keys;
        }
        var groups = new Set();
        keys.forEach(function (k) {
            var p = parseKey(k);
            var item = itemOf(p.kind, p.name);
            if (groupOf(item)) {
                groups.add(item.group);
            }
        });
        if (!groups.size) {
            return keys;
        }
        var out = new Set(keys);
        state.nodes.forEach(function (n) { if (groups.has(n.group)) { out.add(key('node', n.name)); } });
        state.links.forEach(function (l) { if (groups.has(l.group)) { out.add(key('link', l.name)); } });
        state.zones.forEach(function (z) { if (groups.has(z.group)) { out.add(key('zone', z.name)); } });
        return Array.from(out);
    }

    // ------------------------------------------------------------------
    // View: zoom and pan

    function updateView() {
        var w = canvas.clientWidth / state.zoom;
        var hh = canvas.clientHeight / state.zoom;
        stage.setAttribute('viewBox', [state.view.x, state.view.y, w, hh].join(' '));
        var zoom = Math.round(state.zoom * 100);
        $('#zoom-value').textContent = zoom + '%';
        $('#zoom-level').title = t('Zoom of the editor view: :p%. Click for actual size (0)', { p: zoom });
        if (state.model) {
            var s = 12 / state.zoom;
            resizeHandle.setAttribute('x', state.model.map.width - s / 2);
            resizeHandle.setAttribute('y', state.model.map.height - s / 2);
            resizeHandle.setAttribute('width', s);
            resizeHandle.setAttribute('height', s);
        }
        updateMinimap();
        if (state.model) {
            renderOverlay();
        }
    }

    function zoomAt(factor, mx, my) {
        var z = Math.min(16, Math.max(0.1, state.zoom * factor));
        if (mx === undefined) {
            mx = state.view.x + canvas.clientWidth / state.zoom / 2;
            my = state.view.y + canvas.clientHeight / state.zoom / 2;
        }
        state.view.x = mx - (mx - state.view.x) * state.zoom / z;
        state.view.y = my - (my - state.view.y) * state.zoom / z;
        state.zoom = z;
        updateView();
    }

    function zoomFit() {
        if (!state.model) {
            return;
        }
        var m = state.model.map, pad = 30;
        state.zoom = Math.min((canvas.clientWidth - pad * 2) / m.width, (canvas.clientHeight - pad * 2) / m.height, 4);
        state.view.x = -(canvas.clientWidth / state.zoom - m.width) / 2;
        state.view.y = -(canvas.clientHeight / state.zoom - m.height) / 2;
        updateView();
    }

    function zoomReset() {
        var cx = state.view.x + canvas.clientWidth / state.zoom / 2;
        var cy = state.view.y + canvas.clientHeight / state.zoom / 2;
        state.zoom = 1;
        state.view.x = cx - canvas.clientWidth / 2;
        state.view.y = cy - canvas.clientHeight / 2;
        updateView();
    }

    function centerOn(x, y) {
        state.view.x = x - canvas.clientWidth / state.zoom / 2;
        state.view.y = y - canvas.clientHeight / state.zoom / 2;
        updateView();
    }

    function updateMinimap() {
        if (!state.model) {
            return;
        }
        var mm = $('#minimap-svg');
        var m = state.model.map;
        mm.setAttribute('viewBox', [0, 0, m.width, m.height].join(' '));
        var v = $('#minimap-view');
        v.setAttribute('x', state.view.x);
        v.setAttribute('y', state.view.y);
        v.setAttribute('width', canvas.clientWidth / state.zoom);
        v.setAttribute('height', canvas.clientHeight / state.zoom);
    }

    // ------------------------------------------------------------------
    // Overlay: selection, handles

    function linkEnds(link, moved) {
        var a = state.nodes.get(link.a), b = state.nodes.get(link.b);
        var pa = anchorPoint(a, link.a_offset), pb = anchorPoint(b, link.b_offset);
        if (moved) {
            if (moved.nodes[link.a]) { pa = [pa[0] + moved.dx, pa[1] + moved.dy]; }
            if (moved.nodes[link.b]) { pb = [pb[0] + moved.dx, pb[1] + moved.dy]; }
        }
        return [pa, pb];
    }

    function anchorPoint(node, offset) {
        var x = node.x, y = node.y, w = node.w / 2, hh = node.h / 2;
        offset = (offset || 'C').toUpperCase();
        var m = /^(-?\d+):(-?\d+)$/.exec(offset);
        if (m) {
            return [x + +m[1], y + +m[2]];
        }
        var compass = /^(NE|NW|SE|SW|N|S|E|W|C)/.exec(offset);
        var c = compass ? compass[1] : 'C';
        if (c.indexOf('N') >= 0) { y -= hh; }
        if (c.indexOf('S') >= 0) { y += hh; }
        if (c.indexOf('E') >= 0) { x += w; }
        if (c.indexOf('W') >= 0) { x -= w; }
        return [x, y];
    }

    function linkPath(link, moved) {
        var ends = linkEnds(link, moved);
        var vias = link.via.map(function (v) {
            return (moved && moved.nodes[link.a] && moved.nodes[link.b]) ? [v[0] + moved.dx, v[1] + moved.dy] : v;
        });
        return [ends[0]].concat(vias, [ends[1]]);
    }

    /**
     * The line drawn through key points, as the engine draws it: a
     * Catmull-Rom curve for curved links, straight segments otherwise.
     * Each point says which span (between key points i and i+1) it is on.
     */
    function curveOf(points, curved, steps) {
        var out = [];
        if (!curved || points.length < 3) {
            points.forEach(function (p, i) { out.push({ x: p[0], y: p[1], span: Math.max(0, Math.min(i, points.length - 2)) }); });
            return out;
        }
        steps = steps || 16;
        var pts = [points[0]].concat(points, [points[points.length - 1]]);
        for (var i = 0; i < pts.length - 3; i++) {
            for (var j = (i === 0 ? 0 : 1); j <= steps; j++) {
                var c = spanPoint(pts[i], pts[i + 1], pts[i + 2], pts[i + 3], j / steps);
                out.push({ x: c[0], y: c[1], span: i });
            }
        }
        return out;
    }

    function spanPoint(p0, p1, p2, p3, t) {
        var t2 = t * t, t3 = t2 * t;
        return [0, 1].map(function (k) {
            return ((-p0[k] + 3 * p1[k] - 3 * p2[k] + p3[k]) * t3 + (2 * p0[k] - 5 * p1[k] + 4 * p2[k] - p3[k]) * t2 + (-p0[k] + p2[k]) * t + 2 * p1[k]) / 2;
        });
    }

    /** Where a span of the drawn link is half way, to put a handle there */
    function spanMiddle(points, curved, i) {
        if (!curved || points.length < 3) {
            return [(points[i][0] + points[i + 1][0]) / 2, (points[i][1] + points[i + 1][1]) / 2];
        }
        var p0 = points[Math.max(0, i - 1)], p3 = points[Math.min(points.length - 1, i + 2)];
        return spanPoint(p0, points[i], points[i + 1], p3, 0.5);
    }

    function curvePoints(points, curved) {
        return curveOf(points, curved).map(function (c) { return c.x + ',' + c.y; }).join(' ');
    }

    /** The span of a link nearest to a map point, for inserting a bend there */
    function spanAt(link, p) {
        var best = null, dist = Infinity;
        curveOf(linkPath(link), link.viastyle !== 'angled', 24).forEach(function (c) {
            var d = Math.hypot(c.x - p.x, c.y - p.y);
            if (d < dist) { dist = d; best = c.span; }
        });
        return best === null ? 0 : best;
    }

    function renderOverlay() {
        overlay.replaceChildren();
        var drag = state.drag;
        var moved = drag && drag.type === 'move' && drag.started ? drag.moved : null;

        // Links being dragged with their nodes
        if (moved) {
            state.links.forEach(function (l) {
                if (moved.nodes[l.a] || moved.nodes[l.b]) {
                    el('polyline', { 'class': 'ghost', points: curvePoints(linkPath(l, moved), l.viastyle !== 'angled') }, overlay);
                }
            });
        }

        renderOverlaps(moved);

        state.selection.forEach(function (k) {
            var p = parseKey(k);
            if (p.kind === 'node') {
                var n = state.nodes.get(p.name);
                if (!n) { return; }
                var dx = moved && moved.nodes[n.name] ? moved.dx : 0, dy = moved && moved.nodes[n.name] ? moved.dy : 0;
                var scaled = drag && drag.type === 'scale' && drag.started && drag.pos[n.name];
                if (scaled) { dx = scaled[0] - n.x; dy = scaled[1] - n.y; }
                el('rect', {
                    'class': 'sel-box' + (n.group ? ' grouped' : ''),
                    x: n.box[0] - 3 + dx, y: n.box[1] - 3 + dy,
                    width: n.box[2] - n.box[0] + 6, height: n.box[3] - n.box[1] + 6
                }, overlay);
                if (n.locked) {
                    var mark = el('text', { 'class': 'lock-mark', x: n.box[2] + 2 + dx, y: n.box[1] + dy }, overlay);
                    mark.textContent = '🔒';
                }
            } else if (p.kind === 'zone') {
                renderZoneBox(state.zones.get(p.name), drag);
            } else if (isFurniture(p.kind)) {
                var f = itemOf(p.kind, p.name);
                if (!f) { return; }
                var on = moved && moved.furniture.indexOf(k) >= 0;
                var fx = on ? moved.dx : 0, fy = on ? moved.dy : 0;
                el('rect', {
                    'class': 'sel-box furniture', x: f.box[0] - 3 + fx, y: f.box[1] - 3 + fy,
                    width: f.box[2] - f.box[0] + 6, height: f.box[3] - f.box[1] + 6
                }, overlay);
            } else {
                var l = state.links.get(p.name);
                if (l) {
                    el('polyline', { 'class': 'sel-link', points: (l.spine.length ? l.spine : linkPath(l)).join(' ') }, overlay);
                }
            }
        });

        var nodes = selected('node'), links = selected('link');

        // One link: its VIAs, the points to add one, its anchors and labels
        if (links.length === 1 && state.selection.size === 1 && !moved) {
            renderLinkHandles(links[0]);
        }

        // One node: a handle to pull a new link from, and one to move its label
        if (nodes.length === 1 && state.selection.size === 1 && !moved && state.tool === 'select') {
            var n1 = nodes[0];
            renderNodeLabelHandle(n1, drag);
            el('circle', { 'class': 'connector', cx: n1.box[2] + 8 / state.zoom, cy: (n1.box[1] + n1.box[3]) / 2, r: 5 / state.zoom, 'data-handle': 'connector' }, overlay)
                .appendChild(el('title', {})).textContent = t('Drag to another node to link them');
        }

        if (state.linkFrom) {
            var s = state.nodes.get(state.linkFrom);
            if (s) {
                el('rect', { 'class': 'link-source', x: s.box[0] - 4, y: s.box[1] - 4, width: s.box[2] - s.box[0] + 8, height: s.box[3] - s.box[1] + 8 }, overlay);
            }
        }

        if (state.placing && state.mouse) {
            var zr = zoneRect(state.mouse);
            el('rect', { 'class': 'ghost zone-ghost', x: zr.x, y: zr.y, width: zr.width, height: zr.height }, overlay);
        }

        if (drag && drag.type === 'lasso' && drag.started) {
            var r = rectOf(drag.start, drag.now);
            el('rect', { 'class': 'lasso', x: r[0], y: r[1], width: r[2] - r[0], height: r[3] - r[1] }, overlay);
        }

        if (drag && drag.type === 'connector' && drag.now) {
            var from = state.nodes.get(drag.from);
            el('line', { 'class': 'ghost', x1: from.x, y1: from.y, x2: drag.now.x, y2: drag.now.y }, overlay);
        }

        if (drag && drag.type === 'via' && drag.started) {
            var vl = state.links.get(drag.link);
            el('polyline', { 'class': 'ghost', points: curvePoints(drag.points, vl && vl.viastyle !== 'angled') }, overlay);
            drag.vias.forEach(function (v) { el('circle', { 'class': 'via', cx: v[0], cy: v[1], r: 5 / state.zoom }, overlay); });
        }

        if (drag && drag.type === 'bwlabel' && drag.started) {
            el('rect', { 'class': 'label-ghost', x: drag.at[0] - drag.w / 2, y: drag.at[1] - drag.h / 2, width: drag.w, height: drag.h }, overlay);
        }

        if (drag && drag.type === 'resize' && drag.started) {
            el('rect', { 'class': 'lasso', x: 0, y: 0, width: drag.w, height: drag.h }, overlay);
        }

        // Two nodes or more: a box with handles to scale their layout
        if (nodes.length >= 2 && state.tool === 'select' && !moved) {
            renderScaleBox(nodes);
        }

        renderGuides(drag);
    }

    /** Lines showing the edges and centres the moved objects line up with */
    function renderGuides(drag) {
        if (!drag || !drag.started || !drag.guides) { return; }
        drag.guides.forEach(function (g) {
            el('line', g.axis === 'x'
                ? { 'class': 'guide', x1: g.at, x2: g.at, y1: g.from, y2: g.to }
                : { 'class': 'guide', y1: g.at, y2: g.at, x1: g.from, x2: g.to }, overlay);
        });
    }

    /** A selected zone: its outline and, alone, a handle to resize it */
    function renderZoneBox(z, drag) {
        if (!z) { return; }
        var x = z.x, y = z.y, w = z.width, hh = z.height;
        var moving = drag && drag.type === 'move' && drag.started && drag.moved.zones.indexOf(z.name) >= 0;
        if (moving) {
            x += drag.moved.dx; y += drag.moved.dy;
        }
        if (drag && drag.type === 'zone-size' && drag.started && drag.zone === z.name) {
            w = drag.w; hh = drag.h;
        }
        el('rect', { 'class': 'sel-box zone' + (z.group ? ' grouped' : ''), x: x - 3, y: y - 3, width: w + 6, height: hh + 6 }, overlay);
        if (state.selection.size === 1 && !moving && !z.locked) {
            var r = 5 / state.zoom;
            el('rect', { 'class': 'scale-handle', x: x + w - r, y: y + hh - r, width: 2 * r, height: 2 * r, 'data-handle': 'zone-size' }, overlay)
                .appendChild(el('title', {})).textContent = t('Drag to resize the zone');
        }
    }

    /** With an icon, the label of the selected node can be dragged on its own */
    function renderNodeLabelHandle(n, drag) {
        if (!n.label_box || n.locked) { return; }
        var b = n.label_box, dx = 0, dy = 0;
        if (drag && drag.type === 'nodelabel' && drag.started) { dx = drag.dx; dy = drag.dy; }
        el('rect', {
            'class': 'label-handle', x: b[0] - 1 + dx, y: b[1] - 1 + dy, width: b[2] - b[0] + 2, height: b[3] - b[1] + 2,
            'data-handle': 'nodelabel'
        }, overlay).appendChild(el('title', {})).textContent = t('Drag to move the label');
    }

    function renderLinkHandles(link) {
        var r = 5 / state.zoom;
        var pts = linkPath(link), curved = link.viastyle !== 'angled';

        // a dot in the middle of each span adds a VIA there
        for (var i = 0; i < pts.length - 1; i++) {
            var mid = spanMiddle(pts, curved, i);
            el('circle', {
                'class': 'via-add', cx: mid[0], cy: mid[1],
                r: r * 0.9, 'data-handle': 'via-add', 'data-index': i
            }, overlay).appendChild(el('title', {})).textContent = t('Drag to bend the link here');
        }

        link.via.forEach(function (v, idx) {
            el('circle', { 'class': 'via', cx: v[0], cy: v[1], r: r, 'data-handle': 'via', 'data-index': idx }, overlay)
                .appendChild(el('title', {})).textContent = t('Drag to move, double-click to remove');
        });

        if (!link.locked) {
            bwLabelBoxes(link).forEach(function (lab) {
                el('rect', {
                    'class': 'label-handle', x: lab.rect[0] - 1, y: lab.rect[1] - 1, width: lab.rect[2] - lab.rect[0] + 2, height: lab.rect[3] - lab.rect[1] + 2,
                    'data-handle': 'bwlabel', 'data-dir': lab.dir
                }, overlay).appendChild(el('title', {})).textContent = t('Drag along the link to move this label');
            });
        }

        [['a', link.a, link.a_offset], ['b', link.b, link.b_offset]].forEach(function (end) {
            var node = state.nodes.get(end[1]);
            ANCHORS.forEach(function (a) {
                var p = anchorPoint(node, a);
                var current = (end[2] || 'C').toUpperCase().replace(/\d+$/, '') === a;
                el('rect', {
                    'class': 'anchor' + (current ? ' current' : ''), x: p[0] - r * 0.7, y: p[1] - r * 0.7,
                    width: r * 1.4, height: r * 1.4, 'data-handle': 'anchor', 'data-end': end[0], 'data-anchor': a
                }, overlay).appendChild(el('title', {})).textContent = a === 'C' ? t('Attach this end at the centre') : t('Attach this end to :anchor', { anchor: a });
            });
        });
    }

    /** The bandwidth labels of a link as drawn: {dir: in|out, rect} */
    function bwLabelBoxes(link) {
        // the labels sit in a group of their own, drawn over all the links
        var groups = $$('#map g[data-kind="link"]').filter(function (e) { return e.dataset.name === link.name; });
        var out = [];
        ['in', 'out'].forEach(function (dir) {
            var box = null;
            groups.forEach(function (g) {
                box = box || g.querySelector('polygon.wm-bwlabel-' + dir) || g.querySelector('text.wm-bwlabel-' + dir);
            });
            if (!box) { return; }
            var bb = box.getBBox();
            out.push({ dir: dir, w: bb.width, h: bb.height, rect: [bb.x, bb.y, bb.x + bb.width, bb.y + bb.height] });
        });
        return out;
    }

    function rectOf(p, q) {
        return [Math.min(p.x, q.x), Math.min(p.y, q.y), Math.max(p.x, q.x), Math.max(p.y, q.y)];
    }

    function refresh() {
        renderOverlay();
        renderInspector();
        updateToolbar();
        $$('#map g[data-kind]').forEach(function (g) {
            g.classList.toggle('selected', state.selection.has(key(g.dataset.kind, g.dataset.name)));
        });
    }

    function updateToolbar() {
        $('[data-cmd="undo"]').disabled = !state.history.undo;
        $('[data-cmd="redo"]').disabled = !state.history.redo;
        var nodes = selected('node').length, any = state.selection.size, objects = alignUnits(selectedObjects()).length;
        $$('[data-needs]').forEach(function (e) {
            var need = e.dataset.needs, ok = true;
            if (need === 'nodes1') { ok = nodes >= 1; }
            if (need === 'nodes2') { ok = nodes >= 2; }
            if (need === 'nodes3') { ok = nodes >= 3; }
            if (need === 'objects2') { ok = objects >= 2; }
            if (need === 'objects3') { ok = objects >= 3; }
            if (need === 'any') { ok = any > 0; }
            if (e.tagName === 'BUTTON') { e.disabled = !ok; } else { e.classList.toggle('unavailable', !ok); }
        });
        $('[data-cmd="tool-select"]').classList.toggle('active', state.tool === 'select');
        $('[data-cmd="tool-link"]').classList.toggle('active', state.tool === 'link');
        $('[data-cmd="snap"]').classList.toggle('active', state.snap);
        $('[data-cmd="zone"]').classList.toggle('active', state.placing);
        $('[data-cmd="paste"]').disabled = !hasClip();
        $('[data-cmd="text-mode"]').classList.toggle('active', state.textMode);
        var spread = $('[data-cmd="spread"]');
        spread.title = t('Move overlapping nodes apart (:n overlapping now)', { n: state.overlaps.size });
        spread.classList.toggle('warn', state.overlaps.size > 0);
        stage.classList.toggle('tool-link', state.tool === 'link');
        stage.classList.toggle('snap', state.snap);
    }

    // ------------------------------------------------------------------
    // Pointer

    function itemAt(evt) {
        var g = evt.target.closest && evt.target.closest('#map g[data-kind]');
        if (g && (g.dataset.kind === 'node' || g.dataset.kind === 'link')) {
            return { kind: g.dataset.kind, name: g.dataset.name };
        }
        if (g && isFurniture(g.dataset.kind) && itemOf(g.dataset.kind, g.dataset.name)) {
            return { kind: g.dataset.kind, name: g.dataset.name };
        }
        if (g && g.dataset.kind === 'zone' && evt.target.classList.contains('wm-zone-label')) {
            return { kind: 'zone', name: g.dataset.name };
        }
        var p = toMap(evt);
        var f = furnitureAt(p);
        if (f) {
            return { kind: f.kind, name: f.name };
        }
        // a zone is picked by its edge, so its inside stays free for selecting an area
        var z = zoneEdgeAt(p);
        return z ? { kind: 'zone', name: z.name } : null;
    }

    /** The legend, timestamp or title under a map point (they may be drawn as one picture) */
    function furnitureAt(p) {
        var found = null;
        state.furniture.forEach(function (f) {
            if (p.x >= f.box[0] && p.x <= f.box[2] && p.y >= f.box[1] && p.y <= f.box[3]) { found = f; }
        });
        return found;
    }

    function zoneEdgeAt(p) {
        var tol = 6 / state.zoom, found = null;
        if (!state.layers.zones) { return null; }
        state.zones.forEach(function (z) {
            var inOuter = p.x >= z.x - tol && p.x <= z.x + z.width + tol && p.y >= z.y - tol && p.y <= z.y + z.height + tol;
            var inInner = p.x > z.x + tol && p.x < z.x + z.width - tol && p.y > z.y + tol && p.y < z.y + z.height - tol;
            if (inOuter && !inInner) { found = z; }
        });
        return found;
    }

    function nodeAtPoint(p) {
        var found = null;
        state.nodes.forEach(function (n) {
            if (p.x >= n.box[0] && p.x <= n.box[2] && p.y >= n.box[1] && p.y <= n.box[3]) {
                found = n;
            }
        });
        return found;
    }

    function linkAtPoint(p) {
        var tol = 6 / state.zoom, found = null;
        state.links.forEach(function (l) {
            var s = l.spine;
            for (var i = 0; i + 1 < s.length && !found; i++) {
                if (distToSegment(p, s[i], s[i + 1]) <= tol + (l.drawn_width || 0) / 2) { found = l; }
            }
        });
        return found;
    }

    function distToSegment(p, a, b) {
        var dx = b[0] - a[0], dy = b[1] - a[1], len = dx * dx + dy * dy;
        var u = len ? Math.max(0, Math.min(1, ((p.x - a[0]) * dx + (p.y - a[1]) * dy) / len)) : 0;
        return Math.hypot(p.x - a[0] - u * dx, p.y - a[1] - u * dy);
    }

    stage.addEventListener('pointerdown', function (evt) {
        if (!state.model) {
            return;
        }
        var p = toMap(evt);

        if (evt.button === 1 || (evt.button === 0 && state.spaceDown)) {
            startDrag(evt, { type: 'pan', client: { x: evt.clientX, y: evt.clientY }, view: { x: state.view.x, y: state.view.y } });
            stage.classList.add('panning');
            return;
        }
        if (evt.button !== 0) {
            return;
        }

        if (state.placing) {
            evt.preventDefault();
            stopPlacing();
            addZone(p);
            return;
        }

        var handle = evt.target.dataset && evt.target.dataset.handle;
        if (evt.target === resizeHandle) {
            startDrag(evt, { type: 'resize', start: p, w: state.model.map.width, h: state.model.map.height });
            return;
        }
        if (handle) {
            handleDown(evt, handle, p);
            return;
        }

        var item = itemAt(evt);

        if (state.tool === 'link') {
            if (item && item.kind === 'node') {
                if (!state.linkFrom) {
                    state.linkFrom = item.name;
                    renderOverlay();
                } else if (state.linkFrom !== item.name) {
                    var a = state.linkFrom;
                    state.linkFrom = null;
                    addLink(a, item.name);
                }
            } else {
                state.linkFrom = null;
                renderOverlay();
            }
            return;
        }

        var ctrl = evt.ctrlKey || evt.metaKey;
        if (!item) {
            // Ctrl+click on empty space adds a node; Ctrl or Shift+drag adds an area to the selection
            startDrag(evt, { type: 'lasso', start: p, now: p, add: evt.shiftKey || ctrl, create: ctrl && !evt.shiftKey });
            return;
        }

        var k = key(item.kind, item.name);
        var keys = withGroups([k], evt.altKey);

        // Ctrl+click on a selected object takes it out of the selection,
        // unless the click turns into a drag, which duplicates the selection
        var unselect = null;
        if (evt.shiftKey || ctrl) {
            if (state.selection.has(k)) {
                if (evt.shiftKey || item.kind === 'link') {
                    keys.forEach(function (kk) { state.selection.delete(kk); });
                    refresh();
                    return;
                }
                unselect = keys;
            } else {
                keys.forEach(function (kk) { state.selection.add(kk); });
                refresh();
            }
        } else if (!state.selection.has(k)) {
            state.selection = new Set(keys);
            refresh();
        }

        if (item.kind === 'link') {
            return;
        }
        startMove(evt, k, p, unselect);
    });

    /**
     * Drag what is selected: unlocked nodes and zones, legends, timestamps
     * and the title move together. A zone moves alone: the nodes on it
     * only follow when they are grouped with it. With Ctrl (Cmd on a Mac)
     * held when the drag ends, the selected nodes are copied there instead.
     * `unselect` is what a click without a drag takes out of the selection.
     */
    function startMove(evt, clicked, p, unselect) {
        var objects = selectedObjects();
        if (!objects.length) {
            var c = parseKey(clicked), it = itemOf(c.kind, c.name);
            if (it && it.locked) { toast(t('This item is locked. Unlock it to move it.')); }
            return;
        }
        var moved = { dx: 0, dy: 0, nodes: {}, zones: [], furniture: [] };
        objects.forEach(function (o) {
            if (o.kind === 'node') { moved.nodes[o.item.name] = true; }
            else if (o.kind === 'zone') { moved.zones.push(o.item.name); }
            else { moved.furniture.push(o.key); }
        });
        // the clicked object is the one snapped to the grid
        var lead = objects.filter(function (o) { return o.key === clicked; })[0] || objects[0];
        var origin = lead.kind === 'node' ? [lead.item.x, lead.item.y] : [lead.box[0], lead.box[1]];
        startDrag(evt, {
            type: 'move', start: p, moved: moved, origin: origin, clicked: clicked, multi: state.selection.size > 1, unselect: unselect, copy: false,
            box: unionBox(objects.map(function (o) { return o.box; })), targets: guideTargets(objects)
        });
    }

    function unionBox(boxes) {
        return [
            Math.min.apply(null, boxes.map(function (b) { return b[0]; })),
            Math.min.apply(null, boxes.map(function (b) { return b[1]; })),
            Math.max.apply(null, boxes.map(function (b) { return b[2]; })),
            Math.max.apply(null, boxes.map(function (b) { return b[3]; }))
        ];
    }

    // ------------------------------------------------------------------
    // Guides: line up the moved objects with the edges and centres of the others

    /** Boxes of what does not move, and of the map itself */
    function guideTargets(moving) {
        var skip = {};
        moving.forEach(function (o) { skip[o.key] = true; });
        var out = [[0, 0, state.model.map.width, state.model.map.height]];
        state.nodes.forEach(function (n) { if (!skip[key('node', n.name)]) { out.push(n.box); } });
        state.zones.forEach(function (z) { if (!skip[key('zone', z.name)] && state.layers.zones) { out.push(boxOf1('zone', z)); } });
        state.furniture.forEach(function (f, k) { if (!skip[k]) { out.push(f.box); } });
        return out;
    }

    function lines(b, axis) {
        return axis === 'x' ? [b[0], (b[0] + b[2]) / 2, b[2]] : [b[1], (b[1] + b[3]) / 2, b[3]];
    }

    /**
     * The shift that brings box `b` onto the nearest guide along one axis,
     * within `tol` map pixels, or null.
     */
    function guideShift(b, targets, axis, tol) {
        var best = null;
        lines(b, axis).forEach(function (v) {
            targets.forEach(function (tb) {
                lines(tb, axis).forEach(function (w) {
                    var d = w - v;
                    if (Math.abs(d) <= tol && (best === null || Math.abs(d) < Math.abs(best))) { best = d; }
                });
            });
        });
        return best;
    }

    /** The guide lines box `b` touches, to draw them */
    function guidesFor(b, targets) {
        var out = [];
        ['x', 'y'].forEach(function (axis) {
            var other = axis === 'x' ? [1, 3] : [0, 2];
            lines(b, axis).forEach(function (v) {
                var from = b[other[0]], to = b[other[1]], hit = false;
                targets.forEach(function (tb) {
                    if (lines(tb, axis).some(function (w) { return Math.abs(w - v) < 0.5; })) {
                        hit = true;
                        from = Math.min(from, tb[other[0]]);
                        to = Math.max(to, tb[other[1]]);
                    }
                });
                if (hit) { out.push({ axis: axis, at: v, from: from, to: to }); }
            });
        });
        return out;
    }

    /** Snap a move to the guides (Alt held: no guides) and keep the lines to draw */
    function applyGuides(d, off) {
        d.guides = [];
        if (off) { return; }
        var tol = GUIDE_SNAP / state.zoom;
        var b = [d.box[0] + d.moved.dx, d.box[1] + d.moved.dy, d.box[2] + d.moved.dx, d.box[3] + d.moved.dy];
        var sx = guideShift(b, d.targets, 'x', tol), sy = guideShift(b, d.targets, 'y', tol);
        if (sx !== null) { d.moved.dx = Math.round(d.moved.dx + sx); }
        if (sy !== null) { d.moved.dy = Math.round(d.moved.dy + sy); }
        b = [d.box[0] + d.moved.dx, d.box[1] + d.moved.dy, d.box[2] + d.moved.dx, d.box[3] + d.moved.dy];
        d.guides = guidesFor(b, d.targets);
    }

    function handleDown(evt, handle, p) {
        if (handle === 'scale') {
            startScale(evt, evt.target.dataset.dir, p);
            return;
        }
        if (handle === 'zone-size') {
            var z = selected('zone')[0];
            startDrag(evt, { type: 'zone-size', zone: z.name, start: p, w: z.width, h: z.height });
            return;
        }
        if (handle === 'nodelabel') {
            var n = selected('node')[0];
            startDrag(evt, { type: 'nodelabel', node: n.name, start: p, dx: 0, dy: 0 });
            return;
        }
        var link = selected('link')[0];
        var idx = +evt.target.dataset.index;

        if (handle === 'bwlabel') {
            var lab = bwLabelBoxes(link).filter(function (l) { return l.dir === evt.target.dataset.dir; })[0];
            if (!lab || link.spine.length < 2) { return; }
            startDrag(evt, { type: 'bwlabel', link: link.name, dir: lab.dir, w: lab.w, h: lab.h, at: [(lab.rect[0] + lab.rect[2]) / 2, (lab.rect[1] + lab.rect[3]) / 2], pct: null });
            return;
        }

        if (handle === 'via' || handle === 'via-add') {
            var vias = link.via.map(function (v) { return v.slice(); });
            var at = idx;
            if (handle === 'via-add') {
                vias.splice(idx, 0, [p.x, p.y]);
            }
            startDrag(evt, { type: 'via', link: link.name, vias: vias, index: at, points: [] });
            return;
        }

        if (handle === 'anchor') {
            var props = {};
            props[evt.target.dataset.end + '_offset'] = evt.target.dataset.anchor;
            send([{ op: 'set_link', names: [link.name], props: props }]);
            return;
        }

        if (handle === 'connector') {
            startDrag(evt, { type: 'connector', from: selected('node')[0].name, now: p });
        }
    }

    function startDrag(evt, drag) {
        drag.pointer = evt.pointerId;
        drag.client0 = { x: evt.clientX, y: evt.clientY };
        drag.started = false;
        state.drag = drag;
        stage.setPointerCapture(evt.pointerId);
        evt.preventDefault();
    }

    stage.addEventListener('pointermove', function (evt) {
        var d = state.drag;
        if (!d || d.pointer !== evt.pointerId) {
            return;
        }
        if (!d.started && Math.hypot(evt.clientX - d.client0.x, evt.clientY - d.client0.y) < 3) {
            return;
        }
        d.started = true;
        var p = toMap(evt);

        switch (d.type) {
            case 'pan':
                state.view.x = d.view.x - (evt.clientX - d.client.x) / state.zoom;
                state.view.y = d.view.y - (evt.clientY - d.client.y) / state.zoom;
                updateView();
                return;

            case 'move':
                d.moved.dx = snap(d.origin[0] + p.x - d.start.x) - d.origin[0];
                d.moved.dy = snap(d.origin[1] + p.y - d.start.y) - d.origin[1];
                applyGuides(d, evt.altKey);
                d.copy = (evt.ctrlKey || evt.metaKey) && Object.keys(d.moved.nodes).length > 0;
                previewMove(d.moved, d.copy);
                break;

            case 'zone-size':
                var zs = state.zones.get(d.zone);
                d.w = Math.max(10, snap(zs.x + zs.width + p.x - d.start.x) - zs.x);
                d.h = Math.max(10, snap(zs.y + zs.height + p.y - d.start.y) - zs.y);
                break;

            case 'nodelabel':
                d.dx = Math.round(p.x - d.start.x);
                d.dy = Math.round(p.y - d.start.y);
                previewLabel(d.node, d.dx, d.dy);
                break;

            case 'bwlabel':
                var bl = state.links.get(d.link);
                var q = projectOnSpine(bl.spine, p);
                d.pct = Math.max(1, Math.min(99, q.pct));
                d.at = pointAlong(bl.spine, d.pct);
                break;

            case 'lasso':
                d.now = p;
                break;

            case 'via':
                d.vias[d.index] = [snap(p.x), snap(p.y)];
                var l = state.links.get(d.link);
                var ends = linkEnds(l);
                d.points = [ends[0]].concat(d.vias, [ends[1]]);
                break;

            case 'connector':
                d.now = p;
                break;

            case 'resize':
                d.w = Math.max(50, snap(state.model.map.width + p.x - d.start.x));
                d.h = Math.max(50, snap(state.model.map.height + p.y - d.start.y));
                break;

            case 'scale':
                scaleMove(d, p, evt.shiftKey);
                break;
        }
        renderOverlay();
    });

    stage.addEventListener('pointermove', function (evt) {
        if (state.model) { state.mouse = toMap(evt); }
        if (state.placing) { renderOverlay(); }
    });
    stage.addEventListener('pointerleave', function () {
        state.mouse = null;
        if (state.placing) { renderOverlay(); }
    });

    /**
     * Show the moved objects at their new place, before the server draws
     * them. When copying, the nodes stay and their copies follow the pointer.
     */
    function previewMove(moved, copy) {
        var shift = (moved.dx || moved.dy) ? 'translate(' + moved.dx + ' ' + moved.dy + ')' : null;
        $$('#map .copy-ghost').forEach(function (g) { g.remove(); });
        if (copy && shift) {
            $$('#map g[data-kind="node"]').forEach(function (g) {
                if (!moved.nodes[g.dataset.name]) { return; }
                var ghost = g.cloneNode(true);
                ghost.removeAttribute('data-kind');
                ghost.classList.add('copy-ghost');
                ghost.setAttribute('transform', shift);
                mapLayer.appendChild(ghost);
            });
            moved = noMove();
            shift = null;
        }
        function place(g, on) {
            if (on && shift) { g.setAttribute('transform', shift); } else { g.removeAttribute('transform'); }
        }
        $$('#map g[data-kind]').forEach(function (g) {
            var kind = g.dataset.kind, name = g.dataset.name;
            if (kind === 'node') {
                place(g, !!moved.nodes[name]);
            } else if (kind === 'zone') {
                place(g, moved.zones.indexOf(name) >= 0);
            } else if (isFurniture(kind)) {
                place(g, moved.furniture.indexOf(key(kind, name)) >= 0);
            } else if (kind === 'link') {
                var l = state.links.get(name);
                g.classList.toggle('dimmed', !!(l && (moved.nodes[l.a] || moved.nodes[l.b]) && shift));
            }
        });
    }

    function noMove() { return { dx: 0, dy: 0, nodes: {}, zones: [], furniture: [] }; }

    /** Move the label of a node, not its icon, while it is dragged */
    function previewLabel(name, dx, dy) {
        $$('#map g[data-kind="node"]').forEach(function (g) {
            if (g.dataset.name !== name) { return; }
            Array.prototype.forEach.call(g.children, function (c) {
                if (c.classList.contains('wm-icon')) { return; }
                if (dx || dy) { c.setAttribute('transform', 'translate(' + dx + ' ' + dy + ')'); } else { c.removeAttribute('transform'); }
            });
        });
    }

    /** The nearest point of a polyline to p: {pct} of its length */
    function projectOnSpine(spine, p) {
        var total = 0, best = { d: Infinity, at: 0 };
        for (var i = 1; i < spine.length; i++) {
            var a = spine[i - 1], b = spine[i];
            var len = Math.hypot(b[0] - a[0], b[1] - a[1]);
            var dx = b[0] - a[0], dy = b[1] - a[1];
            var u = len ? Math.max(0, Math.min(1, ((p.x - a[0]) * dx + (p.y - a[1]) * dy) / (len * len))) : 0;
            var d = Math.hypot(p.x - a[0] - u * dx, p.y - a[1] - u * dy);
            if (d < best.d) { best = { d: d, at: total + u * len }; }
            total += len;
        }
        return { pct: total ? Math.round(best.at / total * 100) : 50 };
    }

    stage.addEventListener('pointerup', endDrag);
    stage.addEventListener('pointercancel', function (evt) { endDrag(evt, true); });

    /** Stop a drag without saving anything (Esc, or the browser took the pointer) */
    function cancelDrag() {
        var d = state.drag;
        if (!d) { return false; }
        state.drag = null;
        stage.classList.remove('panning');
        try { stage.releasePointerCapture(d.pointer); } catch (e) { /* already released */ }
        previewMove(noMove());
        previewPositions({});
        if (d.type === 'nodelabel') { previewLabel(d.node, 0, 0); }
        refresh();
        return true;
    }

    function endDrag(evt, cancelled) {
        var d = state.drag;
        if (!d || d.pointer !== evt.pointerId) {
            return;
        }
        if (cancelled) {
            cancelDrag();
            return;
        }
        state.drag = null;
        stage.classList.remove('panning');

        switch (d.type) {
            case 'move':
                if (!d.started || (!d.moved.dx && !d.moved.dy)) {
                    // a plain click in a multi-selection keeps only what was clicked
                    if (!d.started && d.multi && !(evt.shiftKey || evt.ctrlKey || evt.metaKey)) {
                        state.selection = new Set(withGroups([d.clicked], evt.altKey));
                    }
                    if (!d.started && d.unselect) {
                        d.unselect.forEach(function (kk) { state.selection.delete(kk); });
                    }
                    previewMove(noMove());
                    refresh();
                    return;
                }
                if (d.copy) {
                    previewMove(noMove());
                    send([{ op: 'clone', nodes: Object.keys(d.moved.nodes), dx: d.moved.dx, dy: d.moved.dy }], createdKeys);
                    return;
                }
                send([moveOp(d.moved)]);
                return;

            case 'zone-size':
                var zz = state.zones.get(d.zone);
                if (!d.started || (d.w === zz.width && d.h === zz.height)) {
                    renderOverlay();
                    return;
                }
                send([{ op: 'set_zone', names: [d.zone], props: { width: d.w, height: d.h } }]);
                return;

            case 'nodelabel':
                var ln = state.nodes.get(d.node);
                if (!d.started || (!d.dx && !d.dy) || !ln) {
                    previewLabel(d.node, 0, 0);
                    renderOverlay();
                    return;
                }
                send([{ op: 'set_node', names: [d.node], props: { labelshift: [ln.label_shift[0] + d.dx, ln.label_shift[1] + d.dy] } }]);
                return;

            case 'bwlabel':
                if (!d.started || d.pct === null) {
                    renderOverlay();
                    return;
                }
                var bl = state.links.get(d.link), pos = effectivePos(bl);
                pos[d.dir] = d.pct;
                // on a two-way link, the "in" label stays on the B side of the "out" one
                if (bl.linkstyle !== 'oneway') {
                    if (d.dir === 'in') { pos.in = Math.max(pos.in, Math.round(pos.out) + 1); } else { pos.out = Math.min(pos.out, Math.round(pos.in) - 1); }
                }
                send([{ op: 'set_link', names: [d.link], props: { labelpos_in: Math.round(pos.in), labelpos_out: Math.round(pos.out) } }]);
                return;

            case 'lasso':
                if (!d.started) {
                    if (d.create) {
                        var at = toMap(evt);
                        addNode({ x: snap(at.x), y: snap(at.y) });
                        return;
                    }
                    if (!d.add) {
                        state.selection.clear();
                    }
                    refresh();
                    return;
                }
                var r = rectOf(d.start, toMap(evt));
                var keys = d.add ? Array.from(state.selection) : [];
                var inside = function (b) { return b[0] >= r[0] && b[2] <= r[2] && b[1] >= r[1] && b[3] <= r[3]; };
                state.nodes.forEach(function (n) {
                    if (n.x >= r[0] && n.x <= r[2] && n.y >= r[1] && n.y <= r[3]) { keys.push(key('node', n.name)); }
                });
                state.links.forEach(function (l) {
                    var m = midpoint(l);
                    if (m && m[0] >= r[0] && m[0] <= r[2] && m[1] >= r[1] && m[1] <= r[3]) { keys.push(key('link', l.name)); }
                });
                state.zones.forEach(function (z) { if (inside(boxOf1('zone', z))) { keys.push(key('zone', z.name)); } });
                state.furniture.forEach(function (f, k) { if (inside(f.box)) { keys.push(k); } });
                state.selection = new Set(withGroups(keys, evt.altKey));
                refresh();
                return;

            case 'via':
                if (!d.started) {
                    renderOverlay();
                    return;
                }
                send([{ op: 'set_link', names: [d.link], props: { via: d.vias } }]);
                return;

            case 'connector':
                var target = nodeAtPoint(toMap(evt));
                if (target && target.name !== d.from) {
                    addLink(d.from, target.name);
                } else {
                    renderOverlay();
                }
                return;

            case 'resize':
                renderOverlay();
                if (d.started && (d.w !== state.model.map.width || d.h !== state.model.map.height)) {
                    askResize(d.w, d.h);
                }
                return;

            case 'scale':
                scaleEnd(d);
                return;
        }
    }

    function midpoint(link) {
        var s = link.spine;
        return s && s.length ? s[Math.floor(s.length / 2)] : null;
    }

    function moveOp(moved) {
        var nodes = {}, vias = {}, zones = {}, furniture = {};
        moved.zones.forEach(function (name) {
            var z = state.zones.get(name);
            zones[name] = [z.x + moved.dx, z.y + moved.dy];
        });
        Object.keys(moved.nodes).forEach(function (name) {
            var n = state.nodes.get(name);
            nodes[name] = [n.x + moved.dx, n.y + moved.dy];
        });
        moved.furniture.forEach(function (k) {
            var f = state.furniture.get(k);
            furniture[k] = [f.pos[0] + moved.dx, f.pos[1] + moved.dy];
        });
        // links whose two ends move keep their shape
        state.links.forEach(function (l) {
            if (l.via.length && moved.nodes[l.a] && moved.nodes[l.b]) {
                vias[l.name] = l.via.map(function (v) { return [v[0] + moved.dx, v[1] + moved.dy]; });
            }
        });
        return { op: 'move', nodes: nodes, vias: vias, zones: zones, furniture: furniture };
    }

    stage.addEventListener('dblclick', function (evt) {
        var handle = evt.target.dataset && evt.target.dataset.handle;
        var link = selected('link')[0];
        if (handle === 'via' && link) {
            var vias = link.via.slice();
            vias.splice(+evt.target.dataset.index, 1);
            send([{ op: 'set_link', names: [link.name], props: { via: vias } }]);
            return;
        }
        if (handle) {
            return;
        }
        var p = toMap(evt);
        var item = itemAt(evt);
        // double-click on a link: a new bend point there
        var onLink = item && item.kind === 'link' ? state.links.get(item.name) : (!item ? linkAtPoint(p) : null);
        if (onLink && !onLink.locked && state.tool === 'select') {
            addBend(onLink, p);
            return;
        }
        if (item && (item.kind === 'node' || item.kind === 'zone')) {
            var input = $('#inspector input[name="label"]');
            if (input) { input.focus(); input.select(); }
        }
    });

    /** Add a bend point (VIA) to a link, in the part of the link nearest to p */
    function addBend(link, p) {
        var vias = link.via.map(function (v) { return v.slice(); });
        vias.splice(spanAt(link, p), 0, [snap(p.x), snap(p.y)]);
        send([{ op: 'set_link', names: [link.name], props: { via: vias } }], [key('link', link.name)]);
    }

    stage.addEventListener('wheel', function (evt) {
        evt.preventDefault();
        var p = toMap(evt);
        if (evt.ctrlKey || evt.metaKey || !evt.shiftKey) {
            zoomAt(Math.pow(1.0015, -evt.deltaY), p.x, p.y);
        } else {
            state.view.x += evt.deltaY / state.zoom;
            updateView();
        }
    }, { passive: false });

    // Minimap: click or drag to move the view
    (function () {
        var mm = $('#minimap-svg'), dragging = false;
        function go(evt) {
            var pt = mm.createSVGPoint();
            pt.x = evt.clientX;
            pt.y = evt.clientY;
            var p = pt.matrixTransform(mm.getScreenCTM().inverse());
            centerOn(p.x, p.y);
        }
        mm.addEventListener('pointerdown', function (e) { dragging = true; mm.setPointerCapture(e.pointerId); go(e); });
        mm.addEventListener('pointermove', function (e) { if (dragging) { go(e); } });
        mm.addEventListener('pointerup', function () { dragging = false; });
    })();

    new ResizeObserver(function () { updateView(); }).observe(canvas);

    // ------------------------------------------------------------------
    // Commands

    function addNode(opts) {
        var op = Object.assign({ op: 'add_node' }, opts);
        send([op], function (r) { return (r.created && r.created.nodes || []).map(function (n) { return key('node', n); }); });
    }

    function addLink(a, b) {
        send([{ op: 'add_link', a: a, b: b }], function (r) {
            return (r.created && r.created.links || []).map(function (n) { return key('link', n); });
        });
    }

    function deleteSelection() {
        var nodes = selected('node'), links = selected('link');
        var locked = nodes.concat(links).filter(function (i) { return i.locked; });
        if (locked.length) {
            toast(t('Locked items are not deleted. Unlock them first.'), true);
        }
        nodes = nodes.filter(function (n) { return !n.locked; });
        links = links.filter(function (l) { return !l.locked; });
        var zones = selected('zone'), ops = [];
        if (nodes.length || links.length) {
            ops.push({ op: 'delete', nodes: nodes.map(function (n) { return n.name; }), links: links.map(function (l) { return l.name; }) });
        }
        if (zones.length) {
            ops.push({ op: 'delete_zones', names: zones.map(function (z) { return z.name; }) });
        }
        send(ops, []);
    }

    /** Where a new zone centred on a map point goes */
    function zoneRect(c) {
        return { x: snap(c.x - 100), y: snap(c.y - 60), width: 200, height: 120 };
    }

    /**
     * A new zone around the selected nodes, or at a point of the map.
     */
    function addZone(at) {
        var nodes = selected('node'), op = { op: 'add_zone' }, pad = 30;
        if (nodes.length) {
            var b = boxOf(nodes);
            op.x = Math.round(b[0] - pad); op.y = Math.round(b[1] - pad - 16);
            op.width = Math.round(b[2] - b[0] + 2 * pad); op.height = Math.round(b[3] - b[1] + 2 * pad + 16);
        } else {
            var c = at || { x: state.view.x + canvas.clientWidth / state.zoom / 2, y: state.view.y + canvas.clientHeight / state.zoom / 2 };
            Object.assign(op, zoneRect(c));
        }
        op.x = Math.max(0, op.x); op.y = Math.max(0, op.y);
        send([op], function (r) { return (r.created && r.created.zones || []).map(function (n) { return key('zone', n); }); });
    }

    /**
     * The Zone button: around the selected nodes at once; otherwise the
     * zone follows the pointer and a click on the map drops it.
     */
    function startPlacing() {
        if (selected('node').length) {
            addZone(null);
            return;
        }
        if (state.tool !== 'select') { setTool('select'); }
        state.placing = true;
        stage.classList.add('placing');
        updateToolbar();
        renderOverlay();
        toast(t('Click on the map to place the zone. Esc to cancel.'));
    }

    function stopPlacing() {
        if (!state.placing) { return false; }
        state.placing = false;
        stage.classList.remove('placing');
        updateToolbar();
        renderOverlay();
        return true;
    }

    function duplicateSelection() {
        var nodes = selected('node');
        if (!nodes.length) {
            return;
        }
        send([{ op: 'clone', nodes: nodes.map(function (n) { return n.name; }), dx: 20, dy: 20 }], createdKeys);
    }

    /** The keys of what the server just created, to select it */
    function createdKeys(r) {
        var c = r.created || {};
        return (c.nodes || []).map(function (n) { return key('node', n); }).concat((c.links || []).map(function (n) { return key('link', n); }));
    }

    /**
     * What align and distribute move as one: each group of the selection,
     * and each object outside a group. When everything selected belongs to
     * one group, its members line up one by one. A unit has a key, its
     * objects, its box and, for a single node, the node itself.
     */
    function alignUnits(objects) {
        var byGroup = new Map(), units = [];
        objects.forEach(function (o) {
            var g = groupOf(o.item);
            if (g) {
                if (!byGroup.has(g)) { byGroup.set(g, []); }
                byGroup.get(g).push(o);
            } else {
                units.push([o]);
            }
        });
        if (!units.length && byGroup.size === 1) {
            units = objects.map(function (o) { return [o]; });
        } else {
            byGroup.forEach(function (members) { units.push(members); });
        }
        return units.map(function (members) {
            return {
                key: members[0].key, objects: members,
                box: unionBox(members.map(function (o) { return o.box; })),
                node: members.length === 1 && members[0].kind === 'node' ? members[0].item : null
            };
        });
    }

    /**
     * Line up, or space evenly, the selected nodes, zones, legends,
     * timestamps and title, by their boxes. Groups move as a whole.
     */
    function align(how) {
        var units = alignUnits(selectedObjects());
        if (units.length < 2) {
            return;
        }
        var all = unionBox(units.map(function (u) { return u.box; }));
        var minX = all[0], minY = all[1], maxX = all[2], maxY = all[3];
        var shift = {};

        units.forEach(function (u) {
            var b = u.box, dx = 0, dy = 0;
            if (how === 'left') { dx = minX - b[0]; }
            if (how === 'right') { dx = maxX - b[2]; }
            if (how === 'hcenter') { dx = (minX + maxX) / 2 - centre(u, 'x'); }
            if (how === 'top') { dy = minY - b[1]; }
            if (how === 'bottom') { dy = maxY - b[3]; }
            if (how === 'vcenter') { dy = (minY + maxY) / 2 - centre(u, 'y'); }
            shift[u.key] = [dx, dy];
        });

        if (how === 'distribute-h' || how === 'distribute-v') {
            var axis = how === 'distribute-h' ? 'x' : 'y';
            var sorted = units.slice().sort(function (a, b) { return centre(a, axis) - centre(b, axis); });
            var first = centre(sorted[0], axis), last = centre(sorted[sorted.length - 1], axis);
            var step = (last - first) / (sorted.length - 1);
            sorted.forEach(function (u, i) {
                var d = first + step * i - centre(u, axis);
                shift[u.key] = axis === 'x' ? [d, 0] : [0, d];
            });
        }

        var op = { op: 'move', nodes: {}, zones: {}, furniture: {} };
        units.forEach(function (u) {
            var dx = Math.round(shift[u.key][0]), dy = Math.round(shift[u.key][1]);
            u.objects.forEach(function (o) {
                if (o.kind === 'node') { op.nodes[o.item.name] = [o.item.x + dx, o.item.y + dy]; }
                else if (o.kind === 'zone') { op.zones[o.item.name] = [o.item.x + dx, o.item.y + dy]; }
                else { op.furniture[o.key] = [o.item.pos[0] + dx, o.item.pos[1] + dy]; }
            });
        });
        send([op]);
    }

    /** The centre a single node is aligned by is its position; for the rest, the box */
    function centre(u, axis) {
        if (u.node) { return axis === 'x' ? u.node.x : u.node.y; }
        return axis === 'x' ? (u.box[0] + u.box[2]) / 2 : (u.box[1] + u.box[3]) / 2;
    }

    function itemsByKind() {
        return { node: selected('node'), link: selected('link'), zone: selected('zone') };
    }

    function hintOps(keyName, value, filter) {
        var by = itemsByKind(), ops = [];
        ['node', 'link', 'zone'].forEach(function (kind) {
            var names = by[kind].filter(filter || function () { return true; }).map(function (i) { return i.name; });
            if (names.length) {
                ops.push({ op: 'hint', kind: kind, names: names, key: keyName, value: value });
            }
        });
        return ops;
    }

    function group() {
        var by = itemsByKind();
        if (by.node.length + by.link.length + by.zone.length < 2) {
            toast(t('Select at least two items to group them.'));
            return;
        }
        send(hintOps('editor_group', 'g' + Date.now().toString(36)));
    }

    function ungroup() {
        send(hintOps('editor_group', null));
    }

    function toggleLock() {
        var by = itemsByKind(), all = by.node.concat(by.link, by.zone);
        var lock = !all.every(function (i) { return i.locked; });
        send(hintOps('editor_lock', lock ? '1' : null));
    }

    function zorder(where) {
        var by = itemsByKind();
        var all = state.model.nodes.concat(state.model.links).map(function (i) { return i.zorder; });
        var z = where === 'front' ? Math.max.apply(null, all) + 1 : Math.max(0, Math.min.apply(null, all) - 1);
        var ops = [];
        if (by.node.length) { ops.push({ op: 'set_node', names: by.node.map(function (n) { return n.name; }), props: { zorder: z } }); }
        if (by.link.length) { ops.push({ op: 'set_link', names: by.link.map(function (l) { return l.name; }), props: { zorder: z } }); }
        send(ops);
    }

    function selectAll() {
        var keys = [];
        state.nodes.forEach(function (n) { keys.push(key('node', n.name)); });
        state.links.forEach(function (l) { keys.push(key('link', l.name)); });
        state.zones.forEach(function (z) { keys.push(key('zone', z.name)); });
        state.furniture.forEach(function (f, k) { keys.push(k); });
        state.selection = new Set(keys);
        refresh();
    }

    function setTool(tool) {
        stopPlacing();
        state.tool = tool;
        state.linkFrom = null;
        updateToolbar();
        renderOverlay();
        if (tool === 'link') {
            toast(t('Click a node, then a second one, to link them. Esc to stop.'));
        }
    }

    function askResize(w, hh) {
        var dlg = $('#resize-dialog');
        dlg.returnValue = '';
        dlg.showModal();
        dlg.addEventListener('close', function onClose() {
            dlg.removeEventListener('close', onClose);
            if (dlg.returnValue !== 'ok') {
                renderOverlay();
                return;
            }
            var f = dlg.querySelector('form');
            send([{
                op: 'set_map', width: w, height: hh,
                resize_mode: f.mode.value, keep_aspect: f.keep_aspect.checked, scale_sizes: f.scale_sizes.checked
            }]).then(zoomFit);
        });
    }

    // ------------------------------------------------------------------
    // Auto-route: bend links around the nodes in their way

    function segmentHitsBox(p, q, b) {
        // Liang-Barsky clipping of segment pq against box b
        var t0 = 0, t1 = 1, dx = q[0] - p[0], dy = q[1] - p[1];
        var checks = [[-dx, p[0] - b[0]], [dx, b[2] - p[0]], [-dy, p[1] - b[1]], [dy, b[3] - p[1]]];
        for (var i = 0; i < 4; i++) {
            var pp = checks[i][0], qq = checks[i][1];
            if (pp === 0) {
                if (qq < 0) { return false; }
            } else {
                var tt = qq / pp;
                if (pp < 0) { if (tt > t1) { return false; } if (tt > t0) { t0 = tt; } } else { if (tt < t0) { return false; } if (tt < t1) { t1 = tt; } }
            }
        }
        return t0 < t1;
    }

    function routeCost(points, obstacles) {
        var hits = 0, len = 0;
        for (var i = 0; i < points.length - 1; i++) {
            var p = points[i], q = points[i + 1];
            len += Math.hypot(q[0] - p[0], q[1] - p[1]);
            obstacles.forEach(function (b) { if (segmentHitsBox(p, q, b)) { hits++; } });
        }
        return hits * 1e6 + len + (points.length - 2) * 25;
    }

    function autoRoute() {
        var links = selected('link');
        if (!links.length) {
            links = Array.from(state.links.values());
        }
        links = links.filter(function (l) { return !l.locked; });
        var margin = 8, ops = [];

        links.forEach(function (l) {
            var ends = linkEnds(l), a = ends[0], b = ends[1];
            var obstacles = [];
            state.nodes.forEach(function (n) {
                if (n.name !== l.a && n.name !== l.b) {
                    obstacles.push([n.box[0] - margin, n.box[1] - margin, n.box[2] + margin, n.box[3] + margin]);
                }
            });

            var candidates = [[]];
            candidates.push([[b[0], a[1]]], [[a[0], b[1]]]);
            var xs = [(a[0] + b[0]) / 2], ys = [(a[1] + b[1]) / 2];
            obstacles.forEach(function (o) { xs.push(o[0] - margin, o[2] + margin); ys.push(o[1] - margin, o[3] + margin); });
            xs.forEach(function (x) { candidates.push([[x, a[1]], [x, b[1]]]); });
            ys.forEach(function (y) { candidates.push([[a[0], y], [b[0], y]]); });

            var best = null, bestCost = Infinity;
            candidates.forEach(function (vias) {
                var c = routeCost([a].concat(vias, [b]), obstacles);
                if (c < bestCost) { bestCost = c; best = vias; }
            });

            var current = routeCost([a].concat(l.via, [b]), obstacles);
            if (best.length === 0) {
                if (l.via.length && current >= 1e6) {
                    ops.push({ op: 'set_link', names: [l.name], props: { via: [] } });
                }
            } else if (bestCost < current) {
                ops.push({
                    op: 'set_link', names: [l.name],
                    props: { via: best.map(function (v) { return [Math.round(v[0]), Math.round(v[1])]; }), viastyle: 'angled' }
                });
            }
        });

        if (!ops.length) {
            toast(t('Nothing to reroute: no link crosses a node.'));
            return;
        }
        send(ops).then(function () { toast(t(':n link(s) rerouted. Ctrl+Z to undo.', { n: ops.length })); });
    }

    // ------------------------------------------------------------------
    // Untangle bandwidth labels

    function pointAlong(spine, pct) {
        var lens = [0];
        for (var i = 1; i < spine.length; i++) {
            lens.push(lens[i - 1] + Math.hypot(spine[i][0] - spine[i - 1][0], spine[i][1] - spine[i - 1][1]));
        }
        var target = lens[lens.length - 1] * pct / 100;
        for (var j = 1; j < spine.length; j++) {
            if (lens[j] >= target) {
                var frac = (target - lens[j - 1]) / ((lens[j] - lens[j - 1]) || 1);
                return [spine[j - 1][0] + (spine[j][0] - spine[j - 1][0]) * frac, spine[j - 1][1] + (spine[j][1] - spine[j - 1][1]) * frac];
            }
        }
        return spine[spine.length - 1];
    }

    function overlaps(r, s) {
        return r[0] < s[2] && s[0] < r[2] && r[1] < s[3] && s[1] < r[3];
    }

    function effectivePos(l) {
        if (l.labelpos_in === 75 && l.labelpos_out === 25) {
            return { in: (l.splitpos + 100) / 2, out: l.splitpos / 2 };
        }
        return { in: l.labelpos_in, out: l.labelpos_out };
    }

    function untangle() {
        var labels = [];
        $$('#map g[data-kind="link"]').forEach(function (g) {
            var l = state.links.get(g.dataset.name);
            ['in', 'out'].forEach(function (dir) {
                var box = g.querySelector('polygon.wm-bwlabel-' + dir) || g.querySelector('text.wm-bwlabel-' + dir);
                if (!box || !l) { return; }
                var bb = box.getBBox();
                labels.push({ link: l, dir: dir, w: bb.width, h: bb.height, rect: [bb.x, bb.y, bb.x + bb.width, bb.y + bb.height] });
            });
        });

        var nodeBoxes = state.model.nodes.map(function (n) { return n.box; });
        var placed = [], changes = {};

        labels.forEach(function (lab) {
            var clash = function (r) {
                return placed.some(function (o) { return overlaps(r, o); }) || nodeBoxes.some(function (b) { return overlaps(r, b); });
            };
            if (!clash(lab.rect) || lab.link.locked || lab.link.spine.length < 2) {
                placed.push(lab.rect);
                return;
            }
            var pos = effectivePos(lab.link);
            var current = pos[lab.dir];
            var range = lab.dir === 'in' ? [52, 95] : [5, 48];
            var options = [];
            for (var p = range[0]; p <= range[1]; p += 3) { options.push(p); }
            options.sort(function (x, y) { return Math.abs(x - current) - Math.abs(y - current); });

            for (var i = 0; i < options.length; i++) {
                var c = pointAlong(lab.link.spine, options[i]);
                var r = [c[0] - lab.w / 2, c[1] - lab.h / 2, c[0] + lab.w / 2, c[1] + lab.h / 2];
                if (!clash(r)) {
                    placed.push(r);
                    changes[lab.link.name] = changes[lab.link.name] || effectivePos(lab.link);
                    changes[lab.link.name][lab.dir] = options[i];
                    return;
                }
            }
            placed.push(lab.rect);
        });

        var ops = Object.keys(changes).map(function (name) {
            return { op: 'set_link', names: [name], props: { labelpos_in: Math.round(changes[name].in), labelpos_out: Math.round(changes[name].out) } };
        });
        if (!ops.length) {
            toast(t('No overlapping labels found.'));
            return;
        }
        send(ops).then(function () { toast(t(':n link label(s) moved. Ctrl+Z to undo.', { n: ops.length })); });
    }

    // ------------------------------------------------------------------
    // Select by criteria

    function findMatches(f) {
        var mode = f.mode.value, field = f.field.value, value = f.value.value, kinds = f.kind.value;
        var re = null;
        if (mode === 'regex') {
            try { re = new RegExp(value, 'i'); } catch (e) { return null; }
        }
        function test(v) {
            v = v == null ? '' : String(v);
            if (mode === 'empty') { return v === ''; }
            if (mode === 'equals') { return v.toLowerCase() === value.toLowerCase(); }
            if (mode === 'regex') { return re.test(v); }
            return v.toLowerCase().indexOf(value.toLowerCase()) >= 0;
        }
        var keys = [];
        if (kinds !== 'link') {
            state.nodes.forEach(function (n) {
                if (field !== 'width' && field !== 'bandwidth_in' && field !== 'node' && test(n[field])) { keys.push(key('node', n.name)); }
            });
        }
        if (kinds !== 'node') {
            state.links.forEach(function (l) {
                var ok = field === 'node' ? (test(l.a) || test(l.b)) : (field !== 'label' && field !== 'icon' && test(l[field]));
                if (ok) { keys.push(key('link', l.name)); }
            });
        }
        return keys;
    }

    function openFind() {
        var dlg = $('#find-dialog'), f = dlg.querySelector('form');
        var count = dlg.querySelector('.find-count');
        function update() {
            var m = findMatches(f);
            count.textContent = m === null ? t('Invalid regular expression') : t(':n item(s) match', { n: m.length });
        }
        f.oninput = update;
        update();
        dlg.returnValue = '';
        dlg.showModal();
        f.value.focus();
        dlg.onclose = function () {
            if (dlg.returnValue !== 'ok') { return; }
            var m = findMatches(f) || [];
            if (f.add.checked) { m = m.concat(Array.from(state.selection)); }
            state.selection = new Set(m);
            refresh();
            toast(t(':n item(s) selected', { n: m.length }));
        };
    }

    // ------------------------------------------------------------------
    // Inspector

    function common(items, field) {
        if (!items.length) { return { value: '', mixed: false }; }
        var v = items[0][field];
        var mixed = items.some(function (i) { return String(i[field]) !== String(v); });
        return { value: mixed ? '' : v, mixed: mixed };
    }

    function row(label, input, full) {
        return h('label', { 'class': 'row' + (full ? ' full' : '') }, [h('span', { text: label }), input]);
    }

    function field(items, name, kind, opts) {
        opts = opts || {};
        var c = common(items, name);
        var input;
        if (opts.options) {
            input = h('select', { name: name }, (c.mixed ? [h('option', { value: '', text: t('(mixed)') })] : []).concat(opts.options.map(function (o) {
                var val = Array.isArray(o) ? o[0] : o, text = Array.isArray(o) ? o[1] : o;
                return h('option', { value: val, text: text, selected: !c.mixed && String(c.value) === String(val) });
            })));
        } else {
            input = h('input', {
                name: name, type: opts.type || 'text', value: c.value, placeholder: c.mixed ? t('(mixed)') : (opts.placeholder || ''),
                'class': c.mixed ? 'mixed' : '', list: opts.list || null
            });
        }
        input.addEventListener('change', function () {
            if (input.value === '' && c.mixed) { return; }
            var props = {};
            props[opts.prop || name] = opts.number ? +input.value : input.value;
            send([{ op: 'set_' + kind, names: items.map(function (i) { return i.name; }), props: props }]);
        });
        return input;
    }

    function colourField(items, name, kind, allowContrast) {
        var c = common(items, name);
        var text = h('input', { name: name, value: c.value, placeholder: c.mixed ? t('(mixed)') : t('none'), 'class': c.mixed ? 'mixed' : '' });
        var swatch = h('input', { type: 'color', value: /^#[0-9a-f]{6}$/i.test(c.value) ? c.value : '#ffffff', title: t('Pick a colour') });
        function save(v) {
            var props = {};
            props[name] = v;
            send([{ op: 'set_' + kind, names: items.map(function (i) { return i.name; }), props: props }]);
        }
        swatch.addEventListener('change', function () { text.value = swatch.value; save(swatch.value); });
        text.addEventListener('change', function () { save(text.value); });
        var buttons = [h('button', { type: 'button', text: t('none'), title: t('No colour'), onclick: function () { save('none'); } })];
        if (allowContrast) {
            buttons.push(h('button', { type: 'button', text: t('auto'), title: t('Black or white, whichever reads best'), onclick: function () { save('contrast'); } }));
        }
        return h('div', { 'class': 'pair' }, [swatch, text].concat(buttons));
    }

    function renderInspector() {
        if (document.activeElement && inspector.contains(document.activeElement) && document.activeElement.tagName !== 'BUTTON') {
            // do not rebuild the form under the user's cursor
            inspector.dataset.stale = '1';
            return;
        }
        inspector.dataset.stale = '';
        var nodes = selected('node'), links = selected('link'), zones = selected('zone'), furniture = selectedFurniture();
        var parts = [];

        if (!nodes.length && !links.length && !zones.length && !furniture.length) {
            parts = mapPanel();
        } else {
            if (nodes.length) { parts = parts.concat(nodePanel(nodes)); }
            if (links.length) { parts = parts.concat(linkPanel(links)); }
            if (zones.length) { parts = parts.concat(zonePanel(zones)); }
            if (furniture.length) { parts = parts.concat(furniturePanel(furniture)); }
            parts.push(h('div', { 'class': 'actions' }, [
                h('button', { type: 'button', text: t('Duplicate'), title: 'Ctrl+D', onclick: duplicateSelection, disabled: !nodes.length }),
                h('button', { type: 'button', 'class': 'danger', text: t('Delete'), title: t('Delete key'), onclick: deleteSelection, disabled: !nodes.length && !links.length && !zones.length })
            ]));
        }
        inspector.replaceChildren.apply(inspector, parts);
    }

    inspector.addEventListener('focusout', function () {
        setTimeout(function () {
            if (inspector.dataset.stale && !inspector.contains(document.activeElement)) {
                renderInspector();
            }
        }, 0);
    });

    function mapPanel() {
        var m = state.model.map;
        var title = h('input', { name: 'title', value: m.title });
        title.addEventListener('change', function () { send([{ op: 'set_map', title: title.value }]); });
        var w = h('input', { name: 'width', type: 'number', min: 50, value: m.width });
        var hh = h('input', { name: 'height', type: 'number', min: 50, value: m.height });
        function size() {
            if (+w.value !== m.width || +hh.value !== m.height) { askResize(+w.value, +hh.value); }
        }
        w.addEventListener('change', size);
        hh.addEventListener('change', size);
        var bg = h('select', { name: 'background' }, [h('option', { value: '', text: t('(none)') })].concat(cfg.backgrounds.map(function (b) {
            return h('option', { value: b, text: b.split('/').pop(), selected: b === m.background });
        })));
        bg.addEventListener('change', function () { send([{ op: 'set_map', background: bg.value }]); });

        return [
            h('h2', { text: t('Map') }),
            row(t('Title'), title),
            row(t('Size'), h('div', { 'class': 'pair' }, [w, h('label', { text: '×' }), hh])),
            row(t('Background'), bg),
            propsButton('general', t('Map properties…')),
            h('p', { 'class': 'help', text: t(':w × :h px, :nodes nodes, :links links.', { w: m.width, h: m.height, nodes: state.model.nodes.length, links: state.model.links.length }) }),
            h('h3', { text: t('Shortcuts') }),
            help([
                [t('Click, Shift+click'), t('select, add to selection')],
                [t('Drag on empty space'), t('select an area')],
                [t('Alt+click'), t('one item of a group')],
                [t('Ctrl+click empty space'), t('new node')],
                ['Ctrl+A', t('select everything, zones and legends included')],
                ['Esc', t('cancel the drag, close the menu, or select nothing')],
                [t('Arrows, Shift+arrows'), t('move by 1 or 10 px')],
                [t('Alt while dragging'), t('no snapping to the other objects')],
                [t('Ctrl+drag'), t('duplicate the selected nodes')],
                [t('Double-click a link'), t('add a bend point there')],
                ['Ctrl+Z, Ctrl+Shift+Z', t('undo, redo')],
                [t('Ctrl+D / Delete'), t('duplicate / delete')],
                ['Ctrl+G / Ctrl+Shift+G', t('group / ungroup')],
                ['Ctrl+C / Ctrl+X / Ctrl+V', t('copy / cut / paste, also into another map')],
                [t('Right-click, Shift+F10'), t('menu of actions')],
                ['L / V', t('link tool / select tool')],
                [t('Wheel, Space+drag'), t('zoom, move the view')],
                ['F / 0', t('fit / actual size')]
            ])
        ];
    }

    function help(lines) {
        return h('div', {}, lines.map(function (l) {
            return h('p', { 'class': 'help' }, [h('kbd', { text: l[0] }), ' ' + l[1]]);
        }));
    }

    function iconOptions() {
        return [['', t('(none)')], ['box', t('Box')], ['rbox', t('Rounded box')], ['round', t('Circle')]].concat(cfg.icons.map(function (i) {
            return [i, i.split('/').pop()];
        }));
    }

    function fontOptions() {
        return state.model.fonts.map(function (f) { return [f, f <= 5 ? t('Built-in :n', { n: f }) : t('Font :n', { n: f })]; });
    }

    function nodePanel(nodes) {
        var single = nodes.length === 1 ? nodes[0] : null;
        var out = [h('h2', { text: single ? t('Node') : t(':n nodes', { n: nodes.length }) })];

        if (single) {
            var name = h('input', { name: 'name', value: single.name });
            name.addEventListener('change', function () {
                send([{ op: 'rename_node', name: single.name, to: name.value }], function () { return [key('node', name.value.replace(/[^\w.\-:]/g, '_'))]; });
            });
            out.push(row(t('Name'), name));
        }

        out.push(row(t('Label'), field(nodes, 'label', 'node')));

        if (single) {
            var x = h('input', { type: 'number', value: single.x }), y = h('input', { type: 'number', value: single.y });
            var pos = function () { send([{ op: 'move', nodes: (function () { var o = {}; o[single.name] = [+x.value, +y.value]; return o; })() }]); };
            x.addEventListener('change', pos);
            y.addEventListener('change', pos);
            out.push(row(t('Position'), h('div', { 'class': 'pair' }, [x, y])));
        }

        out.push(
            row(t('Icon'), field(nodes, 'icon', 'node', { options: iconOptions() })),
            h('h3', { text: t('Label style') }),
            row(t('Font'), field(nodes, 'labelfont', 'node', { options: fontOptions(), number: true })),
            row(t('Background'), colourField(nodes, 'labelbgcolour', 'node')),
            row(t('Text'), colourField(nodes, 'labelfontcolour', 'node', true)),
            row(t('Outline'), colourField(nodes, 'labeloutlinecolour', 'node')),
            h('h3', { text: t('Data') }),
            row(t('Target'), field(nodes, 'target', 'node')),
            row(t('Info URL'), field(nodes, 'infourl', 'node')),
            row(t('Layer'), field(nodes, 'zorder', 'node', { type: 'number', number: true }))
        );

        if (single && single.label_box) {
            out.push(
                h('p', { 'class': 'help', text: t('Drag the label of the selected node to move it away from the icon.') }),
                h('div', { 'class': 'actions' }, [
                    h('button', {
                        type: 'button', text: t('Reset label position'), disabled: !single.label_shift[0] && !single.label_shift[1],
                        onclick: function () { send([{ op: 'set_node', names: [single.name], props: { labelshift: null } }]); }
                    })
                ])
            );
        }

        if (nodes.some(function (n) { return n.group; })) {
            out.push(h('p', { 'class': 'help', text: t('Grouped: clicking one selects the whole group. Alt+click picks one.') }));
        }
        return out;
    }

    function furnitureName(f) {
        if (f.kind === 'legend') { return t('Legend :name', { name: f.name }); }
        if (f.kind === 'timestamp') { return f.name === 'MIN' ? t('Oldest data time') : f.name === 'MAX' ? t('Newest data time') : t('Timestamp'); }
        if (f.kind === 'title') { return t('Title'); }
        return t('Link width legend');
    }

    /** Legends, timestamps and title: where they are */
    function furniturePanel(items) {
        var single = items.length === 1 ? items[0] : null;
        var out = [h('h2', { text: single ? furnitureName(single) : t(':n map items', { n: items.length }) })];
        if (single) {
            var x = h('input', { type: 'number', min: 0, value: single.pos[0] }), y = h('input', { type: 'number', min: 0, value: single.pos[1] });
            var place = function () {
                var f = {};
                f[key(single.kind, single.name)] = [+x.value, +y.value];
                send([{ op: 'move', furniture: f }]);
            };
            x.addEventListener('change', place);
            y.addEventListener('change', place);
            out.push(row(t('Position'), h('div', { 'class': 'pair' }, [x, y])));
        }
        out.push(h('p', { 'class': 'help', text: t('Drag it on the map, or use the arrow keys. It lines up with the other objects while dragged.') }));
        if (single && single.kind === 'legend') {
            out.push(h('p', { 'class': 'help', text: t('The legend gives the colour of a link by its load: the traffic in each direction as a percentage of the link bandwidth.') }));
        }
        if (single && single.kind !== 'widthlegend') {
            out.push(propsButton(single.kind === 'legend' ? 'legend' : single.kind === 'title' ? 'title' : 'timestamp', t('Text, font and colours…')));
        }
        return out;
    }

    /** Opens the Map properties dialog (editor-mapprops.js) at a section */
    function propsButton(section, text) {
        return h('button', { type: 'button', 'class': 'props-open', text: text, onclick: function () {
            if (window.WMEditor.openMapProperties) { window.WMEditor.openMapProperties(section); }
        } });
    }

    function zonePanel(zones) {
        var single = zones.length === 1 ? zones[0] : null;
        var out = [h('h2', { text: single ? t('Zone') : t(':n zones', { n: zones.length }) })];

        if (single) {
            var name = h('input', { name: 'name', value: single.name });
            name.addEventListener('change', function () {
                send([{ op: 'set_zone', names: [single.name], props: { name: name.value } }], function () { return [key('zone', name.value.replace(/[^\w.\-:]/g, '_'))]; });
            });
            out.push(row(t('Name'), name));
        }

        out.push(row(t('Label'), field(zones, 'label', 'zone')));

        if (single) {
            out.push(
                row(t('Position'), h('div', { 'class': 'pair' }, [field(zones, 'x', 'zone', { type: 'number', number: true }), field(zones, 'y', 'zone', { type: 'number', number: true })])),
                row(t('Size'), h('div', { 'class': 'pair' }, [field(zones, 'width', 'zone', { type: 'number', number: true }), h('label', { text: '×' }), field(zones, 'height', 'zone', { type: 'number', number: true })]))
            );
        }

        out.push(
            h('h3', { text: t('Style') }),
            row(t('Fill'), colourField(zones, 'fillcolour', 'zone')),
            row(t('Opacity (%)'), field(zones, 'opacity', 'zone', { type: 'number', number: true })),
            row(t('Outline'), colourField(zones, 'outlinecolour', 'zone')),
            row(t('Outline width'), field(zones, 'outlinewidth', 'zone', { type: 'number', number: true })),
            row(t('Outline style'), field(zones, 'outlinestyle', 'zone', { options: [['solid', t('Solid')], ['dashed', t('Dashed')]] })),
            h('h3', { text: t('Label style') }),
            row(t('Font'), field(zones, 'labelfont', 'zone', { options: fontOptions(), number: true })),
            row(t('Text'), colourField(zones, 'labelfontcolour', 'zone')),
            row(t('Placement'), field(zones, 'labelpos', 'zone', { options: [
                ['NW', t('Top left')], ['N', t('Top centre')], ['NE', t('Top right')],
                ['SW', t('Bottom left')], ['S', t('Bottom centre')], ['SE', t('Bottom right')]
            ] })),
            h('p', { 'class': 'help', text: t('Zones are drawn under links and nodes. Dragging a zone by its edge or label moves the zone alone; group it with nodes (Ctrl+G) to move them together.') })
        );
        return out;
    }

    function anchorPicker(link, end) {
        var current = (link[end + '_offset'] || 'C').toUpperCase().replace(/\d+$/, '');
        return h('div', { 'class': 'anchors' }, ANCHORS.map(function (a) {
            return h('button', {
                type: 'button', text: a, 'class': a === current ? 'on' : '',
                onclick: function () {
                    var props = {};
                    props[end + '_offset'] = a;
                    send([{ op: 'set_link', names: [link.name], props: props }]);
                }
            });
        }));
    }

    function linkPanel(links) {
        var single = links.length === 1 ? links[0] : null;
        var out = [h('h2', { text: single ? t('Link :name', { name: single.name }) : t(':n links', { n: links.length }) })];

        if (single) {
            out.push(h('p', { 'class': 'help', text: single.a + ' → ' + single.b }));
        }

        out.push(
            row(t('Width'), field(links, 'width', 'link', { placeholder: t('auto, or pixels'), list: 'width-options' })),
            row(t('Bandwidth'), field(links, 'bandwidth_in', 'link', { prop: 'bandwidth', placeholder: t('auto, 1G, 10G...'), list: 'bw-options' })),
            h('datalist', { id: 'width-options' }, ['auto', '2', '4', '6', '9', '12'].map(function (v) { return h('option', { value: v }); })),
            h('datalist', { id: 'bw-options' }, ['auto', '100M', '1G', '10G', '25G', '40G', '100G', '400G'].map(function (v) { return h('option', { value: v }); })),
            row(t('Target'), field(links, 'target', 'link')),
            interfacePicker(links),
            h('h3', { text: t('Shape') }),
            row(t('Style'), field(links, 'viastyle', 'link', { options: [['curved', t('Curved')], ['angled', t('Straight segments')]] })),
            row(t('Arrows'), field(links, 'linkstyle', 'link', { options: [['twoway', t('Both ways')], ['oneway', t('One way')]] })),
            row(t('Split at %'), field(links, 'splitpos', 'link', { type: 'number', number: true })),
            row(t('Layer'), field(links, 'zorder', 'link', { type: 'number', number: true }))
        );

        if (single) {
            out.push(
                row(t('Ends'), h('div', { 'class': 'pair' }, [anchorPicker(single, 'a'), anchorPicker(single, 'b')])),
                h('p', { 'class': 'help', text: t('Drag the red points on the link to bend it, or double-click the link to add one. Double-click a point to remove it. Drag a bandwidth label along the link to move it.') }),
                h('div', { 'class': 'actions' }, [
                    h('button', { type: 'button', text: t('Straighten'), disabled: !single.via.length, onclick: function () { send([{ op: 'set_link', names: [single.name], props: { via: [] } }]); } }),
                    h('button', { type: 'button', text: t('Auto-route'), onclick: autoRoute })
                ]),
                h('h3', { text: t('Comments') }),
                row(t('Near :node', { node: single.a }), field(links, 'comment_out', 'link')),
                row(t('Near :node', { node: single.b }), field(links, 'comment_in', 'link'))
            );
        }
        return out;
    }

    // The devices and interfaces of the data sources, fetched once per page
    var dsCache = { sources: null, hosts: {}, ifaces: {}, lastSource: null };

    function dsFetch(store, key, url) {
        if (!store[key]) {
            store[key] = api('GET', url).then(function (d) { return (d && d.data) || []; });
            store[key].catch(function () { delete store[key]; });
        }
        return store[key];
    }
    function dsSources() { return dsFetch(dsCache, 'sources', '/api/data-sources'); }
    function dsHosts(sourceId) {
        return dsFetch(dsCache.hosts, sourceId, '/api/data-sources/' + encodeURIComponent(sourceId) + '/hosts');
    }
    function dsInterfaces(sourceId, hostId) {
        return dsFetch(dsCache.ifaces, sourceId + '/' + hostId, '/api/data-sources/' + encodeURIComponent(sourceId) + '/hosts/' + encodeURIComponent(hostId) + '/bandwidths');
    }

    /** Interface number: the SNMP index when the data source gives one */
    function ifaceNumber(i) {
        if (i.ifIndex != null && i.ifIndex !== '') { return String(i.ifIndex); }
        return /^\d+$/.test(String(i.interfaceId)) && !i.target ? String(i.interfaceId) : '';
    }

    /** The link's data source hint, {sourceId, hostId, interfaceId...}, or null */
    function parseSelection(value) {
        if (value === '' || value == null) { return null; }
        if (/^\d+$/.test(String(value))) { return { sourceId: +value }; }
        try {
            var v = JSON.parse(value);
            return v && v.sourceId ? v : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * A search box over a list. Every word typed must appear in the item's
     * text; arrows move through the results, Enter picks.
     */
    function searchList(opts) {
        var LIMIT = 50;
        var input = h('input', { type: 'search', placeholder: opts.placeholder, autocomplete: 'off', 'aria-label': opts.placeholder });
        var results = h('div', { 'class': 'ds-results', role: 'listbox' }, [h('p', { 'class': 'help', text: t('Loading…') })]);
        var items = null;

        function render() {
            if (!items) { return; }
            var words = input.value.toLowerCase().split(/\s+/).filter(Boolean);
            var found = items.filter(function (i) {
                var text = opts.haystack(i).toLowerCase();
                return words.every(function (w) { return text.indexOf(w) >= 0; });
            });
            var out = found.slice(0, LIMIT).map(function (i) {
                return h('button', { type: 'button', role: 'option', 'class': 'ds-result', onclick: function () { opts.onPick(i); } }, [
                    h('span', { text: opts.text(i) }),
                    opts.detail && opts.detail(i) ? h('small', { text: opts.detail(i) }) : null
                ]);
            });
            if (!found.length) {
                out.push(h('p', { 'class': 'help', text: items.length ? t('Nothing matches') : opts.empty }));
            } else if (found.length > LIMIT) {
                out.push(h('p', { 'class': 'help', text: t(':n more, type to narrow the search', { n: found.length - LIMIT }) }));
            }
            results.replaceChildren.apply(results, out);
        }

        input.addEventListener('input', render);
        input.addEventListener('keydown', function (evt) {
            var first = $('.ds-result', results);
            if (evt.key === 'ArrowDown' && first) {
                first.focus();
            } else if (evt.key === 'Enter') {
                // from the result, so the inspector can rebuild after the pick
                if (first) { first.focus(); first.click(); }
            } else if (evt.key === 'Escape') {
                if (input.value !== '') {
                    input.value = '';
                    render();
                } else {
                    input.blur();
                }
            } else {
                return;
            }
            evt.preventDefault();
            evt.stopPropagation();
        });
        results.addEventListener('keydown', function (evt) {
            var list = $$('.ds-result', results), i = list.indexOf(document.activeElement);
            if (evt.key === 'ArrowDown') {
                (list[i + 1] || list[i]).focus();
            } else if (evt.key === 'ArrowUp') {
                (i > 0 ? list[i - 1] : input).focus();
            } else if (evt.key === 'Escape') {
                input.focus();
            } else {
                // Typing on a result goes back to the search box, and never
                // reaches the editor shortcuts (Backspace deletes the link)
                if (evt.key !== 'Tab' && evt.key !== 'Enter' && evt.key !== ' ' && !evt.ctrlKey && !evt.metaKey) {
                    input.focus();
                }
                evt.stopPropagation();
                return;
            }
            evt.preventDefault();
            evt.stopPropagation();
        });

        opts.items.then(function (list) {
            items = list;
            render();
        }).catch(function (e) {
            results.replaceChildren(h('p', { 'class': 'help', text: e.message }));
        });

        return { el: h('div', { 'class': 'ds-search' }, [input, results]), input: input };
    }

    /**
     * Where the link's traffic comes from: a device, then one of its
     * interfaces, both found by typing part of their name (or the interface
     * number). The data source is asked for only when there are several.
     */
    function interfacePicker(links) {
        var c = common(links, 'datasource');
        var current = c.mixed ? null : parseSelection(c.value);
        var picked = !!(current && current.hostId && current.interfaceId);
        var box = h('div', { 'class': 'ds-picker' });

        function apply(sourceId, host, it) {
            var selection = { sourceId: +sourceId, hostId: String(host.hostid), interfaceId: String(it.interfaceId), inKey: String(it.rxKey || ''), outKey: String(it.txKey || '') };
            var props = {
                target: it.target || ('zabbix:hostid:' + selection.hostId + ':key:' + selection.inKey + ':' + selection.outKey),
                datasource: selection
            };
            if (it.bandwidth) { props.bandwidth = it.bandwidth; }
            dsCache.lastSource = +sourceId;
            send([{ op: 'set_link', names: links.map(function (l) { return l.name; }), props: props }]);
        }

        function hostName(host) { return host.name || host.host || String(host.hostid); }
        function ifaceDetail(i) {
            var n = ifaceNumber(i);
            return n ? t('no. :n', { n: n }) : '';
        }

        // What is picked now, with a button to change it
        function showCurrent() {
            var what = h('div', { 'class': 'ds-what' }, [h('strong', { text: c.mixed ? t('(mixed)') : t('Loading…') })]);
            box.replaceChildren(
                what,
                h('div', { 'class': 'actions' }, [h('button', { type: 'button', text: t('Change'), onclick: function () { showSearch(true); } })])
            );
            if (!current) { return; }
            Promise.all([dsSources(), dsHosts(current.sourceId), dsInterfaces(current.sourceId, current.hostId)]).then(function (r) {
                var src = r[0].find(function (s) { return +s.id === +current.sourceId; });
                var host = r[1].find(function (x) { return String(x.hostid) === String(current.hostId); });
                var it = r[2].find(function (x) { return String(x.interfaceId) === String(current.interfaceId); });
                what.replaceChildren(
                    h('strong', { text: host ? hostName(host) : t('Device :id', { id: current.hostId }) }),
                    h('span', { text: (it ? it.label : t('Interface :id', { id: current.interfaceId })) + (it && ifaceDetail(it) ? ' · ' + ifaceDetail(it) : '') })
                );
                if (r[0].length > 1 && src) { what.appendChild(h('small', { text: src.name })); }
            }).catch(function () {
                what.replaceChildren(h('strong', { text: t('Device :id', { id: current.hostId }) }), h('span', { text: t('Interface :id', { id: current.interfaceId }) }));
            });
        }

        function showSearch(focus) {
            var sourceRow = h('div');
            var steps = h('div');
            var cancel = (picked || c.mixed) ? h('div', { 'class': 'actions' }, [h('button', { type: 'button', text: t('Cancel'), onclick: showCurrent })]) : null;
            box.replaceChildren.apply(box, [sourceRow, steps, cancel].filter(Boolean));

            function pickDevice(sourceId) {
                var list = searchList({
                    placeholder: t('Search for a device'),
                    items: dsHosts(sourceId),
                    text: hostName,
                    detail: function (x) { return x.host && x.host !== x.name ? x.host : ''; },
                    haystack: function (x) { return hostName(x) + ' ' + (x.host || ''); },
                    empty: t('No device in this data source'),
                    onPick: function (host) { pickInterface(sourceId, host); }
                });
                steps.replaceChildren(list.el);
                return list.input;
            }

            function pickInterface(sourceId, host) {
                var list = searchList({
                    placeholder: t('Search for an interface (name or number)'),
                    items: dsInterfaces(sourceId, host.hostid),
                    text: function (i) { return i.label || String(i.interfaceId); },
                    detail: ifaceDetail,
                    haystack: function (i) {
                        var n = ifaceNumber(i);
                        return [i.label, String(i.interfaceId)].concat(i.names || []).join(' ') + (n ? ' #' + n + ' ' + n : '');
                    },
                    empty: t('No interface with traffic data'),
                    onPick: function (it) { apply(sourceId, host, it); }
                });
                steps.replaceChildren(
                    h('div', { 'class': 'ds-chosen' }, [
                        h('strong', { text: hostName(host) }),
                        h('button', { type: 'button', text: t('Other device'), onclick: function () { pickDevice(sourceId).focus(); } })
                    ]),
                    list.el
                );
                list.input.focus();
            }

            dsSources().then(function (sources) {
                if (!sources.length) {
                    sourceRow.replaceChildren(h('p', { 'class': 'help', text: t('No data source configured') }));
                    return;
                }
                var ids = sources.map(function (s) { return +s.id; });
                var preferred = [current && +current.sourceId, dsCache.lastSource].find(function (id) { return ids.indexOf(id) >= 0; });
                var chosen = sources.length === 1 ? ids[0] : preferred;
                if (sources.length > 1) {
                    var select = h('select', { 'aria-label': t('Data source') },
                        (chosen ? [] : [h('option', { value: '', text: t('Choose a data source') })]).concat(sources.map(function (s) {
                            return h('option', { value: s.id, text: s.name, selected: +s.id === chosen });
                        })));
                    select.addEventListener('change', function () {
                        if (select.value) {
                            $('option[value=""]', select) && select.removeChild($('option[value=""]', select));
                            pickDevice(select.value).focus();
                        }
                    });
                    sourceRow.replaceChildren(row(t('Source'), select));
                }
                if (chosen) {
                    var input = pickDevice(chosen);
                    if (focus) { input.focus(); }
                }
            }).catch(function (e) {
                sourceRow.replaceChildren(h('p', { 'class': 'help', text: e.message }));
            });
        }

        if (picked || c.mixed) {
            showCurrent();
        } else {
            showSearch(false);
        }
        return h('div', {}, [h('h3', { text: t('Monitored interface') }), box]);
    }

    // ------------------------------------------------------------------
    // Palette

    function renderTemplates() {
        var holder = $('#palette-templates');
        var names = (state.model.templates.nodes || []).filter(function (n) { return n !== 'DEFAULT'; });
        holder.replaceChildren.apply(holder, names.map(function (n) {
            return h('div', { 'class': 'palette-item', draggable: 'true', 'data-template': n }, [h('span', { 'class': 'pi-box', text: 'T' }), ' ' + n]);
        }));
    }

    document.addEventListener('dragstart', function (evt) {
        var item = evt.target.closest && evt.target.closest('.palette-item, .palette-icon');
        if (!item) { return; }
        evt.dataTransfer.setData('application/x-wm-node', JSON.stringify({ template: item.dataset.template || 'DEFAULT', icon: item.dataset.icon || '' }));
        evt.dataTransfer.effectAllowed = 'copy';
    });
    stage.addEventListener('dragover', function (evt) {
        if (Array.prototype.indexOf.call(evt.dataTransfer.types, 'application/x-wm-node') >= 0) {
            evt.preventDefault();
            evt.dataTransfer.dropEffect = 'copy';
        }
    });
    stage.addEventListener('drop', function (evt) {
        var raw = evt.dataTransfer.getData('application/x-wm-node');
        if (!raw) { return; }
        evt.preventDefault();
        var d = JSON.parse(raw), p = toMap(evt);
        var opts = { x: snap(p.x), y: snap(p.y), template: d.template };
        if (d.icon) {
            opts.icon = d.icon;
            opts.name = d.icon.split('/').pop().replace(/\.\w+$/, '');
        }
        addNode(opts);
    });

    // ------------------------------------------------------------------
    // Copy, cut and paste (also into another map, through localStorage)

    var CLIP_KEY = 'wm-editor-clipboard', CLIP_VERSION = 1;
    var pasteCount = { time: null, n: 0 };

    function hasClip() {
        try { return !!localStorage.getItem(CLIP_KEY); } catch (e) { return false; }
    }

    function readClip() {
        try {
            var c = JSON.parse(localStorage.getItem(CLIP_KEY) || 'null');
            return c && c.version === CLIP_VERSION && typeof c.text === 'string' ? c : null;
        } catch (e) {
            return null;
        }
    }

    function copySelection(cut) {
        var nodes = selected('node');
        if (!nodes.length) {
            toast(t('Select nodes to copy them (links between copied nodes come along).'));
            return Promise.resolve();
        }
        var ok = false;
        return run(function () {
            return api('POST', '/editor/copy/' + cfg.mapId, { nodes: nodes.map(function (n) { return n.name; }) }).then(function (d) {
                d.time = Date.now();
                try {
                    localStorage.setItem(CLIP_KEY, JSON.stringify(d));
                } catch (e) {
                    throw new Error(t('The browser refused to keep the copy (storage full or disabled).'));
                }
                ok = true;
                toast(t(':nodes node(s) and :links link(s) copied', { nodes: d.nodes.length, links: d.links.length }));
            });
        }).then(function () {
            updateToolbar();
            if (cut && ok) {
                deleteSelection();
            }
        });
    }

    /** Paste at a map point (centre of the pasted nodes), or offset a little */
    function paste(at) {
        var clip = readClip();
        if (!clip) {
            toast(t('Nothing to paste: copy nodes first (Ctrl+C).'));
            return;
        }
        var op = { op: 'paste', text: clip.text };
        if (at) {
            op.at = [snap(at.x), snap(at.y)];
        } else if (clip.mapId === cfg.mapId) {
            // each paste of the same copy lands a little further
            if (pasteCount.time !== clip.time) { pasteCount = { time: clip.time, n: 0 }; }
            pasteCount.n++;
            op.dx = op.dy = 20 * pasteCount.n;
        } else {
            op.at = [
                Math.round(state.view.x + canvas.clientWidth / state.zoom / 2),
                Math.round(state.view.y + canvas.clientHeight / state.zoom / 2)
            ];
        }
        send([op], createdKeys);
    }

    window.addEventListener('storage', function (evt) {
        if (evt.key === CLIP_KEY && state.model) { updateToolbar(); }
    });

    // ------------------------------------------------------------------
    // Scale the layout of the selected nodes with handles

    var SCALE_DIRS = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];

    function boxOf(nodes) {
        return [
            Math.min.apply(null, nodes.map(function (n) { return n.box[0]; })),
            Math.min.apply(null, nodes.map(function (n) { return n.box[1]; })),
            Math.max.apply(null, nodes.map(function (n) { return n.box[2]; })),
            Math.max.apply(null, nodes.map(function (n) { return n.box[3]; }))
        ];
    }

    function renderScaleBox(nodes) {
        var d = state.drag && state.drag.type === 'scale' && state.drag.started ? state.drag : null;
        var b = d ? d.preview : boxOf(nodes);
        var pad = 6 / state.zoom, s = 8 / state.zoom;
        var x1 = b[0] - pad, y1 = b[1] - pad, x2 = b[2] + pad, y2 = b[3] + pad;
        el('rect', { 'class': 'scale-box', x: x1, y: y1, width: x2 - x1, height: y2 - y1 }, overlay);
        if (d) {
            renderScaleGhosts(d);
        }
        var mx = (x1 + x2) / 2, my = (y1 + y2) / 2;
        var at = { nw: [x1, y1], n: [mx, y1], ne: [x2, y1], e: [x2, my], se: [x2, y2], s: [mx, y2], sw: [x1, y2], w: [x1, my] };
        SCALE_DIRS.forEach(function (dir) {
            el('rect', {
                'class': 'scale-handle h-' + dir, x: at[dir][0] - s / 2, y: at[dir][1] - s / 2, width: s, height: s,
                'data-handle': 'scale', 'data-dir': dir
            }, overlay).appendChild(el('title', {})).textContent = t('Drag to spread or tighten the selected nodes (Shift keeps proportions)');
        });
    }

    function startScale(evt, dir, p) {
        var nodes = selected('node');
        var b = boxOf(nodes);
        var movable = nodes.filter(function (n) { return !n.locked; });
        if (!movable.length) {
            toast(t('These nodes are locked. Unlock them to move them.'));
            return;
        }
        var inSel = {};
        nodes.forEach(function (n) { inSel[n.name] = true; });
        startDrag(evt, { type: 'scale', dir: dir, box: b, preview: b, start: p, nodes: movable, inSel: inSel, pos: {}, vias: {} });
    }

    function scaleMove(d, p, keep) {
        var b = d.box, dir = d.dir;
        var w = Math.max(1, b[2] - b[0]), hh = Math.max(1, b[3] - b[1]);
        var cx = (b[0] + b[2]) / 2, cy = (b[1] + b[3]) / 2;
        var dx = p.x - d.start.x, dy = p.y - d.start.y;
        var sx = 1, sy = 1, ax = cx, ay = cy;
        var horiz = dir.indexOf('e') >= 0 || dir.indexOf('w') >= 0, vert = dir.indexOf('n') >= 0 || dir.indexOf('s') >= 0;

        if (dir.indexOf('e') >= 0) { sx = (w + dx) / w; ax = b[0]; }
        if (dir.indexOf('w') >= 0) { sx = (w - dx) / w; ax = b[2]; }
        if (dir.indexOf('s') >= 0) { sy = (hh + dy) / hh; ay = b[1]; }
        if (dir.indexOf('n') >= 0) { sy = (hh - dy) / hh; ay = b[3]; }
        sx = Math.max(0.05, sx);
        sy = Math.max(0.05, sy);

        if (keep) {
            var k = horiz && vert ? (Math.abs(sx - 1) > Math.abs(sy - 1) ? sx : sy) : (horiz ? sx : sy);
            sx = sy = k;
        }

        function tx(x) { return ax + (x - ax) * sx; }
        function ty(y) { return ay + (y - ay) * sy; }

        d.pos = {};
        d.nodes.forEach(function (n) {
            d.pos[n.name] = [snap(tx(n.x)), snap(ty(n.y))];
        });
        d.vias = {};
        state.links.forEach(function (l) {
            if (l.via.length && d.inSel[l.a] && d.inSel[l.b]) {
                d.vias[l.name] = l.via.map(function (v) { return [Math.round(tx(v[0])), Math.round(ty(v[1]))]; });
            }
        });
        d.preview = [tx(b[0]), ty(b[1]), tx(b[2]), ty(b[3])];
        if (d.preview[0] > d.preview[2]) { d.preview = [d.preview[2], d.preview[1], d.preview[0], d.preview[3]]; }
        if (d.preview[1] > d.preview[3]) { d.preview = [d.preview[0], d.preview[3], d.preview[2], d.preview[1]]; }
        previewPositions(d.pos);
    }

    /** Show nodes at new positions while dragging, before the server draws them */
    function previewPositions(pos) {
        $$('#map g[data-kind="node"]').forEach(function (g) {
            var n = state.nodes.get(g.dataset.name), q = pos[g.dataset.name];
            if (q && n) {
                g.setAttribute('transform', 'translate(' + (q[0] - n.x) + ' ' + (q[1] - n.y) + ')');
            } else {
                g.removeAttribute('transform');
            }
        });
        $$('#map g[data-kind="link"]').forEach(function (g) {
            var l = state.links.get(g.dataset.name);
            g.classList.toggle('dimmed', !!(l && (pos[l.a] || pos[l.b])));
        });
    }

    function renderScaleGhosts(d) {
        state.links.forEach(function (l) {
            if (!d.pos[l.a] && !d.pos[l.b]) { return; }
            var a = state.nodes.get(l.a), b = state.nodes.get(l.b);
            if (d.pos[l.a]) { a = Object.assign({}, a, { x: d.pos[l.a][0], y: d.pos[l.a][1] }); }
            if (d.pos[l.b]) { b = Object.assign({}, b, { x: d.pos[l.b][0], y: d.pos[l.b][1] }); }
            var pts = [anchorPoint(a, l.a_offset)].concat(d.vias[l.name] || l.via, [anchorPoint(b, l.b_offset)]);
            el('polyline', { 'class': 'ghost', points: pts.join(' ') }, overlay);
        });
    }

    function scaleEnd(d) {
        var changed = d.started && Object.keys(d.pos).some(function (name) {
            var n = state.nodes.get(name);
            return n && (d.pos[name][0] !== n.x || d.pos[name][1] !== n.y);
        });
        if (!changed) {
            previewPositions({});
            refresh();
            return;
        }
        send([{ op: 'move', nodes: d.pos, vias: d.vias }]);
    }

    // ------------------------------------------------------------------
    // Overlapping nodes, and spreading them apart

    function boxesOverlap(a, b, gap) {
        return a[0] < b[2] + gap && b[0] < a[2] + gap && a[1] < b[3] + gap && b[1] < a[3] + gap;
    }

    function computeOverlaps() {
        var list = Array.from(state.nodes.values()), out = new Set();
        for (var i = 0; i < list.length; i++) {
            for (var j = i + 1; j < list.length; j++) {
                if (boxesOverlap(list[i].box, list[j].box, 0)) {
                    out.add(list[i].name);
                    out.add(list[j].name);
                }
            }
        }
        state.overlaps = out;
    }

    function renderOverlaps(moved) {
        if (!state.layers.nodelabels) {
            // labels are hidden: keep a trace of where each node is
            state.nodes.forEach(function (n) {
                el('rect', { 'class': 'node-trace', x: n.box[0], y: n.box[1], width: n.box[2] - n.box[0], height: n.box[3] - n.box[1] }, overlay);
            });
        }
        state.overlaps.forEach(function (name) {
            var n = state.nodes.get(name);
            if (!n || (moved && moved.nodes[name])) { return; }
            el('rect', { 'class': 'overlap', x: n.box[0] - 1, y: n.box[1] - 1, width: n.box[2] - n.box[0] + 2, height: n.box[3] - n.box[1] + 2 }, overlay)
                .appendChild(el('title', {})).textContent = t('Overlaps another node');
        });
    }

    /**
     * Push overlapping nodes apart until `gap` px separates them. The
     * selected nodes move (all nodes when nothing is selected); the others
     * and locked nodes stay. Each step moves along the shorter way out.
     */
    function spreadApart(gap) {
        gap = gap == null ? 10 : gap;
        var sel = selected('node');
        var pool = new Set((sel.length ? sel : Array.from(state.nodes.values())).filter(function (n) { return !n.locked; }).map(function (n) { return n.name; }));
        var items = Array.from(state.nodes.values()).map(function (n) {
            return { n: n, b: n.box.slice(), dx: 0, dy: 0, movable: pool.has(n.name) };
        });

        // only nodes that overlap something now take part
        var involved = new Set();
        items.forEach(function (a, i) {
            items.forEach(function (b, j) {
                if (i < j && (a.movable || b.movable) && boxesOverlap(a.b, b.b, 0)) { involved.add(i); involved.add(j); }
            });
        });
        if (!involved.size) {
            toast(t('No overlapping nodes.'));
            return;
        }

        for (var iter = 0; iter < 400; iter++) {
            var pushed = false;
            for (var i = 0; i < items.length; i++) {
                for (var j = i + 1; j < items.length; j++) {
                    var a = items[i], b = items[j];
                    if (!a.movable && !b.movable) { continue; }
                    if (!involved.has(i) && !involved.has(j)) { continue; }
                    var ab = [a.b[0] + a.dx, a.b[1] + a.dy, a.b[2] + a.dx, a.b[3] + a.dy];
                    var bb = [b.b[0] + b.dx, b.b[1] + b.dy, b.b[2] + b.dx, b.b[3] + b.dy];
                    if (!boxesOverlap(ab, bb, gap - 0.01)) { continue; }

                    var ox = Math.min(ab[2], bb[2]) - Math.max(ab[0], bb[0]) + gap;
                    var oy = Math.min(ab[3], bb[3]) - Math.max(ab[1], bb[1]) + gap;
                    var cax = (ab[0] + ab[2]) / 2, cbx = (bb[0] + bb[2]) / 2, cay = (ab[1] + ab[3]) / 2, cby = (bb[1] + bb[3]) / 2;
                    var horizontal = ox <= oy;
                    var sign = horizontal ? (cbx - cax || (j % 2 ? 1 : -1)) : (cby - cay || (j % 2 ? 1 : -1));
                    sign = sign > 0 ? 1 : -1;
                    var amount = Math.ceil(horizontal ? ox : oy);
                    var shareA = a.movable ? (b.movable ? amount / 2 : amount) : 0;
                    var shareB = amount - shareA;
                    if (horizontal) { a.dx -= sign * shareA; b.dx += sign * shareB; } else { a.dy -= sign * shareA; b.dy += sign * shareB; }
                    involved.add(i);
                    involved.add(j);
                    pushed = true;
                }
            }
            if (!pushed) { break; }
        }

        var pos = {}, count = 0;
        items.forEach(function (it) {
            var dx = Math.round(it.dx), dy = Math.round(it.dy);
            if (dx || dy) {
                pos[it.n.name] = [it.n.x + dx, it.n.y + dy];
                count++;
            }
        });
        if (!count) {
            toast(t('The overlapping nodes are locked or not selected.'));
            return;
        }
        send([{ op: 'move', nodes: pos }]).then(function () { toast(t(':n node(s) moved apart. Ctrl+Z to undo.', { n: count })); });
    }

    // ------------------------------------------------------------------
    // Layers hidden while editing (this browser only, never saved)

    function loadLayers() {
        var layers = { background: true, zones: true, links: true, linklabels: true, nodelabels: true };
        try {
            var saved = JSON.parse(localStorage.getItem('wm-editor-layers') || '{}');
            Object.keys(layers).forEach(function (k) { if (saved[k] === false) { layers[k] = false; } });
        } catch (e) { /* defaults */ }
        return layers;
    }

    function applyLayers() {
        Object.keys(state.layers).forEach(function (k) {
            stage.classList.toggle('hide-' + k, !state.layers[k]);
            var box = $('#layers-panel input[name="' + k + '"]');
            if (box) { box.checked = state.layers[k]; }
        });
        var hidden = Object.keys(state.layers).filter(function (k) { return !state.layers[k]; }).length;
        $('[data-cmd="layers"]').classList.toggle('active', hidden > 0);
    }

    function setLayer(name, on) {
        if (!(name in state.layers)) { return; }
        state.layers[name] = !!on;
        try { localStorage.setItem('wm-editor-layers', JSON.stringify(state.layers)); } catch (e) { /* this session only */ }
        applyLayers();
        renderOverlay();
    }

    function toggleLayersPanel(force) {
        var panel = $('#layers-panel'), btn = $('[data-cmd="layers"]');
        var open = typeof force === 'boolean' ? force : panel.hidden;
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            var first = panel.querySelector('input');
            if (first) { first.focus(); }
        }
    }

    $('#layers-panel').addEventListener('change', function (evt) {
        if (evt.target.name) { setLayer(evt.target.name, evt.target.checked); }
    });
    $('#layers-panel').addEventListener('keydown', function (evt) {
        if (evt.key === 'Escape') {
            toggleLayersPanel(false);
            $('[data-cmd="layers"]').focus();
            evt.preventDefault();
        }
    });
    document.addEventListener('pointerdown', function (evt) {
        if (!$('#layers-panel').hidden && !evt.target.closest('#layers-panel, [data-cmd="layers"]')) {
            toggleLayersPanel(false);
        }
    }, true);
    applyLayers();

    // ------------------------------------------------------------------
    // Context menu

    var menu = $('#ctx-menu');
    var menuReturn = null;

    function menuOpen() { return !menu.hidden; }

    function menuEntries(at) {
        var nodes = selected('node'), links = selected('link'), zones = selected('zone'), any = nodes.length + links.length + zones.length;
        var all = nodes.concat(links, zones), objects = alignUnits(selectedObjects()).length;
        var locked = all.length && all.every(function (i) { return i.locked; });
        var grouped = all.some(function (i) { return i.group; });
        var entries = [
            { label: t('Cut'), kbd: 'Ctrl+X', run: function () { copySelection(true); }, off: !nodes.length },
            { label: t('Copy'), kbd: 'Ctrl+C', run: function () { copySelection(false); }, off: !nodes.length },
            { label: t('Paste'), kbd: 'Ctrl+V', run: function () { paste(at); }, off: !hasClip() },
            { label: t('Duplicate'), kbd: 'Ctrl+D', run: duplicateSelection, off: !nodes.length },
            { label: t('Delete'), kbd: t('Del'), run: deleteSelection, off: !any, danger: true }
        ];
        if (all.length) {
            entries.push('-',
                { label: t('Group'), kbd: 'Ctrl+G', run: group, off: any < 2 },
                { label: t('Ungroup'), kbd: 'Ctrl+Shift+G', run: ungroup, off: !grouped },
                { label: locked ? t('Unlock') : t('Lock'), kbd: 'Ctrl+L', run: toggleLock },
                { label: t('Bring to front'), kbd: 'Ctrl+]', run: function () { zorder('front'); } },
                { label: t('Send to back'), kbd: 'Ctrl+[', run: function () { zorder('back'); } });
        }
        if (objects >= 2) {
            entries.push('-', {
                row: t('Align'), items: [
                    ['⎸←', t('Align left edges'), 'left'], ['↕', t('Align centres vertically'), 'hcenter'], ['→⎹', t('Align right edges'), 'right'],
                    ['⤒', t('Align top edges'), 'top'], ['↔', t('Align centres horizontally'), 'vcenter'], ['⤓', t('Align bottom edges'), 'bottom']
                ].concat(objects >= 3 ? [['⋯', t('Space evenly, left to right'), 'distribute-h'], ['⋮', t('Space evenly, top to bottom'), 'distribute-v']] : [])
            });
        }
        entries.push('-',
            { label: nodes.length ? t('Add a zone around the selection') : t('Add a zone here'), run: function () { addZone(at); } },
            { label: nodes.length ? t('Spread apart the selection') : t('Spread apart overlapping nodes'), run: function () { spreadApart(10); }, off: !state.overlaps.size },
            { label: t('Select all'), kbd: 'Ctrl+A', run: selectAll });
        return entries;
    }

    function openMenu(clientX, clientY, at) {
        menuReturn = document.activeElement;
        var items = menuEntries(at).map(function (e) {
            if (e === '-') {
                return h('div', { 'class': 'ctx-sep', role: 'separator' });
            }
            if (e.row) {
                return h('div', { 'class': 'ctx-row', role: 'group', 'aria-label': e.row }, [h('span', { text: e.row })].concat(e.items.map(function (it) {
                    return h('button', {
                        type: 'button', role: 'menuitem', tabindex: '-1', title: it[1], 'aria-label': it[1], text: it[0],
                        onclick: function () { closeMenu(); align(it[2]); }
                    });
                })));
            }
            return h('button', {
                type: 'button', role: 'menuitem', tabindex: '-1', disabled: !!e.off, 'class': e.danger ? 'danger' : '',
                onclick: function () { closeMenu(); e.run(); }
            }, [h('span', { text: e.label }), e.kbd ? h('kbd', { text: e.kbd }) : null]);
        });
        menu.replaceChildren.apply(menu, items);
        menu.hidden = false;
        var r = menu.getBoundingClientRect();
        menu.style.left = Math.max(4, Math.min(clientX, window.innerWidth - r.width - 4)) + 'px';
        menu.style.top = Math.max(4, Math.min(clientY, window.innerHeight - r.height - 4)) + 'px';
        var first = menuItems()[0];
        if (first) { first.focus(); } else { menu.focus(); }
    }

    function closeMenu() {
        if (menu.hidden) { return; }
        menu.hidden = true;
        menu.replaceChildren();
        if (menuReturn && menuReturn.focus && document.contains(menuReturn)) { menuReturn.focus(); }
        menuReturn = null;
    }

    function menuItems() {
        return $$('[role="menuitem"]:not([disabled])', menu);
    }

    function openMenuForSelection() {
        var nodes = selected('node'), links = selected('link'), x, y;
        if (nodes.length) {
            var b = boxOf(nodes);
            x = (b[0] + b[2]) / 2; y = (b[1] + b[3]) / 2;
        } else if (links.length && midpoint(links[0])) {
            x = midpoint(links[0])[0]; y = midpoint(links[0])[1];
        } else {
            x = state.view.x + canvas.clientWidth / state.zoom / 2;
            y = state.view.y + canvas.clientHeight / state.zoom / 2;
        }
        var pt = stage.createSVGPoint();
        pt.x = x; pt.y = y;
        var c = pt.matrixTransform(stage.getScreenCTM());
        openMenu(c.x, c.y, { x: x, y: y });
    }

    stage.addEventListener('contextmenu', function (evt) {
        evt.preventDefault();
        if (!state.model || state.textMode) { return; }
        var item = itemAt(evt);
        if (item) {
            var k = key(item.kind, item.name);
            if (!state.selection.has(k)) {
                state.selection = new Set(withGroups([k], evt.altKey));
                refresh();
            }
        } else if (!evt.target.dataset || !evt.target.dataset.handle) {
            if (state.selection.size) {
                state.selection.clear();
                refresh();
            }
        }
        openMenu(evt.clientX, evt.clientY, toMap(evt));
    });

    menu.addEventListener('keydown', function (evt) {
        var list = menuItems(), i = list.indexOf(document.activeElement);
        switch (evt.key) {
            case 'Escape': case 'Tab': closeMenu(); break;
            case 'ArrowDown': case 'ArrowRight': (list[(i + 1) % list.length] || list[0]).focus(); break;
            case 'ArrowUp': case 'ArrowLeft': (list[(i - 1 + list.length) % list.length] || list[0]).focus(); break;
            case 'Home': list[0].focus(); break;
            case 'End': list[list.length - 1].focus(); break;
            default: return;
        }
        evt.preventDefault();
        evt.stopPropagation();
    });
    document.addEventListener('pointerdown', function (evt) {
        if (menuOpen() && !menu.contains(evt.target)) { closeMenu(); }
    }, true);
    window.addEventListener('blur', closeMenu);
    window.addEventListener('resize', closeMenu);
    stage.addEventListener('wheel', closeMenu, { passive: true });

    // ------------------------------------------------------------------
    // Text mode: the .conf itself, checked by the engine as it is typed

    var text = {
        panel: $('#text-mode'), area: $('#text-area'), gutter: $('#text-gutter'), list: $('#text-problems'),
        status: $('#text-status'), apply: $('#text-apply'),
        original: '', version: '', timer: null, seq: 0, problems: { errors: [], warnings: [] }, lines: 0
    };

    function openText() {
        if (state.textMode) { return; }
        closeMenu();
        run(function () {
            return api('GET', '/editor/source/' + cfg.mapId).then(function (d) {
                text.original = d.text;
                text.version = d.version;
                text.area.value = d.text;
                state.textMode = true;
                $('#app').classList.add('text-mode');
                text.panel.hidden = false;
                text.problems = { errors: [], warnings: [] };
                renderGutter(true);
                updateToolbar();
                text.area.focus();
                text.area.setSelectionRange(0, 0);
                text.area.scrollTop = 0;
                text.area.scrollLeft = 0;
                validateText();
            });
        });
    }

    function closeText(force) {
        if (!state.textMode) { return; }
        if (!force && text.area.value !== text.original && !window.confirm(t('Discard the changes made to the text?'))) {
            return;
        }
        clearTimeout(text.timer);
        state.textMode = false;
        $('#app').classList.remove('text-mode');
        text.panel.hidden = true;
        updateToolbar();
        updateView();
    }

    function applyText() {
        if (text.area.value === text.original) {
            closeText(true);
            return;
        }
        var ok = false;
        run(function () {
            return api('POST', '/editor/source/' + cfg.mapId, { text: text.area.value, version: text.version }).then(function (d) {
                ok = true;
                closeText(true);
                applyRender(d);
                toast(t('Text saved. Ctrl+Z to undo.'));
            });
        }).then(function () {
            if (!ok && state.textMode) { text.area.focus(); }
        });
    }

    function validateText() {
        clearTimeout(text.timer);
        var seq = ++text.seq;
        text.status.textContent = t('Checking…');
        text.timer = setTimeout(function () {
            api('POST', '/editor/validate/' + cfg.mapId, { text: text.area.value }).then(function (d) {
                if (seq !== text.seq || !state.textMode) { return; }
                text.problems = { errors: d.errors || [], warnings: d.warnings || [] };
                renderProblems(d);
            }).catch(function (e) {
                if (seq === text.seq) { text.status.textContent = e.message; }
            });
        }, 500);
    }

    function renderProblems(d) {
        var p = text.problems;
        var all = p.errors.map(function (e) { return { kind: 'error', line: e.line, message: e.message }; })
            .concat(p.warnings.map(function (w) { return { kind: 'warning', line: w.line, message: w.message }; }));
        text.list.replaceChildren.apply(text.list, all.map(function (pb) {
            return h('li', { 'class': pb.kind }, [h('button', {
                type: 'button', disabled: pb.line == null,
                onclick: function () { goToLine(pb.line); }
            }, [h('span', { 'class': 'pb-line', text: pb.line == null ? '—' : t('Line :n', { n: pb.line }) }), ' ' + pb.message])]);
        }));
        if (p.errors.length) {
            text.status.textContent = t(':n error(s): the text cannot be saved as it is.', { n: p.errors.length });
        } else if (p.warnings.length) {
            text.status.textContent = t(':n warning(s). :nodes nodes, :links links.', { n: p.warnings.length, nodes: d.nodes, links: d.links });
        } else {
            text.status.textContent = t('No problem found. :nodes nodes, :links links.', { nodes: d.nodes, links: d.links });
        }
        text.status.className = p.errors.length ? 'error' : (p.warnings.length ? 'warning' : 'ok');
        text.apply.disabled = p.errors.length > 0;
        renderGutter(true);
    }

    /** Line numbers, with the lines that have a problem marked */
    function renderGutter(force) {
        var n = text.area.value.split('\n').length;
        if (!force && n === text.lines) { return; }
        text.lines = n;
        var marks = {};
        text.problems.warnings.forEach(function (w) { if (w.line) { marks[w.line] = 'warning'; } });
        text.problems.errors.forEach(function (e) { if (e.line) { marks[e.line] = 'error'; } });
        var frag = document.createDocumentFragment();
        for (var i = 1; i <= n; i++) {
            var d = document.createElement('div');
            d.textContent = i;
            if (marks[i]) { d.className = marks[i]; }
            frag.appendChild(d);
        }
        text.gutter.replaceChildren(frag);
        text.gutter.scrollTop = text.area.scrollTop;
    }

    function goToLine(line) {
        var lines = text.area.value.split('\n');
        var start = 0;
        for (var i = 0; i < line - 1 && i < lines.length; i++) { start += lines[i].length + 1; }
        var end = start + (lines[line - 1] || '').length;
        text.area.focus();
        text.area.setSelectionRange(start, end);
        var lh = parseFloat(getComputedStyle(text.area).lineHeight) || 18;
        text.area.scrollTop = Math.max(0, (line - 1) * lh - text.area.clientHeight / 3);
    }

    text.area.addEventListener('input', function () {
        renderGutter(false);
        validateText();
    });
    text.area.addEventListener('scroll', function () { text.gutter.scrollTop = text.area.scrollTop; });
    text.area.addEventListener('keydown', function (evt) {
        if (evt.key === 'Tab' && !evt.shiftKey && !evt.ctrlKey && !evt.metaKey && !evt.altKey) {
            // a tab character, as in the .conf files; Esc then Tab leaves the text
            text.area.setRangeText('\t', text.area.selectionStart, text.area.selectionEnd, 'end');
            text.area.dispatchEvent(new Event('input'));
            evt.preventDefault();
        } else if (evt.key === 'Escape') {
            text.apply.focus();
            evt.preventDefault();
        } else if ((evt.ctrlKey || evt.metaKey) && (evt.key === 'Enter' || evt.key === 's' || evt.key === 'S')) {
            if (!text.apply.disabled) { applyText(); }
            evt.preventDefault();
        }
    });
    text.apply.addEventListener('click', applyText);
    $('#text-cancel').addEventListener('click', function () { closeText(false); });
    window.addEventListener('beforeunload', function (evt) {
        if (state.textMode && text.area.value !== text.original) {
            evt.preventDefault();
            evt.returnValue = '';
        }
    });

    // ------------------------------------------------------------------
    // Toolbar and keyboard

    var commands = {
        'undo': function () { historyStep('undo'); },
        'redo': function () { historyStep('redo'); },
        'tool-select': function () { setTool('select'); },
        'tool-link': function () { setTool(state.tool === 'link' ? 'select' : 'link'); },
        'align-left': function () { align('left'); },
        'align-hcenter': function () { align('hcenter'); },
        'align-right': function () { align('right'); },
        'align-top': function () { align('top'); },
        'align-vcenter': function () { align('vcenter'); },
        'align-bottom': function () { align('bottom'); },
        'distribute-h': function () { align('distribute-h'); },
        'distribute-v': function () { align('distribute-v'); },
        'group': group,
        'ungroup': ungroup,
        'lock': toggleLock,
        'front': function () { zorder('front'); },
        'back': function () { zorder('back'); },
        'autoroute': autoRoute,
        'untangle': untangle,
        'find': openFind,
        'copy': function () { copySelection(false); },
        'cut': function () { copySelection(true); },
        'paste': function () { paste(null); },
        'spread': function () { spreadApart(10); },
        'zone': function () { if (state.placing) { stopPlacing(); } else { startPlacing(); } },
        'layers': toggleLayersPanel,
        'text-mode': function () { if (state.textMode) { closeText(); } else { openText(); } },
        'snap': function () { state.snap = !state.snap; updateToolbar(); },
        'zoom-in': function () { zoomAt(1.25); },
        'zoom-out': function () { zoomAt(0.8); },
        'zoom-reset': zoomReset,
        'zoom-fit': zoomFit
    };

    document.addEventListener('click', function (evt) {
        var b = evt.target.closest && evt.target.closest('.toolbar button[data-cmd], #appbar button[data-cmd]');
        if (b && state.textMode && b.dataset.cmd !== 'text-mode') {
            return;
        }
        if (b && commands[b.dataset.cmd]) {
            commands[b.dataset.cmd]();
        }
    });

    function nudge(dx, dy) {
        var objects = selectedObjects();
        if (!objects.length) { return; }
        var n = state.nudge || (state.nudge = { moved: noMove() });
        objects.forEach(function (o) {
            if (o.kind === 'node') { n.moved.nodes[o.item.name] = true; }
            else if (o.kind === 'zone') { if (n.moved.zones.indexOf(o.item.name) < 0) { n.moved.zones.push(o.item.name); } }
            else if (n.moved.furniture.indexOf(o.key) < 0) { n.moved.furniture.push(o.key); }
        });
        n.moved.dx += dx;
        n.moved.dy += dy;
        previewMove(n.moved);
        clearTimeout(n.timer);
        // a burst of key presses is saved as one change
        n.timer = setTimeout(function () {
            state.nudge = null;
            send([moveOp(n.moved)]);
        }, 400);
    }

    /**
     * Esc leaves what is going on, one thing at a time: a drag, a menu or
     * panel, a field being typed in (its change is dropped), the link tool,
     * then the selection. Dialogs close themselves; text mode has its own.
     */
    function escape(target) {
        if (cancelDrag()) { return true; }
        if (stopPlacing()) { return true; }
        // the interface search boxes handle Escape themselves
        if (target.closest && target.closest('.ds-search')) { return false; }
        if (menuOpen()) { closeMenu(); return true; }
        var popover = null;
        try { popover = $(':popover-open'); } catch (e) { /* no popover support */ }
        if (popover) { popover.hidePopover(); return true; }
        if (!$('#layers-panel').hidden) {
            toggleLayersPanel(false);
            $('[data-cmd="layers"]').focus();
            return true;
        }
        var open = $$('#app details[open]').filter(function (d) { return d.contains(target) || d.closest('#toolbar'); });
        if (open.length) {
            open.forEach(function (d) { d.open = false; });
            return true;
        }
        if (target.closest && target.closest('#inspector') && /^(INPUT|SELECT|TEXTAREA)$/.test(target.tagName)) {
            if (target.tagName === 'SELECT') {
                Array.prototype.forEach.call(target.options, function (o) { o.selected = o.defaultSelected; });
            } else {
                target.value = target.defaultValue;
            }
            target.blur();
            return true;
        }
        if (state.tool === 'link' || state.linkFrom) { setTool('select'); return true; }
        if (state.selection.size) {
            state.selection.clear();
            refresh();
            return true;
        }
        return false;
    }

    document.addEventListener('keydown', function (evt) {
        if (evt.key !== 'Escape' || state.textMode || $('dialog[open]')) {
            return;
        }
        if (escape(evt.target)) {
            evt.preventDefault();
            evt.stopPropagation();
        }
    }, true);

    document.addEventListener('keydown', function (evt) {
        var tgt = evt.target;
        if (state.textMode || menuOpen() || (tgt.closest && tgt.closest('#layers-panel'))) {
            return;
        }
        if (tgt.tagName === 'INPUT' || tgt.tagName === 'SELECT' || tgt.tagName === 'TEXTAREA' || $('dialog[open]')) {
            return;
        }
        var ctrl = evt.ctrlKey || evt.metaKey, k = evt.key;

        if (k === 'ContextMenu' || (evt.shiftKey && k === 'F10')) { openMenuForSelection(); evt.preventDefault(); return; }
        if (ctrl && (k === 'c' || k === 'C')) { copySelection(false); evt.preventDefault(); return; }
        if (ctrl && (k === 'x' || k === 'X')) { copySelection(true); evt.preventDefault(); return; }
        if (ctrl && (k === 'v' || k === 'V')) { paste(state.mouse); evt.preventDefault(); return; }

        if (k === ' ') { state.spaceDown = true; evt.preventDefault(); return; }
        if (ctrl && (k === 'z' || k === 'Z')) { historyStep(evt.shiftKey ? 'redo' : 'undo'); evt.preventDefault(); return; }
        if (ctrl && (k === 'y' || k === 'Y')) { historyStep('redo'); evt.preventDefault(); return; }
        if (ctrl && (k === 'a' || k === 'A')) { selectAll(); evt.preventDefault(); return; }
        if (ctrl && (k === 'd' || k === 'D')) { duplicateSelection(); evt.preventDefault(); return; }
        if (ctrl && (k === 'g' || k === 'G')) { (evt.shiftKey ? ungroup : group)(); evt.preventDefault(); return; }
        if (ctrl && (k === 'l' || k === 'L')) { toggleLock(); evt.preventDefault(); return; }
        if (ctrl && (k === 'f' || k === 'F')) { openFind(); evt.preventDefault(); return; }
        if (ctrl && k === ']') { zorder('front'); evt.preventDefault(); return; }
        if (ctrl && k === '[') { zorder('back'); evt.preventDefault(); return; }
        if (ctrl) { return; }

        var step = evt.shiftKey ? 10 : 1;
        switch (k) {
            case 'Delete': case 'Backspace': deleteSelection(); break;
            case 'ArrowLeft': nudge(-step, 0); break;
            case 'ArrowRight': nudge(step, 0); break;
            case 'ArrowUp': nudge(0, -step); break;
            case 'ArrowDown': nudge(0, step); break;
            case 'l': case 'L': setTool('link'); break;
            case 'v': case 'V': setTool('select'); break;
            case 'g': case 'G': commands.snap(); break;
            case 'f': case 'F': zoomFit(); break;
            case '0': zoomReset(); break;
            case '+': case '=': zoomAt(1.25); break;
            case '-': zoomAt(0.8); break;
            default: return;
        }
        evt.preventDefault();
    });

    document.addEventListener('keyup', function (evt) {
        if (evt.key === ' ') { state.spaceDown = false; }
    });

    // Other people (or the classic editor) may change the map: reload when
    // the file changes and nothing is being dragged.
    setInterval(function () {
        if (state.drag || state.nudge || state.textMode || document.hidden) { return; }
        fetch('/map/' + cfg.mapId + '/version').then(function (r) { return r.ok ? r.text() : null; }).then(function (v) {
            if (v === null) { return; }
            if (lastVersion !== null && v !== lastVersion) { run(load); }
            lastVersion = v;
        }).catch(function () {});
    }, 10000);

    run(load);

    // for tests and debugging
    window.WMEditor = {
        state: state, send: send, load: load, autoRoute: autoRoute, untangle: untangle, align: align,
        spreadApart: spreadApart, copy: copySelection, paste: paste, openMenu: openMenu, setLayer: setLayer,
        openText: openText, closeText: closeText,
        // for the other editor scripts (editor-icons.js, editor-toolbars.js, editor-mapprops.js)
        t: t, h: h, api: api, run: run, toast: toast, commands: commands, refresh: refresh, updateView: updateView,
        selected: selected
    };
    document.dispatchEvent(new CustomEvent('wm-editor-ready'));
})();
