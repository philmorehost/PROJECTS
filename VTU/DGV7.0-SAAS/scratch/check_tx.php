<?php
include("func/bc-connect.php");
$query = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE type_alternative LIKE '%Direct Data%' ORDER BY id DESC LIMIT 5");
while($row = mysqli_fetch_assoc($query)) {
    echo "Ref: " . $row['reference'] . " | Amount: " . $row['amount'] . " | Desc: " . $row['description'] . " | Status: " . $row['status'] . "\n";
}
?>
