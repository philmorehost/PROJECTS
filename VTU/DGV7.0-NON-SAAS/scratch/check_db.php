<?php
include 'func/bc-connect.php';
$res = mysqli_query($connection_server, "DESCRIBE sas_ai_transactions");
while($row = mysqli_fetch_assoc($res)) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
?>
