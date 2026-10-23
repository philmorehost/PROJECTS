<?php
// Automated Wallet Funding Webhook for Flutterwave
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

// 1. Get the payload
$body = file_get_contents("php://input");
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Empty payload"]);
    exit;
}

// 2. Identify Vendor by Host or Transaction Reference
$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

if (!$get_vendor) {
    // Attempt fallback via transaction reference
    $tx_data = $data['data'] ?? $data;
    $tx_ref = mysqli_real_escape_string($connection_server, $tx_data['tx_ref'] ?? '');
    if (!empty($tx_ref)) {
        $q_v = mysqli_query($connection_server, "SELECT vendor_id FROM sas_user_payment_checkouts WHERE reference='$tx_ref' LIMIT 1");
        if ($r_v = mysqli_fetch_assoc($q_v)) {
            $get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='".$r_v['vendor_id']."' LIMIT 1"));
        }
    }
}

if (!$get_vendor) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}
$vendor_id = $get_vendor['id'];

// 3. Get Gateway Settings
$get_gateway = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vendor_id' AND gateway_name='flutterwave' LIMIT 1"));
if (!$get_gateway) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Gateway not configured"]);
    exit;
}

$secret_hash = trim($get_gateway['encrypt_key'] ?? '');
$signature = $_SERVER['HTTP_VERIF_HASH'] ?? '';

// 4. Verify Signature (Security)
if (!empty($secret_hash)) {
    if (!$signature || ($signature !== $secret_hash)) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid signature"]);
        exit;
    }
}

// 5. Process Transaction
if ($data['status'] === 'successful' || $data['event'] === 'charge.completed') {
    // Standardize data from different FLW event types
    $tx_data = $data['data'] ?? $data;
    $tx_ref = mysqli_real_escape_string($connection_server, $tx_data['tx_ref']);
    $flw_id = mysqli_real_escape_string($connection_server, $tx_data['id']);
    // The verif-hash above is the primary control, but it is only checked when a merchant has
    // configured one. This cap is unconditional: the credit can never exceed the amount WE recorded
    // for the reference, so an unauthenticated (or replayed) callback cannot inflate it.
    $expected_amount = 0.0;
    $q_expected = mysqli_query($connection_server, "SELECT amount FROM sas_transactions WHERE vendor_id='$vendor_id' AND (reference='$tx_ref' OR api_reference='$flw_id') ORDER BY id DESC LIMIT 1");
    if ($q_expected && ($r_expected = mysqli_fetch_assoc($q_expected))) {
        $expected_amount = (float)$r_expected['amount'];
    }
    $amount = (float)$tx_data['amount'];
    if ($expected_amount > 0) {
        $amount = min($amount, $expected_amount);
    } else {
        error_log("SECURITY: Flutterwave webhook for unknown/already-settled reference - not credited. vendor=$vendor_id ref=$tx_ref");
        http_response_code(200);
        echo json_encode(["status" => "ignored", "message" => "Unknown reference"]);
        exit;
    }
    $customer_email = mysqli_real_escape_string($connection_server, $tx_data['customer']['email']);

    // Check if already successfully processed (status=1); pending (status=2) records from create-checkout are OK to overwrite
    $check_tx = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vendor_id' AND (reference='$tx_ref' OR api_reference='$flw_id') AND status=1 LIMIT 1");
    if (mysqli_num_rows($check_tx) == 0) {
        // Find user — first try checkout lookup, then fall back to email
        $username = "";
        $q_checkout = mysqli_query($connection_server, "SELECT username FROM sas_user_payment_checkouts WHERE vendor_id='$vendor_id' AND reference='$tx_ref' LIMIT 1");
        if ($r_checkout = mysqli_fetch_assoc($q_checkout)) {
            $username = $r_checkout['username'];
        } else {
            $get_user = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$vendor_id' AND email='$customer_email' LIMIT 1");
            if ($r_user = mysqli_fetch_assoc($get_user)) $username = $r_user['username'];
        }

        if (!empty($username)) {
            $fee_percent = (float)($get_gateway['percentage'] ?? 0);
            $discounted_amount = $amount - ($amount * ($fee_percent / 100));

            $desc = "Wallet Funding via Flutterwave (FLW ID: $flw_id)";

            $res = chargeOtherUser($username, "credit", "FLW", "Wallet Funding", $tx_ref, $flw_id, $amount, $discounted_amount, $desc, "APP", "flutterwave.com", 1);

            if ($res === "success") {
                // Mark the original pending transaction as successful
                mysqli_query($connection_server, "UPDATE sas_transactions SET status=1 WHERE vendor_id='$vendor_id' AND reference='$tx_ref' AND status=2");
                echo json_encode(["status" => "success", "message" => "Wallet funded"]);
            } else {
                echo json_encode(["status" => "error", "message" => "Charge failed: $res"]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "User not found for reference/email"]);
        }
    } else {
        echo json_encode(["status" => "success", "message" => "Transaction already processed"]);
    }
} else {
    echo json_encode(["status" => "ignored", "message" => "Transaction status not successful"]);
}

mysqli_close($connection_server);
