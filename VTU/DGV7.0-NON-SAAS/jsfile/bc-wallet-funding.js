/* Progressive enhancement only. Native POST + server validation work without this file. */
(() => {
  'use strict';

  const beewaveBox = document.querySelector('[data-beewave-checkout]');
  if (beewaveBox) {
    try {
      const cfg = JSON.parse(beewaveBox.getAttribute('data-beewave-checkout') || '{}');
      const launch = () => {
        if (typeof window.BeefinanceCheckout === 'undefined') {
          window.setTimeout(launch, 250);
          return;
        }
        window.BeefinanceCheckout.open({
          accessKey: cfg.public_key || '',
          name: cfg.name || '',
          email: cfg.email || '',
          phone: cfg.phone || '',
          amount: Number(cfg.charge_amount || 0),
          reference: cfg.reference || ''
        });
      };
      const s = document.createElement('script');
      s.src = 'https://merchant.beewave.ng/checkout.min.js';
      s.defer = true;
      s.onload = launch;
      document.head.appendChild(s);
      const launchBtn = document.getElementById('beewaveLaunchBtn');
      if (launchBtn) launchBtn.addEventListener('click', launch);
    } catch (_) {}
  }

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
      : 'Ready to proceed to server-verified payment checkout.';
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
    status.textContent = 'Preparing payment checkout...';
    window.setTimeout(reset, 8000);
  });
  window.addEventListener('pageshow', reset);
  const result = document.querySelector('[data-fund-success], [data-fund-error], [data-fund-bank]');
  if (result) result.focus();
})();
