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
    var openBtns = doc.querySelectorAll('[data-drawer-open]');
    var closeBtns = doc.querySelectorAll('[data-drawer-close]');
    var lastFocus = null;

    function setOpenAttr(open) {
        Array.prototype.forEach.call(openBtns, function (btn) {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    function setDrawer(open) {
        if (!drawer) { return; }
        drawer.setAttribute('data-open', open ? 'true' : 'false');
        setOpenAttr(open);
        if (body) { body.setAttribute('data-drawer-open', open ? 'true' : 'false'); }
        if (open) {
            lastFocus = doc.activeElement;
            var first = drawer.querySelector('a, button');
            if (first) { window.setTimeout(function () { first.focus(); }, 40); }
        } else if (lastFocus && lastFocus.focus) {
            lastFocus.focus();
        }
    }

    /* Every opener (header button and the tab bar's "Menu") toggles it. */
    Array.prototype.forEach.call(openBtns, function (btn) {
        btn.addEventListener('click', function () {
            setDrawer(drawer && drawer.getAttribute('data-open') !== 'true');
        });
    });

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
    Array.prototype.forEach.call(doc.querySelectorAll('[data-lm-copy]'), function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-lm-copy') || '';
            var label = btn.querySelector('span');
            var done = function (ok) {
                if (!label) { return; }
                var old = label.textContent;
                label.textContent = ok ? 'Copied' : 'Press Ctrl+C';
                window.setTimeout(function () { label.textContent = old; }, 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
            } else {
                var ta = doc.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', 'readonly');
                ta.style.position = 'fixed';
                ta.style.top = '-1000px';
                doc.body.appendChild(ta);
                ta.select();
                try { doc.execCommand('copy'); done(true); } catch (err) { done(false); }
                doc.body.removeChild(ta);
            }
        });
    });

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

/* ---------------------------------------------------------------- toast */
(function () {
    'use strict';

    var el = document.getElementById('lm-toast');
    if (!el) { return; }

    var timer = null;

    /**
     * Show a short message. Exposed as window.lmToast so page scripts can use
     * it without pulling in a framework.
     */
    window.lmToast = function (message, kind) {
        el.textContent = String(message === undefined || message === null ? '' : message);
        el.className = 'lm-toast is-visible' + (kind ? ' lm-toast--' + kind : '');
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            el.className = 'lm-toast';
        }, 4000);
    };
})();

/* ----------------------------------------------------------------- tabs */
(function () {
    'use strict';

    /*
     * <div class="lm-tabs" role="tablist"> with buttons carrying
     * aria-controls="panel-id". Used by the API docs code samples.
     */
    Array.prototype.forEach.call(document.querySelectorAll('.lm-tabs'), function (list) {
        var buttons = list.querySelectorAll('[role="tab"]');

        function select(btn) {
            Array.prototype.forEach.call(buttons, function (b) {
                var active = b === btn;
                b.setAttribute('aria-selected', active ? 'true' : 'false');
                b.setAttribute('tabindex', active ? '0' : '-1');
                var panel = document.getElementById(b.getAttribute('aria-controls'));
                if (panel) { panel.hidden = !active; }
            });
        }

        Array.prototype.forEach.call(buttons, function (btn) {
            btn.addEventListener('click', function () { select(btn); });
            btn.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
                var i = Array.prototype.indexOf.call(buttons, btn);
                var next = buttons[e.key === 'ArrowRight' ? (i + 1) % buttons.length
                    : (i - 1 + buttons.length) % buttons.length];
                if (next) { next.focus(); select(next); }
            });
        });
    });
})();
