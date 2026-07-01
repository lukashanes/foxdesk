(function() {
    'use strict';

    var body = document.body;
    if (!body || !body.classList.contains('app-shell-page')) {
        return;
    }

    var reduceMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduceMotion) {
        return;
    }

    var leaving = false;
    var transitionDelay = 120;

    function isPlainLeftClick(event) {
        return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
    }

    function isTransitionableLink(link) {
        if (!link || leaving) {
            return false;
        }

        if (link.hasAttribute('download') || link.hasAttribute('data-no-page-transition')) {
            return false;
        }

        var target = (link.getAttribute('target') || '').trim();
        if (target && target.toLowerCase() !== '_self') {
            return false;
        }

        var rawHref = (link.getAttribute('href') || '').trim();
        if (
            rawHref === ''
            || rawHref.charAt(0) === '#'
            || /^(mailto:|tel:|javascript:|data:|blob:)/i.test(rawHref)
        ) {
            return false;
        }

        var destination;
        try {
            destination = new URL(rawHref, window.location.href);
        } catch (error) {
            return false;
        }

        if (destination.origin !== window.location.origin) {
            return false;
        }

        var current = new URL(window.location.href);
        if (destination.pathname === current.pathname && destination.search === current.search) {
            return false;
        }

        return true;
    }

    function clearTransitionState() {
        leaving = false;
        body.classList.remove('is-page-leaving');
        var main = document.getElementById('main-content');
        if (main) {
            main.removeAttribute('aria-busy');
        }
    }

    document.addEventListener('click', function(event) {
        if (!isPlainLeftClick(event) || event.defaultPrevented) {
            return;
        }

        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (!isTransitionableLink(link)) {
            return;
        }

        event.preventDefault();
        leaving = true;
        body.classList.add('is-page-leaving');

        var main = document.getElementById('main-content');
        if (main) {
            main.setAttribute('aria-busy', 'true');
        }

        window.setTimeout(function() {
            window.location.assign(link.href);
        }, transitionDelay);
    }, true);

    window.addEventListener('pageshow', clearTransitionState);
    window.addEventListener('pagehide', function() {
        body.classList.remove('is-page-leaving');
    });
})();
