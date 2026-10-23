<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With, X-App-Source");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include("../../func/bc-connect.php");

// Identify Vendor
$v_id = resolveVendorID();
if ($v_id <= 0) {
    echo json_encode(["status" => "failed", "desc" => "Vendor not found or inactive"]);
    exit;
}
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$v_id' AND status=1 LIMIT 1"));
if (!$select_vendor_table) {
    echo json_encode(["status" => "failed", "desc" => "Vendor not found or inactive"]);
    exit;
}

$api_key = mysqli_real_escape_string($connection_server, $_GET['api_key'] ?? '');
if(empty($api_key)) exit(json_encode(["status" => "failed", "desc" => "Missing API Key"]));

$purchase_method = ((isset($api_post_info_from_app) && is_array($api_post_info_from_app)) || (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android')) ? "app" : "api";
$api_status_clause = ($purchase_method == "app") ? "" : "AND api_status=1";
$user_q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$v_id' AND api_key='$api_key' $api_status_clause AND status=1");
$user = mysqli_fetch_assoc($user_q);
if(!$user) exit(json_encode(["status" => "failed", "desc" => "Unauthorized"]));

// For chargeUser to work correctly, we might need some session-like variables or global user details
$get_logged_user_details = $user;

if (empty($_POST)) {
    $json_input = json_decode(file_get_contents('php://input'), true);
    if (is_array($json_input)) {
        $_POST = $json_input;
    }
}

// The transaction PIN belongs to a PERSON, not to a merchant api_key. External API callers
// ($purchase_method === 'api') serve that merchant's own customers, so they are exempt - requiring a
// PIN here made every API purchase fail with "Invalid transaction PIN.". The mobile app ("app") is
// a person, so it still has to prove the PIN.
if ($purchase_method !== 'api' && !requireTransactionPin($select_vendor_table, $get_logged_user_details, $_POST, $__pin_error)) {
    exit(json_encode(["status" => "failed", "desc" => $__pin_error]));
}

$network = mysqli_real_escape_string($connection_server, $_POST['network'] ?? '');
$service_type = mysqli_real_escape_string($connection_server, $_POST['service_type'] ?? 'data');
$data_type = mysqli_real_escape_string($connection_server, $_POST['data_type'] ?? '');
$plan_code = mysqli_real_escape_string($connection_server, $_POST['plan_code'] ?? '');
$quantity = (int)($_POST['quantity'] ?? 0);
$brand_name = isset($_POST['brand_name']) ? mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['brand_name']))) : '';
$custom_price = isset($_POST['custom_price']) ? mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['custom_price']))) : '';

if(empty($network) || empty($plan_code) || $quantity < 1 || $quantity > 40){
    exit(json_encode(["status" => "failed", "desc" => "Missing or invalid parameters. Max qty 40."]));
}

if ($service_type == 'data' && empty($data_type)) {
    exit(json_encode(["status" => "failed", "desc" => "Data type is required for data service."]));
}

$get_plan = mysqli_fetch_array(mysqli_query($connection_server, "SELECT p.*, prod.product_name FROM sas_databundle_plans p JOIN sas_products prod ON p.product_id = prod.id WHERE p.vendor_id='".$user["vendor_id"]."' AND prod.product_name='$network' AND p.service_type='$service_type' AND p.data_type='$data_type' AND p.plan_code='$plan_code' AND p.status=1"));

if(!$get_plan) exit(json_encode(["status" => "failed", "desc" => "Plan not found or inactive for $service_type - $data_type ($network)"]));

$unit_price = (float)($get_plan['price'] ?? 0);
if($unit_price <= 0) exit(json_encode(["status" => "failed", "desc" => "Plan price not configured."]));

$total_price = $unit_price * $quantity;

if($user['balance'] < $total_price) exit(json_encode(["status" => "failed", "desc" => "Insufficient balance."]));

$reference = substr(str_shuffle("12345678901234567890"), 0, 15);
$batch_reference = strtoupper(substr($service_type, 0, 1))."BATCH-".substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 10);
$description = "API Purchase of $quantity ".ucwords($service_type)." Bundle Cards (".strtoupper($get_plan['product_name'])." ".strtoupper($get_plan['plan_code']).")";

// Charge User (Note: chargeUser uses global $connection_server and session user_session, but since we are API, we should check if it works)
// The function chargeUser in func/bc-func.php uses $_SESSION["user_session"].
// We should temporarily set it if needed or refactor chargeUser.
// For now, let's try setting the session key.
session_start();
$_SESSION["user_session"] = $user['username'];

$debit = chargeUser("debit", $batch_reference, ucwords($service_type)." Bundle Card", $reference, "", $total_price, $total_price, $description, "API", $select_vendor_table['website_url'], 1);

if($debit === "success"){
    $get_sms = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_databundle_config WHERE vendor_id='".$user["vendor_id"]."' && network='".$get_plan['product_name']."' AND service_type='$service_type'"));
    $sms_number = $get_sms ? $get_sms['sms_to_number'] : "N/A";

    $cards = [];
    for($i = 0; $i < $quantity; $i++){
        $epin = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT)."-".str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT)."-".str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $sn = str_pad(random_int(0, 9999999), 7, '0', STR_PAD_LEFT);

        mysqli_query($connection_server, "INSERT INTO sas_databundle_cards (vendor_id, user_id, product_id, service_type, data_type, network, plan_name, validity, price, epin, serial_number, sms_number, brand_name, custom_price, status, batch_reference, date_sold) VALUES ('".$user["vendor_id"]."', '".$user["id"]."', '".$get_plan['product_id']."', '".$get_plan['service_type']."', '".$get_plan['data_type']."', '".$get_plan['product_name']."', '".$get_plan['plan_code']."', '".$get_plan['validity_days']."', '".$get_plan['price']."', '$epin', '$sn', '$sms_number', " . ($brand_name !== "" ? "'$brand_name'" : "NULL") . ", " . ($custom_price !== "" ? "'$custom_price'" : "NULL") . ", 'Sold', '$batch_reference', CURRENT_TIMESTAMP)");

        $cards[] = [
            "epin" => $epin,
            "sn" => $sn,
            "price" => $get_plan['price'],
            "brand_name" => $brand_name !== "" ? $brand_name : null,
            "custom_price" => $custom_price !== "" ? $custom_price : null
        ];
    }

    echo json_encode(["status" => "success", "desc" => "Cards generated successfully", "batch" => $batch_reference, "cards" => $cards]);
} else {
    echo json_encode(["status" => "failed", "desc" => "Transaction initiation failed."]);
}
?>