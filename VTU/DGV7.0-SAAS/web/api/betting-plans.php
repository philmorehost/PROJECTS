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
    $purchase_method = (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android') ? "app" : "api";

    if (mysqli_num_rows($get_user_query) == 1) {
        if (($get_logged_user_details["api_status"] == 1 || $purchase_method == "app") && $get_logged_user_details["status"] == 1) {

            $betting_providers = ['msport', 'naijabet', 'nairabet', 'bet9ja-agent', 'betland', 'betlion', 'supabet', 'bet9ja', 'bangbet', 'betking', '1xbet', 'betway', 'merrybet', 'mlotto', 'western-lotto', 'hallabet', 'green-lotto'];
            $plans_response = ["BETTING_PROVIDERS" => []];

            foreach ($betting_providers as $provider) {
                $status_query = mysqli_query($connection_server, "SELECT * FROM sas_betting_status WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='$provider'");
                $status = mysqli_fetch_array($status_query);

                if ($status && !empty($status['api_id'])) {
                    $api_check = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $status['api_id'] . "' && api_type='betting' && status=1 LIMIT 1");
                    if (mysqli_num_rows($api_check) > 0) {
                        $plans_response["BETTING_PROVIDERS"][] = [
                            "provider_code" => $provider,
                            "provider_name" => ucwords(str_replace(["-", "_"], " ", $provider))
                        ];
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
