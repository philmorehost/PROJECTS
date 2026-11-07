<?php
header("Content-Type: application/json");

// Disable output buffering to avoid trailing output issues
while (ob_get_level()) {
    ob_end_clean();
}

include_once(__DIR__ . "/../../func/bc-config.php");
include_once(__DIR__ . "/../../func/bc-func.php");
include_once(__DIR__ . "/../../func/bc-ussd-fulfillment.php");

// Log raw request for debugging purposes
$log_dir = __DIR__ . "/../../logs";
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_file = $log_dir . "/ussd_requests.log";
$raw_input = file_get_contents("php://input");
@file_put_contents($log_file, "[" . date("Y-m-d H:i:s") . "] URL: " . $_SERVER['REQUEST_URI'] . " | GET: " . json_encode($_GET) . " | POST: " . json_encode($_POST) . " | BODY: " . $raw_input . "\n", FILE_APPEND);

// Extract parameters (Hollatags usually calls callback via GET)
$session_msisdn = $_REQUEST['session_msisdn'] ?? '';
$session_operation = $_REQUEST['session_operation'] ?? '';
$session_msg = $_REQUEST['session_msg'] ?? '';
$session_id = $_REQUEST['session_id'] ?? '';
$session_from = $_REQUEST['session_from'] ?? '';

if (empty($session_id) || empty($session_msisdn)) {
    echo json_encode([
        "session_operation" => "end",
        "session_type" => 4,
        "session_id" => $session_id,
        "session_msg" => "Error: Missing required USSD parameters."
    ]);
    exit();
}

// Check if USSD access is globally enabled
$ussd_global = getSuperAdminOption('ussd_access_enabled', '0') == '1';

if (!$ussd_global) {
    echo json_encode([
        "session_operation" => "end",
        "session_type" => 4,
        "session_id" => $session_id,
        "session_msg" => "Error: USSD service is locked or unavailable."
    ]);
    exit();
}

$vendor_id = 0;
$session_from_esc = mysqli_real_escape_string($connection_server, $session_from);

// Check if dial string matches global USSD code
$global_ussd_code = getSuperAdminOption('hollatags_ussd_code', '');
$clean_from = trim($session_from, "*#");
$global_ussd_clean = trim($global_ussd_code, "*#");
$is_global = ($clean_from === $global_ussd_clean || $session_from === $global_ussd_code);

if ($is_global) {
    // 1. Try to find vendor from existing session
    $session_id_esc = mysqli_real_escape_string($connection_server, $session_id);
    $get_session = mysqli_query($connection_server, "SELECT vendor_id FROM sas_ussd_sessions WHERE session_id='$session_id_esc' LIMIT 1");
    if ($get_session && $sess_row = mysqli_fetch_assoc($get_session)) {
        $vendor_id = (int)$sess_row['vendor_id'];
    }

    // 2. If new session, check if they dialed a direct PIN
    if ($vendor_id <= 0) {
        $direct_epin = "";
        if (preg_match('/^\*\d+\*\d+\*([\d\-]+)#?$/', $session_msg, $matches)) {
            $direct_epin = $matches[1];
        } elseif (preg_match('/^([\d\-]+)$/', trim($session_msg), $matches)) {
            $direct_epin = $matches[1];
        }

        if (!empty($direct_epin)) {
            $epin_normalized = trim(str_replace("-", "", $direct_epin));
            if (strlen($epin_normalized) == 12) {
                $epin_normalized = substr($epin_normalized, 0, 4) . "-" . substr($epin_normalized, 4, 4) . "-" . substr($epin_normalized, 8, 4);
            }
            $epin_normalized_esc = mysqli_real_escape_string($connection_server, $epin_normalized);
            $get_card = mysqli_query($connection_server, "SELECT vendor_id FROM sas_databundle_cards WHERE epin='$epin_normalized_esc' LIMIT 1");
            if ($get_card && $c_row = mysqli_fetch_assoc($get_card)) {
                $vendor_id = (int)$c_row['vendor_id'];
            }
        }
    }
} else {
    // Fallback to legacy vendor-specific shortcode lookup
    $get_v = mysqli_query($connection_server, "SELECT id, ussd_access FROM sas_vendors WHERE hollatags_ussd_code='$session_from_esc' AND status=1 LIMIT 1");
    if ($get_v && $r = mysqli_fetch_assoc($get_v)) {
        if ($r['ussd_access'] == 1) {
            $vendor_id = $r['id'];
        }
    } else {
        $get_v2 = mysqli_query($connection_server, "SELECT id, ussd_access FROM sas_vendors WHERE (hollatags_ussd_code LIKE '%$clean_from%' OR hollatags_ussd_code='$session_from_esc') AND status=1 LIMIT 1");
        if ($get_v2 && $r2 = mysqli_fetch_assoc($get_v2)) {
            if ($r2['ussd_access'] == 1) {
                $vendor_id = $r2['id'];
            }
        }
    }
}

$response = processUSSDSession($session_id, $session_msisdn, $session_operation, $session_msg, $session_from, $vendor_id);

@file_put_contents($log_file, "[" . date("Y-m-d H:i:s") . "] RESPONSE: " . json_encode($response) . "\n", FILE_APPEND);

echo json_encode($response);
exit();
?>