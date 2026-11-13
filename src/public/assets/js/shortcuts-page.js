/**
 * NetworkWeathermapNG - the keyboard shortcuts page (/account/shortcuts)
 *
 * Draws one row per action from window.WM_SHORTCUTS_PAGE (written by
 * AccountController::shortcuts): its keys as chips, a button to add a key
 * (the next key pressed), a button back to the default keys. Drag actions
 * get a list of modifiers instead. Conflicts (the same key twice in an
 * area, or a key of "Everywhere" used in an area) are shown as they
 * appear, and Save stays off until they are fixed. The server checks again
 * (App\Services\KeyboardShortcuts::problems).
 */
(function () {
    'use strict';

    var cfg = window.WM_SHORTCUTS_PAGE;
    var K = window.WMKeys;
    var root = document.getElementById('ks-page');
    if (!cfg || !K || !root) { return; }

    function t(s, params) {
        var out = (window.WM_T && window.WM_T[s]) || s;
        Object.keys(params || {}).forEach(function (k) { out = out.split(':' + k).join(String(params[k])); });
        return out;
    }

    function h(tag, attrs, children) {
        var el = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { el.textContent = attrs[k]; }
            else if (k === 'class') { el.className = attrs[k]; }
            else if (k.slice(0, 2) === 'on') { el.addEventListener(k.slice(2), attrs[k]); }
            else if (attrs[k] !== null && attrs[k] !== false && attrs[k] !== undefined) { el.setAttribute(k, attrs[k] === true ? '' : attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return el;
    }

    function icon(name) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'icon');
        svg.setAttribute('aria-hidden', 'true');
        var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', '/assets/icons/ui.svg#' + name);
        svg.appendChild(use);
        return svg;
    }

    var actions = cfg.actions;
    var byId = {};
    actions.forEach(function (a) { byId[a.id] = a; });
    var saved = copy(cfg.bindings);
    var current = copy(cfg.bindings);
    var capturing = null;   // {id, button}
    var filter = '';

    function copy(b) {
        var out = {};
        actions.forEach(function (a) { out[a.id] = (b[a.id] || a.defaults).slice(); });
        return out;
    }

    function same(a, b) {
        return a.length === b.length && a.every(function (x, i) { return x === b[i]; });
    }

    function dirty() {
        return actions.some(function (a) { return !same(current[a.id], saved[a.id]); });
    }

    /** Problems, as the server finds them (KeyboardShortcuts::problems), on both rows of a conflict */
    function problems() {
        var out = {};
        var owners = {};
        var areas = cfg.areas.map(function (a) { return a.id; });
        actions.forEach(function (a) {
            var keys = current[a.id];
            if (keys.length > cfg.max) { out[a.id] = t('At most :n keys for one action', { n: cfg.max }); return; }
            keys.forEach(function (k) {
                if (a.choices ? a.choices.indexOf(k) < 0
                    : cfg.reserved.indexOf(k) >= 0 || (cfg.areaReserved[a.area] || []).indexOf(k) >= 0) {
                    out[a.id] = t('This key cannot be used');
                    return;
                }
                var scopes = a.area === 'global' ? areas : [a.area, 'global'];
                scopes.forEach(function (s) {
                    var other = owners[s] && owners[s][k];
                    if (other && other !== a.id) {
                        out[a.id] = t('Same key as: :what', { what: byId[other].label });
                        out[other] = out[other] || t('Same key as: :what', { what: a.label });
                    }
                });
                owners[a.area] = owners[a.area] || {};
                owners[a.area][k] = owners[a.area][k] || a.id;
            });
        });
        return out;
    }

    function matches(a) {
        if (!filter) { return true; }
        var hay = (a.label + ' ' + current[a.id].map(K.display).join(' ') + ' ' + current[a.id].join(' ')).toLowerCase();
        return hay.indexOf(filter) >= 0;
    }

    function chip(a, k) {
        return h('span', { 'class': 'ks-key' }, [
            h('kbd', { text: K.display(k) }),
            cfg.canSave ? h('button', {
                type: 'button', 'class': 'ks-remove', 'data-f': 'remove-' + k, 'aria-label': t('Remove :key', { key: K.display(k) }), title: t('Remove :key', { key: K.display(k) }),
                onclick: function () { current[a.id] = current[a.id].filter(function (x) { return x !== k; }); render(); }
            }, [icon('x')]) : null
        ]);
    }

    function keysCell(a) {
        if (a.choices) {
            var sel = h('select', {
                'class': 'form-control ks-drag', 'data-f': 'drag', 'aria-label': a.label, disabled: !cfg.canSave,
                onchange: function () { current[a.id] = sel.value ? [sel.value] : []; render(); }
            }, [h('option', { value: '', text: t('None') })].concat(a.choices.map(function (c) {
                return h('option', { value: c, text: modifierName(c) });
            })));
            sel.value = current[a.id][0] || '';
            return h('td', {}, [sel]);
        }
        var keys = current[a.id];
        var list = h('div', { 'class': 'ks-keys' }, keys.length ? keys.map(function (k) { return chip(a, k); }) : [h('span', { 'class': 'ks-none', text: t('No key') })]);
        if (capturing && capturing.id === a.id) {
            list.appendChild(h('span', { 'class': 'ks-listening', role: 'status' }, [t('Press the keys…'), ' ', h('small', { text: t('Esc cancels') })]));
        }
        return h('td', {}, [list]);
    }

    function row(a, problem) {
        var changed = !same(current[a.id], a.defaults);
        var add = !a.choices && cfg.canSave && current[a.id].length < cfg.max ? h('button', {
            type: 'button', 'class': 'wm-btn ks-add', 'data-f': 'add', 'aria-pressed': capturing && capturing.id === a.id ? 'true' : 'false',
            onclick: function () { listen(a.id); }
        }, [icon('plus'), h('span', { text: t('Add a key') })]) : null;
        var reset = changed && cfg.canSave ? h('button', {
            type: 'button', 'class': 'wm-btn ks-reset', 'data-f': 'reset', title: t('Back to the default keys'), 'aria-label': t('Back to the default keys') + ': ' + a.label,
            onclick: function () { current[a.id] = a.defaults.slice(); render(); }
        }, [icon('history')]) : null;
        return h('tr', { 'class': 'ks-row' + (problem ? ' ks-bad' : '') + (changed ? ' ks-changed' : ''), 'data-action': a.id }, [
            h('th', { scope: 'row' }, [
                h('span', { text: a.label }),
                changed ? h('span', { 'class': 'ks-tag', text: t('Changed') }) : null,
                problem ? h('span', { 'class': 'ks-problem', text: problem }) : null
            ]),
            keysCell(a),
            h('td', {}, [h('div', { 'class': 'ks-actions' }, [add, reset])])
        ]);
    }

    var stateEl = document.getElementById('ks-state');
    var saveBtn = document.getElementById('ks-save');
    var cancelBtn = document.getElementById('ks-cancel');

    function render() {
        var found = problems();
        var active = document.activeElement;
        var focusRow = active && active.closest && active.closest('.ks-row');
        var focus = focusRow && active.dataset.f ? { id: focusRow.dataset.action, f: active.dataset.f } : null;
        root.querySelectorAll('.ks-area').forEach(function (section) {
            var area = section.dataset.area;
            var list = actions.filter(function (a) { return a.area === area && matches(a); });
            var body = section.querySelector('.ks-rows');
            body.replaceChildren.apply(body, list.map(function (a) { return row(a, found[a.id]); }));
            var fixed = section.querySelector('.ks-fixed');
            if (fixed) {
                fixed.querySelectorAll('tr').forEach(function (tr) { tr.hidden = !!filter && tr.textContent.toLowerCase().indexOf(filter) < 0; });
            }
            section.hidden = !list.length && !(fixed && fixed.querySelector('tr:not([hidden])'));
        });
        // keep the focus on the same control after drawing again
        if (focus) { focusOn(focus.id, [focus.f, 'add', 'reset']); }
        var bad = Object.keys(found).length > 0;
        var changed = dirty();
        saveBtn.disabled = !cfg.canSave || !changed || bad;
        cancelBtn.disabled = !changed;
        stateEl.textContent = bad ? t('Fix the keys marked in red first.') : changed ? t('Changes not saved') : '';
        stateEl.className = 'ks-state' + (bad ? ' ks-state-bad' : '');
    }

    /** Put the focus on the first of these controls (data-f) of a row */
    function focusOn(id, names) {
        var tr = root.querySelector('.ks-row[data-action="' + CSS.escape(id) + '"]');
        for (var i = 0; tr && i < names.length; i++) {
            var el = tr.querySelector('[data-f="' + CSS.escape(names[i]) + '"]');
            if (el) { el.focus(); return; }
        }
    }

    /** A modifier as this computer names it */
    function modifierName(m) {
        var mac = { Ctrl: '⌘ Cmd', Alt: '⌥ Option', Shift: '⇧ Shift' };
        return K.isMac ? mac[m] || m : m;
    }

    /** Wait for the next key combination for an action */
    function listen(id) {
        capturing = capturing && capturing.id === id ? null : { id: id };
        render();
    }

    window.addEventListener('keydown', function (e) {
        if (!capturing) { return; }
        var c = K.combo(e);
        var id = capturing.id;
        // Tab still moves the focus, and stops waiting
        if (c === 'Tab' || c === 'Shift+Tab') { capturing = null; render(); return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        if (!c) { return; }   // a modifier alone: wait for the key
        capturing = null;
        if (c !== 'Escape' && current[id].indexOf(c) < 0) {
            current[id].push(c);
        }
        render();
        focusOn(id, ['add', 'remove-' + c, 'reset']);
    }, true);

    document.addEventListener('pointerdown', function (e) {
        if (capturing && !(e.target.closest && e.target.closest('.ks-add'))) { capturing = null; render(); }
    });

    document.getElementById('ks-filter').addEventListener('input', function (e) {
        filter = e.target.value.trim().toLowerCase();
        render();
    });

    cancelBtn.addEventListener('click', function () {
        current = copy(saved);
        capturing = null;
        render();
    });

    document.getElementById('ks-reset-all').addEventListener('click', function () {
        var ask = window.WMUI ? window.WMUI.confirm({ message: t('Put every key back to its default?'), confirm: t('Back to the defaults'), cancel: t('Cancel') })
            : Promise.resolve(window.confirm(t('Put every key back to its default?')));
        ask.then(function (yes) {
            if (!yes) { return; }
            current = {};
            actions.forEach(function (a) { current[a.id] = a.defaults.slice(); });
            capturing = null;
            render();
        });
    });

    function toast(msg, type) {
        if (window.WMUI) { window.WMUI.toast(msg, type); }
    }

    saveBtn.addEventListener('click', function () {
        saveBtn.disabled = true;
        fetch('/account/shortcuts', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ bindings: current })
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false }; });
        }).then(function (res) {
            if (!res.ok) {
                toast(res.error || t('The keys could not be saved'), 'error');
                render();
                return;
            }
            saved = copy(res.bindings);
            current = copy(res.bindings);
            // the keys of this page (the palette) follow at once
            window.WM_KEYS = res.bindings;
            K.decorate();
            toast(t('Keyboard shortcuts saved'), 'success');
            render();
        }).catch(function () {
            toast(t('The keys could not be saved'), 'error');
            render();
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (dirty()) { e.preventDefault(); e.returnValue = ''; }
    });

    render();
})();
