/**
 * Offline banner behaviour.
 *
 * Loads the real public/assets/app.js in a small stubbed browser and drives the four things the
 * banner must do: appear on its own, appear only while actually offline, disappear the moment the
 * connection returns, and stay closed when the user closes it. No dependency beyond node:vm —
 * this is the one part of the app that only exists in the browser, so it needs its own harness.
 */
import { readFile } from 'node:fs/promises';
import { createContext, runInContext } from 'node:vm';

const source = await readFile(new URL('../../public/assets/app.js', import.meta.url), 'utf8');

const failures = [];
let checks = 0;
function check(name, condition, detail = '') {
  checks++;
  if (condition) {
    console.log(`PASS ${name}`);
  } else {
    failures.push(name);
    console.log(`FAIL ${name}${detail ? ' :: ' + String(detail).slice(0, 300) : ''}`);
  }
}

/** A fake page: just enough DOM for app.js, with a controllable connection. */
function browser({ online = true, reachable = true } = {}) {
  const listeners = {};
  const sheet = {
    contains: (node) => node === sheet || node === sheetChild,
  };
  const sheetChild = { id: 'inside' };
  const modal = {
    hidden: true,
    listeners: {},
    addEventListener(type, fn) { this.listeners[type] = fn; },
    querySelector: () => sheet,
    setAttribute(k, v) { (this.attrs = this.attrs || {})[k] = v; },
  };
  const elements = {
    'offline-modal': modal,
    'offline-close': { listeners: {}, addEventListener(type, fn) { this.listeners[type] = fn; } },
    'offline-retry': { listeners: {}, addEventListener(type, fn) { this.listeners[type] = fn; } },
    'offline-hint': { textContent: '' },
  };

  const window = {
    location: { href: 'https://example.test/dashboard', pathname: '/dashboard', reload() { window.reloaded = true; } },
    localStorage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
    matchMedia: () => ({ matches: false, addEventListener() {} }),
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    dispatch(type) { (listeners[type] || []).forEach((fn) => fn({ type })); },
    setTimeout, clearTimeout, setInterval, clearInterval,
    fetch: () => reachable
      ? Promise.resolve({ ok: true, status: 200 })
      : Promise.reject(new Error('network down')),
    AbortController,
    URLSearchParams,
    document: null,
    navigator: { onLine: online, userAgent: 'node' },
  };

  const document = {
    documentElement: { dataset: {}, classList: { list: new Set(), add(c) { this.list.add(c); }, remove(c) { this.list.delete(c); }, toggle(c, on) { on ? this.list.add(c) : this.list.delete(c); }, contains(c) { return this.list.has(c); } } },
    body: { style: {}, classList: { add() {}, remove() {}, contains() { return false; } }, dataset: {} },
    getElementById: (id) => elements[id] ?? null,
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener(type, fn) { (documentListeners[type] = documentListeners[type] || []).push(fn); },
    createElement: () => ({ style: {}, setAttribute() {}, appendChild() {} }),
    cookie: '',
    readyState: 'complete',
  };
  const documentListeners = {};
  window.document = document;

  const context = createContext({
    window, document, navigator: window.navigator, localStorage: window.localStorage,
    setTimeout, clearTimeout, setInterval, clearInterval, console,
    fetch: window.fetch, AbortController, URLSearchParams, Promise,
  });
  runInContext(source, context, { filename: 'app.js' });

  return {
    modal,
    window,
    document,
    sheet,
    sheetChild,
    close: () => elements['offline-close'].listeners.click(),
    retry: () => elements['offline-retry'].listeners.click(),
    backdropClick: () => modal.listeners.click({ target: { id: 'backdrop' } }),
    insideClick: () => modal.listeners.click({ target: sheetChild }),
    pressKey: (key) => (documentListeners.keydown || []).forEach((fn) => fn({ key })),
    scrollLocked: () => document.documentElement.classList.contains('offline-open'),
  };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

/* 1. Online page: nothing is shown. */
{
  const page = browser({ online: true, reachable: true });
  await settle();
  check('a connected page shows no notice', page.modal.hidden === true);
}

/* 2. Connection drops: the notice appears without any user action. */
{
  const page = browser({ online: true, reachable: true });
  await settle();
  page.window.navigator.onLine = false;
  page.window.dispatch('offline');
  check('going offline opens the notice automatically', page.modal.hidden === false);
  check('the page behind it cannot scroll', page.scrollLocked() === true);

  /* 3. Connection returns: closed at once, not on the next probe. */
  page.window.navigator.onLine = true;
  page.window.dispatch('online');
  check('coming back online closes it immediately', page.modal.hidden === true);
  check('scrolling is restored', page.scrollLocked() === false);
}

/* 4. The reported bug: it would not close. Every route must work. */
{
  const page = browser({ online: false, reachable: false });
  await settle();
  check('an offline page opens the notice on load', page.modal.hidden === false);

  page.close();
  check('the Dismiss button closes it', page.modal.hidden === true);
  check('closing restores page scrolling', page.scrollLocked() === false);

  // A probe while dismissed and still offline must not resurrect it.
  await settle();
  check('a dismissed notice stays closed during the same outage', page.modal.hidden === true);

  // Backdrop and Escape are the other two conventional ways out.
  const second = browser({ online: false, reachable: false });
  await settle();
  second.backdropClick();
  check('tapping the backdrop closes it', second.modal.hidden === true);

  const third = browser({ online: false, reachable: false });
  await settle();
  third.pressKey('Escape');
  check('the Escape key closes it', third.modal.hidden === true);

  const fourth = browser({ online: false, reachable: false });
  await settle();
  fourth.insideClick();
  check('tapping inside the sheet does not close it', fourth.modal.hidden === false);
}

/* 5. A dead connection with navigator.onLine still true (router up, no internet): the probe is
      what catches it, which is why navigator.onLine is never trusted on its own. */
{
  const page = browser({ online: true, reachable: false });
  await settle();
  check('a stalled connection opens the notice even though onLine says connected',
    page.modal.hidden === false);
}

/* 6. Recovery always wins: a stale dismissal must not keep the state wrong for the next outage. */
{
  const page = browser({ online: false, reachable: false });
  await settle();
  page.close();
  page.window.navigator.onLine = true;
  page.window.dispatch('online');
  check('recovery clears a dismissal', page.modal.hidden === true);
  page.window.navigator.onLine = false;
  page.window.dispatch('offline');
  check('the next outage opens the notice again after a dismissal',
    page.modal.hidden === false);
}

console.log('\n' + '─'.repeat(62));
console.log(`${checks - failures.length} passed, ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
