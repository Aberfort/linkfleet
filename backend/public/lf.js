/*! LinkFleet conversion snippet. Docs: docs/API.md#conversions */
(function (window, document) {
    'use strict';

    var PARAM = 'lf_click';
    var STORAGE_KEY = 'lf_click';
    var LIFETIME_SECONDS = 90 * 24 * 60 * 60;
    // What a click token looks like. Anything else is not ours to store or send.
    var TOKEN = /^[A-Za-z0-9]{1,64}$/;
    var EVENT = /^[a-z0-9][a-z0-9_.:-]{0,63}$/;

    var script = document.currentScript || document.querySelector('script[src*="/lf.js"]');
    var origin = '';

    try {
        origin = script && script.src ? new URL(script.src, window.location.href).origin : '';
    } catch (ignore) {
        origin = '';
    }

    function fromUrl() {
        try {
            var token = new URLSearchParams(window.location.search).get(PARAM);

            return token && TOKEN.test(token) ? token : null;
        } catch (ignore) {
            return null;
        }
    }

    function fromCookie() {
        var pairs = (document.cookie || '').split(';');

        for (var i = 0; i < pairs.length; i++) {
            var pair = pairs[i].replace(/^\s+/, '');

            if (pair.indexOf(STORAGE_KEY + '=') === 0) {
                var value = pair.slice(STORAGE_KEY.length + 1);

                return TOKEN.test(value) ? value : null;
            }
        }

        return null;
    }

    function fromStorage() {
        try {
            var value = window.localStorage.getItem(STORAGE_KEY);

            return value && TOKEN.test(value) ? value : null;
        } catch (ignore) {
            return null;
        }
    }

    function remember(token) {
        try {
            var secure = window.location.protocol === 'https:' ? '; Secure' : '';

            document.cookie = STORAGE_KEY + '=' + token + '; Max-Age=' + LIFETIME_SECONDS + '; Path=/; SameSite=Lax' + secure;
        } catch (ignore) {
            /* cookies blocked: localStorage below may still work */
        }

        try {
            window.localStorage.setItem(STORAGE_KEY, token);
        } catch (ignore) {
            /* storage blocked too: this visit simply cannot be attributed later */
        }
    }

    /** The click this visitor arrived from, if we know it. A fresh arrival replaces an older one. */
    function clickId() {
        return fromUrl() || fromCookie() || fromStorage();
    }

    /**
     * Reports that the visitor did something worth counting.
     *   linkfleet.track('purchase', { value: 49.9, currency: 'USD', id: 'order-1001' })
     * `id` makes the report safe to send twice. Returns whether anything was sent.
     */
    function track(event, options) {
        try {
            var token = clickId();
            var name = String(event || '').toLowerCase();

            if (!token || !origin || !EVENT.test(name)) {
                return false;
            }

            var opts = options || {};
            var query = ['click=' + encodeURIComponent(token), 'event=' + encodeURIComponent(name)];

            if (opts.value !== undefined && opts.value !== null && opts.value !== '') {
                query.push('value=' + encodeURIComponent(opts.value));
                query.push('currency=' + encodeURIComponent(opts.currency || ''));
            }

            if (opts.id !== undefined && opts.id !== null && opts.id !== '') {
                query.push('id=' + encodeURIComponent(opts.id));
            }

            new window.Image().src = origin + '/lf.gif?' + query.join('&');

            return true;
        } catch (ignore) {
            // Tracking must never be the reason a checkout page breaks.
            return false;
        }
    }

    var arrival = fromUrl();

    if (arrival) {
        remember(arrival);
    }

    // Calls made before this script finished loading were queued by the stub
    // from the docs: window.linkfleet = window.linkfleet || { q: [], track: function () { this.q.push(arguments); } }
    var queued = window.linkfleet && window.linkfleet.q ? window.linkfleet.q : [];

    window.linkfleet = { track: track, clickId: clickId, q: [] };

    for (var i = 0; i < queued.length; i++) {
        track.apply(null, queued[i]);
    }
})(window, document);
