<?php
if ($isp == "mtn") {
 $localserver_isp_code = "1";
} else {
 if ($isp == "airtel") {
  $localserver_isp_code = "4";
 } else {
  if ($isp == "glo") {
   $localserver_isp_code = "2";
  } else {
   if ($isp == "9mobile") {
    $localserver_isp_code = "3";
   }
  }
 }
}

$curl_url = "https://" . $api_detail["api_base_url"] . "/api/topup/";
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
$curl_postfields_data = json_encode(array("network" => $localserver_isp_code, "amount" => $amount, "mobile_number" => $phone_no, "Ported_number" => true, "airtime_type" => "VTU"), true);
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
 $api_response_description = "Transaction Successful | N" . $amount . " Airtime to 234" . substr($phone_no, "1", "11") . " was successful";
 $api_response_status = 1;
}

if (in_array($curl_json_result["Status"], array("pending"))) {
 $api_response = "pending";
 $api_response_reference = $curl_json_result["id"];
 $api_response_text = $curl_json_result["Status"];
 $api_response_description = "Transaction Pending | N" . $amount . " Airtime to 234" . substr($phone_no, "1", "11") . " was pending";
 $api_response_status = 2;
}

if (!in_array($curl_json_result["Status"], array("successful", "pending"))) {
 $api_response = "failed";
 $api_response_text = $curl_json_result["Status"];
 $api_response_description = "Transaction Failed | N" . $amount . " Airtime to 234" . substr($phone_no, "1", "11") . " failed";
 $api_response_status = 3;
}
curl_close($curl_request);
?>