<?php
 $data_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");
 if(in_array($product_name, array_keys($data_service_provider_alter_code))){
  if($product_name == "mtn"){
   $net_id = "1";
   $web_data_size_array = array("500mb"=>"42","1gb"=>"46","2gb"=>"47","3gb"=>"48","5gb"=>"49","10gb"=>"50");
  }else{
   if($product_name == "airtel"){
    $net_id = "2";
    $web_data_size_array = array("500mb"=>"51","1gb"=>"52","2gb"=>"53","5gb"=>"54","10gb"=>"55");
   }else{
    if($product_name == "glo"){
     $net_id = "3";
     $web_data_size_array = array("500mb"=>"71","1gb"=>"72","2gb"=>"73","3gb"=>"74","5gb"=>"75","10gb"=>"76");
    }else{
     if($product_name == "9mobile"){
      $net_id = "4";
      $web_data_size_array = array("500mb"=>"85","1gb"=>"86","2gb"=>"87","3gb"=>"88","5gb"=>"90","10gb"=>"91");
     }
    }
   }
  }
  if(in_array($quantity, array_keys($web_data_size_array))){
   $curl_url = "https://".$api_detail["api_base_url"]."/api/user";
   $curl_legitdataway_user_request = curl_init($curl_url);
   curl_setopt($curl_legitdataway_user_request, CURLOPT_POST, true);
   curl_setopt($curl_legitdataway_user_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_legitdataway_user_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_legitdataway_user_request, CURLOPT_CONNECTTIMEOUT, 10);
   curl_setopt($curl_legitdataway_user_request, CURLOPT_SSL_VERIFYHOST, true);
   curl_setopt($curl_legitdataway_user_request, CURLOPT_SSL_VERIFYPEER, true);
   $curl_legitdataway_user_http_headers = array(
    "Authorization: Basic ".base64_encode($api_detail["api_key"]),
    "Content-Type: application/json",
   );
   curl_setopt($curl_legitdataway_user_request, CURLOPT_HTTPHEADER, $curl_legitdataway_user_http_headers);
   $curl_legitdataway_user_result = curl_exec($curl_legitdataway_user_request);
   $curl_legitdataway_user_json_result = json_decode($curl_legitdataway_user_result, true);
   if(!is_array($curl_legitdataway_user_json_result)){ $curl_legitdataway_user_json_result = array(); }
   curl_close($curl_legitdataway_user_request);

   if(curl_errno($curl_request)){
    $api_response = "failed";
    $api_response_text = 1;
    $api_response_description = "";
    $api_response_status = 3;
   }

   if($curl_legitdataway_user_json_result["status"] == "success"){
    $curl_url = "https://".$api_detail["api_base_url"]."/api/data/";
    $curl_request = curl_init($curl_url);
    curl_setopt($curl_request, CURLOPT_POST, true);
    curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
    $curl_http_headers = array(
     "Authorization: Token ".$curl_legitdataway_user_json_result["AccessToken"],
     "Content-Type: application/json",
    );
    curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
    $legitdataway_reference = substr(str_shuffle("12345678901234567890"), 0, 15);
    $curl_postfields_data = json_encode(array("network"=>$net_id,"data_plan"=>$web_data_size_array[$quantity],"phone"=>$phone_no,"bypass"=>true,"request-id"=>$legitdataway_reference),true);
    curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
    $curl_result = curl_exec($curl_request);
    $curl_json_result = json_decode($curl_result, true);
    if(!is_array($curl_json_result)){ $curl_json_result = array(); }


    if(in_array($curl_json_result["status"],array("success"))){
     $api_response = "successful";
     $api_response_reference = $curl_json_result["request-id"];
     $api_response_text = $curl_json_result["status"];
     $api_response_description = "Transaction Successful | ".strtoupper(str_replace(["_","-"]," ",$quantity))." credited to 234".substr($phone_no, "1", "11");
     $api_response_status = 1;
    }

    if(!in_array($curl_json_result["status"],array("success"))){
     $api_response = "failed";
     $api_response_text = $curl_json_result["status"];
     $api_response_description = "Transaction Failed | ".strtoupper(str_replace(["_","-"]," ",$quantity))." data to 234".substr($phone_no, "1", "11")." was not delivered";
     $api_response_status = 3;
    }
   }else{
    //Err: Could not connect
    $api_response = "failed";
    $api_response_text = "";
    $api_response_description = "Err: Could not connect";
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