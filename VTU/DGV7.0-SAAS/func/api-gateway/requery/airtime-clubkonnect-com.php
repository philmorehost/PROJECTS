<?php
    $explode_clubkonnect_apikey = array_filter(explode(":",trim($api_detail["api_key"])));
    $ck_user_id = $explode_clubkonnect_apikey[0] ?? '';
    $ck_api_key = $explode_clubkonnect_apikey[1] ?? '';

    if(!empty($get_api_reference_id)){
        $curl_url = "https://www.nellobytesystems.com/APIQueryV1.asp?UserID=".$ck_user_id."&APIKey=".$ck_api_key."&OrderID=".urlencode($get_api_reference_id);
    } else {
        $req_id = preg_replace('/[^A-Za-z0-9]/', '', $requery_reference);
        $curl_url = "https://www.nellobytesystems.com/APIQueryV1.asp?UserID=".$ck_user_id."&APIKey=".$ck_api_key."&RequestID=".urlencode($req_id);
    }

    $curl_request = curl_init($curl_url);
    curl_setopt($curl_request, CURLOPT_HTTPGET, true);
    curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl_request, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
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
        $api_response_description = str_replace(["pending","failed"], "successful", str_replace(["Transaction Pending","Transaction Failed"], "Transaction Successful", getTransaction($requery_reference, "description")));
        $api_response_status = 1;
    } elseif(in_array($sc, array(100, 300))){
        $api_response = "pending";
        $api_response_reference = $curl_json_result["orderid"] ?? $get_api_reference_id;
        $api_response_text = $os;
        $api_response_description = str_replace(["successful","failed"], "pending", str_replace(["Transaction Successful","Transaction Failed"], "Transaction Pending", getTransaction($requery_reference, "description")));
        $api_response_status = 2;
    } else {
        // A reply we cannot READ is not a reply that FAILED. nellobytes/clubkonnect answers with a
        // statuscode (200/201/299 success, 100/300 pending, other = failure); a body carrying no
        // statuscode and no status word at all is an unreadable answer - a rate limit, an HTML error
        // page, a truncated response - and the provider may well have delivered. Calling it "failed"
        // told the customer their purchase had failed AND authorised a refund, because the sentinel
        // below is a real provider word so the refund gate passed. That is how one run of unreadable
        // replies became a wall of failed airtime purchases with no refund.
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