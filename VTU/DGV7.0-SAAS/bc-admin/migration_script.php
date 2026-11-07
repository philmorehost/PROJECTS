<?php
include("../func/bc-admin-config.php");

echo "Starting migration...<br>";

// 1. Create Global Service Control Table
$sql1 = "CREATE TABLE IF NOT EXISTS sas_global_service_control (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(50) NOT NULL UNIQUE,
    status TINYINT(1) DEFAULT 1
)";
if (mysqli_query($connection_server, $sql1)) {
    echo "Table 'sas_global_service_control' created or already exists.<br>";
} else {
    echo "Error creating table: " . mysqli_error($connection_server) . "<br>";
}

// 2. Add columns to sas_user_banks
$sql2 = "ALTER TABLE sas_user_banks ADD COLUMN gateway_name VARCHAR(50) AFTER reference, ADD COLUMN status TINYINT(1) DEFAULT 1";
if (mysqli_query($connection_server, $sql2)) {
    echo "Columns added to 'sas_user_banks'.<br>";
} else {
    echo "Note: Columns might already exist in 'sas_user_banks'. Error: " . mysqli_error($connection_server) . "<br>";
}

// 3. Add columns to sas_vendor_banks
$sql3 = "ALTER TABLE sas_vendor_banks ADD COLUMN gateway_name VARCHAR(50) AFTER reference, ADD COLUMN status TINYINT(1) DEFAULT 1";
if (mysqli_query($connection_server, $sql3)) {
    echo "Columns added to 'sas_vendor_banks'.<br>";
} else {
    echo "Note: Columns might already exist in 'sas_vendor_banks'. Error: " . mysqli_error($connection_server) . "<br>";
}

// 4. Map existing bank accounts to gateways
echo "Mapping existing accounts...<br>";

$mappings = [
    'monnify' => ['WEMA', 'VFD', 'MONNIFY', 'STERLING', 'FIDELITY'],
    'payvessel' => ['PAYVESSEL', 'GLOBUS', 'TITAN'],
    'beewave' => ['BEEWAVE']
];

foreach ($mappings as $gateway => $keywords) {
    foreach ($keywords as $kw) {
        mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='$gateway' WHERE (bank_name LIKE '%$kw%' OR account_name LIKE '%$kw%') AND (gateway_name IS NULL OR gateway_name = '')");
        mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='$gateway' WHERE (bank_name LIKE '%$kw%' OR account_name LIKE '%$kw%') AND (gateway_name IS NULL OR gateway_name = '')");
    }
}

echo "Migration completed successfully.";
?>
