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
  function round2(n) {
    return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
  }
  function fmtNgn(n) {
    return 'NGN ' + Number(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function computeGatewayFee(rule, gross) {
    const g = Math.max(0, round2(gross));
    if (g <= 0) return 0;
    const pct = Math.max(0, Number(rule.percent || 0));
    const flat = Math.max(0, Number(rule.flat || 0));
    const waive = Math.max(0, Number(rule.waive_flat_below || 0));
    const cap = Math.max(0, Number(rule.cap || 0));
    const applyFlat = (flat > 0 && (waive <= 0 || g >= waive)) ? flat : 0;
    let fee = (g * (pct / 100)) + applyFlat;
    if (cap > 0) fee = Math.min(cap, fee);
    return Math.max(0, round2(fee));
  }
  function solveGrossForNet(rule, netNeeded) {
    const net = Math.max(0, round2(netNeeded));
    if (net <= 0) return 0;
    const rate = Math.max(0, Number(rule.percent || 0)) / 100;
    const flat = Math.max(0, Number(rule.flat || 0));
    const waive = Math.max(0, Number(rule.waive_flat_below || 0));
    const cap = Math.max(0, Number(rule.cap || 0));
    if (rate >= 0.99) return net;
    const cands = [];
    if (rate > 0) {
      const c1 = round2(net / (1 - rate));
      if (waive <= 0 || c1 < waive) cands.push(c1);
    } else {
      cands.push(net);
    }
    cands.push(round2((net + flat) / (1 - rate)));
    if (cap > 0) cands.push(round2(net + cap));
    let best = null;
    for (const cand of cands) {
      if (cand < net) continue;
      for (let k = 0; k <= 3; k++) {
        const t = round2(cand + k * 0.01);
        const f = computeGatewayFee(rule, t);
        if (round2(t - f) >= net) {
          if (best === null || t < best) best = t;
          break;
        }
      }
    }
    return best !== null ? best : net;
  }
  const previewBox = form.querySelector('[data-fee-preview]');
  function updateFeePreview() {
    if (!previewBox) return;
    const checked = form.querySelector('input[name="gateway"]:checked');
    const rawCfg = checked ? checked.getAttribute('data-fee-config') : '';
    const m = minor(amount.value.trim());
    if (!rawCfg || m === null) {
      previewBox.style.display = 'none';
      return;
    }
    try {
      const cfg = JSON.parse(rawCfg);
      const credit = m / 100;
      const maxMarkup = Math.max(1, Number(cfg.max_markup_percent ?? 1));
      const markupPct = Math.min(maxMarkup, Math.max(0, Number(cfg.markup_percent ?? 0.5)));
      const vendorMarkup = round2(credit * (markupPct / 100));
      const netNeeded = round2(credit + vendorMarkup);
      const reqAmount = solveGrossForNet(cfg, netNeeded);
      const gwFee = Math.max(0, round2(reqAmount - netNeeded));
      const surchargePct = Math.max(0, Number(cfg.checkout_surcharge_percent || 0));
      const surcharge = surchargePct > 0 ? round2(reqAmount * (surchargePct / 100)) : 0;
      const userPay = round2(reqAmount + surcharge);
      const totalGwFee = round2(gwFee + surcharge);

      const elCredit = previewBox.querySelector('[data-fee-credit]');
      const elGw = previewBox.querySelector('[data-fee-gw]');
      const elGwLbl = previewBox.querySelector('[data-fee-gw-label]');
      const elMarkupPct = previewBox.querySelector('[data-fee-markup-pct]');
      const elMarkup = previewBox.querySelector('[data-fee-markup]');
      const elTotal = previewBox.querySelector('[data-fee-total]');
      const elNote = previewBox.querySelector('[data-fee-note]');

      if (elCredit) elCredit.textContent = fmtNgn(credit);
      if (elGw) elGw.textContent = fmtNgn(totalGwFee);
      if (elGwLbl) elGwLbl.textContent = cfg.gateway_fee_label || 'Auto';
      if (elMarkupPct) elMarkupPct.textContent = Number(markupPct.toFixed(2)) + '%';
      if (elMarkup) elMarkup.textContent = fmtNgn(vendorMarkup);
      if (elTotal) elTotal.textContent = fmtNgn(userPay);
      if (elNote) {
        if (surcharge > 0) {
          elNote.style.display = 'block';
          elNote.textContent = 'Includes gateway settlement + checkout processing fee.';
        } else {
          elNote.style.display = 'none';
        }
      }
      previewBox.style.display = 'block';
    } catch (_) {
      previewBox.style.display = 'none';
    }
  }
  function reset() {
    busy = false;
    button.disabled = unavailable;
    form.removeAttribute('aria-busy');
  }
  amount.addEventListener('input', () => {
    amount.setCustomValidity('');
    updateFeePreview();
    if (!unavailable) status.textContent = minor(amount.value) === null
      ? 'Enter NGN 100.00–1,000,000.00 with at most two decimal places.'
      : 'Ready to proceed to server-verified payment checkout.';
  });
  form.querySelectorAll('input[name="gateway"]').forEach(r => {
    r.addEventListener('change', updateFeePreview);
  });
  updateFeePreview();
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
