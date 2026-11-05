/**
 * Map properties of the SVG editor, shown in the properties panel when
 * nothing is selected: sections that fold for the background colour and
 * theme, the title, legend and timestamp texts, fonts and colours, the
 * colour scale of the links (with presets), the link defaults (the DEFAULT
 * link), the basemap and the fonts. A selected legend, timestamp or title
 * shows its own section. Each field is saved when it changes, as one step
 * that can be undone; the colour scale is saved with its Apply button.
 */
(function () {
    'use strict';

    var ed = window.WMEditor;
    var t = ed.t, h = ed.h;
    var FOLD_KEY = 'wm-editor-map-sections';
    var scaleRows = []; // the steps of the colour scale being edited
    var scaleStart = '';

    /**
     * Colour scales to start from, in % of the link bandwidth. Our own
     * choices: every step but the classic ones reads on a light and a dark
     * background, and the colour-blind safe one keeps its steps apart by
     * lightness as well as hue (colours after Okabe and Ito).
     */
    var PRESETS = [
        ['classic', t('Classic Weathermap'), [
            [0, 0, '#c0c0c0'], [0, 1, '#ffffff'], [1, 10, '#8c00ff'], [10, 25, '#2020ff'], [25, 40, '#00c0ff'],
            [40, 55, '#00f000'], [55, 70, '#f0f000'], [70, 85, '#ffc000'], [85, 100, '#ff0000']
        ]],
        ['traffic', t('Traffic light: green, amber, red'), [
            [0, 0, '#9aa0a6'], [0, 30, '#2e9e4f'], [30, 50, '#8cbf3f'], [50, 70, '#e6b800'],
            [70, 85, '#f08c1a'], [85, 100, '#d93025']
        ]],
        ['colourblind', t('Colour-blind safe'), [
            [0, 0, '#9aa0a6'], [0, 10, '#56b4e9'], [10, 25, '#0072b2'], [25, 40, '#009e73'],
            [40, 55, '#f0e442'], [55, 70, '#e69f00'], [70, 85, '#d55e00'], [85, 100, '#8e1b3f']
        ]],
        ['heat', t('Heat: light to dark as the load grows'), [
            [0, 0, '#9aa0a6'], [0, 15, '#f2d15c'], [15, 30, '#f0a840'], [30, 45, '#e8803a'], [45, 60, '#d9573a'],
            [60, 75, '#c0303a'], [75, 90, '#92203c'], [90, 100, '#5e1a3a']
        ]],
        ['blue', t('One colour: blue, darker when loaded'), [
            [0, 0, '#9aa0a6'], [0, 20, '#9ecae1'], [20, 40, '#6baed6'], [40, 60, '#3182bd'],
            [60, 80, '#1c5fa0'], [80, 100, '#0b3a75']
        ]]
    ];

    function presetRows(id) {
        var p = PRESETS.filter(function (x) { return x[0] === id; })[0];
        return p ? p[2].map(function (r) { return { bottom: r[0], top: r[1], colour: r[2], colour2: '', tag: '' }; }) : [];
    }

    function scaleJson(rows) {
        return JSON.stringify(rows.map(function (r) { return [+r.bottom, +r.top, r.colour, r.colour2 || '', r.tag || '']; }));
    }

    /** The Link colours tab: one row per step, presets, and a preview bar */
    function scalePane(steps) {
        scaleRows = (steps || []).map(function (s) { return Object.assign({}, s); });
        scaleStart = scaleJson(scaleRows);
        var list = h('div', { 'class': 'scale-list' });
        var bar = h('div', { 'class': 'scale-bar', 'aria-hidden': 'true' });
        var msg = h('span', { 'class': 'hint scale-msg', role: 'status' });

        function check() {
            var bad = scaleRows.filter(function (r) { return !(+r.bottom >= 0 && +r.top <= 100 && +r.bottom <= +r.top); }).length;
            msg.textContent = bad ? t('Each step goes from a value to a higher one, between 0 and 100 %.') : '';
        }

        function render() {
            list.replaceChildren.apply(list, scaleRows.map(function (r, i) {
                function num(k, label) {
                    var inp = h('input', { type: 'number', min: 0, max: 100, step: 'any', value: r[k], 'aria-label': label, 'data-k': k });
                    inp.addEventListener('input', function () { r[k] = inp.value; preview(); check(); });
                    return inp;
                }
                var none = r.colour === 'none';
                var pick = h('input', { type: 'color', value: none ? '#ffffff' : r.colour, disabled: none, 'aria-label': t('Colour') });
                pick.addEventListener('input', function () { r.colour = pick.value; r.colour2 = ''; preview(); });
                var off = h('input', { type: 'checkbox', checked: none, title: t('No colour: links in this step are not drawn') });
                off.addEventListener('change', function () { r.colour = off.checked ? 'none' : pick.value; pick.disabled = off.checked; preview(); });
                return h('div', { 'class': 'scale-row' }, [
                    num('bottom', t('From (%)')), h('span', { text: '–' }), num('top', t('To (%)')), h('span', { text: '%' }),
                    pick, h('label', { 'class': 'inline' }, [off, ' ' + t('None')]),
                    r.colour2 ? h('span', { 'class': 'hint', text: t('gradient'), title: t('This step fades to :colour', { colour: r.colour2 }) }) : null,
                    h('button', { type: 'button', text: '×', title: t('Remove this step'), 'aria-label': t('Remove this step'), disabled: scaleRows.length < 2,
                        onclick: function () { scaleRows.splice(i, 1); render(); } })
                ]);
            }));
            preview();
            check();
        }

        function preview() {
            var parts = scaleRows.filter(function (r) { return +r.top > +r.bottom; }).slice().sort(function (a, b) { return a.bottom - b.bottom; });
            bar.replaceChildren.apply(bar, parts.map(function (r) {
                var w = Math.max(0, Math.min(100, +r.top) - Math.max(0, +r.bottom));
                var c = r.colour === 'none' ? 'transparent' : r.colour;
                return h('span', { style: 'flex:' + w + ' 0 0;background:' + (r.colour2 ? 'linear-gradient(90deg,' + c + ',' + r.colour2 + ')' : c), title: r.bottom + '–' + r.top + ' %' });
            }));
        }

        var preset = h('select', { 'aria-label': t('Preset') }, [h('option', { value: '', text: t('Choose a preset…') })].concat(PRESETS.map(function (p) {
            return h('option', { value: p[0], text: p[1] });
        })));
        preset.addEventListener('change', function () {
            if (!preset.value) { return; }
            scaleRows = presetRows(preset.value);
            preset.value = '';
            render();
        });
        var add = h('button', { type: 'button', text: t('Add a step'), onclick: function () {
            var last = scaleRows[scaleRows.length - 1];
            var from = last ? Math.min(100, +last.top) : 0;
            scaleRows.push({ bottom: from, top: 100, colour: last && last.colour !== 'none' ? last.colour : '#808080', colour2: '', tag: '' });
            render();
        } });

        var apply = h('button', { type: 'button', 'class': 'primary', text: t('Apply the colours'), onclick: function () {
            if (scaleJson(scaleRows) === scaleStart) { return; }
            ed.send([{ op: 'set_scale', steps: scaleRows.map(function (r) {
                return { bottom: +r.bottom, top: +r.top, colour: r.colour, colour2: r.colour2 || '', tag: r.tag || '' };
            }) }], function () { ed.toast(t('Link colours saved')); return undefined; });
        } });
        var reset = h('button', { type: 'button', text: t('Reset'), title: t('Back to the saved colours'), onclick: function () {
            scaleRows = JSON.parse(scaleStart).map(function (r) { return { bottom: r[0], top: r[1], colour: r[2], colour2: r[3], tag: r[4] }; });
            render();
        } });

        render();
        return [
            h('p', { 'class': 'mapprops-explain', text: t('The colour of a link follows its load, in % of its bandwidth: each step gives the colour from one value to the next. A 0 to 0 step colours the links without traffic. The legend shows these steps.') }),
            field(t('Start from a preset'), preset, t('Replaces the steps below. Nothing is saved before Apply.')),
            bar,
            list,
            h('div', { 'class': 'pair' }, [add, msg]),
            h('div', { 'class': 'actions' }, [reset, apply])
        ];
    }

    var SECTIONS = [
        ['general', t('General')], ['title', t('Title')], ['legend', t('Legend')], ['scale', t('Link colours')],
        ['timestamp', t('Timestamp')], ['links', t('Link defaults')], ['basemap', t('Basemap')], ['fonts', t('Fonts')]
    ];

    /**
     * Basemaps to start from: [id, label, url, dark url]. OpenFreeMap serves
     * OpenStreetMap vector tiles for free, without a key; its styles come
     * from OpenMapTiles (Positron, Dark Matter) and OSM Liberty (BSD-3).
     */
    var BASEMAPS = [
        ['openfreemap', t('OpenFreeMap, light or dark with the theme'), 'https://tiles.openfreemap.org/styles/positron', 'https://tiles.openfreemap.org/styles/dark'],
        ['liberty', t('OpenFreeMap Liberty, in colour'), 'https://tiles.openfreemap.org/styles/liberty', '']
    ];

    /** The Basemap tab: tiles under the map in the viewer and the editor, and where the canvas sits on them */
    function basemapPane(p, m) {
        var url = h('input', { name: 'basemap', value: p.basemap, autocomplete: 'off', placeholder: 'https://…', spellcheck: 'false' });
        var dark = h('input', { name: 'basemap_dark', value: p.basemap_dark, autocomplete: 'off', placeholder: t('same as above'), spellcheck: 'false' });
        var centre = h('input', { name: 'basemap_center', value: p.basemap_center, autocomplete: 'off', placeholder: '48.8566 2.3522', spellcheck: 'false' });
        var zoom = h('input', { type: 'number', name: 'basemap_zoom', min: 0, max: 22, step: 'any', value: p.basemap_zoom, placeholder: '5' });
        var credit = h('input', { name: 'basemap_attribution', value: p.basemap_attribution, autocomplete: 'off', placeholder: t('© OpenStreetMap contributors') });
        var preset = h('select', { 'aria-label': t('Preset') }, [
            h('option', { value: '', text: t('Choose a basemap…') }),
            h('option', { value: 'none', text: t('None: the plain background') })
        ].concat(BASEMAPS.map(function (b) { return h('option', { value: b[0], text: b[1] }); })));
        preset.addEventListener('change', function () {
            var b = BASEMAPS.filter(function (x) { return x[0] === preset.value; })[0];
            if (preset.value === 'none') { url.value = ''; dark.value = ''; }
            if (b) {
                url.value = b[2];
                dark.value = b[3];
                credit.value = '';
                if (!centre.value) { centre.value = '46.6 2.4'; }
                if (!zoom.value) { zoom.value = '5'; }
            }
            if (preset.value) {
                sendProps({ basemap: url.value, basemap_dark: dark.value, basemap_attribution: credit.value, basemap_center: centre.value, basemap_zoom: zoom.value });
            }
            preset.value = '';
        });

        // the nodes that have a point: frame the canvas around them
        var pinned = [];
        ed.state.nodes.forEach(function (n) { if (n.geo) { pinned.push(n.geo); } });
        var fit = h('button', { type: 'button', text: t('Frame the located nodes'), disabled: !pinned.length,
            title: pinned.length ? t(':n nodes have a location', { n: pinned.length }) : t('No node has a location yet') });
        fit.addEventListener('click', function () {
            var f = window.WMBasemap && window.WMBasemap.fitFrame(pinned, m.width, m.height, 60);
            if (!f) { return; }
            centre.value = f.center[0].toFixed(5) + ' ' + f.center[1].toFixed(5);
            zoom.value = String(f.zoom);
            sendProps({ basemap_center: centre.value, basemap_zoom: zoom.value });
        });

        var servers = (m.basemap_servers || []).join(', ');
        return [
            h('p', { 'class': 'mapprops-explain', text: t('A web map drawn under the map in the live view and in the editor. The canvas covers the area around its centre point; at 100 %, one pixel of the map is one pixel of the web map at the zoom level below. Nodes with a location (in the node panel) sit on their point; the others keep their position. The PNG image and the downloaded SVG keep the plain background.') }),
            field(t('Start from'), preset),
            field(t('Style or tiles address'), url, t('A MapLibre style (JSON) or raster tiles with {z}/{x}/{y}. Allowed: this site, or :servers (BASEMAP_SERVERS).', { servers: servers || '–' })),
            field(t('For the dark theme'), dark, t('Optional, of the same kind as the address above.')),
            field(t('Centre (latitude longitude)'), centre),
            field(t('Zoom level'), zoom, t('0 shows the whole world, 5 a country, 11 a city.')),
            fit,
            field(t('Credit'), credit, t('Shown on the map. Styles carry their own; raster tiles default to OpenStreetMap.'))
        ];
    }

    function fontSelect(name, value) {
        return h('select', { name: name, 'class': 'font-select' }, ed.state.model.fonts.map(function (f) {
            return h('option', { value: f, text: ed.fontName(f), selected: +f === +value });
        }));
    }

    // ------------------------------------------------------------------
    // Fonts: those of the map, with a sample, and adding one of the fonts
    // shipped with the application. Saved at once, not with Apply.

    var loadedFaces = {};

    /** A CSS font family drawing font n of this map (the editor serves its file) */
    function faceFor(n) {
        var info = ed.state.model.font_info[n];
        if (!info || !info.size || !window.FontFace) { return null; }
        var family = 'wm-font-' + n + '-' + info.file.replace(/[^\w]/g, '');
        if (!loadedFaces[family]) {
            loadedFaces[family] = new FontFace(family, 'url(/editor/font/' + window.WM_EDITOR.mapId + '/' + n + ')');
            loadedFaces[family].load().then(function (f) { document.fonts.add(f); }).catch(function () {});
        }
        return family;
    }

    function fontsPane() {
        var list = h('div', { 'class': 'font-list' });
        function renderList() {
            var rows = Object.keys(ed.state.model.font_info || {}).map(Number).sort(function (a, b) { return a - b; }).map(function (n) {
                var info = ed.state.model.font_info[n], family = faceFor(n);
                var size = info.size ? h('input', { type: 'number', min: 4, max: 96, value: info.size, 'aria-label': t('Size of font :n', { n: n }) }) : null;
                if (size) {
                    size.addEventListener('change', function () {
                        if (+size.value === info.size) { return; }
                        ed.send([{ op: 'define_font', n: n, file: info.file, size: +size.value }], function () { return undefined; }).then(afterChange);
                    });
                }
                return h('div', { 'class': 'font-row' }, [
                    h('span', { 'class': 'font-n', text: '#' + n }),
                    h('span', { 'class': 'font-sample', style: family ? 'font-family: "' + family + '", sans-serif; font-size: ' + Math.min(22, Math.max(10, info.size * 1.2)) + 'px' : null, text: info.label }),
                    size
                ]);
            });
            if (!rows.length) { rows.push(h('p', { 'class': 'hint', text: t('This map uses only the built-in fonts.') })); }
            list.replaceChildren.apply(list, rows);
        }

        var file = h('select', { 'aria-label': t('Font') }, (window.WM_EDITOR.fonts || []).map(function (f) {
            return h('option', { value: f.file, text: f.label });
        }));
        var size = h('input', { type: 'number', min: 4, max: 96, value: 10, 'aria-label': t('Size') });
        var add = h('button', { type: 'button', text: t('Add the font') });
        var preview = h('span', { 'class': 'font-sample', text: 'Core-Paris 1G 45%' });
        function showPreview() {
            preview.style.fontFamily = '';
            var url = '/' + file.value, family = 'wm-ship-' + file.value.replace(/[^\w]/g, '');
            if (!loadedFaces[family] && window.FontFace) {
                loadedFaces[family] = new FontFace(family, 'url(' + url + ')');
                loadedFaces[family].load().then(function (f) { document.fonts.add(f); }).catch(function () {});
            }
            preview.style.fontFamily = '"' + family + '", sans-serif';
            preview.style.fontSize = Math.min(22, Math.max(10, +size.value * 1.2)) + 'px';
        }
        file.addEventListener('change', showPreview);
        size.addEventListener('input', showPreview);
        add.addEventListener('click', function () {
            ed.send([{ op: 'define_font', file: file.value, size: +size.value || 10 }], function (r) {
                var n = r.created && r.created.fonts && r.created.fonts[0];
                // the model is not updated yet: name it from the form
                if (n) { ed.toast(t('Font :name added: pick it in the Font lists.', { name: file.selectedOptions[0].text + ' ' + (+size.value || 10) })); }
                return undefined;
            }).then(afterChange);
        });

        /** New fonts show in the lists of the other tabs, keeping what is chosen there */
        function afterChange() {
            renderList();
            Array.prototype.forEach.call(document.querySelectorAll('#inspector select.font-select'), function (sel) {
                var v = sel.value;
                sel.replaceChildren.apply(sel, ed.state.model.fonts.map(function (f) {
                    return h('option', { value: f, text: ed.fontName(f), selected: String(f) === v });
                }));
            });
        }

        renderList();
        showPreview();
        return [
            h('p', { 'class': 'mapprops-explain', text: t('The fonts the map can use. Changes here are saved at once. Add a font, then choose it for the title, legend, timestamp, labels or links.') }),
            list,
            h('h4', { text: t('Add a font') }),
            h('div', { 'class': 'pair font-add' }, [file, size, add]),
            preview
        ];
    }

    function choice(name, value, options) {
        return h('select', { name: name }, options.map(function (o) {
            return h('option', { value: o[0], text: o[1], selected: o[0] === value });
        }));
    }

    /** A colour; when it may be none, a box turns it off */
    function colour(name, value, mayBeNone) {
        var input = h('input', { type: 'color', name: name, value: value === 'none' ? '#ffffff' : value });
        if (!mayBeNone) { return input; }
        var none = h('input', { type: 'checkbox', name: name + '__none', checked: value === 'none' });
        input.disabled = value === 'none';
        none.addEventListener('change', function () { input.disabled = none.checked; });
        return h('div', { 'class': 'pair' }, [input, h('label', { 'class': 'inline' }, [none, ' ' + t('None')])]);
    }

    function field(label, control, help) {
        return h('label', {}, [label, control].concat(help ? [h('span', { 'class': 'hint', text: help })] : []));
    }

    function check(name, checked, label) {
        return h('label', { 'class': 'inline' }, [h('input', { type: 'checkbox', name: name, checked: checked }), ' ' + label]);
    }

    /** Themes the server knows (MapThemes), by id */
    var THEMES = [['night', t('Night: dark canvas, cream to amber glowing links')]];

    /** The theme row of the General tab: applied and saved at once, one undo step */
    function themePane() {
        var pick = h('select', { 'aria-label': t('Theme') }, THEMES.map(function (x) {
            return h('option', { value: x[0], text: x[1] });
        }));
        var go = h('button', { type: 'button', text: t('Apply the theme') });
        go.addEventListener('click', function () {
            var name = pick.selectedOptions[0].text, done = false;
            go.disabled = true;
            ed.send([{ op: 'apply_theme', theme: pick.value }], function () { done = true; return undefined; }).then(function () {
                if (done) { ed.toast(t('Theme applied: :name. Ctrl+Z to undo.', { name: name })); }
            }).finally(function () { go.disabled = false; });
        });
        return [
            h('h4', { text: t('Theme') }),
            h('div', { 'class': 'pair' }, [pick, go]),
            h('span', { 'class': 'hint', text: t('Sets the colours of the map, the legend, the link colours and the default node and link labels. Ctrl+Z undoes it.') })
        ];
    }

    /** Saves map properties at once, as one step that can be undone */
    function sendProps(props) {
        return ed.send([{ op: 'set_map', props: props }], function () { return undefined; });
    }

    /** The value of a named field of a section, as the server expects it */
    function valueOf(box, name) {
        var el = box.querySelector('[name="' + name + '"]');
        if (!el) { return undefined; }
        if (el.type === 'checkbox') { return el.checked; }
        if (el.type === 'color') {
            var none = box.querySelector('[name="' + name + '__none"]');
            return none && none.checked ? 'none' : el.value.toLowerCase();
        }
        return el.value;
    }

    /** The fields of a section, by section id; title adds the map name when shown for the title itself */
    function panes(id, p, m, withName) {
        switch (id) {
            case 'appearance':
                return [
                    field(t('Background colour'), colour('bg_colour', p.bg_colour, false), t('Seen where the background image does not cover the map.'))
                ].concat(themePane());
            case 'title':
                return (withName ? [field(t('Map name'), h('input', { name: 'title', value: m.title, autocomplete: 'off' }), t('Drawn as the title of the map and shown in the map list.'))] : []).concat([
                    field(t('Font'), fontSelect('title_font', p.title_font)),
                    field(t('Colour'), colour('title_colour', p.title_colour, false))
                ]);
            case 'legend':
                return [
                    h('p', { 'class': 'mapprops-explain', text: t('The legend gives the colour of a link by its load: the traffic in each direction as a percentage of the link bandwidth. A 1G link carrying 450M is at 45%, so that half of the link takes the colour of the 45% step.') }),
                    check('key_shown', p.key_shown, t('Show the legend on the map')),
                    field(t('Legend text'), h('input', { name: 'key_text', value: p.key_text, autocomplete: 'off' })),
                    field(t('Style'), choice('key_style', p.key_style, [
                        ['classic', t('Classic: a list of colours')], ['horizontal', t('Horizontal bar')], ['vertical', t('Vertical bar')],
                        ['inverted', t('Vertical bar, 100% at the top')], ['tags', t('List with the step tags')],
                        ['modern', t('Modern: a rounded card with a colour bar')]
                    ])),
                    field(t('Bar length (px)'), h('input', { type: 'number', name: 'key_size', min: 50, max: 2000, value: p.key_size }), t('For the horizontal, vertical and modern bars.')),
                    field(t('Font'), fontSelect('key_font', p.key_font)),
                    field(t('Text colour'), colour('key_text_colour', p.key_text_colour, false)),
                    field(t('Box colour'), colour('key_bg_colour', p.key_bg_colour, true)),
                    field(t('Box outline'), colour('key_outline_colour', p.key_outline_colour, true))
                ];
            case 'timestamp':
                return [
                    field(t('Timestamp text'), h('input', { name: 'stamp_text', value: p.stamp_text, autocomplete: 'off' }),
                        t('%d day, %m month, %b month name, %Y year, %H hour, %M minutes, %S seconds. Example: Created: %b %d %Y %H:%M:%S')),
                    field(t('Style'), choice('stamp_style', p.stamp_style, [
                        ['plain', t('Plain text')], ['pill', t('On a rounded label, in the legend colours')]
                    ])),
                    field(t('Font'), fontSelect('time_font', p.time_font)),
                    field(t('Colour'), colour('time_colour', p.time_colour, false))
                ];
            case 'links':
                return [
                    h('p', { 'class': 'mapprops-explain', text: t('The values a link uses when it has none of its own. Links that had the old default value take the new one; links with their own value keep it.') }),
                    field(t('Bandwidth'), h('input', { name: 'link_bandwidth', value: p.link_bandwidth, autocomplete: 'off', placeholder: '1G' }),
                        t('In bits per second: 100M, 1G, 10G. The link load in % is its traffic divided by this.')),
                    field(t('Width (px)'), h('input', { name: 'link_width', value: p.link_width, autocomplete: 'off' }), t('A number, or auto to follow the bandwidth.')),
                    field(t('Label'), choice('link_labelstyle', p.link_labelstyle, [
                        ['percent', t('Load in %')], ['bits', t('Traffic in bits/s')], ['unformatted', t('Raw value')], ['none', t('No label')]
                    ])),
                    field(t('Label font'), fontSelect('link_bwfont', p.link_bwfont)),
                    field(t('Label box'), choice('link_bwstyle', p.link_bwstyle, [
                        ['classic', t('Level box')], ['angled', t('Box along the link')], ['pill', t('Pill along the link, with an arrow')]
                    ]))
                ];
            case 'scale':
                return scalePane(m.scale).concat([
                    h('h4', { text: t('Saturated links') }),
                    check('traffic_pulse', p.traffic_pulse, t('Make links at 80 % or more pulse and glow in the viewer')),
                    h('span', { 'class': 'hint', text: t('Only on the live map. A visitor who asked their system for less motion sees the glow without the pulse.') }),
                    h('h4', { text: t('Glow') }),
                    check('link_glow', p.link_glow, t('Draw a soft glow around the links, in their own colour')),
                    h('span', { 'class': 'hint', text: t('In the live map and the editor; the PNG image keeps plain links. Looks best on a dark background.') }),
                    h('h4', { text: t('Traffic flow') }),
                    check('link_flow', p.link_flow, t('Move glowing dots along the links, in the direction of the traffic')),
                    h('span', { 'class': 'hint', text: t('Only on the live map. Busier links move faster. No motion for a visitor who asked their system for less motion.') })
                ]);
            case 'basemap':
                return basemapPane(p, m);
            case 'fonts':
                return fontsPane();
        }
        return [];
    }

    /** What a folded section shows on its row */
    function summary(id, p, m) {
        switch (id) {
            case 'appearance': return p.bg_colour;
            case 'title': return ed.fontName(p.title_font);
            case 'legend': return p.key_shown ? t('Shown') : t('Hidden');
            case 'scale': return t(':n steps', { n: (m.scale || []).length });
            case 'timestamp': return p.stamp_text;
            case 'links': return p.link_bandwidth;
            case 'basemap':
                if (!p.basemap) { return t('None'); }
                try { return new URL(p.basemap, location.href).hostname; } catch (e) { return p.basemap; }
            case 'fonts': return String(Object.keys(ed.state.model.font_info || {}).length);
        }
        return '';
    }

    var SECTIONS = [
        ['appearance', t('Background colour and theme')], ['title', t('Title')], ['legend', t('Legend')], ['scale', t('Link colours')],
        ['timestamp', t('Timestamp')], ['links', t('Link defaults')], ['basemap', t('Basemap')], ['fonts', t('Fonts')]
    ];

    function loadFolds() {
        try { return JSON.parse(localStorage.getItem(FOLD_KEY) || 'null') || {}; } catch (e) { return {}; }
    }

    function saveFold(id, open) {
        var folds = loadFolds();
        folds[id] = open;
        try { localStorage.setItem(FOLD_KEY, JSON.stringify(folds)); } catch (e) { /* this page only */ }
    }

    /** Saves a named field of a section when it changes */
    function listen(box) {
        box.addEventListener('change', function (evt) {
            var el = evt.target, name = el.name || '';
            if (!name || name === 'csrf_token') { return; }
            name = name.replace(/__none$/, '');
            var value = valueOf(box, name);
            if (value === undefined) { return; }
            if (name === 'title') {
                ed.send([{ op: 'set_map', title: value }]);
                return;
            }
            var props = {};
            props[name] = value;
            sendProps(props);
        });
    }

    /**
     * One section of the map properties, as a fold. `forItem` shows it open
     * for a selected legend, title or timestamp, without remembering it.
     */
    function section(id, forItem) {
        var m = ed.state.model && ed.state.model.map;
        if (!m || !m.props) { return null; }
        var label = SECTIONS.filter(function (x) { return x[0] === id; })[0];
        var open = forItem ? true : !!loadFolds()[id];
        var details = h('details', { 'class': 'map-sec', 'data-section': id, open: open }, [
            h('summary', {}, [h('span', { text: label ? label[1] : id }), h('span', { 'class': 'map-sec-value', text: summary(id, m.props, m) || '' })]),
            h('div', { 'class': 'map-sec-body' }, panes(id, m.props, m, forItem))
        ]);
        if (!forItem) {
            details.addEventListener('toggle', function () { saveFold(id, details.open); });
        }
        listen(details);
        return details;
    }

    /** Every section, for the map panel */
    function sections() {
        return SECTIONS.map(function (s) { return section(s[0], false); }).filter(Boolean);
    }

    /** Selects nothing, so the panel shows the map; opens a section if asked */
    function open(id) {
        ed.state.selection.clear();
        ed.refresh();
        if (id) { saveFold(id, true); ed.renderInspector(); }
        var app = document.getElementById('app');
        if (app.classList.contains('no-inspector')) { ed.toast(t('The properties panel is hidden: show it from ⋯ › Toolbars and panels.')); }
        var target = id ? document.querySelector('#inspector .map-sec[data-section="' + id + '"]') : document.querySelector('#inspector-body input');
        if (target) {
            target.scrollIntoView({ block: 'start' });
            (id ? target.querySelector('summary') : target).focus();
        }
    }

    ed.commands['map-properties'] = function () { open(); };
    ed.openMapProperties = open;
    ed.mapSections = sections;
    ed.mapSection = section;
    // the panel was drawn before this script was loaded
    if (ed.state.model) { ed.renderInspector(); }
})();
