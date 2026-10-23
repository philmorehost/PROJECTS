<?php
header('Content-Type: application/json');
session_start();
include("../func/bc-admin-config.php");

if (!isset($_SESSION["admin_session"])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['plans']) || !isset($data['network']) || !isset($data['api_id'])) {
    echo json_encode(["success" => false, "message" => "Missing data"]);
    exit();
}

$vid = $get_logged_admin_details["id"];
$network = mysqli_real_escape_string($connection_server, $data['network']);
$api_id = mysqli_real_escape_string($connection_server, $data['api_id']);
$plans = $data['plans'];
$service_type = $data['type'] ?? '';

// Services where val_1 is the discount/commission % (no code-based plans)
$is_percent_service = in_array($service_type, ['airtime', 'electric', 'betting']);

// Get product_id for this network (self-heal: create the row if ProductSetUp.php was never visited for it yet)
$prod_q = mysqli_query($connection_server, "SELECT id FROM sas_products WHERE vendor_id='$vid' AND product_name='$network' LIMIT 1");
if(mysqli_num_rows($prod_q) == 0){
    mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('$vid', '$network', '1')");
    $prod_q = mysqli_query($connection_server, "SELECT id FROM sas_products WHERE vendor_id='$vid' AND product_name='$network' LIMIT 1");
}
$product_id = mysqli_fetch_assoc($prod_q)['id'];

$pricing_tables = ['sas_smart_parameter_values', 'sas_agent_parameter_values', 'sas_api_parameter_values'];

$count = 0;
foreach($plans as $plan){
    $code = mysqli_real_escape_string($connection_server, $plan['code']);
    $name = mysqli_real_escape_string($connection_server, $plan['name']);
    $smart_price = mysqli_real_escape_string($connection_server, $plan['smartPrice'] ?? $plan['calculatedPrice'] ?? $plan['price']);
    $agent_price = mysqli_real_escape_string($connection_server, $plan['agentPrice'] ?? $plan['calculatedPrice'] ?? $plan['price']);
    $api_price = mysqli_real_escape_string($connection_server, $plan['apiPrice'] ?? $plan['calculatedPrice'] ?? $plan['price']);
    $days = (int)($plan['days'] ?? 30);

    $level_map = [
        'sas_smart_parameter_values' => $smart_price,
        'sas_agent_parameter_values' => $agent_price,
        'sas_api_parameter_values' => $api_price
    ];

    foreach($level_map as $table => $price){
        if($is_percent_service){
            // For percent-based services (airtime, electric, betting), val_1 stores the discount %.
            // There is one row per product; update val_1 directly.
            $check = mysqli_query($connection_server, "SELECT id FROM $table WHERE vendor_id='$vid' AND api_id='$api_id' AND product_id='$product_id' LIMIT 1");
            if($check && mysqli_num_rows($check) == 0){
                mysqli_query($connection_server, "INSERT INTO $table (vendor_id, api_id, product_id, val_1, val_4, status) VALUES ('$vid', '$api_id', '$product_id', '$price', '$name', 1)");
            } else {
                mysqli_query($connection_server, "UPDATE $table SET val_1='$price', val_4='$name' WHERE vendor_id='$vid' AND api_id='$api_id' AND product_id='$product_id'");
            }
        } else {
            // For code-based services (data, cable, exam, bulk-sms), val_1=code, val_2=price.
            $check = mysqli_query($connection_server, "SELECT * FROM $table WHERE vendor_id='$vid' AND api_id='$api_id' AND product_id='$product_id' AND val_1='$code'");
            if(mysqli_num_rows($check) == 0){
                mysqli_query($connection_server, "INSERT INTO $table (vendor_id, api_id, product_id, val_1, val_2, val_3, val_4, status) VALUES ('$vid', '$api_id', '$product_id', '$code', '$price', '$days', '$name', 1)");
            } else {
                mysqli_query($connection_server, "UPDATE $table SET val_2='$price', val_3='$days', val_4='$name' WHERE vendor_id='$vid' AND api_id='$api_id' AND product_id='$product_id' AND val_1='$code'");
            }
        }
    }
    $count++;
}

echo json_encode(["success" => true, "message" => "Successfully saved $count plan(s) for $network"]);
?>
