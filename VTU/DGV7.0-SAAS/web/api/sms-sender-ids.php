<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

// Select Vendor Table
$vendor_id = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

if ($select_vendor_table) {

    $input = array_merge($_GET, $_POST);
    if (empty($input)) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    $api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? "")));

    if (!empty($api_key)) {
        $get_user_query = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . $select_vendor_table["id"] . "' && api_key='" . $api_key . "' LIMIT 1");
    } else {
        echo json_encode(["status" => "failed", "desc" => "Authentication failed: Provide api_key"]);
        exit;
    }

    $get_logged_user_details = mysqli_fetch_array($get_user_query);

    if (mysqli_num_rows($get_user_query) == 1) {
        if ($get_logged_user_details["status"] == 1) {

            $sender_ids = [];
            $q = mysqli_query($connection_server, "SELECT sender_id, status FROM sas_bulk_sms_sender_id WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' AND username='" . $get_logged_user_details["username"] . "'");

            while($r = mysqli_fetch_assoc($q)) {
                $sender_ids[] = [
                    "sender_id" => $r['sender_id'],
                    "status" => ($r['status'] == 1 ? "Approved" : ($r['status'] == 2 ? "Pending" : "Rejected"))
                ];
            }

            echo json_encode([
                "status" => "success",
                "data" => $sender_ids
            ]);

        } else {
            echo json_encode(["status" => "failed", "desc" => "Account is not active"]);
        }
    } else {
        echo json_encode(["status" => "failed", "desc" => "User not exists or invalid credentials"]);
    }
} else {
    echo json_encode(["status" => "failed", "desc" => "Website not registered or inactive"]);
}

mysqli_close($connection_server);
?>
