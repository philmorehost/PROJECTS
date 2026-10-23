<?php
// ─── Logging Helper ──────────────────────────────────────────────────────────
// Writes a timestamped entry to logs/sms-localserver-YYYY-MM-DD.log
// Log is protected by the existing logs/.htaccess (deny from all).
function sms_ls_log($msg, $data = null) {
    $log_dir  = $_SERVER['DOCUMENT_ROOT'] . '/logs';
    $log_file = $log_dir . '/sms-localserver-' . date('Y-m-d') . '.log';
    $line     = '[' . date('Y-m-d H:i:s') . ' ' . date_default_timezone_get() . '] ' . $msg;
    if ($data !== null) {
        $line .= ' | ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    @file_put_contents($log_file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ─── Gateway Logic ───────────────────────────────────────────────────────────
$sms_service_provider_alter_code = array("mtn" => "mtn", "airtel" => "airtel", "glo" => "glo", "9mobile" => "9mobile");

sms_ls_log("SMS LocalServer Gateway STARTED", array(
    "product_name" => $product_name ?? null,
    "sms_type"     => $sms_type     ?? null,
    "sender_id"    => $sender_id    ?? null,
    "phone_no"     => $phone_no     ?? null,
    "schedule_date"=> $schedule_date ?? null,
    "api_base_url" => $api_detail["api_base_url"] ?? null,
    "api_key_set"  => !empty($api_detail["api_key"]),
));

if (in_array($product_name, array_keys($sms_service_provider_alter_code))) {
    $web_sms_size_array = array("standard_sms" => "standard_sms", "flash_sms" => "flash_sms", "in_app_otp" => "in_app_otp");

    if (!empty($sms_type)) {
        $curl_url = "https://" . $api_detail["api_base_url"] . "/web/api/sms.php";
        sms_ls_log("Initiating cURL to upstream API", $curl_url);

        $curl_request = curl_init($curl_url);
        curl_setopt($curl_request, CURLOPT_POST, true);
        curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl_request, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

        $curl_postfields_data = "";

        // standard_sms, flash_sms, and in_app_otp all send a plain-text message
        if (in_array($sms_type, array("standard_sms", "flash_sms", "in_app_otp"))) {
            // $text_message is stored URL-encoded — decode before embedding in JSON
            $plain_message = urldecode($text_message);
            // Map in_app_otp → standard_sms for the upstream provider
            $upstream_type = ($sms_type === "in_app_otp") ? "standard_sms" : $sms_type;
            $payload_array = array(
                "api_key"      => $api_detail["api_key"],
                "network"      => $product_name,
                "sender_id"    => $sender_id,
                "phone_number" => $phone_no,
                "type"         => $upstream_type,
                "message"      => $plain_message,
                "date"         => $schedule_date,
            );
            $curl_postfields_data = json_encode($payload_array);
            sms_ls_log("Payload built for '$sms_type' (upstream: '$upstream_type')", array(
                "network"         => $product_name,
                "sender_id"       => $sender_id,
                "phone_number"    => $phone_no,
                "type"            => $upstream_type,
                "message_len"     => strlen($plain_message),
                "message_preview" => substr($plain_message, 0, 80),
            ));
        }

        if ($sms_type === "otp") {
            $otp_type_map  = array("numeric" => "numeric", "alphanumeric" => "alphanumeric");
            $otp_type_text = $otp_type_map[$otp_type] ?? "";
            $phone_list    = array_values(array_filter(explode(",", $phone_no)));
            $payload_array = array(
                "api_key"      => $api_detail["api_key"],
                "network"      => $product_name,
                "phone_number" => $phone_list[0] ?? "",
                "type"         => $sms_type,
                "otp_type"     => $otp_type_text,
                "pin_attempts" => $pin_attempts,
                "expires"      => $expiration_time,
                "pin_length"   => $pin_length,
            );
            $curl_postfields_data = json_encode($payload_array);
            sms_ls_log("Payload built for OTP", array(
                "network"      => $product_name,
                "phone_number" => $phone_list[0] ?? "",
                "otp_type"     => $otp_type_text,
                "pin_attempts" => $pin_attempts,
                "expires"      => $expiration_time,
            ));
        }

        if (empty($curl_postfields_data)) {
            sms_ls_log("ERROR: Unknown SMS type — no payload built, aborting", array("sms_type" => $sms_type));
            $api_response             = "failed";
            $api_response_text        = "unsupported_type";
            $api_response_description = "SMS type '$sms_type' is not supported by this gateway";
            $api_response_status      = 3;
            $curl_json_result         = array();
        } else {
            curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);

            $start_time  = microtime(true);
            $curl_result = curl_exec($curl_request);
            $elapsed_ms  = round((microtime(true) - $start_time) * 1000);
            $http_code   = curl_getinfo($curl_request, CURLINFO_HTTP_CODE);

            sms_ls_log("cURL completed", array(
                "http_code"            => $http_code,
                "elapsed_ms"           => $elapsed_ms,
                "curl_errno"           => curl_errno($curl_request),
                "curl_error"           => curl_error($curl_request) ?: null,
                "raw_response_preview" => $curl_result === false ? "FALSE" : substr($curl_result, 0, 200),
            ));

            if (curl_errno($curl_request) || $curl_result === false) {
                $err_msg = curl_error($curl_request) ?: "Connection error";
                sms_ls_log("ERROR: cURL connection failed", array("error" => $err_msg, "elapsed_ms" => $elapsed_ms));
                $api_response             = "failed";
                $api_response_text        = $err_msg;
                $api_response_description = "API Connection Failed";
                $api_response_status      = 3;
                $curl_json_result         = array();
            } else {
                $curl_json_result = function_exists('bc_gateway_json_decode') ? bc_gateway_json_decode($curl_result) : json_decode($curl_result, true);
                if(!is_array($curl_json_result)){ $curl_json_result = array(); }
                if (!is_array($curl_json_result)) {
                    sms_ls_log("ERROR: Response is not valid JSON", array("raw" => substr($curl_result, 0, 500)));
                    $api_response             = "failed";
                    $api_response_text        = "invalid_json";
                    $api_response_description = "Invalid response format from provider";
                    $api_response_status      = 3;
                    $curl_json_result         = array();
                } else {
                    sms_ls_log("Response decoded successfully", $curl_json_result);
                }
            }
        }

        // ── Parse result for message-based types ─────────────────────────────
        if (in_array($sms_type, array("standard_sms", "flash_sms", "in_app_otp"))) {
            $res_status = $curl_json_result["status"] ?? "";
            if ($res_status === "success") {
                $api_response             = "successful";
                $api_response_reference   = $curl_json_result["ref"] ?? uniqid("SMS");
                $api_response_text        = "";
                $api_response_description = "Transaction Successful";
                $api_response_status      = 1;
                sms_ls_log("RESULT: successful", array("ref" => $api_response_reference));
            } elseif ($res_status === "pending") {
                $api_response             = "pending";
                $api_response_reference   = $curl_json_result["ref"] ?? uniqid("SMS");
                $api_response_text        = "";
                $api_response_description = "Transaction Pending";
                $api_response_status      = 2;
                sms_ls_log("RESULT: pending", array("ref" => $api_response_reference));
            } elseif (($api_response ?? "") !== "failed") {
                $api_response             = "failed";
                $api_response_text        = $curl_json_result["desc"] ?? "Transaction Failed";
                $api_response_description = "Transaction Failed";
                $api_response_status      = 3;
                sms_ls_log("RESULT: failed (from provider response)", $curl_json_result);
            }
        }

        // ── Parse result for OTP ─────────────────────────────────────────────
        if ($sms_type === "otp") {
            $res_status = $curl_json_result["status"] ?? "";
            if ($res_status === "success") {
                $api_response             = "successful";
                $api_response_reference   = $curl_json_result["ref"] ?? uniqid("SMS");
                $api_response_text        = $curl_json_result["otp"] ?? "";
                $api_response_description = "Transaction Successful";
                $api_response_status      = 1;
                sms_ls_log("RESULT: OTP successful", array("ref" => $api_response_reference));
            } elseif ($res_status === "pending") {
                $api_response             = "pending";
                $api_response_reference   = $curl_json_result["ref"] ?? uniqid("SMS");
                $api_response_text        = "";
                $api_response_description = "Transaction Pending";
                $api_response_status      = 2;
                sms_ls_log("RESULT: OTP pending", array("ref" => $api_response_reference));
            } elseif (($api_response ?? "") !== "failed") {
                $api_response             = "failed";
                $api_response_text        = $curl_json_result["desc"] ?? "Transaction Failed";
                $api_response_description = "Transaction Failed";
                $api_response_status      = 3;
                sms_ls_log("RESULT: OTP failed (from provider response)", $curl_json_result);
            }
        }

        if (isset($curl_request) && is_resource($curl_request)) {
            if (function_exists('bc_gateway_settle_purchase')) { bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, "Transaction Failed | no valid response from the upstream gateway", $curl_result ?? ""); }
        }

    } else {
        sms_ls_log("ERROR: sms_type is empty — cannot proceed");
        $api_response             = "failed";
        $api_response_text        = "";
        $api_response_description = "SMS type not provided";
        $api_response_status      = 3;
    }
} else {
    sms_ls_log("ERROR: product_name not in supported networks", array("product_name" => $product_name ?? null));
    $api_response             = "failed";
    $api_response_text        = "";
    $api_response_description = "Service not available";
    $api_response_status      = 3;
}

sms_ls_log("SMS LocalServer Gateway FINISHED", array(
    "api_response"             => $api_response             ?? null,
    "api_response_status"      => $api_response_status      ?? null,
    "api_response_description" => $api_response_description ?? null,
    "api_response_text"        => $api_response_text        ?? null,
));
?>