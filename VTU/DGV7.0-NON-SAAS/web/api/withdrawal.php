<?php session_start();
//header("Content-Type", "application/json");
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
	}

	$get_vendor_details = $select_vendor_table;
	$api_key_sanitized = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($get_api_post_info["api_key"])), $vendor_id));
	$get_user_detail_via_apikey = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . $get_vendor_details["id"] . "' && api_key='" . $api_key_sanitized . "' LIMIT 1");
	$get_logged_user_details = mysqli_fetch_array($get_user_detail_via_apikey);
	if (mysqli_num_rows($get_user_detail_via_apikey) == 1) {
		if (($get_logged_user_details["api_status"] == 1 || $purchase_method == "app") && $get_logged_user_details["status"] == 1) {

			$pin = $get_api_post_info['pin'] ?? '';
			if (empty($pin)) {
				echo json_encode(array("status" => "failed", "code" => "pin_required", "desc" => "Transaction PIN is required"), true);
				exit;
			}
			$sec29_pin_ok = function_exists('bc_verify_pin_secure')
				? bc_verify_pin_secure((string)$pin, $get_logged_user_details, 'withdrawal')
				: verifyUserPIN($pin, $get_logged_user_details);
			if (!$sec29_pin_ok) {
				echo json_encode(array("status" => "failed", "desc" => "Invalid Transaction PIN"), true);
				exit;
			}

			// §29 step-up (every auth mode — developer key AND app session alike):
			// the PIN alone no longer moves money from this API, matching the
			// website. Customers with an authenticator app prove a TOTP code;
			// everyone else answers an emailed one-time code. Apps drive the
			// two-call flow from the machine-readable "code" field:
			//   no code supplied -> "totp_required" (retry with totp_code) or
			//                       "otp_required" (code emailed; retry with otp_code)
			//   wrong code       -> "failed" with a generic desc.
			$sec29_totp_enrolled = (($get_logged_user_details['two_factor_type'] ?? 'none') === 'totp') && !empty($get_logged_user_details['totp_secret']);
			if ($sec29_totp_enrolled) {
				$totp_code = (string)($get_api_post_info['totp_code'] ?? ($get_api_post_info['otp_code'] ?? ''));
				if ($totp_code === '') {
					echo json_encode(array("status" => "failed", "code" => "totp_required", "desc" => "Enter the 6-digit code from your authenticator app and retry this request with totp_code."), true);
					exit;
				}
				$sec29_sf_err = function_exists('bc_stepup_actor_second_factor')
					? bc_stepup_actor_second_factor('sas_users', $get_logged_user_details, (string)$get_logged_user_details['username'], array('totp_code' => $totp_code, 'otp_code' => $totp_code), 'api_withdrawal')
					: 'Verification is unavailable.';
				if ($sec29_sf_err !== '') {
					echo json_encode(array("status" => "failed", "code" => "totp_invalid", "desc" => $sec29_sf_err), true);
					exit;
				}
			} elseif (function_exists('bc_verify_otp')) {
				$otp_code = (string)($get_api_post_info['otp_code'] ?? '');
				if ($otp_code === '') {
					if (function_exists('bc_stepup_send_email_otp')) {
						bc_stepup_send_email_otp((int)$get_vendor_details["id"], (string)$get_logged_user_details['username'], (string)$get_logged_user_details['email'], 'api_withdrawal', 'Withdrawal');
					}
					echo json_encode(array("status" => "failed", "code" => "otp_required", "desc" => "A verification code was sent to your email. Retry this request with otp_code."), true);
					exit;
				}
				if (!bc_verify_otp('stepup_api_withdrawal', (string)$get_logged_user_details['username'], (int)$get_vendor_details["id"], $otp_code)) {
					echo json_encode(array("status" => "failed", "code" => "otp_invalid", "desc" => "Invalid or expired verification code"), true);
					exit;
				}
			}

			if (!isServiceEnabled('payout')) {
				echo json_encode(array("status" => "failed", "desc" => "Payout service is currently offline. Please contact support."), true);
				exit;
			}

			if (!isServiceEnabled('withdraw')) {
				echo json_encode(array("status" => "failed", "desc" => "Wallet to Bank service is currently offline. Please contact support."), true);
				exit;
			}

			$action_function = 1;
			$_SESSION["user_session"] = $get_logged_user_details["username"];
			include_once("../func/bank-transfer.php");
			
			$api_json_response_encode = $json_response_encode;
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