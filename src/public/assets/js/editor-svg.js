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
        selection: new Set(),     // "node:NAME" or "link:NAME"
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
        first: true
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
                    throw new Error((data && data.error) || ('The server answered ' + r.status));
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
            throw new Error('The map could not be drawn');
        }
        var children = Array.prototype.map.call(root.childNodes, function (n) { return document.importNode(n, true); });
        mapLayer.replaceChildren.apply(mapLayer, children);

        state.model = d.model;
        if (d.version) { lastVersion = d.version; }
        state.history = d.history || { undo: 0, redo: 0 };
        state.nodes = new Map(d.model.nodes.map(function (n) { return [n.name, n]; }));
        state.links = new Map(d.model.links.map(function (l) { return [l.name, l]; }));

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
            if (!(p.kind === 'node' ? state.nodes : state.links).has(p.name)) {
                state.selection.delete(key);
            }
        });
    }

    // ------------------------------------------------------------------
    // Helpers

    function key(kind, name) { return kind + ':' + name; }
    function parseKey(k) { var i = k.indexOf(':'); return { kind: k.slice(0, i), name: k.slice(i + 1) }; }

    function selected(kind) {
        var out = [];
        state.selection.forEach(function (k) {
            var p = parseKey(k);
            if (p.kind === kind) {
                out.push(kind === 'node' ? state.nodes.get(p.name) : state.links.get(p.name));
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
        var t = $('#toast');
        t.textContent = msg;
        t.className = isError ? 'error' : '';
        t.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.hidden = true; }, isError ? 6000 : 2500);
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
            var item = p.kind === 'node' ? state.nodes.get(p.name) : state.links.get(p.name);
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
        var moved = drag && drag.type === 'nodes' && drag.started ? drag.moved : null;

        // Links being dragged with their nodes
        if (moved) {
            state.links.forEach(function (l) {
                if (moved[l.a] || moved[l.b]) {
                    el('polyline', { 'class': 'ghost', points: linkPath(l, moved).join(' ') }, overlay);
                }
            });
        }

        state.selection.forEach(function (k) {
            var p = parseKey(k);
            if (p.kind === 'node') {
                var n = state.nodes.get(p.name);
                if (!n) { return; }
                var dx = moved && moved[n.name] ? moved.dx : 0, dy = moved && moved[n.name] ? moved.dy : 0;
                el('rect', {
                    'class': 'sel-box' + (n.group ? ' grouped' : ''),
                    x: n.box[0] - 3 + dx, y: n.box[1] - 3 + dy,
                    width: n.box[2] - n.box[0] + 6, height: n.box[3] - n.box[1] + 6
                }, overlay);
                if (n.locked) {
                    var t = el('text', { 'class': 'lock-mark', x: n.box[2] + 2 + dx, y: n.box[1] + dy }, overlay);
                    t.textContent = '🔒';
                }
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
                .appendChild(el('title', {})).textContent = 'Drag to another node to link them';
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
    }

    function renderLinkHandles(link) {
        var r = 5 / state.zoom;
        var pts = linkPath(link);

        // a dot in the middle of each segment adds a VIA there
        for (var i = 0; i < pts.length - 1; i++) {
            el('circle', {
                'class': 'via-add', cx: (pts[i][0] + pts[i + 1][0]) / 2, cy: (pts[i][1] + pts[i + 1][1]) / 2,
                r: r * 0.9, 'data-handle': 'via-add', 'data-index': i
            }, overlay).appendChild(el('title', {})).textContent = 'Drag to bend the link here';
        }

        link.via.forEach(function (v, idx) {
            el('circle', { 'class': 'via', cx: v[0], cy: v[1], r: r, 'data-handle': 'via', 'data-index': idx }, overlay)
                .appendChild(el('title', {})).textContent = 'Drag to move, double-click to remove';
        });

        [['a', link.a, link.a_offset], ['b', link.b, link.b_offset]].forEach(function (end) {
            var node = state.nodes.get(end[1]);
            ANCHORS.forEach(function (a) {
                var p = anchorPoint(node, a);
                var current = (end[2] || 'C').toUpperCase().replace(/\d+$/, '') === a;
                el('rect', {
                    'class': 'anchor' + (current ? ' current' : ''), x: p[0] - r * 0.7, y: p[1] - r * 0.7,
                    width: r * 1.4, height: r * 1.4, 'data-handle': 'anchor', 'data-end': end[0], 'data-anchor': a
                }, overlay).appendChild(el('title', {})).textContent = 'Attach this end ' + (a === 'C' ? 'at the centre' : 'to ' + a);
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
            if (need === 'nodes2') { ok = nodes >= 2; }
            if (need === 'nodes3') { ok = nodes >= 3; }
            if (need === 'any') { ok = any > 0; }
            if (e.tagName === 'BUTTON') { e.disabled = !ok; } else { e.classList.toggle('unavailable', !ok); }
        });
        $('[data-cmd="tool-select"]').classList.toggle('active', state.tool === 'select');
        $('[data-cmd="tool-link"]').classList.toggle('active', state.tool === 'link');
        $('[data-cmd="snap"]').classList.toggle('active', state.snap);
        stage.classList.toggle('tool-link', state.tool === 'link');
        stage.classList.toggle('snap', state.snap);
    }

    // ------------------------------------------------------------------
    // Pointer

    function itemAt(evt) {
        var g = evt.target.closest && evt.target.closest('#map g[data-kind]');
        if (!g || (g.dataset.kind !== 'node' && g.dataset.kind !== 'link')) {
            return null;
        }
        return { kind: g.dataset.kind, name: g.dataset.name };
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
                if (state.nodes.get(item.name).locked) { toast('This node is locked. Unlock it to move it.'); }
                return;
            }
            var moved = { dx: 0, dy: 0 };
            movable.forEach(function (n) { moved[n.name] = true; });
            var lead = state.nodes.get(item.name);
            startDrag(evt, { type: 'nodes', start: p, moved: moved, lead: lead.locked ? movable[0] : lead, clicked: k, multi: state.selection.size > 1 });
        }
    });

    function handleDown(evt, handle, p) {
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
        }
        renderOverlay();
    });

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
        }
    }

    function midpoint(link) {
        var s = link.spine;
        return s && s.length ? s[Math.floor(s.length / 2)] : null;
    }

    function moveOp(moved) {
        var nodes = {}, vias = {};
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
        return { op: 'move', nodes: nodes, vias: vias };
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
        if (item && item.kind === 'node') {
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
            toast('Locked items are not deleted. Unlock them first.', true);
        }
        nodes = nodes.filter(function (n) { return !n.locked; });
        links = links.filter(function (l) { return !l.locked; });
        if (!nodes.length && !links.length) {
            return;
        }
        send([{ op: 'delete', nodes: nodes.map(function (n) { return n.name; }), links: links.map(function (l) { return l.name; }) }], []);
    }

    function duplicateSelection() {
        var nodes = selected('node');
        if (!nodes.length) {
            return;
        }
        send([{ op: 'clone', nodes: nodes.map(function (n) { return n.name; }), dx: 20, dy: 20 }], function (r) {
            var c = r.created || {};
            return (c.nodes || []).map(function (n) { return key('node', n); }).concat((c.links || []).map(function (n) { return key('link', n); }));
        });
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
            toast('Select at least two items to group them.');
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
            toast('Click a node, then a second one, to link them. Esc to stop.');
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
                var t = qq / pp;
                if (pp < 0) { if (t > t1) { return false; } if (t > t0) { t0 = t; } } else { if (t < t0) { return false; } if (t < t1) { t1 = t; } }
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
            toast('Nothing to reroute: no link crosses a node.');
            return;
        }
        send(ops).then(function () { toast(ops.length + ' link(s) rerouted. Ctrl+Z to undo.'); });
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
                var t = (target - lens[j - 1]) / ((lens[j] - lens[j - 1]) || 1);
                return [spine[j - 1][0] + (spine[j][0] - spine[j - 1][0]) * t, spine[j - 1][1] + (spine[j][1] - spine[j - 1][1]) * t];
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
            toast('No overlapping labels found.');
            return;
        }
        send(ops).then(function () { toast(ops.length + ' link label(s) moved. Ctrl+Z to undo.'); });
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
            count.textContent = m === null ? 'Invalid regular expression' : m.length + ' item(s) match';
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
            toast(m.length + ' item(s) selected');
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
            input = h('select', { name: name }, (c.mixed ? [h('option', { value: '', text: '(mixed)' })] : []).concat(opts.options.map(function (o) {
                var val = Array.isArray(o) ? o[0] : o, text = Array.isArray(o) ? o[1] : o;
                return h('option', { value: val, text: text, selected: !c.mixed && String(c.value) === String(val) });
            })));
        } else {
            input = h('input', {
                name: name, type: opts.type || 'text', value: c.value, placeholder: c.mixed ? '(mixed)' : (opts.placeholder || ''),
                'class': c.mixed ? 'mixed' : '', list: opts.list || null
            });
        }
        input.addEventListener('change', function () {
            if (input.value === '' && c.mixed) { return; }
            var props = {};
            props[opts.prop || name] = opts.number ? +input.value : input.value;
            send([{ op: kind === 'node' ? 'set_node' : 'set_link', names: items.map(function (i) { return i.name; }), props: props }]);
        });
        return input;
    }

    function colourField(items, name, kind, allowContrast) {
        var c = common(items, name);
        var text = h('input', { name: name, value: c.value, placeholder: c.mixed ? '(mixed)' : 'none', 'class': c.mixed ? 'mixed' : '' });
        var swatch = h('input', { type: 'color', value: /^#[0-9a-f]{6}$/i.test(c.value) ? c.value : '#ffffff', title: 'Pick a colour' });
        function save(v) {
            var props = {};
            props[name] = v;
            send([{ op: kind === 'node' ? 'set_node' : 'set_link', names: items.map(function (i) { return i.name; }), props: props }]);
        }
        swatch.addEventListener('change', function () { text.value = swatch.value; save(swatch.value); });
        text.addEventListener('change', function () { save(text.value); });
        var buttons = [h('button', { type: 'button', text: 'none', title: 'No colour', onclick: function () { save('none'); } })];
        if (allowContrast) {
            buttons.push(h('button', { type: 'button', text: 'auto', title: 'Black or white, whichever reads best', onclick: function () { save('contrast'); } }));
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
        var nodes = selected('node'), links = selected('link');
        var parts = [];

        if (!nodes.length && !links.length) {
            parts = mapPanel();
        } else {
            if (nodes.length) { parts = parts.concat(nodePanel(nodes)); }
            if (links.length) { parts = parts.concat(linkPanel(links)); }
            parts.push(h('div', { 'class': 'actions' }, [
                h('button', { type: 'button', text: 'Duplicate', title: 'Ctrl+D', onclick: duplicateSelection, disabled: !nodes.length }),
                h('button', { type: 'button', 'class': 'danger', text: 'Delete', title: 'Delete key', onclick: deleteSelection })
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
        var bg = h('select', { name: 'background' }, [h('option', { value: '', text: '(none)' })].concat(cfg.backgrounds.map(function (b) {
            return h('option', { value: b, text: b.split('/').pop(), selected: b === m.background });
        })));
        bg.addEventListener('change', function () { send([{ op: 'set_map', background: bg.value }]); });

        return [
            h('h2', { text: 'Map' }),
            row('Title', title),
            row('Size', h('div', { 'class': 'pair' }, [w, h('label', { text: '×' }), hh])),
            row('Background', bg),
            h('p', { 'class': 'help', text: m.width + ' × ' + m.height + ' px, ' + state.model.nodes.length + ' nodes, ' + state.model.links.length + ' links.' }),
            h('h3', { text: 'Shortcuts' }),
            help([
                ['Click, Shift+click', 'select, add to selection'],
                ['Drag on empty space', 'select an area'],
                ['Alt+click', 'one item of a group'],
                ['Double-click empty space', 'new node'],
                ['Ctrl+A / Esc', 'select all / nothing'],
                ['Arrows, Shift+arrows', 'move by 1 or 10 px'],
                ['Ctrl+Z, Ctrl+Shift+Z', 'undo, redo'],
                ['Ctrl+D / Delete', 'duplicate / delete'],
                ['Ctrl+G / Ctrl+Shift+G', 'group / ungroup'],
                ['L / V', 'link tool / select tool'],
                ['Wheel, Space+drag', 'zoom, move the view'],
                ['F / 0', 'fit / actual size']
            ])
        ];
    }

    function help(lines) {
        return h('div', {}, lines.map(function (l) {
            return h('p', { 'class': 'help' }, [h('kbd', { text: l[0] }), ' ' + l[1]]);
        }));
    }

    function iconOptions() {
        return [['', '(none)'], ['box', 'Box'], ['rbox', 'Rounded box'], ['round', 'Circle']].concat(cfg.icons.map(function (i) {
            return [i, i.split('/').pop()];
        }));
    }

    function fontOptions() {
        return state.model.fonts.map(function (f) { return [f, f <= 5 ? 'Built-in ' + f : 'Font ' + f]; });
    }

    function nodePanel(nodes) {
        var single = nodes.length === 1 ? nodes[0] : null;
        var out = [h('h2', { text: single ? 'Node' : nodes.length + ' nodes' })];

        if (single) {
            var name = h('input', { name: 'name', value: single.name });
            name.addEventListener('change', function () {
                send([{ op: 'rename_node', name: single.name, to: name.value }], function () { return [key('node', name.value.replace(/[^\w.\-:]/g, '_'))]; });
            });
            out.push(row('Name', name));
        }

        out.push(row('Label', field(nodes, 'label', 'node')));

        if (single) {
            var x = h('input', { type: 'number', value: single.x }), y = h('input', { type: 'number', value: single.y });
            var pos = function () { send([{ op: 'move', nodes: (function () { var o = {}; o[single.name] = [+x.value, +y.value]; return o; })() }]); };
            x.addEventListener('change', pos);
            y.addEventListener('change', pos);
            out.push(row('Position', h('div', { 'class': 'pair' }, [x, y])));
        }

        out.push(
            row('Icon', field(nodes, 'icon', 'node', { options: iconOptions() })),
            h('h3', { text: 'Label style' }),
            row('Font', field(nodes, 'labelfont', 'node', { options: fontOptions(), number: true })),
            row('Background', colourField(nodes, 'labelbgcolour', 'node')),
            row('Text', colourField(nodes, 'labelfontcolour', 'node', true)),
            row('Outline', colourField(nodes, 'labeloutlinecolour', 'node')),
            h('h3', { text: 'Data' }),
            row('Target', field(nodes, 'target', 'node')),
            row('Info URL', field(nodes, 'infourl', 'node')),
            row('Layer', field(nodes, 'zorder', 'node', { type: 'number', number: true }))
        );

        if (nodes.some(function (n) { return n.group; })) {
            out.push(h('p', { 'class': 'help', text: 'Grouped: clicking one selects the whole group. Alt+click picks one.' }));
        }
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
        var out = [h('h2', { text: single ? 'Link ' + single.name : links.length + ' links' })];

        if (single) {
            out.push(h('p', { 'class': 'help', text: single.a + ' → ' + single.b }));
        }

        out.push(
            row('Width', field(links, 'width', 'link', { placeholder: 'auto, or pixels', list: 'width-options' })),
            row('Bandwidth', field(links, 'bandwidth_in', 'link', { prop: 'bandwidth', placeholder: 'auto, 1G, 10G...', list: 'bw-options' })),
            h('datalist', { id: 'width-options' }, ['auto', '2', '4', '6', '9', '12'].map(function (v) { return h('option', { value: v }); })),
            h('datalist', { id: 'bw-options' }, ['auto', '100M', '1G', '10G', '25G', '40G', '100G', '400G'].map(function (v) { return h('option', { value: v }); })),
            row('Target', field(links, 'target', 'link')),
            interfacePicker(links),
            h('h3', { text: 'Shape' }),
            row('Style', field(links, 'viastyle', 'link', { options: [['curved', 'Curved'], ['angled', 'Straight segments']] })),
            row('Arrows', field(links, 'linkstyle', 'link', { options: [['twoway', 'Both ways'], ['oneway', 'One way']] })),
            row('Split at %', field(links, 'splitpos', 'link', { type: 'number', number: true })),
            row('Layer', field(links, 'zorder', 'link', { type: 'number', number: true }))
        );

        if (single) {
            out.push(
                row('Ends', h('div', { 'class': 'pair' }, [anchorPicker(single, 'a'), anchorPicker(single, 'b')])),
                h('p', { 'class': 'help', text: 'Drag the red points on the link to bend it. Double-click a point to remove it.' }),
                h('div', { 'class': 'actions' }, [
                    h('button', { type: 'button', text: 'Straighten', disabled: !single.via.length, onclick: function () { send([{ op: 'set_link', names: [single.name], props: { via: [] } }]); } }),
                    h('button', { type: 'button', text: 'Auto-route', onclick: autoRoute })
                ]),
                h('h3', { text: 'Comments' }),
                row('Near ' + single.a, field(links, 'comment_out', 'link')),
                row('Near ' + single.b, field(links, 'comment_in', 'link'))
            );
        }
        return out;
    }

    function interfacePicker(links) {
        var box = h('details', {}, [h('summary', { text: 'Pick an interface from a data source' })]);
        var src = h('select', {}, [h('option', { value: '', text: 'Loading…' })]);
        var host = h('select', { disabled: true }, [h('option', { value: '', text: 'Host' })]);
        var iface = h('select', { disabled: true }, [h('option', { value: '', text: 'Interface' })]);
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
                fill(src, list, 'id', 'name', list.length ? 'Data source' : 'No data source configured');
            }).catch(function (e) { fill(src, [], 'id', 'name', e.message); });
        });
        src.addEventListener('change', function () {
            host.disabled = iface.disabled = true;
            if (!src.value) { return; }
            get('/api/data-sources/' + encodeURIComponent(src.value) + '/hosts').then(function (list) {
                fill(host, list, 'hostid', 'name', 'Host');
                host.disabled = false;
            }).catch(function (e) { fill(host, [], 'hostid', 'name', e.message); });
        });
        host.addEventListener('change', function () {
            iface.disabled = true;
            if (!host.value) { return; }
            get('/api/data-sources/' + encodeURIComponent(src.value) + '/hosts/' + encodeURIComponent(host.value) + '/bandwidths').then(function (list) {
                items = list;
                fill(iface, list, 'interfaceId', 'label', list.length ? 'Interface' : 'No interface with traffic data');
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

        box.appendChild(row('Source', src));
        box.appendChild(row('Host', host));
        box.appendChild(row('Interface', iface));
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
        'snap': function () { state.snap = !state.snap; updateToolbar(); },
        'zoom-in': function () { zoomAt(1.25); },
        'zoom-out': function () { zoomAt(0.8); },
        'zoom-reset': zoomReset,
        'zoom-fit': zoomFit
    };

    $('#toolbar').addEventListener('click', function (evt) {
        var b = evt.target.closest('button[data-cmd]');
        if (b && commands[b.dataset.cmd]) {
            commands[b.dataset.cmd]();
        }
    });

    function nudge(dx, dy) {
        var nodes = selected('node').filter(function (n) { return !n.locked; });
        if (!nodes.length) { return; }
        var n = state.nudge || (state.nudge = { moved: { dx: 0, dy: 0 } });
        nodes.forEach(function (node) { n.moved[node.name] = true; });
        n.moved.dx += dx;
        n.moved.dy += dy;
        moveNodeGroups(n.moved);
        clearTimeout(n.timer);
        // a burst of key presses is saved as one change
        n.timer = setTimeout(function () {
            state.nudge = null;
            send([moveOp(n.moved)]);
        }, 400);
    }

    document.addEventListener('keydown', function (evt) {
        var t = evt.target;
        if (t.tagName === 'INPUT' || t.tagName === 'SELECT' || t.tagName === 'TEXTAREA' || $('dialog[open]')) {
            return;
        }
        var ctrl = evt.ctrlKey || evt.metaKey, k = evt.key;

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
        if (state.drag || state.nudge || document.hidden) { return; }
        fetch('/map/' + cfg.mapId + '/version').then(function (r) { return r.ok ? r.text() : null; }).then(function (v) {
            if (v === null) { return; }
            if (lastVersion !== null && v !== lastVersion) { run(load); }
            lastVersion = v;
        }).catch(function () {});
    }, 10000);

    run(load);

    // for tests and debugging
    window.WMEditor = { state: state, send: send, load: load, autoRoute: autoRoute, untangle: untangle, align: align };
})();
