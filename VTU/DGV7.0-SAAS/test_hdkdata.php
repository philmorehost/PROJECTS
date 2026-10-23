<?php
$product_name = "glo";
$quantity = "200mb"; // Wait, for glo shared, what are the sizes? 
// In shared-data, glo has 1gb, 3gb, 5gb. Where is 200mb?
// Ah! The user requested: "Test GLO Data 200MB to 08119871010"
// BUT in shared-data-hdkdata-com.php:
// $web_data_size_array = array("1gb" => "339", "3gb" => "340", "5gb" => "341");
// 200mb is NOT in shared-data!
// It's in cg-data!
// For cg-data:
// $web_data_size_array = array("200mb" => "293", "500mb" => "198", "1gb" => "194", "2gb" => "195", "3gb" => "196", "5gb" => "197", "10gb" => "200");

// Let's test cg-data with 200mb.
$phone_no = "08119871010";
$api_detail = array(
    "api_base_url" => "hdkdata.com",
    "api_key" => "dc73ba27562cd812bac11c8ac7a94fc4c14b05d2"
);

$net_id = "2"; // Glo
$web_data_size_array = array("1gb" => "339"); // Shared Data

$clean_base_url = preg_replace('#^https?://#', '', trim($api_detail["api_base_url"]));
$clean_base_url = rtrim($clean_base_url, "/");
$curl_url = "https://" . $clean_base_url . "/api/data/";
$curl_request = curl_init($curl_url);
curl_setopt($curl_request, CURLOPT_POST, true);
curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
$curl_http_headers = array(
    "Authorization: Token " . $api_detail["api_key"],
    "Content-Type: application/json",
);
curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
$curl_postfields_data = json_encode(array("network" => $net_id, "plan" => $web_data_size_array[$quantity], "mobile_number" => $phone_no, "Ported_number" => true));
curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
$curl_result = curl_exec($curl_request);
echo "RESPONSE:\n" . $curl_result . "\n";
echo "ERROR:\n" . curl_error($curl_request) . "\n";
?>
