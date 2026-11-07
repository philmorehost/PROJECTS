<?php
	$exam_service_provider_alter_code = array("waec" => "waec", "neco" => "neco", "nabteb" => "nabteb", "jamb" => "jamb");
	if(in_array($product_name, array_keys($exam_service_provider_alter_code))){
	 if($product_name == "waec"){
	$web_exam_size_array = array("result_checker" => "waecdirect");
			$clubkonnect_exam_base_url = "https://www.nellobytesystems.com/APIWAECV1.asp";
	}else{
	if($product_name == "neco"){
	$web_exam_size_array = array();
				$clubkonnect_exam_base_url = "";
			}else{
	if($product_name == "nabteb"){
	$web_exam_size_array = array();
					$clubkonnect_exam_base_url = "";
	}else{
	if($product_name == "jamb"){
						$clubkonnect_exam_base_url = "https://www.nellobytesystems.com/APIJAMBV1.asp";
	$web_exam_size_array = array("direct_entry" => "de", "utme_with_mock" => "utme-mock", "utme_without_mock" => "utme-no-mock");
					}
	}
	}
	}

	if(in_array($quantity, array_keys($web_exam_size_array))){
			$explode_clubkonnect_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
	$curl_url = $clubkonnect_exam_base_url."?UserID=".$explode_clubkonnect_apikey[0]."&APIKey=".$explode_clubkonnect_apikey[1]."&ExamType=".$web_exam_size_array[$quantity]."&PhoneNo=09111111111";
	$curl_request = curl_init($curl_url);
	curl_setopt($curl_request, CURLOPT_POST, true);
	curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
	$abumpay_reference = substr(str_shuffle("12345678901234567890"), 0, 15);
	$curl_postfields_data = json_encode(array("token"=>$api_detail["api_key"],"service_id"=>$web_exam_size_array[$epp],"request_id"=>$abumpay_reference),true);
	curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
	$curl_result = curl_exec($curl_request);
	$curl_json_result = json_decode($curl_result, true);
	if(!is_array($curl_json_result)){ $curl_json_result = array(); }

			if(curl_errno($curl_request)){
				$api_response = "failed";
				$api_response_text = 1;
				$api_response_description = "";
				$api_response_status = 3;
			}
			
			if(in_array($curl_json_result["statuscode"],array(200, 201, 299))){
	$api_response = "successful";
	$api_response_reference = $curl_json_result["orderid"];
	$api_response_text = $curl_json_result["status"];
	$api_response_description = "Transaction Successful | ".$curl_json_result["carddetails"];
	$api_response_status = 1;
   }

   if(in_array($curl_json_result["statuscode"],array(100, 300))){
	$api_response = "pending";
	$api_response_reference = $curl_json_result["orderid"];
	$api_response_text = $curl_json_result["status"];
	$api_response_description = "Transaction Pending | ".$curl_json_result["carddetails"];
	$api_response_status = 2;
   }

   if(!in_array($curl_json_result["statuscode"],array(100, 300, 200, 201, 299))){
	$api_response = "failed";
	$api_response_text = $curl_json_result["status"];
	$api_response_description = "Transaction Failed";
	$api_response_status = 3;
   }
	}else{
	//Exam size not available
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