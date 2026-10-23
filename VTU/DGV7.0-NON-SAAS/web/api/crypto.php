<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");
include_once("../../func/bc-crypto-func.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? '')));

if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    exit;
}

$vendor_id = resolveVendorID();
$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) != 1) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
    exit;
}
$user = mysqli_fetch_assoc($check_user);
$username = $user['username'];

if(!isServiceEnabled('crypto_hub')){
    echo json_encode(["status" => "error", "message" => "Crypto Hub is currently disabled"]);
    exit;
}

$action = $input['action'] ?? 'get_wallets';

if ($action === 'get_wallets') {
    $currencies = getPlisioCurrencies($vendor_id);
    $userWallets = getUserCryptoWallets($vendor_id, $username);
    $supported = ['BTC', 'ETH', 'LTC', 'TRX', 'USDT_TRX', 'USDC', 'BCH', 'DOGE'];

    $wallet_list = [];
    foreach($supported as $cid) {
        $bal = $userWallets[$cid]['balance'] ?? 0;
        $label = ($cid == 'USDT_TRX') ? 'Tether (TRC-20)' : $cid;

        // Check if active in Plisio
        $is_active = false;
        foreach($currencies as $c) if($c['cid'] == $cid) { $is_active = true; break; }

        if($is_active || $bal > 0) {
            $wallet_list[] = [
                "currency" => $cid,
                "label" => $label,
                "balance" => (float)$bal,
                "address" => $userWallets[$cid]['address'] ?? '',
                "is_active" => $is_active
            ];
        }
    }

    $select_v = mysqli_query($connection_server, "SELECT crypto_swap_fee FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
    $rv = mysqli_fetch_assoc($select_v);
    $swap_fee_percent = (float)($rv['crypto_swap_fee'] ?? 0);

    echo json_encode([
        "status" => "success",
        "wallets" => $wallet_list,
        "swap_fee_percent" => $swap_fee_percent,
        "ngn_balance" => (float)$user['balance']
    ]);
}

elseif ($action === 'get_rate') {
    $from = strtoupper($input['from'] ?? '');
    $to = strtoupper($input['to'] ?? '');
    if (empty($from) || empty($to)) {
        echo json_encode(["status" => "error", "message" => "From and To currencies required"]);
        exit;
    }
    $rate = getPlisioExchangeRate($from, $to, $vendor_id);
    echo json_encode(["status" => "success", "rate" => (float)$rate]);
}

elseif ($action === 'create_invoice') {
    $currency = mysqli_real_escape_string($connection_server, $input['currency'] ?? '');
    $amount = (float)($input['amount'] ?? 0);

    if (empty($currency)) {
        echo json_encode(['status' => 'error', 'message' => 'Currency is required']);
        exit;
    }

    $params = [
        'currency' => $currency,
        'order_number' => 'DEP_APP_' . time() . '_' . rand(10, 99),
        'order_name' => 'App Deposit - ' . $username,
        'callback_url' => $web_http_host . '/users-plisio.php',
        'email' => $user['email'],
        'amount' => $amount
    ];

    $res = createPlisioInvoice($params, $vendor_id);
    if (($res['status'] ?? '') == 'success') {
        $tx_data = $res['data'];
        $internal_ref = $params['order_number'];
        $actual_amount = $tx_data['invoice_total_sum'] ?? $amount;

        logCryptoTransaction($vendor_id, $username, 'deposit', $currency, $actual_amount, 2, $tx_data['txn_id'], $tx_data['invoice_url'], $tx_data['wallet_hash'] ?? '', ['order_number' => $internal_ref], $internal_ref, $tx_data['view_key'] ?? '');

        echo json_encode(['status' => 'success', 'data' => $tx_data, 'local_ref' => $internal_ref]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $res['message'] ?? 'Plisio Error']);
    }
}

elseif ($action === 'swap') {
    $from = strtoupper(mysqli_real_escape_string($connection_server, $input['from'] ?? ''));
    $to = strtoupper(mysqli_real_escape_string($connection_server, $input['to'] ?? ''));
    $amount = (float)($input['amount'] ?? 0);
    $pin = $input['pin'] ?? '';

    // WHO MUST PROVE THE PIN: the mobile app is a person and always sends one. An external api_key
    // integration serves that merchant's own customers, so there is no single PIN to ask for - the
    // requirement is skipped when the caller sends none. A supplied pin is still always validated.
    $__pin_is_person = (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android') || isset($api_post_info_from_app);
    $__pin_supplied  = ($pin !== '' && $pin !== null);
    if ($__pin_is_person || $__pin_supplied) {
        if (empty($pin)) {
            echo json_encode(['status' => 'error', 'message' => 'Transaction PIN is required']);
            exit;
        }

        if (!verifyUserPIN($pin, $user)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Transaction PIN']);
            exit;
        }
    }

    if ($from == $to || $amount <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid swap parameters']);
        exit;
    }

    $wallets = getUserCryptoWallets($vendor_id, $username);
    if (($wallets[$from]['balance'] ?? 0) < $amount) {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient Crypto Balance']);
        exit;
    }

    $rate = getPlisioExchangeRate($from, $to, $vendor_id);
    if ($rate <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Exchange rate currently unavailable']);
        exit;
    }

    $gross_target = $amount * $rate;
    $fee = 0;
    $net_target = $gross_target;

    if ($to == 'NGN') {
        $select_v = mysqli_query($connection_server, "SELECT crypto_swap_fee FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
        $rv = mysqli_fetch_assoc($select_v);
        $swap_fee_percent = (float)($rv['crypto_swap_fee'] ?? 0);
        $fee = ($swap_fee_percent / 100) * $gross_target;
        $net_target = $gross_target - $fee;
    }

    if (updateUserCryptoBalance($vendor_id, $username, $from, $amount, 'debit')) {
        if ($to == 'NGN') {
            chargeOtherUser($username, "credit", "crypto_swap", "Crypto Swap", "SWP_" . time(), "INTERNAL", $gross_target, $net_target, "App Swap $amount $from to NGN", "APP", $_SERVER['HTTP_HOST'], 1);
            logCryptoTransaction($vendor_id, $username, 'swap_out', $from, $amount, 1, 'INTERNAL_SWAP', '', '', ['to' => 'NGN', 'rate' => $rate, 'fee' => $fee]);
            echo json_encode(['status' => 'success', 'message' => 'Swap successful! NGN balance updated.']);
        } else {
            // Live/Internal crypto-to-crypto
            if (updateUserCryptoBalance($vendor_id, $username, $to, $net_target, 'credit')) {
                logCryptoTransaction($vendor_id, $username, 'swap_out', $from, $amount, 1, 'INTERNAL_SWAP', '', '', ['to' => $to, 'rate' => $rate]);
                logCryptoTransaction($vendor_id, $username, 'swap_in', $to, $net_target, 1, 'INTERNAL_SWAP', '', '', ['from' => $from, 'rate' => $rate]);
                echo json_encode(['status' => 'success', 'message' => "Swap of $amount $from to $to completed."]);
            } else {
                updateUserCryptoBalance($vendor_id, $username, $from, $amount, 'credit'); // Rollback
                echo json_encode(['status' => 'error', 'message' => 'Failed to credit target wallet.']);
            }
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Debit failed.']);
    }
}

elseif ($action === 'withdraw') {
    $currency = mysqli_real_escape_string($connection_server, $input['currency'] ?? '');
    $amount = (float)($input['amount'] ?? 0);
    $address = mysqli_real_escape_string($connection_server, $input['address'] ?? '');
    $pin = $input['pin'] ?? '';

    // See the note in the 'swap' branch: a person (mobile app) must prove the PIN, an external
    // api_key integration must not be blocked by a PIN its customers cannot supply.
    $__pin_is_person = (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android') || isset($api_post_info_from_app);
    $__pin_supplied  = ($pin !== '' && $pin !== null);
    if ($__pin_is_person || $__pin_supplied) {
        if (!verifyUserPIN($pin, $user)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Transaction PIN']);
            exit();
        }
    }

    $wallets = getUserCryptoWallets($vendor_id, $username);
    if (($wallets[$currency]['balance'] ?? 0) < $amount) {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient Balance']);
        exit();
    }

    $v_q = mysqli_query($connection_server, "SELECT approve_withdrawal FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
    $v_r = mysqli_fetch_assoc($v_q);

    if ($v_r['approve_withdrawal'] == 1) {
        updateUserCryptoBalance($vendor_id, $username, $currency, $amount, 'debit');
        $w_ref = 'CW_APP_' . time();
        mysqli_query($connection_server, "INSERT INTO sas_crypto_withdrawals (vendor_id, username, reference, currency_code, crypto_amount, address, status) VALUES ('$vendor_id', '$username', '$w_ref', '$currency', '$amount', '$address', 'pending')");
        echo json_encode(['status' => 'success', 'message' => 'Withdrawal request submitted for approval']);
    } else {
        $res = plisioCashOut(['psys_cid' => $currency, 'amount' => $amount, 'address' => $address, 'type' => 'cash_out'], $vendor_id);
        if (($res['status'] ?? '') == 'success') {
            updateUserCryptoBalance($vendor_id, $username, $currency, $amount, 'debit');
            logCryptoTransaction($vendor_id, $username, 'withdrawal', $currency, $amount, 1, $res['data']['txn_id'], '', $address);
            echo json_encode(['status' => 'success', 'message' => 'Withdrawal processed successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $res['message'] ?? 'Plisio Withdrawal Failed']);
        }
    }
}

elseif ($action === 'history') {
    $history = [];
    $q = mysqli_query($connection_server, "SELECT * FROM sas_crypto_transactions WHERE vendor_id='$vendor_id' AND username='$username' ORDER BY id DESC LIMIT 50");
    while ($r = mysqli_fetch_assoc($q)) {
        $history[] = [
            "id" => (int)$r['id'],
            "type" => $r['type'],
            "currency" => $r['currency_code'],
            "amount" => (float)$r['amount'],
            "status" => (int)$r['status'],
            "reference" => $r['reference'],
            "txid" => $r['blockchain_txid'],
            "date" => $r['created_at']
        ];
    }
    echo json_encode(["status" => "success", "history" => $history]);
}

else {
    echo json_encode(["status" => "error", "message" => "Invalid crypto action"]);
}

mysqli_close($connection_server);
