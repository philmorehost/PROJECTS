<?php
 if($isp == "mtn"){
  $clubkonnect_isp_code = "01";
 }else{
  if($isp == "glo"){
   $clubkonnect_isp_code = "02";
  }else{
   if($isp == "9mobile"){
    $clubkonnect_isp_code = "03";
   }else{
    if($isp == "airtel"){
     $clubkonnect_isp_code = "04";
    }
   }
  }
 }

 $explode_clubkonnect_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
 $request_id = substr(date("YmdHis") . rand(111, 999), 0, 15);
 $curl_url = "https://www.nellobytesystems.com/APIAirtimeV1.asp?UserID=".urlencode($explode_clubkonnect_apikey[0])."&APIKey=".urlencode($explode_clubkonnect_apikey[1])."&MobileNetwork=".urlencode($clubkonnect_isp_code)."&Amount=".urlencode($amount)."&MobileNumber=".urlencode($phone_no)."&RequestID=".urlencode($request_id);
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


 if(curl_errno($curl_request)){
  $api_response = "failed";
  $api_response_text = 1;
  $api_response_description = "";
  $api_response_status = 3;
 }

 if(in_array($curl_json_result["statuscode"],array(200, 201, 299))){
  $api_response = "successful";
  $api_response_reference = $curl_json_result["orderid"];
  $api_response_text = $curl_json_result["status"];
  $api_response_description = "Transaction Successful | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." was successful";
  $api_response_status = 1;
 }

 if(in_array($curl_json_result["statuscode"],array(100, 300))){
  $api_response = "pending";
  $api_response_reference = $curl_json_result["orderid"];
  $api_response_text = $curl_json_result["status"];
  $api_response_description = "Transaction Pending | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." was pending";
  $api_response_status = 2;
 }

 if(!in_array($curl_json_result["statuscode"],array(100, 300, 200, 201, 299))){
  $api_response = "failed";
  $api_response_text = $curl_json_result["status"];
  $api_response_description = "Transaction Failed | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." failed";
  $api_response_status = 3;
 }
curl_close($curl_request);
?>