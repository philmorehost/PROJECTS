/* Progressive enhancement only. Native POST + server validation work without this file. */
(() => {
  'use strict';
  const form = document.querySelector('[data-wallet-funding]');
  if (!form) return;
  const amount = form.elements.namedItem('amount');
  const button = form.querySelector('button[type="submit"]');
  const status = form.querySelector('[role="status"]');
  if (!amount || !button || !status) return;
  const unavailable = button.disabled;
  let busy = false;
  function minor(value) {
    if (!/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/.test(value)) return null;
    const parts = value.split('.');
    const n = Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0'));
    return n >= 10000 && n <= 100000000 ? n : null;
  }
  function reset() {
    busy = false;
    button.disabled = unavailable;
    form.removeAttribute('aria-busy');
  }
  amount.addEventListener('input', () => {
    amount.setCustomValidity('');
    if (!unavailable) status.textContent = minor(amount.value) === null
      ? 'Enter NGN 100.00–1,000,000.00 with at most two decimal places.'
      : 'Ready to request bank details. No payment will be charged by this form.';
  });
  form.addEventListener('submit', event => {
    if (unavailable || busy) { event.preventDefault(); return; }
    if (minor(amount.value) === null) {
      event.preventDefault();
      const message = 'Enter NGN 100.00–1,000,000.00 with at most two decimal places.';
      status.textContent = message;
      amount.setCustomValidity(message);
      amount.reportValidity();
      amount.focus();
      return;
    }
    busy = true;
    button.disabled = true;
    form.setAttribute('aria-busy', 'true');
    status.textContent = 'Checking your bank transfer instructions. No money has been sent.';
    // Recover if navigation is interrupted; the POST is read-only and safe to resubmit.
    window.setTimeout(reset, 5000);
  });
  window.addEventListener('pageshow', reset);
  const result = document.querySelector('[data-fund-error], [data-fund-bank]');
  if (result) result.focus();
})();
