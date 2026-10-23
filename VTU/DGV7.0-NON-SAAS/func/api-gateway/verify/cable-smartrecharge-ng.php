<?php
    $data_service_provider_alter_code = array("startimes" => "startimes", "dstv" => "dstv", "gotv" => "gotv");
    if(in_array($product_name, array_keys($data_service_provider_alter_code))){
    	if($product_name == "startimes"){
    		$web_cable_size_array = array("nova" => "nova", "basic" => "basic", "smart" => "smart", "classic" => "classic", "super" => "super");
    		$api_product_code = $product_name."_".$quantity;
    	}else{
    		if($product_name == "dstv"){
    			$web_cable_size_array = array("padi" => "padi", "yanga" => "yanga", "confam" => "confam", "compact" => "compact", "compact_plus" => "compact_plus");
    			$api_product_code = $product_name."_".$quantity;
    		}else{
    			if($product_name == "gotv"){
    				$web_cable_size_array = array("smallie" => "smallie", "jinja" => "jinja", "jolli" => "jolli", "max" => "max", "super" => "super");
    				$api_product_code = $product_name."_".$quantity;
    			}
    		}
    	}
    	
    	if(in_array($quantity, array_keys($web_cable_size_array))){
    		$curl_url = "https://".$api_detail["api_base_url"]."/api/v2/tv/?api_key=".$api_detail["api_key"]."&smartcard_number=".$iuc_no."&product_code=".$isp."_".$quantity."&task=verify";
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
        	

        	if(in_array($curl_json_result["error_code"],array(1987))){
            	$api_response = "successful";
            	$api_response_text = $curl_json_result["data"]["text_status"];
            	$api_response_description = $curl_json_result["data"]["name"];
            	$api_response_status = 1;
        	}

        	if(!in_array($curl_json_result["error_code"],array(1987))){
            	$api_response = "failed";
            	$api_response_text = $curl_json_result["data"]["text_status"];
            	$api_response_description = "Err: Cannot Verify Customer";
            	$api_response_status = 3;
        	}
    	}else{
        	//Cable size not available
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