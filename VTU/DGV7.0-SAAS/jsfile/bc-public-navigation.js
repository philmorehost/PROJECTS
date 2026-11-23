/* Public navigation only. No session, storage, provider or financial calls. */
(function () {
    'use strict';
    var nav = document.querySelector('[data-public-navigation]');
    if (!nav || !window.matchMedia) return;
    var button = nav.querySelector('.public-menu-toggle');
    var links = nav.querySelector('#public-menu-links');
    if (!button || !links) return;
    var mobile = window.matchMedia('(max-width: 800px)');
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
    function resize() {
        // Never leave keyboard focus in a newly hidden link when entering mobile.
        var focusInside = links.contains(document.activeElement);
        close(mobile.matches && focusInside);
    }
    if (mobile.addEventListener) mobile.addEventListener('change', resize);
    else mobile.addListener(resize);
    button.hidden = false;
    nav.setAttribute('data-menu-ready', '');
}());
