<?php
	$sms_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");
	if(in_array($product_name, array_keys($sms_service_provider_alter_code))){
	 if($product_name == "mtn"){
	$web_sms_size_array = array("standard_sms" => "dnd", "in_app_otp" => "dnd");
	}else{
	if($product_name == "airtel"){
	$web_sms_size_array = array("standard_sms" => "dnd", "in_app_otp" => "dnd");
			}else{
	if($product_name == "glo"){
	$web_sms_size_array = array("standard_sms" => "dnd", "in_app_otp" => "dnd");
	}else{
	if($product_name == "9mobile"){
	$web_sms_size_array = array("standard_sms" => "dnd", "in_app_otp" => "dnd");
					}
	}
	}
	}

	if(in_array($sms_type, array_keys($web_sms_size_array))){
			$explode_termii_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
	if(in_array($sms_type, array("standard_sms"))){
		$curl_url = "https://api.ng.termii.com/api/sms/send/bulk";
	}

	if(in_array($sms_type, array("otp"))){
		$curl_url = "https://api.ng.termii.com/api/sms/otp/generate";
		$otp_type_array_2 = array("numeric" => "NUMERIC", "alphanumeric" => "ALPHANUMERIC");
		if(in_array($otp_type, array_keys($otp_type_array_2))){
			$otp_type_text = $otp_type_array_2[$otp_type];
		}else{
			$otp_type_text = "";
		}
	}

	$curl_request = curl_init($curl_url);
	curl_setopt($curl_request, CURLOPT_POST, true);
	curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
	$headers = array(
		"Content-Type: application/json",
	);
	curl_setopt($curl_request, CURLOPT_HTTPHEADER, $headers);
	$sms_api_gateway_phone_no_array = array();
	foreach(explode(",",$phone_no) as $each_phone){
		$refined_phone_no = "234".substr($each_phone, (strlen($each_phone)-10), strlen($each_phone));
		array_push($sms_api_gateway_phone_no_array, $refined_phone_no);
	}

	if(in_array($sms_type, array("standard_sms"))){
		$curl_postfields_data = json_encode(array("api_key"=> $explode_termii_apikey[0],"to"=> $sms_api_gateway_phone_no_array,"from"=> $sender_id,"sms"=> $text_message, "type" => "plain", "channel" => $web_sms_size_array[$sms_type]), true);
	}
	if(in_array($sms_type, array("otp"))){
		$curl_postfields_data = json_encode(array("api_key"=> $explode_termii_apikey[0],"pin_type" => $otp_type_text, "phone_number"=> $sms_api_gateway_phone_no_array[0], "pin_attempts" => $pin_attempts, "pin_time_to_live" => $expiration_time, "pin_length" => $pin_length), true);
	}
	curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
	$curl_result = curl_exec($curl_request);
	$curl_json_result = json_decode($curl_result, true);
if(!is_array($curl_json_result) || !isset($curl_json_result["code"])){
	$curl_json_result = array("code" => "999", "response_description" => "Invalid API response. Check your API credentials and try again.");
	}

			
			if(curl_errno($curl_request)){
				$api_response = "failed";
				$api_response_text = 1;
				$api_response_description = "";
				$api_response_status = 3;
			}
			
			if(in_array($sms_type, array("standard_sms"))){
				if(in_array($curl_json_result["code"],array("ok"))){
					$api_response = "successful";
					$api_response_reference = $curl_json_result["message_id"];
					$api_response_text = "";
					$api_response_description = "Transaction Successful";
					$api_response_status = 1;
				}
			
				if(!in_array($curl_json_result["code"],array("ok"))){
					$api_response = "failed";
					$api_response_text = "";
					$api_response_description = "Transaction Failed";
					$api_response_status = 3;
				}
			}

			if(in_array($sms_type, array("otp"))){
				if(in_array($curl_json_result["status"],array("success"))){
					$api_response = "successful";
					$api_response_reference = $curl_json_result["data"]["pin_id"];
					$api_response_text = $curl_json_result["data"]["otp"];
					$api_response_description = "Transaction Successful";
					$api_response_status = 1;
				}
			
				if(!in_array($curl_json_result["status"],array("success"))){
					$api_response = "failed";
					$api_response_text = "";
					$api_response_description = "Transaction Failed";
					$api_response_status = 3;
				}
			}
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
curl_close($curl_request);
?>