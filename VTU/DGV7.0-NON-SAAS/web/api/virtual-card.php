<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");
require_once("../../func/api-gateway/chimoney.php");
require_once("../../func/api-gateway/bsicards.php");
include_once("../../func/bc-giftcard-func.php");

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
$user_id = $user['id'];
$username = $user['username'];

$action = $input['action'] ?? 'list_cards';

// Fetch Profit Settings for calculations
$q_set = mysqli_query($connection_server, "SELECT * FROM sas_settings WHERE vendor_id='$vendor_id' AND setting_name LIKE 'vc_%'");
$vc_settings = []; while($rs = mysqli_fetch_assoc($q_set)) $vc_settings[$rs['setting_name']] = $rs['setting_value'];
$issuance_profit_usd = (float)($vc_settings['vc_issuance_profit_usd'] ?? 2.00);
$funding_fee_percent = (float)($vc_settings['vc_funding_profit_percent'] ?? 3.00);
$usd_rate = getLiveExchangeRate('USD', 'NGN', $vendor_id, 'virtual-card');

if ($action === 'list_cards') {
    $cards = [];
    $q = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE vendor_id='$vendor_id' AND username='$username' AND status != 'terminated' ORDER BY created_at DESC");
    while ($r = mysqli_fetch_assoc($q)) {
        $cards[] = [
            "reference" => $r['reference'],
            "masked_pan" => $r['masked_pan'],
            "balance_usd" => (float)$r['balance_usd'],
            "card_name" => $r['card_name'],
            "expiry" => $r['expiry_month'] . '/' . $r['expiry_year'],
            "status" => $r['status'],
            "is_frozen_auto" => (int)$r['is_frozen_auto'],
            "created_at" => $r['created_at']
        ];
    }

    $products = [];
    $q_prod = mysqli_query($connection_server, "SELECT g.* FROM sas_global_virtual_card_products g JOIN sas_vendor_virtual_card_products v ON g.chimoney_product_id = v.chimoney_product_id WHERE v.vendor_id='$vendor_id' AND v.status=1");
    while ($p = mysqli_fetch_assoc($q_prod)) {
        $products[] = [
            "id" => $p['chimoney_product_id'],
            "name" => $p['name'],
            "description" => $p['description'],
            "logo" => $p['logo_url'],
            "currency" => $p['currency']
        ];
    }

    echo json_encode(["status" => "success", "cards" => $cards, "available_products" => $products, "rate" => $usd_rate, "issuance_fee" => $issuance_profit_usd, "funding_fee_pct" => $funding_fee_percent]);
}

elseif ($action === 'issue') {
    $product_id = mysqli_real_escape_string($connection_server, $input['product_id'] ?? '');
    $amount_usd = (float)($input['amount_usd'] ?? 5);
    $card_name = mysqli_real_escape_string($connection_server, $input['card_name'] ?? '');
    $pin = $input['pin'] ?? '';

    if (empty($pin)) {
        echo json_encode(['status' => 'error', 'message' => 'Transaction PIN is required']);
        exit;
    }

    if (!verifyUserPIN($pin, $user)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Transaction PIN']);
        exit;
    }

    if ($amount_usd < 5) {
        echo json_encode(['status' => 'error', 'message' => 'Minimum issuance amount is $5']);
        exit;
    }

    $total_usd = $amount_usd + $issuance_profit_usd;
    $total_ngn = $total_usd * $usd_rate;

    if ($user['balance'] < $total_ngn) {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient wallet balance. NGN ' . number_format($total_ngn, 2) . ' required.']);
        exit;
    }

    if (strpos($product_id, 'bsi_') === 0) {
        $res = issueVirtualCardBSICards($vendor_id, $username, $product_id, $amount_usd);
    } else {
        $res = issueVirtualCard($vendor_id, $username, $card_name, $user['email'], $amount_usd);
    }

    if ($res['status'] === 'success') {
        chargeOtherUser($username, "debit", "virtual_card", "Virtual Card Issuance", $res['reference'], $res['card_id'] ?? $res['reference'], $total_ngn, $total_ngn, "New Virtual Card: $card_name ($$amount_usd)", "APP", $_SERVER['HTTP_HOST'], 1);
        echo json_encode(['status' => 'success', 'message' => 'Card issued successfully!', 'reference' => $res['reference']]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $res['message']]);
    }
}

elseif ($action === 'fund') {
    $reference = mysqli_real_escape_string($connection_server, $input['card_ref'] ?? '');
    $amount_usd = (float)($input['amount_usd'] ?? 0);
    $pin = $input['pin'] ?? '';

    if (empty($pin)) {
        echo json_encode(['status' => 'error', 'message' => 'Transaction PIN is required']);
        exit;
    }

    if (!verifyUserPIN($pin, $user)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Transaction PIN']);
        exit;
    }

    if ($amount_usd <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Amount must be greater than 0']);
        exit;
    }

    $total_usd_with_fee = $amount_usd * (1 + ($funding_fee_percent / 100));
    $total_ngn = $total_usd_with_fee * $usd_rate;

    if ($user['balance'] < $total_ngn) {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient wallet balance. NGN ' . number_format($total_ngn, 2) . ' required.']);
        exit;
    }

    $c_q = mysqli_query($connection_server, "SELECT provider FROM sas_virtual_cards_v2 WHERE reference='$reference' LIMIT 1");
    $card_data = mysqli_fetch_assoc($c_q);

    if ($card_data['provider'] === 'bsicards') {
        $res = fundVirtualCardBSICards($vendor_id, $reference, $amount_usd);
    } else {
        $res = fundVirtualCardV2($vendor_id, $username, $reference, $amount_usd);
    }

    if ($res['status'] === 'success') {
        chargeOtherUser($username, "debit", "virtual_card", "Virtual Card Funding", "FND".time(), $reference, $total_ngn, $total_ngn, "Virtual Card Top-up: $$amount_usd", "APP", $_SERVER['HTTP_HOST'], 1);
        echo json_encode(['status' => 'success', 'message' => 'Card funded successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $res['message']]);
    }
}

elseif ($action === 'reveal') {
    $pin = $input['pin'] ?? '';
    $reference = mysqli_real_escape_string($connection_server, $input['card_ref'] ?? '');

    if (verifyUserPIN($pin, $user)) {
        $q = mysqli_query($connection_server, "SELECT masked_pan, cvv, expiry_month, expiry_year FROM sas_virtual_cards_v2 WHERE reference='$reference' AND username='$username' LIMIT 1");
        if ($r = mysqli_fetch_assoc($q)) {
            echo json_encode(['status' => 'success', 'data' => $r]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Card not found']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Transaction PIN']);
    }
}

elseif ($action === 'withdraw') {
    $reference = mysqli_real_escape_string($connection_server, $input['card_ref'] ?? '');

    $q = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE reference='$reference' AND username='$username' LIMIT 1");
    $card = mysqli_fetch_assoc($q);

    if ($card && $card['balance_usd'] > 0) {
        $amt_usd = $card['balance_usd'];
        $amt_ngn = $amt_usd * $usd_rate;

        mysqli_query($connection_server, "UPDATE sas_virtual_cards_v2 SET balance_usd = 0, status='terminated' WHERE id='".$card['id']."'");
        chargeOtherUser($username, "credit", "virtual_card", "Card Liquidation", "WDR".time(), $reference, $amt_ngn, $amt_ngn, "Virtual Card Withdrawal: $$amt_usd returned to wallet", "APP", $_SERVER['HTTP_HOST'], 1);

        echo json_encode(['status' => 'success', 'message' => 'Funds returned to NGN wallet and card terminated.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No funds available on card']);
    }
}

else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}

mysqli_close($connection_server);
