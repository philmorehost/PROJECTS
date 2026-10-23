<?php
  $curl_url = "https://api.mtn.com/v1/oauth/access_token?grant_type=client_credentials";
	$curl_request = curl_init($curl_url);
	curl_setopt($curl_request, CURLOPT_POST, true);
	curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
	$curl_http_headers = array(
		"Content-Type:  application/x-www-form-urlencoded"
	);
	curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
	$legitdataway_reference = substr(str_shuffle("12345678901234567890"), 0, 15);
	$curl_postfields_data = http_build_query(array("client_id"=>"pZc1jUfjObP9eHA8WuLNwGS5Lth0z7ja","client_secret"=>"XCK44dAx1aLG4Kog"));
	curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
	$curl_result = curl_exec($curl_request);
	$curl_json_result = json_decode($curl_result, true);
	echo "access_token: ".$curl_json_result["access_token"]."<br/>".
	"application_name: ".$curl_json_result["application_name"]."<br/>";
	
	
	//Subscription API
	$curl_subscription_url = "https://sandbox.api.mtn.com/v1/transfer/customers/2348140985576";
	$curl_subscription_request = curl_init($curl_subscription_url);
	curl_setopt($curl_subscription_request, CURLOPT_POST, true);
	curl_setopt($curl_subscription_request, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($curl_subscription_request, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl_subscription_request, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($curl_subscription_request, CURLOPT_SSL_VERIFYPEER, false);
	$curl_subscription_http_headers = array(
	  "Authorization: Bearer ".$curl_json_result["access_token"],
		"Content-Type:  application/json",
		"Accept:  application/json",
		
	);
	curl_setopt($curl_subscription_request, CURLOPT_HTTPHEADER, $curl_subscription_http_headers);
	$legitdataway_reference = substr(str_shuffle("12345678901234567890"), 0, 15);
	$curl_subscription_postfields_data = json_encode(array("receiverMsisdn"=>"09068240860", "productCode"=>"NACT_NG_Data_4504", "sendSms"=>true), true);
	curl_setopt($curl_subscription_request, CURLOPT_POSTFIELDS, $curl_subscription_postfields_data);
	$curl_subscription_result = curl_exec($curl_subscription_request);
	$curl_subscription_json_result = json_decode($curl_subscription_result, true);
	var_dump($curl_subscription_result);
?>