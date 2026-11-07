<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

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
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user = mysqli_fetch_assoc($check_user);
    $username = $user['username'];

    $amount = (float)($input["amount"] ?? 0);
    $gateway = mysqli_real_escape_string($connection_server, $input["gateway"] ?? 'Manual Bank Deposit');
    $reference = "MAN_" . time() . rand(100, 999);
    // sas_transactions.api_website is NOT NULL with no default. Every other writer fills it with the
    // host the request came through (see the chargeOtherUser() call below, and the existing rows in
    // the table). Omitting it made MySQL reject the INSERT outright, so the app showed
    // "Field 'api_website' doesn't have a default value" instead of filing the request.
    $api_website = mysqli_real_escape_string($connection_server, $_SERVER["HTTP_HOST"] ?? 'APP');

    if ($amount < 1) {
        echo json_encode(["status" => "error", "message" => "Invalid amount"]);
        exit;
    }

    // Fetch the admin's manual bank deposit charge fee
    $admin_payment = mysqli_fetch_assoc(mysqli_query($connection_server,
        "SELECT amount_charged FROM sas_admin_payments WHERE vendor_id='$vendor_id' LIMIT 1"));
    $charge_fee = (float)($admin_payment['amount_charged'] ?? 0);

    // sas_transactions.balance_before / balance_after are NOT NULL with no default.
    // This is a pending funding request (status 2): the wallet is not touched until an
    // admin approves it, so record the current balance for both.
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

        // If a charge fee is configured and the user has sufficient balance, deduct it immediately
        if ($charge_fee > 0 && (float)$user['balance'] >= $charge_fee) {
            $fee_ref = "FEE_" . time() . rand(100, 999);
            $fee_deducted = chargeOtherUser(
                $username, "debit", "manual_bank_charge", "Manual Deposit Fee",
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
        echo json_encode(["status" => "error", "message" => "Failed to submit request: " . mysqli_error($connection_server)]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
}
mysqli_close($connection_server);
