<?php session_start();
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

if(!isServiceEnabled('intl_transfer')){
    header("Location: Dashboard.php");
    exit();
}
$provider = bc_svc_active_provider($vid);
$provider_label = bc_svc_provider_label($provider);
$services = bc_svc_provider_services($vid);
$notice = ''; $notice_type = 'info';
if (isset($_POST['send_transfer'])) {
    $amount_usd = (float)($_POST['amount_usd'] ?? 0);
    $payload = [
        'recipient_name' => trim(strip_tags($_POST['recipient_name'] ?? '')),
        'recipient_country' => trim(strip_tags($_POST['recipient_country'] ?? '')),
        'recipient_account' => trim(strip_tags($_POST['recipient_account'] ?? '')),
        'currency' => strtoupper(trim(strip_tags($_POST['currency'] ?? 'USD'))),
        'amount_usd' => $amount_usd,
        'service' => trim(strip_tags($_POST['service'] ?? 'global_transfer')),
    ];
    if ($amount_usd <= 0 || $payload['recipient_name'] === '' || $payload['recipient_account'] === '') {
        $notice = 'Please complete the recipient name, account and a valid amount.';
        $notice_type = 'danger';
    } else {
        $ref = bc_svc_request_create($vid, $svc_user, 'intl_transfer', $provider, $payload);
        // §26: indicative LIVE quote from the active provider (read-only — a
        // quote moves no money; settlement still follows the recorded request).
        $quote_note = '';
        if ($ref !== '' && $provider === 'klasha' && function_exists('bc_klasha_fx_quote')) {
            $q = bc_klasha_fx_quote($vid, [
                'sourceCurrency' => 'USD',
                'sourceAmount' => number_format($amount_usd, 2, '.', ''),
                'destinationCurrency' => $payload['currency'],
                'mode' => 'SOURCE',
            ]);
            $qd = (isset($q['json']['data']) && is_array($q['json']['data'])) ? $q['json']['data'] : [];
            if (!empty($q['ok']) && isset($qd['destinationAmount']) && is_numeric($qd['destinationAmount'])) {
                $quote_note = ' Indicative live Klasha quote: $' . number_format($amount_usd, 2) . ' ≈ ' . number_format((float)$qd['destinationAmount'], 2) . ' ' . $payload['currency'] . (isset($qd['rate']) ? ' at rate ' . $qd['rate'] : '') . ' (fees confirmed at settlement).';
            }
        } elseif ($ref !== '' && $provider === 'flutterwave' && function_exists('bc_flw_endpoint')) {
            $q = bc_flw_endpoint($vid, 'transfer_rates', 'GET', [
                'source_currency' => 'USD',
                'destination_currency' => $payload['currency'],
                'amount' => $amount_usd,
            ]);
            $qd = (isset($q['json']['data']) && is_array($q['json']['data'])) ? $q['json']['data'] : [];
            if (!empty($q['ok']) && isset($qd['rate']) && is_numeric($qd['rate'])) {
                $quote_note = ' Indicative live Flutterwave rate: 1 USD = ' . $qd['rate'] . ' ' . $payload['currency'] . ' (fees confirmed at settlement).';
            }
        }
        $notice = $ref !== ''
            ? "Transfer request received ($ref). We will confirm the live rate and complete your payout shortly." . $quote_note
            : 'We could not record your request. Please try again.';
        $notice_type = $ref !== '' ? 'success' : 'danger';
    }
}
$my_requests = bc_svc_requests_for_user($vid, (int)$svc_user['id'], 'intl_transfer');
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Send Money Abroad | " . ($get_all_site_details["site_title"] ?? 'Platform'));
bc_app_sidebar();
bc_app_topbar(array("title" => "SEND MONEY ABROAD", "crumb" => "Home / Send Money Abroad"));
?>
<section class="section dashboard">
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <span class="badge bg-primary mb-2">Powered by <?php echo htmlspecialchars($provider_label); ?></span>
            <h4 class="fw-bold mb-1">Send money across borders</h4>
            <p class="text-muted small mb-0">Services below are exactly what your active provider (<?php echo htmlspecialchars($provider_label); ?>) offers. Switch providers in admin and this page follows — nothing from another provider is shown.</p>
        </div>
    </div>

    <?php if (isset($services['china_payout'])): ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Payout to China in 3 steps</h6>
            <div class="row g-3 text-center">
                <div class="col-md-4"><div class="p-3 bg-light rounded-4 h-100"><div class="fw-bold text-primary">STEP 01 · WALLET</div><strong>Create USD Wallet</strong><p class="small text-muted mb-0">Open a multi-currency USD wallet with instant verification.</p></div></div>
                <div class="col-md-4"><div class="p-3 bg-light rounded-4 h-100"><div class="fw-bold text-primary">STEP 02 · FUNDING</div><strong>Fund Instantly</strong><p class="small text-muted mb-0">Fund via NGN/GHS/ZAR conversion, virtual accounts or USDC/USDT at live FX.</p></div></div>
                <div class="col-md-4"><div class="p-3 bg-light rounded-4 h-100"><div class="fw-bold text-primary">STEP 03 · SETTLEMENT</div><strong>China Settlement</strong><p class="small text-muted mb-0">Lock a live quote and pay Chinese bank accounts &amp; mobile wallets directly.</p></div></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body p-4">
                <h6 class="fw-bold mb-3">What <?php echo htmlspecialchars($provider_label); ?> offers you</h6>
                <?php foreach ($services as $slug => $svc): ?>
                    <div class="d-flex gap-3 mb-3">
                        <i class="bi bi-check-circle-fill text-success mt-1"></i>
                        <div><strong class="d-block"><?php echo htmlspecialchars($svc['label']); ?></strong><span class="small text-muted"><?php echo htmlspecialchars($svc['note']); ?></span></div>
                    </div>
                <?php endforeach; ?>
            </div></div>
        </div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4"><div class="card-body p-4">
                <h6 class="fw-bold mb-3">New transfer request</h6>
                <?php if ($notice !== ''): ?><div class="alert alert-<?php echo $notice_type; ?> rounded-3 small"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
                <form method="post">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label small fw-bold">Service</label>
                            <select name="service" class="form-select">
                                <?php foreach ($services as $slug => $svc): ?><option value="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($svc['label']); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Amount (USD)</label><input type="number" step="0.01" min="1" name="amount_usd" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Recipient name</label><input type="text" name="recipient_name" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Recipient country</label><input type="text" name="recipient_country" class="form-control" placeholder="e.g. China, United Kingdom"></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Recipient account / wallet</label><input type="text" name="recipient_account" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Payout currency</label><input type="text" name="currency" class="form-control" value="USD"></div>
                    </div>
                    <button type="submit" name="send_transfer" class="btn btn-primary rounded-pill px-4 fw-bold mt-4">Submit Transfer Request</button>
                    <p class="small text-muted mt-3 mb-0">Rates are locked at live FX before settlement. Completed Crypto &amp; International Transfer volume counts toward referral rewards.</p>
                </form>
            </div></div>

            <?php if ($my_requests): ?>
            <div class="card border-0 shadow-sm rounded-4 mt-4"><div class="card-body p-4">
                <h6 class="fw-bold mb-3">My transfer requests</h6>
                <?php foreach ($my_requests as $r): $p = json_decode($r['payload'] ?? '{}', true) ?: []; ?>
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span><strong><?php echo htmlspecialchars($r['reference']); ?></strong> <span class="text-muted small">$<?php echo number_format((float)($p['amount_usd'] ?? 0), 2); ?> → <?php echo htmlspecialchars($p['recipient_name'] ?? ''); ?></span></span>
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($r['status']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div></div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php bc_app_foot(); ?>
