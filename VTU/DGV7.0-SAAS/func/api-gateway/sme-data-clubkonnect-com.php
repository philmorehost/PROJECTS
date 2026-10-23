<?php
$data_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "9mobile" => "9mobile");
if (in_array($product_name, array_keys($data_service_provider_alter_code))) {
 if ($product_name == "mtn") {
  $net_id = "01";
  $web_data_size_array = array("500mb" => "500.0", "1gb" => "1000.0", "2gb" => "2000.0", "3gb" => "3000.0", "5gb" => "5000.0", "10gb" => "10000.0");
 } else {
  if ($product_name == "airtel") {
   $net_id = "04";
   $web_data_size_array = array("500mb" => "500", "1gb" => "1000", "2gb" => "2000", "5gb" => "5000", "10gb" => "10000");
  } else {
   if ($product_name == "glo") {
    $net_id = "02";
    $web_data_size_array = array();
   } else {
    if ($product_name == "9mobile") {
     $net_id = "03";
     $web_data_size_array = array();
    }
   }
  }
 }
 if (in_array($quantity, array_keys($web_data_size_array))) {

  $clubkonnect_reference = substr(date("YmdHis") . rand(111, 999), 0, 15);
  $explode_clubkonnect_apikey = array_filter(explode(":", trim($api_detail["api_key"])));
  $curl_url = "https://www.nellobytesystems.com/APIDatabundleV1.asp?UserID=" . urlencode($explode_clubkonnect_apikey[0]) . "&APIKey=" . urlencode($explode_clubkonnect_apikey[1]) . "&MobileNetwork=" . urlencode($net_id) . "&DataPlan=" . urlencode($web_data_size_array[$quantity]) . "&MobileNumber=" . urlencode($phone_no) . "&RequestID=" . urlencode($clubkonnect_reference);
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


  if(in_array($curl_json_result["statuscode"],array(200, 201, 299))){
   $api_response = "successful";
   $api_response_reference = $curl_json_result["orderid"];
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Successful | " . strtoupper(str_replace(["_", "-"], " ", $quantity)) . " credited to 234" . substr($phone_no, "1", "11");
   $api_response_status = 1;
  }

  if(in_array($curl_json_result["statuscode"],array(100, 300))){
   $api_response = "pending";
   $api_response_reference = $curl_json_result["orderid"];
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Pending | " . strtoupper(str_replace(["_", "-"], " ", $quantity)) . " credited to 234" . substr($phone_no, "1", "11");
   $api_response_status = 2;
  }

  if(!in_array($curl_json_result["statuscode"],array(100, 300, 200, 201, 299))){
   $api_response = "failed";
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Failed";
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
?>