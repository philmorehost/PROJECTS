<?php
    if(!empty($requery_reference)){
        $curl_url = "https://".$api_detail["api_base_url"]."/api/data/".getTransaction($requery_reference, "api_reference");
        $curl_request = curl_init($curl_url);
        curl_setopt($curl_request, CURLOPT_HTTPGET, true);
        curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
        $curl_http_headers = array(
            "Authorization: Token  ".$api_detail["api_key"],
            "Content-Type: application/json",
        );
        curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
        $curl_result = trim(curl_exec($curl_request));
        $curl_json_result = json_decode($curl_result, true);
        if(!is_array($curl_json_result)){ $curl_json_result = array(); }
        

        if(in_array($curl_json_result["Status"],array("successful"))){
            $api_response = "successful";
            $api_response_reference = $curl_json_result["id"];
            $api_response_text = $curl_json_result["Status"];
            $api_response_description = str_replace(["pending","failed"], "successful", str_replace(["Transaction Pending","Transaction Failed"], "Transaction Successful", getTransaction($requery_reference, "description")));
            $api_response_status = 1;
        }
        
        if(in_array($curl_json_result["Status"],array("pending"))){
            $api_response = "pending";
            $api_response_reference = $curl_json_result["id"];
            $api_response_text = $curl_json_result["Status"];
            $api_response_description = str_replace(["successful","failed"], "pending", str_replace(["Transaction Successful","Transaction Failed"], "Transaction Pending", getTransaction($requery_reference, "description")));
            $api_response_status = 2;
        }
        
        if(!in_array($curl_json_result["Status"],array("successful","pending"))){
            $api_response = "failed";
            $api_response_text = $curl_json_result["Status"];
            $api_response_description = "Transaction Failed | 234".substr(getTransaction($requery_reference, "product_unique_id"), "1", "11")." failed";
            $api_response_status = 3;
        }
    }else{
        //CG ref empty
        $api_response = "failed";
        $api_response_text = "";
        $api_response_description = "";
        $api_response_status = 3;
    }
curl_close($curl_request);
?>