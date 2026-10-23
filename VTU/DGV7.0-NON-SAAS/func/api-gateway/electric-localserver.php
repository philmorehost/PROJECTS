<?php
$web_electric_size_array = array("ekedc" => "ekedc", "eedc" => "eedc", "ikedc" => "ikedc", "jedc" => "jedc", "kedco" => "kedco", "ibedc" => "ibedc", "phed" => "phed", "aedc" => "aedc", "yedc" => "yedc", "bedc" => "bedc", "aba" => "aba", "kaedco" => "kaedco");
if (in_array($epp, array_keys($web_electric_size_array))) {
 $curl_url = "https://" . $api_detail["api_base_url"] . "/web/api/electric.php";
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
 $curl_postfields_data = json_encode(array("api_key" => $api_detail["api_key"], "type" => $type, "meter_number" => $meter_number, "provider" => $epp, "amount" => $amount), true);
 curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
 $curl_result = curl_exec($curl_request);
 $curl_json_result = function_exists('bc_gateway_json_decode') ? bc_gateway_json_decode($curl_result) : json_decode($curl_result, true);
 if(!is_array($curl_json_result)){ $curl_json_result = array(); }


 if (curl_errno($curl_request)) {
  $api_response = "failed";
  $api_response_text = 1;
  $api_response_description = "";
  $api_response_status = 3;
 }

 if (in_array($curl_json_result["status"], array("success"))) {
  $api_response = "successful";
  $api_response_token = $curl_json_result["token"];
  $api_response_token_unit = $curl_json_result["token_unit"];
  $api_response_meter_number = $curl_json_result["meter_number"];
  $api_response_reference = $curl_json_result["ref"];
  $api_response_text = $curl_json_result["status"];
  $api_response_description = $curl_json_result["response_desc"];
  $api_response_status = 1;
  mysqli_query($connection_server, "INSERT INTO sas_electric_purchaseds (vendor_id, reference, meter_provider, meter_type, username, meter_number, meter_token, token_unit) VALUES ('" . $get_logged_user_details["vendor_id"] . "', '$reference', '$epp', '$type', '" . $get_logged_user_details["username"] . "', '" . $api_response_meter_number . "', '" . $api_response_token . "', '" . $api_response_token_unit . "')");
 }

 if (in_array($curl_json_result["status"], array("pending"))) {
  $api_response = "pending";
  $api_response_token = $curl_json_result["token"];
  $api_response_token_unit = $curl_json_result["token_unit"];
  $api_response_meter_number = $meter_number;
  $api_response_reference = $curl_json_result["ref"];
  $api_response_text = $curl_json_result["status"];
  $api_response_description = $curl_json_result["response_desc"];
  $api_response_status = 2;
  mysqli_query($connection_server, "INSERT INTO sas_electric_purchaseds (vendor_id, reference, meter_provider, meter_type, username, meter_number, meter_token, token_unit) VALUES ('" . $get_logged_user_details["vendor_id"] . "', '$reference', '$epp', '$type', '" . $get_logged_user_details["username"] . "', '" . $api_response_meter_number . "', '" . $api_response_token . "', '" . $api_response_token_unit . "')");
 }

 if (in_array($curl_json_result["status"], array("failed"))) {
  $api_response = "failed";
  $api_response_text = $curl_json_result["status"];
  $api_response_description = "Transaction Failed | Meter No: " . $meter_number . " recharge failed";
  $api_response_status = 3;
 }
} else {
 //Electric size not available
 $api_response = "failed";
 $api_response_text = "";
 $api_response_description = "";
 $api_response_status = 3;
}
if (function_exists('bc_gateway_settle_purchase')) { bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, "Transaction Failed | no valid response from the upstream gateway", $curl_result ?? ""); }
?>