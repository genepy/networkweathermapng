/*
 * NetworkWeathermapNG - colour picker in the style of the application
 *
 * Every <input type="color"> of the page opens this picker instead of the
 * browser's own dialog (an input marked data-native keeps the browser's).
 * The input stays the source of truth: the picker writes its value and
 * fires the same events the browser does, "input" while the colour moves
 * and "change" once it is chosen, so the code around the input does not
 * need to know about the picker.
 *
 * The picker has a saturation and brightness area, a hue slider, a hex
 * field, and swatches: the colours already used on the map (the editor's
 * WMEditor.mapColours()), the colours of the map themes
 * (WM_EDITOR.themeColours), a basic palette and the colours picked
 * recently (this browser, localStorage wm-colour-recent).
 *
 * An input marked data-none also gets a *None* button: it fires
 * "wm-colour-none" on the input, and the code around it says what no
 * colour means there.
 *
 * Closing the picker by a click outside keeps the colour; Escape puts the
 * colour back as it was. Texts come from window.WM_T, English otherwise.
 */
(function () {
    'use strict';

    function t(s, params) {
        var out = (window.WM_T && window.WM_T[s]) || s;
        Object.keys(params || {}).forEach(function (k) { out = out.split(':' + k).join(params[k]); });
        return out;
    }

    var RECENT_KEY = 'wm-colour-recent';
    var RECENT_MAX = 10;
    var MAP_MAX = 20;

    /** A small palette of clear colours, then greys */
    var BASIC = [
        '#dc2626', '#ea580c', '#f59e0b', '#eab308', '#84cc16', '#16a34a', '#14b8a6', '#06b6d4',
        '#0ea5e9', '#2563eb', '#4f46e5', '#7c3aed', '#c026d3', '#db2777',
        '#ffffff', '#e2e8f0', '#94a3b8', '#64748b', '#334155', '#000000'
    ];

    // ------------------------------------------------------------------
    // Colour maths: hex <-> rgb <-> hsv (h 0..360, s and v 0..1)

    /** "#abc", "abc", "#aabbcc" or "aabbcc" as "#aabbcc", null otherwise */
    function normHex(s) {
        var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(s || '').trim());
        if (!m) { return null; }
        var x = m[1].toLowerCase();
        if (x.length === 3) { x = x[0] + x[0] + x[1] + x[1] + x[2] + x[2]; }
        return '#' + x;
    }

    function hexToHsv(hex) {
        var n = parseInt(hex.slice(1), 16);
        var r = (n >> 16 & 255) / 255, g = (n >> 8 & 255) / 255, b = (n & 255) / 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min, h = 0;
        if (d) {
            if (max === r) { h = ((g - b) / d) % 6; } else if (max === g) { h = (b - r) / d + 2; } else { h = (r - g) / d + 4; }
            h = (h * 60 + 360) % 360;
        }
        return { h: h, s: max ? d / max : 0, v: max };
    }

    function hsvToHex(c) {
        var f = function (n) {
            var k = (n + c.h / 60) % 6;
            var x = c.v - c.v * c.s * Math.max(0, Math.min(k, 4 - k, 1));
            return ('0' + Math.round(x * 255).toString(16)).slice(-2);
        };
        return '#' + f(5) + f(3) + f(1);
    }

    // ------------------------------------------------------------------
    // Recent colours

    function recent() {
        try {
            var list = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
            return Array.isArray(list) ? list.map(normHex).filter(Boolean) : [];
        } catch (e) {
            return [];
        }
    }

    function remember(hex) {
        var list = [hex].concat(recent().filter(function (c) { return c !== hex; })).slice(0, RECENT_MAX);
        try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch (e) { /* private window */ }
    }

    // ------------------------------------------------------------------
    // The picker (one for the page, moved next to the input it serves)

    var pop = null, area, thumb, hue, hexField, was, now, noneButton, groups;
    var cur = null;          // { input, start, hsv }
    var lastClosed = null;   // { input, at }: a click on the same input closes it, not reopens it

    function el(tag, cls, attrs) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        return e;
    }

    function build() {
        pop = el('div', 'wm-colour', { popover: 'auto', role: 'dialog', 'aria-label': t('Choose a colour') });

        area = el('div', 'wmc-area', { tabindex: '0', role: 'slider', 'aria-label': t('Saturation and brightness'),
            title: t('Saturation (left to right) and brightness (bottom to top). Arrow keys move it, Shift for bigger steps.') });
        thumb = el('span', 'wmc-thumb');
        area.appendChild(thumb);

        hue = el('input', 'wmc-hue', { type: 'range', min: '0', max: '359', step: '1', 'aria-label': t('Hue') });

        var row = el('div', 'wmc-row');
        var preview = el('span', 'wmc-preview', { 'aria-hidden': 'true' });
        was = el('button', 'wmc-was', { type: 'button', title: t('Before: click to go back to it'), tabindex: '-1' });
        now = el('span', 'wmc-now');
        preview.append(was, now);
        var hexLabel = el('label', 'wmc-hex');
        hexField = el('input', '', { type: 'text', maxlength: '7', spellcheck: 'false', autocomplete: 'off', 'aria-label': t('Hex code') });
        hexLabel.append(hexField);
        noneButton = el('button', 'wmc-none', { type: 'button', title: t('No colour') });
        noneButton.textContent = t('None');
        row.append(preview, hexLabel, noneButton);

        groups = el('div', 'wmc-groups');
        pop.append(area, hue, row, groups);

        // area: drag, or arrow keys
        area.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) { return; }
            area.setPointerCapture(e.pointerId);
            pickAt(e);
            area.focus();
        });
        area.addEventListener('pointermove', function (e) {
            if (area.hasPointerCapture(e.pointerId)) { pickAt(e); }
        });
        area.addEventListener('keydown', function (e) {
            var step = e.shiftKey ? 0.1 : 0.01, c = cur.hsv;
            var moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
            if (moves[e.key]) {
                e.preventDefault();
                set({ h: c.h, s: clamp(c.s + moves[e.key][0]), v: clamp(c.v + moves[e.key][1]) });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                close(true);
            }
        });
        hue.addEventListener('input', function () {
            set({ h: +hue.value, s: cur.hsv.s, v: cur.hsv.v });
        });
        hexField.addEventListener('input', function () {
            var hex = normHex(hexField.value);
            if (hex && hexField.value.replace('#', '').length === 6) { set(hexToHsv(hex), true); }
        });
        hexField.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') { return; }
            e.preventDefault();
            var hex = normHex(hexField.value);
            if (hex) { set(hexToHsv(hex), true); close(true); }
        });
        hexField.addEventListener('blur', function () {
            if (cur) { hexField.value = cur.input.value; }
        });
        was.addEventListener('click', function () { set(hexToHsv(cur.start)); });
        noneButton.addEventListener('click', function () {
            var input = cur.input;
            close(false);
            input.dispatchEvent(new CustomEvent('wm-colour-none', { bubbles: true }));
        });
        groups.addEventListener('click', function (e) {
            var b = e.target.closest('.wmc-swatch');
            if (!b) { return; }
            set(hexToHsv(b.dataset.colour));
            close(true);
        });
        // closed by a click outside (light dismiss): the colour stays
        pop.addEventListener('toggle', function (e) {
            if (e.newState === 'closed' && cur) { finish(true); }
        });
    }

    function clamp(x) { return Math.max(0, Math.min(1, x)); }

    function pickAt(e) {
        var r = area.getBoundingClientRect();
        set({ h: cur.hsv.h, s: clamp((e.clientX - r.left) / r.width), v: clamp(1 - (e.clientY - r.top) / r.height) });
    }

    /** New colour: draw it, write it in the input, fire "input" */
    function set(hsv, fromHex) {
        cur.hsv = hsv;
        var hex = hsvToHex(hsv);
        draw(hex, fromHex);
        if (cur.input.value !== hex) {
            cur.input.value = hex;
            cur.input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    function draw(hex, keepHexField) {
        var c = cur.hsv;
        area.style.setProperty('--wmc-hue', 'hsl(' + Math.round(c.h) + ' 100% 50%)');
        thumb.style.left = (c.s * 100) + '%';
        thumb.style.top = ((1 - c.v) * 100) + '%';
        thumb.style.background = hex;
        area.setAttribute('aria-valuetext', t('Saturation :s %, brightness :v %', { s: Math.round(c.s * 100), v: Math.round(c.v * 100) }));
        hue.value = String(Math.round(c.h) % 360);
        now.style.background = hex;
        if (!keepHexField) { hexField.value = hex; }
        groups.querySelectorAll('.wmc-swatch').forEach(function (b) {
            b.setAttribute('aria-pressed', b.dataset.colour === hex ? 'true' : 'false');
        });
    }

    function swatches(title, colours) {
        if (!colours.length) { return null; }
        var box = el('div', 'wmc-group');
        var head = el('p', 'wmc-title');
        head.textContent = title;
        var list = el('div', 'wmc-swatches', { role: 'group', 'aria-label': title });
        colours.forEach(function (c) {
            var b = el('button', 'wmc-swatch', { type: 'button', title: c, 'aria-label': c, 'data-colour': c });
            b.style.setProperty('--wmc-c', c);
            list.appendChild(b);
        });
        box.append(head, list);
        return box;
    }

    /** Names of the map themes (App\Services\Editor\MapThemes) */
    var THEME_NAMES = { night: t('Night'), day: t('Day'), ocean: t('Ocean'), contrast: t('High contrast') };

    function themeTitle(name) {
        return t('Theme: :name', { name: THEME_NAMES[name] || name });
    }

    function fillGroups() {
        var parts = [];
        var editor = window.WMEditor;
        var onMap = editor && editor.mapColours ? editor.mapColours().slice(0, MAP_MAX) : [];
        parts.push(swatches(t('On this map'), onMap));
        var themes = (window.WM_EDITOR && window.WM_EDITOR.themeColours) || {};
        Object.keys(themes).forEach(function (name) {
            parts.push(swatches(themeTitle(name), (themes[name] || []).map(normHex).filter(Boolean)));
        });
        parts.push(swatches(t('Basic'), BASIC));
        parts.push(swatches(t('Recent'), recent()));
        groups.replaceChildren.apply(groups, parts.filter(Boolean));
    }

    function place(input) {
        var r = input.getBoundingClientRect();
        var w = pop.offsetWidth, h = pop.offsetHeight, gap = 6;
        var left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
        var top = r.bottom + gap;
        if (top + h > window.innerHeight - 8) { top = Math.max(8, r.top - gap - h); }
        pop.style.left = Math.max(8, left) + 'px';
        pop.style.top = top + 'px';
    }

    function open(input) {
        if (!pop) { build(); }
        if (cur) { close(true); }
        var start = normHex(input.value) || '#000000';
        cur = { input: input, start: start, hsv: hexToHsv(start) };
        // inside a modal dialog the picker must be in it, or it would be inert
        var host = input.closest('dialog[open]') || document.body;
        if (pop.parentNode !== host) { host.appendChild(pop); }
        noneButton.hidden = !('none' in input.dataset);
        was.style.background = start;
        fillGroups();
        draw(start);
        pop.showPopover();
        place(input);
        input.setAttribute('aria-expanded', 'true');
        area.focus();
    }

    /** Close: keep the colour (fire "change" if it changed) or put the first one back */
    function close(keep) {
        if (!cur) { return; }
        var c = cur;
        finish(keep);
        if (pop.matches(':popover-open')) { pop.hidePopover(); }
        if (c.input.isConnected) { c.input.focus(); }
    }

    function finish(keep) {
        var c = cur;
        cur = null;
        lastClosed = { input: c.input, at: Date.now() };
        c.input.setAttribute('aria-expanded', 'false');
        if (!keep && c.input.value !== c.start) {
            c.input.value = c.start;
            c.input.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }
        if (keep && c.input.value !== c.start) {
            remember(c.input.value);
            c.input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // Escape puts the colour back; first in line, before the page's own keys
    window.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !cur) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        close(false);
    }, true);

    document.addEventListener('click', function (e) {
        var input = e.target;
        if (!input || input.tagName !== 'INPUT' || input.type !== 'color' || 'native' in input.dataset) { return; }
        if (typeof HTMLElement === 'undefined' || !HTMLElement.prototype.showPopover) { return; }
        e.preventDefault();
        if (input.disabled) { return; }
        // the click that closed the picker (outside it) lands on this very input
        if (lastClosed && lastClosed.input === input && Date.now() - lastClosed.at < 300) { return; }
        open(input);
    }, true);

    window.WMColour = { open: open, close: close, normHex: normHex, hexToHsv: hexToHsv, hsvToHex: hsvToHex };
})();
