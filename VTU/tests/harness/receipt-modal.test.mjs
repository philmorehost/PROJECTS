// DOM regression for the actual receipt markup/script and modal rules in both shells.
// Run: node VTU/tests/harness/receipt-modal.test.mjs (npm ci in this directory first).
import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const base = new URL('../../', import.meta.url);
const tick = () => new Promise(resolve => setTimeout(resolve, 0));
let checks = 0;
for (const edition of ['DGV7.0-SAAS', 'DGV7.0-NON-SAAS']) {
  const cssSource = fs.readFileSync(new URL(`${edition}/func/bc-plate-app.php`, base), 'utf8');
  // Use the production selector/rules, not a test implementation of the overlay.
  const css = [...cssSource.matchAll(/\.(?:pk-app__wrap|bc-receipt-host) \.modal(?:[\w .-]*)\s*\{[^}]+\}/g)].map(m => m[0]).join('\n');
  for (const shell of ['app', 'admin']) {
    const source = fs.readFileSync(new URL(`${edition}/func/bc-plate-${shell}.php`, base), 'utf8');
    const start = source.indexOf('<!-- Keep the footer receipt');
    assert.ok(start !== -1);
    const end = source.indexOf('</script>', start) + '</script>'.length;
    const fragment = source.slice(start, end);
    const script = fragment.match(/<script>([\s\S]*?)<\/script>/)[1];
    const markup = fragment.slice(0, fragment.indexOf('<script>'));
    const area = shell === 'app' ? 'web' : 'bc-admin';
    const dom = new JSDOM(`<!doctype html><style>${css}</style><main><div class="pk-app__wrap"><button id="trigger">Transaction</button></div></main>${markup}`, {
      url: `https://console.test/${area}/Transactions.php`, runScripts: 'outside-only', pretendToBeVisual: true,
    });
    const w = dom.window, d = w.document;
    const pending = [];
    w.fetch = (url, opts) => new Promise((resolve, reject) => pending.push({ url, opts, resolve, reject }));
    // Receipt must not invoke Bootstrap even if another page loaded it.
    w.bootstrap = { Modal: { getOrCreateInstance() { throw new Error('Unexpected Bootstrap backdrop'); } } };
    w.eval(script);
    const modal = d.getElementById('bcReceiptModal');
    const body = d.getElementById('bcReceiptBody');
    const trigger = d.getElementById('trigger');
    assert.equal(modal.style.display, 'none');
    trigger.focus();
    w.showTransactionDetails('REF & 1');
    assert.equal(w.getComputedStyle(modal).position, 'fixed', 'footer modal is a viewport overlay, not an in-flow footer block');
    assert.equal(w.getComputedStyle(modal).display, 'flex');
    assert.equal(w.getComputedStyle(modal).zIndex, '2000');
    assert.ok(modal.closest('.pk-app__wrap'), 'modal matches the shared scoped stylesheet');
    assert.equal(modal.getAttribute('aria-hidden'), 'false');
    assert.equal(modal.getAttribute('role'), 'dialog');
    assert.equal(d.activeElement, modal);
    assert.equal(pending[0].url, 'transaction-details.php?ref=REF%20%26%201');
    assert.equal(pending[0].opts.credentials, 'same-origin');
    w.showTransactionDetails('NEW');
    assert.ok(pending[0].opts.signal.aborted, 'superseded request aborted');
    const payload = ref => ({ ok: true, reference: ref, status_code: 1, status: 'Success', amount: '100', description: '<img src=x onerror=alert(1)>' });
    pending[1].resolve({ ok: true, json: async () => payload('NEW') });
    await tick();
    assert.ok(body.textContent.includes('NEW'));
    assert.equal(body.querySelector('img'), null, 'receipt data rendered as escaped text');
    pending[0].resolve({ ok: true, json: async () => payload('OLD') });
    await tick();
    assert.ok(body.textContent.includes('NEW') && !body.textContent.includes('OLD'), 'late response cannot replace the selected receipt');
    modal.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(modal.style.display, 'none');
    assert.equal(modal.getAttribute('aria-hidden'), 'true');
    assert.equal(d.activeElement, trigger, 'close restores focus to the row/button');
    w.showTransactionDetails('ERROR');
    pending[2].resolve({ ok: false });
    await tick();
    assert.ok(body.textContent.includes('Could not load'));
    assert.equal(modal.style.display, 'flex', 'request error is visible, not silently hidden');
    d.querySelector('[data-bc-receipt-close]').click();
    assert.equal(modal.style.display, 'none');
    w.showTransactionDetails('CLOSE');
    modal.click();
    assert.equal(modal.style.display, 'none', 'backdrop click closes');
    assert.ok(pending[3].opts.signal.aborted);
    assert.ok(d.getElementById('bcReceiptPdfBtn') && d.getElementById('bcReceiptImgBtn'), 'download controls preserved');
    dom.window.close();
    checks += 24;
    console.log(`PASS ${edition} ${shell}: overlay scope, open/close/focus, escaped data, request race and error handling`);
  }
}
console.log(`${checks} receipt DOM assertions passed (not a full browser/device test).`);
