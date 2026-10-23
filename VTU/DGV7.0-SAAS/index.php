<?php
// Single source of truth for vendor data
include("func/bc-connect.php");

// Initialize vendor details
$vendor_account_details = null;
$error_message = null;

if ($connection_server) {
    $host = strtolower(trim(explode(':', $_SERVER["HTTP_HOST"])[0] ?? ''));
    $cacheKey = 'vendor_details_' . md5($host);

    if (function_exists('bc_cache_get')) {
        $vendor_account_details = bc_cache_get($cacheKey, 300);
    }

    if (!$vendor_account_details) {
        $stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_vendors WHERE website_url = ? AND status = 1 LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $host);

            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                if ($row = mysqli_fetch_assoc($result)) {
                    $vendor_account_details = $row;
                    if (function_exists('bc_cache_set')) {
                        bc_cache_set($cacheKey, $vendor_account_details);
                    }
                } else {
                    $error_message = "No vendor found for this host.";
                }
            } else {
                $error_message = "Failed to execute vendor query.";
            }

            mysqli_stmt_close($stmt);
        } else {
            $error_message = "Failed to prepare vendor query.";
        }
    }
} else {
    $error_message = "Failed to connect to the database.";
}

// Optional per-vendor App redirect (Off / Prompt / Force) — bc-admin > Account
// Settings > Site Details. Anonymous visitors only; logged-in users/admins are skipped.
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
$app_redirect = ['mode' => 'off', 'link' => ''];
if ($vendor_account_details) {
    $app_redirect = bc_get_app_redirect_info($vendor_account_details["id"]);
    if ($app_redirect['mode'] === 'force' && !isset($_SESSION['admin_session']) && !isset($_SESSION['user_session'])) {
        header("Location: " . $app_redirect['link']);
        exit;
    }
}

// Default CSS template
$css_style_template_location = "index-bc-style-template-1.php";

// If a vendor is found, check for a custom style template
if ($vendor_account_details) {
    $stmt_template = mysqli_prepare($connection_server, "SELECT template_name FROM sas_vendor_style_templates WHERE vendor_id = ?");
    if ($stmt_template) {
        mysqli_stmt_bind_param($stmt_template, "i", $vendor_account_details["id"]);
        
        if (mysqli_stmt_execute($stmt_template)) {
            $result_template = mysqli_stmt_get_result($stmt_template);
            if ($get_vendor_style_template = mysqli_fetch_assoc($result_template)) {
                $style_template_name = explode(".", trim($get_vendor_style_template["template_name"]))[0];
                if (!empty($style_template_name)) {
                    $style_template_location = "index-" . $style_template_name . ".php";
                    if (file_exists($style_template_location)) {
                        $css_style_template_location = $style_template_location;
                    }
                }
            }
        }
        
        mysqli_stmt_close($stmt_template);
    }
}

// Pass both vendor data and any error message to the template
include(__DIR__ . "/" . $css_style_template_location);

// Soft "Prompt" mode: show a dismissible interstitial offering the app, remembered via
// cookie for 30 days. Only for anonymous visitors when the vendor selected "prompt".
if ($app_redirect['mode'] === 'prompt' && !empty($app_redirect['link']) && !isset($_SESSION['admin_session']) && !isset($_SESSION['user_session'])) {
    $app_link_attr = htmlspecialchars($app_redirect['link'], ENT_QUOTES);
    echo '<!-- App Prompt (soft redirect mode) -->
<div id="appPromptOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.75);z-index:2147483647;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#ffffff;border-radius:16px;max-width:420px;width:100%;padding:28px 24px;text-align:center;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="font-size:44px;line-height:1;">&#128241;</div>
        <h3 style="margin:14px 0 6px;color:#0f172a;font-size:18px;font-weight:700;">Get the App for the Best Experience</h3>
        <p style="margin:0 0 22px;color:#64748b;font-size:14px;line-height:1.55;">Use our mobile app for faster transactions, instant alerts and exclusive offers &mdash; or continue on the website.</p>
        <a href="' . $app_link_attr . '" style="display:block;background:#287bff;color:#ffffff !important;text-decoration:none;padding:13px;border-radius:10px;font-weight:700;margin-bottom:10px;">Continue to App</a>
        <button type="button" id="appPromptStay" style="display:block;width:100%;background:transparent;border:1px solid #cbd5e1;color:#334155;padding:12px;border-radius:10px;font-weight:600;cursor:pointer;">Stay on Website</button>
    </div>
</div>
<script>
(function(){
    var overlay = document.getElementById("appPromptOverlay");
    var stay = document.getElementById("appPromptStay");
    if (!overlay || !stay) return;
    function dismissed(){ return document.cookie.split(";").some(function(c){ return c.trim().indexOf("app_prompt_dismissed=1") === 0; }); }
    if (dismissed()) return;
    overlay.style.display = "flex";
    stay.addEventListener("click", function(){
        document.cookie = "app_prompt_dismissed=1; path=/; max-age=2592000";
        overlay.style.display = "none";
    });
})();
</script>';
}
?>