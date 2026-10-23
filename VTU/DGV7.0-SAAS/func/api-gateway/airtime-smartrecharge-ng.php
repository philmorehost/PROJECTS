<?php
 $curl_url = "https://".$api_detail["api_base_url"]."/api/v2/airtime/?api_key=".$api_detail["api_key"]."&product_code=".$isp."_custom&phone=".$phone_no."&amount=".$amount;
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

 if(in_array($curl_json_result["error_code"],array(1986))){
  $api_response = "successful";
  $api_response_reference = $curl_json_result["data"]["recharge_id"];
  $api_response_text = $curl_json_result["data"]["text_status"];
  $api_response_description = "Transaction Successful | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." was successful";
  $api_response_status = 1;
 }

 if(in_array($curl_json_result["error_code"],array(1981))){
  $api_response = "pending";
  $api_response_reference = $curl_json_result["data"]["recharge_id"];
  $api_response_text = $curl_json_result["data"]["text_status"];
  $api_response_description = "Transaction Pending | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." was pending";
  $api_response_status = 2;
 }

 if(!in_array($curl_json_result["error_code"],array(1986, 1981))){
  $api_response = "failed";
  $api_response_text = $curl_json_result["data"]["text_status"];
  $api_response_description = "Transaction Failed | N".$amount." Airtime to 234".substr($phone_no, "1", "11")." failed";
  $api_response_status = 3;
 }
curl_close($curl_request);
?>