<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

// Select Vendor Table
$vendor_id = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

if ($select_vendor_table) {

    // Support GET and POST
    $input = array_merge($_GET, $_POST);
    if (empty($input)) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    $api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? "")));
    $user_id = mysqli_real_escape_string($connection_server, trim(strip_tags($input["UserID"] ?? "")));

    if (!empty($api_key)) {
        $get_user_query = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . $select_vendor_table["id"] . "' && api_key='" . $api_key . "' LIMIT 1");
    } elseif (!empty($user_id)) {
        $get_user_query = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . $select_vendor_table["id"] . "' && username='" . $user_id . "' LIMIT 1");
    } else {
        echo json_encode(["status" => "failed", "desc" => "Authentication failed: Provide api_key or UserID"]);
        exit;
    }

    $get_logged_user_details = mysqli_fetch_array($get_user_query);
    $purchase_method = ((isset($api_post_info_from_app) && is_array($api_post_info_from_app)) || (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android')) ? "app" : "api";

    if (mysqli_num_rows($get_user_query) == 1) {
        if (($get_logged_user_details["api_status"] == 1 || $purchase_method == "app") && $get_logged_user_details["status"] == 1) {

            $account_level = $get_logged_user_details["account_level"];
            $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
            $price_table = $account_level_table_name_arrays[$account_level] ?? "sas_api_parameter_values";

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
                    // Only include network+type combos that are active in the status table
                    $status_query = mysqli_query($connection_server, "SELECT * FROM $status_table WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='$network_key' && status='1'");
                    $status = mysqli_fetch_array($status_query);

                    if ($status && !empty($status['api_id'])) {
                        $api_id = $status['api_id'];

                        // Only use APIs that are active
                        $api_check = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='$api_id' && api_type='$type_key' && status='1' LIMIT 1"));
                        if (!$api_check) continue;

                        // Try all possible product names in sas_products table
                        foreach ($product_names as $p_name) {
                            $product_query = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && product_name='$p_name' && status='1' LIMIT 1");
                            $product = mysqli_fetch_array($product_query);

                            if ($product) {
                                // Only fetch active plans (status = 1) with a valid price
                                $price_query = mysqli_query($connection_server, "SELECT * FROM $price_table WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && api_id='$api_id' && product_id='".$product["id"]."' && status='1' && val_2 > 0");

                                while ($plan_details = mysqli_fetch_assoc($price_query)) {
                                    $data_type_label = ucwords(str_replace("-", " ", $type_key));

                                    $plans_response["MOBILE_NETWORK"][$network_label][] = [
                                        "ID" => $plan_details["id"],
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
                }
            }

            echo json_encode($plans_response);

        } else {
            echo json_encode(["status" => "failed", "desc" => "API access not enabled for this account"]);
        }
    } else {
        echo json_encode(["status" => "failed", "desc" => "User not exists or invalid credentials"]);
    }
} else {
    echo json_encode(["status" => "failed", "desc" => "Website not registered or inactive"]);
}

mysqli_close($connection_server);
?>
