<?php
 $data_service_providers = array("mtn", "airtel", "glo", "9mobile");
 if(in_array($product_name, $data_service_providers)){

  if(!empty($quantity)){
   $curl_url = "https://".$api_detail["api_base_url"]."/api/v2/directdata/?api_key=".$api_detail["api_key"]."&product_code=".$quantity."&phone=".$phone_no;
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
    $api_response_description = "Transaction Successful | ".strtoupper(str_replace(["_","-"]," ",$quantity))." credited to 234".substr($phone_no, "1", "11");
    $api_response_status = 1;
   }

   if(in_array($curl_json_result["error_code"],array(1981))){
    $api_response = "pending";
    $api_response_reference = $curl_json_result["data"]["recharge_id"];
    $api_response_text = $curl_json_result["data"]["text_status"];
    $api_response_description = "Transaction Pending | ".strtoupper(str_replace(["_","-"]," ",$quantity))." credited to 234".substr($phone_no, "1", "11");
    $api_response_status = 2;
   }

   if(!in_array($curl_json_result["error_code"],array(1986, 1981))){
    $api_response = "failed";
    $api_response_text = $curl_json_result["data"]["text_status"];
    $api_response_description = "Transaction Failed | ".strtoupper(str_replace(["_","-"]," ",$quantity))." data to 234".substr($phone_no, "1", "11")." was not delivered";
    $api_response_status = 3;
   }
  }else{
   //Data size not available
   $api_response = "failed";
   $api_response_text = "";
   $api_response_description = "";
   $api_response_status = 3;
  }
 }else{
  //Service not available
  $api_response = "failed";
  $api_response_text = "";
  $api_response_description = "Service not available";
  $api_response_status = 3;
 }
curl_close($curl_request);
?>