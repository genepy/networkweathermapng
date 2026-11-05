/**
 * NetworkWeathermapNG - the Maps page: thumbnails or list (remembered by the
 * browser), filter chips (all, favourites, a group, over 80 %), search,
 * favourites (starred maps first, stored per user on the server), the state
 * badge of each map, and for editors the actions on the maps (menus, move
 * dialog, drag and drop to reorder, bulk selection in the list).
 */
(function () {
    'use strict';

    function t(s) { return (window.WM_T && window.WM_T[s]) || s; }
    function fmt(s, params) {
        return t(s).replace(/:([a-z]+)/g, function (m, k) { return params[k] !== undefined ? params[k] : m; });
    }
    function store(key, value) {
        try {
            if (value === undefined) { return localStorage.getItem(key); }
            localStorage.setItem(key, value);
        } catch (e) { /* private mode */ }
        return null;
    }
    function notify(message) {
        if (window.WMUI) { WMUI.toast(message, 'error'); }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const page = document.getElementById('mapsPage');
        const grid = document.getElementById('mapsGrid');
        if (!page || !grid) return;
        const tbody = document.getElementById('mapsRows');
        const search = document.getElementById('mapsSearch');
        const count = document.getElementById('mapsCount');
        const noMatch = document.getElementById('mapsNoMatch');
        const chips = Array.from(document.querySelectorAll('#mapsChips .chip'));
        const busyAt = Number(page.dataset.busy || 80);

        // One entry per map: its thumbnail and its row share the same data
        const items = Array.from(grid.querySelectorAll('.map-card')).map(function (card) {
            return {
                id: card.dataset.mapId,
                card: card,
                row: tbody ? tbody.querySelector('tr[data-map-id="' + card.dataset.mapId + '"]') : null
            };
        });
        function each(item, fn) { [item.card, item.row].forEach(function (el) { if (el) fn(el); }); }
        function itemOf(el) {
            const host = el.closest('[data-map-id]');
            return host ? items.find(function (i) { return i.id === host.dataset.mapId; }) : null;
        }

        // ------------------------------------------------------------------
        // Thumbnails or list

        function setView(view) {
            page.dataset.view = view;
            page.querySelectorAll('.maps-view-switch button').forEach(function (b) {
                b.setAttribute('aria-pressed', b.dataset.view === view ? 'true' : 'false');
            });
            store('wm-maps-view', view);
        }
        page.querySelectorAll('.maps-view-switch button').forEach(function (b) {
            b.addEventListener('click', function () { setView(b.dataset.view); });
        });
        setView(page.dataset.view === 'list' ? 'list' : 'grid');

        // ------------------------------------------------------------------
        // Filter chips and search

        let filter = (chips.find(function (c) { return c.getAttribute('aria-pressed') === 'true'; }) || chips[0] || {dataset: {}}).dataset.filter || 'all';

        function norm(s) {
            return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        }

        function passes(el) {
            if (filter === 'favourites') return el.dataset.fav === '1';
            if (filter === 'busy') return el.dataset.load !== '' && Number(el.dataset.load) >= busyAt;
            if (filter.indexOf('group:') === 0) return el.dataset.group === filter.slice(6);
            return true;
        }

        function apply() {
            const words = norm(search ? search.value : '').split(/\s+/).filter(Boolean);
            let shown = 0;
            items.forEach(function (item) {
                const name = norm(item.card.dataset.name || '');
                const ok = passes(item.card) && words.every(function (w) { return name.indexOf(w) !== -1; });
                each(item, function (el) { el.hidden = !ok; });
                if (!ok && item.row) {
                    const box = item.row.querySelector('input[name="ids[]"]');
                    if (box) box.checked = false;
                }
                if (ok) shown++;
            });
            noMatch.hidden = shown > 0;
            count.textContent = shown < items.length ? fmt(':shown of :total maps', {shown: shown, total: items.length}) : '';
            refreshBulk();
        }

        function counts() {
            const n = {all: items.length, favourites: 0, busy: 0};
            items.forEach(function (item) {
                const c = item.card;
                if (c.dataset.fav === '1') n.favourites++;
                if (c.dataset.load !== '' && Number(c.dataset.load) >= busyAt) n.busy++;
            });
            chips.forEach(function (chip) {
                const f = chip.dataset.filter;
                if (n[f] !== undefined) chip.querySelector('.chip-count').textContent = n[f];
            });
        }

        // The filter goes in the address (a reload or a shared link keeps it),
        // and the phone navigation shows Favourites as the current section
        function setFilter(f) {
            filter = f;
            chips.forEach(function (c) { c.setAttribute('aria-pressed', c.dataset.filter === f ? 'true' : 'false'); });
            const url = new URL(window.location.href);
            url.searchParams.delete('filter');
            url.searchParams.delete('group_id');
            if (f === 'favourites' || f === 'busy') url.searchParams.set('filter', f);
            if (f.indexOf('group:') === 0) url.searchParams.set('group_id', f.slice(6));
            history.replaceState(null, '', url.pathname + url.search);
            document.querySelectorAll('.bottom-nav a[data-section]').forEach(function (a) {
                const on = a.dataset.section === (f === 'favourites' ? 'favourites' : 'maps');
                a.classList.toggle('active', on);
                if (on) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
            });
            apply();
        }

        chips.forEach(function (chip) {
            chip.addEventListener('click', function () { setFilter(chip.dataset.filter); });
        });
        // the bottom navigation's Favourites while already on this page
        document.querySelectorAll('.bottom-nav a[data-section="favourites"], .bottom-nav a[data-section="maps"]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                setFilter(a.dataset.section === 'favourites' ? 'favourites' : 'all');
            });
        });

        if (search) {
            try { search.value = sessionStorage.getItem('wm-maps-search') || ''; } catch (e) { /* private mode */ }
            search.addEventListener('input', function () {
                try { sessionStorage.setItem('wm-maps-search', search.value); } catch (e) { /* private mode */ }
                apply();
            });
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { search.value = ''; search.dispatchEvent(new Event('input')); }
                if (e.key === 'Enter') {
                    const first = items.find(function (i) { return !i.card.hidden; });
                    if (first) window.location.href = first.card.querySelector('a.map-link').href;
                }
            });
        }

        // ------------------------------------------------------------------
        // Favourites

        function reorder() {
            const sorted = items.slice().sort(function (a, b) {
                const fa = a.card.dataset.fav === '1' ? 0 : 1;
                const fb = b.card.dataset.fav === '1' ? 0 : 1;
                if (fa !== fb) return fa - fb;
                return Number(a.card.dataset.order) - Number(b.card.dataset.order);
            });
            sorted.forEach(function (i) {
                grid.appendChild(i.card);
                if (i.row) tbody.appendChild(i.row);
            });
        }

        function setFav(item, on) {
            each(item, function (el) {
                el.dataset.fav = on ? '1' : '0';
                const btn = el.querySelector('.map-fav');
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                btn.title = on ? t('Remove from favourites') : t('Add to favourites');
            });
            counts();
        }

        page.addEventListener('click', function (e) {
            const btn = e.target.closest('.map-fav');
            if (!btn) return;
            e.preventDefault();
            const item = itemOf(btn);
            if (!item) return;
            const on = item.card.dataset.fav !== '1';
            setFav(item, on);
            reorder();
            apply();
            btn.focus();
            fetch('/map/' + item.id + '/favourite', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({favourite: on})
            }).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
            }).catch(function () {
                setFav(item, !on);
                reorder();
                apply();
                notify(t('The favourite could not be saved'));
            });
        });

        // ------------------------------------------------------------------
        // Drag and drop to reorder the thumbnails (editors): a map moves among
        // the maps of its own group, which keep the order positions they held
        // between them. The grip button also moves it with the arrow keys.

        const live = document.getElementById('mapsMoved');
        if (grid.hasAttribute('data-reorder')) {
            let dragged = null;

            const groupItems = function (group) {
                return Array.from(grid.querySelectorAll('.map-card'))
                    .filter(function (c) { return c.dataset.group === group; })
                    .map(itemOf);
            };
            const clearMarks = function () {
                grid.querySelectorAll('.drop-before, .drop-after').forEach(function (c) {
                    c.classList.remove('drop-before', 'drop-after');
                });
            };
            const groupName = function (group) {
                const chip = chips.find(function (c) { return c.dataset.filter === 'group:' + group; });
                return chip ? chip.querySelector('span').textContent : '';
            };

            // the group's maps in their new sequence take the positions they held
            const commit = function (item) {
                const group = item.card.dataset.group;
                const list = groupItems(group);
                const slots = list.map(function (i) { return Number(i.card.dataset.order); }).sort(function (a, b) { return a - b; });
                list.forEach(function (i, k) { each(i, function (el) { el.dataset.order = slots[k]; }); });
                reorder();
                apply();
                const pos = list.indexOf(item) + 1;
                if (live) live.textContent = fmt('Moved to position :pos of :total in :group', {pos: pos, total: list.length, group: groupName(group)});
                fetch('/editor/maps/order', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ids: list.map(function (i) { return Number(i.id); })})
                }).then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                }).catch(function () {
                    notify(t('The new order could not be saved'));
                    setTimeout(function () { window.location.reload(); }, 1500);
                });
            };

            const place = function (item, target, after) {
                if (!target || target === item.card) return;
                grid.insertBefore(item.card, after ? target.nextSibling : target);
                commit(item);
            };

            grid.addEventListener('dragstart', function (e) {
                const handle = e.target.closest ? e.target.closest('.map-drag') : null;
                if (!handle) return;
                dragged = itemOf(handle);
                if (!dragged) return;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', dragged.id);
                e.dataTransfer.setDragImage(dragged.card, 24, 24);
                dragged.card.classList.add('is-dragging');
            });
            grid.addEventListener('dragover', function (e) {
                const card = e.target.closest ? e.target.closest('.map-card') : null;
                if (!dragged || !card || card === dragged.card || card.dataset.group !== dragged.card.dataset.group) {
                    clearMarks();
                    return; // no drop here: another group
                }
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const box = card.getBoundingClientRect();
                const after = e.clientX > box.left + box.width / 2;
                clearMarks();
                card.classList.add(after ? 'drop-after' : 'drop-before');
            });
            grid.addEventListener('drop', function (e) {
                const card = e.target.closest ? e.target.closest('.map-card') : null;
                if (!dragged || !card) return;
                e.preventDefault();
                const after = card.classList.contains('drop-after');
                clearMarks();
                place(dragged, card, after);
            });
            grid.addEventListener('dragend', function () {
                clearMarks();
                if (dragged) dragged.card.classList.remove('is-dragging');
                dragged = null;
            });

            grid.addEventListener('keydown', function (e) {
                const handle = e.target.closest ? e.target.closest('.map-drag') : null;
                if (!handle) return;
                const back = e.key === 'ArrowLeft' || e.key === 'ArrowUp';
                const forward = e.key === 'ArrowRight' || e.key === 'ArrowDown';
                if (!back && !forward) return;
                e.preventDefault();
                const item = itemOf(handle);
                const visible = groupItems(item.card.dataset.group).filter(function (i) { return !i.card.hidden; });
                const k = visible.indexOf(item);
                const other = visible[back ? k - 1 : k + 1];
                if (!other) return;
                place(item, other.card, forward);
                handle.focus();
            });
        }

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

        function showState(item, st) {
            let title;
            const load = (st.max === null || st.max === undefined) ? '' : String(st.max);
            if (load === '') {
                title = t('No data yet');
            } else {
                title = fmt('Max load :pct %', {pct: st.max}) + (st.link ? ' (' + st.link + ')' : '');
            }
            if (st.time) {
                title += ' — ' + fmt('Updated :time', {time: formatTime(st.time, item.card.dataset.timezone)});
            }
            each(item, function (el) {
                el.dataset.load = load;
                const badge = el.querySelector('.map-state');
                if (!badge) return;
                badge.querySelector('.map-state-text').textContent = load === '' ? '' : Math.round(st.max) + ' %';
                badge.querySelector('.map-state-dot').style.background = load === '' ? '' : (st.colour || '');
                badge.title = title;
                badge.setAttribute('aria-label', title);
                badge.classList.remove('map-state-stale');
            });
            counts();
            if (filter === 'busy') apply();
        }

        const queue = items.filter(function (i) { return i.card.dataset.stale === '1'; });
        function next() {
            const item = queue.shift();
            if (!item) return;
            fetch('/map/' + item.id + '/status', {cache: 'no-store', credentials: 'same-origin'})
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (st) { if (st) showState(item, st); })
                .catch(function () {})
                .finally(next);
        }
        next();
        next();

        // ------------------------------------------------------------------
        // Menus: one open at a time; a click elsewhere or Escape closes them

        const menus = Array.from(page.querySelectorAll('.row-menu'));
        function closeMenus(except) { menus.forEach(function (m) { if (m !== except) { m.open = false; } }); }
        menus.forEach(function (m) {
            m.addEventListener('toggle', function () {
                if (m.open) { closeMenus(m); }
                const card = m.closest('.map-card');
                if (card) card.classList.toggle('menu-open', m.open);
            });
        });
        document.addEventListener('click', function (e) { if (!e.target.closest('.row-menu')) { closeMenus(null); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenus(null); } });

        // ------------------------------------------------------------------
        // Move to another group

        const dialog = document.getElementById('moveDialog');
        if (dialog) {
            const select = document.getElementById('moveGroup');
            let moving = null;
            page.addEventListener('click', function (e) {
                const btn = e.target.closest('.menu-move');
                if (!btn) return;
                closeMenus(null);
                moving = btn.dataset.mapId;
                select.value = btn.dataset.group;
                dialog.showModal();
            });
            dialog.querySelector('button[value="cancel"]').addEventListener('click', function () { dialog.close(); });
            dialog.addEventListener('close', function () {
                if (dialog.returnValue !== 'move' || !moving) return;
                fetch('/editor/maps/move/' + moving, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({group_id: select.value})
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (!data.success) throw new Error(data.error || '');
                    window.location.reload();
                }).catch(function (err) {
                    notify(err && err.message ? err.message : t('Failed to move map'));
                });
                dialog.returnValue = '';
            });
        }

        // ------------------------------------------------------------------
        // Bulk selection in the list

        const form = document.getElementById('mapsList');
        const bar = form ? form.querySelector('.bulk-bar') : null;
        const all = document.getElementById('check-all');
        function boxes() {
            return Array.from(form.querySelectorAll('input[name="ids[]"]')).filter(function (b) { return !b.closest('tr').hidden; });
        }
        function refreshBulk() {
            if (!bar) return;
            const visible = boxes();
            const n = form.querySelectorAll('input[name="ids[]"]:checked').length;
            bar.hidden = n === 0;
            form.querySelector('.bulk-count').textContent = fmt(':n selected', {n: n});
            all.checked = n > 0 && n === visible.length;
            all.indeterminate = n > 0 && n < visible.length;
        }
        if (bar) {
            all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); refreshBulk(); });
            form.addEventListener('change', function (e) { if (e.target.name === 'ids[]') refreshBulk(); });
            form.addEventListener('submit', function (e) {
                const btn = e.submitter;
                if (!btn || !btn.dataset.confirmBulk || form.dataset.confirmed) { delete form.dataset.confirmed; return; }
                e.preventDefault();
                const ask = window.WMUI
                    ? WMUI.confirm({ message: btn.dataset.confirmBulk, confirm: btn.textContent.trim(), danger: true })
                    : Promise.resolve(window.confirm(btn.dataset.confirmBulk));
                ask.then(function (ok) {
                    if (!ok) { return; }
                    form.dataset.confirmed = '1';
                    form.requestSubmit(btn);
                });
            });
        }

        counts();
        apply();
    });
})();
