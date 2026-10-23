<?php
/**
 * Guest customer/meter/bookmaker verification — POST/GET ?service=cable|electric|betting
 * Guest-safe equivalent of web/api/verify-{cable,electric,betting}.php's action_function==3
 * branch in web/func/{cable,electric,betting}.php, with wallet/account-level branching removed
 * (guest pricing is fixed — verify doesn't need a price at all, only the customer lookup).
 */
include_once(__DIR__ . "/guest-bootstrap.php");

$vendor = guest_resolve_vendor();
$vendor_id = $vendor['id'];
guest_security_gate($vendor_id, "guest_verify", 20, 60);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($input)) $input = array_merge($_GET, $_POST);

$service = strtolower(trim(strip_tags($input['service'] ?? '')));

switch ($service) {

    case 'cable': {
        $isp = strtolower(trim(strip_tags($input['type'] ?? $input['provider'] ?? '')));
        $iuc_no = sanitize_phone_number(trim(strip_tags($input['iuc_number'] ?? $input['iuc_no'] ?? '')));
        $quantity = trim(strip_tags($input['package'] ?? $input['plan_code'] ?? ''));

        if (empty($iuc_no) || !is_numeric($iuc_no) || empty($isp) || empty($quantity)) {
            guest_fail("Incomplete Parameters");
        }
        if (!in_array($isp, ['startimes', 'dstv', 'gotv', 'showmax'])) {
            guest_fail("Invalid cable type");
        }

        $resolved = guest_resolve_enabled_api($vendor_id, 'sas_cable_status', $isp, 'cable');
        if (!$resolved['ok']) guest_fail($resolved['error']);
        $api_detail = $resolved['api_detail'];

        $product = guest_product_row($vendor_id, $isp);
        $status_row = guest_status_row($vendor_id, 'sas_cable_status', $isp);
        if (!$product || $product['status'] != 1 || !$status_row || $status_row['status'] != 1) {
            guest_fail("Product Locked");
        }

        $gw = guest_gateway_filename('verify', 'cable', $api_detail['api_base_url']);
        $res = guest_run_gateway('verify', $gw, ['api_detail' => $api_detail, 'isp' => $isp, 'product_name' => $isp, 'iuc_no' => $iuc_no, 'quantity' => $quantity]);
        if (in_array($res['api_response'], ['successful', 'pending'])) {
            // Cable verify gateways return the smartcard owner's name either in a dedicated
            // customer_name var or (most of them) as the description itself — surface it as
            // customer_name so the app's "Verified" card can show WHO owns the IUC number,
            // matching the electric/betting branches below.
            guest_json([
                "status" => "success",
                "desc" => $res['api_response_description'],
                "customer_name" => $res['api_response_customer_name'] ?? $res['api_response_description'],
                "customer_address" => $res['api_response_customer_address']
            ]);
        }
        guest_fail("Error: Unable to verify customer");
    }

    case 'electric': {
        $epp = strtolower(trim(strip_tags($input['provider'] ?? '')));
        $type = strtolower(trim(strip_tags($input['type'] ?? '')));
        $meter_number = sanitize_phone_number(trim(strip_tags($input['meter_number'] ?? $input['meter_no'] ?? '')));

        if (empty($meter_number) || !is_numeric($meter_number) || empty($epp) || empty($type)) {
            guest_fail("Incomplete Parameters");
        }
        $electric_types = ["ekedc", "eedc", "ikedc", "jedc", "kedco", "ibedc", "phed", "aedc", "yedc", "bedc", "aba", "kaedco"];
        if (!in_array($epp, $electric_types)) {
            guest_fail("Invalid electric type");
        }

        $resolved = guest_resolve_enabled_api($vendor_id, 'sas_electric_status', $epp, 'electric');
        if (!$resolved['ok']) guest_fail($resolved['error']);
        $api_detail = $resolved['api_detail'];

        $product = guest_product_row($vendor_id, $epp);
        $status_row = guest_status_row($vendor_id, 'sas_electric_status', $epp);
        if (!$product || $product['status'] != 1 || !$status_row || $status_row['status'] != 1) {
            guest_fail("Product Locked");
        }

        $gw = guest_gateway_filename('verify', 'electric', $api_detail['api_base_url']);
        $res = guest_run_gateway('verify', $gw, ['api_detail' => $api_detail, 'epp' => $epp, 'product_name' => $epp, 'meter_number' => $meter_number, 'type' => $type]);
        if (in_array($res['api_response'], ['successful', 'pending'])) {
            guest_json([
                "status" => "success",
                "desc" => $res['api_response_description'],
                "customer_name" => $res['api_response_customer_name'] ?? $res['api_response_description'],
                "customer_address" => $res['api_response_customer_address']
            ]);
        }
        guest_fail("Error: Unable to verify customer");
    }

    case 'betting': {
        $epp = strtolower(trim(strip_tags($input['provider'] ?? '')));
        $customer_id = sanitize_phone_number(trim(strip_tags($input['customer_id'] ?? '')));

        if (empty($customer_id) || !is_numeric($customer_id) || empty($epp)) {
            guest_fail("Incomplete Parameters");
        }
        $betting_providers = ['msport', 'naijabet', 'nairabet', 'bet9ja-agent', 'betland', 'betlion', 'supabet', 'bet9ja', 'bangbet', 'betking', '1xbet', 'betway', 'merrybet', 'mlotto', 'western-lotto', 'hallabet', 'green-lotto'];
        if (!in_array($epp, $betting_providers)) {
            guest_fail("Invalid betting type");
        }

        $resolved = guest_resolve_enabled_api($vendor_id, 'sas_betting_status', $epp, 'betting');
        if (!$resolved['ok']) guest_fail($resolved['error']);
        $api_detail = $resolved['api_detail'];

        $product = guest_product_row($vendor_id, $epp);
        $status_row = guest_status_row($vendor_id, 'sas_betting_status', $epp);
        if (!$product || $product['status'] != 1 || !$status_row || $status_row['status'] != 1) {
            guest_fail("Product Locked");
        }

        $gw = guest_gateway_filename('verify', 'betting', $api_detail['api_base_url']);
        $res = guest_run_gateway('verify', $gw, ['api_detail' => $api_detail, 'epp' => $epp, 'product_name' => $epp, 'customer_id' => $customer_id]);
        if (in_array($res['api_response'], ['successful', 'pending'])) {
            guest_json([
                "status" => "success",
                "desc" => $res['api_response_description'],
                "customer_name" => $res['api_response_description'],
                "customer_address" => $res['api_response_customer_address']
            ]);
        }
        guest_fail("Error: Unable to verify customer");
    }

    default:
        guest_fail("Unknown or missing service. Use one of: cable, electric, betting");
}
