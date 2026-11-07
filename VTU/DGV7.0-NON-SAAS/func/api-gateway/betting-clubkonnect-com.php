<?php
 $web_betting_size_array = array("msport" => "msport", "naijabet" => "naijabet", "nairabet" => "nairabet", "bet9ja-agent" => "bet9ja-agent", "betland" => "betland", "betlion" => "betlion", "supabet" => "supabet", "bet9ja" => "bet9ja", "bangbet" => "bangbet", "betking" => "betking", "1xbet" => "1xbet", "betway" => "betway", "merrybet" => "merrybet", "mlotto" => "mlotto", "western-lotto" => "western-lotto", "hallabet" => "hallabet", "green-lotto" => "green-lotto");
 if(in_array($epp, array_keys($web_betting_size_array))){
  $explode_clubkonnect_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
  $clubkonnect_meter_type = array("prepaid"=>"01","postpaid"=>"02");
  $clubkonnect_reference = substr(str_shuffle("12345678901234567890"), 0, 15);
  $curl_url = "https://www.nellobytesystems.com/APIBettingV1.asp?UserID=".$explode_clubkonnect_apikey[0]."&APIKey=".$explode_clubkonnect_apikey[1]."&BettingCompany=".$web_betting_size_array[$epp]."&CustomerID=".$customer_id."&Amount=".$amount."&RequestID=".$clubkonnect_reference;
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
	$api_response_reference = $curl_json_result["transactionid"];
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Successful | BettingCompany: ".$curl_json_result["ordertype"]." | CustomerID: ".$curl_json_result["customerid"];
   $api_response_status = 1;
  }

  if(in_array($curl_json_result["status"],array("ORDER_RECEIVED", "ORDER_PROCESSED"))){
   $api_response = "pending";
	$api_response_reference = $curl_json_result["transactionid"];
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Successful | BettingCompany: ".$curl_json_result["ordertype"]." | CustomerID: "  .$curl_json_result["customerid"];
   $api_response_status = 2;
  }

  if(!in_array($curl_json_result["status"],array("ORDER_COMPLETED", "ORDER_RECEIVED", "ORDER_PROCESSED"))){
   $api_response = "failed";
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Pending | BettingCompany: ".strtoupper($web_betting_size_array[$epp])." | CustomerID: ".$customer_id;
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