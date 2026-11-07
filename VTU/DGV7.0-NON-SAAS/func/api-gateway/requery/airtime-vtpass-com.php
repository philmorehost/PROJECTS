<?php
    if(!empty($requery_reference)){
        $curl_url = "https://vtpass.com/api/requery";
        $curl_request = curl_init($curl_url);
        curl_setopt($curl_request, CURLOPT_POST, true);
        curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
        $curl_http_headers = array(
            "Authorization: Basic ".base64_encode($api_detail["api_key"]),
            "Content-Type: application/json",
        );
        curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
        $curl_postfields_data = json_encode(array("request_id"=> $get_api_reference_id), true);
        curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
        $curl_result = trim(curl_exec($curl_request));
        $curl_json_result = json_decode($curl_result, true);
if(!is_array($curl_json_result) || !isset($curl_json_result["code"])){
        $curl_json_result = array("code" => "999", "response_description" => "Invalid API response. Check your API credentials and try again.");
        }
        

        $code = $curl_json_result["code"] ?? '';
        $status = $curl_json_result["content"]["transactions"]["status"] ?? '';

        if(in_array($code, array("000","044")) || stripos($status, "delivered") !== false || stripos($status, "success") !== false){
            $api_response = "successful";
            $api_response_reference = $curl_json_result["requestId"] ?? $get_api_reference_id;
            $api_response_text = $curl_json_result["response_description"] ?? "SUCCESS";
            $api_response_description = str_replace(["pending","failed"], "successful", str_replace(["Transaction Pending","Transaction Failed"], "Transaction Successful", getTransaction($requery_reference, "description")));
            $api_response_status = 1;
        } elseif(in_array($code, array("001","099")) || stripos($status, "pending") !== false || stripos($status, "initiated") !== false){
            $api_response = "pending";
            $api_response_reference = $curl_json_result["requestId"] ?? $get_api_reference_id;
            $api_response_text = $curl_json_result["response_description"] ?? "PENDING";
            $api_response_description = str_replace(["successful","failed"], "pending", str_replace(["Transaction Successful","Transaction Failed"], "Transaction Pending", getTransaction($requery_reference, "description")));
            $api_response_status = 2;
        }
        
        if(!in_array($curl_json_result["code"],array("000","044","001","099"))){
            $api_response = "failed";
            $api_response_text = $curl_json_result["response_description"];
            $api_response_description = "Transaction Failed | N".getTransaction($requery_reference, "amount")." Airtime to 234".substr(getTransaction($requery_reference, "product_unique_id"), "1", "11")." failed";
            $api_response_status = 3;
        }
    }
    
curl_close($curl_request);
?>