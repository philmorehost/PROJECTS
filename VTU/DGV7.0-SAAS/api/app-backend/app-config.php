<?php
if (isset($_SERVER["HTTPS"]) && ($_SERVER["HTTPS"] == "on")) {
	$web_http_host = "https://" . $_SERVER["HTTP_HOST"];
} else {
	$web_http_host = "http://" . $_SERVER["HTTP_HOST"];
}

include($_SERVER["DOCUMENT_ROOT"] . "/func/bc-connect.php");
include($_SERVER["DOCUMENT_ROOT"] . "/func/bc-tables.php");

//Service Provider ID Array
$mtn_carrier_id_array = array("803", "702", "703", "704", "903", "806", "706", "707", "813", "810", "814", "816", "906", "916", "913", "903");
$airtel_carrier_id_array = array("701", "708", "802", "808", "812", "901", "902", "904", "907", "911", "912");
$glo_carrier_id_array = array("805", "705", "905", "807", "815", "811", "915");
$etisalat_carrier_id_array = array("809", "817", "818", "908", "909");

if (!function_exists('bc_app_backend_preauth_guard')) {
	function bc_app_backend_preauth_guard($vendor, $password, $username = '') {
		$app_ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
		if (function_exists('bc_is_rate_limited')) {
			if (bc_is_rate_limited('app_backend_ip', $app_ip, 20, 60)) {
				echo json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Too many attempts. Try again later."));
				exit;
			}
			$u_key = (int)($vendor['id'] ?? 0) . '|' . strtolower(trim((string)$username));
			if ($username !== '' && bc_is_rate_limited('app_backend_user', $u_key, 10, 300)) {
				echo json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Too many attempts. Try again later."));
				exit;
			}
		}
		if (!preg_match('/^[a-f0-9]{32}$/i', (string)$password)) {
			echo json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Invalid username or password"));
			exit;
		}
		if (function_exists('bc_user_login_otp_required') && bc_user_login_otp_required($vendor)) {
			echo json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Please update your app to the latest version to sign in. Email verification is now required."));
			exit;
		}
	}
}

?>