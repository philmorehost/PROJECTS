<?php
include("func/bc-connect.php");
$res = mysqli_query($connection_server, "DESCRIBE sas_vendors");
while($row = mysqli_fetch_assoc($res)) {
    echo $row['Field'] . "\n";
}
?>
