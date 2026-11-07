<?php session_start();
require_once("../func/bc-config.php");
require_once("../func/api-gateway/chimoney.php");
require_once("../func/api-gateway/bsicards.php");

$vid = resolveVendorID();
$uname = $_SESSION["user_session"] ?? "";
if ($vid <= 0 || empty($uname)) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? '';

// Fetch Profit Settings for calculations
$q_set = mysqli_query($connection_server, "SELECT * FROM sas_settings WHERE vendor_id='$vid' AND setting_name LIKE 'vc_%'");
$vc_settings = []; while($rs = mysqli_fetch_assoc($q_set)) $vc_settings[$rs['setting_name']] = $rs['setting_value'];
$issuance_profit_usd = (float)($vc_settings['vc_issuance_profit_usd'] ?? 2.00);
$funding_fee_percent = (float)($vc_settings['vc_funding_profit_percent'] ?? 3.00);

// Use a fixed or dynamic rate for USD/NGN
require_once("../func/bc-giftcard-func.php");
$usd_rate = getLiveExchangeRate('USD', 'NGN', $vid, 'virtual-card');

if ($action === 'reveal_security') {
    $pin = $_POST['pin'] ?? '';
    $ref = $_POST['card_ref'] ?? '';

    $u_q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vid' AND username='$uname' LIMIT 1");
    $u_r = mysqli_fetch_assoc($u_q);

    if (verifyUserPIN($pin, $u_r)) {
        $ref_esc = mysqli_real_escape_string($connection_server, $ref);
        $c_q = mysqli_query($connection_server, "SELECT masked_pan, cvv, expiry_month, expiry_year FROM sas_virtual_cards_v2 WHERE reference='$ref_esc' AND username='$uname' LIMIT 1");
        $c_r = mysqli_fetch_assoc($c_q);
        echo json_encode(['status' => 'success', 'data' => $c_r]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Transaction PIN']);
    }
}

if ($action === 'issue_card') {
    $amount_usd = (float)($_POST['amount_usd'] ?? 5);
    $name_on_card = mysqli_real_escape_string($connection_server, trim($_POST['name_on_card'] ?? ''));
    $product_id = mysqli_real_escape_string($connection_server, $_POST['product_id'] ?? 'visa_usd_global');

    if ($amount_usd < 5) {
        echo json_encode(['status' => 'error', 'message' => 'Minimum issuance amount is $5']);
        exit();
    }

    $total_usd = $amount_usd + $issuance_profit_usd;
    $total_ngn = $total_usd * $usd_rate;

    $u_q = mysqli_query($connection_server, "SELECT balance, email FROM sas_users WHERE vendor_id='$vid' AND username='$uname' LIMIT 1");
    $u_r = mysqli_fetch_assoc($u_q);

    if ($u_r['balance'] >= $total_ngn) {
        // Routing logic based on product_id prefix
        if (strpos($product_id, 'bsi_') === 0) {
            $res = issueVirtualCardBSICards($vid, $uname, $product_id, $amount_usd);
        } else {
            $res = issueVirtualCard($vid, $uname, $name_on_card, $u_r['email'], $amount_usd);
        }

        if ($res['status'] === 'success') {
            chargeOtherUser($uname, "debit", "virtual_card", "Virtual Card Issuance", $res['reference'], $res['card_id'] ?? $res['reference'], $total_ngn, $total_ngn, "New Virtual Card Issuance: $name_on_card ($$amount_usd)", "WEB", $_SERVER['HTTP_HOST'], 1);
            echo json_encode(['status' => 'success', 'message' => 'Card Issued Successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $res['message']]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient Wallet Balance. You need NGN ' . number_format($total_ngn, 2)]);
    }
}

if ($action === 'fund_card') {
    $amount_usd = (float)($_POST['amount_usd'] ?? 0);
    $card_ref = $_POST['card_ref'] ?? '';
    $ref_esc = mysqli_real_escape_string($connection_server, $card_ref);

    $total_usd_with_fee = $amount_usd * (1 + ($funding_fee_percent / 100));
    $total_ngn = $total_usd_with_fee * $usd_rate;

    $u_q = mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE vendor_id='$vid' AND username='$uname' LIMIT 1");
    $u_r = mysqli_fetch_assoc($u_q);

    if ($u_r['balance'] >= $total_ngn) {
        $c_q = mysqli_query($connection_server, "SELECT provider FROM sas_virtual_cards_v2 WHERE reference='$ref_esc' LIMIT 1");
        $card_data = mysqli_fetch_assoc($c_q);

        if ($card_data['provider'] === 'bsicards') {
            $res = fundVirtualCardBSICards($vid, $card_ref, $amount_usd);
        } else {
            $res = fundVirtualCardV2($vid, $uname, $card_ref, $amount_usd);
        }

        if ($res['status'] === 'success') {
            chargeOtherUser($uname, "debit", "virtual_card", "Virtual Card Funding", "FND".time(), $card_ref, $total_ngn, $total_ngn, "Virtual Card Top-up: $$amount_usd", "WEB", $_SERVER['HTTP_HOST'], 1);
            echo json_encode(['status' => 'success', 'message' => 'Card Funded Successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $res['message']]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient Balance']);
    }
}

if ($action === 'withdraw_card') {
    $card_ref = $_POST['card_ref'] ?? '';
    $ref_esc = mysqli_real_escape_string($connection_server, $card_ref);

    $c_q = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE reference='$ref_esc' AND username='$uname' LIMIT 1");
    $card = mysqli_fetch_assoc($c_q);

    if ($card && $card['balance_usd'] > 0) {
        $amt_usd = $card['balance_usd'];
        $amt_ngn = $amt_usd * $usd_rate;

        // In real world, we would call Chimoney to terminate/withdraw, but for this "Vanilla" implementation,
        // we'll simulate returning funds to NGN wallet as requested.
        mysqli_query($connection_server, "UPDATE sas_virtual_cards_v2 SET balance_usd = 0, status='terminated' WHERE id='".$card['id']."'");
        chargeOtherUser($uname, "credit", "virtual_card", "Card Liquidation", "WDR".time(), $card_ref, $amt_ngn, $amt_ngn, "Virtual Card Withdrawal: $$amt_usd returned to wallet", "WEB", $_SERVER['HTTP_HOST'], 1);

        echo json_encode(['status' => 'success', 'message' => 'Funds returned to NGN wallet']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No funds available on card']);
    }
}

if ($action === 'reactivate_card') {
    $card_ref = $_POST['card_ref'] ?? '';
    $u_q = mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE vendor_id='$vid' AND username='$uname' LIMIT 1");
    $u_r = mysqli_fetch_assoc($u_q);

    if ($u_r['balance'] >= 2000) { // Minimum threshold to allow reactivation
        $res = unfreezeVirtualCardV2($vid, $card_ref);
        echo json_encode($res);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Minimum NGN 2,000 balance required to reactivate.']);
    }
}
