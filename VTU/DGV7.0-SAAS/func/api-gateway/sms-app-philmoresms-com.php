<?php
	$sms_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");
	if(in_array($product_name, array_keys($sms_service_provider_alter_code))){
        // Only Promotional SMS is supported via the new PhilmoreSMS developer API.
        // The endpoint does not differentiate gateway type — standard (promotional) only.
	    $web_sms_size_array = array("standard_sms" => "standard_sms");

	if(in_array($sms_type, array_keys($web_sms_size_array))){
            $api_token = trim($api_detail["api_key"]); // Single token — no colon-split needed

            $curl_url = "https://app.philmoresms.com/api/sms.php";
            $post_data = http_build_query(array(
                'token'      => $api_token,
                'senderID'   => $sender_id,
                'recipients' => $phone_no,
                'message'    => urldecode($text_message),
            ));

            $curl_request = curl_init($curl_url);
            curl_setopt($curl_request, CURLOPT_POST, true);
            curl_setopt($curl_request, CURLOPT_POSTFIELDS, $post_data);
            curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl_request, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
            $curl_result = curl_exec($curl_request);
            $curl_json_result = json_decode($curl_result, true);
            if(!is_array($curl_json_result)){ $curl_json_result = array(); }

            if(curl_errno($curl_request)){
                $api_response = "failed";
                $api_response_text = 1;
                $api_response_description = "";
                $api_response_status = 3;
            } else {
                if(isset($curl_json_result["error_code"]) && $curl_json_result["error_code"] === "000"){
                    $api_response = "successful";
                    $api_response_reference = $curl_json_result["reference"] ?? uniqid('SMS');
                    $api_response_text = $curl_json_result["status"];
                    $api_response_description = "Transaction Successful";
                    $api_response_status = 1;
                } else {
                    $api_response = "failed";
                    $api_response_text = $curl_json_result["error_code"] ?? "error";
                    $api_response_description = "Transaction Failed";
                    $api_response_status = 3;
                }
            }
            curl_close($curl_request);
        }else{
        //sms size not available
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
?>