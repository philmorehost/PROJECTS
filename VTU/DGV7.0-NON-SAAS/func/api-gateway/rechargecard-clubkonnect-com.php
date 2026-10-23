<?php
	$recharge_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");
 if(in_array($product_name, array_keys($recharge_service_provider_alter_code))){
  if($product_name == "mtn"){
			$clubkonnect_isp_code = "01";
   $web_recharge_size_array = array("100"=>"100","200"=>"200","500"=>"500");
  }else{
   if($product_name == "airtel"){
				$clubkonnect_isp_code = "04";
    $web_recharge_size_array = array("100"=>"100","200"=>"200","500"=>"500");
   }else{
    if($product_name == "glo"){
					$clubkonnect_isp_code = "02";
     $web_recharge_size_array = array("100"=>"100","200"=>"200","500"=>"500");
    }else{
     if($product_name == "9mobile"){
						$clubkonnect_isp_code = "03";
      $web_recharge_size_array = array("100"=>"100","200"=>"200","500"=>"500");
     }
    }
   }
  }
		
		if(in_array($quantity, array_keys($web_recharge_size_array))){
			$card_name = $isp."_".$quantity;
			$explode_clubkonnect_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
			$curl_url = "https://www.nellobytesystems.com/APIEPINV1.asp?UserID=".$explode_clubkonnect_apikey[0]."&APIKey=".$explode_clubkonnect_apikey[1]."&MobileNetwork=".$clubkonnect_isp_code."&Value=".$web_recharge_size_array[$quantity]."&Quantity=".$qty_number;
			/*$curl_request = curl_init($curl_url);
			curl_setopt($curl_request, CURLOPT_HTTPGET, true);
			curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
 curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, true);
			curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, true);
			$curl_result = curl_exec($curl_request);
			$curl_json_result = json_decode($curl_result, true);
			if(!is_array($curl_json_result)){ $curl_json_result = array(); }
			*/
			
			/*$curl_json_result = '{"TXN_EPhIN":[ {"transactionid":"6329036611","transactiondate":"12/20/2019 9:08:00 PM","mobilenetwork":"MTN","amount":"100","batchno":"82057","sno":"00000003802132587","pin":"14819613681469920"},{"transactionid":"6329036611","transactiondate":"12/20/2019 9:08:00 PM","mobilenetwork":"MTN","amount":"100","batchno":"82057","sno":"00000003802132587","pin":"64819613681469920"}]}';
			$curl_json_result = json_decode($curl_json_result, true);
			if(!is_array($curl_json_result)){ $curl_json_result = array(); }*/
			var_dump($curl_url);
			if(count($curl_json_result["TXN_EPIN"]) > 0){
				$recharge_pin_stack = "";
				foreach($curl_json_result["TXN_EPIN"] as $recharge_card_json){
					$json_decode_recharge_card_json = $recharge_card_json;
					$recharge_pin_stack .= $json_decode_recharge_card_json["pin"].",";
				}
				
				mysqli_query($connection_server, "INSERT INTO sas_card_purchaseds (vendor_id, reference, business_name, card_type, username, card_name, cards) VALUES ('".$get_logged_user_details["vendor_id"]."', '$reference', '$business_name', '$type', '".$get_logged_user_details["username"]."', '$card_name', '".str_replace(",",",",$recharge_pin_stack)."')");
				$users_card_purchased = str_replace(",",",",$recharge_pin_stack);
				
				$api_response = "successful";
				$api_response_reference = $curl_json_result["TXN_EPIN"][0]["transactionid"];
				$api_response_text = "success";
				$api_response_description = "Transaction Successful";
				$api_response_status = 1;
			}
				
			if(($curl_json_result["TXN_EPIN"] == false) || (count($curl_json_result["TXN_EPIN"]) == 0)){
				$api_response = "failed";
				$api_response_text = "failed";
				$api_response_description = "Transaction Failed".$curl_json_result.json_encode($curl_json_result, true);
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