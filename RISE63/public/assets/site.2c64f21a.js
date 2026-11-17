/* RISE 63 — public site behaviour.
   ───────────────────────────────────────────────────────────────────────────────────────────────
   Deliberately separate from app.js, and deliberately script-free in the templates: the CSP allows
   only hashed inline scripts, so anything written inline here would be silently blocked in
   production. Everything is progressive — with this file missing the page is still complete, every
   slide is still in the document, and the first one is visible because the markup says so.

   The slider:
     · cross-fades (opacity only), so nothing reflows and no scrollbar appears
     · pauses on hover, on focus, and when the tab is hidden — a carousel that keeps running while
       you are reading slide two is hostile
     · stops for good once the visitor takes control, because their choice outranks a timer
     · respects prefers-reduced-motion by never autoplaying at all
     · is operable by pointer, keyboard (← → Home End) and touch swipe
*/
(function () {
  'use strict';

  /* ── Header: reveal the rule only once the page has scrolled ─────────────────────── */
  function initHeader() {
    var header = document.querySelector('[data-site-header]');
    if (!header) { return; }
    var ticking = false;
    function update() {
      header.setAttribute('data-scrolled', window.scrollY > 8 ? 'true' : 'false');
      ticking = false;
    }
    window.addEventListener('scroll', function () {
      if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
    }, { passive: true });
    update();
  }

  /* ── Mobile navigation ──────────────────────────────────────────────────────────── */
  function initNav() {
    var toggle = document.querySelector('[data-site-menu]');
    var nav = document.getElementById('site-nav');
    if (!toggle || !nav) { return; }

    function setOpen(open) {
      nav.setAttribute('data-open', open ? 'true' : 'false');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    toggle.addEventListener('click', function () {
      setOpen(nav.getAttribute('data-open') !== 'true');
    });

    // A tap outside closes it. Without this the panel covers the page it is meant to lead to and the
    // only way out is the button the visitor has already forgotten about.
    document.addEventListener('click', function (event) {
      if (nav.getAttribute('data-open') !== 'true') { return; }
      if (toggle.contains(event.target) || nav.contains(event.target)) { return; }
      setOpen(false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && nav.getAttribute('data-open') === 'true') {
        setOpen(false);
        toggle.focus();
      }
    });

    // Following a link should not leave the panel open over the next page's content.
    nav.addEventListener('click', function (event) {
      if (event.target.closest('a')) { setOpen(false); }
    });

    // A resize past the breakpoint must not leave a phone-shaped panel on a desktop.
    window.addEventListener('resize', function () {
      if (window.innerWidth > 900) { setOpen(false); }
    });
  }

  /* ── Hero carousel ──────────────────────────────────────────────────────────────── */
  function initSlider() {
    var root = document.querySelector('[data-slider]');
    if (!root) { return; }

    var slides = Array.prototype.slice.call(root.querySelectorAll('[data-slide]'));
    if (slides.length < 2) {
      // One slide is a hero, not a carousel. Leave it alone: the controls are not rendered.
      return;
    }

    var pips = Array.prototype.slice.call(root.querySelectorAll('[data-slide-to]'));
    var prev = root.querySelector('[data-slide-prev]');
    var next = root.querySelector('[data-slide-next]');
    var counter = root.querySelector('[data-slide-current]');
    var interval = parseInt(root.getAttribute('data-interval') || '7000', 10);
    if (!(interval > 2000)) { interval = 7000; }

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var index = 0;
    var timer = null;
    var stopped = false;   // the visitor has taken control; never resume on its own
    var hovering = false;
    var focused = false;

    // The pip's progress bar is driven by the same duration, so the fill and the timer cannot drift.
    root.style.setProperty('--pip-duration', interval + 'ms');

    function show(target, userInitiated) {
      var count = slides.length;
      index = ((target % count) + count) % count;

      slides.forEach(function (slide, i) {
        var active = i === index;
        slide.classList.toggle('is-active', active);
        // aria-hidden on the inactive panels is what stops a screen reader reading four headlines in
        // a row and calling them one paragraph.
        if (active) {
          slide.removeAttribute('aria-hidden');
        } else {
          slide.setAttribute('aria-hidden', 'true');
        }
      });

      pips.forEach(function (pip, i) {
        var active = i === index;
        pip.classList.toggle('is-active', active);
        // aria-current, not aria-selected: these are buttons in a group, not tabs. A tab role
        // without a tabpanel is an invalid pattern and tells a screen reader about controls that do
        // not exist.
        if (active) { pip.setAttribute('aria-current', 'true'); }
        else { pip.removeAttribute('aria-current'); }
        // Re-trigger the fill animation: removing and re-adding is the only reliable reset.
        if (active) {
          var track = pip.querySelector('.hero-pip-track');
          if (track) {
            var clone = track.cloneNode(true);
            track.parentNode.replaceChild(clone, track);
          }
        }
      });

      if (counter) { counter.textContent = ('0' + (index + 1)).slice(-2); }

      if (userInitiated) {
        stopped = true;
        stop();
        root.setAttribute('data-paused', 'true');
      }
    }

    function start() {
      if (reduceMotion || stopped || hovering || focused || document.hidden) { return; }
      stop();
      timer = window.setTimeout(function () {
        show(index + 1, false);
        start();
      }, interval);
    }

    function stop() {
      if (timer) { window.clearTimeout(timer); timer = null; }
    }

    function restart() {
      stop();
      root.setAttribute('data-paused', 'false');
      start();
    }

    if (prev) { prev.addEventListener('click', function () { show(index - 1, true); }); }
    if (next) { next.addEventListener('click', function () { show(index + 1, true); }); }
    pips.forEach(function (pip, i) {
      pip.addEventListener('click', function () { show(i, true); });
    });

    // Keyboard: the region is focusable, and the arrows work the way a carousel is described in
    // every accessibility guide.
    root.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1, true); }
      else if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1, true); }
      else if (event.key === 'Home') { event.preventDefault(); show(0, true); }
      else if (event.key === 'End') { event.preventDefault(); show(slides.length - 1, true); }
    });

    root.setAttribute('tabindex', '0');

    // Hover and focus pause without stopping the rotation altogether — moving the mouse away should
    // resume, whereas pressing an arrow should not.
    root.addEventListener('mouseenter', function () { hovering = true; stop(); });
    root.addEventListener('mouseleave', function () { hovering = false; if (!stopped) { restart(); } });
    root.addEventListener('focusin', function () { focused = true; stop(); });
    root.addEventListener('focusout', function () {
      focused = false;
      if (!stopped && !hovering) { restart(); }
    });

    // A background tab must not burn through the slides unseen.
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { stop(); } else if (!stopped) { restart(); }
    });

    // Touch: horizontal swipe, with a dead zone so a vertical scroll is never read as a swipe.
    var startX = 0;
    var startY = 0;
    var tracking = false;
    root.addEventListener('touchstart', function (event) {
      if (event.touches.length !== 1) { tracking = false; return; }
      startX = event.touches[0].clientX;
      startY = event.touches[0].clientY;
      tracking = true;
    }, { passive: true });
    root.addEventListener('touchend', function (event) {
      if (!tracking) { return; }
      tracking = false;
      var touch = event.changedTouches[0];
      if (!touch) { return; }
      var dx = touch.clientX - startX;
      var dy = touch.clientY - startY;
      if (Math.abs(dx) < 48 || Math.abs(dx) < Math.abs(dy) * 1.4) { return; }
      show(dx < 0 ? index + 1 : index - 1, true);
    }, { passive: true });

    // Swipe in a direction the browser would otherwise treat as navigation.
    root.addEventListener('touchmove', function (event) {
      if (!tracking || event.touches.length !== 1) { return; }
      var dx = Math.abs(event.touches[0].clientX - startX);
      var dy = Math.abs(event.touches[0].clientY - startY);
      if (dx > dy && dx > 24 && event.cancelable) { event.preventDefault(); }
    }, { passive: false });

    show(0, false);
    if (reduceMotion) {
      root.setAttribute('data-paused', 'true');
    } else {
      start();
    }
  }

  /* ── Smooth in-page anchors ─────────────────────────────────────────────────────── */
  function initAnchors() {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { return; }
    document.addEventListener('click', function (event) {
      var link = event.target.closest('a[href^="#"]');
      if (!link) { return; }
      var id = link.getAttribute('href').slice(1);
      if (!id) { return; }
      var target = document.getElementById(id);
      if (!target) { return; }
      event.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      // Move focus as well as the viewport, or the keyboard user is left where they were.
      target.setAttribute('tabindex', '-1');
      target.focus({ preventScroll: true });
      window.history.replaceState(null, '', '#' + id);
    });
  }

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function () {
    initHeader();
    initNav();
    initSlider();
    initAnchors();
  });
})();
