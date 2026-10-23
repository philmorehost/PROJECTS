<?php session_start();
include("../func/bc-config.php");
include("../func/bc-crypto-func.php");

if (!isset($_SESSION["user_session"]) || !isset($get_logged_user_details)) {
    header("Location: Login.php");
    exit();
}

$vid = $get_logged_user_details['vendor_id'];
$username = $get_logged_user_details['username'];

if(!isServiceEnabled('crypto_hub')){
    header("Location: Dashboard.php");
    exit();
}

// Handle AJAX Rate Fetching
if (isset($_GET['get_rate'])) {
    $from = strtoupper($_GET['from']);
    $to = strtoupper($_GET['to']);
    $rate = getPlisioExchangeRate($from, $to, $vid);
    echo json_encode(['rate' => $rate]);
    exit();
}

// Handle Deposit/Invoice Creation
if (isset($_POST['action']) && $_POST['action'] == 'create_invoice') {
    $currency = mysqli_real_escape_string($connection_server, $_POST['currency']);
    $amount = (float)($_POST['amount'] ?? 0);

    if ($amount <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Minimum deposit amount is 1.00']);
        exit();
    }

    $params = [
        'currency' => $currency,
        'order_number' => 'DEP_' . time() . '_' . rand(10, 99),
        'order_name' => 'Wallet Deposit - ' . $username,
        'callback_url' => $web_http_host . '/users-plisio.php',
        'email' => $get_logged_user_details['email'],
        'amount' => $amount
    ];

    $res = createPlisioInvoice($params, $vid);
    if (($res['status'] ?? '') == 'success') {
        $tx_data = $res['data'];
        $internal_ref = $params['order_number'];
        $actual_amount = $tx_data['invoice_total_sum'] ?? $amount;
        $logged_tx_id = logCryptoTransaction($vid, $username, 'deposit', $currency, $actual_amount, 2, $tx_data['txn_id'], $tx_data['invoice_url'], $tx_data['wallet_hash'] ?? '', ['order_number' => $internal_ref, 'total_sum' => $actual_amount], $internal_ref, $tx_data['view_key'] ?? '');

        if ($logged_tx_id) {
            $tx_data['local_invoice_url'] = 'ViewCryptoInvoice.php?ref=' . $internal_ref;
            echo json_encode(['status' => 'success', 'data' => $tx_data]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to log transaction internally.']);
        }
    } else {
        $err_msg = $res['data']['message'] ?? $res['message'] ?? 'Plisio Error';
        echo json_encode(['status' => 'error', 'message' => $err_msg]);
    }
    exit();
}

// Handle Swap
if (isset($_POST['action']) && $_POST['action'] == 'swap') {
    $from = strtoupper(mysqli_real_escape_string($connection_server, $_POST['from']));
    $to = strtoupper(mysqli_real_escape_string($connection_server, $_POST['to']));
    $amount = (float)$_POST['amount'];

    if ($from == $to) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot swap same currency']);
        exit();
    }

    $wallets = getUserCryptoWallets($vid, $username);
    if (($wallets[$from]['balance'] ?? 0) < $amount) {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient Balance']);
        exit();
    }

    $rate = getPlisioExchangeRate($from, $to, $vid);
    if ($rate <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Exchange Rate']);
        exit();
    }

    $gross_target_amount = $amount * $rate;
    $fee_amount = 0;
    $net_target_amount = $gross_target_amount;

    if ($to == 'NGN') {
        $select_v = mysqli_query($connection_server, "SELECT crypto_swap_fee FROM sas_vendors WHERE id='$vid' LIMIT 1");
        $rv = mysqli_fetch_assoc($select_v);
        $swap_fee_percent = (float)($rv['crypto_swap_fee'] ?? 0);

        if ($swap_fee_percent > 0) {
            $fee_amount = ($swap_fee_percent / 100) * $gross_target_amount;
            $net_target_amount = $gross_target_amount - $fee_amount;
        }
    }

    // Internal ledger swap
    if (updateUserCryptoBalance($vid, $username, $from, $amount, 'debit')) {
        if ($to == 'NGN') {
            // Credit main wallet
            chargeOtherUser($username, "credit", "crypto_swap", "Crypto Swap", "SWP_" . time(), "INTERNAL", $gross_target_amount, $net_target_amount, "Swap $amount $from to Naira (Fee: ₦" . number_format($fee_amount, 2) . ")", "WEB", $_SERVER['HTTP_HOST'], 1);

            logCryptoTransaction($vid, $username, 'swap_out', $from, $amount, 1, 'INTERNAL_SWAP', '', '', ['to' => $to, 'rate' => $rate, 'fee' => $fee_amount]);

            $success_msg = 'Swap Successful. ₦' . number_format($net_target_amount, 2) . ' credited to your wallet after ₦' . number_format($fee_amount, 2) . ' fee.';
            echo json_encode(['status' => 'success', 'message' => $success_msg]);
        } else {
            // Live Crypto-to-Crypto Swap via Plisio Withdrawal (Auto-Conversion on Provider Side)
            // Plisio supports cross-currency withdrawals (e.g. BTC to ETH) using the psys_cid parameter for source and the destination address for currency
            // Note: This requires the provider balance to be sufficient.

            // To ensure 100% API connectivity, we attempt to process this via Plisio withdrawal if a wallet address exists
            $to_wallet = $wallets[$to]['address'] ?? '';
            if (!empty($to_wallet)) {
                // Determine precision from currencies list
                $cur_from = null; foreach($currencies as $c) if($c['cid'] == $from) { $cur_from = $c; break; }
                $precision = (int)($cur_from['precision'] ?? 8);
                $formatted_amount = number_format($amount, $precision, '.', '');

                $res = plisioCashOut([
                    'psys_cid' => $from,
                    'amount' => $formatted_amount,
                    'to' => $to_wallet,
                    'type' => 'cash_out'
                ], $vid);

                if (($res['status'] ?? '') == 'success') {
                    // Update internal ledger for 'to' currency once API confirms (or assume success for UX)
                    updateUserCryptoBalance($vid, $username, $to, $net_target_amount, 'credit');
                    logCryptoTransaction($vid, $username, 'swap_out', $from, $amount, 1, $res['data']['txn_id'], '', $to_wallet, ['to' => $to, 'rate' => $rate]);
                    logCryptoTransaction($vid, $username, 'swap_in', $to, $net_target_amount, 1, $res['data']['txn_id'], '', $to_wallet, ['from' => $from, 'rate' => $rate]);
                    echo json_encode(['status' => 'success', 'message' => "Live Swap of $amount $from to $to initiated via API."]);
                } else {
                    // Rollback 'from' balance if API fails
                    updateUserCryptoBalance($vid, $username, $from, $amount, 'credit');
                    echo json_encode(['status' => 'error', 'message' => 'API Swap Failed: ' . ($res['message'] ?? 'Provider Error')]);
                }
            } else {
                // Fallback to internal swap if user hasn't generated a wallet for the target currency yet
                updateUserCryptoBalance($vid, $username, $to, $net_target_amount, 'credit');
                logCryptoTransaction($vid, $username, 'swap_out', $from, $amount, 1, 'INTERNAL_SWAP', '', '', ['to' => $to, 'rate' => $rate]);
                logCryptoTransaction($vid, $username, 'swap_in', $to, $net_target_amount, 1, 'INTERNAL_SWAP', '', '', ['from' => $from, 'rate' => $rate]);
                echo json_encode(['status' => 'success', 'message' => "Swap of $amount $from to $to completed internally."]);
            }
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Internal Swap Error']);
    }
    exit();
}

// Handle OTP Sending
if (isset($_POST['action']) && $_POST['action'] == 'send_otp') {
    $otp = generateOTP();
    $_SESSION["crypto_withdrawal_otp"] = $otp;
    $_SESSION["crypto_withdrawal_otp_time"] = time();

    $subject = "Crypto Withdrawal OTP";
    $body = "Your verification code for crypto withdrawal is: <b>$otp</b>. This code expires in 10 minutes.";
    sendVendorEmail($get_logged_user_details["email"], $subject, $body);

    echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email']);
    exit();
}


// Handle Manual Sync (Self-Help)
if (isset($_POST['action']) && $_POST['action'] == 'manual_sync') {
    $ref = mysqli_real_escape_string($connection_server, $_POST['reference']);

    // Search by Reference or Blockchain TXID
    $q = mysqli_query($connection_server, "SELECT * FROM `sas_crypto_transactions` WHERE (`reference`='$ref' OR `blockchain_txid`='$ref') AND `vendor_id`='$vid' AND `username`='$username' LIMIT 1");
    $tx = mysqli_fetch_assoc($q);

    if (!$tx) {
        echo json_encode(['status' => 'error', 'message' => 'Transaction reference or Blockchain TXID not found in your history.']);
        exit();
    }

    if ($tx['status'] == 1) {
        echo json_encode(['status' => 'success', 'message' => 'This transaction is already completed and credited.']);
        exit();
    }

    // Try to sync with Plisio
    $res = getPlisioTransactionDetails($tx['plisio_tx_id'], $vid);
    if (($res['status'] ?? '') == 'success') {
        $data = $res['data'];
        $new_st = $data['status'] ?? '';
        $status_code = (int)($data['status_code'] ?? 0);

        if ($new_st == 'completed' || $new_st == 'mismatch' || $status_code === 31) {
            $amount = $data['amount'] ?? $tx['amount'];
            $blockchain_txid = $data['tx_id'] ?? '';
            if (is_array($blockchain_txid)) $blockchain_txid = implode(',', $blockchain_txid);
            $blockchain_txid_esc = mysqli_real_escape_string($connection_server, $blockchain_txid);

            if (updateUserCryptoBalance($vid, $username, $tx['currency_code'], $amount, 'credit')) {
                mysqli_query($connection_server, "UPDATE `sas_crypto_transactions` SET `status`='1', `amount`='$amount', `blockchain_txid`='$blockchain_txid_esc' WHERE `id`='".$tx['id']."'");
                echo json_encode(['status' => 'success', 'message' => 'Success! Payment detected and wallet credited.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Payment detected but failed to update wallet. Please contact support.']);
            }
        } else {
            echo json_encode(['status' => 'info', 'message' => "Plisio reports status: $new_st. If you just paid, please wait for network confirmations."]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Could not verify status with provider: ' . ($res['message'] ?? 'Unknown Error')]);
    }
    exit();
}

// Handle Withdrawal (External Crypto)
if (isset($_POST['action']) && $_POST['action'] == 'withdraw') {
    $currency = mysqli_real_escape_string($connection_server, $_POST['currency']);
    $amount = (float)$_POST['amount'];
    $address = mysqli_real_escape_string($connection_server, $_POST['address']);
    $pin = $_POST['pin'];
    $otp = $_POST['otp'] ?? '';

    // Verify PIN
    if (!verifyUserPIN($pin, $get_logged_user_details)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Transaction PIN']);
        exit();
    }

    // Verify OTP
    if (empty($_SESSION["crypto_withdrawal_otp"]) || $otp !== $_SESSION["crypto_withdrawal_otp"]) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Email OTP']);
        exit();
    }
    if (time() - $_SESSION["crypto_withdrawal_otp_time"] > 600) {
        echo json_encode(['status' => 'error', 'message' => 'OTP has expired']);
        exit();
    }

    $wallets = getUserCryptoWallets($vid, $username);
    if (($wallets[$currency]['balance'] ?? 0) < $amount) {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient Balance']);
        exit();
    }

    $v_q = mysqli_query($connection_server, "SELECT approve_withdrawal FROM sas_vendors WHERE id='$vid' LIMIT 1");
    $v_r = mysqli_fetch_assoc($v_q);
    $needs_approval = ($v_r['approve_withdrawal'] == 1);

    if ($needs_approval) {
        updateUserCryptoBalance($vid, $username, $currency, $amount, 'debit');
        $w_ref = 'CW_' . time() . '_' . rand(10, 99);
        mysqli_query($connection_server, "INSERT INTO sas_crypto_withdrawals (vendor_id, username, reference, currency_code, crypto_amount, ngn_amount, address, status) VALUES ('$vid', '$username', '$w_ref', '$currency', '$amount', 0, '$address', 'pending')");
        echo json_encode(['status' => 'success', 'message' => 'Withdrawal request submitted for approval']);
    } else {
        // Direct cash out via Plisio
        $res = plisioCashOut([
            'psys_cid' => $currency,
            'amount' => $amount,
            'address' => $address,
            'type' => 'cash_out'
        ], $vid);

        if (($res['status'] ?? '') == 'success') {
            updateUserCryptoBalance($vid, $username, $currency, $amount, 'debit');
            logCryptoTransaction($vid, $username, 'withdrawal', $currency, $amount, 1, $res['data']['txn_id'], '', $address);
            echo json_encode(['status' => 'success', 'message' => 'Withdrawal processed successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $res['message'] ?? 'Plisio Withdrawal Failed']);
        }
    }
    exit();
}

$currencies = getPlisioCurrencies($vid);
$userWallets = getUserCryptoWallets($vid, $username);
$supported = ['BTC', 'ETH', 'LTC', 'TRX', 'USDT_TRX', 'USDC', 'BCH', 'DOGE'];

// Filter supported currencies to only those returned by Plisio (ensure they are active)
$active_supported = [];
foreach($supported as $s) {
    foreach($currencies as $c) {
        if($c['cid'] == $s) {
            $active_supported[] = $s;
            break;
        }
    }
}
if (empty($active_supported)) $active_supported = $supported; // Fallback
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Crypto | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; color: #1e293b; }
        .card { border-radius: 1.25rem; border: none; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); }
        .balance-hero {
            background: linear-gradient(135deg, <?php echo $vendor_primary_color; ?> 0%, <?php echo $vendor_primary_color; ?>cc 100%);
            border-radius: 1.25rem;
            color: white;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .balance-hero::after {
            content: '';
            position: absolute;
            top: -20%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            pointer-events: none;
        }
        .balance-amount { font-size: 2.5rem; font-weight: 800; letter-spacing: -1px; }
        .quick-action-btn {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.25);
            color: white;
            padding: 0.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.75rem;
            backdrop-filter: blur(10px);
            transition: all 0.2s;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 60px;
            position: relative;
            z-index: 10;
        }
        .quick-action-btn i { font-size: 1.25rem; margin-bottom: 2px; }
        .quick-action-btn:hover { background: rgba(255,255,255,0.25); color: white; transform: translateY(-2px); }

        .coin-icons-row img { width: 32px; height: 32px; margin-right: 8px; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.15)); transition: transform 0.2s; }
        .coin-icons-row img:hover { transform: scale(1.15); }

        @media (max-width: 767px) {
            .balance-amount { font-size: 1.75rem; }
            .balance-hero { padding: 1.25rem; }
            .quick-action-btn { font-size: 0.65rem; padding: 0.4rem; }
        }
    </style>
</head>
<body class="bg-light">
    <?php include("../func/bc-header.php"); ?>

    <div class="pagetitle">
      <h1>CRYPTO</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Crypto</li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">

        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
            <div class="small">
                <strong>Safety Tip:</strong> Please always keep your <b>Transaction Reference</b> number. You will need it to manually sync your payment if it doesn't reflect within 5-10 minutes.
            </div>
        </div>

        <!-- Balance Hero -->
        <div class="balance-hero shadow">
            <div class="row align-items-center">
                <div class="col-md-7 mb-3 mb-md-0" style="position: relative; z-index: 10;">
                    <div class="small fw-semibold opacity-75 mb-1">AVAILABLE BALANCE</div>
                    <div class="balance-amount mb-2 d-flex align-items-center">
                        ₦<?php echo toDecimal($get_logged_user_details["balance"], "2"); ?>
                        <a href="CoinConversion.php" class="ms-3 d-flex align-items-center justify-content-center text-decoration-none" title="Get Bonus" style="width: 42px; height: 42px; background: rgba(255, 215, 0, 0.15); color: #FFD700; border-radius: 50%; border: 1px solid rgba(255, 215, 0, 0.3); transition: all 0.3s ease; backdrop-filter: blur(5px);">
                            <i class="bi bi-gift-fill" style="font-size: 1.25rem;"></i>
                        </a>
                    </div>

                    <div class="coin-icons-row mt-3 mb-2">
                        <img src="../asset/ngn-icon.png" alt="NGN" title="Naira">
                        <img src="../asset/crypto/btc.png" alt="BTC" title="Bitcoin">
                        <img src="../asset/crypto/eth.png" alt="ETH" title="Ethereum">
                        <img src="../asset/crypto/ltc.png" alt="LTC" title="Litecoin">
                        <img src="../asset/crypto/trx.png" alt="TRX" title="Tron">
                        <img src="../asset/crypto/usdt-trx.png" alt="USDT" title="Tether TRC-20">
                        <img src="../asset/crypto/usdc.png" alt="USDC" title="USD Coin">
                        <img src="../asset/crypto/bch.png" alt="BCH" title="Bitcoin Cash">
                        <img src="../asset/crypto/doge.png" alt="DOGE" title="Dogecoin">
                    </div>

                    <div class="row row-cols-5 g-2 mt-4" style="position: relative; z-index: 20;">
                        <div class="col"><a href="javascript:void(0)" onclick="openGeneralDepositModal()" class="quick-action-btn"><i class="bi bi-plus-lg"></i>Deposit</a></div>
                        <div class="col"><a href="javascript:void(0)" onclick="openGeneralWithdrawModal()" class="quick-action-btn"><i class="bi bi-send"></i>Send</a></div>
                        <div class="col"><a href="javascript:void(0)" onclick="openSwapModal()" class="quick-action-btn"><i class="bi bi-arrow-left-right"></i>Swap</a></div>
                        <div class="col"><a href="javascript:void(0)" onclick="openSwapModal('NGN')" class="quick-action-btn"><i class="bi bi-bank"></i>To NGN</a></div>
                        <div class="col"><a href="javascript:void(0)" onclick="openSelfHelpModal()" class="quick-action-btn"><i class="bi bi-patch-question"></i>Self Help</a></div>
                    </div>
                </div>
                <div class="col-md-5 text-md-end">
                    <div class="bg-white bg-opacity-10 p-3 rounded-4 backdrop-blur border border-white border-opacity-10 d-inline-block text-start shadow-sm" style="min-width: 220px;">
                        <div class="d-flex justify-content-between mb-2 gap-3">
                            <span class="small opacity-75">Account Type</span>
                            <span class="fw-bold small"><?php echo accountLevel($get_logged_user_details["account_level"]); ?></span>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="small opacity-75">Username</span>
                            <span class="fw-bold small">@<?php echo $get_logged_user_details["username"]; ?></span>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="VirtualBanks.php" class="btn btn-light btn-sm rounded-pill px-4 fw-bold shadow-sm py-2">
                            <i class="bi bi-bank me-2 text-primary"></i> Account Details
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Wallet Balances Section -->
        <div class="row g-3 mb-4">
            <div class="col-12"><h6 class="fw-bold mb-0">My Wallets</h6></div>
            <?php
            $display_coins = ['BTC', 'ETH', 'LTC', 'USDT_TRX', 'TRX', 'USDC', 'BCH', 'DOGE'];
            foreach($display_coins as $cid):
                $bal = $userWallets[$cid]['balance'] ?? 0;
                $label = ($cid == 'USDT_TRX') ? 'USDT' : $cid;
                $icon = strtolower(str_replace('_', '-', $cid)) . ".png";
                // Only show if asset is in active Plisio list or has balance
                $is_active = false; foreach($currencies as $c) if($c['cid'] == $cid) { $is_active = true; break; }
                if(!$is_active && $bal <= 0) continue;
            ?>
            <div class="col-lg-3 col-md-4 col-6">
                <div class="card shadow-sm border-0 h-100 position-relative overflow-hidden">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="bg-light rounded-circle p-2 d-flex align-items-center justify-content-center" style="width:40px; height:40px;">
                                <img src="../asset/crypto/<?php echo $icon; ?>" style="width:24px; height:24px;">
                            </div>
                            <span class="badge bg-light text-dark rounded-pill" style="font-size:0.65rem;"><?php echo $cid; ?></span>
                        </div>
                        <div class="text-muted small fw-bold mb-1"><?php echo $label; ?></div>
                        <div class="h5 fw-bold mb-0"><?php echo (float)$bal; ?></div>
                    </div>
                    <div class="position-absolute bottom-0 end-0 p-1 opacity-10">
                        <i class="bi bi-wallet2 fs-1"></i>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if(isKYCEnforced() && $get_logged_user_details['kyc_status'] != 2): ?>
        <div class="alert alert-danger border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center justify-content-between p-4">
            <div class="d-flex align-items-center">
                <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3 me-3">
                    <i class="bi bi-shield-lock-fill fs-3"></i>
                </div>
                <div>
                    <h6 class="fw-bold mb-1">KYC Verification Required</h6>
                    <p class="small mb-0 text-muted">Crypto features are restricted until your identity is verified.</p>
                </div>
            </div>
            <a href="KYCVerification.php" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">Complete KYC</a>
        </div>
        <style>.crypto-card button, .col-lg-4 button { pointer-events: none; opacity: 0.6; }</style>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Main Content Area -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0">Latest Invoices</h6>
                        <a href="CryptoInvoices.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">View History</a>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <?php
                            $res = mysqli_query($connection_server, "SELECT * FROM `sas_crypto_transactions` WHERE `vendor_id`='$vid' AND `username`='$username' ORDER BY `id` DESC LIMIT 3");
                            if($res && mysqli_num_rows($res) > 0):
                            while($row = mysqli_fetch_assoc($res)):
                                $s = $row['status'];
                                $c = ($s == 1) ? 'success' : (($s == 2) ? 'warning' : 'danger');
                                $txt = ($s == 1) ? 'Success' : (($s == 2) ? 'Pending' : 'Expired');
                            ?>
                            <div class="col-md-4">
                                <div class="card border bg-light shadow-none h-100 mb-0">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between mb-3">
                                            <span class="badge bg-<?php echo $c; ?> bg-opacity-10 text-<?php echo $c; ?>"><?php echo $txt; ?></span>
                                            <small class="text-muted" style="font-size:0.65rem;"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></small>
                                        </div>
                                        <div class="h6 fw-bold mb-1"><?php echo (float)$row['amount']; ?> <?php echo $row['currency_code']; ?></div>
                                        <div class="text-muted small text-truncate mb-3" style="font-size:0.7rem;"><?php echo $row['reference']; ?></div>
                                        <?php if($s == 2): ?>
                                            <a href="ViewCryptoInvoice.php?ref=<?php echo $row['reference']; ?>" class="btn btn-primary btn-sm w-100 rounded-pill">Pay Now</a>
                                        <?php else: ?>
                                            <a href="ViewCryptoInvoice.php?ref=<?php echo $row['reference']; ?>" class="btn btn-outline-secondary btn-sm w-100 rounded-pill">Details</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; else: ?>
                            <div class="col-12 text-center py-4 text-muted">No invoices generated yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4 mt-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0">Other Activity</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover small mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-4">Type</th>
                                        <th>Asset</th>
                                        <th>Amount</th>
                                        <th class="pe-4">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Exclude the 3 deposits shown above to show other types or older deposits
                                    $res_other = mysqli_query($connection_server, "SELECT * FROM `sas_crypto_transactions` WHERE `vendor_id`='$vid' AND `username`='$username' ORDER BY `id` DESC LIMIT 3, 7");
                                    if($res_other && mysqli_num_rows($res_other) > 0):
                                    while($row = mysqli_fetch_assoc($res_other)):
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="text-uppercase small fw-bold"><?php echo str_replace('_', ' ', $row['type']); ?></span>
                                            <div class="text-muted" style="font-size:0.7rem;"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></div>
                                        </td>
                                        <td><?php echo $row['currency_code']; ?></td>
                                        <td class="fw-bold"><?php echo (float)$row['amount']; ?></td>
                                        <td class="pe-4">
                                            <?php
                                                $s = $row['status'];
                                                $c = ($s == 1) ? 'success' : (($s == 2) ? 'warning' : 'danger');
                                                $txt = ($s == 1) ? 'Paid' : (($s == 2) ? 'Pending' : 'Failed');
                                                echo "<span class='badge bg-$c bg-opacity-10 text-$c rounded-pill'>$txt</span>";
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">No other transactions</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions Sidebar -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-body p-4 text-center">
                        <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 60px; height: 60px;">
                            <i class="bi bi-arrow-left-right fs-3"></i>
                        </div>
                        <h5 class="fw-bold">Instant Swap</h5>
                        <p class="small text-muted">Exchange your crypto instantly at current market rates.</p>
                        <button class="btn btn-success w-100 rounded-pill py-2" onclick="openSwapModal()">Start Swap</button>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4 mb-4 border-primary border-opacity-10">
                    <div class="card-body p-4 text-center">
                        <div class="bg-primary bg-opacity-10 text-dark-primary rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3 overflow-hidden" style="width: 60px; height: 60px;">
                            <img src="../asset/crypto/bank.png" alt="Withdraw" style="width:100%; height:100%; object-fit:cover;">
                        </div>
                        <h5 class="fw-bold">Withdraw Funds</h5>
                        <p class="small text-muted">Withdraw your NGN balance to any Nigerian bank account.</p>
                        <a href="SendFund.php" class="btn btn-primary w-100 rounded-pill py-2">Go to Withdrawal</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Deposit Modal -->
    <div class="modal fade" id="depositModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold">Deposit <span id="depCurrencyName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div id="depInit">
                        <p class="text-muted">Enter amount to deposit (optional) or click generate to get address.</p>
                        <input type="number" id="depAmount" class="form-control mb-3" placeholder="0.00 (Optional)">
                        <button class="btn btn-primary w-100 py-2 rounded-3" id="btnGenDep" onclick="generateDeposit()">Generate Address</button>
                    </div>
                    <div id="depResult" style="display:none;">
                        <img id="depQR" src="" class="img-fluid mb-3 shadow-sm rounded-3">
                        <div class="input-group mb-3">
                            <input type="text" id="depAddr" class="form-control bg-light" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyValue('depAddr')"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <div class="alert alert-info small text-start">
                            <i class="bi bi-info-circle me-2"></i>Send exactly the amount shown on the invoice. Funds will be credited after 1 confirmation.
                        </div>
                        <a href="" id="depLocalInvoice" class="btn btn-primary w-100 rounded-pill py-2 mb-2">View My Invoice</a>
                        <a href="" id="depInvoiceLink" target="_blank" class="btn btn-link btn-sm">View Official Plisio Invoice</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Swap Modal -->
    <div class="modal fade" id="swapModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold">Instant Crypto Swap</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="swapForm">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">From</label>
                            <select name="from" class="form-select" required>
                                <?php foreach($supported as $s): ?>
                                <option value="<?php echo $s; ?>"><?php echo ($s == 'USDT_TRX') ? 'USDT (TRC-20)' : $s; ?> (Bal: <?php echo $userWallets[$s]['balance'] ?? 0; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3 text-center">
                            <i class="bi bi-arrow-down-up fs-4 text-muted"></i>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">To</label>
                            <select name="to" class="form-select" id="swapToSelect" required>
                                <?php foreach($supported as $s): ?>
                                <option value="<?php echo $s; ?>"><?php echo ($s == 'USDT_TRX') ? 'USDT (TRC-20)' : $s; ?></option>
                                <?php endforeach; ?>
                                <option value="NGN">Main Wallet (NGN)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Amount to Swap</label>
                            <input type="number" name="amount" id="swapAmount" class="form-control" step="0.00000001" required>
                        </div>

                        <div id="swapEstimate" class="mb-3 p-3 bg-light rounded-3 text-center d-none">
                            <div class="row align-items-center">
                                <div class="col-12 mb-2">
                                    <span class="text-muted small">You will receive:</span><br>
                                    <span class="h4 fw-bold text-success" id="swapVal">0.00</span>
                                </div>
                                <div id="swapFeeRow" class="col-12 border-top pt-2 d-none">
                                    <div class="d-flex justify-content-between px-2">
                                        <span class="text-muted small">Service Fee:</span>
                                        <span class="text-danger small fw-bold" id="swapFeeVal">₦ 0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success w-100 py-2 rounded-3 mt-2">Execute Swap</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include("../func/bc-footer.php"); ?>

    <!-- Wallet List Modal -->
    <div class="modal fade" id="walletListModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-0">
                    <h6 class="fw-bold mb-0">My Crypto Wallets</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php foreach($supported as $cid):
                            $bal = $userWallets[$cid]['balance'] ?? 0;
                            $label = ($cid == 'USDT_TRX') ? 'Tether (TRC-20)' : $cid;
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                            <div class="d-flex align-items-center">
                                <img src="../asset/crypto/<?php echo strtolower(str_replace('_', '-', $cid)) . ".png"; ?>" style="width:28px; height:28px; margin-right:12px;">
                                <div>
                                    <div class="fw-bold small"><?php echo $label; ?></div>
                                    <div class="text-muted small"><?php echo $cid; ?></div>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold"><?php echo (float)$bal; ?></div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Self Help Modal -->
    <div class="modal fade" id="selfHelpModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold">Crypto Self-Help</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="small text-muted mb-4">Paid for an invoice but it hasn't reflected? Enter your transaction reference below to manually synchronize with the payment provider.</p>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Transaction Reference</label>
                        <input type="text" id="syncRef" class="form-control form-control-lg bg-light border-0" placeholder="DEP_123456789...">
                    </div>
                    <button class="btn btn-info w-100 py-3 rounded-pill fw-bold text-white shadow-sm" onclick="triggerManualSync(this)">
                        <i class="bi bi-arrow-repeat me-1"></i> Sync Transaction
                    </button>

                    <div class="mt-4 pt-3 border-top">
                        <h6 class="fw-bold small mb-2 text-uppercase text-muted">Common Issues</h6>
                        <ul class="small text-muted ps-3 mb-0">
                            <li>Check if your wallet shows the transaction as "Confirmed".</li>
                            <li>Plisio requires at least 1-3 confirmations depending on the coin.</li>
                            <li>Syncing may take up to 2 minutes after the network confirmation.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Withdraw Modal -->
    <div class="modal fade" id="withdrawModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold">Send Crypto Externally</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="withdrawForm">
                    <div class="modal-body p-4">
                        <input type="hidden" name="currency" id="withdrawCid">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Destination Address</label>
                            <input type="text" name="address" class="form-control" placeholder="Enter wallet address" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Amount</label>
                            <input type="number" name="amount" class="form-control" step="0.00000001" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Transaction PIN</label>
                            <input type="password" name="pin" class="form-control" maxlength="4" placeholder="****" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Email OTP</label>
                            <div class="input-group">
                                <input type="text" name="otp" class="form-control" placeholder="6-digit code" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="sendWithdrawalOTP(this)">Get Code</button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2 rounded-3 mt-2">Submit Withdrawal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script>
        let currentDepCid = '';

        function openWalletListModal() {
            new bootstrap.Modal(document.getElementById('walletListModal')).show();
        }

        function openSelfHelpModal(ref = '') {
            document.getElementById('syncRef').value = ref;
            new bootstrap.Modal(document.getElementById('selfHelpModal')).show();
        }

        function triggerManualSync(btn) {
            const ref = document.getElementById('syncRef').value;
            if(!ref) {
                Swal.fire('Required', 'Please enter a transaction reference.', 'warning');
                return;
            }

            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Checking Status...';

            fetch('CryptoHub.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=manual_sync&reference=${ref}`
            })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.innerHTML = originalHtml;

                Swal.fire({
                    title: res.status.toUpperCase(),
                    text: res.message,
                    icon: res.status === 'info' ? 'info' : (res.status === 'success' ? 'success' : 'error')
                }).then(() => {
                    if(res.status === 'success') location.reload();
                });
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                Swal.fire('Error', 'An unexpected error occurred.', 'error');
            });
        }

        function openGeneralDepositModal() {
            let options = '';
            <?php foreach($supported as $cid):
                $cur = null; foreach($currencies as $c) if($c['cid'] == $cid) { $cur = $c; break; }
                if($cur):
            ?>
            options += `<div class="col-6 mb-3">
                            <button class="btn btn-outline-primary w-100 p-3 rounded-4" onclick="Swal.close(); openDepositModal('<?php echo $cid; ?>', '<?php echo $cur['name']; ?>')">
                                <img src="../asset/crypto/<?php echo strtolower(str_replace('_', '-', $cid)) . ".png"; ?>" style="width:30px; height:30px; margin-bottom:5px;"><br>
                                <small class="fw-bold"><?php echo $cur['name']; ?></small>
                            </button>
                        </div>`;
            <?php endif; endforeach; ?>

            Swal.fire({
                title: 'Select Currency to Deposit',
                html: `<div class="row g-2 p-2">${options}</div>`,
                showConfirmButton: false,
                showCloseButton: true
            });
        }

        function openDepositModal(cid, name) {
            currentDepCid = cid;
            document.getElementById('depCurrencyName').innerText = name;
            document.getElementById('depInit').style.display = 'block';
            document.getElementById('depResult').style.display = 'none';
            new bootstrap.Modal(document.getElementById('depositModal')).show();
        }

        function openGeneralWithdrawModal() {
            let options = '';
            <?php foreach($supported as $cid):
                $cur = null; foreach($currencies as $c) if($c['cid'] == $cid) { $cur = $c; break; }
                if($cur):
            ?>
            options += `<div class="col-6 mb-3">
                            <button class="btn btn-outline-danger w-100 p-3 rounded-4" onclick="Swal.close(); openWithdrawModal('<?php echo $cid; ?>')">
                                <img src="../asset/crypto/<?php echo strtolower(str_replace('_', '-', $cid)) . ".png"; ?>" style="width:30px; height:30px; margin-bottom:5px;"><br>
                                <small class="fw-bold"><?php echo $cur['name']; ?></small>
                            </button>
                        </div>`;
            <?php endif; endforeach; ?>

            Swal.fire({
                title: 'Select Currency to Send',
                html: `<div class="row g-2 p-2">${options}</div>`,
                showConfirmButton: false,
                showCloseButton: true
            });
        }

        function generateDeposit() {
            const btn = document.getElementById('btnGenDep');
            const amt = document.getElementById('depAmount').value;
            btn.disabled = true; btn.innerText = 'Generating...';

            fetch('CryptoHub.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=create_invoice&currency=${currentDepCid}&amount=${amt}`
            }).then(r => r.json()).then(res => {
                btn.disabled = false; btn.innerText = 'Generate Address';
                if(res.status == 'success') {
                    document.getElementById('depInit').style.display = 'none';
                    document.getElementById('depResult').style.display = 'block';
                    if(res.data.wallet_hash) {
                        document.getElementById('depQR').src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${res.data.wallet_hash}`;
                        document.getElementById('depAddr').value = res.data.wallet_hash;
                    } else {
                        document.getElementById('depQR').style.display = 'none';
                        document.getElementById('depAddr').parentElement.style.display = 'none';
                    }
                    document.getElementById('depInvoiceLink').href = res.data.invoice_url;
                    document.getElementById('depLocalInvoice').href = res.data.local_invoice_url;
                } else {
                    alert(res.message);
                }
            });
        }

        function copyValue(id) {
            const el = document.getElementById(id);
            el.select();
            document.execCommand('copy');
            alert('Copied to clipboard!');
        }

        function openSwapModal(target = '') {
            if(target) document.getElementById('swapToSelect').value = target;
            new bootstrap.Modal(document.getElementById('swapModal')).show();
        }

        document.getElementById('swapForm').onsubmit = function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            const to = fd.get('to');
            fd.append('action', 'swap');
            fetch('CryptoHub.php', {
                method: 'POST',
                body: new URLSearchParams(fd)
            }).then(r => r.json()).then(res => {
                if(res.status == 'success') {
                    let confirmBtn = 'Done';
                    let showCancel = false;
                    if(to === 'NGN') {
                        confirmBtn = 'Withdraw Now';
                        showCancel = true;
                    }

                    Swal.fire({
                        title: 'Success!',
                        text: res.message,
                        icon: 'success',
                        showCancelButton: showCancel,
                        confirmButtonText: confirmBtn,
                        cancelButtonText: 'Stay Here',
                        confirmButtonColor: '#287bff'
                    }).then((result) => {
                        if (result.isConfirmed && to === 'NGN') {
                            window.location.href = 'SendFund.php';
                        } else {
                            location.reload();
                        }
                    });
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            });
        }

        function openWithdrawModal(cid) {
            document.getElementById('withdrawCid').value = cid;
            new bootstrap.Modal(document.getElementById('withdrawModal')).show();
        }

        function sendWithdrawalOTP(btn) {
            const originalText = btn.innerHTML;
            btn.innerHTML = 'Sending...';
            btn.disabled = true;

            fetch('CryptoHub.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=send_otp'
            }).then(r => r.json()).then(res => {
                alert(res.message);
                if(res.status == 'success') {
                    let count = 60;
                    const timer = setInterval(() => {
                        btn.innerHTML = `Resend in ${count}s`;
                        count--;
                        if(count < 0) {
                            clearInterval(timer);
                            btn.innerHTML = 'Get Code';
                            btn.disabled = false;
                        }
                    }, 1000);
                } else {
                    btn.innerHTML = 'Get Code';
                    btn.disabled = false;
                }
            });
        }

        document.getElementById('withdrawForm').onsubmit = function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'withdraw');
            fetch('CryptoHub.php', {
                method: 'POST',
                body: new URLSearchParams(fd)
            }).then(r => r.json()).then(res => {
                alert(res.message);
                if(res.status == 'success') location.reload();
            });
        }

        // Rate estimation
        document.getElementById('swapAmount').oninput = function() {
            const amt = parseFloat(this.value || 0);
            const from = document.querySelector('#swapForm select[name="from"]').value;
            const to = document.querySelector('#swapForm select[name="to"]').value;
            const est = document.getElementById('swapEstimate');
            const valEl = document.getElementById('swapVal');

            if(amt > 0) {
                est.classList.remove('d-none');
                valEl.innerText = '...';
                fetch(`CryptoHub.php?get_rate=1&from=${from}&to=${to}`)
                .then(r => r.json()).then(res => {
                    if(res.rate) {
                        const gross = amt * res.rate;
                        if(to === 'NGN') {
                            const feePercent = <?php echo (float)($select_vendor_table['crypto_swap_fee'] ?? 0); ?>;
                            const fee = (feePercent / 100) * gross;
                            const net = gross - fee;
                            valEl.innerText = '₦ ' + net.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            document.getElementById('swapFeeVal').innerText = '₦ ' + fee.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            document.getElementById('swapFeeRow').classList.remove('d-none');
                        } else {
                            valEl.innerText = gross.toFixed(8) + ' ' + to;
                            document.getElementById('swapFeeRow').classList.add('d-none');
                        }
                    }
                });
            } else {
                est.classList.add('d-none');
            }
        }

    </script>
</body>
</html>