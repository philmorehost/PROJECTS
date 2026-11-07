<?php
 $web_electric_size_array = array("ekedc"=>"01","eedc"=>"09","ikedc"=>"02","jedc"=>"06","kedco"=>"04","ibedc"=>"07","phed"=>"05","aedc"=>"03", "bedc"=>"08", "kaedco"=>"10", "yedc"=>"11", "aba"=>"12");
 if(in_array($epp, array_keys($web_electric_size_array))){
  $explode_clubkonnect_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
  $clubkonnect_meter_type = array("prepaid"=>"01","postpaid"=>"02");
  $clubkonnect_reference = substr(str_shuffle("12345678901234567890"), 0, 15);
  $curl_url = "https://www.nellobytesystems.com/APIElectricityV1.asp?UserID=".$explode_clubkonnect_apikey[0]."&APIKey=".$explode_clubkonnect_apikey[1]."&ElectricCompany=".$web_electric_size_array[$epp]."&MeterType=".$clubkonnect_meter_type[$type]."&MeterNo=".$meter_number."&Amount=".$amount."&PhoneNo=09111111111&RequestID=".$clubkonnect_reference;
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

  if(in_array($curl_json_result["status"],array("ORDER_COMPLETED"))){
   $api_response = "successful";
   $api_response_token = $curl_json_result["metertoken"];
   $api_response_token_unit = "";
   $api_response_meter_number = $curl_json_result["meterno"];
	$api_response_reference = $curl_json_result["transactionid"];
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Successful | Meter No: ".$curl_json_result["meterno"]." | Meter Token: ".$curl_json_result["metertoken"];
   $api_response_status = 1;
   mysqli_query($connection_server, "INSERT INTO sas_electric_purchaseds (vendor_id, reference, meter_provider, meter_type, username, meter_number, meter_token, token_unit) VALUES ('".$get_logged_user_details["vendor_id"]."', '$reference', '$epp', '$type', '".$get_logged_user_details["username"]."', '".$api_response_meter_number."', '".$api_response_token."', '".$api_response_token_unit."')");
  }

  if(in_array($curl_json_result["status"],array("ORDER_RECEIVED", "ORDER_PROCESSED"))){
   $api_response = "pending";
   $api_response_token = $curl_json_result["metertoken"];
   $api_response_token_unit = "";
   $api_response_meter_number = $meter_number;
	$api_response_reference = $curl_json_result["transactionid"];
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Pending | Meter No: ".$meter_number." | Meter Token: ".$curl_json_result["metertoken"];
   $api_response_status = 2;
   mysqli_query($connection_server, "INSERT INTO sas_electric_purchaseds (vendor_id, reference, meter_provider, meter_type, username, meter_number, meter_token, token_unit) VALUES ('".$get_logged_user_details["vendor_id"]."', '$reference', '$epp', '$type', '".$get_logged_user_details["username"]."', '".$api_response_meter_number."', '".$api_response_token."', '".$api_response_token_unit."')");
  }

  if(!in_array($curl_json_result["status"],array("ORDER_COMPLETED", "ORDER_RECEIVED", "ORDER_PROCESSED"))){
   $api_response = "failed";
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Failed | Meter No: ".$meter_number." recharge failed";
   $api_response_status = 3;
  }

 }else{
  //Electric size not available
  $api_response = "failed";
  $api_response_text = "";
  $api_response_description = "";
  $api_response_status = 3;
 }
curl_close($curl_request);
?>