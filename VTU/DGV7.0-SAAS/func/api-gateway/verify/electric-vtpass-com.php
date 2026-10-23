<?php
    $web_electric_size_array = array("ekedc"=>"eko-electric","eedc"=>"enugu-electric","ikedc"=>"ikeja-electric","jedc"=>"jos-electric","kedco"=>"kano-electric","ibedc"=>"ibadan-electric","phed"=>"portharcourt-electric","aedc"=>"abuja-electric","yedc"=>"yola-electric", "bedc" => "benin-electric", "aba" => "aba-electric", "kaedco" => "kaduna-electric");
    if(in_array($epp, array_keys($web_electric_size_array))){
        $curl_url = "https://vtpass.com/api/merchant-verify";
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
            "Authorization: Basic ".base64_encode($api_detail["api_key"]),
            "Content-Type: application/json",
        );
        curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
        $curl_postfields_data = json_encode(array("billersCode"=>$meter_number,"serviceID"=>$web_electric_size_array[$epp],"type"=>$type),true);
        curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
        $curl_result = curl_exec($curl_request);
        $curl_json_result = json_decode($curl_result, true);
if(!is_array($curl_json_result) || !isset($curl_json_result["code"])){
        $curl_json_result = array("code" => "999", "response_description" => "Invalid API response. Check your API credentials and try again.");
        }
        

        if(in_array($curl_json_result["code"],array("000"))){
            $api_response = "successful";
            $api_response_text = "";
            $api_response_description = $curl_json_result["content"]["Customer_Name"];
            $api_response_status = 1;
        }

        if(!in_array($curl_json_result["code"],array("000"))){
            $api_response = "failed";
            $api_response_text = "";
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