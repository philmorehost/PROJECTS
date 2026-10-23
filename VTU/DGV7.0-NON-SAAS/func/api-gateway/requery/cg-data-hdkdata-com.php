<?php
    if(!empty($requery_reference)){
        // The base URL and key must be normalised exactly like the purchase gateway does, otherwise
        // a stored "https://" scheme (-> https://https://host/...) or a key stored WITH its "Token "
        // prefix (-> 401 Unauthorized) made every requery unreadable, and the catch-all below then
        // refunded transactions HDK had actually delivered and charged for.
        $clean_base_url = rtrim(preg_replace('#^https?://#', '', trim((string)$api_detail["api_base_url"])), "/");
        $clean_api_key  = trim(str_ireplace("Token ", "", (string)$api_detail["api_key"]));
        $curl_url = "https://".$clean_base_url."/api/data/".rawurlencode((string)getTransaction($requery_reference, "api_reference"));
        $curl_request = curl_init($curl_url);
        curl_setopt($curl_request, CURLOPT_HTTPGET, true);
        curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_request, CURLOPT_TIMEOUT, 20);
        curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
        $curl_http_headers = array(
            "Authorization: Token ".$clean_api_key,
            "Content-Type: application/json",
            "Accept: application/json",
        );
        curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
        $curl_result = trim(curl_exec($curl_request));
        $curl_json_result = function_exists('bc_gateway_json_decode') ? bc_gateway_json_decode($curl_result) : json_decode($curl_result, true);
        if(!is_array($curl_json_result)){ $curl_json_result = array(); }

        // ONLY HDK's own status word is a verdict. A 401 body, a 404, an HTML error page, a
        // timeout or an empty reply is NOT a failure: nothing is refunded on it (see
        // func/bc-gateway.php), the transaction is simply left for the next requery.
        $hdk_verdict = function_exists('bc_gateway_provider_verdict') ? bc_gateway_provider_verdict($curl_json_result, "Status") : null;

        if($hdk_verdict === "successful"){
            $api_response = "successful";
            $api_response_reference = $curl_json_result["id"];
            $api_response_text = $curl_json_result["Status"];
            $api_response_description = str_replace(["pending","failed"], "successful", str_replace(["Transaction Pending","Transaction Failed"], "Transaction Successful", getTransaction($requery_reference, "description")));
            $api_response_status = 1;
        }
        
        if($hdk_verdict === "pending"){
            $api_response = "pending";
            $api_response_reference = $curl_json_result["id"];
            $api_response_text = $curl_json_result["Status"];
            $api_response_description = str_replace(["successful","failed"], "pending", str_replace(["Transaction Successful","Transaction Failed"], "Transaction Pending", getTransaction($requery_reference, "description")));
            $api_response_status = 2;
        }
        
        if($hdk_verdict === "failed"){
            $api_response = "failed";
            $api_response_text = $curl_json_result["Status"];
            $api_response_description = "Transaction Failed | 234".substr(getTransaction($requery_reference, "product_unique_id"), "1", "11")." failed";
            $api_response_status = 3;
        }

        if($hdk_verdict === null){
            if(function_exists('bc_gateway_log_raw_response')){ bc_gateway_log_raw_response($curl_result, "HDK-REQUERY-UNRESOLVED(errno=".curl_errno($curl_request).")"); }
        }
    }else{
        //Reference empty - nothing to ask the provider about, so leave the transaction pending.
        $api_response = "pending";
        $api_response_text = "";
        $api_response_description = "Transaction Pending | awaiting confirmation from the data provider";
        $api_response_status = 2;
    }
?>