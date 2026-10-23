<?php session_start();
//header("Content-Type", "application/json");
include_once("func/bc-connect.php");

//Select Vendor Table
$vendor_id = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

// If run via CLI or host not found, we might need a fallback or loop through all vendors.
// But for now, let's just make it robust for the current vendor context.

if ($select_vendor_table) {
    $get_api_post_info = json_decode(file_get_contents('php://input'), true);

    $get_vendor_details = $select_vendor_table;

    @mysqli_query($connection_server, "ALTER TABLE sas_transactions ADD COLUMN requery_count INT DEFAULT 0");

    // status='2'  → a stuck pending purchase: resolve it to success/failed.
    // status='3'  → a failure from today: a few bounded retries in case the provider changes its mind.
    // status='1'  → a successful purchase from today placed through an upstream API: rechecked a
    //               bounded number of times, because a provider can fail a transaction only AFTER we
    //               already reported success (and the parent refunds our account). Without this the
    //               reversal never reaches this install, the customer keeps a successful order that
    //               was never delivered, and is never refunded.
    $select_user_requeried_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='" . $get_vendor_details["id"] . "' AND (status='2' OR (status='3' AND requery_count < 3 AND DATE(date) = CURDATE()) OR (status='1' AND api_id > 0 AND api_reference <> '' AND requery_count < 3 AND DATE(date) = CURDATE())) ORDER BY (status='2') DESC, id ASC LIMIT 50");

    if (mysqli_num_rows($select_user_requeried_transaction_details) > 0) {
        while ($requeried_transaction = mysqli_fetch_assoc($select_user_requeried_transaction_details)) {
            $_SESSION["user_session"] = $requeried_transaction["username"];
            $get_user_detail_via_username = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . $get_vendor_details["id"] . "' && username='" . $_SESSION["user_session"] . "' LIMIT 1");
            $get_logged_user_details = mysqli_fetch_array($get_user_detail_via_username);

            if ((mysqli_num_rows($get_user_detail_via_username) == 1) && ($get_logged_user_details["status"] == "1")) {
                if ($requeried_transaction['status'] == '3' || $requeried_transaction['status'] == '1') {
                    mysqli_query($connection_server, "UPDATE sas_transactions SET requery_count = requery_count + 1 WHERE id='".$requeried_transaction['id']."'");
                }
                $purchase_method = "cron_job";
                $action_function = 2;
                $cron_job_requery_reference = $requeried_transaction["reference"];
                include("web/func/requery-transaction.php");
                $json_response_decode = json_decode($json_response_encode, true);
                //echo json_encode($json_response_decode, true)."<br/>";
            }
        }
    }

} else {
    //Website not registered
    $json_response_array = array("status" => "201", "text" => "Website Not Registered");
    echo json_encode($json_response_array, true);
}

mysqli_close($connection_server);
?>