<?php error_reporting(0);
include_once($_SERVER["DOCUMENT_ROOT"] . "/config.php");
header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
// fwrite(fopen("complaints.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $username = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["username"])));
    $password = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["encoded-passkey"])));
    $complaint_title = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["title"])));
    $complaint_body = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["message"])));


    $status_update = "failed";
    $status_msg = "Unknown Error";

    if (
        !empty($username) &&
        !empty($password) &&
        !empty($complaint_title) &&
        !empty($complaint_body)
    ) {
        $checkuser = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . $username . "' OR email='" . $username . "'");
        if (mysqli_num_rows($checkuser) == 1) {
            $checkuser_pass = mysqli_query($conn_db, "SELECT * FROM users WHERE (username = '" . $username . "' OR email='" . $username . "') AND password = '" . $password . "'");
            if (mysqli_num_rows($checkuser_pass) == 1) {
                $get_user_detail = mysqli_fetch_array($checkuser_pass);

                $ref = uniqid("complaint_", true);
                $add_complaint = mysqli_query($conn_db, "INSERT INTO complaints (username, ref, title, body, notes, `status`) VALUES ('$username', '$ref', '$complaint_title', '$complaint_body', '', 'pending')");
                if ($add_complaint === true) {

                    // Email Beginning
                    $complaint_template_encoded_text_array = array("{firstname}" => $get_user_detail["firstname"], "{lastname}" => $get_user_detail["lastname"], "{subject}" => $complaint_title, "{message}" => $complaint_body, "{reference}" => $ref);
                    $raw_complaint_template_subject = getUserEmailTemplate('user-complaint', 'subject');
                    $raw_complaint_template_body = getUserEmailTemplate('user-complaint', 'body');
                    foreach ($complaint_template_encoded_text_array as $array_key => $array_val) {
                        $raw_complaint_template_subject = str_replace($array_key, $array_val, $raw_complaint_template_subject);
                        $raw_complaint_template_body = str_replace($array_key, $array_val, $raw_complaint_template_body);
                    }

                    beeMailer($get_user_detail["email"], $raw_complaint_template_subject, $raw_complaint_template_body);
                    // Email End

                    $status_update = "success";
                    $status_msg = "Complaint laid to Customer Representative Successfully";
                } else {
                    $status_msg = "Error: Server Down, Complaint failed to upload";
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
        } elseif (empty($complaint_title)) {
            $status_msg = "Complaint Title is required";
        } elseif (empty($complaint_body)) {
            $status_msg = "Complaint Body is required";
        }
    }
    $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg), true);
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}


echo $app_json;
?>