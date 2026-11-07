<?php
// SME Plug transaction lookup: GET https://smeplug.ng/api/v1/transactions/{provider_reference}
$api_response = "pending";
$api_response_text = "";
$api_response_description = "Transaction Pending | awaiting confirmation from SME Plug";
$api_response_status = 2;
$api_response_reference = (string)($get_api_reference_id ?? "");

if (!empty($get_api_reference_id) && !empty($api_detail["api_key"])) {
    $smeplug_reference = rawurlencode((string)$get_api_reference_id);
    $curl_url = "https://smeplug.ng/api/v1/transactions/" . $smeplug_reference;
    $curl_request = curl_init($curl_url);
    curl_setopt_array($curl_request, array(
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer " . trim((string)$api_detail["api_key"]),
            "Accept: application/json"
        )
    ));
    $curl_result = curl_exec($curl_request);
    $smeplug_curl_errno = curl_errno($curl_request);
    $smeplug_http_code = (int)curl_getinfo($curl_request, CURLINFO_HTTP_CODE);
    $smeplug_curl_error = curl_error($curl_request);
    if (strtoupper((string)($purchase_method ?? "")) === "CRON_JOB" && function_exists("bc_requery_queue_log")) {
        $curl_errno = $smeplug_curl_errno;
        $curl_error = $smeplug_curl_error;
        $curl_http_code = $smeplug_http_code;
    }
    curl_close($curl_request);
    $curl_json_result = json_decode((string)$curl_result, true);

    if ($smeplug_curl_errno === 0 && $smeplug_http_code >= 200 && $smeplug_http_code < 300 && is_array($curl_json_result)) {
        $smeplug_transaction = $curl_json_result["transaction"] ?? $curl_json_result["data"]["transaction"] ?? $curl_json_result["data"] ?? array();
        if (!is_array($smeplug_transaction)) $smeplug_transaction = array();
        $smeplug_status = $smeplug_transaction["current_status"] ?? $smeplug_transaction["status"] ?? $curl_json_result["current_status"] ?? $curl_json_result["status_text"] ?? "";
        $smeplug_status_text = strtolower(trim(is_scalar($smeplug_status) ? (string)$smeplug_status : ""));
        $api_response_reference = (string)($smeplug_transaction["reference"] ?? $smeplug_transaction["provider_reference"] ?? $get_api_reference_id);

        if (preg_match('/^(success|successful|completed|delivered|credited)$/i', $smeplug_status_text)) {
            $api_response = "successful";
            $api_response_text = $smeplug_status_text;
            $api_response_description = str_replace(array("pending", "failed"), "successful", str_replace(array("Transaction Pending", "Transaction Failed"), "Transaction Successful", getTransaction($requery_reference, "description")));
            $api_response_status = 1;
        } elseif (preg_match('/^(failed|failure|rejected|declined|cancelled|canceled|refunded|error)$/i', $smeplug_status_text)) {
            $api_response = "failed";
            $api_response_text = $smeplug_status_text;
            $api_response_description = str_replace(array("pending", "successful"), "failed", str_replace(array("Transaction Pending", "Transaction Successful"), "Transaction Failed", getTransaction($requery_reference, "description")));
            $api_response_status = 3;
        } elseif (preg_match('/pending|processing|queued|initiated|awaiting|in.progress/i', $smeplug_status_text)) {
            $api_response = "pending";
            $api_response_text = $smeplug_status_text;
            $api_response_description = str_replace(array("successful", "failed"), "pending", str_replace(array("Transaction Successful", "Transaction Failed"), "Transaction Pending", getTransaction($requery_reference, "description")));
            $api_response_status = 2;
        }
        // Unknown/empty states remain pending, never refunded speculatively.
    }
}
?>
