/* ==========================================================================
   License Manager — public site behaviour
   - mobile drawer (menu button, backdrop, Escape, focus return)
   - header shadow once the page is scrolled
   - reveal-on-scroll for sections that opt in with [data-reveal]
   - copy-to-clipboard for code blocks
   No dependencies. Safe to include on every public page.
   ========================================================================== */
(function () {
    'use strict';

    var doc = document;
    var body = doc.body;

    /* ------------------------------------------------------------ drawer */
    var drawer = doc.querySelector('[data-drawer]');
    var openBtn = doc.querySelector('[data-drawer-open]');
    var closeBtns = doc.querySelectorAll('[data-drawer-close]');
    var lastFocus = null;

    function setDrawer(open) {
        if (!drawer || !openBtn) { return; }
        drawer.setAttribute('data-open', open ? 'true' : 'false');
        openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (body) { body.setAttribute('data-drawer-open', open ? 'true' : 'false'); }
        if (open) {
            lastFocus = doc.activeElement;
            var first = drawer.querySelector('a, button');
            if (first) { window.setTimeout(function () { first.focus(); }, 40); }
        } else if (lastFocus && lastFocus.focus) {
            lastFocus.focus();
        }
    }

    if (openBtn) {
        openBtn.addEventListener('click', function () {
            setDrawer(drawer && drawer.getAttribute('data-open') !== 'true');
        });
    }

    Array.prototype.forEach.call(closeBtns, function (el) {
        el.addEventListener('click', function () { setDrawer(false); });
    });

    doc.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && drawer && drawer.getAttribute('data-open') === 'true') {
            setDrawer(false);
        }
    });

    /* Keep focus inside the panel while it is open. */
    doc.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || !drawer || drawer.getAttribute('data-open') !== 'true') { return; }
        var focusable = drawer.querySelectorAll('a[href], button:not([disabled])');
        if (!focusable.length) { return; }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    /* ------------------------------------------------------------ header */
    var header = doc.querySelector('.lm-header');
    if (header) {
        var onScroll = function () {
            header.setAttribute('data-scrolled', window.scrollY > 8 ? 'true' : 'false');
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    /* ------------------------------------------------------------ reveal */
    if ('IntersectionObserver' in window) {
        var items = doc.querySelectorAll('[data-reveal]');
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    io.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
        Array.prototype.forEach.call(items, function (el) { io.observe(el); });
    } else {
        Array.prototype.forEach.call(doc.querySelectorAll('[data-reveal]'), function (el) {
            el.classList.add('is-visible');
        });
    }

    /* -------------------------------------------------------------- copy */
    Array.prototype.forEach.call(doc.querySelectorAll('[data-copy]'), function (btn) {
        btn.addEventListener('click', function () {
            var target = doc.querySelector(btn.getAttribute('data-copy'));
            if (!target) { return; }
            var text = target.innerText;
            var done = function () {
                var old = btn.textContent;
                btn.textContent = 'Copied';
                window.setTimeout(function () { btn.textContent = old; }, 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, done);
            } else {
                var ta = doc.createElement('textarea');
                ta.value = text;
                doc.body.appendChild(ta);
                ta.select();
                try { doc.execCommand('copy'); } catch (err) {}
                doc.body.removeChild(ta);
                done();
            }
        });
    });
})();
