<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

include("func/bc-config.php");

if (!isset($connection_server)) {
    die("Connection failed - check bc-config.php\n");
}

$tables = ['sas_login_attempts', 'sas_blocked_ips', 'sas_blocked_accounts', 'sas_users', 'sas_vendors', 'sas_super_admin'];

foreach ($tables as $table) {
    echo "--- $table ---\n";
    $res = mysqli_query($connection_server, "SHOW INDEX FROM `$table` ");
    if ($res) {
        while($row = mysqli_fetch_assoc($res)) {
            echo "Index: " . $row['Key_name'] . " on " . $row['Column_name'] . "\n";
        }
    } else {
        echo "Error: " . mysqli_error($connection_server) . "\n";
    }
}
?>