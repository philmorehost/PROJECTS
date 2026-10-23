<?php
 $web_electric_size_array = array("ekedc"=>"eko-electric","eedc"=>"enugu-electric","ikedc"=>"ikeja-electric","jedc"=>"jos-electric","kedco"=>"kano-electric","ibedc"=>"ibadan-electric","phed"=>"portharcourt-electric","aedc"=>"abuja-electric","yedc"=>"yola-electric", "bedc" => "benin-electric", "aba" => "aba-electric", "kaedco" => "kaduna-electric");
 if(in_array($epp, array_keys($web_electric_size_array))){
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
  $curl_postfields_data = json_encode(array("request_id"=>$vtpass_reference,"serviceID"=>$web_electric_size_array[$epp],"billersCode"=>$meter_number,"variation_code"=>$type,"amount"=>$amount,"phone"=>"09111111111"),true);
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
   $api_response_token = $curl_json_result["mainToken"];
   $api_response_token_unit = $curl_json_result["mainTokenUnits"];
   $api_response_meter_number = $meter_number;
	$api_response_reference = $curl_json_result["requestId"];
   $api_response_text = $curl_json_result["response_description"];
   $api_response_description = "Transaction Successful | Meter No: ".$curl_json_result["content"]["transactions"]["unique_element"]." | Meter Token: ".$curl_json_result["purchased_code"];
   $api_response_status = 1;
   mysqli_query($connection_server, "INSERT INTO sas_electric_purchaseds (vendor_id, reference, meter_provider, meter_type, username, meter_number, meter_token, token_unit) VALUES ('".$get_logged_user_details["vendor_id"]."', '$reference', '$epp', '$type', '".$get_logged_user_details["username"]."', '".$api_response_meter_number."', '".$api_response_token."', '".$api_response_token_unit."')");
  }

  if(in_array($curl_json_result["code"],array("001","099"))){
   $api_response = "pending";
   $api_response_token = $curl_json_result["mainToken"];
   $api_response_token_unit = $curl_json_result["mainTokenUnits"];
   $api_response_meter_number = $meter_number;
	$api_response_reference = $curl_json_result["requestId"];
   $api_response_text = $curl_json_result["response_description"];
   $api_response_description = "Transaction Pending | Meter No: ".$meter_number." | Meter Token: ".$curl_json_result["purchased_code"];
   $api_response_status = 2;
   mysqli_query($connection_server, "INSERT INTO sas_electric_purchaseds (vendor_id, reference, meter_provider, meter_type, username, meter_number, meter_token, token_unit) VALUES ('".$get_logged_user_details["vendor_id"]."', '$reference', '$epp', '$type', '".$get_logged_user_details["username"]."', '".$api_response_meter_number."', '".$api_response_token."', '".$api_response_token_unit."')");
  }

  if(!in_array($curl_json_result["code"],array("000","044","001","099"))){
   $api_response = "failed";
   $api_response_text = $curl_json_result["response_description"];
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