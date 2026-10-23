<?php
if (isset($_SERVER["HTTPS"]) && ($_SERVER["HTTPS"] == "on")) {
	$web_http_host = "https://" . $_SERVER["HTTP_HOST"];
} else {
	$web_http_host = "http://" . $_SERVER["HTTP_HOST"];
}

include($_SERVER["DOCUMENT_ROOT"] . "/func/bc-connect.php");
include($_SERVER["DOCUMENT_ROOT"] . "/func/bc-tables.php");

//Service Provider ID Array
$mtn_carrier_id_array = array("803", "702", "703", "704", "903", "806", "706", "707", "813", "810", "814", "816", "906", "916", "913", "903");
$airtel_carrier_id_array = array("701", "708", "802", "808", "812", "901", "902", "904", "907", "911", "912");
$glo_carrier_id_array = array("805", "705", "905", "807", "815", "811", "915");
$etisalat_carrier_id_array = array("809", "817", "818", "908", "909");

?>