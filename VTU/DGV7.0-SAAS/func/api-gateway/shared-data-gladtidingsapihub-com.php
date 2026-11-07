<?php
$data_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "9mobile" => "9mobile");
if (in_array($product_name, array_keys($data_service_provider_alter_code))) {
 if ($product_name == "mtn") {
  $net_id = "1";
  $web_data_size_array = array("1gb_awoof_data" => "382","3.5gb_awoof_data" => "383", "15gb_awoof_data" => "384","500mb_data_coupons" => "368", "1gb_data_coupons" => "370", "2gb_data_coupons" => "371", "3gb_data_coupons" => "372", "5gb_data_coupons" => "373");
 } else {
  if ($product_name == "airtel") {
   $net_id = "4";
   $web_data_size_array = array("300mb_awoof_data" => "375", "1gb_awoof_data" => "376", "2gb_awoof_data" => "377", "3gb_awoof_data" => "378", "10gb_awoof_data" => "380", "15gb_awoof_data" => "381");
  } else {
   if ($product_name == "glo") {
    $net_id = "2";
    $web_data_size_array = array("1gb_awoof_data" => "385", "2gb_awoof_data" => "386", "3.5gb_awoof_data" => "387","15gb_awoof_data" => "388");
   } else {
    if ($product_name == "9mobile") {
     $net_id = "3";
     $web_data_size_array = array();
    }
   }
  }
 }
 if (in_array($quantity, array_keys($web_data_size_array))) {
  $curl_url = "https://" . $api_detail["api_base_url"] . "/api/data/";
  $curl_request = curl_init($curl_url);
  curl_setopt($curl_request, CURLOPT_POST, true);
  curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
  curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
  $curl_http_headers = array(
   "Authorization: Token " . $api_detail["api_key"],
   "Content-Type: application/json",
  );
  curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
  $curl_postfields_data = json_encode(array("network" => $net_id, "plan" => $web_data_size_array[$quantity], "mobile_number" => $phone_no, "Ported_number" => true), true);
  curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
  $curl_result = curl_exec($curl_request);
  $curl_json_result = json_decode($curl_result, true);
  if(!is_array($curl_json_result)){ $curl_json_result = array(); }


  if (curl_errno($curl_request)) {
   $api_response = "failed";
   $api_response_text = 1;
   $api_response_description = "";
   $api_response_status = 3;
  }

  if (in_array($curl_json_result["Status"], array("successful"))) {
   $api_response = "successful";
   $api_response_reference = $curl_json_result["id"];
   $api_response_text = $curl_json_result["Status"];
   $api_response_description = "Transaction Successful | " . strtoupper(str_replace(["_", "-"], " ", $quantity)) . " credited to 234" . substr($phone_no, "1", "11");
   $api_response_status = 1;
  }

  if (in_array($curl_json_result["Status"], array("pending"))) {
   $api_response = "pending";
   $api_response_reference = $curl_json_result["id"];
   $api_response_text = $curl_json_result["Status"];
   $api_response_description = "Transaction Pending | " . strtoupper(str_replace(["_", "-"], " ", $quantity)) . " credited to 234" . substr($phone_no, "1", "11");
   $api_response_status = 2;
  }

  if (!in_array($curl_json_result["Status"], array("successful", "pending"))) {
   $api_response = "failed";
   $api_response_text = $curl_json_result["Status"];
   $api_response_description = "Transaction Failed | " . strtoupper(str_replace(["_", "-"], " ", $quantity)) . " data to 234" . substr($phone_no, "1", "11") . " was not delivered";
   $api_response_status = 3;
  }
 } else {
  //Data size not available
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
curl_close($curl_request);
?>