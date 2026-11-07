<?php
/**
 * Guest network auto-detect — GET/POST phone=<msisdn>[,<msisdn>...]
 * The original web/api/identify-network.php has no auth already; this wrapper just adds
 * the shared IP rate-limit gate so it can't be used to hammer the DB for free.
 */
include_once(__DIR__ . "/guest-bootstrap.php");

$vendor = guest_resolve_vendor();
guest_security_gate($vendor['id'], "guest_identify_network", 60, 60);

$input = array_merge($_GET, $_POST);
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$phone_input = $input["phone"] ?? $input["phone_number"] ?? "";
if (empty($phone_input)) {
    guest_json(["status" => "error", "message" => "Phone number required"]);
}

$phone_array = array_filter(explode(",", $phone_input));
$results = [];

foreach ($phone_array as $p) {
    $p = sanitize_phone_number(trim($p));
    if (strlen($p) == 11) {
        $results[] = ["phone" => $p, "network" => identifyISP($p)];
    } else {
        $results[] = ["phone" => $p, "network" => "Invalid"];
    }
}

if (count($results) == 1) {
    guest_json(["status" => "success", "network" => $results[0]['network']]);
}
guest_json(["status" => "success", "data" => $results]);
