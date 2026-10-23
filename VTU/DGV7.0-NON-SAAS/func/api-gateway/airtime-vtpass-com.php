<?php
 if($isp == "mtn"){
  $vtpass_isp_code = "mtn";
 }else{
  if($isp == "glo"){
   $vtpass_isp_code = "glo";
  }else{
   if($isp == "9mobile"){
    $vtpass_isp_code = "etisalat";
   }else{
    if($isp == "airtel"){
     $vtpass_isp_code = "airtel";
    }
   }
  }
 }
 $curl_url = "https://vtpass.com/api/pay";
 $curl_request = curl_init($curl_url);
 curl_setopt($curl_request, CURLOPT_POST, true);
 curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
 curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
 curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
 $curl_http_headers = array(
  "Authorization: Basic ".base64_encode($api_detail["api_key"]),
  "Content-Type: application/json",
 );
 curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
 $vtpass_reference = substr(str_shuffle("12345678901234567890"), 0, 15);
 $curl_postfields_data = json_encode(array("request_id"=> $vtpass_reference,"serviceID"=> $vtpass_isp_code,"amount"=> $amount,"phone"=> $phone_no), true);
 curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
 $curl_result = curl_exec($curl_request);
 $curl_json_result = json_decode($curl_result, true);
if(!is_array($curl_json_result) || !isset($curl_json_result["code"])){
 $curl_json_result = array("code" => "999", "response_description" => "Invalid API response. Check your API credentials and try again.");
 }



 if(curl_errno($curl_request)){
  $api_response = "failed";
  $api_response_text = 1;
  $api_response_description = "";
  $api_response_status = 3;
 }

 if(in_array($curl_json_result["code"],array("000","044"))){
  $api_response = "successful";
  $api_response_reference = $curl_json_result["requestId"];
  $api_response_text = $curl_json_result["response_description"];
  $api_response_description = "Transaction Successful | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." was successful";
  $api_response_status = 1;
 }

 if(in_array($curl_json_result["code"],array("001","099"))){
  $api_response = "pending";
  $api_response_reference = $curl_json_result["requestId"];
  $api_response_text = $curl_json_result["response_description"];
  $api_response_description = "Transaction Pending | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." was pending";
  $api_response_status = 2;
 }

 if(!in_array($curl_json_result["code"],array("000","044","001","099"))){
  $api_response = "failed";
  $api_response_text = $curl_json_result["response_description"];
  $api_response_description = "Transaction Failed | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." failed";
  $api_response_status = 3;
 }
curl_close($curl_request);
?>