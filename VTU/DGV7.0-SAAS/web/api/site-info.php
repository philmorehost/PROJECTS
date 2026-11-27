<?php
header("Content-Type: application/json");
header("Cache-Control: no-store");
require_once __DIR__ . "/../../func/bc-theme.php";
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
    if (file_exists(__DIR__ . '/../../func/bc-stepup.php')) { include_once(__DIR__ . '/../../func/bc-stepup.php'); }
    $services = array();
    $has_global_sc = mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_global_service_control'");
    if ($has_global_sc && mysqli_num_rows($has_global_sc) > 0) {
        $get_gsc = mysqli_query($connection_server, "SELECT service_name, status FROM sas_global_service_control ORDER BY id ASC");
        if ($get_gsc) {
            while ($grow = mysqli_fetch_assoc($get_gsc)) {
                $services[$grow['service_name']] = (int)$grow['status'];
            }
        }
    }
    $get_sc = mysqli_query($connection_server, "SELECT service_name, status FROM sas_service_control WHERE vendor_id='$vendor_id' ORDER BY id ASC");
    if ($get_sc) {
        while($row = mysqli_fetch_assoc($get_sc)){
            $services[$row['service_name']] = (int)$row['status'];
        }
    }
    // §33: effective registration and password_reset state for the mobile App after table merges
    // so stale/missing vendor rows or global overrides cannot leave login actions out of sync.
    $services['registration'] = (!function_exists('bc_registration_enabled') || bc_registration_enabled($vendor_id)) ? 1 : 0;
    $services['password_reset'] = (!function_exists('isServiceEnabled') || isServiceEnabled('password_reset', $vendor_id)) ? 1 : 0;
    // New Services
    $services['gift_card'] = isServiceEnabled('gift_card', $vendor_id) ? 1 : 0;
    $services['virtual_card'] = isServiceEnabled('virtual_card', $vendor_id) ? 1 : 0;
    $services['crypto_hub'] = isServiceEnabled('crypto_hub', $vendor_id) ? 1 : 0;

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $sanitized_host = strtolower(preg_replace('/^www\./', '', explode(':', $host)[0]));

    $site_title_api = trim((string)($get_site['site_title'] ?? ''));
    if ($site_title_api === '') {
        $site_title_api = 'FintechFlow';
    }

    echo json_encode([
        "status" => "success",
        "data" => [
            "site_title" => $site_title_api,
            "logo_url" => "https://" . $host . "/uploaded-image/" . str_replace([".", ":"], "-", $sanitized_host) . "_logo.png",
            "primary_color" => bc_theme_palette($get_style['primary_color'] ?? null)['primary_color'],
            "theme" => bc_theme_palette($get_style['primary_color'] ?? null),
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
