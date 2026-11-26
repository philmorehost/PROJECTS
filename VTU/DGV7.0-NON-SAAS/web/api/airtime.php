<?php
require_once __DIR__ . '/../../func/bc-vtu-runtime.php';
bc_vtu_guard_hop();
require_once __DIR__ . '/../../func/bc-secure-session.php';
 bc_secure_session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With, X-App-Source");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ((!isset($api_post_info_from_app) || !is_array($api_post_info_from_app)) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "failed", "desc" => "Method not allowed"]);
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
		$purchase_method = "api";
		$get_api_post_info = json_decode(file_get_contents('php://input'), true);
		if (!is_array($get_api_post_info)) $get_api_post_info = $_POST;
	}

	$get_vendor_details = $select_vendor_table;
	$api_key_sanitized = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($get_api_post_info["api_key"] ?? "")), $vendor_id));
$purchase_method = bc_api_is_app_session(isset($api_post_info_from_app) && is_array($api_post_info_from_app)) ? "app" : "api";
	$get_user_detail_via_apikey = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . $get_vendor_details["id"] . "' && api_key='" . $api_key_sanitized . "' LIMIT 1");
	$get_logged_user_details = mysqli_fetch_array($get_user_detail_via_apikey);
	if (mysqli_num_rows($get_user_detail_via_apikey) == 1) {
		if (($get_logged_user_details["api_status"] == 1 || $purchase_method == "app") && (int)$get_logged_user_details["status"] === 1 && empty($get_logged_user_details["is_blocked"])) {

			$_SESSION["user_session"] = $get_logged_user_details["username"];

            // Handle Bulk
            $phone_input = $get_api_post_info["phone_number"] ?? $get_api_post_info["phone_no"] ?? "";
            $phone_array = array_unique(array_filter(explode(",", $phone_input)));
            $fixed_amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($get_api_post_info["amount"] ?? ""))));
            $fixed_network = $get_api_post_info["network"] ?? "";

            if (count($phone_array) > 1) {
                $__pin_enforced = ($purchase_method === "app") || !empty($GLOBALS['pin_required_for_api_purchase']);
                if ($__pin_enforced && !requireTransactionPin($get_vendor_details ?? array(), $get_logged_user_details, $get_api_post_info ?? array(), $__pin_error)) {
                    $api_json_response_encode = json_encode(["status" => "failed", "code" => "pin_required", "desc" => $__pin_error], true);
                } elseif (function_exists('isServiceEnabled') && !isServiceEnabled('airtime', (int)$get_logged_user_details["vendor_id"])) {
                    $api_json_response_encode = json_encode(["status" => "failed", "desc" => "Airtime service is currently unavailable."], true);
                } elseif (!is_numeric($fixed_amount) || !is_finite((float)$fixed_amount) || (float)$fixed_amount <= 0) {
                    $api_json_response_encode = json_encode(["status" => "failed", "desc" => "Enter a valid positive airtime amount."], true);
                } elseif (!is_numeric($get_logged_user_details["balance"] ?? null) || (float)$get_logged_user_details["balance"] <= 0) {
                    $api_json_response_encode = json_encode(["status" => "failed", "desc" => "Insufficient Wallet Balance"], true);
                } elseif (count($phone_array) > 200) {
                    $api_json_response_encode = json_encode(["status" => "failed", "desc" => "Maximum of 200 recipients allowed per bulk request."], true);
                } else {
                    $queue_items = array();
                    $invalid_phones = array();
                    foreach ($phone_array as $each_phone) {
                        $clean_phone = sanitize_phone_number(trim(strip_tags($each_phone)));
                        if ($clean_phone === '') continue;
                        if (function_exists('bc_valid_mobile_phone') ? !bc_valid_mobile_phone($clean_phone) : (preg_match('/^0[789][01]\d{8}$/', $clean_phone) !== 1)) {
                            $invalid_phones[] = $clean_phone;
                            continue;
                        }
                        $isp = (empty($fixed_network) || $fixed_network == "auto") ? identifyISP($clean_phone) : $fixed_network;
                        $queue_items[] = array(
                            "phone"  => $clean_phone,
                            "isp"    => $isp,
                            "amount" => $fixed_amount,
                        );
                    }
                    if (!empty($invalid_phones) || empty($queue_items)) {
                        $api_json_response_encode = json_encode(["status" => "failed", "desc" => "One or more phone numbers are invalid."], true);
                    } else {
                        $enqueue_result = bc_enqueue_bulk_batch($connection_server, $get_logged_user_details["vendor_id"], $get_logged_user_details["username"], $get_logged_user_details["id"], "airtime", $purchase_method, $queue_items);
                        $api_json_response_encode = json_encode([
                            "status" => "queued",
                            "desc" => "Bulk Airtime request received and queued for background processing.",
                            "batch_number" => $enqueue_result["batch_number"],
                            "count" => $enqueue_result["total"]
                        ]);
                    }
                }
            } else {
                include_once("../func/airtime.php");
                $api_json_response_encode = $json_response_encode ?? "";
            }

			alterUser($get_logged_user_details["username"], "last_login", date('Y-m-d H:i:s.u'));
			unset($_SESSION["user_session"]);
		}

		if ((int)$get_logged_user_details["status"] !== 1 || !empty($get_logged_user_details["is_blocked"])) {
			$api_json_response_encode = json_encode(["status" => "failed", "desc" => "Your account is currently suspended or blocked."], true);
		} elseif ($get_logged_user_details["api_status"] == 2 && $purchase_method != "app") {
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