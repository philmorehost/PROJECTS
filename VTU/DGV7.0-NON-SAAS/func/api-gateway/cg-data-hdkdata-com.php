<?php
 $data_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");
 if(in_array($product_name, array_keys($data_service_provider_alter_code))){
  if($product_name == "mtn"){
   $net_id = "1";
   $web_data_size_array = array("500mb"=>"214","1gb"=>"7","2gb"=>"8","3gb"=>"44","5gb"=>"291","10gb"=>"213");
  }else{
   if($product_name == "airtel"){
    $net_id = "4";
    $web_data_size_array = array("500mb"=>"372","1gb"=>"373","2gb"=>"375","5gb"=>"","10gb"=>"379");
   }else{
    if($product_name == "glo"){
     $net_id = "2";
     $web_data_size_array = array("200mb"=>"225","500mb"=>"203","1gb"=>"194","2gb"=>"195","3gb"=>"196","5gb"=>"197","10gb"=>"200");
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
    "Authorization: Token ".trim(str_ireplace("Token ", "", $api_detail["api_key"])),
    "Content-Type: application/json",
   );
   curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
   $curl_postfields_data = json_encode(array("network"=>(int)$net_id,"plan"=>(int)$web_data_size_array[$quantity],"mobile_number"=>$phone_no,"Ported_number"=>true));
   curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
   $curl_result = curl_exec($curl_request);
   error_log("HDK DATA API RAW RESPONSE: " . $curl_result);
   $curl_json_result = function_exists('bc_gateway_json_decode') ? bc_gateway_json_decode($curl_result) : json_decode($curl_result, true);
   if(!is_array($curl_json_result)){ $curl_json_result = array(); }

   // HDK debits the wallet on ITS side the moment it accepts the request, so ONLY its own status
   // word may fail the transaction. An unreadable/absent reply is NOT a failure: the transaction
   // stays PENDING and the requery queue establishes the truth (see func/bc-gateway.php).
   $hdk_verdict = function_exists('bc_gateway_provider_verdict') ? bc_gateway_provider_verdict($curl_json_result, "Status") : null;

   if($hdk_verdict === "successful"){
    $api_response = "successful";
    $api_response_reference = $curl_json_result["id"];
    $api_response_text = $curl_json_result["Status"];
    $api_response_description = "Transaction Successful | ".strtoupper(str_replace(["_","-"]," ",$quantity))." credited to 234".substr($phone_no, "1", "11");
    $api_response_status = 1;
   }

   if($hdk_verdict === "pending"){
    $api_response = "pending";
    $api_response_reference = $curl_json_result["id"];
    $api_response_text = $curl_json_result["Status"];
    $api_response_description = "Transaction Pending | ".strtoupper(str_replace(["_","-"]," ",$quantity))." credited to 234".substr($phone_no, "1", "11");
    $api_response_status = 2;
   }

   if($hdk_verdict === "failed"){
    $api_response = "failed";
    $api_response_reference = $curl_json_result["id"];
    $api_response_text = $curl_json_result["Status"];
    $api_response_description = "Transaction Failed | ".strtoupper(str_replace(["_","-"]," ",$quantity))." data to 234".substr($phone_no, "1", "11")." was not delivered";
    $api_response_status = 3;
   }

   if($hdk_verdict === null){
    // Nothing readable came back: fail it only when the request provably never left us.
    $hdk_transport = function_exists('bc_gateway_curl_outcome') ? bc_gateway_curl_outcome(curl_errno($curl_request)) : "pending";
    if($hdk_transport === "failed"){
     $api_response = "failed";
     $api_response_text = "";
     $api_response_description = "Transaction Failed | the data provider could not be reached";
     $api_response_status = 3;
    }else{
     $api_response = "pending";
     $api_response_text = "";
     $api_response_description = "Transaction Pending | awaiting confirmation from the data provider";
     $api_response_status = 2;
    }
    if(function_exists('bc_gateway_log_raw_response')){ bc_gateway_log_raw_response($curl_result, "HDK-UNRESOLVED(errno=".curl_errno($curl_request).")"); }
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
if (function_exists('bc_gateway_settle_purchase')) { bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, "Transaction Pending | awaiting confirmation from the data provider", $curl_result ?? ""); }
?>
