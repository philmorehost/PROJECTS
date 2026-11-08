/* RISE 63 — progressive enhancement only.
   Everything here is optional: every form and page works with JavaScript disabled.
   Contents: theme switch, offline banner, install prompt, live-search combobox,
   draft autosave, idempotent offline submit queue, service-worker registration, update toast. */
(function () {
  'use strict';

  var THEME_KEY = 'rise63-theme';
  var DRAFT_PREFIX = 'rise63-draft-';
  var QUEUE_KEY = 'rise63-queue';
  var SNOOZE_KEY = 'rise63-install-snoozed';
  var root = document.documentElement;

  function store(key, value) {
    try { window.localStorage.setItem(key, value); } catch (e) { /* private mode */ }
  }
  function read(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }
  function remove(key) {
    try { window.localStorage.removeItem(key); } catch (e) { /* noop */ }
  }

  /* ── Theme ─────────────────────────────────────────────────────────────────── */
  function currentTheme() {
    return root.dataset.theme || 'system';
  }
  function applyTheme(theme) {
    root.dataset.theme = theme;
    store(THEME_KEY, theme);
    var button = document.getElementById('theme-toggle');
    if (button) {
      var label = theme === 'system' ? 'Appearance: follows your device' : 'Appearance: ' + theme;
      button.setAttribute('aria-label', label);
      button.title = label;
    }
  }
  function nextTheme(theme) {
    return theme === 'system' ? 'light' : (theme === 'light' ? 'dark' : 'system');
  }
  var themeButton = document.getElementById('theme-toggle');
  if (themeButton) {
    themeButton.addEventListener('click', function () {
      applyTheme(nextTheme(currentTheme()));
    });
  }
  applyTheme(currentTheme());

  /* ── Offline banner ────────────────────────────────────────────────────────── */
  /* Requirements, in order of how easy they are to get wrong:
       1. automatic — no user action needed to make it appear;
       2. only while actually offline — navigator.onLine reports "connected" to any network,
          including a router with no internet, so it is a hint and never the answer;
       3. removed with no perceptible delay when the connection returns, and re-tested on a timer
          so a missing 'online' event cannot leave it stranded on screen;
       4. closable by hand, and a dismissal sticks until the next time the connection drops —
          otherwise the bar would reappear seconds after being dismissed. */
  var banner = document.getElementById('offline-banner');
  var bannerDismiss = document.getElementById('offline-dismiss');
  var offlineDismissed = false;
  var probeTimer = null;
  var probeInFlight = false;
  /* 5s: fast enough that an offline bar never outlives a working connection by more than a moment,
     cheap enough to run on a metered phone (one HEAD to the favicon the page already loaded). */
  var PROBE_INTERVAL = 5000;

  function setBanner(offline) {
    if (!banner) { return; }
    var wasHidden = banner.hidden;
    var show = offline && !offlineDismissed;
    banner.hidden = !show;
    // The bar is fixed, so it does not move the page; nothing needs reflow. Announce the change
    // once, for screen readers, rather than on every probe.
    if (show && wasHidden) {
      banner.setAttribute('data-since', String(Date.now()));
    }
  }

  /* A request that fails fast is a better answer than navigator.onLine. HEAD on the same origin
     proves the route to this server; a captive portal that answers everything still counts as
     online, which is correct — the app can reach something. */
  function probe() {
    if (probeInFlight || !window.fetch) { return; }
    probeInFlight = true;
    var done = function (reachable) {
      probeInFlight = false;
      if (reachable) {
        if (offlineDismissed) { offlineDismissed = false; }
        setBanner(false);
        flushQueue();
      } else {
        setBanner(true);
      }
    };
    var url = (document.querySelector('link[rel="icon"]') || {}).href || window.location.href;
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = setTimeout(function () {
      if (controller) { controller.abort(); }
    }, 4000);
    window.fetch(url, {
      method: 'HEAD',
      cache: 'no-store',
      credentials: 'same-origin',
      signal: controller ? controller.signal : undefined
    }).then(function (response) {
      clearTimeout(timer);
      // Any HTTP answer — even 403 or 404 — means the server was reached.
      done(true);
    }, function () {
      clearTimeout(timer);
      done(false);
    });
  }

  function startProbing() {
    if (probeTimer !== null) { return; }
    probeTimer = setInterval(probe, PROBE_INTERVAL);
  }
  function stopProbing() {
    if (probeTimer === null) { return; }
    clearInterval(probeTimer);
    probeTimer = null;
  }

  if (banner) {
    // navigator.onLine is only trusted when it says "offline": that is a reliable negative. When
    // it flips to online the bar is cleared at once, then a probe confirms the server agrees.
    window.addEventListener('offline', function () {
      offlineDismissed = false;
      setBanner(true);
      startProbing();
    });
    window.addEventListener('online', function () {
      offlineDismissed = false;
      setBanner(false);
      probe();
      stopProbing();
    });
    window.addEventListener('pageshow', function () { if (navigator.onLine === false) { setBanner(true); startProbing(); } });

    if (bannerDismiss) {
      bannerDismiss.addEventListener('click', function () {
        offlineDismissed = true;
        setBanner(false);
        // Keep probing while dismissed: the flag has to clear when the connection returns, or the
        // next outage would show nothing at all.
        startProbing();
      });
    }

    // Initial state. navigator.onLine === false is trustworthy; true is not, so probe once on load
    // rather than trusting it and silently hiding a real outage.
    if (navigator.onLine === false) {
      setBanner(true);
      startProbing();
    } else {
      probe();
    }
  }

  /* ── Install prompt (Android/Chromium) ─────────────────────────────────────── */
  var installBox = document.getElementById('install-prompt');
  var installHint = document.getElementById('install-hint');
  var deferredPrompt = null;
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

  function snoozed() {
    var value = parseInt(read(SNOOZE_KEY) || '0', 10);
    return value > Date.now();
  }
  function showInstall(hintText) {
    if (!installBox || standalone || snoozed()) { return; }
    if (hintText && installHint) { installHint.textContent = hintText; }
    installBox.hidden = false;
  }
  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferredPrompt = event;
    showInstall('Add RISE 63 to your home screen — it works offline.');
  });
  var accept = document.getElementById('install-accept');
  if (accept) {
    accept.addEventListener('click', function () {
      if (installBox) { installBox.hidden = true; }
      if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt = null;
      }
    });
  }
  var dismiss = document.getElementById('install-dismiss');
  if (dismiss) {
    dismiss.addEventListener('click', function () {
      if (installBox) { installBox.hidden = true; }
      store(SNOOZE_KEY, String(Date.now() + 14 * 24 * 60 * 60 * 1000));
    });
  }
  var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  if (isIos && !standalone) {
    showInstall('Tap Share, then “Add to Home Screen” to install RISE 63.');
  }

  /* ── Live-search combobox ──────────────────────────────────────────────────── */
  function initCombobox(combo) {
    var input = combo.querySelector('input[role="combobox"]');
    var hidden = combo.querySelector('input[type="hidden"]');
    var list = combo.querySelector('.combo-list');
    if (!input || !list) { return; }
    var endpoint = combo.dataset.endpoint;
    var timer = null;
    var controller = null;
    var active = -1;
    var options = [];

    function close() {
      list.hidden = true;
      list.innerHTML = '';
      input.setAttribute('aria-expanded', 'false');
      active = -1;
      options = [];
    }
    function render(items, term) {
      list.innerHTML = '';
      if (!items.length) {
        var empty = document.createElement('li');
        empty.className = 'combo-empty';
        empty.textContent = term ? 'No parish matches “' + term + '”.' : 'Start typing your parish, area or zone.';
        list.appendChild(empty);
      } else {
        items.forEach(function (item, index) {
          var li = document.createElement('li');
          li.className = 'combo-option';
          li.id = combo.id + '-opt-' + index;
          li.setAttribute('role', 'option');
          li.dataset.id = item.id;
          li.dataset.label = item.label;
          li.innerHTML = '<strong></strong><small></small>';
          li.querySelector('strong').textContent = item.parish;
          li.querySelector('small').textContent = item.area + ' · ' + item.zone;
          li.addEventListener('mousedown', function (event) {
            event.preventDefault();
            choose(item);
          });
          list.appendChild(li);
        });
      }
      options = Array.prototype.slice.call(list.querySelectorAll('.combo-option'));
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }
    function choose(item) {
      input.value = item.label;
      if (hidden) { hidden.value = item.id; }
      close();
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function highlight(index) {
      options.forEach(function (li) { li.setAttribute('aria-selected', 'false'); });
      if (index < 0 || index >= options.length) { return; }
      options[index].setAttribute('aria-selected', 'true');
      input.setAttribute('aria-activedescendant', options[index].id);
      if (options[index].scrollIntoView) { options[index].scrollIntoView({ block: 'nearest' }); }
    }
    function search(term) {
      if (!endpoint) { return; }
      if (controller) { controller.abort(); }
      controller = typeof AbortController === 'function' ? new AbortController() : null;
      fetch(endpoint + '?q=' + encodeURIComponent(term), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: controller ? controller.signal : undefined
      }).then(function (response) {
        if (!response.ok) { throw new Error('search failed'); }
        return response.json();
      }).then(function (data) {
        render(Array.isArray(data.parishes) ? data.parishes : [], term);
      }).catch(function () {
        if (list.hidden) { return; }
        close();
      });
    }

    input.addEventListener('input', function () {
      if (hidden) { hidden.value = ''; }
      var term = input.value.trim();
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { search(term); }, 120);
    });
    input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (list.hidden) { search(input.value.trim()); return; }
        active = Math.min(active + 1, options.length - 1);
        highlight(active);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active = Math.max(active - 1, 0);
        highlight(active);
      } else if (event.key === 'Enter') {
        if (!list.hidden && active >= 0 && options[active]) {
          event.preventDefault();
          choose({ id: options[active].dataset.id, label: options[active].dataset.label });
        }
      } else if (event.key === 'Escape') {
        close();
      }
    });
    input.addEventListener('focus', function () {
      if (list.hidden) { search(input.value.trim()); }
    });
    document.addEventListener('click', function (event) {
      if (!combo.contains(event.target)) { close(); }
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-combobox]'), initCombobox);

  /* ── Destructive-action confirmation ───────────────────────────────────────── */
  // Any form carrying data-confirm asks once before submitting. Native confirm() is used
  // deliberately: it works offline, needs no markup and cannot be styled into something the
  // user misreads. Buttons stay enabled if they cancel.
  function initConfirm() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement)) { return; }
      var message = form.getAttribute('data-confirm');
      if (!message || form.dataset.confirmed === '1') { return; }
      if (!window.confirm(message)) {
        event.preventDefault();
        return;
      }
      form.dataset.confirmed = '1';
    }, true);
  }

  /* ── Caps Lock hint on password fields ─────────────────────────────────────── */
  function initCapsHint() {
    var hint = document.getElementById('caps-hint');
    if (!hint) { return; }
    document.addEventListener('keyup', function (event) {
      if (typeof event.getModifierState !== 'function') { return; }
      hint.hidden = !event.getModifierState('CapsLock');
    });
  }

  /* ── Draft autosave ────────────────────────────────────────────────────────── */
  Array.prototype.forEach.call(document.querySelectorAll('form[data-autosave]'), function (form) {
    var key = DRAFT_PREFIX + form.id;
    if (!form.id) { return; }
    var timer = null;
    var status = form.querySelector('[data-draft-status]');
    var saved = read(key);
    if (saved) {
      try {
        var draft = JSON.parse(saved);
        var restored = 0;
        Object.keys(draft).forEach(function (name) {
          var field = form.elements[name];
          if (field && typeof field.value === 'string' && field.value === '') {
            field.value = draft[name];
            restored++;
          }
        });
        if (restored && status) { status.textContent = 'Restored your saved draft.'; }
      } catch (e) { /* ignore malformed draft */ }
    }
    function snapshot() {
      var data = {};
      Array.prototype.forEach.call(form.elements, function (field) {
        if (!field.name || field.type === 'file' || field.type === 'hidden' || field.type === 'password') { return; }
        if (field.type === 'checkbox' || field.type === 'radio') {
          if (field.checked) { data[field.name] = field.value; }
        } else if (field.value) {
          data[field.name] = field.value;
        }
      });
      store(key, JSON.stringify(data));
      if (status) { status.textContent = 'Draft saved on this device.'; }
    }
    form.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(snapshot, 900);
    });
    form.addEventListener('submit', function () {
      window.clearTimeout(timer);
      remove(key);
    });
  });

  /* ── Idempotency key for offline-safe submits ──────────────────────────────── */
  function newKey() {
    if (window.crypto && window.crypto.getRandomValues) {
      var bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }
    return String(Date.now()) + Math.random().toString(16).slice(2);
  }
  Array.prototype.forEach.call(document.querySelectorAll('form[data-offline-queue]'), function (form) {
    var field = form.querySelector('input[name="idempotency_key"]');
    if (field && !field.value) { field.value = newKey(); }
  });

  function queuePush(payload) {
    var queue = [];
    try { queue = JSON.parse(read(QUEUE_KEY) || '[]'); } catch (e) { queue = []; }
    queue.push(payload);
    store(QUEUE_KEY, JSON.stringify(queue.slice(-20)));
  }
  function queueLen() {
    try { return (JSON.parse(read(QUEUE_KEY) || '[]')).length; } catch (e) { return 0; }
  }
  function flushQueue() {
    if (!navigator.onLine || queueLen() === 0) { return; }
    var queue;
    try { queue = JSON.parse(read(QUEUE_KEY) || '[]'); } catch (e) { return; }
    var next = [];
    queue.forEach(function (item) {
      var body = new URLSearchParams(item.fields);
      fetch(item.action, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
      }).then(function (response) {
        if (!response.ok) { next.push(item); }
      }).catch(function () { next.push(item); });
    });
    store(QUEUE_KEY, JSON.stringify(next));
  }

  /* ── Copy-to-clipboard helper ──────────────────────────────────────────────── */
  // [data-copy-target] copies the value of the element with that id. The installer uses it so the
  // operator can paste the exact domain into the provider's order form without retyping.
  function initCopyButtons() {
    document.addEventListener('click', function (event) {
      var button = event.target.closest('[data-copy-target]');
      if (!button) { return; }
      var field = document.getElementById(button.getAttribute('data-copy-target'));
      if (!field) { return; }
      var value = field.value !== undefined ? field.value : field.textContent;
      var done = function () {
        var original = button.textContent;
        button.textContent = 'Copied';
        window.setTimeout(function () { button.textContent = original; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(done, function () { field.select && field.select(); });
      } else if (field.select) {
        field.select();
        try { document.execCommand('copy'); done(); } catch (e) { /* leave it selected */ }
      }
    });
  }

  /* ── Boot the small behaviours ─────────────────────────────────────────────── */
  initConfirm();
  initCapsHint();
  initCopyButtons();

  /* ── Service worker & update toast ─────────────────────────────────────────── */
  if ('serviceWorker' in navigator && document.body.classList.contains('signed-in')) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(document.body.dataset.sw || 'sw.js', { scope: './' }).catch(function () { /* ignore */ });
    });
    var refreshing = false;
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (refreshing) { return; }
      refreshing = true;
      var toast = document.createElement('div');
      toast.className = 'flash flash-info';
      toast.setAttribute('role', 'status');
      toast.textContent = 'RISE 63 has been updated. Reload to use the new version.';
      var button = document.createElement('button');
      button.className = 'btn btn-sm btn-secondary';
      button.type = 'button';
      button.textContent = 'Reload';
      button.addEventListener('click', function () { window.location.reload(); });
      toast.appendChild(document.createTextNode(' '));
      toast.appendChild(button);
      document.getElementById('main').prepend(toast);
    });
  }
}());
