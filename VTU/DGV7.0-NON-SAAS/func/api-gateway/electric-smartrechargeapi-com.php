<?php
 $web_electric_size_array = array("ekedc"=>$epp."_".$type."_custom","eedc"=>$epp."_".$type."_custom","ikedc"=>$epp."_".$type."_custom","jedc"=>$epp."_".$type."_custom","kedco"=>$epp."_".$type."_custom","ibedc"=>$epp."_".$type."_custom","phed"=>$epp."_".$type."_custom","aedc"=>$epp."_".$type."_custom", "bedc"=>$epp."_".$type."_custom", "kaedco"=>$epp."_".$type."_custom", "aba"=>$epp."_".$type."_custom", "yedc"=>$epp."_".$type."_custom");
 if(in_array($epp, array_keys($web_electric_size_array))){
  $curl_url = "https://".$api_detail["api_base_url"]."/api/v2/electric/?api_key=".$api_detail["api_key"]."&meter_number=".$meter_number."&product_code=".$web_electric_size_array[$epp]."&amount=".$amount;
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
   $api_response_token = $curl_json_result["token"];
   $api_response_token_unit = "";
   $api_response_meter_number = $curl_json_result["meter_number"];
	$api_response_reference = $curl_json_result["data"]["recharge_id"];
	$api_response_text = $curl_json_result["data"]["text_status"];
	$api_response_description = "Transaction Successful | Meter No: ".$curl_json_result["meter_number"]." | Meter Token: ".$curl_json_result["token"];
	$api_response_status = 1;
   mysqli_query($connection_server, "INSERT INTO sas_electric_purchaseds (vendor_id, reference, meter_provider, meter_type, username, meter_number, meter_token, token_unit) VALUES ('".$get_logged_user_details["vendor_id"]."', '$reference', '$epp', '$type', '".$get_logged_user_details["username"]."', '".$api_response_meter_number."', '".$api_response_token."', '".$api_response_token_unit."')");
  }

  if(in_array($curl_json_result["error_code"],array(1981))){
	$api_response = "pending";
	$api_response_token = $curl_json_result["token"];
   $api_response_token_unit = "";
   $api_response_meter_number = $meter_number;
	$api_response_reference = $curl_json_result["data"]["recharge_id"];
	$api_response_text = $curl_json_result["data"]["text_status"];
	$api_response_description = "Transaction Pending | Meter No: ".$meter_number." | Meter Token: ".$curl_json_result["token"];
	$api_response_status = 2;
   mysqli_query($connection_server, "INSERT INTO sas_electric_purchaseds (vendor_id, reference, meter_provider, meter_type, username, meter_number, meter_token, token_unit) VALUES ('".$get_logged_user_details["vendor_id"]."', '$reference', '$epp', '$type', '".$get_logged_user_details["username"]."', '".$api_response_meter_number."', '".$api_response_token."', '".$api_response_token_unit."')");
  }

  if(!in_array($curl_json_result["error_code"],array(1986, 1981))){
	$api_response = "failed";
	  $api_response_text = $curl_json_result["data"]["text_status"];
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