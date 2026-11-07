<?php
header('Content-Type: application/json');
include("../../func/bc-connect.php");

$api_key = mysqli_real_escape_string($connection_server, $_GET['api_key'] ?? '');
if(empty($api_key)) exit(json_encode(["status" => "failed", "desc" => "Missing API Key"]));

$vendor_id = resolveVendorID();
$purchase_method = ((isset($api_post_info_from_app) && is_array($api_post_info_from_app)) || (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android')) ? "app" : "api";
$api_status_clause = ($purchase_method == "app") ? "" : "AND api_status=1";
$user_q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' $api_status_clause AND status=1");
$user = mysqli_fetch_assoc($user_q);
if(!$user) exit(json_encode(["status" => "failed", "desc" => "Unauthorized"]));

$vid = $user["vendor_id"];

$bundle_plans_sql = "SELECT p.*, prod.product_name FROM sas_databundle_plans p JOIN sas_products prod ON p.product_id = prod.id WHERE p.vendor_id='$vid' && p.status=1";
$bundle_plans_query = mysqli_query($connection_server, $bundle_plans_sql);

$response_data = [];

while($plan = mysqli_fetch_assoc($bundle_plans_query)){
    $stype = $plan['service_type'] ?? 'data';
    $dtype = $plan['data_type'];
    $pname = $plan['product_name'];
    $pcode = $plan['plan_code'];
    $price = (float)($plan['price'] ?? 0);

    if($price > 0){
        $response_data[strtoupper($pname)][] = [
            "PLAN_CODE" => $pcode,
            "SERVICE_TYPE" => $stype,
            "DATA_TYPE" => $dtype,
            "AMOUNT" => $price,
            "DURATION" => $plan['validity_days'] . " Days"
        ];
    }
}

echo json_encode(["status" => "success", "MOBILE_NETWORK" => $response_data]);
?>
