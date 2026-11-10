/**
 * BLOCKPAYNE — Live Production Zero-Reload SPA Engine, Multi-Language Code Switcher,
 * Command Palette (Ctrl+K), Live Blockchain Watcher & Instant Country Firewall Search.
 */
(function () {
  'use strict';

  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const baseMeta = document.querySelector('meta[name="bp-base"]');
  const CSRF = csrfMeta ? csrfMeta.getAttribute('content') || '' : '';
  const BASE = baseMeta ? (baseMeta.getAttribute('content') || '').replace(/\/$/, '') : '';

  function url(path) {
    const clean = String(path || '').replace(/^\/+/, '');
    return BASE + '/' + clean;
  }

  function esc(val) {
    const div = document.createElement('div');
    div.textContent = val == null ? '' : String(val);
    return div.innerHTML;
  }

  function toast(msg, type) {
    let stack = document.getElementById('bp-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'bp-toast-stack';
      stack.className = 'bp-toast-stack';
      document.body.appendChild(stack);
    }
    const el = document.createElement('div');
    el.className = 'bp-toast' + (type === 'error' ? ' error' : '');
    el.textContent = msg;
    stack.appendChild(el);
    setTimeout(function () {
      el.style.opacity = '0';
      el.style.transition = 'opacity 0.25s ease';
      setTimeout(function () { el.remove(); }, 260);
    }, 3400);
  }

  async function copyText(text, label) {
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
      }
      toast((label || 'Copied') + ' to clipboard!');
    } catch (_) {
      toast('Press Ctrl+C to copy', 'error');
    }
  }

  async function apiPost(endpoint, body) {
    const res = await fetch(url(endpoint), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': CSRF
      },
      body: JSON.stringify(body || {})
    });
    return res.json().catch(function () {
      return { ok: false, error: 'Unexpected server response.' };
    });
  }

  async function apiGet(endpoint) {
    const res = await fetch(url(endpoint), {
      headers: { 'Accept': 'application/json' }
    });
    return res.json().catch(function () {
      return { ok: false };
    });
  }

  window.BP = { toast, copyText, apiPost, apiGet, url, esc };

  /* ─────────────────────────── Global Copy & Multi-Language Code Tabs ─────────────────────────── */
  document.addEventListener('click', function (e) {
    const copyAttrBtn = e.target.closest('[data-copy]');
    if (copyAttrBtn) {
      copyText(copyAttrBtn.getAttribute('data-copy') || '', 'Copied');
      return;
    }

    const copyTargetBtn = e.target.closest('[data-copy-target]');
    if (copyTargetBtn) {
      const target = document.getElementById(copyTargetBtn.getAttribute('data-copy-target') || '');
      if (target) copyText(target.value || target.textContent || '', 'Copied');
      return;
    }

    const copyCodeBtn = e.target.closest('[data-copy-code]');
    if (copyCodeBtn) {
      const pane = copyCodeBtn.closest('.bp-code-pane');
      const codeEl = pane ? pane.querySelector('code') : null;
      if (codeEl) copyText(codeEl.textContent || '', 'Code snippet copied');
      return;
    }

    const langTab = e.target.closest('.bp-code-tab[data-lang]');
    if (langTab) {
      const group = langTab.closest('.bp-code-tabs');
      const lang = langTab.getAttribute('data-lang');
      if (!group || !lang) return;
      group.querySelectorAll('.bp-code-tab').forEach(function (b) {
        b.classList.toggle('active', b === langTab);
      });
      group.querySelectorAll('.bp-code-pane').forEach(function (p) {
        p.classList.toggle('bp-hidden', p.getAttribute('data-pane') !== lang);
      });
    }
  });

  // Live API Key Injection Toggle on /api-docs
  const injectKeysBtn = document.getElementById('bp-docs-inject-keys');
  if (injectKeysBtn) {
    let injected = false;
    injectKeysBtn.addEventListener('click', function () {
      const liveKey = injectKeysBtn.getAttribute('data-live-key') || '';
      const liveSec = injectKeysBtn.getAttribute('data-live-secret') || '';
      if (!liveKey) return;

      document.querySelectorAll('.bp-docs-code').forEach(function (codeEl) {
        let txt = codeEl.textContent || '';
        if (!injected) {
          txt = txt.split('bp_live_YOUR_API_KEY').join(liveKey);
          if (liveSec) txt = txt.split('whsec_YOUR_WEBHOOK_SECRET').join(liveSec);
        } else {
          txt = txt.split(liveKey).join('bp_live_YOUR_API_KEY');
          if (liveSec) txt = txt.split(liveSec).join('whsec_YOUR_WEBHOOK_SECRET');
        }
        codeEl.textContent = txt;
      });
      injected = !injected;
      const span = injectKeysBtn.querySelector('span');
      if (span) span.textContent = injected ? 'Hide My Live Keys' : 'Insert My Live Keys';
      toast(injected ? 'Live API credentials inserted into code examples!' : 'Placeholder keys restored.');
    });
  }

  /* ─────────────────────────── 1. Landing Page Live Blockchain Inspector ─────────────────────────── */
  const lookupForm = document.getElementById('bp-lookup-form');
  if (lookupForm) {
    lookupForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      const input = document.getElementById('bp-lookup-input');
      const out = document.getElementById('bp-lookup-result');
      const q = (input ? input.value : '').trim();
      if (!q || !out) return;

      if (/^(INV-|TOPUP-)/i.test(q)) {
        window.location.href = url('pay/' + encodeURIComponent(q.toUpperCase()));
        return;
      }

      out.classList.remove('bp-hidden');
      out.innerHTML = '<div class="bp-muted">Querying live blockchain network…</div>';
      const data = await apiGet('api/lookup?q=' + encodeURIComponent(q));
      if (!data.ok) {
        out.innerHTML = '<div style="color:#fca5a5;">' + esc(data.error || 'Address or invoice not found.') + '</div>';
        return;
      }
      if (data.type === 'xpub') {
        const rows = (data.samples || []).map(function (s) {
          return '<div class="bp-row-between" style="padding:6px 0;border-bottom:1px solid #1e293b;">' +
            '<span class="bp-subtle bp-mono">m/0/' + esc(s.index) + '</span>' +
            '<span class="bp-mono" style="font-size:13px;word-break:break-all;">' + esc(s.address) + '</span></div>';
        }).join('');
        out.innerHTML = '<div class="bp-badge bp-badge-success bp-mb-2">Valid Non-Custodial HD Key</div>' + rows;
      } else {
        const expLink = data.explorer_url
          ? ' <a href="' + esc(data.explorer_url) + '" target="_blank" rel="noopener" style="color:#34d399;font-size:12px;text-decoration:underline;margin-left:6px;">Explorer &rarr;</a>'
          : '';
        out.innerHTML =
          '<div class="bp-row-between">' +
          '<div><span class="bp-badge bp-badge-info">' + esc(data.coin) + '</span> ' +
          '<span class="bp-mono" style="font-size:13px;margin-left:6px;word-break:break-all;">' + esc(data.address) + '</span>' + expLink + '</div>' +
          '<div style="text-align:right;"><strong>' + esc(data.balance) + ' ' + esc(data.coin) + '</strong>' +
          '<div class="bp-subtle" style="font-size:12px;">' + esc(data.tx_count) + ' on-chain tx(s)</div></div></div>';
      }
    });
  }

  /* ─────────────────────────── 2. Installer Stage 3 Live Brand Preview ─────────────────────────── */
  const titleInput = document.getElementById('bp-install-title');
  const colorInput = document.getElementById('bp-install-color');
  const previewBadge = document.getElementById('bp-install-badge-preview');
  if (titleInput && previewBadge) {
    const syncBrand = function () {
      const val = (titleInput.value || 'BlockPayne').trim();
      const ch = (val.match(/[a-zA-Z0-9]/) || [val.charAt(0) || 'B'])[0].toUpperCase();
      previewBadge.textContent = ch;
      if (colorInput && /^#[0-9a-fA-F]{6}$/.test(colorInput.value)) {
        previewBadge.style.setProperty('--bp-accent', colorInput.value);
      }
    };
    titleInput.addEventListener('input', syncBrand);
    if (colorInput) colorInput.addEventListener('input', syncBrand);
  }

  /* ─────────────────────────── 3. Live Buyer Checkout Blockchain Watcher ─────────────────────────── */
  if (window.BP_CHECKOUT) {
    initCheckoutWatcher(window.BP_CHECKOUT);
  }

  function initCheckoutWatcher(cfg) {
    let remaining = Number(cfg.remaining_seconds || 0);
    let currentStatus = cfg.status || 'pending';
    const timerEl = document.getElementById('bp-checkout-timer');

    if (timerEl && remaining > 0) {
      setInterval(function () {
        if (currentStatus === 'confirmed' || remaining <= 0) return;
        remaining = Math.max(0, remaining - 1);
        const m = String(Math.floor(remaining / 60)).padStart(2, '0');
        const s = String(remaining % 60).padStart(2, '0');
        timerEl.textContent = m + ':' + s;
      }, 1000);
    }

    function applyCheckoutStatus(inv) {
      if (!inv) return;
      const prev = currentStatus;
      currentStatus = inv.status;

      const s1 = document.getElementById('bp-step-pending');
      const s2 = document.getElementById('bp-step-detected');
      const s3 = document.getElementById('bp-step-confirmed');
      const banner = document.getElementById('bp-checkout-status-banner');
      const txBox = document.getElementById('bp-checkout-tx');
      const expLink = document.getElementById('bp-checkout-explorer-link');
      const swBox = document.getElementById('bp-checkout-coin-switcher');
      if (swBox && currentStatus !== 'pending') {
        swBox.classList.add('bp-hidden');
      }

      if (s1 && s2 && s3) {
        s1.className = 'bp-step ' + (currentStatus === 'pending' ? 'active' : 'done');
        s2.className = 'bp-step ' + (currentStatus === 'detected' ? 'active' : (currentStatus === 'confirmed' ? 'done' : ''));
        s3.className = 'bp-step ' + (currentStatus === 'confirmed' ? 'done' : '');
      }

      if (banner) {
        if (currentStatus === 'detected') {
          banner.className = 'bp-badge bp-badge-info';
          banner.textContent = 'Payment Detected in Mempool (0-Conf)';
        } else if (currentStatus === 'confirmed') {
          banner.className = 'bp-badge bp-badge-success';
          banner.textContent = 'Confirmed (' + (inv.confirmations || 1) + ' conf)';
        } else if (currentStatus === 'expired') {
          banner.className = 'bp-badge bp-badge-danger';
          banner.textContent = 'Invoice Expired';
        }
      }

      if (txBox && inv.tx_hash) {
        txBox.classList.remove('bp-hidden');
        txBox.textContent = 'TX: ' + inv.tx_hash;
      }
      if (expLink && inv.explorer_url) {
        expLink.setAttribute('href', inv.explorer_url);
      }

      if (prev !== currentStatus) {
        if (currentStatus === 'detected') toast('Incoming transaction detected in mempool!');
        if (currentStatus === 'confirmed') {
          toast('Payment confirmed on-chain!');
          const qrArea = document.getElementById('bp-checkout-pay-box');
          const doneArea = document.getElementById('bp-checkout-done-box');
          if (qrArea && doneArea) {
            qrArea.classList.add('bp-hidden');
            doneArea.classList.remove('bp-hidden');
          }
          if (inv.redirect_url) {
            setTimeout(function () { window.location.href = inv.redirect_url; }, 2200);
          }
        }
      }
    }

    async function pollChainOnce(showFeedback) {
      const res = await apiGet('api/invoice-status?invoice_id=' + encodeURIComponent(cfg.invoice_id));
      if (res.ok && res.invoice) {
        const before = currentStatus;
        applyCheckoutStatus(res.invoice);
        if (showFeedback && before === res.invoice.status && res.invoice.status === 'pending') {
          toast('Checked live blockchain — awaiting incoming transaction.');
        }
      }
    }

    if (currentStatus === 'pending' || currentStatus === 'detected') {
      const interval = setInterval(function () {
        if (currentStatus === 'confirmed' || currentStatus === 'expired') {
          clearInterval(interval);
          return;
        }
        pollChainOnce(false);
      }, 6000);
    }

    const checkBtn = document.getElementById('bp-btn-check-chain');
    if (checkBtn) {
      checkBtn.addEventListener('click', async function () {
        checkBtn.disabled = true;
        await pollChainOnce(true);
        checkBtn.disabled = false;
      });
    }

    // Live buyer coin-switcher handler on `/pay/{invoice_id}`
    document.querySelectorAll('[data-switch-coin]').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        if (currentStatus !== 'pending') {
          toast('Payment already detected; network is locked.', 'error');
          return;
        }
        const newCoin = btn.getAttribute('data-switch-coin');
        if (!newCoin || btn.classList.contains('active')) return;
        btn.disabled = true;
        const res = await apiPost('api/switch-coin', { invoice_id: cfg.invoice_id, currency: newCoin });
        btn.disabled = false;
        if (!res.ok || !res.invoice) {
          toast(res.error || 'Could not switch payment network.', 'error');
          return;
        }
        const inv = res.invoice;
        document.querySelectorAll('[data-switch-coin]').forEach(function (b) {
          b.classList.toggle('active', b.getAttribute('data-switch-coin') === inv.currency);
        });
        const pill = document.getElementById('bp-checkout-coin-pill');
        if (pill) pill.textContent = inv.currency;
        const amtLabel = document.getElementById('bp-checkout-amt-label');
        if (amtLabel) amtLabel.textContent = 'Send Exact Amount (' + inv.currency + ')';
        const addrLabel = document.getElementById('bp-checkout-addr-label');
        if (addrLabel) addrLabel.textContent = inv.currency + ' Deposit Address';
        const amtIn = document.getElementById('bp-copy-amt');
        if (amtIn) amtIn.value = inv.crypto_amount;
        const addrIn = document.getElementById('bp-copy-addr');
        if (addrIn) addrIn.value = inv.deposit_address;
        const uriBtn = document.getElementById('bp-checkout-uri-btn');
        if (uriBtn && inv.payment_uri) uriBtn.setAttribute('href', inv.payment_uri);
        const expLink = document.getElementById('bp-checkout-explorer-link');
        if (expLink && inv.explorer_url) expLink.setAttribute('href', inv.explorer_url);
        const qrWrap = document.getElementById('bp-checkout-qr-wrap');
        if (qrWrap && inv.qr_svg) qrWrap.innerHTML = inv.qr_svg;
        toast('Switched payment network to ' + inv.currency);
      });
    });
  }

  /* ─────────────────────────── 4. Zero-Reload SPA Dashboard ─────────────────────────── */
  if (window.BP_SPA_STATE) {
    initSpaDashboard(window.BP_SPA_STATE);
  }

  function initSpaDashboard(initialState) {
    let state = initialState;
    let countriesCache = (state.admin && state.admin.countries) ? state.admin.countries : null;
    let countryFilterQuery = '';
    let countryFilterAction = 'ALL';

    const sidebarDrawer = document.getElementById('bp-sidebar-drawer');
    const drawerOverlay = document.getElementById('bp-drawer-overlay');

    function closeDrawer() {
      if (sidebarDrawer) sidebarDrawer.classList.remove('drawer-open');
      if (drawerOverlay) drawerOverlay.classList.add('bp-hidden');
    }

    function toggleDrawer() {
      if (!sidebarDrawer || !drawerOverlay) return;
      const isOpen = sidebarDrawer.classList.toggle('drawer-open');
      drawerOverlay.classList.toggle('bp-hidden', !isOpen);
    }

    document.querySelectorAll('[data-toggle-drawer]').forEach(function (b) {
      b.addEventListener('click', toggleDrawer);
    });
    document.querySelectorAll('[data-close-drawer]').forEach(function (b) {
      b.addEventListener('click', closeDrawer);
    });

    const params = new URLSearchParams(window.location.search);
    let activeTab = params.get('tab') || 'overview';

    // Clean URL after server-side Payhub return verification
    if (params.get('payhub_ref') || params.get('reference') || params.get('trxref')) {
      history.replaceState(null, '', url('dashboard?tab=prefund'));
    }

    function setTab(tab) {
      activeTab = tab;
      closeDrawer();
      document.querySelectorAll('[data-spa-panel]').forEach(function (el) {
        el.classList.toggle('bp-hidden', el.getAttribute('data-spa-panel') !== tab);
      });
      document.querySelectorAll('[data-spa-tab]').forEach(function (btn) {
        btn.classList.toggle('active', btn.getAttribute('data-spa-tab') === tab);
      });
      history.replaceState(null, '', url('dashboard?tab=' + encodeURIComponent(tab)));

      if (tab === 'admin_countries' && !countriesCache) {
        loadCountries();
      }
    }

    async function loadCountries() {
      const wrap = document.getElementById('bp-countries-tbody');
      if (wrap) wrap.innerHTML = '<tr><td colspan="3" class="bp-muted">Loading 250 ISO countries…</td></tr>';
      const res = await apiPost('api/spa', { action: 'refresh', include_countries: 1 });
      if (res.state && res.state.admin && res.state.admin.countries) {
        state = res.state;
        countriesCache = res.state.admin.countries;
        renderCountries();
      }
    }

    async function runAction(action, payload, onSuccess) {
      const res = await apiPost('api/spa', Object.assign({ action: action }, payload || {}));
      if (!res.ok) {
        toast(res.error || 'Action could not be completed.', 'error');
        return res;
      }
      if (res.message) toast(res.message);
      if (res.state) {
        const prevMap = {};
        (state.invoices || []).forEach(function (i) { prevMap[i.invoice_id] = i.status; });
        (res.state.invoices || []).forEach(function (i) {
          if (prevMap[i.invoice_id] && prevMap[i.invoice_id] !== 'confirmed' && i.status === 'confirmed') {
            toast('Payment Confirmed: ' + i.invoice_id + ' ($' + i.fiat_amount + ')!');
          }
        });
        state = res.state;
        if (state.admin && state.admin.countries) {
          countriesCache = state.admin.countries;
        }
        renderAll();
      }
      if (onSuccess) onSuccess(res);
      return res;
    }

    function statusBadge(st) {
      if (st === 'confirmed' || st === 'active') return '<span class="bp-badge bp-badge-success">' + esc(st) + '</span>';
      if (st === 'detected') return '<span class="bp-badge bp-badge-info">detected (0-conf)</span>';
      if (st === 'pending') return '<span class="bp-badge bp-badge-warn">pending</span>';
      return '<span class="bp-badge bp-badge-danger">' + esc(st) + '</span>';
    }

    function kingCrownSvg() {
      return '<span class="bp-king-badge" title="Recognized Trusted IP (5+ clean logins across sessions)">' +
        '<span class="bp-king-dot"></span>' +
        '<svg width="13" height="13" viewBox="0 0 24 24" fill="#10b981" stroke="#6ee7b7" stroke-width="1.5" aria-hidden="true"><path d="M3 18h18l-2-11-5 4-4-6-4 6-5-4z"/><rect x="3" y="19" width="18" height="2" rx="1"/></svg>' +
        '<span>Trusted IP</span></span>';
    }

    function renderAll() {
      document.querySelectorAll('[data-bind="prefund_balance"]').forEach(function (el) {
        el.textContent = '$' + state.prefund_balance;
      });
      document.querySelectorAll('[data-bind="confirmed_volume"]').forEach(function (el) {
        el.textContent = '$' + (state.kpis ? state.kpis.confirmed_volume_usd : '0.00');
      });
      document.querySelectorAll('[data-bind="confirmed_count"]').forEach(function (el) {
        el.textContent = String(state.kpis ? state.kpis.confirmed_count : 0);
      });
      document.querySelectorAll('[data-bind="wallets_count"]').forEach(function (el) {
        el.textContent = String(state.kpis ? state.kpis.wallets_count : 0);
      });
      document.querySelectorAll('[data-bind="api_key"]').forEach(function (el) {
        el.value = state.user.api_key || '';
      });
      document.querySelectorAll('[data-bind="webhook_secret"]').forEach(function (el) {
        el.value = state.user.webhook_secret || '';
      });

      const invHtml = (state.invoices || []).length === 0
        ? '<tr><td colspan="5" class="bp-muted">No invoices yet. Click <strong>New Invoice</strong> or use the REST API to accept crypto.</td></tr>'
        : state.invoices.map(function (inv) {
          return '<tr>' +
            '<td><a href="' + esc(inv.checkout_path) + '" class="bp-mono" style="color:#34d399;font-weight:600;">' + esc(inv.invoice_id) + '</a>' +
            (inv.order_id ? '<div class="bp-subtle" style="font-size:11.5px;">#' + esc(inv.order_id) + '</div>' : '') + '</td>' +
            '<td><strong>$' + esc(inv.fiat_amount) + '</strong> <span class="bp-subtle">' + esc(inv.fiat_currency) + '</span></td>' +
            '<td class="bp-mono">' + esc(inv.crypto_amount) + ' <span class="bp-badge bp-badge-neutral">' + esc(inv.currency) + '</span></td>' +
            '<td>' + statusBadge(inv.status) + '</td>' +
            '<td style="text-align:right;"><a class="bp-btn bp-btn-ghost bp-btn-sm" href="' + esc(inv.checkout_path) + '">Open</a></td>' +
            '</tr>';
        }).join('');

      const recentTbody = document.getElementById('bp-recent-invoices-tbody');
      if (recentTbody) {
        const top5 = (state.invoices || []).slice(0, 6);
        recentTbody.innerHTML = top5.length === 0 ? invHtml : top5.map(function (inv) {
          return '<tr>' +
            '<td><a href="' + esc(inv.checkout_path) + '" class="bp-mono" style="color:#34d399;font-weight:600;">' + esc(inv.invoice_id) + '</a></td>' +
            '<td>$' + esc(inv.fiat_amount) + '</td>' +
            '<td class="bp-mono">' + esc(inv.crypto_amount) + ' ' + esc(inv.currency) + '</td>' +
            '<td>' + statusBadge(inv.status) + '</td>' +
            '<td style="text-align:right;"><a class="bp-btn bp-btn-ghost bp-btn-sm" href="' + esc(inv.checkout_path) + '">Pay Page</a></td></tr>';
        }).join('');
      }
      const allInvTbody = document.getElementById('bp-all-invoices-tbody');
      if (allInvTbody) allInvTbody.innerHTML = invHtml;

      // Wallets list
      const walletsWrap = document.getElementById('bp-wallets-list');
      if (walletsWrap) {
        if (!state.wallets || state.wallets.length === 0) {
          walletsWrap.innerHTML = '<div class="bp-card bp-muted">No wallets connected yet. Connect your BTC zPub/xPub or crypto receiving address to start accepting direct payments.</div>';
        } else {
          walletsWrap.innerHTML = state.wallets.map(function (w) {
            return '<div class="bp-card-compact bp-row-between">' +
              '<div>' +
              '<div class="bp-flex-gap"><span class="bp-badge bp-badge-info">' + esc(w.coin_symbol) + '</span>' +
              '<strong>' + esc(w.wallet_label) + '</strong>' + statusBadge(w.status) + '</div>' +
              '<div class="bp-mono bp-subtle bp-mt-2" style="font-size:12.5px;">' + esc(w.derivation_mode.toUpperCase()) + ': ' + esc(w.xpub_masked) +
              ' · Next index: #' + esc(w.derived_index) + '</div></div>' +
              '<div class="bp-flex-gap">' +
              '<button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-wallet-toggle="' + w.id + '">' + (w.status === 'active' ? 'Pause' : 'Enable') + '</button>' +
              '<button type="button" class="bp-btn bp-btn-danger bp-btn-sm" data-wallet-del="' + w.id + '">Remove</button>' +
              '</div></div>';
          }).join('');
        }
      }

      // Payment Links list
      const linksWrap = document.getElementById('bp-links-list');
      if (linksWrap) {
        if (!state.payment_links || state.payment_links.length === 0) {
          linksWrap.innerHTML = '<div class="bp-muted" style="padding:12px 0;">No payment links yet. Create one above to share on websites, invoices, or social media.</div>';
        } else {
          linksWrap.innerHTML = state.payment_links.map(function (l) {
            const priceLabel = l.allow_custom_amount ? 'Buyer enters amount' : ('$' + esc(l.fiat_amount) + ' ' + esc(l.fiat_currency));
            return '<div class="bp-card-compact bp-row-between" style="margin-bottom:10px;">' +
              '<div><strong>' + esc(l.title) + '</strong> <span class="bp-subtle">· ' + priceLabel + '</span>' +
              '<div class="bp-mono bp-subtle" style="font-size:12px;">' + esc(l.url) + '</div></div>' +
              '<div class="bp-flex-gap">' +
              '<button type="button" class="bp-btn bp-btn-secondary bp-btn-sm" data-copy="' + esc(l.url) + '">Copy Link</button>' +
              '<a class="bp-btn bp-btn-ghost bp-btn-sm" href="' + esc(l.path) + '" target="_blank">Open</a>' +
              '<button type="button" class="bp-btn bp-btn-danger bp-btn-sm" data-link-del="' + l.id + '">Delete</button>' +
              '</div></div>';
          }).join('');
        }
      }

      // Prefund Ledger table
      const txTbody = document.getElementById('bp-transactions-tbody');
      if (txTbody) {
        txTbody.innerHTML = (!state.transactions || state.transactions.length === 0)
          ? '<tr><td colspan="5" class="bp-muted">No prefund ledger entries yet.</td></tr>'
          : state.transactions.map(function (t) {
            const sign = t.type === 'fee_debit' ? '-' : '+';
            const color = t.type === 'fee_debit' ? '#fca5a5' : '#34d399';
            return '<tr>' +
              '<td>' + esc(t.description || t.type) + '</td>' +
              '<td><span class="bp-badge bp-badge-neutral">' + esc(t.gateway || t.type) + '</span></td>' +
              '<td class="bp-mono" style="color:' + color + ';font-weight:700;">' + sign + '$' + esc(t.amount) + '</td>' +
              '<td>' + statusBadge(t.status) + '</td>' +
              '<td class="bp-subtle" style="font-size:12px;">' + esc(t.created_at) + '</td></tr>';
          }).join('');
      }

      if (state.admin) {
        renderAdmin();
      }
    }

    function renderAdmin() {
      const adm = state.admin;
      document.querySelectorAll('[data-bind="adm_merchants"]').forEach(function (e) { e.textContent = String(adm.stats.total_merchants); });
      document.querySelectorAll('[data-bind="adm_volume"]').forEach(function (e) { e.textContent = '$' + adm.stats.platform_volume_usd; });
      document.querySelectorAll('[data-bind="adm_fees"]').forEach(function (e) { e.textContent = '$' + adm.stats.total_fees_usd; });
      document.querySelectorAll('[data-bind="adm_blocked_countries"]').forEach(function (e) { e.textContent = String(adm.stats.blocked_countries); });

      const mTbody = document.getElementById('bp-admin-merchants-tbody');
      if (mTbody) {
        mTbody.innerHTML = (adm.merchants || []).map(function (m) {
          const nextSt = m.status === 'active' ? 'suspended' : 'active';
          const btnLabel = m.status === 'active' ? 'Suspend' : 'Unlock / Activate';
          return '<tr>' +
            '<td><strong>' + esc(m.name) + '</strong><div class="bp-subtle" style="font-size:12px;">' + esc(m.email) + ' (' + esc(m.role) + ')</div></td>' +
            '<td class="bp-mono">$' + esc(m.prefund_balance) + '</td>' +
            '<td>' + esc(m.paid_invoices) + '</td>' +
            '<td>' + statusBadge(m.status) + '</td>' +
            '<td style="text-align:right;" class="bp-flex-gap">' +
            '<button type="button" class="bp-btn bp-btn-secondary bp-btn-sm" data-adm-credit="' + m.id + '" data-adm-name="' + esc(m.name) + '">+ Credit</button>' +
            (m.role !== 'admin' ? '<button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-adm-status="' + m.status + '" data-adm-next="' + nextSt + '" data-adm-uid="' + m.id + '">' + btnLabel + '</button>' : '') +
            '</td></tr>';
        }).join('');
      }

      const secTbody = document.getElementById('bp-admin-seclogs-tbody');
      if (secTbody) {
        secTbody.innerHTML = (adm.security_logs || []).length === 0
          ? '<tr><td colspan="5" class="bp-muted">No security events logged yet.</td></tr>'
          : adm.security_logs.map(function (log) {
            const king = log.is_whitelisted ? (' ' + kingCrownSvg()) : '';
            const evClass = (log.event_type === 'whitelisted_success') ? 'bp-badge-success'
              : (log.event_type === 'admin_alert' ? 'bp-badge-warn' : 'bp-badge-danger');
            return '<tr>' +
              '<td class="bp-mono">' + esc(log.ip_address) + (log.country_code ? ' <span class="bp-subtle">(' + esc(log.country_code) + ')</span>' : '') + king + '</td>' +
              '<td>' + esc(log.username_attempted) + '</td>' +
              '<td><span class="bp-badge ' + evClass + '">' + esc(log.event_type) + '</span></td>' +
              '<td class="bp-muted" style="font-size:12.5px;">' + esc(log.details) + '</td>' +
              '<td class="bp-subtle" style="font-size:12px;">' + esc(log.created_at) + '</td></tr>';
          }).join('');
      }

      const ipTbody = document.getElementById('bp-admin-iprules-tbody');
      if (ipTbody) {
        ipTbody.innerHTML = (adm.ip_rules || []).length === 0
          ? '<tr><td colspan="4" class="bp-muted">No active IP blocks or manual whitelists.</td></tr>'
          : adm.ip_rules.map(function (r) {
            const pill = r.rule_type === 'whitelist' ? kingCrownSvg() : '<span class="bp-badge bp-badge-danger">Blocked (' + esc(r.duration_label) + ')</span>';
            return '<tr>' +
              '<td class="bp-mono">' + esc(r.ip_address) + '</td>' +
              '<td>' + pill + '</td>' +
              '<td class="bp-muted" style="font-size:12.5px;">' + esc(r.reason) + '</td>' +
              '<td style="text-align:right;"><button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-ip-remove="' + esc(r.ip_address) + '">Clear</button></td></tr>';
          }).join('');
      }

      if (countriesCache) {
        renderCountries();
      }
    }

    function renderCountries() {
      const tbody = document.getElementById('bp-countries-tbody');
      if (!tbody || !countriesCache) return;
      const q = countryFilterQuery.toLowerCase().trim();
      const filtered = countriesCache.filter(function (c) {
        if (countryFilterAction !== 'ALL' && c.action !== countryFilterAction) return false;
        if (!q) return true;
        return c.country_name.toLowerCase().indexOf(q) !== -1 || c.country_code.toLowerCase().indexOf(q) !== -1;
      });

      const countEl = document.getElementById('bp-country-match-count');
      if (countEl) countEl.textContent = filtered.length + ' countries';

      tbody.innerHTML = filtered.map(function (c) {
        const mkBtn = function (act, activeCls) {
          const isAct = c.action === act;
          const style = isAct ? activeCls : 'bp-btn-ghost';
          return '<button type="button" class="bp-btn ' + style + ' bp-btn-sm" data-country-code="' + esc(c.country_code) + '" data-country-act="' + esc(act) + '">' + esc(act) + '</button>';
        };
        return '<tr>' +
          '<td class="bp-mono" style="width:70px;font-weight:700;">' + esc(c.country_code) + '</td>' +
          '<td>' + esc(c.country_name) + '</td>' +
          '<td style="text-align:right;"><div class="bp-flex-gap" style="justify-content:flex-end;">' +
          mkBtn('Whitelisted', 'bp-btn-primary') +
          mkBtn('Not Specified', 'bp-btn-secondary') +
          mkBtn('Blacklisted', 'bp-btn-danger') +
          '</div></td></tr>';
      }).join('');
    }

    document.querySelectorAll('[data-spa-tab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setTab(btn.getAttribute('data-spa-tab') || 'overview');
      });
    });

    document.addEventListener('click', async function (e) {
      const wToggle = e.target.closest('[data-wallet-toggle]');
      if (wToggle) {
        await runAction('wallet.toggle', { wallet_id: Number(wToggle.getAttribute('data-wallet-toggle')) });
        return;
      }

      const wDel = e.target.closest('[data-wallet-del]');
      if (wDel) {
        await runAction('wallet.delete', { wallet_id: Number(wDel.getAttribute('data-wallet-del')) });
        return;
      }

      const lDel = e.target.closest('[data-link-del]');
      if (lDel) {
        await runAction('link.delete', { link_id: Number(lDel.getAttribute('data-link-del')) });
        return;
      }

      const cAct = e.target.closest('[data-country-code]');
      if (cAct) {
        const code = cAct.getAttribute('data-country-code');
        const act = cAct.getAttribute('data-country-act');
        if (countriesCache) {
          const item = countriesCache.find(function (x) { return x.country_code === code; });
          if (item) item.action = act;
          renderCountries();
        }
        await runAction('admin.country_rule', { country_code: code, action: act });
        return;
      }

      const ipRem = e.target.closest('[data-ip-remove]');
      if (ipRem) {
        await runAction('admin.ip_rule', { ip_address: ipRem.getAttribute('data-ip-remove'), operation: 'remove' });
        return;
      }

      const mStatus = e.target.closest('[data-adm-uid]');
      if (mStatus) {
        await runAction('admin.merchant_status', {
          user_id: Number(mStatus.getAttribute('data-adm-uid')),
          status: mStatus.getAttribute('data-adm-next')
        });
        return;
      }

      const mCredit = e.target.closest('[data-adm-credit]');
      if (mCredit) {
        const uid = Number(mCredit.getAttribute('data-adm-credit'));
        const name = mCredit.getAttribute('data-adm-name') || 'Merchant';
        const modal = document.getElementById('bp-modal-admin-credit');
        const uidInp = document.getElementById('bp-adm-credit-uid');
        const nameSpan = document.getElementById('bp-adm-credit-name');
        if (modal && uidInp && nameSpan) {
          uidInp.value = String(uid);
          nameSpan.textContent = name;
          modal.classList.remove('bp-hidden');
        }
        return;
      }

      const openModal = e.target.closest('[data-open-modal]');
      if (openModal) {
        const id = openModal.getAttribute('data-open-modal');
        const m = document.getElementById(id);
        if (m) m.classList.remove('bp-hidden');
        return;
      }

      const closeModal = e.target.closest('[data-close-modal]');
      if (closeModal || e.target.classList.contains('bp-backdrop')) {
        document.querySelectorAll('.bp-backdrop').forEach(function (b) { b.classList.add('bp-hidden'); });
      }
    });

    const countrySearch = document.getElementById('bp-country-search');
    if (countrySearch) {
      countrySearch.addEventListener('input', function () {
        countryFilterQuery = countrySearch.value || '';
        renderCountries();
      });
    }
    const countryStatusFilter = document.getElementById('bp-country-filter-action');
    if (countryStatusFilter) {
      countryStatusFilter.addEventListener('change', function () {
        countryFilterAction = countryStatusFilter.value || 'ALL';
        renderCountries();
      });
    }

    function bindSpaForm(formId, actionName, afterSuccess) {
      const form = document.getElementById(formId);
      if (!form) return;
      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
        const fd = new FormData(form);
        const payload = {};
        fd.forEach(function (v, k) { payload[k] = v; });
        form.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
          payload[cb.name] = cb.checked ? '1' : '';
        });
        const res = await runAction(actionName, payload, afterSuccess);
        if (btn) btn.disabled = false;
        return res;
      });
    }

    bindSpaForm('bp-form-new-invoice', 'invoice.create', function (res) {
      document.querySelectorAll('.bp-backdrop').forEach(function (b) { b.classList.add('bp-hidden'); });
      if (res.checkout_path) {
        window.location.href = res.checkout_path;
      }
    });

    bindSpaForm('bp-form-add-wallet', 'wallet.add', function () {
      const f = document.getElementById('bp-form-add-wallet');
      if (f) f.reset();
      const prev = document.getElementById('bp-wallet-preview-box');
      if (prev) prev.classList.add('bp-hidden');
      document.querySelectorAll('.bp-backdrop').forEach(function (b) { b.classList.add('bp-hidden'); });
      setTab('wallets');
    });

    bindSpaForm('bp-form-new-link', 'link.create', function () {
      const f = document.getElementById('bp-form-new-link');
      if (f) f.reset();
    });

    bindSpaForm('bp-form-payhub-topup', 'prefund.payhub_init', function (res) {
      if (res.authorization_url) {
        window.location.href = res.authorization_url;
      }
    });

    bindSpaForm('bp-form-crypto-topup', 'prefund.crypto_create', function (res) {
      if (res.checkout_path) {
        window.location.href = res.checkout_path;
      }
    });

    bindSpaForm('bp-form-profile', 'profile.update');
    bindSpaForm('bp-form-bf-settings', 'admin.bf_settings');
    bindSpaForm('bp-form-ip-rule', 'admin.ip_rule', function () {
      const f = document.getElementById('bp-form-ip-rule');
      if (f) f.reset();
    });
    bindSpaForm('bp-form-platform-settings', 'admin.platform_save');
    bindSpaForm('bp-form-admin-credit', 'admin.credit_merchant', function () {
      document.querySelectorAll('.bp-backdrop').forEach(function (b) { b.classList.add('bp-hidden'); });
    });

    const regenBtn = document.getElementById('bp-btn-regen-apikey');
    if (regenBtn) {
      regenBtn.addEventListener('click', async function () {
        await runAction('profile.update', { regenerate_api_key: 1 });
      });
    }

    const previewDerivBtn = document.getElementById('bp-btn-preview-derivation');
    if (previewDerivBtn) {
      previewDerivBtn.addEventListener('click', async function () {
        const inp = document.getElementById('bp-wallet-xpub-input');
        const coinSel = document.getElementById('bp-wallet-coin-select');
        const box = document.getElementById('bp-wallet-preview-box');
        if (!inp || !box) return;
        box.classList.remove('bp-hidden');
        box.innerHTML = '<div class="bp-muted">Deriving child addresses…</div>';
        const res = await apiPost('api/spa', {
          action: 'wallet.preview',
          xpub_key: inp.value,
          coin_symbol: coinSel ? coinSel.value : 'BTC'
        });
        if (!res.ok) {
          box.innerHTML = '<div style="color:#fca5a5;">' + esc(res.error) + '</div>';
          return;
        }
        box.innerHTML = (res.addresses || []).map(function (a) {
          return '<div class="bp-mono" style="font-size:12px;padding:3px 0;"><strong>' + esc(a.path) + ':</strong> ' + esc(a.address) + '</div>';
        }).join('');
      });
    }

    /* ─────────────── Command Palette (Ctrl+K / Cmd+K) ─────────────── */
    const cmdBackdrop = document.getElementById('bp-cmd-palette');
    const cmdInput = document.getElementById('bp-cmd-input');
    const cmdList = document.getElementById('bp-cmd-list');

    function getCommands() {
      const baseCmds = [
        { label: 'Create New Invoice', hint: 'Action', run: function () { document.getElementById('bp-modal-new-invoice').classList.remove('bp-hidden'); } },
        { label: 'Connect Wallet (xPub / Address)', hint: 'Action', run: function () { document.getElementById('bp-modal-add-wallet').classList.remove('bp-hidden'); } },
        { label: 'Open API Documentation (7 Languages)', hint: 'Docs', run: function () { window.location.href = url('api-docs'); } },
        { label: 'Go to Overview', hint: 'Tab', run: function () { setTab('overview'); } },
        { label: 'Go to Invoices', hint: 'Tab', run: function () { setTab('invoices'); } },
        { label: 'Go to Wallets (xPub)', hint: 'Tab', run: function () { setTab('wallets'); } },
        { label: 'Go to Payment Links', hint: 'Tab', run: function () { setTab('links'); } },
        { label: 'Top Up Prefund Credit (Payhub / Crypto)', hint: 'Tab', run: function () { setTab('prefund'); } },
        { label: 'API Keys & Webhooks', hint: 'Tab', run: function () { setTab('api'); } },
        { label: 'Copy Live Merchant API Key', hint: 'Copy', run: function () { copyText(state.user.api_key || '', 'API Key'); } },
        { label: 'Account & Alert Settings', hint: 'Tab', run: function () { setTab('settings'); } }
      ];
      if (state.admin) {
        baseCmds.push(
          { label: 'Admin: Merchants & Balances', hint: 'Admin', run: function () { setTab('admin_merchants'); } },
          { label: 'Admin: Security Logs & Brute-Force (cPHulk)', hint: 'Admin', run: function () { setTab('admin_security'); } },
          { label: 'Admin: Country Firewall (Live Search)', hint: 'Admin', run: function () { setTab('admin_countries'); } },
          { label: 'Admin: Gateway, Payhub & Live Node Settings', hint: 'Admin', run: function () { setTab('admin_platform'); } }
        );
      }
      (state.invoices || []).slice(0, 15).forEach(function (inv) {
        baseCmds.push({
          label: 'Invoice ' + inv.invoice_id + ' ($' + inv.fiat_amount + ' · ' + inv.currency + ')',
          hint: inv.status,
          run: function () { window.location.href = inv.checkout_path; }
        });
      });
      return baseCmds;
    }

    function openCmdPalette() {
      if (!cmdBackdrop || !cmdInput) return;
      cmdBackdrop.classList.remove('bp-hidden');
      cmdInput.value = '';
      renderCmdItems('');
      setTimeout(function () { cmdInput.focus(); }, 20);
    }

    function renderCmdItems(q) {
      if (!cmdList) return;
      const query = q.toLowerCase().trim();
      const items = getCommands().filter(function (c) {
        return !query || c.label.toLowerCase().indexOf(query) !== -1 || c.hint.toLowerCase().indexOf(query) !== -1;
      }).slice(0, 10);

      cmdList.innerHTML = items.length === 0
        ? '<div class="bp-muted" style="padding:16px;">No matching commands or invoices.</div>'
        : items.map(function (c, idx) {
          return '<button type="button" class="bp-cmd-item' + (idx === 0 ? ' selected' : '') + '" data-cmd-idx="' + idx + '">' +
            '<span>' + esc(c.label) + '</span><span class="bp-kbd">' + esc(c.hint) + '</span></button>';
        }).join('');

      cmdList.querySelectorAll('[data-cmd-idx]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const item = items[Number(btn.getAttribute('data-cmd-idx'))];
          cmdBackdrop.classList.add('bp-hidden');
          if (item) item.run();
        });
      });

      cmdInput.onkeydown = function (ev) {
        if (ev.key === 'Enter' && items[0]) {
          ev.preventDefault();
          cmdBackdrop.classList.add('bp-hidden');
          items[0].run();
        } else if (ev.key === 'Escape') {
          cmdBackdrop.classList.add('bp-hidden');
        }
      };
    }

    if (cmdInput) {
      cmdInput.addEventListener('input', function () {
        renderCmdItems(cmdInput.value || '');
      });
    }

    document.addEventListener('keydown', function (ev) {
      if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') {
        ev.preventDefault();
        openCmdPalette();
      } else if (ev.key === 'Escape') {
        document.querySelectorAll('.bp-backdrop').forEach(function (b) { b.classList.add('bp-hidden'); });
        closeDrawer();
      }
    });

    document.querySelectorAll('[data-open-cmd]').forEach(function (btn) {
      btn.addEventListener('click', openCmdPalette);
    });

    renderAll();
    setTab(activeTab);

    setInterval(async function () {
      const hasPending = (state.invoices || []).some(function (i) {
        return i.status === 'pending' || i.status === 'detected';
      });
      if (hasPending && !document.hidden) {
        await runAction('refresh', {});
      }
    }, 10000);
  }

  /* ─────────────────────────── 5. Register Lightweight PWA Service Worker ─────────────────────────── */
  if ('serviceWorker' in navigator && window.location.protocol === 'https:') {
    navigator.serviceWorker.register(url('sw.js')).catch(function () {});
  }
})();
