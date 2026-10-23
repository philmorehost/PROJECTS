<?php
 $data_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");
 if(in_array($product_name, array_keys($data_service_provider_alter_code))){
  if($product_name == "mtn"){
   $web_data_size_array = array("250mb" => "corporate_data_250mb", "500mb" => "corporate_data_500mb", "1gb" => "corporate_data_1gb", "2gb" => "corporate_data_2gb", "3gb" => "corporate_data_3gb", "5gb" => "corporate_data_5gb", "10gb" => "corporate_data_10gb");
  }else{
   if($product_name == "airtel"){
    $web_data_size_array = array("300mb" => "airtel_300mb_7days","500mb" => "airtel_500mb_30days","1gb" => "airtel_1gb_30days","2gb" => "airtel_2gb_30days","5gb" => "airtel_5gb_30days","10gb" => "airtel_10gb_30days");
   }else{
    if($product_name == "glo"){
      $web_data_size_array = array("200mb" => "glo_cg_200mb_14days", "500mb" => "glo_cg_500mb_30days", "1gb" => "glo_cg_1gb_30days", "2gb" => "glo_cg_2gb_30days", "3gb" => "glo_cg_3gb_30days", "5gb" => "glo_cg_5gb_30days", "10gb" => "glo_cg_10gb_30days");
				}else{
     if($product_name == "9mobile"){
      $web_data_size_array = array();
     }
    }
   }
  }
  if(in_array($quantity, array_keys($web_data_size_array))){
   $curl_url = "https://".$api_detail["api_base_url"]."/api/v2/datashare/?api_key=".$api_detail["api_key"]."&product_code=".$web_data_size_array[$quantity]."&phone=".$phone_no;
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