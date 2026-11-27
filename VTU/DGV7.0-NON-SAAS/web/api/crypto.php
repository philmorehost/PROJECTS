<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");
include_once("../../func/bc-crypto-func.php");
include_once("../../func/bc-crypto-a2p.php");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input)) {
    $input = $_REQUEST;
}

$vendor_id = resolveVendorID();
if ($vendor_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Vendor not found or inactive"]);
    exit;
}

$vendor_check = mysqli_query($connection_server, "SELECT id, force_security_pin, crypto_swap_fee FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1");
if (!$vendor_check || mysqli_num_rows($vendor_check) !== 1) {
    echo json_encode(["status" => "error", "message" => "Vendor not found or inactive"]);
    exit;
}
$vendor_row = mysqli_fetch_assoc($vendor_check);

$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($input["api_key"] ?? '')), $vendor_id));

if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' AND status=1 AND (is_blocked IS NULL OR is_blocked=0) LIMIT 1");
if (!$check_user || mysqli_num_rows($check_user) != 1) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key or inactive account"]);
    exit;
}
$user = mysqli_fetch_assoc($check_user);
$username = $user['username'];
$username_esc = mysqli_real_escape_string($connection_server, $username);

if (!isServiceEnabled('crypto_hub')) {
    echo json_encode(["status" => "error", "message" => "Crypto Hub is currently disabled"]);
    exit;
}

// §28: the legacy third-party crypto provider is retired and removed. Legacy
// invoice/payout actions are refused, while the API-free Admin <-> User
// (A2P / P2A) CryptoHub Buy, Sell, and Swap desk operates locally.
$action = $input['action'] ?? 'get_wallets';
$bc_crypto_retired_actions = ['create_invoice', 'deposit', 'convert', 'cash_out', 'withdraw', 'payout'];
if (in_array(strtolower((string)$action), $bc_crypto_retired_actions, true) && bc_gateway_is_retired('plisio')) {
    echo json_encode([
        "status" => "error",
        "code" => "GATEWAY_RETIRED",
        "message" => bc_retired_gateway_message('plisio'),
    ]);
    exit;
}

$requireCryptoKyc = function () use ($vendor_id, $user) {
    if (function_exists('isKYCEnforced') && isKYCEnforced($vendor_id) && (int)($user['kyc_status'] ?? 0) !== 2) {
        echo json_encode([
            "status" => "error",
            "code" => "KYC_REQUIRED",
            "message" => "Please complete your KYC verification before placing CryptoHub orders."
        ]);
        exit;
    }
};

$requireCryptoPin = function () use ($input, $user, $vendor_row) {
    $pin = (string)($input['pin'] ?? '');
    $isApp = function_exists('bc_api_is_app_session')
        ? bc_api_is_app_session(isset($GLOBALS['api_post_info_from_app']) && is_array($GLOBALS['api_post_info_from_app']))
        : false;
    $forcePin = !empty($vendor_row['force_security_pin']) || !empty($user['security_pin']);
    if ($isApp || $forcePin || $pin !== '') {
        if ($pin === '') {
            echo json_encode(["status" => "error", "code" => "PIN_REQUIRED", "message" => "Transaction PIN is required"]);
            exit;
        }
        if (!function_exists('bc_verify_pin_secure') || !bc_verify_pin_secure($pin, $user, 'crypto_a2p')) {
            echo json_encode(["status" => "error", "code" => "PIN_INVALID", "message" => "Invalid Transaction PIN"]);
            exit;
        }
    }
};

if ($action === 'get_wallets' || $action === 'a2p_desk') {
    $forceRefresh = !empty($input['refresh']);
    $bundle = bc_crypto_a2p_all_quotes($vendor_id, $forceRefresh);
    $cfg = $bundle['config'];
    $rates = $bundle['rates'];
    $quotes = $bundle['quotes'];
    $userWallets = getUserCryptoWallets($vendor_id, $username);

    $wallet_list = [];
    $seenCodes = [];
    $portfolio_ngn = 0.0;

    foreach ($quotes as $cid => $q) {
        $bal = (float)($userWallets[$cid]['balance'] ?? 0);
        if (!$q['enabled'] && $bal <= 0) {
            continue;
        }
        $seenCodes[$cid] = true;
        $valNgn = round($bal * (float)$q['base_ngn'], 2);
        $portfolio_ngn += $valNgn;
        $wallet_list[] = [
            "currency" => $cid,
            "symbol" => $q['symbol'],
            "label" => $q['name'],
            "network" => $q['network'],
            "network_label" => $q['network_label'],
            "balance" => $bal,
            "val_ngn" => $valNgn,
            "address" => $userWallets[$cid]['address'] ?? '',
            "deposit_address" => $q['deposit_address'],
            "network_note" => $q['network_note'],
            "address_hint" => $q['address_hint'],
            "decimals" => (int)$q['decimals'],
            "is_active" => (bool)$q['enabled'],
            "buy_enabled" => (bool)$q['buy_enabled'],
            "sell_enabled" => (bool)$q['sell_enabled'],
            "swap_enabled" => (bool)$q['swap_enabled'],
            "usd_price" => (float)$q['usd_price'],
            "base_ngn" => (float)$q['base_ngn'],
            "buy_rate_ngn" => (float)$q['buy_rate_ngn'],
            "sell_rate_ngn" => (float)$q['sell_rate_ngn'],
        ];
    }

    // Preserve visibility of any held legacy wallets (e.g., USDT_TRX)
    foreach ($userWallets as $cid => $uw) {
        if (isset($seenCodes[$cid])) {
            continue;
        }
        $bal = (float)($uw['balance'] ?? 0);
        if ($bal <= 0) {
            continue;
        }
        $fallbackQuote = ($cid === 'USDT_TRX' && isset($quotes['USDT_TRC20'])) ? $quotes['USDT_TRC20'] : null;
        $baseNgn = $fallbackQuote ? (float)$fallbackQuote['base_ngn'] : 0.0;
        $valNgn = round($bal * $baseNgn, 2);
        $portfolio_ngn += $valNgn;
        $wallet_list[] = [
            "currency" => $cid,
            "symbol" => $fallbackQuote ? $fallbackQuote['symbol'] : $cid,
            "label" => ($cid === 'USDT_TRX') ? 'Tether (TRC-20 Legacy)' : $cid,
            "network" => $fallbackQuote ? $fallbackQuote['network'] : $cid,
            "network_label" => $fallbackQuote ? $fallbackQuote['network_label'] : $cid,
            "balance" => $bal,
            "val_ngn" => $valNgn,
            "address" => $uw['address'] ?? '',
            "deposit_address" => '',
            "network_note" => 'Legacy held balance',
            "address_hint" => '',
            "decimals" => 4,
            "is_active" => false,
            "buy_enabled" => false,
            "sell_enabled" => false,
            "swap_enabled" => false,
            "usd_price" => $fallbackQuote ? (float)$fallbackQuote['usd_price'] : 0.0,
            "base_ngn" => $baseNgn,
            "buy_rate_ngn" => 0.0,
            "sell_rate_ngn" => 0.0,
        ];
    }

    $kycRequired = function_exists('isKYCEnforced') ? isKYCEnforced($vendor_id) : false;
    $kycVerified = ((int)($user['kyc_status'] ?? 0) === 2);

    echo json_encode([
        "status" => "success",
        "wallets" => $wallet_list,
        "swap_fee_percent" => (float)$cfg['swap_fee_pct'],
        "ngn_balance" => (float)$user['balance'],
        "portfolio_ngn" => round($portfolio_ngn, 2),
        "desk" => [
            "rate_mode" => $cfg['rate_mode'],
            "rate_source" => $rates['source'],
            "swap_fee_pct" => (float)$cfg['swap_fee_pct'],
            "min_trade_ngn" => (float)$cfg['min_trade_ngn'],
            "max_trade_ngn" => (float)$cfg['max_trade_ngn'],
            "desk_notice" => $cfg['desk_notice'],
            "updated_at" => $rates['updated_at'],
            "kyc_required" => $kycRequired,
            "kyc_verified" => $kycVerified,
            "withdraw_channels" => [
                "local" => "SendFund.php",
                "international" => "IntlTransfer.php",
            ],
        ],
    ]);
}

elseif ($action === 'get_rate') {
    if (bc_gateway_is_retired('plisio')) {
        echo json_encode(["status" => "error", "code" => "GATEWAY_RETIRED", "message" => bc_retired_gateway_message('plisio')]);
        exit;
    }
    echo json_encode(["status" => "error", "code" => "GATEWAY_RETIRED", "message" => bc_retired_gateway_message('plisio')]);
}

elseif ($action === 'create_invoice') {
    echo json_encode(["status" => "error", "code" => "GATEWAY_RETIRED", "message" => bc_retired_gateway_message('plisio')]);
    exit;
}

elseif ($action === 'get_a2p_rate') {
    $from = strtoupper(trim((string)($input['from'] ?? '')));
    $to = strtoupper(trim((string)($input['to'] ?? '')));
    if ($from === '' || $to === '') {
        echo json_encode(["status" => "error", "message" => "From and To currencies required"]);
        exit;
    }
    $bundle = bc_crypto_a2p_all_quotes($vendor_id);
    $cfg = $bundle['config'];
    $rates = $bundle['rates'];
    $quotes = $bundle['quotes'];

    $fromNgn = ($from === 'NGN') ? 1.0 : (float)($quotes[$from]['base_ngn'] ?? 0);
    $toNgn = ($to === 'NGN') ? 1.0 : (float)($quotes[$to]['base_ngn'] ?? 0);
    if ($fromNgn <= 0 || $toNgn <= 0) {
        echo json_encode(["status" => "error", "message" => "Unsupported currency pair"]);
        exit;
    }

    $rate = round($fromNgn / $toNgn, 8);
    echo json_encode([
        "status" => "success",
        "from" => $from,
        "to" => $to,
        "rate" => $rate,
        "swap_fee_pct" => (float)$cfg['swap_fee_pct'],
        "rate_source" => $rates['source'],
        "updated_at" => $rates['updated_at'],
    ]);
}

elseif ($action === 'a2p_buy') {
    $requireCryptoKyc();
    $requireCryptoPin();
    $res = bc_crypto_a2p_submit_buy($connection_server, $vendor_id, $user, $input);
    echo json_encode([
        "status" => !empty($res['ok']) ? "success" : "error",
        "reference" => $res['reference'] ?? '',
        "message" => $res['message'] ?? 'Unable to process Buy order.',
    ]);
}

elseif ($action === 'p2a_sell') {
    $requireCryptoKyc();
    $requireCryptoPin();
    $res = bc_crypto_a2p_submit_sell($connection_server, $vendor_id, $user, $input);
    echo json_encode([
        "status" => !empty($res['ok']) ? "success" : "error",
        "reference" => $res['reference'] ?? '',
        "message" => $res['message'] ?? 'Unable to process Sell order.',
    ]);
}

elseif ($action === 'a2p_swap' || $action === 'swap') {
    $requireCryptoKyc();
    $requireCryptoPin();
    $swapInput = [
        'swap_from' => $input['swap_from'] ?? ($input['from'] ?? ''),
        'swap_to' => $input['swap_to'] ?? ($input['to'] ?? ''),
        'amount' => $input['amount'] ?? 0,
    ];
    $res = bc_crypto_a2p_execute_swap($connection_server, $vendor_id, $user, $swapInput);
    echo json_encode([
        "status" => !empty($res['ok']) ? "success" : "error",
        "reference" => $res['reference'] ?? '',
        "to_is_ngn" => !empty($res['to_is_ngn']),
        "message" => $res['message'] ?? 'Unable to process Swap.',
    ]);
}

elseif ($action === 'history') {
    $history = [];
    $q = mysqli_query($connection_server, "SELECT * FROM sas_crypto_transactions WHERE vendor_id='$vendor_id' AND username='$username_esc' ORDER BY id DESC LIMIT 50");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $meta = !empty($r['metadata']) ? json_decode((string)$r['metadata'], true) : null;
            if (!is_array($meta)) {
                $meta = [];
            }
            $history[] = [
                "id" => (int)$r['id'],
                "type" => $r['type'],
                "currency" => $r['currency_code'],
                "amount" => (float)$r['amount'],
                "ngn_amount" => isset($meta['ngn_amount']) ? (float)$meta['ngn_amount'] : (isset($meta['net_ngn']) ? (float)$meta['net_ngn'] : 0.0),
                "status" => (int)$r['status'],
                "reference" => $r['reference'],
                "txid" => $r['blockchain_txid'],
                "address" => $r['address'],
                "reject_reason" => (string)($meta['admin_note'] ?? ''),
                "date" => $r['created_at'],
            ];
        }
    }
    echo json_encode(["status" => "success", "history" => $history]);
}

else {
    echo json_encode(["status" => "error", "message" => "Invalid crypto action"]);
}

mysqli_close($connection_server);
