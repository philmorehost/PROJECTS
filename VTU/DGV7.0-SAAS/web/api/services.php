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
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) != 1) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
    exit;
}
$user = mysqli_fetch_assoc($check_user);
$acc_level = $user['account_level'];
$acc_table = [1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values"][$acc_level];

// Fetch Enabled Services using centralized function
$all_possible_services = [
    'data', 'airtime', 'cable', 'electric', 'betting', 'exam', 'bulk_sms',
    'data_card', 'print_data', 'print_airtime', 'print_cable', 'print_electric',
    'print_exam', 'print_betting', 'recharge_card', 'bank_transfer', 'payout',
    'virtual_card', 'gift_card', 'crypto_hub', 'nin_card', 'bvn_verify'
];
$services = [];
foreach ($all_possible_services as $s_name) {
    if (isServiceEnabled($s_name, $vendor_id)) {
        $services[] = $s_name;
    }
}

$response_data = [
    "status" => "success",
    "services" => $services,
    "plans" => []
];

// Helper to fetch plans
function fetchCategoryPlans($vid, $acc_table, $api_type) {
    global $connection_server;
    $plans = [];
    $q = "SELECT p.product_name, v.val_1 as price, v.val_2 as code, v.val_3 as validity, v.val_4 as display_name
          FROM $acc_table v
          JOIN sas_products p ON v.product_id = p.id AND v.vendor_id = p.vendor_id
          JOIN sas_apis a ON v.api_id = a.id AND v.vendor_id = a.vendor_id
          WHERE v.vendor_id='$vid' AND a.api_type='$api_type' AND p.status=1 AND a.status=1";
    $res = mysqli_query($connection_server, $q);
    while($row = mysqli_fetch_assoc($res)) {
        $plans[] = $row;
    }
    return $plans;
}

if (in_array('data', $services)) {
    $response_data['plans']['data']['sme'] = fetchCategoryPlans($vendor_id, $acc_table, 'sme-data');
    $response_data['plans']['data']['gifting'] = fetchCategoryPlans($vendor_id, $acc_table, 'cg-data');
    $response_data['plans']['data']['direct'] = fetchCategoryPlans($vendor_id, $acc_table, 'dd-data');
    $response_data['plans']['data']['shared'] = fetchCategoryPlans($vendor_id, $acc_table, 'shared-data');
}

if (in_array('airtime', $services)) {
    $response_data['plans']['airtime'] = fetchCategoryPlans($vendor_id, $acc_table, 'airtime');
}

if (in_array('cable', $services)) {
    $response_data['plans']['cable'] = fetchCategoryPlans($vendor_id, $acc_table, 'cable');
}

if (in_array('electric', $services)) {
    $response_data['plans']['electric'] = fetchCategoryPlans($vendor_id, $acc_table, 'electric');
}

if (in_array('exam', $services)) {
    $response_data['plans']['exam'] = fetchCategoryPlans($vendor_id, $acc_table, 'exam');
}

if (in_array('gift_card', $services)) {
    $products = [];
    $q = mysqli_query($connection_server, "SELECT v.reloadly_product_id, v.product_name, v.logo_url, g.logo_url as global_logo, v.vendor_markup, v.category_name, g.currency_code
        FROM sas_vendor_giftcard_products v
        LEFT JOIN sas_global_giftcard_products g ON TRIM(CAST(v.reloadly_product_id AS CHAR)) = TRIM(CAST(g.reloadly_product_id AS CHAR))
        WHERE v.vendor_id='$vendor_id' AND v.status=1 ORDER BY v.product_name ASC");
    while ($r = mysqli_fetch_assoc($q)) {
        $products[] = [
            "id" => $r['reloadly_product_id'],
            "name" => $r['product_name'],
            "logo" => $r['logo_url'] ?: $r['global_logo'],
            "markup" => $r['vendor_markup'],
            "category" => $r['category_name'],
            "currency" => $r['currency_code'] ?: 'USD'
        ];
    }
    $response_data['plans']['gift_card'] = $products;
}

if (in_array('virtual_card', $services)) {
    $v_products = [];
    $q_v = mysqli_query($connection_server, "SELECT g.* FROM sas_global_virtual_card_products g JOIN sas_vendor_virtual_card_products v ON g.chimoney_product_id = v.chimoney_product_id WHERE v.vendor_id='$vendor_id' AND v.status=1");
    while ($p = mysqli_fetch_assoc($q_v)) {
        $v_products[] = [
            "id" => $p['chimoney_product_id'],
            "name" => $p['name'],
            "logo" => $p['logo_url'],
            "currency" => $p['currency']
        ];
    }
    $response_data['plans']['virtual_card'] = $v_products;
}

echo json_encode($response_data);
mysqli_close($connection_server);
