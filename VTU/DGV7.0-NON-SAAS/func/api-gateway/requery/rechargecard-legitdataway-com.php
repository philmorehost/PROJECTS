<?php
    if(!empty($requery_reference)){
        $api_response = "successful";
        $api_response_reference = getTransaction($requery_reference, "api_reference");
        $api_response_text = "Transaction Successful";
        $api_response_description = "Transaction Successful";
        $api_response_status = 1;   
    }
curl_close($curl_request);
?>