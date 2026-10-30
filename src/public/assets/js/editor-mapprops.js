/**
 * Map properties of the SVG editor: the title, legend and timestamp texts,
 * fonts and colours, the background colour, and the link defaults (the
 * DEFAULT link). Opened from the top bar, from the Map panel, or from a
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

    var SECTIONS = [
        ['general', t('General')], ['title', t('Title')], ['legend', t('Legend')],
        ['timestamp', t('Timestamp')], ['links', t('Link defaults')]
    ];

    function fontSelect(name, value) {
        return h('select', { name: name }, ed.state.model.fonts.map(function (f) {
            return h('option', { value: f, text: f <= 5 ? t('Built-in :n', { n: f }) : t('Font :n', { n: f }), selected: +f === +value });
        }));
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
            ]
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
        var now = values(), props = {}, op = { op: 'set_map' }, changed = false;
        Object.keys(now).forEach(function (k) {
            if (String(now[k]) === String(start[k])) { return; }
            changed = true;
            if (k === 'title') { op.title = now[k]; } else { props[k] = now[k]; }
        });
        if (!changed) { dialog.close(); return; }
        if (Object.keys(props).length) { op.props = props; }

        var apply = dialog.querySelector('button.primary'), saved = false;
        apply.disabled = true;
        // send() shows its own error and reloads the map; the select callback runs only on success
        ed.send([op], function () { saved = true; return undefined; }).then(function () {
            if (saved) {
                dialog.close();
                ed.toast(t('Map properties saved'));
            }
        }).finally(function () { apply.disabled = false; });
    });

    ed.commands['map-properties'] = function () { open(); };
    ed.openMapProperties = open;
})();
