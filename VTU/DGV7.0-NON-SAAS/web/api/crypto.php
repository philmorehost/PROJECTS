<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");
include_once("../../func/bc-crypto-func.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($input["api_key"] ?? '')), 0));

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

// §28: the legacy crypto provider is retired and removed. Reads of existing
// wallets/balances/history stay available so users can still see funds and support
// can reconcile, but no NEW invoice, swap or payout is created against it.
$action = $input['action'] ?? 'get_wallets';
$bc_crypto_retired_actions = ['create_invoice', 'deposit', 'swap', 'convert', 'cash_out', 'withdraw', 'payout'];
if (in_array(strtolower((string)$action), $bc_crypto_retired_actions, true) && bc_gateway_is_retired('plisio')) {
    echo json_encode([
        "status" => "error",
        "code" => "GATEWAY_RETIRED",
        "message" => bc_retired_gateway_message('plisio'),
    ]);
    exit;
}

if ($action === 'get_wallets') {
    $userWallets = getUserCryptoWallets($vendor_id, $username);
    $supported = ['BTC', 'ETH', 'LTC', 'TRX', 'USDT_TRX', 'USDC', 'BCH', 'DOGE'];

    $wallet_list = [];
    foreach($supported as $cid) {
        $bal = $userWallets[$cid]['balance'] ?? 0;
        $label = ($cid == 'USDT_TRX') ? 'Tether (TRC-20)' : $cid;

        // §28: supported set is defined locally; wallets/balances are local records.
        $is_active = true;

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
    // Rates came from the retired provider, so no quote can be issued until Phase 3 adapters.
    if (bc_gateway_is_retired('plisio')) {
        echo json_encode(["status" => "error", "code" => "GATEWAY_RETIRED", "message" => bc_retired_gateway_message('plisio')]);
        exit;
    }
    $from = strtoupper($input['from'] ?? '');
    $to = strtoupper($input['to'] ?? '');
    if (empty($from) || empty($to)) {
        echo json_encode(["status" => "error", "message" => "From and To currencies required"]);
        exit;
    }
    // §28: no rate source remains on the retired rail (refused above).
    echo json_encode(["status" => "error", "code" => "GATEWAY_RETIRED", "message" => bc_retired_gateway_message('plisio')]);
}

elseif ($action === 'create_invoice') {
    // §28: refused by the retired-gateway guard above; no provider call remains here.
    echo json_encode(["status" => "error", "code" => "GATEWAY_RETIRED", "message" => bc_retired_gateway_message('plisio')]);
    exit;
}

elseif ($action === 'swap') {
    // §28: refused by the retired-gateway guard above; no provider call remains here.
    echo json_encode(["status" => "error", "code" => "GATEWAY_RETIRED", "message" => bc_retired_gateway_message('plisio')]);
    exit;
}

elseif ($action === 'withdraw') {
    // §28: refused by the retired-gateway guard above; no provider call remains here.
    echo json_encode(["status" => "error", "code" => "GATEWAY_RETIRED", "message" => bc_retired_gateway_message('plisio')]);
    exit;
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
