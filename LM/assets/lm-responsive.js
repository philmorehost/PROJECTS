/* ==========================================================================
   License Manager — responsive behaviour
   --------------------------------------------------------------------------
   assets/lm-responsive.css can fix most of a phone layout on its own. Two
   things need the DOM:

     1. Tables. A table with eight columns cannot be made to fit 360px without
        becoming unreadable, and CSS alone cannot give it a scroll container
        that is *around* it rather than on <body>. This wraps every table in
        .lm-table-scroll, which scrolls on its own and stays keyboard
        reachable (tabindex="0" + a label).

     2. Grids. Each page declares its own class names, so the stylesheet
        cannot know which of them are `display: grid` with two or more
        columns. At phone width this tags those elements .lm-stack, and the
        stylesheet collapses them to one column.

   Nothing here runs on a desktop: the grid pass is a no-op above 700px, and a
   table that already fits simply shows no scrollbar. Loaded by
   includes/responsive.php — see lm_responsive_head().
   ========================================================================== */

(function () {
    'use strict';

    var PHONE = 700;

    /* ------------------------------------------------------------------ util */

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            if (timer) {
                window.clearTimeout(timer);
            }
            timer = window.setTimeout(fn, wait);
        };
    }

    /** A human label for the table's scroll region. */
    function tableLabel(table) {
        var caption = table.querySelector('caption');
        if (caption && caption.textContent.trim()) {
            return caption.textContent.trim();
        }

        var card = table.closest('.card, .panel, .box, section');
        if (card) {
            var heading = card.querySelector('h1, h2, h3, h4');
            if (heading && heading.textContent.trim()) {
                return heading.textContent.trim();
            }
        }

        return document.title || 'Table';
    }

    /* ---------------------------------------------------------------- tables */

    function wrapTables() {
        var tables = document.querySelectorAll('table');

        for (var i = 0; i < tables.length; i++) {
            var table = tables[i];

            /* Already handled: a second wrap would nest two scroll areas. */
            if (table.parentNode && table.parentNode.classList &&
                table.parentNode.classList.contains('lm-table-scroll')) {
                continue;
            }

            /* The page decided how this one scrolls. */
            if (table.closest('[data-lm-no-scroll]')) {
                continue;
            }

            var box = document.createElement('div');
            box.className = 'lm-table-scroll';
            box.setAttribute('tabindex', '0');
            box.setAttribute('role', 'region');
            box.setAttribute('aria-label', tableLabel(table) + ' (scrollable)');

            table.parentNode.insertBefore(box, table);
            box.appendChild(table);
        }
    }

    /* ----------------------------------------------------------------- grids */

    /**
     * How many columns a grid actually has.
     *
     * getComputedStyle hands back the used value in a browser and the declared
     * value in jsdom ("repeat(2, 1fr)"), so both shapes have to resolve to the
     * same number. `auto-fit` / `auto-fill` already wrap on their own and are
     * left alone.
     */
    function trackCount(value) {
        var v = String(value || '').trim();

        if (!v || v === 'none') {
            return 0;
        }

        /* repeat(2, minmax(0, 1fr)) → two tracks */
        v = v.replace(/repeat\(\s*(\d+)\s*,\s*((?:[^()]|\([^()]*\))*)\)/gi, function (match, times, track) {
            var out = [];
            for (var i = 0; i < parseInt(times, 10); i++) {
                out.push(track);
            }
            return out.join(' ');
        });

        /* auto-fit / auto-fill already decide their own column count. */
        v = v.replace(/repeat\(\s*(?:auto-fit|auto-fill)\s*,\s*(?:[^()]|\([^()]*\))*\)/gi, 'x');

        /* minmax(0, 1fr), fit-content(200px), … → one token each */
        v = v.replace(/[a-z-]+\([^()]*\)/gi, 'x');

        return v.split(/\s+/).filter(Boolean).length;
    }

    function clearStack() {
        var stacked = document.querySelectorAll('.lm-stack');
        for (var i = 0; i < stacked.length; i++) {
            stacked[i].classList.remove('lm-stack');
        }
    }

    function collapseGrids() {
        if (window.innerWidth > PHONE) {
            clearStack();
            return;
        }

        /* Only block-level containers can be a grid. Restricting the scan to
           them keeps the pass to a few hundred nodes on the heaviest screen,
           and still catches the grids the page declared inline on a bare
           <div>. */
        var nodes = document.querySelectorAll(
            'div, section, article, aside, header, footer, nav, main, form, fieldset, ul, ol, dl, [class]'
        );

        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            var style = window.getComputedStyle(el);

            if (style.display !== 'grid') {
                continue;
            }

            if (trackCount(style.gridTemplateColumns) < 2) {
                continue;
            }

            /* The drawer and the top bar have their own layout. */
            if (el.closest('.lm-m-drawer, .lm-m-bar, .lm-m-tabs, .sidebar')) {
                continue;
            }

            el.classList.add('lm-stack');
        }
    }

    /* ------------------------------------------------------------------ boot */

    function start() {
        var body = document.body;

        /* The tag is `defer` in production, but inlining it (a preview build)
           would run it while <body> is still being parsed. */
        if (!body || body.getAttribute('data-lm-responsive') === 'ready') {
            return;
        }
        body.setAttribute('data-lm-responsive', 'ready');

        wrapTables();
        collapseGrids();

        window.addEventListener('resize', debounce(collapseGrids, 180));
        window.addEventListener('orientationchange', debounce(collapseGrids, 180));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
