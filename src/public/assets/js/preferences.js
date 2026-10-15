/*
 * Language and theme, kept in cookies so that pages are rendered with them.
 *   <select data-pref="lang">      changes the language and reloads
 *   <button data-pref="theme">     cycles automatic, light, dark
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
    var ICONS = { '': '◐', light: '☀', dark: '☾' };

    function paint(button) {
        var labels = {};
        try { labels = JSON.parse(button.getAttribute('data-labels') || '{}'); } catch (e) { /* defaults */ }
        var t = currentTheme();
        button.textContent = ICONS[t];
        button.title = labels[t] || t || 'auto';
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
                var next = ORDER[(ORDER.indexOf(currentTheme()) + 1) % ORDER.length];
                if (next) {
                    document.documentElement.setAttribute('data-theme', next);
                } else {
                    document.documentElement.removeAttribute('data-theme');
                }
                setCookie('theme', next);
                document.querySelectorAll('[data-pref="theme"]').forEach(paint);
                document.dispatchEvent(new CustomEvent('wm:theme'));
            });
        });
    });
})();
