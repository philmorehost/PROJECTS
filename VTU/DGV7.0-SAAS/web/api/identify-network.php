<?php
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = array_merge($_GET, $_POST);
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$phone_input = $input["phone"] ?? $input["phone_number"] ?? "";
if (empty($phone_input)) {
    echo json_encode(["status" => "error", "message" => "Phone number required"]);
    exit;
}

$phone_array = array_filter(explode(",", $phone_input));
$results = [];

foreach ($phone_array as $p) {
    $p = sanitize_phone_number(trim($p));
    if (strlen($p) == 11) {
        $isp = identifyISP($p);
        $results[] = ["phone" => $p, "network" => $isp];
    } else {
        $results[] = ["phone" => $p, "network" => "Invalid"];
    }
}

if (count($results) == 1) {
    echo json_encode(["status" => "success", "network" => $results[0]['network']]);
} else {
    echo json_encode(["status" => "success", "data" => $results]);
}
?>
