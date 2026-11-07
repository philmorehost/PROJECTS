<?php
include_once("../func/bc-connect.php");
$res = mysqli_query($connection_server, "DESCRIBE sas_pending_vendors");
while($row = mysqli_fetch_assoc($res)) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
?>
