<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include("../func/bc-config.php");
include_once(__DIR__ . "/../func/bc-fintech-services.php");
include_once(__DIR__ . "/../func/bc-fintech-klasha.php");
include_once(__DIR__ . "/../func/bc-fintech-flutterwave.php");

if (!isset($_SESSION["user_session"]) || !isset($get_logged_user_details)) {
    header("Location: Login.php");
    exit();
}
$vid = (int)$get_logged_user_details['vendor_id'];
$svc_user = $get_logged_user_details;
require_once __DIR__ . '/../func/bc-service-request-idempotency.php';
require_once __DIR__ . '/../func/bc-service-intake-bridge.php';
$svc_form_key = bc_svc_form_key($_POST['request_key'] ?? null);

if (!isServiceEnabled('intl_transfer')) {
    header("Location: Dashboard.php");
    exit();
}
require_once __DIR__ . '/../func/bc-service-provider-routing.php';
$provider = bc_service_route_provider($vid,'intl_transfer');
$provider_label = bc_svc_provider_label($provider);
$services = bc_service_route_services($vid,'intl_transfer',$provider);
$notice = '';
$notice_type = 'info';

if (isset($_POST['send_transfer'])) {
    bc_validate_csrf();
    if (!is_string($_POST['route_snapshot'] ?? null) || !hash_equals($provider, $_POST['route_snapshot'])) {
        http_response_code(409);
        exit('Service routing changed. Reload this page before submitting a new request.');
    }
    $amount_usd = (float)($_POST['amount_usd'] ?? 0);
    $payload = [
        'recipient_name' => trim(strip_tags($_POST['recipient_name'] ?? '')),
        'recipient_country' => trim(strip_tags($_POST['recipient_country'] ?? '')),
        'recipient_account' => trim(strip_tags($_POST['recipient_account'] ?? '')),
        'currency' => strtoupper(trim(strip_tags($_POST['currency'] ?? 'USD'))),
        'amount_usd' => $amount_usd,
        'service' => trim(strip_tags($_POST['service'] ?? 'global_transfer')),
    ];
    if ($provider === '' || !isset($services[$payload['service']]) || $amount_usd <= 0 || $payload['recipient_name'] === '' || $payload['recipient_account'] === '') {
        $notice = 'Please complete the recipient name, account and a valid amount.';
        $notice_type = 'danger';
    } else {
        $svc_result = bc_svc_request_once($vid, $svc_user, 'intl_transfer', $provider, $payload, $_POST['request_key'] ?? null);
        $ref = $svc_result['reference'];
        if ($ref !== '' && empty($svc_result['replayed'])) {
            bc_svc_enqueue_held_attempt($vid, $ref);
        }
        // §26: indicative LIVE quote from the active provider (read-only — a
        // quote moves no money; settlement still follows the recorded request).
        $quote_note = '';
        if ($ref !== '' && !$svc_result['replayed'] && $provider === 'klasha' && function_exists('bc_klasha_fx_quote')) {
            $q = bc_klasha_fx_quote($vid, [
                'sourceCurrency' => 'USD',
                'sourceAmount' => number_format($amount_usd, 2, '.', ''),
                'destinationCurrency' => $payload['currency'],
                'mode' => 'SOURCE',
            ]);
            $qd = (isset($q['json']['data']) && is_array($q['json']['data'])) ? $q['json']['data'] : [];
            if (!empty($q['ok']) && isset($qd['destinationAmount']) && is_numeric($qd['destinationAmount'])) {
                $quote_note = ' Indicative live quote: $' . number_format($amount_usd, 2) . ' ≈ ' . number_format((float)$qd['destinationAmount'], 2) . ' ' . $payload['currency'] . (isset($qd['rate']) ? ' at rate ' . $qd['rate'] : '') . ' (fees confirmed at settlement).';
            }
        } elseif ($ref !== '' && !$svc_result['replayed'] && $provider === 'flutterwave' && function_exists('bc_flw_endpoint')) {
            $q = bc_flw_endpoint($vid, 'transfer_rates', 'GET', [
                'source_currency' => 'USD',
                'destination_currency' => $payload['currency'],
                'amount' => $amount_usd,
            ]);
            $qd = (isset($q['json']['data']) && is_array($q['json']['data'])) ? $q['json']['data'] : [];
            if (!empty($q['ok']) && isset($qd['rate']) && is_numeric($qd['rate'])) {
                $quote_note = ' Indicative live rate: 1 USD = ' . $qd['rate'] . ' ' . $payload['currency'] . ' (fees confirmed at settlement).';
            }
        }
        $notice = $ref !== ''
            ? "Transfer request received ($ref). This is a request only; no payout or wallet movement has occurred." . $quote_note
            : bc_svc_retry_message($svc_result);
        $notice_type = $ref !== '' ? 'success' : 'danger';
    }
}
if (isset($svc_result) && $svc_result['ok'] && $svc_result['replayed']) {
    $notice = 'Existing request recovered; no new request was created. ' . $notice;
}
$my_requests = bc_svc_requests_for_user($vid, (int)$svc_user['id'], 'intl_transfer');

// Compute fintech telemetry stats from user history & routed services
$total_req_count = count($my_requests);
$total_req_usd = 0.0;
$completed_count = 0;
$pending_count = 0;
foreach ($my_requests as $r) {
    $rp = json_decode($r['payload'] ?? '{}', true) ?: [];
    $total_req_usd += (float)($rp['amount_usd'] ?? 0);
    $st = strtolower((string)($r['status'] ?? ''));
    if (in_array($st, ['completed', 'approved', 'settled', 'success'], true)) {
        $completed_count++;
    } else {
        $pending_count++;
    }
}
$active_service_count = count($services);
$first_service_slug = $active_service_count > 0 ? (string)array_key_first($services) : '';

include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Send Money Abroad | " . ($get_all_site_details["site_title"] ?? 'Platform'));
bc_app_sidebar();
bc_app_topbar(array("title" => "SEND MONEY ABROAD", "crumb" => "Home / Send Money Abroad"));
?>
<style>
    .ft-hero {
        position: relative;
        overflow: hidden;
        border-radius: 20px;
        padding: 28px;
        color: #fff;
        background: linear-gradient(135deg, #0b1220 0%, #152238 52%, var(--brand-dark, #0f766e) 100%);
        box-shadow: 0 20px 44px -22px rgba(11, 18, 32, 0.55);
    }
    .ft-hero::after {
        content: "";
        position: absolute;
        width: 320px; height: 320px;
        right: -80px; top: -110px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.16) 0%, transparent 70%);
        pointer-events: none;
    }
    .ft-pill {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 5px 12px; border-radius: 999px;
        background: rgba(255,255,255,.12);
        border: 1px solid rgba(255,255,255,.22);
        font-size: 12px; font-weight: 700;
    }
    .ft-dot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; }
    .ft-stat {
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 16px;
        padding: 18px;
        height: 100%;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.03);
        transition: transform .18s ease, box-shadow .18s ease;
    }
    .ft-stat:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06); }
    .ft-stat-ico {
        width: 40px; height: 40px; border-radius: 12px;
        display: inline-flex; align-items: center; justify-content: center;
        background: rgba(var(--bs-primary-rgb), .10);
        color: var(--brand-ink);
        margin-bottom: 12px;
    }
    .ft-stat-val { font-size: 1.45rem; font-weight: 800; color: var(--ink); line-height: 1.15; }
    .ft-stat-lbl { font-size: 12.5px; color: var(--muted); margin-top: 3px; }
    .ft-svc-card {
        border: 1.5px solid var(--line);
        border-radius: 16px;
        padding: 16px;
        background: #fff;
        cursor: pointer;
        transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        height: 100%;
    }
    .ft-svc-card:hover { border-color: rgba(var(--bs-primary-rgb), .45); transform: translateY(-2px); }
    .ft-svc-card.is-selected {
        border-color: var(--brand);
        background: rgba(var(--bs-primary-rgb), .035);
        box-shadow: 0 10px 24px -14px rgba(var(--bs-primary-rgb), .45);
    }
    .ft-feat-tile {
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 15px;
        background: rgba(var(--wash-rgb), .65);
        height: 100%;
    }
    .ft-step {
        border: 1px solid var(--line);
        border-radius: 16px;
        padding: 18px;
        background: #fff;
        height: 100%;
        position: relative;
    }
    .ft-step-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 800;
        background: rgba(var(--bs-primary-rgb), .12);
        color: var(--brand-ink);
        margin-bottom: 10px;
    }
    .ft-chip {
        border: 1px solid var(--line);
        background: #fff;
        color: var(--ink);
        padding: 6px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: all .15s ease;
    }
    .ft-chip:hover, .ft-chip.is-on {
        border-color: var(--brand);
        background: var(--brand);
        color: var(--brand-fg);
    }
    .ft-summary {
        border-radius: 16px;
        padding: 20px;
        background: linear-gradient(160deg, rgba(var(--bs-primary-rgb), .06), rgba(var(--wash-rgb), .9));
        border: 1px solid rgba(var(--bs-primary-rgb), .20);
    }
    .ft-status-pill {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 4px 10px; border-radius: 999px;
        font-size: 11.5px; font-weight: 700; text-transform: capitalize;
    }
    .ft-status-pill.ok { background: rgba(var(--ok-rgb), .12); color: var(--ok); }
    .ft-status-pill.warn { background: rgba(var(--warn-rgb), .14); color: #92400e; }
    .ft-status-pill.muted { background: rgba(var(--muted-rgb), .14); color: var(--muted); }
    .ft-table th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); border-bottom: 1px solid var(--line); padding: 10px 12px; }
    .ft-table td { padding: 12px; border-bottom: 1px solid var(--line); font-size: 13.5px; vertical-align: middle; }
</style>

<section class="section dashboard">
    <!-- Fintech Hero Banner -->
    <div class="ft-hero mb-4">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="ft-pill"><span class="ft-dot"></span> International Transfers</span>
                    <span class="ft-pill">Rail: <?php echo htmlspecialchars($provider !== '' ? $provider_label : 'Unavailable'); ?></span>
                    <span class="ft-pill">Indicative Live FX</span>
                </div>
                <h3 class="fw-bold mb-2">Send money across borders with transparent FX</h3>
                <p class="opacity-75 mb-0" style="max-width:62ch">Submit verified cross-border payout requests across supported global corridors and China settlement rails. Every option shown mirrors live platform routing.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="#new-transfer-form" class="btn btn-light rounded-pill px-4 fw-bold">New Transfer Request</a>
            </div>
        </div>
    </div>

    <!-- 4 Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18"/></svg></div>
                <div class="ft-stat-val"><?php echo (int)$active_service_count; ?></div>
                <div class="ft-stat-lbl">Enabled transfer corridors</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                <div class="ft-stat-val">$<?php echo number_format($total_req_usd, 2); ?></div>
                <div class="ft-stat-lbl">Total requested volume</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg></div>
                <div class="ft-stat-val"><?php echo (int)$total_req_count; ?></div>
                <div class="ft-stat-lbl">Submitted requests (<?php echo (int)$pending_count; ?> in review)</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg></div>
                <div class="ft-stat-val"><?php echo htmlspecialchars($provider !== '' ? $provider_label : 'Paused'); ?></div>
                <div class="ft-stat-lbl">Active settlement rail · <?php echo (int)$completed_count; ?> settled</div>
            </div>
        </div>
    </div>

    <?php if (isset($services['china_payout'])): ?>
    <!-- 3-Step China Payout Workflow -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h6 class="fw-bold mb-0">Payout to China in 3 steps</h6>
                    <span class="small text-muted">Streamlined RMB / CNY settlement directly to Chinese bank accounts and wallets</span>
                </div>
                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2">China Corridor Active</span>
            </div>
            <div class="row g-3">
                <div class="col-md-4"><div class="ft-step"><span class="ft-step-badge">STEP 01 · WALLET</span><strong class="d-block mb-1">Create USD Wallet</strong><p class="small text-muted mb-0">Open a multi-currency USD wallet with instant verification.</p></div></div>
                <div class="col-md-4"><div class="ft-step"><span class="ft-step-badge">STEP 02 · FUNDING</span><strong class="d-block mb-1">Fund Instantly</strong><p class="small text-muted mb-0">Fund via NGN/GHS/ZAR conversion, virtual accounts or USDC/USDT at live FX.</p></div></div>
                <div class="col-md-4"><div class="ft-step"><span class="ft-step-badge">STEP 03 · SETTLEMENT</span><strong class="d-block mb-1">China Settlement</strong><p class="small text-muted mb-0">Lock a live quote and pay Chinese bank accounts &amp; mobile wallets directly.</p></div></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Service Cards + Platform Feature Cards -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h6 class="fw-bold mb-0">Transfer Services &amp; Capabilities</h6>
                    <span class="small text-muted">Select any enabled service card below to pre-fill your transfer request</span>
                </div>
            </div>
            <?php if ($services): ?>
            <div class="row g-3 mb-4" id="intlServiceCards">
                <?php $s_idx = 0; foreach ($services as $slug => $svc): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="ft-svc-card<?php echo $s_idx === 0 ? ' is-selected' : ''; ?>" data-intl-svc="<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>" role="button" tabindex="0">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <strong class="d-block"><?php echo htmlspecialchars($svc['label']); ?></strong>
                            <span class="badge bg-success bg-opacity-10 text-success">Active</span>
                        </div>
                        <p class="small text-muted mb-0"><?php echo htmlspecialchars($svc['note']); ?></p>
                    </div>
                </div>
                <?php $s_idx++; endforeach; ?>
            </div>
            <?php else: ?>
                <div class="alert alert-warning small mb-4">No cross-border transfer services are currently enabled for this route.</div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-sm-6 col-xl-3"><div class="ft-feat-tile"><strong class="d-block small mb-1">Indicative Live FX Quote</strong><span class="small text-muted">Provider rates are fetched read-only on submission so you see indicative payouts up front.</span></div></div>
                <div class="col-sm-6 col-xl-3"><div class="ft-feat-tile"><strong class="d-block small mb-1">Idempotent Request Guard</strong><span class="small text-muted">Unique request keys prevent accidental duplicate submissions when retrying.</span></div></div>
                <div class="col-sm-6 col-xl-3"><div class="ft-feat-tile"><strong class="d-block small mb-1">Route Snapshot Protection</strong><span class="small text-muted">Every submission verifies live provider routing before persisting to your ledger.</span></div></div>
                <div class="col-sm-6 col-xl-3"><div class="ft-feat-tile"><strong class="d-block small mb-1">Referral Volume Eligible</strong><span class="small text-muted">Completed International Transfers count toward your referral coin unlock target.</span></div></div>
            </div>
        </div>
    </div>

    <!-- Transfer Request Form + Live Summary Guard -->
    <div class="row g-4" id="new-transfer-form">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0">New transfer request</h6>
                        <a href="IntlTransfer.php" class="small fw-semibold">Reset form</a>
                    </div>
                    <?php if ($notice !== ''): ?><div class="alert alert-<?php echo $notice_type; ?> rounded-3 small"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
                    <?php if ($provider === ''): ?><p class="alert alert-warning small">Request routing is disabled or unavailable. No request can be submitted.</p><?php endif; ?>
                    <p class="small text-muted mb-3">Submitting records a request for review only. It does not debit a wallet, execute a payment or confirm provider acceptance.</p>

                    <form method="post" id="intlTransferForm">
                        <input type="hidden" name="request_key" value="<?php echo htmlspecialchars($svc_form_key, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo bc_csrf_field(); ?>
                        <input type="hidden" name="route_snapshot" value="<?php echo htmlspecialchars($provider, ENT_QUOTES, 'UTF-8'); ?>">
                        <p class="small text-muted">Keep this form unchanged when retrying an unconfirmed submission. <a href="IntlTransfer.php">Start a new request</a> only for a separate request.</p>

                        <div class="mb-3">
                            <span class="d-block small fw-bold mb-2">Quick Amount Presets (USD)</span>
                            <div class="d-flex flex-wrap gap-2" id="intlAmtPresets">
                                <button type="button" class="ft-chip" data-amt="100">$100</button>
                                <button type="button" class="ft-chip" data-amt="250">$250</button>
                                <button type="button" class="ft-chip" data-amt="500">$500</button>
                                <button type="button" class="ft-chip" data-amt="1000">$1,000</button>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Service</label>
                                <select name="service" id="intlServiceSelect" class="form-select">
                                    <?php foreach ($services as $slug => $svc): ?>
                                        <option value="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($svc['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Amount (USD)</label>
                                <input type="number" step="0.01" min="1" name="amount_usd" id="intlAmountInput" class="form-control" placeholder="100.00" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Recipient name</label>
                                <input type="text" name="recipient_name" id="intlRecipName" class="form-control" placeholder="Full legal / account name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Recipient country</label>
                                <input type="text" name="recipient_country" id="intlRecipCountry" class="form-control" placeholder="e.g. China, United Kingdom">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Recipient account / wallet</label>
                                <input type="text" name="recipient_account" class="form-control" placeholder="IBAN, Alipay/WeChat ID or account no." required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Payout currency</label>
                                <input type="text" name="currency" id="intlCurrencyInput" class="form-control" value="USD" placeholder="USD, GBP, EUR, CNY">
                            </div>
                        </div>
                        <button type="submit" name="send_transfer" <?php echo $provider === '' ? 'disabled' : ''; ?> class="btn btn-primary rounded-pill px-4 fw-bold mt-4">Submit Transfer Request</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4 d-flex flex-column justify-content-between gap-3">
                    <div>
                        <h6 class="fw-bold mb-3">Live Request Summary</h6>
                        <div class="ft-summary mb-3">
                            <div class="d-flex justify-content-between py-2 border-bottom"><span class="small text-muted">Selected Service</span><strong class="small" id="sumIntlSvc"><?php echo $first_service_slug !== '' ? htmlspecialchars($services[$first_service_slug]['label']) : 'None'; ?></strong></div>
                            <div class="d-flex justify-content-between py-2 border-bottom"><span class="small text-muted">Amount (USD)</span><strong class="small" id="sumIntlAmt">$0.00</strong></div>
                            <div class="d-flex justify-content-between py-2 border-bottom"><span class="small text-muted">Payout Currency</span><strong class="small" id="sumIntlCur">USD</strong></div>
                            <div class="d-flex justify-content-between py-2 border-bottom"><span class="small text-muted">Recipient</span><strong class="small" id="sumIntlRecip">—</strong></div>
                            <div class="d-flex justify-content-between pt-2"><span class="small text-muted">Routing Rail</span><strong class="small"><?php echo htmlspecialchars($provider !== '' ? $provider_label : 'Unavailable'); ?></strong></div>
                        </div>
                    </div>
                    <div class="p-3 rounded-3 bg-light small text-muted">
                        <strong>Settlement Notice:</strong> Rates are locked at live FX before settlement. Completed Crypto &amp; International Transfer volume counts toward referral rewards.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Transfer Request History -->
    <div class="card border-0 shadow-sm rounded-4 mt-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h6 class="fw-bold mb-0">My Transfer Request History</h6>
                    <span class="small text-muted">Track submitted cross-border transfer requests and settlement statuses</span>
                </div>
                <span class="badge bg-secondary bg-opacity-10 text-dark"><?php echo (int)$total_req_count; ?> records</span>
            </div>
            <?php if ($my_requests): ?>
            <div class="table-responsive">
                <table class="table ft-table mb-0">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Recipient</th>
                            <th>Country</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_requests as $r):
                            $p = json_decode($r['payload'] ?? '{}', true) ?: [];
                            $st = strtolower((string)($r['status'] ?? 'pending'));
                            $pill_cls = in_array($st, ['completed', 'approved', 'settled', 'success'], true) ? 'ok' : (in_array($st, ['pending', 'held', 'review'], true) ? 'warn' : 'muted');
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($r['reference']); ?></strong></td>
                            <td><?php echo htmlspecialchars($p['recipient_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($p['recipient_country'] ?? '—'); ?></td>
                            <td><strong>$<?php echo number_format((float)($p['amount_usd'] ?? 0), 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($p['currency'] ?? 'USD'); ?></td>
                            <td><span class="ft-status-pill <?php echo $pill_cls; ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="p-4 text-center rounded-4 bg-light small text-muted">No international transfer requests submitted yet. Complete the form above to record your first request.</div>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
(function () {
    var sel = document.getElementById('intlServiceSelect');
    var cards = document.querySelectorAll('[data-intl-svc]');
    var amtInput = document.getElementById('intlAmountInput');
    var curInput = document.getElementById('intlCurrencyInput');
    var recipInput = document.getElementById('intlRecipName');
    var presets = document.querySelectorAll('#intlAmtPresets [data-amt]');

    var sumSvc = document.getElementById('sumIntlSvc');
    var sumAmt = document.getElementById('sumIntlAmt');
    var sumCur = document.getElementById('sumIntlCur');
    var sumRecip = document.getElementById('sumIntlRecip');

    function syncSummary() {
        if (sel && sumSvc && sel.selectedIndex >= 0) {
            sumSvc.textContent = sel.options[sel.selectedIndex].text;
        }
        if (amtInput && sumAmt) {
            var v = parseFloat(amtInput.value) || 0;
            sumAmt.textContent = '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        if (curInput && sumCur) {
            sumCur.textContent = (curInput.value || 'USD').toUpperCase();
        }
        if (recipInput && sumRecip) {
            sumRecip.textContent = recipInput.value.trim() || '—';
        }
        for (var i = 0; i < cards.length; i++) {
            var active = sel && cards[i].getAttribute('data-intl-svc') === sel.value;
            cards[i].classList.toggle('is-selected', !!active);
        }
    }

    for (var i = 0; i < cards.length; i++) {
        cards[i].addEventListener('click', function () {
            if (sel) sel.value = this.getAttribute('data-intl-svc');
            syncSummary();
        });
        cards[i].addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (sel) sel.value = this.getAttribute('data-intl-svc');
                syncSummary();
            }
        });
    }
    for (var j = 0; j < presets.length; j++) {
        presets[j].addEventListener('click', function () {
            if (amtInput) amtInput.value = this.getAttribute('data-amt');
            for (var k = 0; k < presets.length; k++) presets[k].classList.toggle('is-on', presets[k] === this);
            syncSummary();
        });
    }
    if (sel) sel.addEventListener('change', syncSummary);
    if (amtInput) amtInput.addEventListener('input', syncSummary);
    if (curInput) curInput.addEventListener('input', syncSummary);
    if (recipInput) recipInput.addEventListener('input', syncSummary);
    syncSummary();
})();
</script>
<?php bc_app_foot(); ?>
