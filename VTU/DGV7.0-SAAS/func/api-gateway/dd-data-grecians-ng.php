<?php
 $data_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");
 if(in_array($product_name, array_keys($data_service_provider_alter_code))){
  if($product_name == "mtn"){
   $web_data_size_array = array("3gb_30days" => "mtn_3gb30days", "6gb_30days" => "mtn_6gb30days", "2.5gb_2days" => "mtn_2_5gb2days", "1.5gb_30days" => "mtn_1_5gb_30days", "2gb_30days" => "mtn_2gb_30days", "4.5gb_30days" => "mtn_4_5gb_30days", "10gb_30days" => "mtn_10gb_30days", "15gb_30days" => "mtn_15gb30days", "75gb_30days" => "mtn_75gb30days", "75gb_60days" => "mtn_75gb60days", "750mb_14days" => "mtn_750mb_14days", "40gb_30days" => "mtn_40gb30days", "120gb_60days" => "mtn_120gb_60days", "8gb_30days" => "mtn_8gb30days", "20gb_30days" => "mtn_20gb30days", "110gb_30days" => "mtn_110gb30days", "30gb_60days" => "mtn_30gb60days");
  }else{
   if($product_name == "airtel"){
    $web_data_size_array = array("1.5gb_30days" => "airtel_1_5gb30days", "15gb_30days" => "airtel_15gb30days", "40gb_30days" => "airtel_40gb30days", "6gb_30days" => "airtel_6gb30days", "8gb_30days" => "airtel_8gb30days", "11gb_30days" => "airtel_11gb30days", "4.5gb_30days" => "airtel_4_5gb30days", "750mb_14days" => "airtel_750mb14days", "2gb_30days" => "airtel_2gb30days", "75gb_30days" => "airtel_75gb30days", "110gb_30days" => "airtel_110gb30days");
   }else{
    if($product_name == "glo"){
     $web_data_size_array = array("2.5gb_30days" => "glo_2_5gb30days", "5.8gb_30days" => "glo_5_8gb30days", "7.7gb_30days" => "glo_7_7gb30days", "10gb" => "glo_10gbdays", "13.25gb_30days" => "glo_13_25gb30days", "18.25gb_30days" => "glo_18_25gb30days", "50gb_30days" => "glo_50gb30days", "93gb_30days" => "glo_93gb30days", "119gb_30days" => "glo_119gb30days", "138gb_30days" => "glo_138gb30days", "29.5gb_30days" => "glo_29_5gb30days", "4.1gb_30days" => "glo_4_1gb30days", "1.05gb_14days" => "glo_1_05gb14days");
    }else{
     if($product_name == "9mobile"){
      $web_data_size_array = array("2gb_30days" => "9mobile_2gb30days", "4.5gb_30days" => "9mobile_4_5gb30days", "11gb_30days" => "9mobile_11gb30days", "75gb_30days" => "9mobile_75gb30days", "500mb_30days" => "9mobile_500mb30days", "1.5gb_30days" => "9mobile_1_5gb30days", "40gb_30days" => "9mobile_40gb30days", "3gb_30days" => "9mobile_3gb30days");
     }
    }
   }
  }
  if(in_array($quantity, array_keys($web_data_size_array))){
   $curl_url = "https://".$api_detail["api_base_url"]."/api/v2/directdata/?api_key=".$api_detail["api_key"]."&product_code=".$web_data_size_array[$quantity]."&phone=".$phone_no;
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