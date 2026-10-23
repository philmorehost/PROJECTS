<?php
session_start();
include_once("../func/bc-config.php");

$data = json_decode(file_get_contents("php://input"), true);
if(!$data) exit(json_encode(["status" => "error", "message" => "Invalid Request"]));

$type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($data['type'] ?? ''))));

if(!isset($get_logged_user_details)) {
    exit(json_encode(["status" => "error", "message" => "User not logged in"]));
}

if(isset($data['ids']) && is_array($data['ids'])) {
    // Bulk check
    $results = [];
    $all_ok = true;
    foreach($data['ids'] as $id) {
        $id_sanitized = mysqli_real_escape_string($connection_server, trim($id));
        if(empty($id_sanitized)) continue;

        $res = productIDPurchaseChecker($id_sanitized, $type, "WEB_CHECK");
        if($res === "LIMIT_REACHED") {
            $results[] = ["id" => $id_sanitized, "limit_reached" => true];
            $all_ok = false;
        } else {
            $results[] = ["id" => $id_sanitized, "limit_reached" => false];
        }
    }
    echo json_encode(["status" => "success", "bulk" => true, "all_ok" => $all_ok, "results" => $results]);
} else {
    // Single check
    $identity = mysqli_real_escape_string($connection_server, trim($data['id'] ?? ''));
    if(empty($identity)) exit(json_encode(["status" => "error", "message" => "Identity missing"]));

    $res = productIDPurchaseChecker($identity, $type, "WEB_CHECK");
    if($res === "success") {
        echo json_encode(["status" => "success", "limit_reached" => false]);
    } elseif($res === "LIMIT_REACHED") {
        echo json_encode(["status" => "success", "limit_reached" => true, "message" => "ABUSE LIMIT: Limit reached for $identity."]);
    } else {
        echo json_encode(["status" => "error", "message" => "System error"]);
    }
}
?>