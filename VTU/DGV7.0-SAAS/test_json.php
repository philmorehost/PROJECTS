<?php
$array = array("network" => "1", "plan" => "123", "mobile_number" => "08119871010", "Ported_number" => true);
$encoded = json_encode($array, true);
echo "ENCODED: " . var_export($encoded, true) . "\n";
?>
