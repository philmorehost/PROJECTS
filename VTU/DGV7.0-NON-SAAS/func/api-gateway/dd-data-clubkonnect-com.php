<?php
 $clubkonnect_isp_codes = array("mtn" => "01", "airtel" => "04", "glo" => "02", "9mobile" => "03");
 if(in_array($product_name, array_keys($clubkonnect_isp_codes))){
  $clubkonnect_isp_code = $clubkonnect_isp_codes[$product_name];

  if(!empty($quantity)){
   $clubkonnect_apikey_parts = explode(":", trim($api_detail["api_key"]));
   $ck_user_id = trim($clubkonnect_apikey_parts[0] ?? '');
   $ck_api_key = trim($clubkonnect_apikey_parts[1] ?? '');
   $request_id = preg_replace('/[^A-Za-z0-9]/', '', $reference);

   $curl_url = "https://www.nellobytesystems.com/APIDatabundleV1.asp?UserID=".urlencode($ck_user_id)."&APIKey=".urlencode($ck_api_key)."&MobileNetwork=".urlencode($clubkonnect_isp_code)."&DataPlan=".urlencode($quantity)."&MobileNumber=".urlencode($phone_no)."&RequestID=".urlencode($request_id);

   $curl_request = curl_init($curl_url);
   curl_setopt($curl_request, CURLOPT_HTTPGET, true);
   curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
   curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
   curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
   curl_setopt($curl_request, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
   curl_setopt($curl_request, CURLOPT_TIMEOUT, 60);
   $curl_result = curl_exec($curl_request);
   $curl_error = curl_errno($curl_request);
   curl_close($curl_request);

   if($curl_error){
    $api_response = "failed";
    $api_response_text = "Connection Error";
    $api_response_description = "Network Error: Unable to connect to provider.";
    $api_response_status = 3;
   } else {
    $curl_json_result = json_decode($curl_result, true);
    if(!is_array($curl_json_result)){ $curl_json_result = array(); }

    if (isset($curl_json_result["statuscode"])) {
     $sc = $curl_json_result["statuscode"];

     if(in_array($sc, array(200, 201, 299))){
      $api_response = "successful";
      $api_response_reference = $curl_json_result["orderid"] ?? '';
      $api_response_text = $curl_json_result["status"] ?? 'SUCCESS';
      $api_response_description = "Transaction Successful | ".strtoupper(str_replace(["_","-"]," ",$quantity))." credited to $phone_no";
      $api_response_status = 1;
     } elseif(in_array($sc, array(100, 300))){
      $api_response = "pending";
      $api_response_reference = $curl_json_result["orderid"] ?? '';
      $api_response_text = $curl_json_result["status"] ?? 'ORDER_RECEIVED';
      $api_response_description = "Transaction Pending | ".strtoupper(str_replace(["_","-"]," ",$quantity))." credited to $phone_no.";
      $api_response_status = 2;
     } else {
      $api_response = "failed";
      $api_response_text = $curl_json_result["status"] ?? 'FAILED';
      $api_response_description = "Transaction Failed | " . ($curl_json_result["status"] ?? "Provider Error");
      $api_response_status = 3;
     }
    } else {
     $api_response = "failed";
     $api_response_text = "Invalid JSON";
     $api_response_description = "Invalid response from provider: " . strip_tags(substr($curl_result, 0, 100));
     $api_response_status = 3;
    }
   }
  }else{
   //Data size not available
   $api_response = "failed";
   $api_response_description = "Data plan ID is missing.";
   $api_response_status = 3;
  }
 }else{
  //Service not available
  $api_response = "failed";
  $api_response_description = "ISP not supported by this gateway.";
  $api_response_status = 3;
 }
?>