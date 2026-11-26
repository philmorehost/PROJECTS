<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_POST;
}
if (!is_array($input)) {
    $input = array();
}

$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}

$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags((string)($input["api_key"] ?? ''))), $vendor_id));

if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if ($check_user && mysqli_num_rows($check_user) == 1) {
    $user = mysqli_fetch_assoc($check_user);
    if ((int)($user['status'] ?? 0) !== 1 || !empty($user['is_blocked'])) {
        echo json_encode(["status" => "error", "message" => "Account is not active"]);
        exit;
    }
    if (!isServiceEnabled('manual_funding', $vendor_id)) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Manual bank funding is currently disabled."]);
        exit;
    }

    if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('fund_manual_app', $vendor_id . ':' . strtolower((string)$user['username']), 5, 600)) {
        http_response_code(429);
        echo json_encode(["status" => "error", "message" => "Too many manual funding requests. Please wait a few minutes before submitting another."]);
        exit;
    }

    $username = mysqli_real_escape_string($connection_server, $user['username']);
    $pending_q = mysqli_query($connection_server, "SELECT COUNT(*) AS cnt FROM sas_transactions WHERE vendor_id='$vendor_id' AND username='$username' AND product_unique_id='manual_funding' AND status='2'");
    $pending_row = $pending_q ? mysqli_fetch_assoc($pending_q) : null;
    if ((int)($pending_row['cnt'] ?? 0) >= 5) {
        http_response_code(429);
        echo json_encode(["status" => "error", "message" => "You already have 5 pending manual deposit requests. Wait for admin review before submitting another."]);
        exit;
    }

    $raw_amount = $input["amount"] ?? 0;
    if (!is_numeric($raw_amount)) {
        echo json_encode(["status" => "error", "message" => "Invalid deposit amount"]);
        exit;
    }
    $amount = round((float)$raw_amount, 2);
    $gateway_clean = substr(trim(strip_tags((string)($input["gateway"] ?? 'Manual Bank Deposit'))), 0, 64);
    if ($gateway_clean === '') $gateway_clean = 'Manual Bank Deposit';
    $gateway = mysqli_real_escape_string($connection_server, $gateway_clean);
    $reference = "MAN_" . time() . random_int(100, 999);
    $api_website = mysqli_real_escape_string($connection_server, $_SERVER["HTTP_HOST"] ?? 'APP');

    $q_limits = mysqli_query($connection_server, "SELECT min_amount, max_amount FROM sas_admin_payment_orders WHERE vendor_id='$vendor_id' LIMIT 1");
    $limits = $q_limits ? mysqli_fetch_assoc($q_limits) : null;
    $min_amt = isset($limits['min_amount']) && is_numeric($limits['min_amount']) ? max(1.0, (float)$limits['min_amount']) : 100.0;
    $max_amt = isset($limits['max_amount']) && is_numeric($limits['max_amount']) ? (float)$limits['max_amount'] : 1000000.0;

    if (!is_finite($amount) || $amount < $min_amt || $amount > $max_amt) {
        echo json_encode(["status" => "error", "message" => "Amount must be between NGN " . number_format($min_amt, 2) . " and NGN " . number_format($max_amt, 2)]);
        exit;
    }

    // Fetch the admin's manual bank deposit charge fee
    $admin_payment_q = mysqli_query($connection_server,
        "SELECT amount_charged FROM sas_admin_payments WHERE vendor_id='$vendor_id' LIMIT 1");
    $admin_payment = $admin_payment_q ? mysqli_fetch_assoc($admin_payment_q) : null;
    $charge_fee = round(max(0.0, (float)($admin_payment['amount_charged'] ?? 0)), 2);

    $balance_before = (float)($user['balance'] ?? 0);
    $balance_after  = $balance_before;

    $q = "INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status)
          VALUES ('$vendor_id', 'manual_funding', 'Wallet Funding', '$reference', '$username', '$amount', '$amount', '$balance_before', '$balance_after', 'Manual Funding Request: $gateway', 'APP', '$api_website', '2')";

    if (mysqli_query($connection_server, $q)) {
        $response = [
            "status" => "success",
            "reference" => $reference,
            "charge_fee" => $charge_fee
        ];

        if ($charge_fee > 0 && (float)$user['balance'] >= $charge_fee) {
            if (function_exists('bc_ensure_wallet_transactional_schema')) {
                bc_ensure_wallet_transactional_schema($connection_server);
            }
            $fee_ref = "FEE_" . time() . random_int(100, 999);
            $fee_deducted = chargeOtherUser(
                $user['username'], "debit", "manual_bank_charge", "Manual Deposit Fee",
                $fee_ref, "", $charge_fee, $charge_fee,
                "Service charge for Manual Bank Deposit: $reference",
                "APP", $_SERVER["HTTP_HOST"] ?? "APP", "1"
            );
            if ($fee_deducted === "success") {
                $response["message"] = "Funding request submitted. ₦" . number_format($charge_fee, 2) . " service charge deducted.";
            } else {
                $response["message"] = "Funding request submitted. Note: service charge deduction failed.";
            }
        } else {
            $response["message"] = "Funding request submitted successfully. Please wait for admin approval.";
        }

        echo json_encode($response);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to submit request."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
}
mysqli_close($connection_server);
