<?php
 $data_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");
 if(in_array($product_name, array_keys($data_service_provider_alter_code))){
  if($product_name == "mtn"){
   $net_id = "1";
   $web_data_size_array = array("500mb"=>"214","1gb"=>"7","2gb"=>"8","3gb"=>"44","5gb"=>"291","10gb"=>"213");
  }else{
   if($product_name == "airtel"){
    $net_id = "4";
    $web_data_size_array = array("500mb"=>"165","1gb"=>"145","2gb"=>"146","5gb"=>"147","10gb"=>"148");
   }else{
    if($product_name == "glo"){
     $net_id = "2";
     $web_data_size_array = array("1gb"=>"286","2gb"=>"288","3.5gb"=>"289","15gb"=>"290");
    }else{
     if($product_name == "9mobile"){
      $net_id = "3";
      $web_data_size_array = array("500mb"=>"221","1gb"=>"183","2gb"=>"185","3gb"=>"186","5gb"=>"188","10gb"=>"189");
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


   if(curl_errno($curl_request)){
    $api_response = "failed";
    $api_response_text = 1;
    $api_response_description = "";
    $api_response_status = 3;
   }

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