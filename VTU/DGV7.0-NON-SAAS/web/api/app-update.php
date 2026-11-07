<?php
/**
 * web/api/app-update.php
 *
 * Returns the latest Android APK version info so the app can prompt the user
 * to update when a newer version is available.
 *
 * The admin uploads the APK to the cPanel file manager (e.g. /apk/<brand>-1.1.0.apk)
 * and updates the version info below. "<brand>" defaults to this vendor's site title.
 *
 * Response (when an update is available):
 *   {
 *     "status":       "update_available",
 *     "version_code": 2,
 *     "version_name": "1.1.0",
 *     "apk_url":      "https://yourdomain.com/apk/<brand>-1.1.0.apk",
 *     "changelog":    "Bug fixes and performance improvements."
 *   }
 *
 * Response (when the app is up to date):
 *   { "status": "up_to_date" }
 */

header("Content-Type: application/json");

// Needs the DB connection and resolveVendorID() so the vendor's chosen update source
// (bc-admin/AppUpdateBroadcast.php) can be honoured.
include_once("../../func/bc-connect.php");

// ── Update configuration ──────────────────────────────────────────────────────
// Edit these values each time you upload a new APK to cPanel.

/**
 * Increment this integer every time you publish a new APK.
 * It must be strictly greater than the versionCode in app/build.gradle
 * for the update prompt to appear.
 */
$latest_version_code = 1;

/** Human-readable version string shown to the user. */
$latest_version_name = "1.0.0";

/**
 * Direct HTTPS URL to the APK in cPanel.
 * Upload the APK via cPanel File Manager → public_html/apk/
 * and set the URL accordingly.
 */
$apk_filename = "";
$app_update_row = null;

// ── Admin-configured settings for this vendor (bc-admin/AppUpdateBroadcast.php) ──
// "local" (the /apk directory below) is the DEFAULT source, so an install that has never saved this
// setting keeps working exactly as before. Anything left blank here falls back to the values above.
$update_source  = "local";
$play_store_url = "";
$vendor_id      = function_exists("resolveVendorID") ? (int) resolveVendorID() : 0;
if ($vendor_id > 0 && $connection_server) {
    $q_aus = mysqli_query($connection_server, "SELECT * FROM sas_app_update_settings WHERE vendor_id='$vendor_id' LIMIT 1");
    $aus   = ($q_aus && mysqli_num_rows($q_aus) > 0) ? mysqli_fetch_assoc($q_aus) : null;
    $app_update_row = $aus;
    if ($aus) {
        if (trim($aus["update_source"] ?? "") === "play") { $update_source = "play"; }
        $play_store_url = trim($aus["play_store_url"] ?? "");
        if ((int) ($aus["apk_version_code"] ?? 0) > 0) { $latest_version_code = (int) $aus["apk_version_code"]; }
        if (trim($aus["apk_version_name"] ?? "") !== "") { $latest_version_name = trim($aus["apk_version_name"]); }
        if (trim($aus["apk_filename"] ?? "") !== "") { $apk_filename = trim($aus["apk_filename"]); }
        // The changelog default is declared further down, so it is applied there instead of here.
    }
}
if ($apk_filename === "") {
    // Brand-aware default, matching the "<brand>-<version>.apk" the app itself requests
    // (AppUpdateManager.downloadAndInstall). A hardcoded brand here pointed every other brand
    // at a file that cannot exist, and the file_exists() gate below then reported "up_to_date"
    // for ever. Falls back to the unbranded "<version>.apk" if the brand is unknown.
    $__brand_slug = function_exists("bc_app_brand_slug") ? bc_app_brand_slug($connection_server, $vendor_id) : "";
    $apk_filename = ($__brand_slug !== "" ? $__brand_slug . "-" : "") . $latest_version_name . ".apk";
}

$host = function_exists("bc_safe_host") ? bc_safe_host() : ($_SERVER['HTTP_HOST'] ?? 'localhost');
$apk_url = "https://{$host}/apk/{$apk_filename}";

/** Optional – shown in the update dialog. */
$changelog = "";
if ($app_update_row && trim($app_update_row["changelog"] ?? "") !== "") {
    $changelog = trim($app_update_row["changelog"]); // admin setting (bc-admin/AppUpdateBroadcast.php)
}

// ── Logic ──────────────────────────────────────────────────────────────────────
// Only return update_available when the APK file actually exists on disk.
$apk_path = __DIR__ . "/../../apk/{$apk_filename}";

// ── Google Play mode ───────────────────────────────────────────────────────────
// No local APK is involved, so the file check below does not apply: the admin "publishes" by
// selecting Google Play and saving a store URL, and clearing that URL stops the prompt again.
// apk_url is deliberately omitted so an APK-installing build does not try to download a store page.
if ($update_source === "play") {
    if ($play_store_url === "") {
        echo json_encode(["status" => "up_to_date", "update_source" => "play"]);
    } else {
        echo json_encode([
            "status"        => "update_available",
            "update_source" => "play",
            "version_code"  => $latest_version_code,
            "version_name"  => $latest_version_name,
            "play_url"      => $play_store_url,
            "store_url"     => $play_store_url,
            "changelog"     => $changelog,
        ]);
    }
    exit;
}

// ── Local host (directory) mode ────────────────────────────────────────────────
if (file_exists($apk_path)) {
    echo json_encode([
        "status"        => "update_available",
        "update_source" => "local",
        "version_code"  => $latest_version_code,
        "version_name"  => $latest_version_name,
        "apk_url"       => $apk_url,
        "changelog"     => $changelog,
    ]);
} else {
    // APK not uploaded yet – treat as up to date so the app is not prompted.
    echo json_encode(["status" => "up_to_date", "update_source" => "local"]);
}
