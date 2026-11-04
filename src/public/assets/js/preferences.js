/*
 * Language and theme, kept in cookies so that pages are rendered with them.
 *   <select data-pref="lang">               changes the language and reloads
 *   <button data-pref="theme">              cycles automatic, light, dark
 *   <button data-pref="theme" data-theme-value="light">  picks one theme
 *                                           (the user menu: aria-pressed shows the current one)
 */
(function () {
    'use strict';

    function setCookie(name, value) {
        var age = value ? 31536000 : 0;
        document.cookie = name + '=' + encodeURIComponent(value) + ';path=/;max-age=' + age + ';samesite=lax';
    }

    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') || '';
    }

    var ORDER = ['', 'light', 'dark'];
    // Icons of the interface sprite (/assets/icons/ui.svg)
    var ICONS = { '': 'sun-moon', light: 'sun', dark: 'moon' };

    function paint(button) {
        var t = currentTheme();
        if (button.hasAttribute('data-theme-value')) {
            button.setAttribute('aria-pressed', String(button.getAttribute('data-theme-value') === t));
            return;
        }
        var labels = {};
        try { labels = JSON.parse(button.getAttribute('data-labels') || '{}'); } catch (e) { /* defaults */ }
        var use = button.querySelector('use');
        if (use) {
            // keep the versioned sprite address the page gave
            use.setAttribute('href', (use.getAttribute('href') || '').split('#')[0] + '#' + ICONS[t]);
        }
        button.title = labels[t] || t || 'auto';
        button.setAttribute('aria-label', button.title);
    }

    function setTheme(next) {
        if (next) {
            document.documentElement.setAttribute('data-theme', next);
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        setCookie('theme', next);
        document.querySelectorAll('[data-pref="theme"]').forEach(paint);
        document.dispatchEvent(new CustomEvent('wm:theme'));
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-pref="lang"]').forEach(function (select) {
            select.addEventListener('change', function () {
                setCookie('lang', select.value);
                window.location.reload();
            });
        });
        document.querySelectorAll('[data-pref="theme"]').forEach(function (button) {
            paint(button);
            button.addEventListener('click', function () {
                if (button.hasAttribute('data-theme-value')) {
                    setTheme(button.getAttribute('data-theme-value'));
                } else {
                    setTheme(ORDER[(ORDER.indexOf(currentTheme()) + 1) % ORDER.length]);
                }
            });
        });
    });
})();
