<?php
// sms-philmoresms-com.php
// Alias gateway for api_base_url = "philmoresms.com" (without the "app." subdomain).
// Correctly routes to https://app.philmoresms.com/api/sms.php — the live PhilmoreSMS API endpoint.
// This file is auto-selected by the gateway resolver when api_base_url is stored as "philmoresms.com".

$sms_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");

if (in_array($product_name, array_keys($sms_service_provider_alter_code))) {

    // All networks use the same standard (promotional) SMS endpoint on PhilmoreSMS.
    $web_sms_size_array = array("standard_sms" => "standard_sms");

    if (in_array($sms_type, array_keys($web_sms_size_array))) {
        $api_token = trim($api_detail["api_key"]);

        // Always target the correct app subdomain regardless of how api_base_url is stored in DB.
        $curl_url  = "https://app.philmoresms.com/api/sms.php";

        $post_data = http_build_query(array(
            'token'      => $api_token,
            'senderID'   => $sender_id,
            'recipients' => $phone_no,
            'message'    => urldecode($text_message), // decode URL-encoded message before sending
        ));

        $curl_request = curl_init($curl_url);
        curl_setopt($curl_request, CURLOPT_POST, true);
        curl_setopt($curl_request, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl_request, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));

        $curl_result      = curl_exec($curl_request);
        $http_code        = curl_getinfo($curl_request, CURLINFO_HTTP_CODE);
        $curl_json_result = json_decode($curl_result, true);
        if(!is_array($curl_json_result)){ $curl_json_result = array(); }

        // Log outcome for debugging
        $log_dir  = $_SERVER['DOCUMENT_ROOT'] . '/logs';
        $log_file = $log_dir . '/sms-philmoresms-' . date('Y-m-d') . '.log';
        $log_line = '[' . date('Y-m-d H:i:s') . ' ' . date_default_timezone_get() . ']'
            . ' http_code=' . $http_code
            . ' token_set=' . (!empty($api_token) ? 'yes' : 'NO')
            . ' sender=' . $sender_id
            . ' phone=' . $phone_no
            . ' msg_len=' . strlen(urldecode($text_message))
            . ' response=' . substr((string)$curl_result, 0, 300);
        @file_put_contents($log_file, $log_line . PHP_EOL, FILE_APPEND | LOCK_EX);

        if (curl_errno($curl_request)) {
            $api_response             = "failed";
            $api_response_text        = curl_error($curl_request) ?: "curl_error";
            $api_response_description = "Connection to PhilmoreSMS failed";
            $api_response_status      = 3;
        } else {
            if (isset($curl_json_result["error_code"]) && $curl_json_result["error_code"] === "000") {
                $api_response             = "successful";
                $api_response_reference   = $curl_json_result["reference"] ?? uniqid('SMS');
                $api_response_text        = $curl_json_result["status"];
                $api_response_description = "Transaction Successful";
                $api_response_status      = 1;
            } else {
                $api_response             = "failed";
                $api_response_text        = $curl_json_result["error_code"] ?? "error";
                $api_response_description = $curl_json_result["message"]    ?? "Transaction Failed";
                $api_response_status      = 3;
            }
        }
        curl_close($curl_request);

    } else {
        // SMS type not supported (only standard_sms is supported on PhilmoreSMS)
        $api_response             = "failed";
        $api_response_text        = "";
        $api_response_description = "SMS type '$sms_type' not supported on PhilmoreSMS";
        $api_response_status      = 3;
    }
} else {
    // Network not supported
    $api_response             = "failed";
    $api_response_text        = "";
    $api_response_description = "Service not available";
    $api_response_status      = 3;
}
?>
