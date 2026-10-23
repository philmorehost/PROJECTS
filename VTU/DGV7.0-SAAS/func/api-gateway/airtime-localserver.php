<?php
 if($isp == "mtn"){
  $localserver_isp_code = "mtn";
 }else{
  if($isp == "airtel"){
   $localserver_isp_code = "airtel";
  }else{
   if($isp == "glo"){
    $localserver_isp_code = "glo";
   }else{
    if($isp == "9mobile"){
     $localserver_isp_code = "9mobile";
    }
   }
  }
 }

 $curl_url = "https://".$api_detail["api_base_url"]."/web/api/airtime.php";
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
 $curl_postfields_data = json_encode(array("api_key"=> $api_detail["api_key"],"network"=> $localserver_isp_code,"amount"=> $amount,"phone_number"=> $phone_no), true);
 curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
 $curl_result = curl_exec($curl_request);
 $curl_json_result = function_exists('bc_gateway_json_decode') ? bc_gateway_json_decode($curl_result) : json_decode($curl_result, true);
 if(!is_array($curl_json_result)){ $curl_json_result = array(); }


 if(curl_errno($curl_request)){
  $api_response = "failed";
  $api_response_text = 1;
  $api_response_description = "";
  $api_response_status = 3;
 }

 if(in_array($curl_json_result["status"],array("success"))){
  $api_response = "successful";
  $api_response_reference = $curl_json_result["ref"];
  $api_response_text = $curl_json_result["status"];
  $api_response_description = "Transaction Successful | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." was successful";
  $api_response_status = 1;
 }

 if(in_array($curl_json_result["status"],array("pending"))){
  $api_response = "pending";
  $api_response_reference = $curl_json_result["ref"];
  $api_response_text = $curl_json_result["status"];
  $api_response_description = "Transaction Pending | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." was pending";
  $api_response_status = 2;
 }

 if(in_array($curl_json_result["status"],array("failed"))){
  $api_response = "failed";
  $api_response_text = $curl_json_result["status"];
  $api_response_description = "Transaction Failed | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." failed";
  $api_response_status = 3;
 }
 if (function_exists('bc_gateway_settle_purchase')) { bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, "Transaction Failed | no valid response from the upstream gateway", $curl_result ?? ""); }
?>