/*
 * Located nodes on a basemap, shared by the viewer (viewer.js) and the SVG
 * editor (editor-svg.js), so both zoom into a geographic map the same way:
 *
 * - a node with a point (SET geo) keeps, past 100 %, the size it has at
 *   100 %: zooming in pulls close sites apart;
 * - the links get thinner by as much (WMGeo.arrowOutline, WMGeo.reachNodes);
 * - located nodes too close to tell apart merge into a cluster, as the map
 *   says with SET cluster_mode (WMGeo.clusters):
 *     nearby  any nodes too close at the current zoom (the default)
 *     none    never
 *     groups  only nodes of the same group (SET cluster, or their site),
 *             the whole group at once
 *
 * Node records given to WMGeo.clusters: {name, x, y, size, group}, x and y
 * in map pixels, size their smaller side at 100 %.
 */
(function () {
    'use strict';

    // two nodes closer than this on the screen merge (or than most of their size)
    var CLUSTER_PX = 32;
    // past this zoom, the view can go deeper on a basemap: six web map levels
    var MAX_ZOOM = 64;
    var MODES = ['nearby', 'none', 'groups'];

    /** The cluster mode of a basemap config (App\Services\Basemap::of) */
    function modeOf(cfg) {
        var m = cfg && cfg.clusters;
        return MODES.indexOf(m) >= 0 ? m : 'nearby';
    }

    /** The scale of a located node at zoom z: 1 up to 100 %, then no bigger on the screen */
    function nodeScale(z) {
        return z > 1 ? 1 / z : 1;
    }

    /** Whether two located nodes are too close on the screen at zoom z */
    function tooClose(a, b, z) {
        // their size on the screen: as on the map up to 100 %, then no bigger
        var size = Math.min(a.size, b.size) * Math.min(z, 1);
        return Math.hypot(a.x - b.x, a.y - b.y) * z < Math.max(CLUSTER_PX, 0.8 * size);
    }

    /**
     * The clusters at zoom z: [{nodes: [...], group: name or ''}], only
     * those of two nodes or more. `keep` (a node name) never merges.
     */
    function clusters(nodes, z, mode, keep) {
        var free = nodes.filter(function (n) { return n.name !== keep; });
        var out = [];
        if (mode === 'none') {
            return out;
        }
        if (mode === 'groups') {
            var byGroup = {}, order = [];
            free.forEach(function (n) {
                if (!n.group) { return; }
                if (!byGroup[n.group]) {
                    byGroup[n.group] = [];
                    order.push(n.group);
                }
                byGroup[n.group].push(n);
            });
            order.forEach(function (g) {
                var list = byGroup[g];
                // the whole group, as soon as two of its nodes run into each other
                var close = list.some(function (n, i) {
                    return list.slice(i + 1).some(function (m) { return tooClose(n, m, z); });
                });
                if (list.length > 1 && close) {
                    out.push({ nodes: list, group: g });
                }
            });
            return out;
        }
        var taken = new Set();
        free.forEach(function (n) {
            if (taken.has(n)) { return; }
            taken.add(n);
            var group = [n];
            free.forEach(function (m) {
                if (!taken.has(m) && tooClose(n, m, z)) {
                    taken.add(m);
                    group.push(m);
                }
            });
            if (group.length > 1) {
                out.push({ nodes: group, group: '' });
            }
        });
        return out;
    }

    /** The zoom at which the nodes of a cluster split, or 0 when they never do (same point) */
    function splitZoom(nodes) {
        var near = Infinity, size = Infinity;
        nodes.forEach(function (n, i) {
            size = Math.min(size, n.size);
            nodes.slice(i + 1).forEach(function (m) {
                var d = Math.hypot(n.x - m.x, n.y - m.y);
                if (d > 0.01) { near = Math.min(near, d); }
            });
        });
        return near === Infinity ? 0 : 1.5 * Math.max(CLUSTER_PX, 0.8 * size) / near;
    }

    /** The outline of an arrow along pts: body of half width w, head of half width aw and length as */
    function arrowOutline(pts, w, aw, as) {
        var d = [0], i, j;
        for (i = 1; i < pts.length; i++) { d.push(d[i - 1] + Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1])); }
        var len = d[d.length - 1];
        if (len <= 0) { return null; }
        as = Math.min(as, len / 1.2);
        var at = len - as;
        // the spine up to where the head starts
        var body = [pts[0]];
        i = 1;
        while (i < pts.length - 1 && d[i] < at) { body.push(pts[i++]); }
        var a = pts[i - 1], b = pts[i];
        var f = d[i] > d[i - 1] ? (at - d[i - 1]) / (d[i] - d[i - 1]) : 0;
        var base = [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f];
        if (Math.hypot(base[0] - body[body.length - 1][0], base[1] - body[body.length - 1][1]) > 0.01 || body.length < 2) { body.push(base); }
        if (body.length < 2) { return null; }
        function normal(p, q) {
            var l = Math.hypot(q[0] - p[0], q[1] - p[1]) || 1;
            return [(q[1] - p[1]) / l, -(q[0] - p[0]) / l];
        }
        var left = [], right = [];
        for (j = 0; j < body.length; j++) {
            var n1 = normal(body[Math.max(0, j - 1)], body[Math.max(1, j)]);
            var n2 = normal(body[Math.min(j, body.length - 2)], body[Math.min(j + 1, body.length - 1)]);
            var nx = n1[0] + n2[0], ny = n1[1] + n2[1];
            var nl = Math.hypot(nx, ny);
            if (nl < 1e-6) { nx = n1[0]; ny = n1[1]; } else { nx /= nl; ny /= nl; }
            // a mitred corner, not too long on a sharp turn
            var m = w / Math.max(0.25, nx * n1[0] + ny * n1[1]);
            var p = body[j];
            left.push([p[0] + nx * m, p[1] + ny * m]);
            right.push([p[0] - nx * m, p[1] - ny * m]);
        }
        var tip = pts[pts.length - 1];
        var hn = normal(base, tip);
        var out = left.concat([
            [base[0] + hn[0] * aw, base[1] + hn[1] * aw],
            tip,
            [base[0] - hn[0] * aw, base[1] - hn[1] * aw]
        ], right.reverse());
        return out.map(function (q) { return q[0].toFixed(3) + ',' + q[1].toFixed(3); }).join(' ');
    }

    /**
     * The engine stops each link where its end is just hidden under its
     * node (data-cut: the length cut at the start and at the tip). A
     * thinner link drawn under a node shrunk as much ends that much closer
     * to the centre, or it would show between the node and its old edge.
     */
    function reachNodes(pts, cut, k) {
        if (!(cut[0] > 0) && !(cut[1] > 0)) { return pts; }
        function stretch(p, q, d) {
            var l = Math.hypot(p[0] - q[0], p[1] - q[1]);
            return l > 0 ? [p[0] + (p[0] - q[0]) / l * d, p[1] + (p[1] - q[1]) / l * d] : p;
        }
        var out = pts.slice();
        if (cut[0] > 0) { out[0] = stretch(pts[0], pts[1], cut[0] * (1 - k)); }
        if (cut[1] > 0) { out[out.length - 1] = stretch(pts[pts.length - 1], pts[pts.length - 2], cut[1] * (1 - k)); }
        return out;
    }

    /** The arrows of an SVG drawing (polygon[data-spine]), to draw them thinner */
    function arrows(svg) {
        var out = [];
        svg.querySelectorAll('polygon[data-spine]').forEach(function (el) {
            var nums = (el.getAttribute('data-spine').match(/-?\d+(?:\.\d+)?(?:e-?\d+)?/g) || []).map(Number);
            var pts = [];
            for (var i = 0; i + 1 < nums.length; i += 2) { pts.push([nums[i], nums[i + 1]]); }
            var w = parseFloat(el.getAttribute('data-w'));
            if (pts.length < 2 || !(w > 0)) { return; }
            var cut = (el.getAttribute('data-cut') || '').split(' ').map(Number);
            out.push({
                el: el, pts: pts, points: el.getAttribute('points'), w: w,
                aw: parseFloat(el.getAttribute('data-aw')) || w,
                as: parseFloat(el.getAttribute('data-as')) || 0,
                cut: [cut[0] > 0 ? cut[0] : 0, cut[1] > 0 ? cut[1] : 0]
            });
        });
        return out;
    }

    /** Draw arrows (from arrows()) at k times their width; 1 puts them back */
    function thin(list, k) {
        list.forEach(function (a) {
            var pts = k < 1 ? arrowOutline(reachNodes(a.pts, a.cut, k), a.w * k, a.aw * k, a.as * k) : null;
            a.el.setAttribute('points', pts || a.points);
        });
    }

    window.WMGeo = {
        CLUSTER_PX: CLUSTER_PX,
        MAX_ZOOM: MAX_ZOOM,
        MODES: MODES,
        modeOf: modeOf,
        nodeScale: nodeScale,
        tooClose: tooClose,
        clusters: clusters,
        splitZoom: splitZoom,
        arrowOutline: arrowOutline,
        reachNodes: reachNodes,
        arrows: arrows,
        thin: thin
    };
})();
