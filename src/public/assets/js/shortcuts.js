/**
 * NetworkWeathermapNG - keyboard shortcuts of the signed-in user
 *
 * window.WM_KEYS (written by the page, App\Services\KeyboardShortcuts) maps
 * each action to its keys, for example {"editor.undo": ["Ctrl+Z"]}. The
 * editor, the map viewer and the command palette ask WMKeys whether a key
 * press is one of an action's keys instead of testing keys themselves, so
 * the keys can be changed in the user's profile (/account/shortcuts).
 *
 * Key combinations are written "Ctrl+Alt+Shift+Key". Ctrl stands for Cmd on
 * a Mac. Letters are upper case; Shift is not written before a symbol or a
 * digit, the character already says it.
 *
 * Elements with data-keys="<action>" get the action's keys: a <kbd> shows
 * them, any other element gets them at the end of its title and in
 * aria-keyshortcuts.
 */
(function () {
    'use strict';

    var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
    var MODIFIERS = { Control: 1, Meta: 1, Alt: 1, AltGraph: 1, Shift: 1, OS: 1, CapsLock: 1, Dead: 1, Unidentified: 1, Process: 1 };
    var ARROWS = { ArrowLeft: '←', ArrowRight: '→', ArrowUp: '↑', ArrowDown: '↓' };

    function t(s) {
        var tr = window.WM_T || {};
        return tr[s] || s;
    }

    function bindings() {
        return window.WM_KEYS || {};
    }

    function isLetter(k) {
        return k.length === 1 && k.toUpperCase() !== k.toLowerCase();
    }

    /** The key combination of a keydown event, or null for a lone modifier */
    function combo(e) {
        var k = e.key;
        if (!k || MODIFIERS[k]) { return null; }
        if (k === ' ' || k === 'Spacebar') { k = 'Space'; }
        // Option + a letter types another character on a Mac: use the key itself
        if (e.altKey && k.length === 1 && !isLetter(k) && /^Key[A-Z]$/.test(e.code || '')) { k = e.code.slice(3); }
        var letter = isLetter(k);
        if (letter) { k = k.toUpperCase(); }
        var parts = [];
        if (e.ctrlKey || e.metaKey) { parts.push('Ctrl'); }
        if (e.altKey) { parts.push('Alt'); }
        if (e.shiftKey && (letter || k.length > 1)) { parts.push('Shift'); }
        parts.push(k);
        return parts.join('+');
    }

    function keysOf(id) {
        var k = bindings()[id];
        return Array.isArray(k) ? k : [];
    }

    function withoutShift(c) {
        return c.replace(/(^|\+)Shift\+/, '$1');
    }

    /**
     * True when the event is one of the action's keys. opts.anyShift: Shift
     * held or not (the arrows move by 1 px, by 10 px with Shift).
     */
    function is(e, id, opts) {
        var c = combo(e);
        if (!c) { return false; }
        var list = keysOf(id);
        if (opts && opts.anyShift) {
            c = withoutShift(c);
            return list.some(function (k) { return withoutShift(k) === c; });
        }
        return list.indexOf(c) >= 0;
    }

    /** The modifier of a drag action ('Ctrl', 'Alt', 'Shift' or '') */
    function dragKey(id) {
        return keysOf(id)[0] || '';
    }

    /** True when the drag action's modifier is held during this pointer event */
    function held(e, id) {
        switch (dragKey(id)) {
            case 'Ctrl': return !!(e.ctrlKey || e.metaKey);
            case 'Alt': return !!e.altKey;
            case 'Shift': return !!e.shiftKey;
            default: return false;
        }
    }

    /** A key combination as people read it on this computer */
    function display(c) {
        if (!c) { return ''; }
        var parts = c === '+' ? ['+'] : c.split(/\+(?!$)/);
        var key = parts.pop();
        if (key === 'Space') { key = t('Space'); }
        else if (key === 'ContextMenu') { key = t('Menu key'); }
        else if (ARROWS[key]) { key = ARROWS[key]; }
        if (isMac) {
            var mac = { Ctrl: '⌘', Alt: '⌥', Shift: '⇧' };
            return parts.map(function (m) { return mac[m]; }).join('') + key;
        }
        return parts.concat([key]).join('+');
    }

    /** The keys of an action as people read them, joined with " / " */
    function label(id) {
        return keysOf(id).map(display).join(' / ');
    }

    /** The first key of an action as people read it ('' when it has none) */
    function first(id) {
        return display(keysOf(id)[0] || '');
    }

    /** aria-keyshortcuts value of a combination */
    function aria(c) {
        var parts = c === '+' ? ['+'] : c.split(/\+(?!$)/);
        var key = parts.pop();
        var names = { Ctrl: isMac ? 'Meta' : 'Control', Alt: 'Alt', Shift: 'Shift' };
        if (key === '+') { key = 'Plus'; }
        return parts.map(function (m) { return names[m]; }).concat([key]).join('+');
    }

    /** Show the user's keys on the elements with data-keys */
    function decorate(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('[data-keys]'), function (el) {
            var id = el.getAttribute('data-keys');
            var keys = keysOf(id);
            if (el.tagName === 'KBD') {
                el.textContent = keys.length ? display(keys[0]) : '';
                el.hidden = !keys.length;
                return;
            }
            if (!el.hasAttribute('data-title')) { el.setAttribute('data-title', el.getAttribute('title') || ''); }
            var base = el.getAttribute('data-title');
            if (base) { el.setAttribute('title', keys.length ? base + ' (' + display(keys[0]) + ')' : base); }
            if (keys.length) {
                el.setAttribute('aria-keyshortcuts', keys.map(aria).join(' '));
            } else {
                el.removeAttribute('aria-keyshortcuts');
            }
        });
    }

    window.WMKeys = {
        isMac: isMac,
        combo: combo,
        is: is,
        keys: keysOf,
        held: held,
        dragKey: dragKey,
        display: display,
        label: label,
        first: first,
        decorate: decorate
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { decorate(); });
    } else {
        decorate();
    }
})();
