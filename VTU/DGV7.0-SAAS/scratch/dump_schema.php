<?php
include 'func/bc-connect.php';
$tables = ['sas_whatsapp_gateway', 'sas_super_admin_options'];
foreach ($tables as $table) {
    echo "--- Table: $table ---\n";
    $q = mysqli_query($connection_server, "DESCRIBE $table");
    while ($row = mysqli_fetch_assoc($q)) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }
}
?>
