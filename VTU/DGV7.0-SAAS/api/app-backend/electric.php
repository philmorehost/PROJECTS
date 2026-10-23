<?php error_reporting(0);
include_once("app-config.php");

header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
fwrite(fopen("electric.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {

    //Select Vendor Table
    $vendor_id = resolveVendorID();
    $select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
    if ($select_vendor_table) {
        $username = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["username"])));
        $password = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["encoded-passkey"])));
        $network = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["network"])));
        $meter_number = str_replace(" ", "", mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["meter_number"]))));
        $meter_type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($decode_post_request["meter_type"]))));
        $amount = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["amount"])));
        $amount = str_replace(["-", "+", "*", "/"], "", $amount);

        $status_update = "failed";
        $status_msg = "Unknown Error";
        $meter_type_array = array("prepaid", "postpaid");
        $electric_owner_name = "Unknown User";


        if (
            !empty($username) &&
            !empty($password) &&
            !empty($network) &&
            !empty($meter_number) &&
            is_numeric($meter_number) &&
            (strlen($meter_number) == 11) &&
            !empty($meter_type) &&
            in_array($meter_type, $meter_type_array) &&
            !empty($amount) &&
            is_numeric($amount) &&
            ($amount >= 100)
        ) {
            $checkuser = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND  (username = '" . $username . "' OR email='" . $username . "')");
            if (mysqli_num_rows($checkuser) == 1) {
                $checkuser_pass = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND (username = '" . $username . "' OR email='" . $username . "') AND password = '" . $password . "'");
                if (mysqli_num_rows($checkuser_pass) == 1) {
                    $get_user_info = mysqli_fetch_array($checkuser_pass);
                    $api_file_url = $_SERVER["DOCUMENT_ROOT"] . "/web/api/electric.php";
                    if (file_exists($api_file_url)) {
                        $api_post_info_from_app = array("api_key" => $get_user_info["api_key"], "provider" => $network, "meter_number" => $meter_number, "type" => $meter_type, "amount" => $amount);
                        include_once($api_file_url);
                        if (in_array($json_response_array["status"], array("success", "pending"))) {
                            $status_update = "success";
                            $status_msg = $json_response_array["desc"];
                            $electric_owner_name = $json_response_array["desc"];
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
                $status_msg = "Electric provider is required";
            } elseif (empty($meter_number)) {
                $status_msg = "Meter number is required";
            } elseif (!is_numeric($meter_number)) {
                $status_msg = "Meter number must be a number";
            } elseif ((strlen($meter_number) < 11) || (strlen($meter_number) > 11)) {
                $status_msg = "Meter number must be 10 digits long";
            } elseif (empty($meter_type)) {
                $status_msg = "Meter type is required";
            } elseif (!in_array($meter_type, $meter_type_array)) {
                $status_msg = "Invalid Meter type";
            } elseif (empty($amount)) {
                $status_msg = "Amount is required";
            } elseif (!is_numeric($amount)) {
                $status_msg = "Amount must be a number";
            } elseif ($amount < 100) {
                $status_msg = "Amount must be 100 NGN and above";
            }
        }
        $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg, "name" => $electric_owner_name), true);
    } else {
        //Website not registered
        $app_json = json_encode(array("json-status" => "failed", "status" => "failed", "status-msg" => "Website not registered"), true);
    }
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}
fwrite(fopen("electric.json", "a"), $app_json . "\n\n");

echo $app_json;
?>