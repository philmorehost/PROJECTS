<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

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
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
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

    $username = mysqli_real_escape_string($connection_server, $user['username']);
    $amount = (float)($input["amount"] ?? 0);
    $gateway = mysqli_real_escape_string($connection_server, trim(strip_tags((string)($input["gateway"] ?? 'Manual Bank Deposit'))));
    $reference = "MAN_" . time() . rand(100, 999);
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
    $admin_payment = mysqli_fetch_assoc(mysqli_query($connection_server,
        "SELECT amount_charged FROM sas_admin_payments WHERE vendor_id='$vendor_id' LIMIT 1"));
    $charge_fee = (float)($admin_payment['amount_charged'] ?? 0);

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
            $fee_ref = "FEE_" . time() . rand(100, 999);
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
