<?php
	$recharge_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");
 if(in_array($product_name, array_keys($recharge_service_provider_alter_code))){
  if($product_name == "mtn"){
			$net_id = "1";
   $web_recharge_size_array = array("100"=>"1","200"=>"2","500"=>"3");
  }else{
   if($product_name == "airtel"){
				$net_id = "2";
    $web_recharge_size_array = array("100"=>"7","200"=>"8","500"=>"9");
   }else{
    if($product_name == "glo"){
					$net_id = "3";
     $web_recharge_size_array = array("100"=>"4","200"=>"5","500"=>"5");
    }else{
     if($product_name == "9mobile"){
						$net_id = "4";
      $web_recharge_size_array = array();
     }
    }
   }
  }
		
		if(in_array($quantity, array_keys($web_recharge_size_array))){
			$card_name = $isp."_".$quantity;
			$curl_url = "https://".$api_detail["api_base_url"]."/api/user";
			$curl_legigitdataway_user_request = curl_init($curl_url);
			curl_setopt($curl_legigitdataway_user_request, CURLOPT_POST, true);
			curl_setopt($curl_legigitdataway_user_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_legigitdataway_user_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_legigitdataway_user_request, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($curl_legigitdataway_user_request, CURLOPT_SSL_VERIFYHOST, true);
			curl_setopt($curl_legigitdataway_user_request, CURLOPT_SSL_VERIFYPEER, true);
			$curl_legigitdataway_user_http_headers = array(
				"Authorization: Basic ".base64_encode($api_detail["api_key"]),
				"Content-Type: application/json",
			);
			curl_setopt($curl_legigitdataway_user_request, CURLOPT_HTTPHEADER, $curl_legigitdataway_user_http_headers);
			$curl_legigitdataway_user_result = curl_exec($curl_legigitdataway_user_request);
			$curl_legigitdataway_user_json_result = json_decode($curl_legigitdataway_user_result, true);
			if(!is_array($curl_legigitdataway_user_json_result)){ $curl_legigitdataway_user_json_result = array(); }
			curl_close($curl_legigitdataway_user_request);

			if(curl_errno($curl_request)){
				$api_response = "failed";
				$api_response_text = 1;
				$api_response_description = "";
				$api_response_status = 3;
			}
			
			if($curl_legigitdataway_user_json_result["status"] == "success"){
				$curl_url = "https://".$api_detail["api_base_url"]."/api/recharge_card";
				$curl_request = curl_init($curl_url);
				curl_setopt($curl_request, CURLOPT_POST, true);
				curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
				curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
				curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
				$curl_http_headers = array(
					"Authorization: Token ".$curl_legigitdataway_user_json_result["AccessToken"],
					"Content-Type: application/json",
				);
				curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
				$legitdataway_reference = substr(str_shuffle("12345678901234567890"), 0, 15);
				$curl_postfields_data = json_encode(array("network"=>$net_id,"quantity"=>$qty_number,"plan_type"=>$web_data_size_array[$quantity],"card_name"=>$_SERVER["HTTP_HOST"]),true);
				curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
				$curl_result = curl_exec($curl_request);
				$curl_json_result = json_decode($curl_result, true);
				if(!is_array($curl_json_result)){ $curl_json_result = array(); }
				

				if(in_array($curl_json_result["status"],array("success"))){
					mysqli_query($connection_server, "INSERT INTO sas_card_purchaseds (vendor_id, reference, business_name, card_type, username, card_name, cards) VALUES ('".$get_logged_user_details["vendor_id"]."', '$reference', '$business_name', '$type', '".$get_logged_user_details["username"]."', '$card_name', '".str_replace(",",",",$curl_json_result["pin"])."')");
					$users_card_purchased = str_replace(",",",",$curl_json_result["pin"]);
					$api_response = "successful";
					$api_response_reference = $curl_json_result["request-id"];
					$api_response_text = $curl_json_result["status"];
					$api_response_description = "Transaction Successful";
					$api_response_status = 1;
				}
				
				if(!in_array($curl_json_result["status"],array("success"))){
					$api_response = "failed";
					$api_response_text = $curl_json_result["status"];
					$api_response_description = "Transaction Failed";
					$api_response_status = 3;
				}
			}else{
				//Err: Could not connect
				$api_response = "failed";
				$api_response_text = "";
				$api_response_description = "Err: Could not connect";
				$api_response_status = 3;
			}
		}else{
			//Data size not available
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