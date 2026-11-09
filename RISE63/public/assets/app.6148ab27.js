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

  /* ── Offline modal ─────────────────────────────────────────────────────────── */
  /* Requirements, in order of how easy they are to get wrong:
       1. automatic — no user action needed to make it appear;
       2. only while actually offline — navigator.onLine reports "connected" to any network,
          including a router with no internet, so it is a hint and never the answer;
       3. gone with no perceptible delay when the connection returns;
       4. closable by hand, and a dismissal sticks until the next time the connection drops —
          otherwise it would reopen seconds after being closed, which is the definition of
          stubborn.
     The bar this replaces failed (4) and sat over the header. A modal fails worse if it cannot be
     closed, so dismissal is the primary path here, not an afterthought. */
  var modal = document.getElementById('offline-modal');
  var modalClose = document.getElementById('offline-close');
  var modalRetry = document.getElementById('offline-retry');
  var modalHint = document.getElementById('offline-hint');
  var dismissed = false;
  var probeTimer = null;
  var probeInFlight = false;
  /* 5s: fast enough that the notice never outlives a working connection by more than a moment,
     cheap enough for a metered phone (one HEAD to a 1KB icon the page already loaded). */
  var PROBE_INTERVAL = 5000;

  function showModal(show) {
    if (!modal) { return; }
    modal.hidden = !show;
    // The page behind a modal must not scroll under it, or a phone user can drag the sheet off
    // screen and appear to have "lost" the page.
    document.documentElement.classList.toggle('offline-open', show);
  }
  function openIfNeeded() {
    if (dismissed) { return; }
    showModal(true);
  }

  /* A request that fails fast is a better answer than navigator.onLine. HEAD on a small same-origin
     file proves the route to this server. */
  function probe() {
    if (probeInFlight || !window.fetch) { return; }
    probeInFlight = true;
    var done = function (reachable) {
      probeInFlight = false;
      if (reachable) {
        dismissed = false;
        showModal(false);
        if (modalHint) { modalHint.textContent = ''; }
        flushQueue();
        stopProbing();
      } else {
        openIfNeeded();
      }
    };
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = setTimeout(function () { if (controller) { controller.abort(); } }, 4000);
    window.fetch('./favicon.svg', {
      method: 'HEAD', cache: 'no-store', credentials: 'same-origin',
      signal: controller ? controller.signal : undefined
    }).then(function () { clearTimeout(timer); done(true); },
            function () { clearTimeout(timer); done(false); });
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

  if (modal) {
    var closeNotice = function () {
      dismissed = true;
      showModal(false);
      // Keep probing while dismissed: the flag has to clear when the connection returns, or the
      // next outage would show nothing at all.
      startProbing();
    };

    if (modalClose) { modalClose.addEventListener('click', closeNotice); }
    if (modalRetry) {
      modalRetry.addEventListener('click', function () {
        if (modalHint) { modalHint.textContent = 'Checking…'; }
        probe();
      });
    }
    modal.addEventListener('click', function (event) {
      var sheet = modal.querySelector('.offline-sheet');
      if (sheet && !sheet.contains(event.target)) { closeNotice(); }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !modal.hidden) { closeNotice(); }
    });

    // navigator.onLine is only trusted when it says "offline": that is a reliable negative. When it
    // flips to online the modal is closed at once, then a probe confirms the server agrees.
    window.addEventListener('offline', function () {
      dismissed = false;
      openIfNeeded();
      startProbing();
    });
    window.addEventListener('online', function () {
      dismissed = false;
      showModal(false);
      probe();
    });
    window.addEventListener('pageshow', function () {
      if (navigator.onLine === false) { openIfNeeded(); startProbing(); }
    });

    if (navigator.onLine === false) {
      openIfNeeded();
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

  /* ── Native parish list (works with no JavaScript at all) ──────────────────── */
  /* The <select> is rendered by PHP, so the list is on the page before any script runs. This only
     mirrors a choice into the hidden field the form submits — and from there into the search box,
     so the member can see what they picked. If this never runs, the select itself still submits. */
  Array.prototype.forEach.call(document.querySelectorAll('[data-parish-pick]'), function (pick) {
    var input = document.getElementById('parish-search');
    var hidden = document.getElementById('parish-id-field');
    pick.addEventListener('change', function () {
      var option = pick.options[pick.selectedIndex];
      if (!option) { return; }
      if (hidden) { hidden.value = pick.value; }
      if (input && pick.value) { input.value = option.getAttribute('data-label') || option.textContent; }
      // A choice from the list settles the question: a leftover typed name must not contradict it.
      var manual = document.getElementById('parish_name_raw');
      if (manual && pick.value) { manual.value = ''; }
    });
  });

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
    /* The picker's escape hatch. When nothing matches — or the member simply cannot see their
       church — the last row offers to file it as "not listed yet" and reveals the typed-name field
       that registration already submits. Without this the only way forward was to leave the
       question blank, which is exactly what the office was unable to act on. */
    var manual = document.getElementById('parish-manual');
    var manualField = document.getElementById('parish_name_raw');
    function offerManual(term) {
      // Three characters, matching the server's own floor for an anonymous search. Offering the
      // escape hatch after one keystroke would invite people past a list that had barely loaded.
      if (!manual || !manualField || !term || term.length < 3) { return; }
      var li = document.createElement('li');
      li.className = 'combo-option combo-option-manual';
      li.id = combo.id + '-manual';
      li.setAttribute('role', 'option');
      li.innerHTML = '<strong></strong><small></small>';
      li.querySelector('strong').textContent = 'My church is not listed';
      li.querySelector('small').textContent = 'Add “' + term.toUpperCase() + '” and tell my representative';
      li.addEventListener('mousedown', function (event) {
        event.preventDefault();
        chooseManual(term);
      });
      list.appendChild(li);
      // Deliberately not pushed onto `options` here: that array is rebuilt from the finished list
      // at the end of render(), and an early push would make the arrow keys see a one-item list
      // while the screen showed fifteen parishes.
    }
    function chooseManual(term) {
      if (hidden) { hidden.value = ''; }
      input.value = '';
      manual.open = true;
      manualField.value = term.toUpperCase();
      close();
      manualField.focus();
      manualField.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function render(items, term) {
      list.innerHTML = '';
      options = [];
      if (!items.length) {
        var empty = document.createElement('li');
        empty.className = 'combo-empty';
        empty.textContent = term ? 'No parish matches “' + term + '”.' : 'Start typing your parish, area or zone.';
        list.appendChild(empty);
        offerManual(term);
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
        offerManual(term);
      }
      // Always rebuilt, in DOM order, so arrow keys, Enter and the highlight all address the
      // same nodes the member can see — including the manual row at the end.
      options = Array.prototype.slice.call(list.querySelectorAll('.combo-option'));
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }
    function choose(item) {
      // The manual row is an option in the same listbox, so it arrives here too. Sending it
      // through the normal path would set both fields to "undefined" and post a parish_id of
      // "undefined"; it is routed to its own handler instead.
      if (item && item.manual) { chooseManual(item.term); return; }
      input.value = item.label;
      if (hidden) { hidden.value = item.id; }
      // Picking from the list settles the question, so a half-typed church name must not ride
      // along and contradict it.
      if (manualField) { manualField.value = ''; }
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
        // The search did not run — offline, a CSP block, a 500. Say so instead of closing the list
        // and leaving an empty box, which is indistinguishable from "your church is not listed".
        showUnavailable(term);
      });
    }

    /* Fail visibly. The member is offered the manual route with their own words, and told why. */
    function showUnavailable(term) {
      list.innerHTML = '';
      options = [];
      var note = document.createElement('li');
      note.className = 'combo-empty';
      note.textContent = 'The parish list could not be loaded just now. Pick from the list on the form, or type your church name below.';
      list.appendChild(note);
      offerManual(term);
      options = Array.prototype.slice.call(list.querySelectorAll('.combo-option'));
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
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
          if (options[active].classList.contains('combo-option-manual')) {
            chooseManual(input.value.trim());
          } else {
            choose({ id: options[active].dataset.id, label: options[active].dataset.label });
          }
        }
      } else if (event.key === 'Escape') {
        close();
      }
    });
    input.addEventListener('focus', function () {
      // An empty term asks the server for the head of the directory, which is the useful answer
      // when somebody taps the box: fifteen churches, not "type three characters".
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

  /* ── Capitals as you type ──────────────────────────────────────────────────── */
  /* Every church name in the directory is stored in capitals, and the server applies that rule
     regardless. Doing it in the field too is not redundancy for its own sake: the member sees the
     value that will be saved, so a name typed in lower case never looks like it was accepted and
     then silently comes back shouting. The caret is preserved by rewriting only the value and
     putting the selection back where it was, or fast typing would jump to the end. */
  function upperCaseField(field) {
    var start = field.selectionStart;
    var end = field.selectionEnd;
    var upper = field.value.toUpperCase();
    if (upper === field.value) { return; }
    field.value = upper;
    try {
      if (start !== null && end !== null) { field.setSelectionRange(start, end); }
    } catch (e) { /* type=email has no selection API; nothing to restore */ }
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-caps]'), function (field) {
    field.addEventListener('input', function () { upperCaseField(field); });
    // A browser autofill or a paste does not always fire input; catch the value on blur too.
    field.addEventListener('change', function () { upperCaseField(field); });
  });

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
