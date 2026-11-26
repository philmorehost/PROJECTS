<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include("../func/bc-admin-config.php");
require_once __DIR__ . '/../func/bc-payment-gateway-settlement.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (empty($get_logged_admin_details) || (int)($get_logged_admin_details['id'] ?? 0) <= 0 || (int)($get_logged_admin_details['status'] ?? 0) !== 1 || !empty($get_logged_admin_details['is_blocked'])) {
    http_response_code(403);
    exit('Please sign in with an active vendor admin account.');
}

$vendor_id = (int)$get_logged_admin_details['id'];
$enabled_gateways = bc_pg_list_platform_gateways($connection_server);

$purpose = strtolower(trim((string)($_POST['purpose'] ?? ($_GET['purpose'] ?? 'wallet_funding'))));
if ($purpose !== 'payout_activation') {
    $purpose = 'wallet_funding';
}

$payout_fee_norm = null;
if ($purpose === 'payout_activation') {
    $q_fee = @mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='payout_activation_fee' LIMIT 1");
    $raw_fee = ($q_fee && ($fr = mysqli_fetch_assoc($q_fee))) ? (string)($fr['option_value'] ?? '15000') : '15000';
    $payout_fee_norm = bc_pg_normalize_amount($raw_fee) ?: bc_pg_normalize_amount('15000');
}

$success_msg = '';
$error_msg = '';
$selected_gateway = '';
$inline_beewave = null;
$amount_value = '';

if ($purpose === 'payout_activation' && $payout_fee_norm !== null) {
    $amount_value = $payout_fee_norm['decimal'];
} else {
    $prefill_norm = bc_pg_normalize_amount($_GET['amount'] ?? '');
    if ($prefill_norm !== null) {
        $amount_value = $prefill_norm['decimal'];
    }
}

if ($method === 'GET' && !empty($_GET['verify_gateway'])) {
    $vgw = strtolower(trim((string)$_GET['verify_gateway']));
    $vref = (string)($_GET['reference'] ?? ($_GET['trxref'] ?? ($_GET['tx_ref'] ?? ($_GET['paymentReference'] ?? ''))));
    if ($vref !== '') {
        $settle = bc_pg_verify_and_settle_vendor($connection_server, $vendor_id, $vgw, $vref, $_GET);
        if (!empty($settle['ok'])) {
            $success_msg = (string)($settle['message'] ?? 'Your vendor wallet has been funded successfully.');
            $q_fresh = @mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
            if ($q_fresh && ($fresh_row = mysqli_fetch_assoc($q_fresh))) {
                $get_logged_admin_details = $fresh_row;
            }
        } else {
            $error_msg = (string)($settle['message'] ?? 'Payment could not be confirmed yet.');
        }
    }
}

if ($method === 'POST') {
    $csrf_post = $_POST['_csrf_token'] ?? '';
    $csrf_sess = $_SESSION['_csrf_token'] ?? '';
    $selected_gateway = strtolower(trim((string)($_POST['gateway'] ?? '')));

    if (!is_string($csrf_post) || !is_string($csrf_sess) || $csrf_sess === '' || !hash_equals($csrf_sess, $csrf_post)) {
        http_response_code(403);
        $error_msg = 'Security validation failed. Refresh the page and try again.';
    } elseif ($selected_gateway === '' || !isset($enabled_gateways[$selected_gateway])) {
        http_response_code(422);
        $error_msg = 'Choose an enabled payment gateway to continue.';
    } else {
        $submitted_amount = ($purpose === 'payout_activation' && $payout_fee_norm !== null)
            ? $payout_fee_norm['decimal']
            : trim((string)($_POST['amount'] ?? ''));
        $amt_norm = bc_pg_normalize_amount($submitted_amount);
        if ($amt_norm === null) {
            http_response_code(422);
            $error_msg = 'Enter an amount between NGN 100.00 and NGN 1,000,000.00.';
            if (strlen($submitted_amount) <= 32) {
                $amount_value = $submitted_amount;
            }
        } else {
            $amount_value = $amt_norm['decimal'];
            $init = bc_pg_init_vendor_gateway_checkout(
                $connection_server,
                $get_logged_admin_details,
                $selected_gateway,
                $amount_value,
                bc_pg_scheme_host(),
                '',
                true,
                $purpose
            );
            if (empty($init['ok'])) {
                $error_msg = (string)($init['message'] ?? 'Could not initialize payment checkout.');
            } elseif (($init['mode'] ?? '') === 'redirect' && !empty($init['authorization_url'])) {
                header('Location: ' . $init['authorization_url']);
                exit();
            } elseif (($init['mode'] ?? '') === 'inline_beewave') {
                $inline_beewave = $init;
            }
        }
    }
}

if ($selected_gateway === '' && !empty($enabled_gateways)) {
    $first_gw = reset($enabled_gateways);
    $selected_gateway = (string)($first_gw['gateway_name'] ?? '');
}

$vendor_banks = [];
$qb = @mysqli_query($connection_server, "SELECT bank_name, account_name, account_number, gateway_name FROM sas_vendor_banks WHERE vendor_id='$vendor_id' AND (status='1' OR status IS NULL)");
if ($qb) {
    while ($br = mysqli_fetch_assoc($qb)) {
        $acct_no = trim((string)($br['account_number'] ?? ''));
        if ($acct_no === '') continue;
        $bgw = strtolower(trim((string)($br['gateway_name'] ?? '')));
        if ($bgw !== '' && !bc_pg_is_platform_gateway_enabled($connection_server, $bgw)) {
            continue;
        }
        $vendor_banks[] = $br;
    }
}

$csrf_token = bc_generate_csrf_token();
$has_options = !empty($enabled_gateways);
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Fund Wallet | " . ($get_all_super_admin_site_details["site_title"] ?? "Admin"), array("area" => "admin"));
?>
<style>
    .vendor-gw-option {
        cursor: pointer;
        transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
    }
    .vendor-gw-option:has(input[type="radio"]:checked) {
        border-color: var(--bs-primary) !important;
        background-color: rgba(13, 110, 253, 0.05);
        box-shadow: 0 0 0 .15rem rgba(13, 110, 253, 0.15);
    }
    .vendor-gw-logo {
        width: 56px;
        height: 56px;
        object-fit: contain;
        border-radius: 12px;
        background: #fff;
        padding: 4px;
    }
</style>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "FUND WALLET", "crumb" => "Home / Fund Wallet")); ?>

    <section class="section dashboard">
      <div class="row justify-content-center g-4">
        <div class="col-lg-7">
          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                    <div>
                        <h1 class="h3 fw-bold text-primary mb-1">Fund Account</h1>
                        <p class="text-muted mb-0 small">Select an enabled payment gateway and enter an amount to fund your vendor wallet.</p>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted">Current Wallet Balance</div>
                        <div class="h5 fw-bold mb-0">₦<?php echo number_format((float)($get_logged_admin_details['balance'] ?? 0), 2); ?></div>
                    </div>
                </div>

                <?php if ($success_msg !== ''): ?>
                    <div class="alert alert-success rounded-4 mb-4" role="alert" tabindex="-1" data-fund-success>
                        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ((int)($get_logged_admin_details['payout_activated'] ?? 0) === 1 && $purpose === 'payout_activation'): ?>
                            <div class="mt-2"><a href="PaymentGateway.php" class="btn btn-sm btn-success rounded-pill">Configure Withdrawal Settings</a></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_msg !== ''): ?>
                    <div class="alert alert-warning rounded-4 mb-4" role="alert" tabindex="-1" data-fund-error>
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($purpose === 'payout_activation'): ?>
                    <div class="alert alert-primary rounded-4 mb-4">
                        <h6 class="fw-bold mb-1"><i class="bi bi-bank2 me-2"></i>Withdrawal Module Activation</h6>
                        <p class="small mb-0">Complete this one-time payment of ₦<?php echo number_format((float)$amount_value, 2); ?> to fund and activate the Automated Withdrawal Module.</p>
                    </div>
                <?php endif; ?>

                <?php if ($inline_beewave !== null): ?>
                    <div class="alert alert-info rounded-4 mb-4" data-beewave-checkout="<?php echo htmlspecialchars(json_encode($inline_beewave), ENT_QUOTES, 'UTF-8'); ?>">
                        <h6 class="fw-bold mb-1">Complete BeeWave Payment</h6>
                        <p class="small mb-2">Reference: <strong><?php echo htmlspecialchars((string)($inline_beewave['reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong> · Total Payable: <strong>₦<?php echo number_format((float)($inline_beewave['charge_amount'] ?? 0), 2); ?></strong></p>
                        <button type="button" id="beewaveLaunchBtn" class="btn btn-primary btn-sm rounded-pill">Open BeeWave Checkout</button>
                    </div>
                <?php endif; ?>

                <form method="post" action="Fund.php<?php echo $purpose === 'payout_activation' ? '?purpose=payout_activation' : ''; ?>" data-wallet-funding>
                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="purpose" value="<?php echo htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8'); ?>">

                    <fieldset class="mb-4">
                        <legend class="form-label small fw-bold text-muted text-uppercase mb-2">Payment Gateway</legend>
                        <?php if ($has_options): ?>
                            <div class="d-grid gap-2">
                                <?php foreach ($enabled_gateways as $gw): ?>
                                    <?php
                                        $gw_name = (string)($gw['gateway_name'] ?? '');
                                        $gw_label = (string)($gw['label'] ?? ucfirst($gw_name));
                                        $gw_markup = isset($gw['markup_percent']) ? (float)$gw['markup_percent'] : (float)($gw['percentage'] ?? 0.0);
                                        $gw_fee_label = (string)($gw['gateway_fee_label'] ?? '');
                                        $gw_rule = is_array($gw['gateway_fee_rule'] ?? null) ? $gw['gateway_fee_rule'] : [];
                                        $fee_cfg = array_merge($gw_rule, [
                                            'gateway_name' => $gw_name,
                                            'markup_percent' => $gw_markup,
                                            'max_markup_percent' => (float)($gw['max_markup_percent'] ?? 10),
                                            'gateway_fee_label' => $gw_fee_label,
                                            'fee_mode' => (string)($gw['fee_mode'] ?? 'customer_pays'),
                                        ]);
                                        $markup_str = rtrim(rtrim(number_format($gw_markup, 2), '0'), '.');
                                        $fee_parts = [];
                                        if ($gw_fee_label !== '') $fee_parts[] = 'Gateway: ' . $gw_fee_label;
                                        if ($gw_markup > 0) $fee_parts[] = $markup_str . '% platform fee';
                                        $fee_note = !empty($fee_parts) ? implode(' + ', $fee_parts) : 'Verified instant settlement';
                                        $logo_rel = '/asset/' . $gw_name . '.jpg';
                                    ?>
                                    <label class="vendor-gw-option d-flex align-items-center gap-3 p-3 border rounded-4">
                                        <input class="form-check-input mt-0" type="radio" name="gateway" value="<?php echo htmlspecialchars($gw_name, ENT_QUOTES, 'UTF-8'); ?>" data-fee-config="<?php echo htmlspecialchars(json_encode($fee_cfg), ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $selected_gateway === $gw_name ? 'checked' : ''; ?>>
                                        <?php if (file_exists(__DIR__ . '/..' . $logo_rel)): ?>
                                            <img src="<?php echo htmlspecialchars($logo_rel, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($gw_label, ENT_QUOTES, 'UTF-8'); ?>" class="vendor-gw-logo border">
                                        <?php endif; ?>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($gw_label, ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($fee_note, ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-secondary rounded-4 mb-0">
                                No online payment gateway is currently configured for vendor funding. Please contact platform support or use a virtual bank account below if available.
                            </div>
                        <?php endif; ?>
                    </fieldset>

                    <div class="mb-3">
                        <label for="fund-amount" class="form-label small fw-bold text-muted text-uppercase">Amount to Fund (₦)</label>
                        <input id="fund-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" maxlength="10" required pattern="(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?" value="<?php echo htmlspecialchars($amount_value, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-lg rounded-3 border bg-light" placeholder="e.g. 5000" aria-describedby="fund-help fund-status" <?php echo $purpose === 'payout_activation' ? 'readonly' : ''; ?>>
                        <div id="fund-help" class="form-text small">Enter NGN 100.00 – NGN 1,000,000.00. Exact gateway fee and credit are verified by the server.</div>
                    </div>

                    <div data-fee-preview class="border rounded-4 bg-light p-3 mb-4 small" style="display:none;" aria-live="polite">
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Amount Credited to Wallet</span><strong data-fee-credit>NGN 0.00</strong></div>
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Gateway Fee (<span data-fee-gw-label>Auto</span>)</span><span data-fee-gw>NGN 0.00</span></div>
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Platform Fee (<span data-fee-markup-pct>0%</span>)</span><span data-fee-markup>NGN 0.00</span></div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between"><span class="fw-bold">Total You Will Pay</span><strong class="text-primary" data-fee-total>NGN 0.00</strong></div>
                        <div class="text-muted mt-1" data-fee-note style="display:none;"></div>
                    </div>

                    <button id="fundProceedBtn" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold py-3 shadow-sm" <?php echo $has_options ? '' : 'disabled'; ?>>
                        CONTINUE TO PAYMENT
                    </button>
                    <p id="fund-status" role="status" aria-live="polite" class="small text-muted text-center mt-2 mb-0">
                        <?php echo $has_options ? 'Choose a payment gateway and enter an amount to continue.' : 'Online gateway checkout is currently unavailable.'; ?>
                    </p>
                </form>

                <?php if (!empty($vendor_banks)): ?>
                    <hr class="my-4 opacity-25">
                    <div>
                        <h2 class="h6 fw-bold mb-2"><i class="bi bi-bank2 me-2 text-primary"></i>Dedicated Bank Transfer Accounts</h2>
                        <p class="small text-muted mb-3">You can also transfer directly to any of your assigned virtual accounts below; your wallet updates automatically once settled.</p>
                        <div class="row g-2">
                            <?php foreach ($vendor_banks as $vb): ?>
                                <div class="col-md-6">
                                    <div class="border rounded-3 p-3 bg-light h-100">
                                        <div class="small text-muted"><?php echo htmlspecialchars((string)($vb['bank_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="fw-bold fs-5 text-dark"><?php echo htmlspecialchars((string)($vb['account_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars((string)($vb['account_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mt-4 pt-3 border-top d-flex justify-content-between flex-wrap gap-2 small">
                    <a href="SelfTransactions.php" class="text-decoration-none"><i class="bi bi-receipt me-1"></i>Wallet Transaction History</a>
                    <a href="Dashboard.php" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>
                </div>
            </div>
          </div>
        </div>
      </div>
    </section>

<script src="/jsfile/bc-wallet-funding.js?v=2" defer></script>
<?php bc_adm_foot(array("area" => "admin")); ?>
