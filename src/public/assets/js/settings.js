/**
 * NetworkWeathermapNG - the Settings space (UI redesign step 6).
 *
 * - Data source form (on its page or in the drawer): the chosen type shows
 *   only its own fields, and the URL is required for Zabbix only.
 * - Data source rows: Test checks the connection on the row; the state dot,
 *   the "tested" time and the message are updated in place, with the request
 *   and response under "Request and response".
 * - On phones the side navigation is a row of chips: the current page is
 *   scrolled into view.
 */
(function () {
    'use strict';

    function t(s) { return (window.WM_T && window.WM_T[s]) || s; }
    function fmt(s, values) {
        return t(s).replace(/:(\w+)/g, function (m, k) { return k in values ? values[k] : m; });
    }

    function setupForm(form) {
        if (form.dataset.ready) return;
        form.dataset.ready = '1';
        const type = form.querySelector('select[name="type"]');
        const url = form.querySelector('input[name="url"]');
        if (!type) return;
        function update() {
            form.setAttribute('data-type', type.value);
            if (url) url.required = type.value === 'zabbix';
        }
        type.addEventListener('change', update);
        update();
    }

    function setup(root) {
        root.querySelectorAll('[data-ds-form]').forEach(setupForm);
    }

    // ------------------------------------------------------------------
    // Test on the row

    function pretty(value) {
        if (value === undefined || value === null || value === '') return '—';
        if (typeof value === 'string') return value;
        try { return JSON.stringify(value, null, 2); } catch (e) { return String(value); }
    }

    function show(row, data) {
        const ok = !!(data && data.success);
        const name = row.dataset.sourceName || '';
        const dot = row.querySelector('.ds-dot');
        if (dot) dot.className = 'ds-dot ' + (ok ? 'is-connected' : 'is-error');
        const state = row.querySelector('.ds-state');
        if (state) state.textContent = ok ? t('Connected') : t('Error');
        const tested = row.querySelector('.ds-tested');
        if (tested) tested.textContent = t('tested just now');

        const detail = ok
            ? (data.version ? fmt('Version :version', {version: data.version}) : (data.message || ''))
            : ((data && data.error) || t('Connection failed'));
        const message = ok
            ? fmt('Connection to “:name” works.', {name: name})
            : fmt('Connection to “:name” failed.', {name: name});

        const box = row.querySelector('.ds-result');
        if (box) {
            box.hidden = false;
            box.classList.toggle('is-error', !ok);
            box.querySelector('.ds-result-text').textContent = message + (detail ? ' ' + detail : '');
            const debug = (data && data.debug) || {};
            const details = box.querySelector('.ds-debug');
            details.hidden = !(debug.curl || debug.request || debug.response);
            box.querySelector('.ds-curl').textContent = pretty(debug.curl);
            box.querySelector('.ds-request').textContent = pretty(debug.request);
            box.querySelector('.ds-response').textContent = pretty(debug.response);
        }
        if (window.WMUI) WMUI.toast(message, ok ? 'success' : 'error');
    }

    function test(button) {
        const row = button.closest('.ds-row');
        if (!row || button.disabled) return;
        const label = button.textContent;
        button.disabled = true;
        button.textContent = t('Testing…');
        fetch('/admin/data-sources/test/' + encodeURIComponent(button.dataset.testSource), {
            credentials: 'same-origin',
            headers: {Accept: 'application/json'}
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function (data) {
            show(row, data || {success: false, error: t('No response returned')});
        }).catch(function (err) {
            show(row, {success: false, error: err.message});
        }).finally(function () {
            button.disabled = false;
            button.textContent = label;
        });
    }

    document.addEventListener('click', function (e) {
        const button = e.target.closest ? e.target.closest('[data-test-source]') : null;
        if (button) test(button);
    });

    document.addEventListener('wm:drawer-load', function (e) { setup(e.target); });

    function ready() {
        setup(document);
        const active = document.querySelector('.settings-nav a.active');
        const nav = document.querySelector('.settings-nav');
        if (active && nav && nav.scrollWidth > nav.clientWidth) {
            nav.scrollLeft = active.offsetLeft - (nav.clientWidth - active.offsetWidth) / 2;
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
