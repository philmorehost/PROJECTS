<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

$input = array_merge($_GET, $_POST);
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? "")));
$action = mysqli_real_escape_string($connection_server, trim(strip_tags($input["action"] ?? "list")));

if (empty($api_key)) {
    echo json_encode(["status" => "failed", "desc" => "Missing api_key"]);
    exit;
}

$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "failed", "desc" => "Vendor not found or inactive"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) == 1) {
    $user = mysqli_fetch_assoc($check_user);
    $username = $user['username'];

    if ($action == "add") {
        $name = mysqli_real_escape_string($connection_server, trim(strip_tags($input["name"] ?? "")));
        $phone = mysqli_real_escape_string($connection_server, trim(strip_tags($input["phone"] ?? "")));

        if (empty($name) || empty($phone)) {
            echo json_encode(["status" => "failed", "desc" => "Name and Phone are required"]);
            exit;
        }

        $q = "INSERT INTO sas_user_contacts (vendor_id, username, contact_name, phone_number) VALUES ('$vendor_id', '$username', '$name', '$phone')";
        if (mysqli_query($connection_server, $q)) {
            echo json_encode(["status" => "success", "desc" => "Contact added successfully"]);
        } else {
            echo json_encode(["status" => "failed", "desc" => "Database error"]);
        }

    } elseif ($action == "delete") {
        $contact_id = (int)($input["id"] ?? 0);
        if ($contact_id > 0) {
            mysqli_query($connection_server, "DELETE FROM sas_user_contacts WHERE id='$contact_id' AND vendor_id='$vendor_id' AND username='$username'");
            echo json_encode(["status" => "success", "desc" => "Contact deleted"]);
        } else {
            echo json_encode(["status" => "failed", "desc" => "Invalid ID"]);
        }

    } else {
        // List
        $contacts = [];
        $res = mysqli_query($connection_server, "SELECT id, contact_name as name, phone_number as phone FROM sas_user_contacts WHERE vendor_id='$vendor_id' AND username='$username' ORDER BY contact_name ASC");
        while ($row = mysqli_fetch_assoc($res)) {
            $contacts[] = $row;
        }
        echo json_encode(["status" => "success", "data" => $contacts]);
    }
} else {
    echo json_encode(["status" => "failed", "desc" => "Invalid API Key"]);
}

mysqli_close($connection_server);
?>
