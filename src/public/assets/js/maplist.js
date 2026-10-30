/**
 * NetworkWeathermapNG - map list: search by name, favourites (starred maps
 * first, stored per user on the server) and the state badge of each map.
 */
(function () {
    'use strict';

    function t(s) { return (window.WM_T && window.WM_T[s]) || s; }
    function fmt(s, params) {
        return t(s).replace(/:([a-z]+)/g, function (m, k) { return params[k] !== undefined ? params[k] : m; });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const grid = document.getElementById('mapsGrid');
        if (!grid) return;
        const search = document.getElementById('mapsSearch');
        const count = document.getElementById('mapsCount');
        const noMatch = document.getElementById('mapsNoMatch');
        const cards = Array.from(grid.querySelectorAll('.map-card'));

        // ------------------------------------------------------------------
        // Search

        function norm(s) {
            return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        }

        function filter() {
            const words = norm(search.value).split(/\s+/).filter(Boolean);
            let shown = 0;
            cards.forEach(function (c) {
                const name = norm(c.dataset.name || '');
                const ok = words.every(function (w) { return name.indexOf(w) !== -1; });
                c.hidden = !ok;
                if (ok) shown++;
            });
            noMatch.hidden = shown > 0;
            count.textContent = words.length ? fmt(':shown of :total maps', {shown: shown, total: cards.length}) : '';
            try { sessionStorage.setItem('wm-maps-search', search.value); } catch (e) { /* private mode */ }
        }

        if (search) {
            try { search.value = sessionStorage.getItem('wm-maps-search') || ''; } catch (e) { /* private mode */ }
            search.addEventListener('input', filter);
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { search.value = ''; filter(); }
                if (e.key === 'Enter') {
                    const first = cards.find(function (c) { return !c.hidden; });
                    if (first) window.location.href = first.querySelector('a.map-link').href;
                }
            });
            if (search.value) filter();
        }

        // ------------------------------------------------------------------
        // Favourites

        function reorder() {
            const sorted = cards.slice().sort(function (a, b) {
                const fa = a.dataset.fav === '1' ? 0 : 1;
                const fb = b.dataset.fav === '1' ? 0 : 1;
                if (fa !== fb) return fa - fb;
                return Number(a.dataset.order) - Number(b.dataset.order);
            });
            sorted.forEach(function (c) { grid.appendChild(c); });
        }

        function setFav(card, on) {
            const btn = card.querySelector('.map-fav');
            card.dataset.fav = on ? '1' : '0';
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.textContent = on ? '★' : '☆';
            btn.title = on ? t('Remove from favourites') : t('Add to favourites');
        }

        grid.addEventListener('click', function (e) {
            const btn = e.target.closest('.map-fav');
            if (!btn) return;
            e.preventDefault();
            const card = btn.closest('.map-card');
            const on = card.dataset.fav !== '1';
            setFav(card, on);
            reorder();
            btn.focus();
            fetch('/map/' + card.dataset.mapId + '/favourite', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({favourite: on})
            }).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
            }).catch(function () {
                setFav(card, !on);
                reorder();
                if (window.WM && WM.notify) WM.notify(t('The favourite could not be saved'), 'error');
            });
        });

        // ------------------------------------------------------------------
        // State badges: maps with no recent data render are asked for it,
        // two at a time

        // Time of a render, in the map's time zone (the browser's own if it does not know it)
        function formatTime(seconds, timeZone) {
            const date = new Date(seconds * 1000);
            try {
                return date.toLocaleString([], timeZone ? {timeZone: timeZone} : {});
            } catch (e) {
                return date.toLocaleString();
            }
        }

        function showState(card, st) {
            const badge = card.querySelector('.map-state');
            if (!badge) return;
            const dot = badge.querySelector('.map-state-dot');
            const text = badge.querySelector('.map-state-text');
            let title;
            if (st.max === null || st.max === undefined) {
                title = t('No data yet');
                text.textContent = '';
                dot.style.background = '';
            } else {
                title = fmt('Max load :pct %', {pct: st.max}) + (st.link ? ' (' + st.link + ')' : '');
                text.textContent = Math.round(st.max) + ' %';
                dot.style.background = st.colour || '';
            }
            if (st.time) {
                title += ' — ' + fmt('Updated :time', {time: formatTime(st.time, card.dataset.timezone)});
            }
            badge.title = title;
            badge.setAttribute('aria-label', title);
            badge.classList.remove('map-state-stale');
        }

        const queue = cards.filter(function (c) { return c.dataset.stale === '1'; });
        function next() {
            const card = queue.shift();
            if (!card) return;
            fetch('/map/' + card.dataset.mapId + '/status', {cache: 'no-store', credentials: 'same-origin'})
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (st) { if (st) showState(card, st); })
                .catch(function () {})
                .finally(next);
        }
        next();
        next();
    });
})();
