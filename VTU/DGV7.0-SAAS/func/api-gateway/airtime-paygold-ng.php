<?php
 if($isp == "mtn"){
  $paygold_isp_code = "mtn";
 }else{
  if($isp == "glo"){
   $paygold_isp_code = "glo";
  }else{
   if($isp == "9mobile"){
    $paygold_isp_code = "etisalat";
   }else{
    if($isp == "airtel"){
     $paygold_isp_code = "airtel";
    }
   }
  }
 }

 $explode_paygold_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
 $curl_url = "https://".$api_detail["api_base_url"]."/wp-json/api/v1/airtime?username=".$explode_paygold_apikey[0]."&password=".$explode_paygold_apikey[1]."&network_id=".$paygold_isp_code."&amount=".$amount."&phone=".$phone_no;
 $curl_request = curl_init($curl_url);
 curl_setopt($curl_request, CURLOPT_HTTPGET, true);
 curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
 curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
 curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
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

 if(in_array($curl_json_result["code"],array("success"))){
  $api_response = "successful";
  $api_response_reference = $curl_json_result["data"]["request_id"];
  $api_response_text = $curl_json_result["code"];
  $api_response_description = "Transaction Successful | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." was successful";
  $api_response_status = 1;
 }

 if(in_array($curl_json_result["code"],array("pending"))){
  $api_response = "pending";
  $api_response_reference = $curl_json_result["data"]["request_id"];
  $api_response_text = $curl_json_result["code"];
  $api_response_description = "Transaction Pending | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." was pending";
  $api_response_status = 2;
 }

 if(!in_array($curl_json_result["code"],array("success", "pending"))){
  $api_response = "failed";
  $api_response_text = $curl_json_result["code"];
  $api_response_description = "Transaction Failed | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." failed";
  $api_response_status = 3;
 }
curl_close($curl_request);
?>