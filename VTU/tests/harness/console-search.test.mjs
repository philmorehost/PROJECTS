import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import assert from 'node:assert/strict';
for (const edition of ['DGV7.0-SAAS', 'DGV7.0-NON-SAAS']) {
  const source = fs.readFileSync(new URL(`../../${edition}/jsfile/bc-custom-all.js`, import.meta.url), 'utf8');
  const search = source.slice(source.indexOf('    // Site-wide real-time search:'));
  const dom = new JSDOM('<!doctype html><body></body>', { url: 'https://console.test/web/Transactions.php', runScripts: 'outside-only' });
  const w = dom.window, d = w.document;
  w.MutationObserver = function () { throw new Error('Whole-document observer must not be installed'); };
  const timers = new Map(); let id = 0, submits = 0;
  w.setTimeout = fn => { timers.set(++id, fn); return id; };
  w.clearTimeout = key => timers.delete(key);
  w.eval(search);
  // Input inserted after initialization: delegation must handle it without rescans.
  d.body.innerHTML = '<form><input name="searchq"></form>';
  const form = d.querySelector('form'), input = form.firstChild;
  form.submit = () => submits++;
  input.value = 'abc';
  input.dispatchEvent(new w.Event('input', { bubbles: true }));
  input.value = 'abcd';
  input.dispatchEvent(new w.Event('input', { bubbles: true }));
  assert.equal(timers.size, 1, 'typing is debounced');
  [...timers.values()][0](); timers.clear();
  assert.equal(submits, 1);
  assert.equal(w.sessionStorage.getItem('lastSearchValue'), 'abcd');
  input.dispatchEvent(new w.Event('input', { bubbles: true }));
  form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  assert.equal(timers.size, 0, 'manual submit cancels the delayed search');
  input.dispatchEvent(new w.Event('input', { bubbles: true }));
  form.remove();
  [...timers.values()][0]();
  assert.equal(submits, 1, 'detached forms do not submit');
  dom.window.close();
  console.log(`PASS ${edition}: delegated dynamic search, debounce, manual submit cancellation, detached form`);
}
