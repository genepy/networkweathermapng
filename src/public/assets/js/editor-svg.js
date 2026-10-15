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
        selection: new Set(),     // "node:NAME", "link:NAME" or "zone:NAME"
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
        var m = kind === 'node' ? state.nodes : kind === 'link' ? state.links : kind === 'zone' ? state.zones : null;
        return m ? m.get(name) : undefined;
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
        return Array.from(out);
    }

    // ------------------------------------------------------------------
    // View: zoom and pan

    function updateView() {
        var w = canvas.clientWidth / state.zoom;
        var hh = canvas.clientHeight / state.zoom;
        stage.setAttribute('viewBox', [state.view.x, state.view.y, w, hh].join(' '));
        $('#zoom-level').textContent = Math.round(state.zoom * 100) + '%';
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
            if (moved[link.a]) { pa = [pa[0] + moved.dx, pa[1] + moved.dy]; }
            if (moved[link.b]) { pb = [pb[0] + moved.dx, pb[1] + moved.dy]; }
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
            return (moved && moved[link.a] && moved[link.b]) ? [v[0] + moved.dx, v[1] + moved.dy] : v;
        });
        return [ends[0]].concat(vias, [ends[1]]);
    }

    function renderOverlay() {
        overlay.replaceChildren();
        var drag = state.drag;
        var moved = drag && (drag.type === 'nodes' || drag.type === 'zone') && drag.started ? drag.moved : null;

        // Links being dragged with their nodes
        if (moved) {
            state.links.forEach(function (l) {
                if (moved[l.a] || moved[l.b]) {
                    el('polyline', { 'class': 'ghost', points: linkPath(l, moved).join(' ') }, overlay);
                }
            });
        }

        renderOverlaps(moved);

        state.selection.forEach(function (k) {
            var p = parseKey(k);
            if (p.kind === 'node') {
                var n = state.nodes.get(p.name);
                if (!n) { return; }
                var dx = moved && moved[n.name] ? moved.dx : 0, dy = moved && moved[n.name] ? moved.dy : 0;
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
            } else {
                var l = state.links.get(p.name);
                if (l) {
                    el('polyline', { 'class': 'sel-link', points: (l.spine.length ? l.spine : linkPath(l)).join(' ') }, overlay);
                }
            }
        });

        var nodes = selected('node'), links = selected('link');

        // One link: its VIAs, the points to add one, and its anchors
        if (links.length === 1 && nodes.length === 0 && !moved) {
            renderLinkHandles(links[0]);
        }

        // One node: a handle to pull a new link from
        if (nodes.length === 1 && links.length === 0 && !moved && state.tool === 'select') {
            var n1 = nodes[0];
            el('circle', { 'class': 'connector', cx: n1.box[2] + 8 / state.zoom, cy: (n1.box[1] + n1.box[3]) / 2, r: 5 / state.zoom, 'data-handle': 'connector' }, overlay)
                .appendChild(el('title', {})).textContent = t('Drag to another node to link them');
        }

        if (state.linkFrom) {
            var s = state.nodes.get(state.linkFrom);
            if (s) {
                el('rect', { 'class': 'link-source', x: s.box[0] - 4, y: s.box[1] - 4, width: s.box[2] - s.box[0] + 8, height: s.box[3] - s.box[1] + 8 }, overlay);
            }
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
            el('polyline', { 'class': 'ghost', points: drag.points.join(' ') }, overlay);
        }

        if (drag && drag.type === 'resize' && drag.started) {
            el('rect', { 'class': 'lasso', x: 0, y: 0, width: drag.w, height: drag.h }, overlay);
        }

        // Two nodes or more: a box with handles to scale their layout
        if (nodes.length >= 2 && state.tool === 'select' && !moved) {
            renderScaleBox(nodes);
        }
    }

    /** A selected zone: its outline and, alone, a handle to resize it */
    function renderZoneBox(z, drag) {
        if (!z) { return; }
        var x = z.x, y = z.y, w = z.width, hh = z.height;
        if (drag && drag.type === 'zone' && drag.started && drag.zones.indexOf(z.name) >= 0) {
            x += drag.moved.dx; y += drag.moved.dy;
        }
        if (drag && drag.type === 'zone-size' && drag.started && drag.zone === z.name) {
            w = drag.w; hh = drag.h;
        }
        el('rect', { 'class': 'sel-box zone', x: x - 3, y: y - 3, width: w + 6, height: hh + 6 }, overlay);
        if (state.selection.size === 1 && !(drag && drag.type === 'zone' && drag.started)) {
            var r = 5 / state.zoom;
            el('rect', { 'class': 'scale-handle', x: x + w - r, y: y + hh - r, width: 2 * r, height: 2 * r, 'data-handle': 'zone-size' }, overlay)
                .appendChild(el('title', {})).textContent = t('Drag to resize the zone');
        }
    }

    function renderLinkHandles(link) {
        var r = 5 / state.zoom;
        var pts = linkPath(link);

        // a dot in the middle of each segment adds a VIA there
        for (var i = 0; i < pts.length - 1; i++) {
            el('circle', {
                'class': 'via-add', cx: (pts[i][0] + pts[i + 1][0]) / 2, cy: (pts[i][1] + pts[i + 1][1]) / 2,
                r: r * 0.9, 'data-handle': 'via-add', 'data-index': i
            }, overlay).appendChild(el('title', {})).textContent = t('Drag to bend the link here');
        }

        link.via.forEach(function (v, idx) {
            el('circle', { 'class': 'via', cx: v[0], cy: v[1], r: r, 'data-handle': 'via', 'data-index': idx }, overlay)
                .appendChild(el('title', {})).textContent = t('Drag to move, double-click to remove');
        });

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
        var nodes = selected('node').length, any = state.selection.size;
        $$('[data-needs]').forEach(function (e) {
            var need = e.dataset.needs, ok = true;
            if (need === 'nodes1') { ok = nodes >= 1; }
            if (need === 'nodes2') { ok = nodes >= 2; }
            if (need === 'nodes3') { ok = nodes >= 3; }
            if (need === 'any') { ok = any > 0; }
            if (e.tagName === 'BUTTON') { e.disabled = !ok; } else { e.classList.toggle('unavailable', !ok); }
        });
        $('[data-cmd="tool-select"]').classList.toggle('active', state.tool === 'select');
        $('[data-cmd="tool-link"]').classList.toggle('active', state.tool === 'link');
        $('[data-cmd="snap"]').classList.toggle('active', state.snap);
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
        if (g && g.dataset.kind === 'zone' && evt.target.classList.contains('wm-zone-label')) {
            return { kind: 'zone', name: g.dataset.name };
        }
        // a zone is picked by its edge, so its inside stays free for selecting an area
        var z = zoneEdgeAt(toMap(evt));
        return z ? { kind: 'zone', name: z.name } : null;
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

    /** Unlocked nodes whose centre is inside the zone */
    function nodesIn(z) {
        var out = [];
        state.nodes.forEach(function (n) {
            if (!n.locked && n.x >= z.x && n.x <= z.x + z.width && n.y >= z.y && n.y <= z.y + z.height) { out.push(n); }
        });
        return out;
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

        if (!item) {
            startDrag(evt, { type: 'lasso', start: p, now: p, add: evt.shiftKey || evt.ctrlKey || evt.metaKey });
            return;
        }

        var k = key(item.kind, item.name);
        var keys = withGroups([k], evt.altKey);

        if (evt.shiftKey || evt.ctrlKey || evt.metaKey) {
            var removing = state.selection.has(k);
            keys.forEach(function (kk) { if (removing) { state.selection.delete(kk); } else { state.selection.add(kk); } });
            refresh();
            if (removing) {
                return;
            }
        } else if (!state.selection.has(k)) {
            state.selection = new Set(keys);
            refresh();
        }

        if (item.kind === 'node') {
            var movable = selected('node').filter(function (n) { return !n.locked; });
            if (!movable.length) {
                if (state.nodes.get(item.name).locked) { toast(t('This node is locked. Unlock it to move it.')); }
                return;
            }
            var moved = { dx: 0, dy: 0 };
            movable.forEach(function (n) { moved[n.name] = true; });
            var lead = state.nodes.get(item.name);
            startDrag(evt, { type: 'nodes', start: p, moved: moved, lead: lead.locked ? movable[0] : lead, clicked: k, multi: state.selection.size > 1 });
        }

        if (item.kind === 'zone') {
            // a zone takes its nodes along, unless Alt is held
            var zones = selected('zone'), zmoved = { dx: 0, dy: 0 };
            if (!evt.altKey) {
                zones.forEach(function (z) { nodesIn(z).forEach(function (n) { zmoved[n.name] = true; }); });
            }
            startDrag(evt, { type: 'zone', start: p, moved: zmoved, lead: state.zones.get(item.name), zones: zones.map(function (z) { return z.name; }), clicked: k, multi: state.selection.size > 1 });
        }
    });

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
        var link = selected('link')[0];
        var idx = +evt.target.dataset.index;

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

            case 'nodes':
                var nx = snap(d.lead.x + p.x - d.start.x), ny = snap(d.lead.y + p.y - d.start.y);
                d.moved.dx = nx - d.lead.x;
                d.moved.dy = ny - d.lead.y;
                moveNodeGroups(d.moved);
                break;

            case 'zone':
                d.moved.dx = snap(d.lead.x + p.x - d.start.x) - d.lead.x;
                d.moved.dy = snap(d.lead.y + p.y - d.start.y) - d.lead.y;
                moveNodeGroups(d.moved);
                moveZoneGroups(d.zones, d.moved);
                break;

            case 'zone-size':
                var zs = state.zones.get(d.zone);
                d.w = Math.max(10, snap(zs.x + zs.width + p.x - d.start.x) - zs.x);
                d.h = Math.max(10, snap(zs.y + zs.height + p.y - d.start.y) - zs.y);
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
    });
    stage.addEventListener('pointerleave', function () { state.mouse = null; });

    function moveNodeGroups(moved) {
        $$('#map g[data-kind="node"]').forEach(function (g) {
            if (moved[g.dataset.name]) {
                g.setAttribute('transform', 'translate(' + moved.dx + ' ' + moved.dy + ')');
            }
        });
        $$('#map g[data-kind="link"]').forEach(function (g) {
            var l = state.links.get(g.dataset.name);
            g.classList.toggle('dimmed', !!(l && (moved[l.a] || moved[l.b]) && (moved.dx || moved.dy)));
        });
    }

    function moveZoneGroups(names, moved) {
        $$('#map g[data-kind="zone"]').forEach(function (g) {
            if (names.indexOf(g.dataset.name) >= 0) {
                g.setAttribute('transform', 'translate(' + moved.dx + ' ' + moved.dy + ')');
            }
        });
    }

    stage.addEventListener('pointerup', endDrag);
    stage.addEventListener('pointercancel', function (evt) { endDrag(evt, true); });

    function endDrag(evt, cancelled) {
        var d = state.drag;
        if (!d || d.pointer !== evt.pointerId) {
            return;
        }
        state.drag = null;
        stage.classList.remove('panning');
        if (cancelled) {
            load();
            return;
        }

        switch (d.type) {
            case 'nodes':
                if (!d.started || (!d.moved.dx && !d.moved.dy)) {
                    // a plain click in a multi-selection keeps only what was clicked
                    if (!d.started && d.multi && !(evt.shiftKey || evt.ctrlKey || evt.metaKey)) {
                        state.selection = new Set(withGroups([d.clicked], evt.altKey));
                    }
                    moveNodeGroups({ dx: 0, dy: 0 });
                    refresh();
                    return;
                }
                send([moveOp(d.moved)]);
                return;

            case 'zone':
                if (!d.started || (!d.moved.dx && !d.moved.dy)) {
                    if (!d.started && d.multi && !(evt.shiftKey || evt.ctrlKey || evt.metaKey)) {
                        state.selection = new Set([d.clicked]);
                    }
                    moveNodeGroups({ dx: 0, dy: 0 });
                    moveZoneGroups(d.zones, { dx: 0, dy: 0 });
                    refresh();
                    return;
                }
                send([moveOp(d.moved, d.zones)]);
                return;

            case 'zone-size':
                var zz = state.zones.get(d.zone);
                if (!d.started || (d.w === zz.width && d.h === zz.height)) {
                    renderOverlay();
                    return;
                }
                send([{ op: 'set_zone', names: [d.zone], props: { width: d.w, height: d.h } }]);
                return;

            case 'lasso':
                if (!d.started) {
                    if (!d.add) {
                        state.selection.clear();
                    }
                    refresh();
                    return;
                }
                var r = rectOf(d.start, toMap(evt));
                var keys = d.add ? Array.from(state.selection) : [];
                state.nodes.forEach(function (n) {
                    if (n.x >= r[0] && n.x <= r[2] && n.y >= r[1] && n.y <= r[3]) { keys.push(key('node', n.name)); }
                });
                state.links.forEach(function (l) {
                    var m = midpoint(l);
                    if (m && m[0] >= r[0] && m[0] <= r[2] && m[1] >= r[1] && m[1] <= r[3]) { keys.push(key('link', l.name)); }
                });
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

    function moveOp(moved, zoneNames) {
        var nodes = {}, vias = {}, zones = {};
        (zoneNames || []).forEach(function (name) {
            var z = state.zones.get(name);
            zones[name] = [z.x + moved.dx, z.y + moved.dy];
        });
        Object.keys(moved).forEach(function (name) {
            if (name === 'dx' || name === 'dy') { return; }
            var n = state.nodes.get(name);
            nodes[name] = [n.x + moved.dx, n.y + moved.dy];
        });
        // links whose two ends move keep their shape
        state.links.forEach(function (l) {
            if (l.via.length && moved[l.a] && moved[l.b]) {
                vias[l.name] = l.via.map(function (v) { return [v[0] + moved.dx, v[1] + moved.dy]; });
            }
        });
        return { op: 'move', nodes: nodes, vias: vias, zones: zones };
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
        var item = itemAt(evt);
        if (item && (item.kind === 'node' || item.kind === 'zone')) {
            var input = $('#inspector input[name="label"]');
            if (input) { input.focus(); input.select(); }
        }
        if (!item && !handle && state.tool === 'select') {
            var p = toMap(evt);
            addNode({ x: snap(p.x), y: snap(p.y) });
        }
    });

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
            op.x = snap(c.x - 100); op.y = snap(c.y - 60); op.width = 200; op.height = 120;
        }
        op.x = Math.max(0, op.x); op.y = Math.max(0, op.y);
        send([op], function (r) { return (r.created && r.created.zones || []).map(function (n) { return key('zone', n); }); });
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

    function align(how) {
        var nodes = selected('node').filter(function (n) { return !n.locked; });
        if (nodes.length < 2) {
            return;
        }
        var minX = Math.min.apply(null, nodes.map(function (n) { return n.box[0]; }));
        var maxX = Math.max.apply(null, nodes.map(function (n) { return n.box[2]; }));
        var minY = Math.min.apply(null, nodes.map(function (n) { return n.box[1]; }));
        var maxY = Math.max.apply(null, nodes.map(function (n) { return n.box[3]; }));
        var pos = {};

        nodes.forEach(function (n) {
            var x = n.x, y = n.y;
            if (how === 'left') { x += minX - n.box[0]; }
            if (how === 'right') { x += maxX - n.box[2]; }
            if (how === 'hcenter') { x = Math.round((minX + maxX) / 2); }
            if (how === 'top') { y += minY - n.box[1]; }
            if (how === 'bottom') { y += maxY - n.box[3]; }
            if (how === 'vcenter') { y = Math.round((minY + maxY) / 2); }
            pos[n.name] = [x, y];
        });

        if (how === 'distribute-h' || how === 'distribute-v') {
            var axis = how === 'distribute-h' ? 'x' : 'y';
            var sorted = nodes.slice().sort(function (a, b) { return a[axis] - b[axis]; });
            var first = sorted[0][axis], last = sorted[sorted.length - 1][axis];
            var step = (last - first) / (sorted.length - 1);
            sorted.forEach(function (n, i) {
                var v = Math.round(first + step * i);
                pos[n.name] = axis === 'x' ? [v, n.y] : [n.x, v];
            });
        }

        send([{ op: 'move', nodes: pos }]);
    }

    function itemsByKind() {
        return { node: selected('node'), link: selected('link') };
    }

    function hintOps(keyName, value, filter) {
        var by = itemsByKind(), ops = [];
        ['node', 'link'].forEach(function (kind) {
            var names = by[kind].filter(filter || function () { return true; }).map(function (i) { return i.name; });
            if (names.length) {
                ops.push({ op: 'hint', kind: kind, names: names, key: keyName, value: value });
            }
        });
        return ops;
    }

    function group() {
        if (state.selection.size < 2) {
            toast(t('Select at least two items to group them.'));
            return;
        }
        send(hintOps('editor_group', 'g' + Date.now().toString(36)));
    }

    function ungroup() {
        send(hintOps('editor_group', null));
    }

    function toggleLock() {
        var by = itemsByKind(), all = by.node.concat(by.link);
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
        state.selection = new Set(keys);
        refresh();
    }

    function setTool(tool) {
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
        var nodes = selected('node'), links = selected('link'), zones = selected('zone');
        var parts = [];

        if (!nodes.length && !links.length && !zones.length) {
            parts = mapPanel();
        } else {
            if (nodes.length) { parts = parts.concat(nodePanel(nodes)); }
            if (links.length) { parts = parts.concat(linkPanel(links)); }
            if (zones.length) { parts = parts.concat(zonePanel(zones)); }
            parts.push(h('div', { 'class': 'actions' }, [
                h('button', { type: 'button', text: t('Duplicate'), title: 'Ctrl+D', onclick: duplicateSelection, disabled: !nodes.length }),
                h('button', { type: 'button', 'class': 'danger', text: t('Delete'), title: t('Delete key'), onclick: deleteSelection })
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
            h('p', { 'class': 'help', text: t(':w × :h px, :nodes nodes, :links links.', { w: m.width, h: m.height, nodes: state.model.nodes.length, links: state.model.links.length }) }),
            h('h3', { text: t('Shortcuts') }),
            help([
                [t('Click, Shift+click'), t('select, add to selection')],
                [t('Drag on empty space'), t('select an area')],
                [t('Alt+click'), t('one item of a group')],
                [t('Double-click empty space'), t('new node')],
                ['Ctrl+A / Esc', t('select all / nothing')],
                [t('Arrows, Shift+arrows'), t('move by 1 or 10 px')],
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

        if (nodes.some(function (n) { return n.group; })) {
            out.push(h('p', { 'class': 'help', text: t('Grouped: clicking one selects the whole group. Alt+click picks one.') }));
        }
        return out;
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
            h('p', { 'class': 'help', text: t('Zones are drawn under links and nodes. Dragging a zone by its edge moves the nodes inside it too; hold Alt to move the zone alone.') })
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
                h('p', { 'class': 'help', text: t('Drag the red points on the link to bend it. Double-click a point to remove it.') }),
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

    function interfacePicker(links) {
        var box = h('details', {}, [h('summary', { text: t('Pick an interface from a data source') })]);
        var src = h('select', {}, [h('option', { value: '', text: t('Loading…') })]);
        var host = h('select', { disabled: true }, [h('option', { value: '', text: t('Host') })]);
        var iface = h('select', { disabled: true }, [h('option', { value: '', text: t('Interface') })]);
        var items = [];

        function fill(sel, list, value, label, placeholder) {
            sel.replaceChildren(h('option', { value: '', text: placeholder }));
            list.forEach(function (i) { sel.appendChild(h('option', { value: i[value], text: i[label] || i[value] })); });
        }
        function get(url) {
            return api('GET', url).then(function (d) { return d.data || []; });
        }

        box.addEventListener('toggle', function once() {
            box.removeEventListener('toggle', once);
            get('/api/data-sources').then(function (list) {
                fill(src, list, 'id', 'name', list.length ? t('Data source') : t('No data source configured'));
            }).catch(function (e) { fill(src, [], 'id', 'name', e.message); });
        });
        src.addEventListener('change', function () {
            host.disabled = iface.disabled = true;
            if (!src.value) { return; }
            get('/api/data-sources/' + encodeURIComponent(src.value) + '/hosts').then(function (list) {
                fill(host, list, 'hostid', 'name', t('Host'));
                host.disabled = false;
            }).catch(function (e) { fill(host, [], 'hostid', 'name', e.message); });
        });
        host.addEventListener('change', function () {
            iface.disabled = true;
            if (!host.value) { return; }
            get('/api/data-sources/' + encodeURIComponent(src.value) + '/hosts/' + encodeURIComponent(host.value) + '/bandwidths').then(function (list) {
                items = list;
                fill(iface, list, 'interfaceId', 'label', list.length ? t('Interface') : t('No interface with traffic data'));
                iface.disabled = !list.length;
            }).catch(function (e) { fill(iface, [], 'interfaceId', 'label', e.message); });
        });
        iface.addEventListener('change', function () {
            var it = items.find(function (i) { return String(i.interfaceId) === iface.value; });
            if (!it) { return; }
            var selection = { sourceId: +src.value, hostId: String(host.value), interfaceId: String(it.interfaceId), inKey: String(it.rxKey || ''), outKey: String(it.txKey || '') };
            var props = {
                target: it.target || ('zabbix:hostid:' + selection.hostId + ':key:' + selection.inKey + ':' + selection.outKey),
                datasource: selection
            };
            if (it.bandwidth) { props.bandwidth = it.bandwidth; }
            send([{ op: 'set_link', names: links.map(function (l) { return l.name; }), props: props }]);
        });

        box.appendChild(row(t('Source'), src));
        box.appendChild(row(t('Host'), host));
        box.appendChild(row(t('Interface'), iface));
        return box;
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
            if (!n || (moved && moved[name])) { return; }
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
        var nodes = selected('node'), links = selected('link'), any = nodes.length + links.length + selected('zone').length;
        var all = nodes.concat(links);
        var locked = all.length && all.every(function (i) { return i.locked; });
        var grouped = all.some(function (i) { return i.group; });
        var entries = [
            { label: t('Cut'), kbd: 'Ctrl+X', run: function () { copySelection(true); }, off: !nodes.length },
            { label: t('Copy'), kbd: 'Ctrl+C', run: function () { copySelection(false); }, off: !nodes.length },
            { label: t('Paste'), kbd: 'Ctrl+V', run: function () { paste(at); }, off: !hasClip() },
            { label: t('Duplicate'), kbd: 'Ctrl+D', run: duplicateSelection, off: !nodes.length },
            { label: t('Delete'), kbd: t('Del'), run: deleteSelection, off: !any, danger: true }
        ];
        if (nodes.length + links.length) {
            entries.push('-',
                { label: t('Group'), kbd: 'Ctrl+G', run: group, off: any < 2 },
                { label: t('Ungroup'), kbd: 'Ctrl+Shift+G', run: ungroup, off: !grouped },
                { label: locked ? t('Unlock') : t('Lock'), kbd: 'Ctrl+L', run: toggleLock },
                { label: t('Bring to front'), kbd: 'Ctrl+]', run: function () { zorder('front'); } },
                { label: t('Send to back'), kbd: 'Ctrl+[', run: function () { zorder('back'); } });
        }
        if (nodes.length >= 2) {
            entries.push('-', {
                row: t('Align'), items: [
                    ['⎸←', t('Align left edges'), 'left'], ['↕', t('Align centres vertically'), 'hcenter'], ['→⎹', t('Align right edges'), 'right'],
                    ['⤒', t('Align top edges'), 'top'], ['↔', t('Align centres horizontally'), 'vcenter'], ['⤓', t('Align bottom edges'), 'bottom']
                ].concat(nodes.length >= 3 ? [['⋯', t('Space evenly, left to right'), 'distribute-h'], ['⋮', t('Space evenly, top to bottom'), 'distribute-v']] : [])
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
        'zone': function () { addZone(null); },
        'layers': toggleLayersPanel,
        'text-mode': function () { if (state.textMode) { closeText(); } else { openText(); } },
        'snap': function () { state.snap = !state.snap; updateToolbar(); },
        'zoom-in': function () { zoomAt(1.25); },
        'zoom-out': function () { zoomAt(0.8); },
        'zoom-reset': zoomReset,
        'zoom-fit': zoomFit
    };

    $('#toolbar').addEventListener('click', function (evt) {
        var b = evt.target.closest('button[data-cmd]');
        if (b && state.textMode && b.dataset.cmd !== 'text-mode') {
            return;
        }
        if (b && commands[b.dataset.cmd]) {
            commands[b.dataset.cmd]();
        }
    });

    function nudge(dx, dy) {
        var nodes = selected('node').filter(function (n) { return !n.locked; });
        var zones = selected('zone').map(function (z) { return z.name; });
        if (!nodes.length && !zones.length) { return; }
        var n = state.nudge || (state.nudge = { moved: { dx: 0, dy: 0 }, zones: zones });
        nodes.forEach(function (node) { n.moved[node.name] = true; });
        n.moved.dx += dx;
        n.moved.dy += dy;
        moveNodeGroups(n.moved);
        moveZoneGroups(n.zones, n.moved);
        clearTimeout(n.timer);
        // a burst of key presses is saved as one change
        n.timer = setTimeout(function () {
            state.nudge = null;
            send([moveOp(n.moved, n.zones)]);
        }, 400);
    }

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
            case 'Escape':
                if (state.tool === 'link') { setTool('select'); } else { state.selection.clear(); refresh(); }
                break;
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
        openText: openText, closeText: closeText
    };
})();
