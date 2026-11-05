/*
 * Geographic basemap: web map tiles drawn under the SVG map, in the viewer
 * and in the SVG editor (see docs/studies/geographic-basemap.md).
 *
 * MapLibre GL JS (BSD-3-Clause, src/public/assets/vendor/maplibre-gl/) is
 * loaded only when a map has a basemap. It never handles the mouse: the
 * page keeps its own pan and zoom and tells the basemap where the map is.
 *
 *   var b = WMBasemap.create(container, config, {onFail: fn});
 *   b.sync(z, tx, ty, mapWidth, mapHeight)   // screen = t + z * map
 *   b.setConfig(config)                      // the map was edited
 *   b.destroy()
 *
 * config is what the server gives (App\Services\Basemap::of):
 * {url, dark, kind: style|raster, center: [lat, lon], zoom, attribution}.
 * The projection is the one of src/lib/WeatherMap/geo.php: the canvas
 * centre is config.center, and at 100 % one map pixel is one pixel of the
 * web map at config.zoom (512-pixel world tiles).
 */
(function () {
    'use strict';

    var TILE = 512;
    var MAX_LAT = 85.05112878;

    function world(lat, lon) {
        lat = Math.max(-MAX_LAT, Math.min(MAX_LAT, lat));
        var s = Math.sin(lat * Math.PI / 180);
        return [(lon + 180) / 360, 0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)];
    }

    function unworld(wx, wy) {
        var lon = wx * 360 - 180;
        var n = Math.PI * (1 - 2 * wy);
        var lat = Math.atan(0.5 * (Math.exp(n) - Math.exp(-n))) * 180 / Math.PI;
        return [lat, lon];
    }

    /** Map pixels of a point, for a frame {center, zoom} and a canvas size */
    function project(cfg, w, h, lat, lon) {
        var size = TILE * Math.pow(2, cfg.zoom);
        var c = world(cfg.center[0], cfg.center[1]);
        var p = world(lat, lon);
        return [w / 2 + (p[0] - c[0]) * size, h / 2 + (p[1] - c[1]) * size];
    }

    /** The point [lat, lon] under map pixels */
    function unproject(cfg, w, h, x, y) {
        var size = TILE * Math.pow(2, cfg.zoom);
        var c = world(cfg.center[0], cfg.center[1]);
        return unworld(c[0] + (x - w / 2) / size, c[1] + (y - h / 2) / size);
    }

    /**
     * The frame {center, zoom} that shows the points [[lat, lon]...] on a
     * canvas of this size, with a margin around them (pixels).
     */
    function fitFrame(points, w, h, margin) {
        if (!points.length) return null;
        var x0 = 1, y0 = 1, x1 = 0, y1 = 0;
        points.forEach(function (p) {
            var q = world(p[0], p[1]);
            x0 = Math.min(x0, q[0]); x1 = Math.max(x1, q[0]);
            y0 = Math.min(y0, q[1]); y1 = Math.max(y1, q[1]);
        });
        var c = unworld((x0 + x1) / 2, (y0 + y1) / 2);
        var room = Math.max(1, Math.min(w - 2 * margin, h - 2 * margin));
        var span = Math.max(x1 - x0, y1 - y0);
        var zoom = span > 0 ? Math.log(room / (span * TILE)) / Math.LN2 : 12;
        zoom = Math.max(0, Math.min(18, Math.floor(zoom * 100) / 100));
        return {center: [c[0], c[1]], zoom: zoom};
    }

    function isDark() {
        var t = document.documentElement.getAttribute('data-theme');
        if (t) return t === 'dark';
        return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    // ------------------------------------------------------------------
    // Loading MapLibre once

    var loading = null;
    function assets() {
        var a = window.WM_MAPLIBRE || {};
        var base = '/assets/vendor/maplibre-gl/';
        return {
            js: a.js || base + 'maplibre-gl-csp.js',
            worker: a.worker || base + 'maplibre-gl-csp-worker.js',
            css: a.css || base + 'maplibre-gl.css'
        };
    }

    function loadLibrary() {
        if (window.maplibregl) return Promise.resolve(window.maplibregl);
        if (loading) return loading;
        var a = assets();
        loading = new Promise(function (resolve, reject) {
            if (!document.querySelector('link[data-maplibre]')) {
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = a.css;
                link.setAttribute('data-maplibre', '');
                document.head.appendChild(link);
            }
            var s = document.createElement('script');
            s.src = a.js;
            s.async = true;
            s.onload = function () {
                if (!window.maplibregl) { reject(new Error('MapLibre did not load')); return; }
                // the CSP build runs its worker from a file of this site (no blob: workers)
                window.maplibregl.setWorkerUrl(new URL(a.worker, location.href).href);
                resolve(window.maplibregl);
            };
            s.onerror = function () { loading = null; reject(new Error('MapLibre did not load')); };
            document.head.appendChild(s);
        });
        return loading;
    }

    function webglAvailable() {
        try {
            var c = document.createElement('canvas');
            return !!(c.getContext('webgl2') || c.getContext('webgl'));
        } catch (e) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // One basemap

    function styleOf(cfg, dark) {
        var url = dark && cfg.dark ? cfg.dark : cfg.url;
        if (cfg.kind !== 'raster') return url;
        return {
            version: 8,
            sources: {
                base: {type: 'raster', tiles: [url], tileSize: 256, maxzoom: 19, attribution: cfg.attribution || ''}
            },
            layers: [{id: 'base', type: 'raster', source: 'base'}]
        };
    }

    function sameFrame(a, b) {
        return a && b && a.url === b.url && a.dark === b.dark && a.kind === b.kind && a.attribution === b.attribution;
    }

    function create(container, cfg, opts) {
        opts = opts || {};
        var self = {config: cfg, map: null, failed: false, ready: false};
        var view = null;     // the last sync() arguments
        var dark = isDark();
        var gone = false;

        function fail(err) {
            if (self.failed || gone) return;
            self.failed = true;
            container.hidden = true;
            if (window.console) console.warn('Basemap:', err && err.message ? err.message : err);
            if (opts.onFail) opts.onFail(err);
        }

        function camera() {
            if (!self.map || !view) return;
            var c = self.config;
            var vw = container.clientWidth || 1, vh = container.clientHeight || 1;
            // the map point under the centre of the screen
            var mx = (vw / 2 - view.tx) / view.z;
            var my = (vh / 2 - view.ty) / view.z;
            var p = unproject(c, view.w, view.h, mx, my);
            var zoom = c.zoom + Math.log(view.z) / Math.LN2;
            self.map.jumpTo({center: [p[1], p[0]], zoom: Math.max(0, Math.min(24, zoom))});
        }

        function build(lib) {
            if (gone) return;
            var custom = self.config.kind !== 'raster' && self.config.attribution ? self.config.attribution : undefined;
            try {
                self.map = new lib.Map({
                    container: container,
                    style: styleOf(self.config, dark),
                    interactive: false,
                    attributionControl: false,
                    fadeDuration: 0,
                    minZoom: 0,
                    maxZoom: 24,
                    center: [self.config.center[1], self.config.center[0]],
                    zoom: self.config.zoom
                });
            } catch (e) {
                fail(e);
                return;
            }
            self.map.addControl(new lib.AttributionControl({compact: true, customAttribution: custom}), 'bottom-right');
            self.map.on('error', function (e) {
                // a missing tile is fine; a style that cannot load is not
                if (!self.ready) fail(e && e.error ? e.error : e);
            });
            self.map.on('load', function () {
                self.ready = true;
                container.classList.add('wm-basemap-ready');
                if (opts.onReady) opts.onReady();
            });
            camera();
        }

        container.classList.add('wm-basemap');
        if (!webglAvailable()) {
            setTimeout(function () { fail(new Error('WebGL is not available')); }, 0);
        } else {
            loadLibrary().then(build, fail);
        }

        function onTheme() {
            var d = isDark();
            if (d === dark) return;
            dark = d;
            if (self.map && self.config.dark) self.map.setStyle(styleOf(self.config, dark));
        }
        document.addEventListener('wm:theme', onTheme);
        var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        if (mq && mq.addEventListener) mq.addEventListener('change', onTheme);

        var onResize = function () { if (self.map) { self.map.resize(); camera(); } };
        var ro = window.ResizeObserver ? new ResizeObserver(onResize) : null;
        if (ro) ro.observe(container); else window.addEventListener('resize', onResize);

        self.sync = function (z, tx, ty, w, h) {
            view = {z: z, tx: tx, ty: ty, w: w, h: h};
            camera();
        };

        self.setConfig = function (next) {
            var keepStyle = sameFrame(self.config, next);
            self.config = next;
            if (self.map && !keepStyle) {
                self.ready = false;
                self.map.setStyle(styleOf(next, dark));
                self.map.once('styledata', function () { self.ready = true; });
            }
            camera();
        };

        self.destroy = function () {
            gone = true;
            document.removeEventListener('wm:theme', onTheme);
            if (mq && mq.removeEventListener) mq.removeEventListener('change', onTheme);
            if (ro) ro.disconnect(); else window.removeEventListener('resize', onResize);
            if (self.map) self.map.remove();
            self.map = null;
            container.classList.remove('wm-basemap', 'wm-basemap-ready');
            container.hidden = false;
        };

        return self;
    }

    window.WMBasemap = {
        create: create,
        project: project,
        unproject: unproject,
        fitFrame: fitFrame,
        same: function (a, b) {
            return !!(a && b && sameFrame(a, b) && a.zoom === b.zoom
                && a.center[0] === b.center[0] && a.center[1] === b.center[1]);
        }
    };
})();
