<?php
 $data_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");
 if(in_array($product_name, array_keys($data_service_provider_alter_code))){
  if($product_name == "mtn"){
   $net_id = "1";
   $web_data_size_array = array("500mb"=>"99","1gb"=>"100","2gb"=>"101","3gb"=>"224","5gb"=>"116","10gb"=>"146");
  }else{
   if($product_name == "airtel"){
    $net_id = "4";
    $web_data_size_array = array("500mb"=>"212","1gb"=>"213","2gb"=>"214","5gb"=>"215","10gb"=>"226");
   }else{
    if($product_name == "glo"){
     $net_id = "2";
     $web_data_size_array = array("500mb"=>"255","1gb"=>"256","2gb"=>"257","3gb"=>"258","5gb"=>"259","10gb"=>"260");
    }else{
     if($product_name == "9mobile"){
      $net_id = "3";
      $web_data_size_array = array("500mb"=>"268","1gb"=>"269","2gb"=>"271","3gb"=>"273","5gb"=>"295","10gb"=>"291");
     }
    }
   }
  }
  if(in_array($quantity, array_keys($web_data_size_array))){
   $curl_url = "https://".$api_detail["api_base_url"]."/api/data/";
   $curl_request = curl_init($curl_url);
   curl_setopt($curl_request, CURLOPT_POST, true);
   curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
   curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
   curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
   $curl_http_headers = array(
    "Authorization: Token ".$api_detail["api_key"],
    "Content-Type: application/json",
   );
   curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
   $curl_postfields_data = json_encode(array("network"=>$net_id,"plan"=>$web_data_size_array[$quantity],"mobile_number"=>$phone_no,"Ported_number"=>true),true);
   curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
   $curl_result = curl_exec($curl_request);
   $curl_json_result = json_decode($curl_result, true);
   if(!is_array($curl_json_result)){ $curl_json_result = array(); }
   curl_close($curl_request);

   if(in_array($curl_json_result["Status"],array("successful"))){
    $api_response = "successful";
    $api_response_reference = $curl_json_result["id"];
    $api_response_text = $curl_json_result["Status"];
    $api_response_description = "Transaction Successful | ".strtoupper(str_replace(["_","-"]," ",$quantity))." credited to 234".substr($phone_no, "1", "11");
    $api_response_status = 1;
   }

   if(in_array($curl_json_result["Status"],array("pending"))){
    $api_response = "pending";
    $api_response_reference = $curl_json_result["id"];
    $api_response_text = $curl_json_result["Status"];
    $api_response_description = "Transaction Pending | ".strtoupper(str_replace(["_","-"]," ",$quantity))." credited to 234".substr($phone_no, "1", "11");
    $api_response_status = 2;
   }

   if(!in_array($curl_json_result["Status"],array("successful","pending"))){
    $api_response = "failed";
    $api_response_text = $curl_json_result["Status"];
    $api_response_description = "Transaction Failed";
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
?>