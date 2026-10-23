<?php
// cPanel UAPI Integration for Addon Domains

function cpanel_add_addon_domain($new_domain) {
    global $connection_server;
    $host = getSuperAdminOption('cpanel_host');
    $host = str_replace(['https://', 'http://'], '', $host);
    $user = getSuperAdminOption('cpanel_username');
    $token = getSuperAdminOption('cpanel_api_token');

    if (empty($host) || empty($user) || empty($token)) return false;

    // Remove protocol and www
    $new_domain = str_replace(["https://", "http://", "www."], "", strtolower($new_domain));
    if (empty($new_domain)) return false;

    // Use a clean subdomain prefix derived from the domain (cPanel requires a subdomain)
    $subdomain = str_replace('.', '_', $new_domain);

    // Build the query
    $query = "https://" . $host . ":2083/execute/AddonDomain/addaddondomain?dir=public_html&newdomain=" . urlencode($new_domain) . "&subdomain=" . urlencode($subdomain);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $query);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: cpanel " . $user . ":" . $token
    ));

    $result = curl_exec($ch);
    curl_close($ch);

    return true; // We can return decode check if needed
}

function cpanel_remove_addon_domain($old_domain) {
    global $connection_server;
    $host = getSuperAdminOption('cpanel_host');
    $host = str_replace(['https://', 'http://'], '', $host);
    $user = getSuperAdminOption('cpanel_username');
    $token = getSuperAdminOption('cpanel_api_token');

    if (empty($host) || empty($user) || empty($token)) return false;

    $old_domain = str_replace(["https://", "http://", "www."], "", strtolower($old_domain));
    if (empty($old_domain)) return false;

    // Build the query
    $query = "https://" . $host . ":2083/execute/AddonDomain/deladdondomain?domain=" . urlencode($old_domain);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $query);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: cpanel " . $user . ":" . $token
    ));

    $result = curl_exec($ch);
    curl_close($ch);

    return true;
}
?>
