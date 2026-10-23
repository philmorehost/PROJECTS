<?php
    $data_service_provider_alter_code = array("startimes" => "startimes", "dstv" => "dstv", "gotv" => "gotv");
    if(in_array($product_name, array_keys($data_service_provider_alter_code))){
        if($product_name == "startimes"){
            $web_cable_size_array = array("nova_weekly" => "nova_weekly", "basic_weekly" => "basic_weekly", "smart_weekly" => "smart_weekly", "classic_weekly" => "classic_weekly", "super_weekly" => "super_weekly", "nova" => "nova", "basic" => "basic", "smart" => "smart", "classic" => "classic", "super" => "super", "chinese_dish" => "chinese_dish", "nova_antenna" => "nova_antenna", "special_weekly" => "special_weekly", "special_monthly" => "special_monthly", "nova_dish_weekly" => "nova_dish_weekly", "super_antenna_weekly" => "super_antenna_weekly", "super_antenna_monthly" => "super_antenna_monthly", "combo_smart_basic_weekly" => "combo_smart_basic_weekly", "combo_special_basic_weekly" => "combo_special_basic_weekly", "combo_super_classic_weekly" => "combo_super_classic_weekly", "combo_smart_basic_monthly" => "combo_smart_basic_monthly", "combo_special_basic_monthly" => "combo_special_basic_monthly", "combo_super_classic_monthly" => "combo_super_classic_monthly");
    	}else{
            if($product_name == "dstv"){
                $web_cable_size_array = array("padi" => "padi", "yanga" => "yanga", "confam" => "confam", "compact" => "compact", "premium" => "premium", "asia" => "asia", "padi_extraview" => "padi_extraview", "yanga_extraview" => "yanga_extraview", "confam_extraview" => "confam_extraview", "compact_extra_view" => "compact_extra_view", "compact_plus" => "compact_plus", "compact_asia_extraview" => "compact_asia_extraview", "compact_plus_extra_view" => "compact_plus_extra_view", "compact_plus_frenchplus_extra_view" => "compact_plus_frenchplus_extra_view", "compact_plus_asia_extraview" => "compact_plus_asia_extraview", "premium_extra_view" => "premium_extra_view", "premium_asia_extra_view" => "premium_asia_extra_view", "premium_french_extra_view" => "premium_french_extra_view");
            }else{
                if($product_name == "gotv"){
                    $web_cable_size_array = array("smallie" => "smallie", "jinja" => "jinja", "jolli" => "jolli", "max" => "max", "super" => "super");
                }
            }
        }
    	
    	if(in_array($quantity, array_keys($web_cable_size_array))){
    		$curl_url = "https://".$api_detail["api_base_url"]."/web/api/verify-cable.php";
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
    		$curl_postfields_data = json_encode(array("api_key"=> $api_detail["api_key"],"type"=> $product_name,"iuc_number"=> $iuc_no,"package"=> $web_cable_size_array[$quantity]), true);
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