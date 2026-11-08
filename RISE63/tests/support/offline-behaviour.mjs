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
  const banner = { hidden: true, offsetHeight: 34, attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } };
  const elements = {
    'offline-banner': banner,
    'offline-dismiss': { listeners: {}, addEventListener(type, fn) { this.listeners[type] = fn; } },
  };

  const window = {
    location: { href: 'https://example.test/dashboard', pathname: '/dashboard' },
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
    documentElement: { dataset: {} },
    body: { style: {}, classList: { add() {}, remove() {} } },
    getElementById: (id) => elements[id] ?? null,
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener() {},
    createElement: () => ({ style: {}, setAttribute() {}, appendChild() {} }),
    cookie: '',
    readyState: 'complete',
  };
  window.document = document;

  const context = createContext({
    window, document, navigator: window.navigator, localStorage: window.localStorage,
    setTimeout, clearTimeout, setInterval, clearInterval, console,
    fetch: window.fetch, AbortController, URLSearchParams, Promise,
  });
  runInContext(source, context, { filename: 'app.js' });
  return { banner, window, elements, dismiss: () => elements['offline-dismiss'].listeners.click() };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

/* 1. Online page: nothing is shown. */
{
  const page = browser({ online: true, reachable: true });
  await settle();
  check('a connected page shows no banner', page.banner.hidden === true);
}

/* 2. Connection drops: the banner appears without any user action. */
{
  const page = browser({ online: true, reachable: true });
  await settle();
  page.window.navigator.onLine = false;
  page.window.dispatch('offline');
  check('going offline shows the banner automatically', page.banner.hidden === false);

  /* 3. Connection returns: removed at once, not on the next probe. */
  page.window.navigator.onLine = true;
  page.window.dispatch('online');
  check('coming back online hides the banner immediately', page.banner.hidden === true);
}

/* 4. Manual close: hidden, and it stays closed while still offline. */
{
  const page = browser({ online: false, reachable: false });
  await settle();
  check('an offline page shows the banner on load', page.banner.hidden === false);
  page.dismiss();
  check('closing the banner hides it', page.banner.hidden === true);
  // A probe while dismissed and still offline must not resurrect it.
  await settle();
  check('a dismissed banner stays closed during the same outage', page.banner.hidden === true);
}

/* 5. A dead connection with navigator.onLine still true (router up, no internet): the probe is
      what catches it, which is why navigator.onLine is never trusted on its own. */
{
  const page = browser({ online: true, reachable: false });
  await settle();
  check('a stalled connection shows the banner even though onLine says connected',
    page.banner.hidden === false);
}

/* 6. Recovery always wins: once the connection is back, a stale dismissal must not keep the
      banner state wrong for the *next* outage. */
{
  const page = browser({ online: false, reachable: false });
  await settle();
  page.dismiss();
  page.window.navigator.onLine = true;
  page.window.dispatch('online');
  check('recovery clears a dismissal', page.banner.hidden === true);
  page.window.navigator.onLine = false;
  page.window.dispatch('offline');
  check('the next outage shows the banner again after a dismissal was cleared',
    page.banner.hidden === false);
}

console.log('\n' + '─'.repeat(62));
console.log(`${checks - failures.length} passed, ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
