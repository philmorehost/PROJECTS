<?php
    if(!empty($requery_reference)){
        $api_response = "successful";
        $api_response_reference = getTransaction($requery_reference, "api_reference");
        $api_response_text = "Transaction Successful";
        $api_response_description = str_replace(["pending","failed"], "successful", str_replace(["Transaction Pending","Transaction Failed"], "Transaction Successful", getTransaction($requery_reference, "description")));
        $api_response_status = 1;   
    }
curl_close($curl_request);
?>