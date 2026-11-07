<?php
include("../func/bc-connect.php");
$lic_key = getSuperAdminOption('license_key', 'NOT_FOUND');
$lic_domain = getSuperAdminOption('license_domain', 'NOT_FOUND');
$lic_status = getSuperAdminOption('license_status', 'NOT_FOUND');
echo "License Key: " . $lic_key . "\n";
echo "License Domain: " . $lic_domain . "\n";
echo "License Status: " . $lic_status . "\n";

$api_url = "https://licensing.philmorehost.com/verify.php?key=" . urlencode($lic_key) . "&domain=" . urlencode($lic_domain);
echo "API URL: " . $api_url . "\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: " . $http_code . "\n";
echo "Curl Error: " . $error . "\n";
echo "Response: " . $response . "\n";
?>
