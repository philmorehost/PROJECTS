<?php
    $web_betting_size_array = array("msport" => "msport", "naijabet" => "naijabet", "nairabet" => "nairabet", "bet9ja-agent" => "bet9ja-agent", "betland" => "betland", "betlion" => "betlion", "supabet" => "supabet", "bet9ja" => "bet9ja", "bangbet" => "bangbet", "betking" => "betking", "1xbet" => "1xbet", "betway" => "betway", "merrybet" => "merrybet", "mlotto" => "mlotto", "western-lotto" => "western-lotto", "hallabet" => "hallabet", "green-lotto" => "green-lotto");
    if(in_array($epp, array_keys($web_betting_size_array))){
        $clubkonnect_meter_type = array("prepaid"=>"01","postpaid"=>"02");
        $explode_clubkonnect_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
        $curl_url = "https://www.nellobytesystems.com/APIVerifyBettingV1.asp?UserID=".$explode_clubkonnect_apikey[0]."&APIKey=".$explode_clubkonnect_apikey[1]."&BettingCompany=".$web_betting_size_array[$epp]."&CustomerID=".$customer_id;
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