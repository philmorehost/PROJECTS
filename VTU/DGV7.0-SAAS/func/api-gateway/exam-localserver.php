<?php
$exam_service_provider_alter_code = array("waec" => "waec", "neco" => "neco", "nabteb" => "nabteb", "jamb" => "jamb");

// Default to a failure. Every branch below may overwrite this, but if none of them runs - an HTML
// body from the wrong host, an unexpected status value - the purchase must still be reported as
// failed. Leaving $api_response null made the caller skip BOTH its success and its refund branch,
// so the customer was debited with no PIN and no refund.
$api_response = "failed";
$api_response_text = "Unknown Error";
$api_response_description = "Transaction Failed";
$api_response_status = 3;
$curl_request = null;

if (in_array($product_name, array_keys($exam_service_provider_alter_code))) {

	if (!empty($quantity)) {
		// api_base_url is admin-typed and may already carry a scheme; strip it so we never build
		// "https://https://..." and fail DNS.
		$exam_local_host = rtrim(preg_replace('#^https?://#i', '', strtolower(trim($api_detail["api_base_url"]))), "/");
		$curl_url = "https://" . $exam_local_host . "/web/api/exam.php";
		$curl_request = curl_init($curl_url);
		curl_setopt($curl_request, CURLOPT_POST, true);
		curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
		$curl_http_headers = array(
			"Content-Type: application/json",
		);
		curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
		$curl_postfields_data = json_encode(array("api_key" => $api_detail["api_key"], "type" => $product_name, "quantity" => $quantity), true);
		curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
		$curl_result = curl_exec($curl_request);
  $curl_json_result = function_exists('bc_gateway_json_decode') ? bc_gateway_json_decode($curl_result) : json_decode($curl_result, true);
		if(!is_array($curl_json_result)){ $curl_json_result = array(); }
		// A non-JSON body (e.g. the 404 HTML page a wrong host returns) leaves $json_status null,
		// so none of the branches below run and the failure defaults set at the top of this file
		// stand - which is what makes the caller refund instead of keeping the customer's money.
		$json_status = isset($curl_json_result["status"]) ? $curl_json_result["status"] : null;
		

		if (curl_errno($curl_request)) {
			$api_response = "failed";
			$api_response_description = "Connection error: " . preg_replace('/\s+/', ' ', (string)curl_error($curl_request));
			$api_response_status = 3;
		}

		if (in_array($json_status, array("success"))) {
			$api_response = "successful";
			$api_response_reference = isset($curl_json_result["ref"]) ? $curl_json_result["ref"] : "";
			$api_response_text = $json_status;
			$api_response_description = isset($curl_json_result["response_desc"]) ? $curl_json_result["response_desc"] : "Transaction Successful";
			$api_response_status = 1;
		}

		if (in_array($json_status, array("pending"))) {
			$api_response = "pending";
			$api_response_reference = isset($curl_json_result["ref"]) ? $curl_json_result["ref"] : "";
			$api_response_text = $json_status;
			$api_response_description = isset($curl_json_result["response_desc"]) ? $curl_json_result["response_desc"] : "Transaction Pending";
			$api_response_status = 2;
		}

		if (in_array($json_status, array("failed"))) {
			$api_response = "failed";
			$api_response_text = $json_status;
			$api_response_description = "Transaction Failed";
			$api_response_status = 3;
		}
	} else {
		//Exam size not available
		$api_response = "failed";
		$api_response_text = "";
		$api_response_description = "";
		$api_response_status = 3;
	}
} else {
	//Service not available
	$api_response = "failed";
	$api_response_text = "";
	$api_response_description = "Service not available";
	$api_response_status = 3;
}
if ($curl_request) {
	if (function_exists('bc_gateway_settle_purchase')) { bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, "Transaction Failed | no valid response from the upstream gateway", $curl_result ?? ""); }
}
?>