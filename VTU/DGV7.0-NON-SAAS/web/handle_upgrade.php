<?php
session_start();
include("../func/bc-config.php");

if (isset($_POST["upgrade-user"])) {
    $upgrade_type = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["upgrade-type"])));

    $account_level_upgrade_array = array("smart" => 1, "agent" => 2);
    $new_account_level = $account_level_upgrade_array[$upgrade_type];

    if ($new_account_level > $get_logged_user_details["account_level"]) {
        $stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_user_upgrade_price WHERE vendor_id=? AND account_type=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "is", $get_logged_user_details["vendor_id"], $new_account_level);
        mysqli_stmt_execute($stmt);
        $get_upgrade_price_result = mysqli_stmt_get_result($stmt);
        $get_upgrade_price = mysqli_fetch_array($get_upgrade_price_result);

        $upgrade_price = $get_upgrade_price["price"];

        if (userBalance(1) >= $upgrade_price) {
            $debit_user = chargeUser("debit", "UPGRADE", "Account Upgrade", substr(str_shuffle("12345678901234567890"), 0, 15), "", $upgrade_price, $upgrade_price, "Account Upgrade to " . accountLevel($new_account_level), "WEB", $_SERVER["HTTP_HOST"], 1);

            if ($debit_user === "success") {
                alterUser($get_logged_user_details["username"], "account_level", $new_account_level);
                $_SESSION["product_purchase_response"] = "Account upgraded successfully!";
            } else {
                $_SESSION["product_purchase_response"] = "Failed to debit user account.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "Insufficient balance for the upgrade.";
        }
    } else {
        $_SESSION["product_purchase_response"] = "Invalid upgrade selection.";
    }

    header("Location: /web/Dashboard.php");
    exit();
} else {
    echo "Invalid request.";
}
?>