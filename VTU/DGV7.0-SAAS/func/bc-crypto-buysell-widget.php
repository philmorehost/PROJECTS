<?php
/**
 * Tenant-safe Crypto Buy/Sell widget partial (Admin <-> User A2P/P2A).
 * Renders the reference 2-mode Buy/Sell card (2:00 held quote, network-bound address check,
 * 60:00 sell deposit instructions) and posts strictly to web/api/crypto-buysell.php with CSRF.
 */
require_once __DIR__ . '/bc-crypto-buysell-bridge.php';
$cbsHoldActive = !bc_cbs_execution_enabled();
$cbsCsrfToken  = function_exists('bc_generate_csrf_token') ? bc_generate_csrf_token() : ($_SESSION['_csrf_token'] ?? '');
$cbsWalletNgn  = isset($get_logged_user_details['balance']) ? (float)$get_logged_user_details['balance'] : 0.0;
$cbsAssets     = bc_cbs_asset_catalogue();
?>
<div class="card border-0 rounded-4 shadow-sm mb-4" id="bc-cbs-card">
    <div class="card-body p-3 p-md-4">
        <?php if ($cbsHoldActive): ?>
        <div class="alert alert-warning border-0 rounded-3 d-flex align-items-start gap-2 small mb-3" role="status">
            <i class="bi bi-shield-lock-fill mt-1"></i>
            <div>
                <strong>Buy / Sell Crypto is Held (CRYPTO_BUYSELL_HOLD):</strong>
                Live quotes, debits, and custody transfers remain paused pending isolated custody verification and reconciliation. Submitting a quote returns HTTP 503 safely with zero wallet impact.
            </div>
        </div>
        <?php endif; ?>

        <div id="bc-cbs-widget" class="bccbs" data-csrf="<?php echo htmlspecialchars((string)$cbsCsrfToken, ENT_QUOTES, 'UTF-8'); ?>" data-held="<?php echo $cbsHoldActive ? '1' : '0'; ?>">
            <div class="bccbs-head">
                <div class="bccbs-tabs" role="tablist" aria-label="Trade side">
                    <button type="button" class="bccbs-tab is-on" data-side="buy" role="tab" aria-selected="true">Buy crypto</button>
                    <button type="button" class="bccbs-tab" data-side="sell" role="tab" aria-selected="false">Sell crypto</button>
                </div>
                <div class="bccbs-bal">Wallet: <strong id="bccbs-bal">₦<?php echo number_format($cbsWalletNgn, 2); ?></strong></div>
            </div>

            <!-- Step 1: Amount & Coin/Network -->
            <section class="bccbs-step" data-step="amount">
                <label class="bccbs-label" for="bccbs-asset">Coin &amp; network</label>
                <select id="bccbs-asset" class="bccbs-input">
                    <?php foreach ($cbsAssets as $a): ?>
                    <option value="<?php echo htmlspecialchars($a['asset'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-symbol="<?php echo htmlspecialchars($a['symbol'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-network="<?php echo htmlspecialchars($a['network'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-network-label="<?php echo htmlspecialchars($a['network_label'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-addr-type="<?php echo htmlspecialchars($a['address_type'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($a['symbol'] . ' — ' . $a['network_label'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="bccbs-rate" id="bccbs-rate">Live quotes provided on execution enablement · Admin ↔ User</div>

                <label class="bccbs-label" for="bccbs-amount" id="bccbs-amount-label">How much do you want to spend?</label>
                <div class="bccbs-amount">
                    <input id="bccbs-amount" class="bccbs-input" inputmode="decimal" autocomplete="off" placeholder="0.00">
                    <button id="bccbs-unit" class="bccbs-unit" type="button">NGN</button>
                </div>
                <div class="bccbs-hint" id="bccbs-estimate">Price locks for 2:00 once confirmed</div>

                <button type="button" id="bccbs-quote-btn" class="bccbs-btn">Get price</button>
            </section>

            <!-- Step 2: 2:00 Price Hold & Network-Bound Address -->
            <section class="bccbs-step" data-step="confirm" hidden>
                <div class="bccbs-summary">
                    <div><span id="bccbs-s-pay-l">You pay</span><strong id="bccbs-s-pay">—</strong></div>
                    <div><span>You get</span><strong id="bccbs-s-get">—</strong></div>
                    <div><span>Rate</span><strong id="bccbs-s-rate">—</strong></div>
                    <div><span>Network</span><strong id="bccbs-s-net">—</strong></div>
                </div>
                <div class="bccbs-timer">Price held for <strong id="bccbs-timer">2:00</strong></div>

                <div id="bccbs-addr-wrap">
                    <label class="bccbs-label" for="bccbs-addr">Your wallet address (receives the crypto)</label>
                    <input id="bccbs-addr" class="bccbs-input bccbs-mono" placeholder="Wallet address" autocomplete="off" spellcheck="false">
                    <div class="bccbs-warn" id="bccbs-addr-warn"></div>
                </div>

                <div class="bccbs-row">
                    <button type="button" class="bccbs-btn bccbs-ghost" data-bccbs-back>Back</button>
                    <button type="button" id="bccbs-confirm-btn" class="bccbs-btn">Pay</button>
                </div>
            </section>

            <!-- Step 3: Sell Deposit View -->
            <section class="bccbs-step" data-step="deposit" hidden>
                <div class="bccbs-warn-strong" id="bccbs-d-warn"></div>
                <label class="bccbs-label">Send exactly</label>
                <div class="bccbs-copy"><strong id="bccbs-d-amount" class="bccbs-big">—</strong><button type="button" data-bccbs-copy="bccbs-d-amount">Copy</button></div>
                <label class="bccbs-label">To this address</label>
                <div class="bccbs-copy"><code id="bccbs-d-addr" class="bccbs-mono">—</code><button type="button" data-bccbs-copy="bccbs-d-addr">Copy</button></div>
                <div class="bccbs-summary">
                    <div><span>You'll receive</span><strong id="bccbs-d-fiat">—</strong></div>
                    <div><span>Send within</span><strong id="bccbs-d-timer">60:00</strong></div>
                </div>
                <div class="bccbs-status"><span class="bccbs-dot"></span><span id="bccbs-d-status">Waiting for your deposit…</span></div>
            </section>

            <!-- Step 4: Order Placed / Result -->
            <section class="bccbs-step" data-step="result" hidden>
                <div class="bccbs-result-icon" id="bccbs-r-icon">…</div>
                <h5 class="fw-bold mt-2 mb-1" id="bccbs-r-title">Order placed</h5>
                <p class="small text-muted mb-2" id="bccbs-r-text"></p>
                <a id="bccbs-r-link" class="bccbs-link" target="_blank" rel="noopener noreferrer" hidden>View on explorer ↗</a>
                <div class="bccbs-status" id="bccbs-r-status-wrap"><span class="bccbs-dot"></span><span id="bccbs-r-status">Sent — waiting for network confirmations…</span></div>
                <button type="button" class="bccbs-btn" data-bccbs-restart>New trade</button>
            </section>

            <div class="bccbs-error" id="bccbs-error" role="alert" hidden></div>
        </div>
    </div>
</div>

<style>
.bccbs{--cbs-red:#E10600;--cbs-red-d:#B30500;--cbs-ink:#0B0B0B;--cbs-mute:#6B6B6B;--cbs-line:#E7E7E7;--cbs-soft:#FFF3F2;max-width:460px;margin:0 auto;border:1px solid var(--cbs-line);border-radius:20px;padding:18px;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.05)}
.bccbs-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.bccbs-tabs{display:flex;background:#F4F4F4;border-radius:999px;padding:4px}
.bccbs-tab{border:0;background:transparent;padding:8px 15px;border-radius:999px;font-weight:600;font-size:.875rem;cursor:pointer;color:var(--cbs-mute)}
.bccbs-tab.is-on{background:var(--cbs-ink);color:#fff}
.bccbs-bal{font-size:.82rem;color:var(--cbs-mute)}
.bccbs-bal strong{color:var(--cbs-ink)}
.bccbs-label{display:block;font-size:.82rem;font-weight:600;margin:12px 0 6px;color:var(--cbs-ink)}
.bccbs-input{width:100%;border:1.5px solid var(--cbs-line);border-radius:12px;padding:11px 13px;font-size:.95rem;background:#fff;color:var(--cbs-ink)}
.bccbs-input:focus{outline:none;border-color:var(--cbs-ink)}
.bccbs-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.84rem;word-break:break-all}
.bccbs-amount{display:flex;gap:8px}
.bccbs-unit{border:1.5px solid var(--cbs-ink);background:#fff;border-radius:12px;padding:0 14px;font-weight:700;cursor:pointer;min-width:78px}
.bccbs-rate,.bccbs-hint{font-size:.8rem;color:var(--cbs-mute);margin-top:7px}
.bccbs-btn{width:100%;margin-top:16px;border:0;border-radius:14px;padding:13px;background:var(--cbs-red);color:#fff;font-size:.98rem;font-weight:700;cursor:pointer}
.bccbs-btn:hover{background:var(--cbs-red-d)}
.bccbs-btn:disabled{opacity:.55;cursor:not-allowed}
.bccbs-ghost{background:#fff;color:var(--cbs-ink);border:1.5px solid var(--cbs-line)}
.bccbs-ghost:hover{background:#F7F7F7}
.bccbs-row{display:flex;gap:10px}
.bccbs-summary{border:1px solid var(--cbs-line);border-radius:14px;padding:6px 14px;margin-top:10px}
.bccbs-summary div{display:flex;justify-content:space-between;gap:10px;padding:8px 0;font-size:.86rem;border-bottom:1px dashed var(--cbs-line)}
.bccbs-summary div:last-child{border-bottom:0}
.bccbs-summary span{color:var(--cbs-mute)}
.bccbs-timer{margin-top:10px;font-size:.82rem;color:var(--cbs-mute);text-align:center}
.bccbs-timer strong{color:var(--cbs-red)}
.bccbs-warn{font-size:.8rem;color:var(--cbs-red-d);margin-top:8px}
.bccbs-warn-strong{background:var(--cbs-soft);border:1px solid #F8C9C6;border-radius:12px;padding:11px 13px;font-size:.83rem;color:var(--cbs-red-d);font-weight:600}
.bccbs-copy{display:flex;align-items:center;gap:10px;border:1.5px solid var(--cbs-line);border-radius:12px;padding:9px 12px}
.bccbs-copy>:first-child{flex:1;min-width:0}
.bccbs-copy button{border:0;background:var(--cbs-ink);color:#fff;border-radius:8px;padding:6px 11px;font-size:.8rem;font-weight:600;cursor:pointer}
.bccbs-big{font-size:1.15rem}
.bccbs-status{display:flex;align-items:center;gap:9px;margin-top:14px;font-size:.85rem;font-weight:600;justify-content:center}
.bccbs-dot{width:10px;height:10px;border-radius:50%;background:var(--cbs-red);animation:bccbs-pulse 1.2s infinite}
.bccbs-step[data-step="result"]{text-align:center}
.bccbs-result-icon{width:52px;height:52px;margin:4px auto;border-radius:50%;background:var(--cbs-ink);color:#fff;display:grid;place-items:center;font-size:1.4rem}
.bccbs-link{display:inline-block;margin-top:8px;color:var(--cbs-red);font-weight:600;text-decoration:none}
.bccbs-error{margin-top:12px;background:var(--cbs-ink);color:#fff;border-radius:12px;padding:11px 13px;font-size:.84rem}
@keyframes bccbs-pulse{50%{opacity:.25}}
@media(max-width:480px){.bccbs{padding:14px;border-radius:16px}}
</style>

<script>
(() => {
    const root = document.getElementById('bc-cbs-widget');
    if (!root) return;
    const $ = (id) => document.getElementById(id);
    const csrf = root.dataset.csrf || '';
    const state = { side: 'buy', unit: 'fiat', quoteId: 0 };
    const ADDR = {
        evm:  { re: /^0x[0-9a-fA-F]{40}$/, hint: 'starts with 0x, 42 characters' },
        tron: { re: /^T[1-9A-HJ-NP-Za-km-z]{33}$/, hint: 'starts with T, 34 characters' },
        btc:  { re: /^(bc1[02-9ac-hj-np-z]{25,87}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$/, hint: 'starts with bc1, 1 or 3' },
        sol:  { re: /^[1-9A-HJ-NP-Za-km-z]{32,44}$/, hint: '32-44 base58 characters' }
    };

    const opt = () => $('bccbs-asset').selectedOptions[0];
    const showErr = (m) => { const el = $('bccbs-error'); el.textContent = m || ''; el.hidden = !m; };
    const setStep = (name) => {
        root.querySelectorAll('.bccbs-step').forEach((s) => { s.hidden = s.dataset.step !== name; });
        showErr('');
    };

    function syncUnitLabel() {
        const o = opt();
        const sym = o ? o.dataset.symbol : 'USDT';
        $('bccbs-unit').textContent = state.unit === 'fiat' ? 'NGN' : sym;
        $('bccbs-amount-label').textContent = state.side === 'buy'
            ? (state.unit === 'fiat' ? 'How much do you want to spend?' : `How much ${sym} do you want?`)
            : (state.unit === 'fiat' ? 'How much NGN do you want to receive?' : `How much ${sym} are you selling?`);
    }

    root.querySelectorAll('.bccbs-tab').forEach((tab) => tab.addEventListener('click', () => {
        root.querySelectorAll('.bccbs-tab').forEach((t) => {
            const on = t === tab;
            t.classList.toggle('is-on', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        state.side = tab.dataset.side;
        setStep('amount');
        syncUnitLabel();
    }));

    $('bccbs-asset').addEventListener('change', syncUnitLabel);
    $('bccbs-unit').addEventListener('click', () => {
        state.unit = state.unit === 'fiat' ? 'crypto' : 'fiat';
        syncUnitLabel();
    });

    root.querySelector('[data-bccbs-back]').addEventListener('click', () => setStep('amount'));
    root.querySelector('[data-bccbs-restart]').addEventListener('click', () => setStep('amount'));

    root.querySelectorAll('[data-bccbs-copy]').forEach((btn) => btn.addEventListener('click', async () => {
        const target = $(btn.dataset.bccbsCopy);
        if (!target) return;
        try {
            await navigator.clipboard.writeText(target.textContent.trim());
            btn.textContent = 'Copied';
            setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
        } catch (e) {
            showErr('Unable to copy automatically.');
        }
    }));

    $('bccbs-quote-btn').addEventListener('click', async () => {
        const raw = $('bccbs-amount').value.replace(/,/g, '').trim();
        if (!(Number(raw) > 0)) { showErr('Enter a valid amount.'); return; }
        const o = opt();
        const btn = $('bccbs-quote-btn');
        btn.disabled = true;
        showErr('');
        try {
            const res = await fetch('api/crypto-buysell.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({
                    action: 'quote',
                    request_id: 'cbs_web_' + Date.now(),
                    side: state.side,
                    asset: o.dataset.symbol,
                    network: o.dataset.network,
                    fiat_currency: 'NGN',
                    crypto_units_1e8: String(Math.round(Number(raw) * 100000000)),
                    rate_fiat_minor: 0
                })
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') {
                showErr(data.message || data.code || 'Unable to lock price right now.');
                return;
            }
            const q = data.quote || {};
            state.quoteId = q.quote_id || 0;
            $('bccbs-s-net').textContent = o.dataset.networkLabel;
            $('bccbs-addr-wrap').hidden = state.side !== 'buy';
            $('bccbs-addr-warn').textContent = `Only use a ${o.dataset.symbol} address on ${o.dataset.networkLabel}. Crypto sent to the wrong network can't be recovered.`;
            setStep('confirm');
        } catch (e) {
            showErr('Network error requesting quote.');
        } finally {
            btn.disabled = false;
        }
    });

    $('bccbs-confirm-btn').addEventListener('click', async () => {
        const o = opt();
        if (state.side === 'buy') {
            const addr = $('bccbs-addr').value.trim();
            const rule = ADDR[o.dataset.addrType] || ADDR.evm;
            if (!rule.re.test(addr)) {
                showErr(`Enter a valid ${o.dataset.networkLabel} address (${rule.hint})`);
                return;
            }
        }
        try {
            const res = await fetch('api/crypto-buysell.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({
                    action: 'execute_buy',
                    quote_id: state.quoteId,
                    request_id: 'cbs_ord_' + Date.now()
                })
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') {
                showErr(data.message || data.code || 'Order paused.');
                return;
            }
            setStep('result');
        } catch (e) {
            showErr('Network error submitting trade.');
        }
    });

    syncUnitLabel();
})();
</script>
