<?php
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REQUEST_URI"] = "/web/BulkAirtime.php";
session_start();
$_SESSION["username"] = "test";
require "../func/bc-config.php"; 
$get_logged_user_details = ["vendor_id" => 1, "username" => "test", "id" => 1, "account_level" => 1, "status" => 1, "is_blocked" => 0];
$connection_server = mysqli_connect("localhost", "root", "", "dgv7_nonsaas"); // Or whatever the DB name is, but bc-connect should handle it.
ob_start();
require "BulkAirtime.php";
$out = ob_get_clean();
file_put_contents("test_output.txt", $out);
echo "Done! test_output.txt created.\n";
?>
