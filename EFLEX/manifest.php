<?php
header('Content-Type: application/json');
require_once 'includes/db_connect.php';

// Fetch PWA settings from the database
$settings_sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'pwa_%'";
$result = $mysqli->query($settings_sql);
$settings = [];
while($row = $result->fetch_assoc()){
    $settings[$row['setting_key']] = $row['setting_value'];
}

$pwa_app_name = $settings['pwa_app_name'] ?? 'Eflex E-commerce';
$pwa_app_short_name = $settings['pwa_app_short_name'] ?? 'Eflex';
$pwa_theme_color = $settings['pwa_theme_color'] ?? '#ffffff';
$pwa_bg_color = $settings['pwa_bg_color'] ?? '#000000';
$pwa_icons = isset($settings['pwa_icons']) ? json_decode($settings['pwa_icons'], true) : null;
if (empty($pwa_icons)) {
    // NOTE: isset() is true for an empty-string setting and json_decode('') returns
    // null, which produced "icons": null and made the app non-installable.
    $pwa_icons = [
        [
            "src" => "/uploads/pwa/icon-192x192.png",
            "sizes" => "192x192",
            "type" => "image/png"
        ],
        [
            "src" => "/uploads/pwa/icon-512x512.png",
            "sizes" => "512x512",
            "type" => "image/png"
        ]
    ];
}

$manifest = [
    'name' => $pwa_app_name,
    'short_name' => $pwa_app_short_name,
    'start_url' => '/',
    'display' => 'standalone',
    'background_color' => $pwa_bg_color,
    'theme_color' => $pwa_theme_color,
    'icons' => $pwa_icons
];

echo json_encode($manifest, JSON_PRETTY_PRINT);
?>
