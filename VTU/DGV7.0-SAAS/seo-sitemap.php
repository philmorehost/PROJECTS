<?php
/**
 * DGV7.0-SAAS - Dynamic XML Sitemap Generator per Vendor
 */

header("Content-Type: application/xml; charset=utf-8");

include("func/bc-connect.php");

$vendor = null;
$sitemap_enabled = 1;

if ($connection_server) {
    $host = strtolower(trim(explode(':', $_SERVER["HTTP_HOST"])[0] ?? ''));
    
    // Resolve vendor
    $stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_vendors WHERE website_url = ? AND status = 1 LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $host);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $vendor = $row;
            
            // Check if sitemap is enabled
            $stmt_site = mysqli_prepare($connection_server, "SELECT sitemap_enabled FROM sas_site_details WHERE vendor_id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt_site, "i", $vendor["id"]);
            if (mysqli_stmt_execute($stmt_site)) {
                $res_site = mysqli_stmt_get_result($stmt_site);
                if ($row_site = mysqli_fetch_assoc($res_site)) {
                    $sitemap_enabled = (int)$row_site['sitemap_enabled'];
                }
            }
            mysqli_stmt_close($stmt_site);
        }
    }
    mysqli_stmt_close($stmt);
}

// If sitemap disabled, output empty or return 404
if (!$vendor || $sitemap_enabled !== 1) {
    http_response_code(404);
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<!-- Sitemap Disabled or Vendor Not Found -->';
    echo '</urlset>';
    exit;
}

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$site_url = $protocol . $_SERVER['HTTP_HOST'];
$last_mod = date('c');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n";

// Dynamic sitemap entries
$pages = [
    ['loc' => '/', 'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => '/Login', 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['loc' => '/Register', 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['loc' => '/Pricing', 'priority' => '0.7', 'changefreq' => 'weekly'],
    ['loc' => '/policy/privacy', 'priority' => '0.3', 'changefreq' => 'monthly'],
    ['loc' => '/policy/terms', 'priority' => '0.3', 'changefreq' => 'monthly'],
    ['loc' => '/policy/refund', 'priority' => '0.3', 'changefreq' => 'monthly'],
    ['loc' => '/policy/aml', 'priority' => '0.3', 'changefreq' => 'monthly'],
    ['loc' => '/policy/kyc', 'priority' => '0.3', 'changefreq' => 'monthly'],
    ['loc' => '/policy/risk-disclosure', 'priority' => '0.3', 'changefreq' => 'monthly'],
];

foreach ($pages as $p) {
    echo '  <url>' . "\n";
    echo '    <loc>' . htmlspecialchars($site_url . $p['loc']) . '</loc>' . "\n";
    echo '    <lastmod>' . $last_mod . '</lastmod>' . "\n";
    echo '    <changefreq>' . $p['changefreq'] . '</changefreq>' . "\n";
    echo '    <priority>' . $p['priority'] . '</priority>' . "\n";
    echo '  </url>' . "\n";
}

echo '</urlset>';
