<?php
    $explode_clubkonnect_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
    $curl_url = "https://www.nellobytesystems.com/APIQueryV1.asp?UserID=".$explode_clubkonnect_apikey[0]."&APIKey=".$explode_clubkonnect_apikey[1]."&OrderID=".$get_api_reference_id;
    $curl_request = curl_init($curl_url);
    curl_setopt($curl_request, CURLOPT_HTTPGET, true);
    curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
    $curl_result = trim(curl_exec($curl_request));
	if (strtoupper((string)($purchase_method ?? "")) === "CRON_JOB" && function_exists("bc_requery_queue_log")) {
		$curl_errno = curl_errno($curl_request);
		$curl_error = curl_error($curl_request);
		$curl_http_code = curl_getinfo($curl_request, CURLINFO_HTTP_CODE);
	}
    $curl_json_result = json_decode($curl_result, true);
    if(!is_array($curl_json_result)){ $curl_json_result = array(); }
    
    
    $sc = $curl_json_result["statuscode"] ?? '';
    $os = $curl_json_result["status"] ?? $curl_json_result["orderstatus"] ?? '';
    if(empty($sc) && stripos($curl_result, "Transaction Successful") !== false) $sc = 200;
    if(empty($sc) && stripos($curl_result, "Order Received") !== false) $sc = 100;

    if(in_array($sc, array(200, 201, 299)) || stripos($os, "Successful") !== false || stripos($os, "COMPLETED") !== false || stripos($os, "Delivered") !== false){
        $api_response = "successful";
        $api_response_reference = $curl_json_result["orderid"] ?? $get_api_reference_id;
        $api_response_text = $os;
        $api_response_description = "Transaction Successful | ".($curl_json_result["carddetails"] ?? "");
        $api_response_status = 1;
    } elseif(in_array($sc, array(100, 300))){
        $api_response = "pending";
        $api_response_reference = $curl_json_result["orderid"] ?? $get_api_reference_id;
        $api_response_text = $os;
        $api_response_description = "Transaction Pending | ".($curl_json_result["carddetails"] ?? "");
        $api_response_status = 2;
    } else {
        // A reply we cannot READ is not a reply that FAILED. nellobytes/clubkonnect answers with a
        // statuscode (200/201/299 success, 100/300 pending, anything else a failure); a body carrying
        // no statuscode and no status word is an unreadable answer - a rate limit, an HTML error page,
        // a truncated response - and the provider may well have delivered. Declaring it "failed" told
        // the customer their purchase had failed AND authorised a refund, because the sentinel below
        // is a real provider word, so the refund gate passed on it.
        if ($sc !== '' && $sc !== null) {
            $api_response = "failed";
            $api_response_text = $os ?: "FAILED";
            $api_response_description = "Transaction Failed | ".($curl_json_result["orderremark"] ?? "provider rejected");
            $api_response_status = 3;
        } else {
            $api_response = "pending";
            $api_response_text = "";
            $api_response_description = "Transaction Pending | awaiting confirmation from the provider (unreadable requery reply)";
            $api_response_status = 2;
        }
    }
curl_close($curl_request);
?>