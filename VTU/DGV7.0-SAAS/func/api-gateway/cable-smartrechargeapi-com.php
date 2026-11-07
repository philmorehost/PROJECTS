<?php
$data_service_provider_alter_code = array("startimes" => "startimes", "dstv" => "dstv", "gotv" => "gotv");
if (in_array($product_name, array_keys($data_service_provider_alter_code))) {
 if ($product_name == "startimes") {
  $web_cable_size_array = array("nova" => "nova", "basic" => "basic", "smart" => "smart", "classic" => "classic", "super" => "super");
  $api_product_code = $product_name . "_" . $quantity;
 } else {
  if ($product_name == "dstv") {
   $web_cable_size_array = array("padi" => "padi", "yanga" => "yanga", "confam" => "confam", "compact" => "compact", "compact_plus" => "compact_plus");
   $api_product_code = $product_name . "_" . $quantity;
  } else {
   if ($product_name == "gotv") {
    $web_cable_size_array = array("smallie" => "smallie", "jinja" => "jinja", "jolli" => "jolli", "max" => "max", "super" => "super");
    $api_product_code = $product_name . "_" . $quantity;
   }
  }
 }
 if (in_array($quantity, array_keys($web_cable_size_array))) {
  $curl_url = "https://" . $api_detail["api_base_url"] . "/api/v2/tv/?api_key=" . $api_detail["api_key"] . "&smartcard_number=" . $iuc_no . "&product_code=" . $api_product_code;
  $curl_request = curl_init($curl_url);
  curl_setopt($curl_request, CURLOPT_HTTPGET, true);
  curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
  curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
  $curl_result = curl_exec($curl_request);
  $curl_json_result = json_decode($curl_result, true);
  if(!is_array($curl_json_result)){ $curl_json_result = array(); }



  if (curl_errno($curl_request)) {
   $api_response = "failed";
   $api_response_text = 1;
   $api_response_description = "";
   $api_response_status = 3;
  }

  if (in_array($curl_json_result["error_code"], array(1986))) {
   $api_response = "successful";
   $api_response_reference = $curl_json_result["data"]["recharge_id"];
   $api_response_text = $curl_json_result["data"]["text_status"];
   $api_response_description = "Transaction Successful | " . str_replace(["_", "-"], " ", $web_cable_size_array[$quantity]) . " to IUC No: " . $iuc_no . " was successful";
   $api_response_status = 1;
  }

  if (in_array($curl_json_result["error_code"], array(1981))) {
   $api_response = "pending";
   $api_response_reference = $curl_json_result["data"]["recharge_id"];
   $api_response_text = $curl_json_result["data"]["text_status"];
   $api_response_description = "Transaction Pending | " . str_replace(["_", "-"], " ", $web_cable_size_array[$quantity]) . " to IUC No: " . $iuc_no . " was pending";
   $api_response_status = 2;
  }

  if (!in_array($curl_json_result["error_code"], array(1986, 1981))) {
   $api_response = "failed";
   $api_response_text = $curl_json_result["data"]["text_status"];
   $api_response_description = "Transaction Failed | " . str_replace(["_", "-"], " ", $web_cable_size_array[$quantity]) . " to IUC No: " . $iuc_no . " failed";
   $api_response_status = 3;
  }
 } else {
  //Cable size not available
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