<?php
$data_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "9mobile" => "9mobile");
if (in_array($product_name, array_keys($data_service_provider_alter_code))) {
 if ($product_name == "mtn") {
  $net_id = "1";
  $web_data_size_array = array("75mb_1day" => "321","110mb_1day" => "320","230mb_1day" => "333","500mb" => "366","750mb_Free_1hour_TT/YT/IG_3days" => "333","1gb_1.5mins_1day" => "215","1.5gb" => "364","2.5gb_1day" => "316","2.5gb_2days" => "317","1gb_7days" => "342","2gb_2days" => "318","1.5gb_7days" => "365","3.2gb_2days" => "216","6gb_7days" => "217","11gb_7days" => "389","7gb_30days" => "370","10gb_10mins+2gb_YouTube_30days" => "351","12.5gb_30days" => "349","16.5gb_10Minutes_30days" => "348","40gb_60days" => "324","36gb_30days" => "352","75gb_30days" => "306","90gb_60days" => "327","165gb_30days" => "355","150gb_60days" => "326","200gb_30days" => "359","200gb_60days" => "307","250gb_30days" => "354","200gb_30days" => "359","480gb_90days" => "358");
 } else {
  if ($product_name == "airtel") {
   $net_id = "4";
   $web_data_size_array = array("150mb_1day" => "310","300mb_2days" => "398","600mb_2days" => "397","1gb_(Social)3days" => "360","1.5gb_2days" => "386","2gb_2days" => "387","3gb_7days" => "313","5gb_2days" => "388","7gb_7days" => "304","10gb_30days" => "283");
  } else {
   if ($product_name == "glo") {
    $net_id = "2";
    $web_data_size_array = array("750mb_1day" => "286","1.5gb_1day" => "288","2.5gb_2days" => "289","10gb_7days" => "290");
   } else {
    if ($product_name == "9mobile") {
     $net_id = "3";
     $web_data_size_array = array();
    }
   }
  }
 }
 if (in_array($quantity, array_keys($web_data_size_array))) {
  $curl_url = "https://" . $api_detail["api_base_url"] . "/api/data/";
  $curl_request = curl_init($curl_url);
  curl_setopt($curl_request, CURLOPT_POST, true);
  curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
  curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
  $curl_http_headers = array(
   "Authorization: Token " . $api_detail["api_key"],
   "Content-Type: application/json",
  );
  curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
  $curl_postfields_data = json_encode(array("network" => $net_id, "plan" => $web_data_size_array[$quantity], "mobile_number" => $phone_no, "Ported_number" => true), true);
  curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
  $curl_result = curl_exec($curl_request);
  $curl_json_result = json_decode($curl_result, true);
  if(!is_array($curl_json_result)){ $curl_json_result = array(); }


  if (curl_errno($curl_request)) {
   $api_response = "failed";
   $api_response_text = 1;
   $api_response_description = "";
   $api_response_status = 3;
  }

  if (in_array($curl_json_result["Status"], array("successful"))) {
   $api_response = "successful";
   $api_response_reference = $curl_json_result["id"];
   $api_response_text = $curl_json_result["Status"];
   $api_response_description = "Transaction Successful | " . strtoupper(str_replace(["_", "-"], " ", $quantity)) . " credited to 234" . substr($phone_no, "1", "11");
   $api_response_status = 1;
  }

  if (in_array($curl_json_result["Status"], array("pending"))) {
   $api_response = "pending";
   $api_response_reference = $curl_json_result["id"];
   $api_response_text = $curl_json_result["Status"];
   $api_response_description = "Transaction Pending | " . strtoupper(str_replace(["_", "-"], " ", $quantity)) . " credited to 234" . substr($phone_no, "1", "11");
   $api_response_status = 2;
  }

  if (!in_array($curl_json_result["Status"], array("successful", "pending"))) {
   $api_response = "failed";
   $api_response_text = $curl_json_result["Status"];
   $api_response_description = "Transaction Failed | " . strtoupper(str_replace(["_", "-"], " ", $quantity)) . " data to 234" . substr($phone_no, "1", "11") . " was not delivered";
   $api_response_status = 3;
  }
 } else {
  //Data size not available
  $api_response = "failed";
  $api_response_text = "";
  $api_response_description = "";
  $api_response_status = 3;
 }
} else {
 //Service not available
 $api_response = "failed";
 $api_response_text = "";
 $api_response_description = "Service not available";
 $api_response_status = 3;
}
curl_close($curl_request);
?>