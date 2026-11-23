/* Public navigation only. No session, storage, provider or financial calls. */
(function () {
    'use strict';
    var nav = document.querySelector('[data-public-navigation]');
    if (!nav || !window.matchMedia) return;
    var button = nav.querySelector('.public-menu-toggle');
    var links = nav.querySelector('#public-menu-links');
    if (!button || !links) return;
    var mobile = window.matchMedia('(max-width: 800px)');
    var lastFocusedLink = null;
    document.addEventListener('focusin', function (event) {
        if (links.contains(event.target)) lastFocusedLink = event.target;
        else if (event.target !== document.body) lastFocusedLink = null;
    });
    document.addEventListener('pointerdown', function (event) {
        if (!links.contains(event.target)) lastFocusedLink = null;
    });
    function close(restoreFocus) {
        nav.removeAttribute('data-menu-open');
        button.setAttribute('aria-expanded', 'false');
        if (restoreFocus) button.focus();
    }
    button.addEventListener('click', function () {
        var open = !nav.hasAttribute('data-menu-open');
        nav.toggleAttribute('data-menu-open', open);
        button.setAttribute('aria-expanded', String(open));
    });
    nav.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && mobile.matches && nav.hasAttribute('data-menu-open')) {
            close(true);
            event.preventDefault();
        }
    });
    links.addEventListener('click', function (event) {
        if (mobile.matches && event.target.closest('a')) close(false);
    });
    document.addEventListener('click', function (event) {
        if (mobile.matches && !nav.contains(event.target)) close(false);
    });
    // CSS can hide desktop links before the matchMedia change callback runs.
    // In Chromium that blur has no related target and focus otherwise falls to body.
    nav.addEventListener('focusout', function (event) {
        if (mobile.matches && !nav.hasAttribute('data-menu-open') &&
            links.contains(event.target) && !event.relatedTarget) button.focus();
    });
    function resize() {
        // Never leave keyboard focus in a newly hidden link when entering mobile.
        var focusInside = links.contains(document.activeElement) ||
            (document.activeElement === document.body && lastFocusedLink !== null);
        close(mobile.matches && focusInside);
    }
    if (mobile.addEventListener) mobile.addEventListener('change', resize);
    else mobile.addListener(resize);
    button.hidden = false;
    nav.setAttribute('data-menu-ready', '');
}());
