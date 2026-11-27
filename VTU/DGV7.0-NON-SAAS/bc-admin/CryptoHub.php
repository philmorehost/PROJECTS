<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include("../func/bc-admin-config.php");
include("../func/bc-crypto-func.php");
require_once __DIR__ . '/../func/bc-crypto-a2p.php';

$vid = (int)$get_logged_admin_details['id'];
$adminEmail = (string)($get_logged_admin_details['email'] ?? '');
$activeAdminTab = in_array(($_GET['tab'] ?? 'desk'), ['desk', 'rates', 'logs'], true) ? $_GET['tab'] : 'desk';

// §29: emailed step-up code sender for approvals.
if (isset($_POST['send_stepup_code'])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $send_err = function_exists('bc_stepup_send_email_otp')
        ? bc_stepup_send_email_otp($vid, $adminEmail, $adminEmail, 'crypto_withdrawal_approve', 'Crypto Hub Order Approval')
        : 'Verification is unavailable.';
    $_SESSION['product_purchase_response'] = $send_err === '' ? "Verification code sent to your email." : "Error: $send_err";
    header("Location: CryptoHub.php?tab=" . urlencode($activeAdminTab));
    exit();
}

// Refresh live rates on demand
if (isset($_POST['refresh_live_rates'])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $bundle = bc_crypto_a2p_all_quotes($vid, true);
    $src = $bundle['rates']['source'] ?? 'manual';
    $_SESSION['product_purchase_response'] = ($src === 'live')
        ? "Live market rates refreshed (1 USD = ₦" . number_format((float)$bundle['rates']['usd_ngn'], 2) . ")."
        : "Live feed unreachable; using cached/configured rates (1 USD = ₦" . number_format((float)$bundle['rates']['usd_ngn'], 2) . ").";
    header("Location: CryptoHub.php?tab=rates");
    exit();
}

// Save A2P / P2A Desk configuration (Rates, Markups, Deposit Wallets)
if (isset($_POST['save_a2p_config'])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $curCfg = bc_crypto_a2p_get_config($vid);
    $catalogue = bc_crypto_a2p_catalogue();

    $newCfg = $curCfg;
    $newCfg['rate_mode'] = (($_POST['rate_mode'] ?? 'live') === 'manual') ? 'manual' : 'live';
    $usdNgn = (float)($_POST['usd_ngn_rate'] ?? $curCfg['usd_ngn_rate']);
    if ($usdNgn > 0) $newCfg['usd_ngn_rate'] = round($usdNgn, 4);
    $newCfg['buy_markup_pct'] = max(0.0, min(50.0, round((float)($_POST['buy_markup_pct'] ?? $curCfg['buy_markup_pct']), 2)));
    $newCfg['sell_markdown_pct'] = max(0.0, min(50.0, round((float)($_POST['sell_markdown_pct'] ?? $curCfg['sell_markdown_pct']), 2)));
    $newCfg['swap_fee_pct'] = max(0.0, min(25.0, round((float)($_POST['swap_fee_pct'] ?? $curCfg['swap_fee_pct']), 2)));
    $minNgn = max(100.0, round((float)($_POST['min_trade_ngn'] ?? $curCfg['min_trade_ngn']), 2));
    $maxNgn = max($minNgn, round((float)($_POST['max_trade_ngn'] ?? $curCfg['max_trade_ngn']), 2));
    $newCfg['min_trade_ngn'] = $minNgn;
    $newCfg['max_trade_ngn'] = $maxNgn;
    $newCfg['desk_notice'] = substr(trim(strip_tags((string)($_POST['desk_notice'] ?? $curCfg['desk_notice']))), 0, 500);

    $postedAssets = $_POST['assets'] ?? [];
    foreach ($catalogue as $code => $meta) {
        $in = is_array($postedAssets[$code] ?? null) ? $postedAssets[$code] : [];
        $manualUsd = isset($in['manual_usd_price']) && is_numeric($in['manual_usd_price']) && (float)$in['manual_usd_price'] > 0
            ? (float)$in['manual_usd_price']
            : (float)($curCfg['assets'][$code]['manual_usd_price'] ?? $meta['default_usd']);
        $newCfg['assets'][$code] = [
            'enabled' => !empty($in['enabled']),
            'buy_enabled' => !empty($in['buy_enabled']),
            'sell_enabled' => !empty($in['sell_enabled']),
            'swap_enabled' => !empty($in['swap_enabled']),
            'deposit_address' => substr(trim(strip_tags((string)($in['deposit_address'] ?? ''))), 0, 128),
            'network_note' => substr(trim(strip_tags((string)($in['network_note'] ?? ''))), 0, 160),
            'manual_usd_price' => $manualUsd,
        ];
    }

    bc_crypto_a2p_save_config($vid, $newCfg);
    if (function_exists('bc_sec_audit')) {
        bc_sec_audit($vid, 'admin', $adminEmail, 'crypto_a2p_config_save', 'crypto_a2p', 'Updated rates, markups, and deposit wallets');
    }
    $_SESSION['product_purchase_response'] = "CryptoHub rates, markup fees, and deposit wallets saved.";
    header("Location: CryptoHub.php?tab=rates");
    exit();
}

// Handle order / withdrawal approval or rejection
if (isset($_POST['action'])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $act = (string)($_POST['action'] ?? '');
    $stepupActions = ['approve_withdrawal', 'approve_a2p_buy', 'reject_a2p_buy', 'approve_p2a_sell', 'reject_p2a_sell'];
    if (in_array($act, $stepupActions, true) && function_exists('bc_stepup_admin_verify')) {
        $stepup_err = bc_stepup_admin_verify($get_logged_admin_details, $_POST, 'crypto_withdrawal_approve');
        if ($stepup_err !== '') {
            $_SESSION['product_purchase_response'] = "Error: $stepup_err";
            header("Location: CryptoHub.php?tab=" . urlencode($activeAdminTab));
            exit();
        }
    }

    $ref = mysqli_real_escape_string($connection_server, (string)($_POST['reference'] ?? ''));

    if (in_array($act, ['approve_a2p_buy', 'reject_a2p_buy', 'approve_p2a_sell', 'reject_p2a_sell'], true)) {
        $res = bc_crypto_a2p_admin_resolve($connection_server, $vid, $adminEmail, $act, (string)($_POST['reference'] ?? ''), [
            'tx_hash' => $_POST['tx_hash'] ?? '',
            'reason' => $_POST['reason'] ?? '',
            'note' => $_POST['note'] ?? '',
        ]);
        if ($res['ok'] && function_exists('bc_sec_audit')) {
            bc_sec_audit($vid, 'admin', $adminEmail, $act, (string)($_POST['reference'] ?? ''), $res['message']);
        }
        $_SESSION['product_purchase_response'] = ($res['ok'] ? '' : 'Error: ') . $res['message'];
        header("Location: CryptoHub.php?tab=desk");
        exit();
    }

    if ($act === 'approve_withdrawal') {
        $q = mysqli_query($connection_server, "SELECT * FROM sas_crypto_withdrawals WHERE reference='$ref' AND vendor_id='$vid' AND status='pending' LIMIT 1");
        if ($r = mysqli_fetch_assoc($q)) {
            $currency = $r['currency_code'];
            $amount = $r['crypto_amount'];
            $address = $r['address'];
            $ngn_amount = (float)$r['ngn_amount'];

            if ($ngn_amount > 0) {
                if (!isServiceEnabled('payout')) {
                    $_SESSION['product_purchase_response'] = "Error: Payout service is currently disabled. Please enable it in Service Control.";
                    header("Location: CryptoHub.php?tab=logs");
                    exit();
                }

                $payhub = getGatewayDetails('payhub', $vid);
                $using_platform_keys = ($payhub['source_table'] ?? '') == 'sas_super_admin_payment_gateways' || ($payhub['vendor_id'] ?? 0) == 0;
                if ($using_platform_keys) {
                    $check_v = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT balance FROM sas_vendors WHERE id='$vid' LIMIT 1"));
                    if (($check_v['balance'] ?? 0) < $ngn_amount) {
                        $_SESSION['product_purchase_response'] = "Error: Insufficient platform balance to process this payout.";
                        header("Location: CryptoHub.php?tab=logs");
                        exit();
                    }
                }

                preg_match('/^(\d+)\s*\(([^)]+)\)$/', $address, $matches);
                $acc_no = $matches[1] ?? '';
                $bank_code = $matches[2] ?? '';

                $u_q = mysqli_query($connection_server, "SELECT firstname, lastname FROM sas_users WHERE username='" . mysqli_real_escape_string($connection_server, $r['username']) . "' AND vendor_id='$vid' LIMIT 1");
                $u_r = mysqli_fetch_assoc($u_q);
                $acc_name = ($u_r['firstname'] ?? '') . ' ' . ($u_r['lastname'] ?? '');

                $res = payhubInitiatePayout($ngn_amount, $bank_code, $acc_no, $acc_name, "Crypto Withdrawal - " . $r['username'], $vid);

                if (($res['status'] ?? '') == 'success') {
                    mysqli_query($connection_server, "UPDATE sas_crypto_withdrawals SET status='completed' WHERE reference='$ref'");
                    logCryptoTransaction($vid, $r['username'], 'withdrawal_ngn', $currency, $amount, 1, $res['data']['reference'] ?? ($res['reference'] ?? 'PO_' . time()), '', $acc_no, ['ngn_value' => $ngn_amount]);
                    if (function_exists('bc_sec_audit')) bc_sec_audit($vid, 'admin', $adminEmail, 'crypto_withdrawal_approve', $ref, 'NGN payout for ' . $r['username']);
                    $_SESSION['product_purchase_response'] = "NGN Withdrawal approved and processed via PayHub.";
                } else {
                    $_SESSION['product_purchase_response'] = "PayHub Error: " . ($res['message'] ?? 'Unknown');
                }
            } else {
                $_SESSION['product_purchase_response'] = "External crypto cash-out uses your manual wallet fulfillment or A2P desk.";
            }
        }
    } elseif ($act === 'reject_withdrawal') {
        $reason = mysqli_real_escape_string($connection_server, $_POST['reason'] ?? 'Rejected by Admin');
        $q = mysqli_query($connection_server, "SELECT * FROM sas_crypto_withdrawals WHERE reference='$ref' AND vendor_id='$vid' AND status='pending' LIMIT 1");
        if ($r = mysqli_fetch_assoc($q)) {
            updateUserCryptoBalance($vid, $r['username'], $r['currency_code'], $r['crypto_amount'], 'credit');
            mysqli_query($connection_server, "UPDATE sas_crypto_withdrawals SET status='rejected', reject_reason='$reason' WHERE reference='$ref'");
            if (function_exists('bc_sec_audit')) bc_sec_audit($vid, 'admin', $adminEmail, 'crypto_withdrawal_reject', $ref, 'Rejected: ' . $reason);
            $_SESSION['product_purchase_response'] = "Withdrawal rejected and funds refunded. Reason: $reason";
        }
    }
    header("Location: CryptoHub.php?tab=logs");
    exit();
}

$a2pBundle = bc_crypto_a2p_all_quotes($vid, false);
$a2pCfg = $a2pBundle['config'];
$a2pRates = $a2pBundle['rates'];
$a2pQuotes = $a2pBundle['quotes'];
$catalogue = bc_crypto_a2p_catalogue();

$pendingBuys = [];
$pendingSells = [];
$q_buys = mysqli_query($connection_server, "SELECT * FROM sas_crypto_transactions WHERE vendor_id='$vid' AND type='a2p_buy' AND status='2' ORDER BY id DESC LIMIT 100");
if ($q_buys) while ($r = mysqli_fetch_assoc($q_buys)) $pendingBuys[] = $r;

$q_sells = mysqli_query($connection_server, "SELECT * FROM sas_crypto_transactions WHERE vendor_id='$vid' AND type='p2a_sell' AND status='2' ORDER BY id DESC LIMIT 100");
if ($q_sells) while ($r = mysqli_fetch_assoc($q_sells)) $pendingSells[] = $r;

$withdrawals = mysqli_query($connection_server, "SELECT * FROM sas_crypto_withdrawals WHERE vendor_id='$vid' ORDER BY created_at DESC LIMIT 100");
$pendingWithdrawCount = 0;
if ($withdrawals) {
    while ($wr = mysqli_fetch_assoc($withdrawals)) {
        if ($wr['status'] === 'pending') $pendingWithdrawCount++;
    }
    mysqli_data_seek($withdrawals, 0);
}

$transactions = mysqli_query($connection_server, "SELECT * FROM sas_crypto_transactions WHERE vendor_id='$vid' ORDER BY created_at DESC LIMIT 100");
$csrfHtml = function_exists('bc_csrf_field') ? bc_csrf_field() : '';
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("CryptoHub Desk | Vendor Admin", array("area" => "admin"));
?>
<style>
.adm-ch-tabs {
    display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 1.25rem;
}
.adm-ch-tab {
    border: 1px solid var(--line); background: var(--surface); color: var(--ink);
    font-weight: 700; font-size: .85rem; padding: 9px 16px; border-radius: 999px;
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
}
.adm-ch-tab.is-active {
    background: var(--brand); color: var(--brand-fg, #fff); border-color: var(--brand);
}
</style>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "CRYPTO HUB DESK (A2P / P2A)", "crumb" => "Home / Crypto Hub")); ?>

<section class="section p-3 p-md-4">
<?php if (function_exists('bc_adm_render_api_info_box')) bc_adm_render_api_info_box('crypto-hub'); ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary btn-sm rounded-pill" href="CryptoPolicies.php">Crypto Asset Policies</a>
        <a class="btn btn-outline-secondary btn-sm rounded-pill" href="CryptoCustody.php">Custody Review</a>
        <a class="btn btn-outline-secondary btn-sm rounded-pill" href="CryptoInvoices.php">Invoice History</a>
    </div>
    <form method="post" class="m-0">
        <?php echo $csrfHtml; ?>
        <button type="submit" name="send_stepup_code" value="1" class="btn btn-primary btn-sm rounded-pill px-3">
            <i class="bi bi-envelope-check me-1"></i> Email Approval Code
        </button>
    </form>
</div>

<!-- KPI Strip -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
            <div class="small text-muted fw-bold">Pending Buy Orders (A2P)</div>
            <div class="h3 fw-bold mb-0 text-primary"><?php echo count($pendingBuys); ?></div>
            <div class="text-muted" style="font-size:.72rem;">Users waiting to receive crypto</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
            <div class="small text-muted fw-bold">Pending Sell Orders (P2A)</div>
            <div class="h3 fw-bold mb-0 text-success"><?php echo count($pendingSells); ?></div>
            <div class="text-muted" style="font-size:.72rem;">Awaiting Naira settlement</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
            <div class="small text-muted fw-bold">Rate Mode &amp; FX</div>
            <div class="h5 fw-bold mb-0">₦<?php echo number_format((float)$a2pRates['usd_ngn'], 2); ?>/$</div>
            <div class="text-muted" style="font-size:.72rem;"><?php echo htmlspecialchars(strtoupper((string)$a2pRates['source']), ENT_QUOTES, 'UTF-8'); ?> · Buy +<?php echo $a2pCfg['buy_markup_pct']; ?>% / Sell -<?php echo $a2pCfg['sell_markdown_pct']; ?>%</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
            <div class="small text-muted fw-bold">Legacy Withdrawals</div>
            <div class="h3 fw-bold mb-0 text-warning"><?php echo $pendingWithdrawCount; ?></div>
            <div class="text-muted" style="font-size:.72rem;">Pending review</div>
        </div>
    </div>
</div>

<!-- Navigation Tabs -->
<div class="adm-ch-tabs">
    <a href="?tab=desk" class="adm-ch-tab <?php echo $activeAdminTab === 'desk' ? 'is-active' : ''; ?>">
        <i class="bi bi-arrow-left-right"></i> A2P / P2A Desk (<?php echo count($pendingBuys) + count($pendingSells); ?>)
    </a>
    <a href="?tab=rates" class="adm-ch-tab <?php echo $activeAdminTab === 'rates' ? 'is-active' : ''; ?>">
        <i class="bi bi-sliders"></i> Rates, Markup &amp; Deposit Wallets
    </a>
    <a href="?tab=logs" class="adm-ch-tab <?php echo $activeAdminTab === 'logs' ? 'is-active' : ''; ?>">
        <i class="bi bi-journal-check"></i> Withdrawals &amp; Audit Log
    </a>
</div>

<?php if ($activeAdminTab === 'desk'): ?>
<div class="row g-4">
    <!-- Pending A2P Buy Orders -->
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4 mb-2">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 class="fw-bold mb-0">Pending Buy Orders (A2P — User Buys from Admin)</h6>
                    <small class="text-muted">User Naira balance is already reserved. Send the crypto (or approve internal wallet credit), enter your TX hash, and confirm with PIN + 2FA.</small>
                </div>
                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3"><?php echo count($pendingBuys); ?> Pending</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover small mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4 border-0">Order &amp; User</th>
                                <th class="border-0">Send / Credit</th>
                                <th class="border-0">Destination</th>
                                <th class="pe-4 border-0">Fulfill / Reject (PIN + 2FA)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($pendingBuys)): foreach ($pendingBuys as $row):
                                $meta = json_decode((string)$row['metadata'], true) ?: [];
                                $ngnPaid = (float)($meta['ngn_amount'] ?? 0);
                                $modeLabel = (($meta['delivery_mode'] ?? 'external') === 'internal') ? 'Internal CryptoHub Wallet' : 'External Wallet';
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold">@<?php echo htmlspecialchars((string)$row['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="font-monospace text-muted" style="font-size:.7rem;"><?php echo htmlspecialchars((string)$row['reference'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-muted" style="font-size:.68rem;"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-primary"><?php echo rtrim(rtrim(sprintf('%.8f', (float)$row['amount']), '0'), '.'); ?> <?php echo htmlspecialchars((string)$row['currency_code'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-success fw-bold" style="font-size:.74rem;">Paid: ₦<?php echo number_format($ngnPaid, 2); ?></div>
                                    <div class="text-muted" style="font-size:.68rem;">Markup: +<?php echo htmlspecialchars((string)($meta['markup_pct'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>%</div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark mb-1"><?php echo htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <div class="font-monospace text-break" style="max-width: 220px; font-size:.72rem;"><?php echo htmlspecialchars((string)$row['address'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="pe-4">
                                    <form method="post" class="d-flex flex-column gap-1" style="min-width: 230px;">
                                        <?php echo $csrfHtml; ?>
                                        <input type="hidden" name="reference" value="<?php echo htmlspecialchars((string)$row['reference'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="text" name="tx_hash" class="form-control form-control-sm font-monospace" placeholder="Admin payout TX hash (optional for internal)">
                                        <div class="d-flex gap-1">
                                            <input type="password" name="pin" class="form-control form-control-sm" maxlength="4" inputmode="numeric" placeholder="PIN" style="max-width:70px;" required>
                                            <input type="text" name="otp_code" class="form-control form-control-sm" maxlength="6" inputmode="numeric" placeholder="2FA code" style="max-width:95px;" required>
                                            <input type="hidden" name="totp_code" value="">
                                        </div>
                                        <div class="d-flex gap-1 mt-1">
                                            <button type="submit" name="action" value="approve_a2p_buy" class="btn btn-success btn-sm rounded-pill px-3">Mark Fulfilled</button>
                                            <button type="submit" name="action" value="reject_a2p_buy" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="return confirmRejection(this)">Reject &amp; Refund</button>
                                        </div>
                                        <input type="text" name="reason" class="form-control form-control-sm mt-1" placeholder="Rejection reason (required to reject)">
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">No pending A2P Buy orders.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending P2A Sell Orders -->
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 class="fw-bold mb-0">Pending Sell Orders (P2A — User Sells to Admin)</h6>
                    <small class="text-muted">Verify the incoming crypto transfer (or internal debit), then approve to credit the user's Naira wallet.</small>
                </div>
                <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3"><?php echo count($pendingSells); ?> Pending</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover small mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4 border-0">Order &amp; User</th>
                                <th class="border-0">Crypto &amp; Payout</th>
                                <th class="border-0">Proof / Source</th>
                                <th class="pe-4 border-0">Verify &amp; Credit (PIN + 2FA)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($pendingSells)): foreach ($pendingSells as $row):
                                $meta = json_decode((string)$row['metadata'], true) ?: [];
                                $ngnDue = (float)($meta['ngn_amount'] ?? 0);
                                $sellSrc = (($meta['sell_source'] ?? 'external') === 'internal') ? 'Internal Wallet (Already Debited)' : 'External Transfer';
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold">@<?php echo htmlspecialchars((string)$row['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="font-monospace text-muted" style="font-size:.7rem;"><?php echo htmlspecialchars((string)$row['reference'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-muted" style="font-size:.68rem;"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo rtrim(rtrim(sprintf('%.8f', (float)$row['amount']), '0'), '.'); ?> <?php echo htmlspecialchars((string)$row['currency_code'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-success fw-bold" style="font-size:.76rem;">Credit: ₦<?php echo number_format($ngnDue, 2); ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark mb-1"><?php echo htmlspecialchars($sellSrc, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if (!empty($row['blockchain_txid'])): ?>
                                    <div class="font-monospace text-primary text-break" style="max-width:220px; font-size:.72rem;">TX: <?php echo htmlspecialchars((string)$row['blockchain_txid'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($meta['sender_note'])): ?>
                                    <div class="text-muted" style="font-size:.7rem;">Note: <?php echo htmlspecialchars((string)$meta['sender_note'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4">
                                    <form method="post" class="d-flex flex-column gap-1" style="min-width: 230px;">
                                        <?php echo $csrfHtml; ?>
                                        <input type="hidden" name="reference" value="<?php echo htmlspecialchars((string)$row['reference'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="text" name="note" class="form-control form-control-sm" placeholder="Verification note (optional)">
                                        <div class="d-flex gap-1">
                                            <input type="password" name="pin" class="form-control form-control-sm" maxlength="4" inputmode="numeric" placeholder="PIN" style="max-width:70px;" required>
                                            <input type="text" name="otp_code" class="form-control form-control-sm" maxlength="6" inputmode="numeric" placeholder="2FA code" style="max-width:95px;" required>
                                            <input type="hidden" name="totp_code" value="">
                                        </div>
                                        <div class="d-flex gap-1 mt-1">
                                            <button type="submit" name="action" value="approve_p2a_sell" class="btn btn-success btn-sm rounded-pill px-3">Credit ₦<?php echo number_format($ngnDue, 0); ?></button>
                                            <button type="submit" name="action" value="reject_p2a_sell" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="return confirmRejection(this)">Reject</button>
                                        </div>
                                        <input type="text" name="reason" class="form-control form-control-sm mt-1" placeholder="Rejection reason (required to reject)">
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">No pending P2A Sell orders.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($activeAdminTab === 'rates'): ?>
<div class="card shadow-sm border-0 rounded-4 p-3 p-md-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h6 class="fw-bold mb-0">Rates, Admin Markup Fees &amp; Deposit Wallet Addresses</h6>
            <small class="text-muted">Use live public market rates + your markup %, or lock fixed manual rates. Set your receiving wallet address for each coin users can sell.</small>
        </div>
        <form method="post" class="m-0">
            <?php echo $csrfHtml; ?>
            <button type="submit" name="refresh_live_rates" value="1" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                <i class="bi bi-arrow-repeat me-1"></i> Refresh Live Rates
            </button>
        </form>
    </div>

    <form method="post">
        <?php echo $csrfHtml; ?>
        <input type="hidden" name="save_a2p_config" value="1">
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <label class="form-label small fw-bold">Rate Source Mode</label>
                <select name="rate_mode" class="form-select">
                    <option value="live" <?php echo $a2pCfg['rate_mode'] === 'live' ? 'selected' : ''; ?>>Live Market + Admin Markup (%)</option>
                    <option value="manual" <?php echo $a2pCfg['rate_mode'] === 'manual' ? 'selected' : ''; ?>>Manual Fixed Rates</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Manual / Fallback USD → NGN (₦)</label>
                <input type="number" step="0.01" min="1" name="usd_ngn_rate" class="form-control" value="<?php echo htmlspecialchars((string)$a2pCfg['usd_ngn_rate'], ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Buy Markup (%)</label>
                <input type="number" step="0.01" min="0" max="50" name="buy_markup_pct" class="form-control" value="<?php echo htmlspecialchars((string)$a2pCfg['buy_markup_pct'], ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Sell Spread (%)</label>
                <input type="number" step="0.01" min="0" max="50" name="sell_markdown_pct" class="form-control" value="<?php echo htmlspecialchars((string)$a2pCfg['sell_markdown_pct'], ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Swap Fee (%)</label>
                <input type="number" step="0.01" min="0" max="25" name="swap_fee_pct" class="form-control" value="<?php echo htmlspecialchars((string)$a2pCfg['swap_fee_pct'], ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Min Order (NGN)</label>
                <input type="number" step="100" min="100" name="min_trade_ngn" class="form-control" value="<?php echo htmlspecialchars((string)$a2pCfg['min_trade_ngn'], ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Max Order (NGN)</label>
                <input type="number" step="100" min="1000" name="max_trade_ngn" class="form-control" value="<?php echo htmlspecialchars((string)$a2pCfg['max_trade_ngn'], ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">Desk Notice Shown to Users</label>
                <input type="text" name="desk_notice" class="form-control" maxlength="500" value="<?php echo htmlspecialchars((string)$a2pCfg['desk_notice'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="table-responsive mb-3">
            <table class="table table-bordered align-middle small">
                <thead class="bg-light">
                    <tr>
                        <th>Coin / Network</th>
                        <th>Permissions</th>
                        <th>Fallback USD ($)</th>
                        <th>Effective NGN (Buy / Sell)</th>
                        <th>Admin Deposit Wallet Address (P2A Sell)</th>
                        <th>Network Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($catalogue as $code => $meta):
                        $ac = $a2pCfg['assets'][$code] ?? [];
                        $aq = $a2pQuotes[$code] ?? [];
                    ?>
                    <tr>
                        <td>
                            <div class="fw-bold"><?php echo htmlspecialchars($meta['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td>
                            <label class="d-block"><input type="checkbox" name="assets[<?php echo $code; ?>][enabled]" value="1" <?php echo !empty($ac['enabled']) ? 'checked' : ''; ?>> Enabled</label>
                            <label class="d-block"><input type="checkbox" name="assets[<?php echo $code; ?>][buy_enabled]" value="1" <?php echo !empty($ac['buy_enabled']) ? 'checked' : ''; ?>> Buy</label>
                            <label class="d-block"><input type="checkbox" name="assets[<?php echo $code; ?>][sell_enabled]" value="1" <?php echo !empty($ac['sell_enabled']) ? 'checked' : ''; ?>> Sell</label>
                            <label class="d-block"><input type="checkbox" name="assets[<?php echo $code; ?>][swap_enabled]" value="1" <?php echo !empty($ac['swap_enabled']) ? 'checked' : ''; ?>> Swap</label>
                        </td>
                        <td style="min-width: 115px;">
                            <input type="number" step="any" min="0.0001" name="assets[<?php echo $code; ?>][manual_usd_price]" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string)($ac['manual_usd_price'] ?? $meta['default_usd']), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="text-muted mt-1" style="font-size:.68rem;">Active: $<?php echo number_format((float)($aq['usd_price'] ?? 0), 4); ?></div>
                        </td>
                        <td style="min-width: 160px;">
                            <div>Buy: <strong class="text-primary">₦<?php echo number_format((float)($aq['buy_rate_ngn'] ?? 0), 2); ?></strong></div>
                            <div>Sell: <strong class="text-success">₦<?php echo number_format((float)($aq['sell_rate_ngn'] ?? 0), 2); ?></strong></div>
                        </td>
                        <td style="min-width: 230px;">
                            <input type="text" name="assets[<?php echo $code; ?>][deposit_address]" class="form-control form-control-sm font-monospace" placeholder="Admin receiving wallet address" value="<?php echo htmlspecialchars((string)($ac['deposit_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td style="min-width: 170px;">
                            <input type="text" name="assets[<?php echo $code; ?>][network_note]" class="form-control form-control-sm" placeholder="e.g. TRC-20 only" value="<?php echo htmlspecialchars((string)($ac['network_note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
            <i class="bi bi-save me-1"></i> Save Desk Settings
        </button>
    </form>
</div>

<?php else: ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 rounded-4 p-4 text-center mb-4">
            <div class="bg-primary bg-opacity-10 text-dark-primary rounded-circle p-3 d-inline-block mb-3">
                <i class="bi bi-shield-lock-fill fs-3"></i>
            </div>
            <h5 class="fw-bold">Security &amp; Gateway Settings</h5>
            <p class="small text-muted">Manage 2FA approval settings, gateway keys, and historical invoices.</p>
            <div class="d-grid gap-2">
                <a href="AccountSettings.php" class="btn btn-primary rounded-pill">Account Settings</a>
                <a href="PaymentGateway.php" class="btn btn-outline-primary rounded-pill">Gateway Configuration</a>
                <a href="CryptoInvoices.php" class="btn btn-info rounded-pill">Invoice History</a>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="fw-bold mb-0">Pending Crypto Withdrawals (Legacy / NGN Payouts)</h6>
                <span class="badge bg-warning bg-opacity-10 text-warning px-3 rounded-pill">Requires Action</span>
            </div>
            <p class="small text-muted px-4 pt-2 mb-0">Approvals need your Security PIN + authenticator/email code.</p>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover small mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4 border-0">User</th>
                                <th class="border-0">Amount</th>
                                <th class="border-0">Destination</th>
                                <th class="pe-4 border-0">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $has_pending = false;
                            if ($withdrawals):
                                mysqli_data_seek($withdrawals, 0);
                                while ($row = mysqli_fetch_assoc($withdrawals)):
                                    if ($row['status'] != 'pending') continue;
                                    $has_pending = true;
                                    $is_ngn = ((float)$row['ngn_amount'] > 0);
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold">@<?php echo htmlspecialchars((string)$row['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-muted" style="font-size:0.7rem;"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars((string)$row['crypto_amount'], ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars((string)$row['currency_code'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if ($is_ngn) echo "<div class='text-success fw-bold' style='font-size:0.75rem;'>₦" . number_format($row['ngn_amount'], 2) . "</div>"; ?>
                                </td>
                                <td>
                                    <div class="text-truncate" style="max-width: 150px;">
                                        <code class="text-primary" title="<?php echo htmlspecialchars((string)$row['address'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$row['address'], ENT_QUOTES, 'UTF-8'); ?></code>
                                    </div>
                                    <?php if ($is_ngn) echo "<span class='badge bg-info bg-opacity-10 text-info' style='font-size:0.6rem;'>Bank Payout</span>"; ?>
                                </td>
                                <td class="pe-4">
                                    <form method="post" class="d-flex flex-column gap-1">
                                        <?php echo $csrfHtml; ?>
                                        <input type="hidden" name="reference" value="<?php echo htmlspecialchars((string)$row['reference'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="d-flex gap-1">
                                            <input type="password" name="pin" class="form-control form-control-sm" maxlength="4" inputmode="numeric" placeholder="PIN" style="font-size:0.65rem; max-width:70px;">
                                            <input type="text" name="otp_code" class="form-control form-control-sm" maxlength="6" inputmode="numeric" placeholder="2FA code" style="font-size:0.65rem; max-width:90px;">
                                            <input type="hidden" name="totp_code" value="">
                                        </div>
                                        <div class="d-flex gap-1">
                                            <button type="submit" name="action" value="approve_withdrawal" class="btn btn-success btn-sm rounded-pill px-3" style="font-size:0.7rem;">Approve</button>
                                            <button type="submit" name="action" value="reject_withdrawal" class="btn btn-outline-danger btn-sm rounded-pill px-3" style="font-size:0.7rem;" onclick="return confirmRejection(this)">Reject</button>
                                        </div>
                                        <input type="text" name="reason" class="form-control form-control-sm mt-1" placeholder="Reason for rejection" style="font-size:0.65rem;">
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; endif; ?>
                            <?php if (!$has_pending): ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
                                No pending withdrawal requests
                            </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0">Audit Log: CryptoHub Activity</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover small mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4 border-0">User / Ref</th>
                                <th class="border-0">Operation</th>
                                <th class="border-0">Asset &amp; Amount</th>
                                <th class="pe-4 border-0">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions):
                                mysqli_data_seek($transactions, 0);
                                while ($row = mysqli_fetch_assoc($transactions)):
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold">@<?php echo htmlspecialchars((string)$row['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-muted font-monospace" style="font-size:0.68rem;"><?php echo htmlspecialchars((string)$row['reference'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="text-uppercase fw-bold text-muted" style="font-size:0.7rem;"><?php echo htmlspecialchars(str_replace('_', ' ', (string)$row['type']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="fw-bold"><?php echo (float)$row['amount']; ?> <?php echo htmlspecialchars((string)$row['currency_code'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td class="pe-4">
                                    <?php
                                        $s = (string)$row['status'];
                                        $c = ($s == '1') ? 'success' : (($s == '2') ? 'warning' : 'danger');
                                        $txt = ($s == '1') ? 'Success' : (($s == '2') ? 'Pending' : 'Rejected');
                                        echo "<span class='badge bg-$c bg-opacity-10 text-$c rounded-pill' style='font-size:0.7rem;'>$txt</span>";
                                    ?>
                                </td>
                            </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
</section>

<script>
function confirmRejection(btn) {
    const form = btn.closest('form');
    const reason = (form?.querySelector('input[name="reason"]')?.value || '').trim();
    if (!reason) {
        if (window.Swal) Swal.fire('Required', 'Please provide a reason for rejection.', 'warning');
        else alert('Please provide a reason for rejection.');
        return false;
    }
    return confirm('Are you sure you want to reject this order?');
}
</script>

<?php bc_adm_foot(array("area" => "admin")); ?>
