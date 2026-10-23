<?php
/**
 * Guest-safe site-info: branding + Service Control Centre status, so the Guest apps can hide
 * a service tile the admin has disabled instead of showing every service unconditionally.
 * Mirrors web/api/site-info.php's shape/queries verbatim (same tables, same field names) so
 * both the authenticated web app and the guest apps read a single consistent source of truth —
 * built on guest-bootstrap.php's helpers since there's no session/api_key here.
 */
include_once(__DIR__ . "/guest-bootstrap.php");

$vendor = guest_resolve_vendor();
$vendor_id = (int)$vendor['id'];

guest_security_gate($vendor_id, "guest_site_info", 60, 60);

$get_site = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='$vendor_id' LIMIT 1"));
$get_style = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendor_style_templates WHERE vendor_id='$vendor_id' LIMIT 1"));

// A service_name with no row here is treated as enabled by default (matching isServiceEnabled()'s
// own documented default) — the app must NOT hide a key that's simply absent from this map.
$services = array();
$get_sc = mysqli_query($connection_server, "SELECT service_name, status FROM sas_service_control WHERE vendor_id='$vendor_id'");
while ($row = mysqli_fetch_assoc($get_sc)) {
    $services[$row['service_name']] = (int)$row['status'];
}

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$sanitized_host = strtolower(preg_replace('/^www\./', '', explode(':', $host)[0]));

guest_json([
    "status" => "success",
    "data" => [
        "site_title" => $get_site['site_title'] ?? "VTU Platform",
        "logo_url" => "https://" . $host . "/uploaded-image/" . str_replace([".", ":"], "-", $sanitized_host) . "_logo.png",
        "primary_color" => $get_style['primary_color'] ?? "#287bff",
        "secondary_color" => $get_style['secondary_color'] ?? "#f6f9fc",
        "services" => $services,
        "currency_symbol" => "₦",
        "support" => [
            "email" => $vendor['email'] ?? "",
            "phone" => !empty($vendor['phone_number']) ? "234" . ltrim($vendor['phone_number'], '0') : "",
            "address" => $vendor['home_address'] ?? ""
        ]
    ]
]);
