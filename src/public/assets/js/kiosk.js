/**
 * NetworkWeathermapNG - kiosk (/cycle): the maps one after the other.
 *
 * Each map stays for its own time (Settings > Kiosk); a thin bar at the top
 * fills up until the next one. Arrows go to the previous or next map, Space
 * pauses, F switches to fullscreen. The controls fade out after 3 s without
 * movement and come back with the mouse, a touch or a key.
 *
 * Each map is the SVG viewer in a frame (/map/{id}?kiosk=1): it draws the
 * map like the live view and refreshes it by itself. Only the current map
 * and the next one have a frame, so the next one is drawn before it shows
 * and a long kiosk does not keep every map in memory. The keys and the
 * movements made over a frame come back to this page.
 */
(function () {
    'use strict';

    const root = document.getElementById('kiosk');
    if (!root) return;
    const maps = Array.from(root.querySelectorAll('.cycle-map'));
    if (!maps.length) return;

    const standalone = root.dataset.standalone === '1';
    const progress = root.querySelector('.kiosk-progress > span');
    const pauseBtn = root.querySelector('[data-kiosk="pause"]');
    const fsBtn = root.querySelector('[data-kiosk="fullscreen"]');
    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ------------------------------------------------------------------
    // Frames: the current map and the next one

    function frameOf(div) {
        return div.querySelector('iframe');
    }

    function addFrame(div) {
        if (frameOf(div)) return;
        const frame = document.createElement('iframe');
        frame.title = div.dataset.name || '';
        frame.setAttribute('tabindex', '-1');
        frame.addEventListener('load', function () { listenInside(frame); });
        frame.src = div.dataset.src;
        div.appendChild(frame);
    }

    /** Keys and movements over the map act on the kiosk (same origin) */
    function listenInside(frame) {
        let doc;
        try {
            doc = frame.contentDocument;
        } catch (e) {
            return;
        }
        if (!doc) return;
        doc.addEventListener('keydown', onKey);
        ['mousemove', 'pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (ev) {
            doc.addEventListener(ev, wake, {passive: true});
        });
    }

    function keepFrames() {
        const keep = [current, (current + 1) % maps.length];
        maps.forEach(function (div, k) {
            if (keep.indexOf(k) !== -1) {
                addFrame(div);
            } else if (frameOf(div)) {
                frameOf(div).remove();
            }
        });
    }

    let current = 0;
    let paused = false;
    let timer = 0;
    let remaining = 0;     // ms left for the current map
    let startedAt = 0;

    function duration(i) {
        return Math.max(5, parseInt(maps[i].dataset.duration, 10) || 30) * 1000;
    }

    function setIcon(btn, name) {
        const use = btn.querySelector('use');
        // keep the versioned sprite address the page gave
        use.setAttribute('href', use.getAttribute('href').split('#')[0] + '#' + name);
    }

    function show(i) {
        current = (i + maps.length) % maps.length;
        keepFrames();
        maps.forEach(function (m, k) {
            m.classList.toggle('active', k === current);
            m.setAttribute('aria-hidden', k === current ? 'false' : 'true');
        });
        root.querySelector('.kiosk-current').textContent = current + 1;
        root.querySelector('.kiosk-name').textContent = maps[current].dataset.name || '';
    }

    /** Run the time of the current map from `ms` left (all of it when omitted) */
    function run(ms) {
        clearTimeout(timer);
        const total = duration(current);
        remaining = ms === undefined ? total : ms;
        if (maps.length < 2) {
            root.classList.add('kiosk-single');
            return;
        }
        if (progress && !reduce) {
            // restart the fill from where it is
            progress.style.animation = 'none';
            void progress.offsetWidth;
            progress.style.animation = 'kiosk-fill ' + (total / 1000) + 's linear forwards';
            progress.style.animationDelay = '-' + ((total - remaining) / 1000) + 's';
            progress.style.animationPlayState = paused ? 'paused' : 'running';
        }
        if (paused) return;
        startedAt = Date.now();
        timer = setTimeout(function () { go(current + 1); }, remaining);
    }

    function go(i) {
        show(i);
        run();
    }

    function setPaused(on) {
        if (on === paused) return;
        paused = on;
        if (paused) {
            clearTimeout(timer);
            remaining = Math.max(0, remaining - (Date.now() - startedAt));
            if (progress) progress.style.animationPlayState = 'paused';
        } else {
            run(remaining);
        }
        root.classList.toggle('kiosk-paused', paused);
        pauseBtn.setAttribute('aria-pressed', paused ? 'true' : 'false');
        pauseBtn.title = paused ? pauseBtn.dataset.play : pauseBtn.dataset.pause;
        pauseBtn.setAttribute('aria-label', pauseBtn.title.replace(/\s*\(.*\)$/, ''));
        setIcon(pauseBtn, paused ? 'play' : 'pause');
    }

    // ------------------------------------------------------------------
    // Fullscreen: the browser's own, or the page without the shell

    function isFullscreen() {
        return standalone || document.fullscreenElement === root;
    }

    function toggleFullscreen() {
        if (standalone) {
            window.location.href = root.dataset.exitUrl;
            return;
        }
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(function () {});
            return;
        }
        if (!root.requestFullscreen) {
            window.location.href = root.dataset.fullscreenUrl;
            return;
        }
        root.requestFullscreen().catch(function () { window.location.href = root.dataset.fullscreenUrl; });
    }

    document.addEventListener('fullscreenchange', function () {
        const on = isFullscreen();
        root.classList.toggle('wmv-fullscreen', on);
        if (fsBtn) {
            fsBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            fsBtn.title = on ? fsBtn.dataset.leave : fsBtn.dataset.enter;
            setIcon(fsBtn, on ? 'minimize' : 'maximize');
        }
    });

    // ------------------------------------------------------------------
    // Controls that fade out

    let idle = 0;
    function wake() {
        root.classList.remove('wmv-idle');
        clearTimeout(idle);
        idle = setTimeout(function () { root.classList.add('wmv-idle'); }, 3000);
    }
    ['mousemove', 'pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, wake, {passive: true});
    });

    root.querySelectorAll('[data-kiosk]').forEach(function (b) {
        b.addEventListener('click', function (e) {
            switch (b.dataset.kiosk) {
                case 'prev': go(current - 1); break;
                case 'next': go(current + 1); break;
                case 'pause': setPaused(!paused); break;
                case 'fullscreen': toggleFullscreen(); break;
                default: return;
            }
            e.preventDefault();
        });
    });

    function onKey(e) {
        const tag = (e.target && e.target.tagName) || '';
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || e.ctrlKey || e.metaKey || e.altKey) return;
        const onButton = tag === 'BUTTON' || tag === 'A';
        switch (e.key) {
            case 'ArrowLeft': go(current - 1); break;
            case 'ArrowRight': go(current + 1); break;
            case ' ':
                if (onButton) return;   // Space presses the focused button
                setPaused(!paused);
                break;
            case 'f': case 'F': toggleFullscreen(); break;
            case 'Escape':
                if (!standalone) return;
                window.location.href = root.dataset.exitUrl;
                break;
            default: return;
        }
        e.preventDefault();
    }
    document.addEventListener('keydown', onKey);

    show(0);
    run();
    wake();
})();
