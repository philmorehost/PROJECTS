<?php session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With, X-App-Source");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include_once("../../func/bc-connect.php");

//Select Vendor Table
$vendor_id = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if ($select_vendor_table) {
	$api_json_response_encode = "";
	if (isset($api_post_info_from_app) && is_array($api_post_info_from_app)) {
		$purchase_method = "app";
		$get_api_post_info = $api_post_info_from_app;
	} else {
		$purchase_method = (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android') ? "app" : "api";
		$get_api_post_info = json_decode(file_get_contents('php://input'), true);
		if (empty($get_api_post_info)) $get_api_post_info = $_REQUEST;
	}

	$get_vendor_details = $select_vendor_table;
	$api_key_sanitized = mysqli_real_escape_string($connection_server, trim(strip_tags($get_api_post_info["api_key"] ?? "")));
	$get_user_detail_via_apikey = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . $get_vendor_details["id"] . "' && api_key='" . $api_key_sanitized . "' LIMIT 1");
	$get_logged_user_details = mysqli_fetch_array($get_user_detail_via_apikey);
	if (mysqli_num_rows($get_user_detail_via_apikey) == 1) {
		if (($get_logged_user_details["api_status"] == 1 || $purchase_method == "app") && $get_logged_user_details["status"] == 1) {

			$_SESSION["user_session"] = $get_logged_user_details["username"];

            // Handle Bulk
            $phone_input = $get_api_post_info["phone_number"] ?? $get_api_post_info["phone_no"] ?? "";
            $phone_array = array_unique(array_filter(explode(",", $phone_input)));
            $fixed_amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($get_api_post_info["amount"] ?? ""))));
            $fixed_network = $get_api_post_info["network"] ?? "";

            if (count($phone_array) > 1) {
                $queue_items = array();
                foreach ($phone_array as $each_phone) {
                    $isp = (empty($fixed_network) || $fixed_network == "auto") ? identifyISP($each_phone) : $fixed_network;
                    $queue_items[] = array(
                        "phone"  => sanitize_phone_number(trim(strip_tags($each_phone))),
                        "isp"    => $isp,
                        "amount" => $fixed_amount,
                    );
                }
                $enqueue_result = bc_enqueue_bulk_batch($connection_server, $get_logged_user_details["vendor_id"], $get_logged_user_details["username"], $get_logged_user_details["id"], "airtime", $purchase_method, $queue_items);
                $api_json_response_encode = json_encode([
                    "status" => "queued",
                    "desc" => "Bulk Airtime request received and queued for background processing.",
                    "batch_number" => $enqueue_result["batch_number"],
                    "count" => $enqueue_result["total"]
                ]);
            } else {
                include_once("../func/airtime.php");
                $api_json_response_encode = $json_response_encode ?? "";
            }

			alterUser($get_logged_user_details["username"], "last_login", date('Y-m-d H:i:s.u'));
			unset($_SESSION["user_session"]);
		}

		if ($get_logged_user_details["api_status"] == 2 && $purchase_method != "app") {
			//API approval needed, Contact Admin
			$json_response_array = array("status" => "failed", "desc" => "API approval needed, Contact Admin");
			$api_json_response_encode = json_encode($json_response_array, true);
		}
	} else {
		//User not exists
		$json_response_array = array("status" => "failed", "desc" => "User not exists");
		$api_json_response_encode = json_encode($json_response_array, true);
	}
} else {
	//Website not registered
	$json_response_array = array("status" => "failed", "desc" => "Website not registered");
	$api_json_response_encode = json_encode($json_response_array, true);
}

if (!isset($api_post_info_from_app) || !is_array($api_post_info_from_app)) {
	echo $api_json_response_encode;
}

mysqli_close($connection_server);
?>