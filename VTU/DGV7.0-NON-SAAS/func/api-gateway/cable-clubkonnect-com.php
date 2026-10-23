<?php
$data_service_provider_alter_code = array("startimes" => "startimes", "dstv" => "dstv", "gotv" => "gotv");
if (in_array($product_name, array_keys($data_service_provider_alter_code))) {
 if ($product_name == "startimes") {
  $web_cable_size_array = array("nova_weekly" => "nova-weekly", "basic_weekly" => "basic-weekly", "smart_weekly" => "smart-weekly", "classic_weekly" => "classic-weekly", "super_weekly" => "super-weekly", "nova" => "nova", "basic" => "basic", "smart" => "smart", "classic" => "classic", "super" => "super", "chinese_dish" => "uni-1", "nova_antenna" => "uni-2", "special_weekly" => "special-weekly", "special_monthly" => "special-monthly", "nova_dish_weekly" => "nova-dish-weekly", "super_antenna_weekly" => "super-antenna-weekly", "super_antenna_monthly" => "super-antenna-monthly", "combo_smart_basic_weekly" => "combo-smart-basic-weekly", "combo_special_basic_weekly" => "combo-special-basic-weekly", "combo_super_classic_weekly" => "combo-super-classic--weekly", "combo_smart_basic_monthly" => "combo-smart-basic-monthly", "combo_special_basic_monthly" => "combo-special-basic-monthly", "combo_super_classic_monthly" => "combo-super-classic--monthly");
  $clubkonnect_isp_code = "startimes";
 } else {
  if ($product_name == "dstv") {
   $web_cable_size_array = array("padi" => "dstv-padi", "yanga" => "dstv-yanga", "confam" => "dstv-confam", "compact" => "dstv79", "premium" => "dstv3", "asia" => "dstv6", "padi_extraview" => "padi-extra", "yanga_extraview" => "yanga-extra", "confam_extraview" => "confam-extra", "compact_extra_view" => "dstv30", "compact_plus" => "dstv7", "compact_asia_extraview" => "com-asia-extra", "compact_plus_extra_view" => "dstv45", "compact_plus_frenchplus_extra_view" => "complus-french-extraview", "compact_plus_asia_extraview" => "dstv48", "premium_extra_view" => "dstv33", "premium_asia_extra_view" => "dstv61", "premium_french_extra_view" => "dstv62");
   $clubkonnect_isp_code = "dstv";
  } else {
   if ($product_name == "gotv") {
    $web_cable_size_array = array("smallie" => "gotv-smallie", "jinja" => "gotv-jinja", "jolli" => "gotv-jolli", "max" => "gotv-max", "super" => "gotv-supa");
    $clubkonnect_isp_code = "gotv";
   }
  }
 }
 if (in_array($quantity, array_keys($web_cable_size_array))) {

  $clubkonnect_reference = substr(str_shuffle("12345678901234567890"), 0, 15);
  $explode_clubkonnect_apikey = array_filter(explode(":", trim($api_detail["api_key"])));
  $curl_url = "https://www.nellobytesystems.com/APICableTVV1.asp?UserID=" . $explode_clubkonnect_apikey[0] . "&APIKey=" . $explode_clubkonnect_apikey[1] . "&CableTV=".$clubkonnect_isp_code."&Package=".$web_cable_size_array[$quantity]."&SmartCardNo=".$iuc_no."&PhoneNo=09111111111&RequestID=" . $clubkonnect_reference;
 
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

  if (curl_errno($curl_request)) {
   $api_response = "failed";
   $api_response_text = 1;
   $api_response_description = "";
   $api_response_status = 3;
  }

  if(in_array($curl_json_result["statuscode"],array(200, 201, 299))){
   $api_response = "successful";
   $api_response_reference = $curl_json_result["orderid"];
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Successful | " . str_replace(["_", "-"], " ", $web_cable_size_array[$quantity]) . " to IUC No: " . $iuc_no . " was successful";
   $api_response_status = 1;
  }

  if(in_array($curl_json_result["statuscode"],array(100, 300))){
   $api_response = "pending";
   $api_response_reference = $curl_json_result["orderid"];
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Pending | " . str_replace(["_", "-"], " ", $web_cable_size_array[$quantity]) . " to IUC No: " . $iuc_no . " was pending";
   $api_response_status = 2;
  }

  if(!in_array($curl_json_result["statuscode"],array(100, 300, 200, 201, 299))){
   $api_response = "failed";
   $api_response_text = $curl_json_result["status"];
   $api_response_description = "Transaction Failed | " . str_replace(["_", "-"], " ", $web_cable_size_array[$quantity]) . " to IUC No: " . $iuc_no . " failed";
   $api_response_status = 3;
  }
 } else {
  //Cable size not available
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