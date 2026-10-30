<?php
// SME Plug Data Purchase: POST https://smeplug.ng/api/v1/data/purchase
// DGV stores provider plan ids in lowercase; SME Plug's alpha-numeric ids are case-sensitive in the catalogue.
$smeplug_network_ids = array("mtn" => 1, "airtel" => 2, "9mobile" => 3, "glo" => 4);
$smeplug_network = strtolower(trim((string)$product_name));
$smeplug_token = trim((string)($api_detail["api_key"] ?? ""));
$smeplug_phone = trim((string)$phone_no);
$smeplug_plan_id = strtoupper(trim((string)$quantity));
$smeplug_customer_reference = trim((string)($reference ?? ""));

$api_response = "pending";
$api_response_text = "";
$api_response_description = "Transaction Pending | awaiting confirmation from SME Plug";
$api_response_status = 2;
$api_response_reference = "";

if ($smeplug_token === "" || !isset($smeplug_network_ids[$smeplug_network]) || $smeplug_phone === "" || $smeplug_plan_id === "") {
    $api_response = "failed";
    $api_response_text = "invalid request";
    $api_response_description = "Transaction Failed | invalid SME Plug data request";
    $api_response_status = 3;
} else {
    $curl_url = "https://smeplug.ng/api/v1/data/purchase";
    $curl_request = curl_init($curl_url);
    curl_setopt_array($curl_request, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer " . $smeplug_token,
            "Content-Type: application/json",
            "Accept: application/json"
        ),
        CURLOPT_POSTFIELDS => json_encode(array(
            "network_id" => $smeplug_network_ids[$smeplug_network],
            "plan_id" => $smeplug_plan_id,
            "phone" => $smeplug_phone,
            "customer_reference" => $smeplug_customer_reference
        ))
    ));
    $curl_result = curl_exec($curl_request);
    $smeplug_curl_errno = curl_errno($curl_request);
    $smeplug_http_code = (int)curl_getinfo($curl_request, CURLINFO_HTTP_CODE);
    $smeplug_curl_error = curl_error($curl_request);
    curl_close($curl_request);
    $curl_json_result = json_decode((string)$curl_result, true);

    if ($smeplug_curl_errno !== 0 || !is_array($curl_json_result) || $smeplug_http_code < 200 || $smeplug_http_code >= 300) {
        // A transport/HTTP/JSON error is ambiguous: SME Plug may have accepted the charge.
        // Keep this pending so DGV's requery flow can determine the final outcome.
        $api_response_text = "";
        $api_response_description = "Transaction Pending | SME Plug response could not be confirmed";
        $api_response_status = 2;
    } else {
        $smeplug_data = $curl_json_result["data"] ?? array();
        $smeplug_status = $smeplug_data["current_status"] ?? $curl_json_result["transaction"]["status"] ?? $curl_json_result["status"] ?? "";
        $smeplug_status_text = strtolower(trim(is_scalar($smeplug_status) ? (string)$smeplug_status : ""));
        $api_response_reference = (string)($smeplug_data["reference"] ?? $curl_json_result["transaction"]["reference"] ?? $curl_json_result["reference"] ?? "");
        $smeplug_message = (string)($smeplug_data["msg"] ?? $curl_json_result["message"] ?? "");
        $smeplug_provider_status = $curl_json_result["status"] ?? false;
        $smeplug_provider_ok = in_array($smeplug_provider_status, array(true, 1, "1", "true", "success", "successful"), true);

        if (preg_match('/^(success|successful|completed|delivered|credited)$/i', $smeplug_status_text)
            || ($smeplug_provider_ok && $api_response_reference !== "" && $smeplug_message !== "" && preg_match('/success|transfer|top.?up|credited|delivered/i', $smeplug_message))) {
            $api_response = "successful";
            $api_response_text = $smeplug_status_text !== "" ? $smeplug_status_text : "success";
            $api_response_description = "Transaction Successful | " . strtoupper($smeplug_network) . " data plan " . $smeplug_plan_id . " credited to " . $smeplug_phone;
            $api_response_status = 1;
        } elseif (preg_match('/pending|processing|queued|initiated|awaiting/i', $smeplug_status_text)) {
            $api_response = "pending";
            $api_response_text = $smeplug_status_text;
            $api_response_description = "Transaction Pending | " . strtoupper($smeplug_network) . " data to " . $smeplug_phone . " is awaiting confirmation";
            $api_response_status = 2;
        } elseif (($curl_json_result["status"] ?? null) === false || preg_match('/^(failed|failure|rejected|declined|cancelled|canceled|refunded|error)$/i', $smeplug_status_text)) {
            $api_response = "failed";
            $api_response_text = $smeplug_status_text !== "" ? $smeplug_status_text : "provider rejected request";
            $api_response_description = "Transaction Failed | " . strtoupper($smeplug_network) . " data to " . $smeplug_phone . " failed";
            $api_response_status = 3;
        } else {
            // A success flag without a stable reference/status is not enough to mark a transaction paid.
            $api_response = "pending";
            $api_response_text = "";
            $api_response_description = "Transaction Pending | SME Plug accepted an unconfirmed data request";
            $api_response_status = 2;
        }
    }
}
?>
