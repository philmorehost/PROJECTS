<?php error_reporting(0);
include_once("app-config.php");

header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
fwrite(fopen("card.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {

    //Select Vendor Table
    $vendor_id = resolveVendorID();
    $select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
    if ($select_vendor_table) {
        $username = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["username"])));
        $password = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["encoded-passkey"])));
        $network = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["network"])));
        $card_type = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["type"])));
        $card_qty = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["qty"])));
        $card_qty_number = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["qty_number"])));
        $card_name = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["card_name"])));
        
        $status_update = "failed";
        $status_msg = "Unknown Error";

        if (
            !empty($username) &&
            !empty($password) &&
            !empty($network) &&
            !empty($card_type) &&
            !empty($card_qty) && 
            !empty($card_qty_number) &&
            is_numeric($card_qty_number) &&
            ($card_qty_number >= 1)
        ) {
            $checkuser = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND  (username = '" . $username . "' OR email='" . $username . "')");
            if (mysqli_num_rows($checkuser) == 1) {
                $checkuser_pass = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND (username = '" . $username . "' OR email='" . $username . "') AND password = '" . $password . "'");
                if (mysqli_num_rows($checkuser_pass) == 1) {
                    $get_user_info = mysqli_fetch_array($checkuser_pass);
                    $api_file_url = $_SERVER["DOCUMENT_ROOT"] . "/web/api/card.php";
                    if (file_exists($api_file_url)) {
                        $api_post_info_from_app = array("api_key" => $get_user_info["api_key"], "network" => $network, "type" => $card_type, "quantity" => $card_qty, "qty_number" => $card_qty_number, "card_name" => $card_name);
                        include_once($api_file_url);
                        if (in_array($json_response_array["status"], array("success", "pending"))) {
                            $status_update = "success";
                            $status_msg = $json_response_array["desc"];
                        } else {
                            $status_msg = $json_response_array["desc"];
                        }
                    } else {
                        $status_msg = "Server Unavailable";
                    }
                } else {
                    $status_msg = "Invalid Password";
                }
            } else {
                $status_msg = "Invalid Username or email";
            }
        } else {
            if (empty($username)) {
                $status_msg = "Empty Username";
            } elseif (empty($password)) {
                $status_msg = "Empty Password";
            } elseif (empty($network)) {
                $status_msg = "Service provider is required";
            } elseif (empty($card_type)) {
                $status_msg = "Card type is required";
            } elseif (empty($card_qty)) {
                $status_msg = "Card qty is required";
            } elseif (empty($card_qty_number)) {
                $status_msg = "Card Quantity is required";
            } elseif (!is_numeric($card_qty_number)) {
                $status_msg = "Card Quantity must be a number";
            } elseif ($card_qty_number < 1) {
                $status_msg = "Card Quantity must be 1 and above";
            }
        }
        $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg), true);
    } else {
        //Website not registered
        $app_json = json_encode(array("json-status" => "failed", "status" => "failed", "status-msg" => "Website not registered"), true);
    }
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}
fwrite(fopen("card.json", "a"), $app_json . "\n\n");

echo $app_json;
?>