<?php
    $web_electric_size_array = array("ekedc" => "ekedc", "eedc" => "eedc", "ikedc" => "ikedc", "jedc" => "jedc", "kedco" => "kedco", "ibedc" => "ibedc", "phed" => "phed", "aedc" => "aedc", "bedc" => "bedc", "kaedco" => "kaedco", "aba" => "aba", "yedc" => "yedc");
    if(in_array($epp, array_keys($web_electric_size_array))){
        $curl_url = "https://".$api_detail["api_base_url"]."/web/api/verify-electric.php";
        $curl_request = curl_init($curl_url);
// cURL waits forever by default: one unresponsive provider would hold this PHP worker and the
// customer's request open indefinitely. Bound the connect and the whole call.
curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl_request, CURLOPT_POST, true);
        curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
        $curl_http_headers = array(
        	"Content-Type: application/json",
        );
        curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
        $curl_postfields_data = json_encode(array("api_key"=> $api_detail["api_key"],"type"=> $type,"meter_number"=> $meter_number,"provider"=> $web_electric_size_array[$epp]), true);
        curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
        $curl_result = curl_exec($curl_request);
        $curl_json_result = json_decode($curl_result, true);
        if(!is_array($curl_json_result)){ $curl_json_result = array(); }
        
        
        if(in_array($curl_json_result["status"],array("success"))){
        	$api_response = "successful";
        	$api_response_text = $curl_json_result["status"];
        	$api_response_description = $curl_json_result["desc"];
        	$api_response_status = 1;
        }
        
        if(in_array($curl_json_result["status"],array("failed"))){
        	$api_response = "failed";
        	$api_response_text = $curl_json_result["status"];
        	$api_response_description = "Err: Cannot Verify Customer";
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