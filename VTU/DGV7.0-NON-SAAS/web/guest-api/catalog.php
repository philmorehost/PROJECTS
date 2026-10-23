<?php
/**
 * Guest catalog endpoint — GET/POST ?service=airtime|data|cable|electric|exam|betting
 * Guest-safe equivalent of web/api/{airtime,data,cable,electric,exam,betting}-plans.php,
 * with the api_key/UserID auth block removed (no guest account exists) and pricing fixed
 * to the retail tier (GUEST_PRICE_TABLE).
 */
include_once(__DIR__ . "/guest-bootstrap.php");

$vendor = guest_resolve_vendor();
$vendor_id = $vendor['id'];

guest_security_gate($vendor_id, "guest_catalog", 60, 60);

$input = array_merge($_GET, $_POST);
$service = strtolower(trim(strip_tags($input['service'] ?? '')));

$price_table = GUEST_PRICE_TABLE;

switch ($service) {

    case 'airtime': {
        $networks = ['mtn', 'airtel', 'glo', '9mobile'];
        $plans_response = ["AIRTIME_VTU" => []];
        foreach ($networks as $network) {
            $network_label = strtoupper($network);
            if ($network == '9mobile') $network_label = '9MOBILE';

            $resolved = guest_resolve_enabled_api($vendor_id, 'sas_airtime_status', $network, 'airtime');
            if (!$resolved['ok']) continue;
            $product = guest_product_row($vendor_id, $network);
            if ($product) {
                $price = guest_price_percent($vendor_id, $resolved['api_detail']['id'], $product['id']);
                if ($price) {
                    $plans_response["AIRTIME_VTU"][$network_label] = [
                        "PRODUCT_CODE" => $product["product_name"],
                        "DISCOUNT_PERCENT" => toDecimal($price["val_1"], 2) . "%"
                    ];
                }
            }
        }
        guest_json($plans_response);
    }

    case 'data': {
        $networks_map = [
            'mtn' => ['mtn', 'mtn-data'],
            'airtel' => ['airtel', 'airtel-data'],
            'glo' => ['glo', 'glo-data'],
            '9mobile' => ['9mobile', '9mobile-data', 'etisalat', 'etisalat-data']
        ];
        $data_types = [
            'shared-data' => 'sas_shared_data_status',
            'sme-data' => 'sas_sme_data_status',
            'cg-data' => 'sas_cg_data_status',
            'dd-data' => 'sas_dd_data_status'
        ];
        $plans_response = ["MOBILE_NETWORK" => []];

        foreach ($networks_map as $network_key => $product_names) {
            $network_label = strtoupper($network_key);
            if ($network_key == '9mobile') $network_label = '9MOBILE';
            $plans_response["MOBILE_NETWORK"][$network_label] = [];

            foreach ($data_types as $type_key => $status_table) {
                $resolved = guest_resolve_enabled_api($vendor_id, $status_table, $network_key, $type_key);
                if (!$resolved['ok']) continue;
                $api_id = $resolved['api_detail']['id'];

                global $connection_server;
                foreach ($product_names as $p_name) {
                    $product = guest_product_row($vendor_id, $p_name);
                    if (!$product) continue;

                    $price_query = mysqli_query($connection_server, "SELECT * FROM $price_table WHERE vendor_id='" . (int)$vendor_id . "' && api_id='" . (int)$api_id . "' && product_id='" . $product["id"] . "' && status='1' && val_2 > 0");
                    while ($plan_details = mysqli_fetch_assoc($price_query)) {
                        $data_type_label = ucwords(str_replace("-", " ", $type_key));
                        $plans_response["MOBILE_NETWORK"][$network_label][] = [
                            "ID" => $plan_details["val_1"] ?? '',
                            "PRODUCT_CODE" => $plan_details["val_1"],
                            "PRODUCT_NAME" => !empty($plan_details["val_4"]) ? $plan_details["val_4"] : str_replace("_", " ", $plan_details["val_1"]),
                            "DATA_TYPE" => $data_type_label,
                            "DATA_TYPE_CODE" => $type_key,
                            "AMOUNT" => $plan_details["val_2"],
                            "DURATION" => $plan_details["val_3"] . " Days"
                        ];
                    }
                }
            }
        }
        guest_json($plans_response);
    }

    case 'cable': {
        $cables = ['startimes', 'dstv', 'gotv', 'showmax'];
        $plans_response = ["CABLE_SUBSCRIPTION" => []];
        global $connection_server;
        foreach ($cables as $cable) {
            $cable_label = strtoupper($cable);
            $plans_response["CABLE_SUBSCRIPTION"][$cable_label] = [];
            $resolved = guest_resolve_enabled_api($vendor_id, 'sas_cable_status', $cable, 'cable');
            if ($resolved['ok']) {
                $product = guest_product_row($vendor_id, $cable);
                if ($product) {
                    $price_query = mysqli_query($connection_server, "SELECT * FROM $price_table WHERE vendor_id='" . (int)$vendor_id . "' && api_id='" . (int)$resolved['api_detail']['id'] . "' && product_id='" . $product["id"] . "'");
                    while ($plan_details = mysqli_fetch_assoc($price_query)) {
                        $plans_response["CABLE_SUBSCRIPTION"][$cable_label][] = [
                            "ID" => $plan_details["val_1"] ?? '',
                            "PACKAGE" => $plan_details["val_1"],
                            "AMOUNT" => $plan_details["val_2"]
                        ];
                    }
                }
            }
        }
        guest_json($plans_response);
    }

    case 'electric': {
        $providers = ['aedc', 'bedc', 'eedc', 'ekedc', 'ibedc', 'ikedc', 'jedc', 'kaedc', 'kaedco', 'kedco', 'phed', 'yedc'];
        $plans_response = ["ELECTRIC_PAYMENT" => []];
        foreach ($providers as $provider) {
            $provider_label = strtoupper($provider);
            $resolved = guest_resolve_enabled_api($vendor_id, 'sas_electric_status', $provider, 'electric');
            if ($resolved['ok']) {
                $product = guest_product_row($vendor_id, $provider);
                if ($product) {
                    $price = guest_price_percent($vendor_id, $resolved['api_detail']['id'], $product['id']);
                    if ($price) {
                        $plans_response["ELECTRIC_PAYMENT"][$provider_label] = [
                            "PROVIDER_CODE" => $product["product_name"],
                            "DISCOUNT_PERCENT" => toDecimal($price["val_1"], 2) . "%"
                        ];
                    }
                }
            }
        }
        guest_json($plans_response);
    }

    case 'exam': {
        $exams = ['waec', 'neco', 'nabteb', 'jamb'];
        $plans_response = ["EXAM_PIN" => []];
        global $connection_server;
        foreach ($exams as $exam) {
            $exam_label = strtoupper($exam);
            $plans_response["EXAM_PIN"][$exam_label] = [];
            $resolved = guest_resolve_enabled_api($vendor_id, 'sas_exam_status', $exam, 'exam');
            if ($resolved['ok']) {
                $product = guest_product_row($vendor_id, $exam);
                if ($product) {
                    $price_query = mysqli_query($connection_server, "SELECT * FROM $price_table WHERE vendor_id='" . (int)$vendor_id . "' && api_id='" . (int)$resolved['api_detail']['id'] . "' && product_id='" . $product["id"] . "'");
                    while ($plan_details = mysqli_fetch_assoc($price_query)) {
                        $plans_response["EXAM_PIN"][$exam_label][] = [
                            "ID" => $plan_details["val_1"] ?? '',
                            "EXAM_TYPE" => $plan_details["val_1"],
                            "AMOUNT" => $plan_details["val_2"]
                        ];
                    }
                }
            }
        }
        guest_json($plans_response);
    }

    case 'betting': {
        $betting_providers = ['msport', 'naijabet', 'nairabet', 'bet9ja-agent', 'betland', 'betlion', 'supabet', 'bet9ja', 'bangbet', 'betking', '1xbet', 'betway', 'merrybet', 'mlotto', 'western-lotto', 'hallabet', 'green-lotto'];
        $plans_response = ["BETTING_PROVIDERS" => []];
        global $connection_server;
        foreach ($betting_providers as $provider) {
            $resolved = guest_resolve_enabled_api($vendor_id, 'sas_betting_status', $provider, 'betting');
            if ($resolved['ok']) {
                $plans_response["BETTING_PROVIDERS"][] = [
                    "provider_code" => $provider,
                    "provider_name" => ucwords(str_replace(["-", "_"], " ", $provider))
                ];
            }
        }
        guest_json($plans_response);
    }

    default:
        guest_fail("Unknown or missing service. Use one of: airtime, data, cable, electric, exam, betting");
}
