/**
 * Sends the CSRF token with every state-changing request made by the page:
 * - adds a hidden "_token" field to forms posted to this site;
 * - adds the "X-CSRF-Token" header to fetch() and XMLHttpRequest calls;
 * - turns links marked data-method="post" into small POST forms, with an
 *   optional confirmation from data-confirm.
 */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) {
        return;
    }
    var token = meta.getAttribute('content');

    function sameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function isUnsafe(method) {
        return !/^(GET|HEAD|OPTIONS)$/i.test(method || 'GET');
    }

    var originalFetch = window.fetch;
    if (originalFetch) {
        window.fetch = function (input, init) {
            init = init || {};
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var method = init.method || (input && input.method) || 'GET';
            if (isUnsafe(method) && sameOrigin(url)) {
                var headers = new Headers(init.headers || (input && input.headers) || {});
                headers.set('X-CSRF-Token', token);
                init.headers = headers;
            }
            return originalFetch.call(this, input, init);
        };
    }

    var originalOpen = XMLHttpRequest.prototype.open;
    var originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
        this._csrfNeeded = isUnsafe(method) && sameOrigin(url);
        return originalOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
        if (this._csrfNeeded) {
            this.setRequestHeader('X-CSRF-Token', token);
        }
        return originalSend.apply(this, arguments);
    };

    function addField(form) {
        if (!isUnsafe(form.getAttribute('method')) || !sameOrigin(form.action || window.location.href)) {
            return;
        }
        if (!form.querySelector('input[name="_token"]')) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_token';
            input.value = token;
            form.appendChild(input);
        }
    }

    document.addEventListener('submit', function (event) {
        addField(event.target);
    }, true);

    // form.submit() does not fire the submit event
    var originalSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        addField(this);
        return originalSubmit.call(this);
    };

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-method="post"]') : null;
        if (!link) {
            return;
        }
        event.preventDefault();
        var question = link.getAttribute('data-confirm');
        if (question && !window.confirm(question)) {
            return;
        }
        var form = document.createElement('form');
        form.method = 'post';
        form.action = link.href;
        form.style.display = 'none';
        document.body.appendChild(form);
        form.submit();
    });
})();
