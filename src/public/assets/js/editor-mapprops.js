/**
 * Map properties of the SVG editor: the title, legend and timestamp texts,
 * fonts and colours, the background colour, the colour scale of the links
 * (with presets) and the link defaults (the DEFAULT link). Opened from the top bar, from the Map panel, or from a
 * selected legend, timestamp or title.
 */
(function () {
    'use strict';

    var ed = window.WMEditor;
    var t = ed.t, h = ed.h;
    var dialog = document.getElementById('mapprops-dialog');
    var form = dialog.querySelector('form');
    var tabs = dialog.querySelector('.mapprops-tabs');
    var body = dialog.querySelector('.mapprops-body');
    var current = 'general';
    var start = null; // values when the dialog opened
    var scaleRows = []; // the steps of the colour scale while the dialog is open
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

        render();
        return [
            h('p', { 'class': 'mapprops-explain', text: t('The colour of a link follows its load, in % of its bandwidth: each step gives the colour from one value to the next. A 0 to 0 step colours the links without traffic. The legend shows these steps.') }),
            field(t('Start from a preset'), preset, t('Replaces the steps below. Nothing is saved before Apply.')),
            bar,
            list,
            h('div', { 'class': 'pair' }, [add, msg])
        ];
    }

    var SECTIONS = [
        ['general', t('General')], ['title', t('Title')], ['legend', t('Legend')], ['scale', t('Link colours')],
        ['timestamp', t('Timestamp')], ['links', t('Link defaults')], ['fonts', t('Fonts')]
    ];

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
            Array.prototype.forEach.call(body.querySelectorAll('select.font-select'), function (sel) {
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

    function build(p, m) {
        var panes = {
            general: [
                field(t('Map title'), h('input', { name: 'title', value: m.title, autocomplete: 'off' })),
                field(t('Background colour'), colour('bg_colour', p.bg_colour, false), t('Seen where the background image does not cover the map.'))
            ],
            title: [
                field(t('Map title'), h('input', { name: 'title_again', value: m.title, autocomplete: 'off' })),
                field(t('Font'), fontSelect('title_font', p.title_font)),
                field(t('Colour'), colour('title_colour', p.title_colour, false))
            ],
            legend: [
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
            ],
            timestamp: [
                field(t('Timestamp text'), h('input', { name: 'stamp_text', value: p.stamp_text, autocomplete: 'off' }),
                    t('%d day, %m month, %b month name, %Y year, %H hour, %M minutes, %S seconds. Example: Created: %b %d %Y %H:%M:%S')),
                field(t('Style'), choice('stamp_style', p.stamp_style, [
                    ['plain', t('Plain text')], ['pill', t('On a rounded label, in the legend colours')]
                ])),
                field(t('Font'), fontSelect('time_font', p.time_font)),
                field(t('Colour'), colour('time_colour', p.time_colour, false))
            ],
            links: [
                h('p', { 'class': 'mapprops-explain', text: t('The values a link uses when it has none of its own. Links that had the old default value take the new one; links with their own value keep it.') }),
                field(t('Bandwidth'), h('input', { name: 'link_bandwidth', value: p.link_bandwidth, autocomplete: 'off', placeholder: '1G' }),
                    t('In bits per second: 100M, 1G, 10G. The link load in % is its traffic divided by this.')),
                field(t('Width (px)'), h('input', { name: 'link_width', value: p.link_width, autocomplete: 'off' }), t('A number, or auto to follow the bandwidth.')),
                field(t('Label'), choice('link_labelstyle', p.link_labelstyle, [
                    ['percent', t('Load in %')], ['bits', t('Traffic in bits/s')], ['unformatted', t('Raw value')], ['none', t('No label')]
                ])),
                field(t('Label font'), fontSelect('link_bwfont', p.link_bwfont))
            ],
            scale: scalePane(m.scale),
            fonts: fontsPane()
        };

        tabs.replaceChildren.apply(tabs, SECTIONS.map(function (s) {
            return h('button', { type: 'button', role: 'tab', 'data-section': s[0], text: s[1], onclick: function () { show(s[0]); } });
        }));
        body.replaceChildren.apply(body, SECTIONS.map(function (s) {
            return h('fieldset', { 'data-section': s[0], role: 'tabpanel' }, panes[s[0]]);
        }));

        // the title is in two tabs; keep them the same
        var t1 = form.elements.title, t2 = form.elements.title_again;
        t1.addEventListener('input', function () { t2.value = t1.value; });
        t2.addEventListener('input', function () { t1.value = t2.value; });
    }

    function show(section) {
        current = section;
        Array.prototype.forEach.call(tabs.children, function (b) { b.setAttribute('aria-selected', b.dataset.section === section ? 'true' : 'false'); });
        Array.prototype.forEach.call(body.children, function (f) { f.hidden = f.dataset.section !== section; });
    }

    /** The values of the form, as the server names them */
    function values() {
        var out = {};
        // only the fields of the tabs: the page adds a CSRF field to every form
        Array.prototype.forEach.call(body.querySelectorAll('[name]'), function (el) {
            if (!el.name || el.name === 'title_again' || /__none$/.test(el.name)) { return; }
            if (el.type === 'checkbox') { out[el.name] = el.checked; return; }
            if (el.type === 'color') {
                var none = form.elements[el.name + '__none'];
                out[el.name] = none && none.checked ? 'none' : el.value.toLowerCase();
                return;
            }
            out[el.name] = el.value;
        });
        return out;
    }

    function open(section) {
        var m = ed.state.model && ed.state.model.map;
        if (!m || !m.props) { return; }
        build(m.props, m);
        show(section || current);
        start = values();
        dialog.showModal();
        var first = body.querySelector('fieldset:not([hidden]) input, fieldset:not([hidden]) select');
        if (first) { first.focus(); }
    }

    form.addEventListener('submit', function (evt) {
        if (evt.submitter && evt.submitter.value === 'cancel') { return; }
        evt.preventDefault();
        var now = values(), props = {}, op = { op: 'set_map' }, changed = false, ops = [];
        Object.keys(now).forEach(function (k) {
            if (String(now[k]) === String(start[k])) { return; }
            changed = true;
            if (k === 'title') { op.title = now[k]; } else { props[k] = now[k]; }
        });
        if (Object.keys(props).length) { op.props = props; }
        if (changed) { ops.push(op); }
        if (scaleJson(scaleRows) !== scaleStart) {
            ops.push({ op: 'set_scale', steps: scaleRows.map(function (r) {
                return { bottom: +r.bottom, top: +r.top, colour: r.colour, colour2: r.colour2 || '', tag: r.tag || '' };
            }) });
        }
        if (!ops.length) { dialog.close(); return; }

        var apply = dialog.querySelector('button.primary'), saved = false;
        apply.disabled = true;
        // send() shows its own error and reloads the map; the select callback runs only on success
        ed.send(ops, function () { saved = true; return undefined; }).then(function () {
            if (saved) {
                dialog.close();
                ed.toast(t('Map properties saved'));
            }
        }).finally(function () { apply.disabled = false; });
    });

    ed.commands['map-properties'] = function () { open(); };
    ed.openMapProperties = open;
})();
