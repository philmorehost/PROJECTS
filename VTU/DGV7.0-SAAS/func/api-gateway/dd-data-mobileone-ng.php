<?php
 $data_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");
 if(in_array($product_name, array_keys($data_service_provider_alter_code))){
  if($product_name == "mtn"){
   $web_data_size_array = array("20gb_30days" => "mtn_20gb_30_days", "110gb_30days" => "mtn_110gb_30days", "2gb_30days" => "mtn_2gb_30_days", "40gb" => "mtn_40gb", "75gb_30days" => "mtn_75gb_30days", "15gb_30days" => "mtn_15gb_30_days", "25mb_24hrs" => "mtn_25mb_24hrs", "3gb_30days" => "mtn_3gb_30days", "120gb_60days" => "mtn_120gb_60days", "150gb_90days" => "mtn_150gb_90_days", "75mb_24hrs" => "mtn_75mb_24hrs", "1gb_24hrs" => "mtn_1gb_24hrs", "200mb_2days" => "mtn_200mb_2days", "2gb_2days" => "mtn_2gb_2days", "350mb_7days" => "mtn_350mb_7days", "1gb_7days" => "mtn_1gb_7days", "6gb_30days" => "mtn_6gb_30days", "75gb_60days" => "mtn_75gb_60days", "250gb_90days" => "mtn_250gb_90days", "400gb_365days" => "mtn_400gb_365days", "1000gb_365days" => "mtn_1000gb_365days", "2000gb_365days" => "mtn_2000gb_365days", "45gb_30days" => "mtn_45gb_30days", "6gb_7days" => "mtn_6gb_7_days", "10gb_30days" => "mtn_10gb_30days", "750mb_14days" => "mtn_750mb_14days", "2.5gb_2days" => "mtn_2_5gb_2days", "8gb_30days" => "mtn_8gb_30days");
		}else{
   if($product_name == "airtel"){
    $web_data_size_array = array("1.5gb" => "airtel_1_5gb", "3gb_30days" => "airtel_3gb_30days", "6gb_7days" => "airtel_6gb_7days", "4.5gb_30days" => "airtel_4_5gb_30days", "110gb_30days" => "airtel_110gb_30days", "750mb" => "airtel_750mb", "75mb10_extra_24hrs" => "airtel_75mb10_extra_24hrs", "200mb_3days" => "airtel_200mb_3days", "350mb_10_extra_7days" => "airtel_350mb__10_extra_7days", "40gb_30days" => "airtel_40gb_30days", "8gb_30days" => "airtel_8gb_30days", "11gb_30days" => "airtel_11gb_30days", "75gb_30days" => "airtel_75gb_30days", "1gb_1day" => "airtel_1gb__1day", "2gb_2days" => "airtel_2gb__2days", "2gb_30days" => "airtel_2gb__30days", "6gb_30days" => "airtel_6gb__30days", "15gb" => "airtel_15gb");
   }else{
    if($product_name == "glo"){
     $web_data_size_array = array("2gb_2days" => "glo_2gb_2days", "100mb_1day" => "glo_100mb_1_day", "350mb_2days" => "glo_350mb_2_days", "1.35gb_14days" => "glo_1_35gb_14days", "2.5gb" => "glo_2_5gb", "5.8gb" => "glo_5_8_gb", "7.7gb" => "glo_7_7_gb", "10gb" => "glo_10gb", "13.5gb" => "glo_13_5_gb", "18.25gb" => "glo_1825gb", "29.5gb" => "glo_295gb", "50gb" => "glo_50gb", "93gb" => "glo_93gb", "119gb" => "glo_119gb", "50mb_1day" => "glo_50mb_1_day", "138gb" => "glo_138gb", "3.75gb" => "glo_3_75gb", "special_1gb_special_1day" => "glo_special_1_gb_special1day", "7gb_special_7days" => "glo__7_gb_special7days", "3.58gb_oneoff_30days" => "glo__3_58_gb_oneoff30days", "225gb_30days" => "glo_225gb_30days", "300gb_30days" => "glo_300gb30days", "425gb_90days" => "glo_425gb90days", "525gb_90days" => "glo_525gb90days", "675gb_120days" => "glo_675gb120days", "1024gb_365days" => "glo_1024gb365days");
    }else{
     if($product_name == "9mobile"){
      $web_data_size_array = array("15gb_30days" => "9mobile_15gb_30days", "40gb_30days" => "9mobile_40_gb_30_days", "75gb_30days" => "9mobile_75_gb_30_days", "7gb_7days" => "9mobile_7gb_7_days", "120gb_365days" => "9mobile_120gb_365_days", "100mb_24hrs" => "9mobile_100mb_24hrs", "1.5gb_30days" => "9mobile_1_5gb_30_days", "3gb_30days" => "9mobile_3gb_30_days", "2gb_30days" => "9mobile_2gb_30days", "100gb_100days" => "9mobile_100gb_100_days", "60gb_180days" => "9mobile_60gb_180_days", "500mb_30days" => "9mobile_500mb_30days", "4.5gb_30days" => "9mobile_4_5gb_30_days", "30gb_90days" => "9mobile_30gb_90_days", "650mb_24hrs" => "9mobile_650mb_24hrs", "25mb_24hrs" => "9mobile_25mb_24_hrs", "11gb_30days" => "9mobile_11gb_30days");
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