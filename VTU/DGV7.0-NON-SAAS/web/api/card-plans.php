<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

// Select Vendor Table
$vendor_id = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

if ($select_vendor_table) {

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

            $networks = ['mtn', 'airtel', 'glo', '9mobile'];
            $card_types = [
                'datacard' => 'sas_datacard_status',
                'rechargecard' => 'sas_rechargecard_status'
            ];

            $plans_response = ["CARD_PRINTING" => []];

            foreach ($networks as $network) {
                $network_label = strtoupper($network);
                if ($network == '9mobile') $network_label = '9MOBILE';

                $plans_response["CARD_PRINTING"][$network_label] = [];

                foreach ($card_types as $type_key => $status_table) {
                    $status_query = mysqli_query($connection_server, "SELECT * FROM $status_table WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='$network'");
                    $status = mysqli_fetch_array($status_query);

                    if ($status && !empty($status['api_id'])) {
                        $api_id = $status['api_id'];

                        $product_query = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && product_name='$network' LIMIT 1");
                        $product = mysqli_fetch_array($product_query);

                        if ($product) {
                            $price_query = mysqli_query($connection_server, "SELECT * FROM $price_table WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && api_id='$api_id' && product_id='".$product["id"]."'");

                            while ($plan_details = mysqli_fetch_assoc($price_query)) {
                                $card_type_label = ucwords($type_key);

                                $plans_response["CARD_PRINTING"][$network_label][] = [
                                    "ID" => $plan_details["val_1"] ?? '',
                                    "PRODUCT_CODE" => $plan_details["val_1"],
                                    "PRODUCT_NAME" => str_replace("_", " ", $plan_details["val_1"]),
                                    "CARD_TYPE" => $card_type_label,
                                    "CARD_TYPE_CODE" => $type_key,
                                    "AMOUNT" => $plan_details["val_2"],
                                    "DURATION" => ($plan_details["val_3"] ?? "0") . " Days"
                                ];
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
