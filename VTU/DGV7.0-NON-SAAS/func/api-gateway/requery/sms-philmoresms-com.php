<?php
    if(!empty($requery_reference)){
        $explode_philmoresms_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
        $curl_url = "https://smsc.philmoresms.com/smsAPI&groupstatus&apikey=".$explode_philmoresms_apikey[0]."&apitoken=".$explode_philmoresms_apikey[1]."&groupid=".getTransaction($requery_reference, "api_reference");
        $curl_request = curl_init($curl_url);
        curl_setopt($curl_request, CURLOPT_HTTPGET, true);
        curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
        $curl_result = trim(curl_exec($curl_request));
        $curl_json_result = json_decode($curl_result, true);
        if(!is_array($curl_json_result)){ $curl_json_result = array(); }
        

        if(in_array($curl_json_result["status"],array("success"))){
            $api_response = "successful";
            $api_response_reference = $curl_json_result["group_id"];
            $api_response_text = "";
            $api_response_description = "Transaction Successful";
            $api_response_status = 1;
        }
		
		if(in_array($curl_json_result["status"],array("queued"))){
			$api_response = "pending";
			$api_response_reference = $curl_json_result["group_id"];
			$api_response_text = "";
			$api_response_description = "Transaction Pending";
			$api_response_status = 2;
		}
		
        if(in_array($curl_json_result["status"],array("error"))){
            $api_response = "failed";
            $api_response_text = "";
            $api_response_description = "Transaction Failed";
            $api_response_status = 3;
        }
    }else{
        //Sms ref empty
        $api_response = "failed";
        $api_response_text = "";
        $api_response_description = "";
        $api_response_status = 3;
    }
curl_close($curl_request);
?>