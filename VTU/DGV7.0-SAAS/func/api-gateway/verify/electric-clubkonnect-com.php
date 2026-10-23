<?php
    $web_electric_size_array = array("ekedc"=>"01","eedc"=>"09","ikedc"=>"02","jedc"=>"06","kedco"=>"04","ibedc"=>"07","phed"=>"05","aedc"=>"03", "bedc"=>"08", "kaedco"=>"10", "yedc"=>"11", "aba"=>"12");
    if(in_array($epp, array_keys($web_electric_size_array))){
        $clubkonnect_meter_type = array("prepaid"=>"01","postpaid"=>"02");
        $explode_clubkonnect_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
        $curl_url = "https://www.nellobytesystems.com/APIVerifyElectricityV1.asp?UserID=".$explode_clubkonnect_apikey[0]."&APIKey=".$explode_clubkonnect_apikey[1]."&ElectricCompany=".$web_electric_size_array[$epp]."&MeterNo=".$meter_number;
        $curl_request = curl_init($curl_url);
// cURL waits forever by default: one unresponsive provider would hold this PHP worker and the
// customer's request open indefinitely. Bound the connect and the whole call.
curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl_request, CURLOPT_HTTPGET, true);
        curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
        $curl_result = curl_exec($curl_request);
        $curl_json_result = json_decode($curl_result, true);
        if(!is_array($curl_json_result)){ $curl_json_result = array(); }
        

        if(in_array($curl_json_result["status"],array("00"))){
            $api_response = "successful";
            $api_response_text = "";
            $api_response_description = $curl_json_result["customer_name"];
            $api_response_status = 1;
        }

        if(in_array($curl_json_result["status"],array("100"))){
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