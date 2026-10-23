<?php
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

// Identify vendor by host
$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

if ($get_vendor) {

    // Fetch site details
    $get_site = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='$vendor_id' LIMIT 1"));

    // Fetch style details
    $get_style = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendor_style_templates WHERE vendor_id='$vendor_id' LIMIT 1"));

    // Check which services are enabled
    $services = array();
    $get_sc = mysqli_query($connection_server, "SELECT service_name, status FROM sas_service_control WHERE vendor_id='$vendor_id'");
    while($row = mysqli_fetch_assoc($get_sc)){
        $services[$row['service_name']] = (int)$row['status'];
    }
    // New Services
    $services['gift_card'] = isServiceEnabled('gift_card', $vendor_id) ? 1 : 0;
    $services['virtual_card'] = isServiceEnabled('virtual_card', $vendor_id) ? 1 : 0;
    $services['crypto_hub'] = isServiceEnabled('crypto_hub', $vendor_id) ? 1 : 0;

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $sanitized_host = strtolower(preg_replace('/^www\./', '', explode(':', $host)[0]));

    echo json_encode([
        "status" => "success",
        "data" => [
            "site_title" => $get_site['site_title'] ?? "VTU Platform",
            "logo_url" => "https://" . $host . "/uploaded-image/" . str_replace([".", ":"], "-", $sanitized_host) . "_logo.png",
            "primary_color" => $get_style['primary_color'] ?? "#287bff",
            "secondary_color" => $get_style['secondary_color'] ?? "#f6f9fc",
            "services" => $services,
            "currency_symbol" => "₦",
            "support" => [
                "email" => $get_vendor['email'] ?? "",
                "phone" => !empty($get_vendor['phone_number']) ? "234" . ltrim($get_vendor['phone_number'], '0') : "",
                "address" => $get_vendor['home_address'] ?? "",
                // Passed through as-is (already digits-only, country code included) — not assumed to
                // be Nigerian, since vendors may run their WhatsApp Business number on any country's line.
                "whatsapp" => $get_vendor['support_whatsapp'] ?? ""
            ]
        ]
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Platform not found on this domain"]);
}

mysqli_close($connection_server);
