<?php
/**
 * DGV7.0-SAAS - Dynamic robots.txt Generator per Vendor
 */

header("Content-Type: text/plain; charset=utf-8");

include("func/bc-connect.php");

$vendor = null;
$custom_robots = '';

if ($connection_server) {
    $host = strtolower(trim(explode(':', $_SERVER["HTTP_HOST"])[0] ?? ''));
    
    // Resolve vendor
    $stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_vendors WHERE website_url = ? AND status = 1 LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $host);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $vendor = $row;
            
            // Check custom robots txt
            $stmt_site = mysqli_prepare($connection_server, "SELECT robots_txt FROM sas_site_details WHERE vendor_id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt_site, "i", $vendor["id"]);
            if (mysqli_stmt_execute($stmt_site)) {
                $res_site = mysqli_stmt_get_result($stmt_site);
                if ($row_site = mysqli_fetch_assoc($res_site)) {
                    $custom_robots = trim($row_site['robots_txt'] ?? '');
                }
            }
            mysqli_stmt_close($stmt_site);
        }
    }
    mysqli_stmt_close($stmt);
}

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$site_url = $protocol . $_SERVER['HTTP_HOST'];

if (!empty($custom_robots)) {
    // Output custom vendor robots.txt content (replace {sitemap} placeholder with dynamic sitemap URL if exists)
    $custom_robots = str_ireplace('{sitemap_url}', $site_url . '/sitemap.xml', $custom_robots);
    echo $custom_robots;
} else {
    // Sensible premium defaults
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "Disallow: /bc-admin/\n";
    echo "Disallow: /bc-spadmin/\n";
    echo "Disallow: /func/\n";
    echo "Disallow: /api/\n";
    echo "Disallow: /webhook/\n";
    echo "Disallow: /payment/\n";
    echo "\n";
    echo "Sitemap: " . $site_url . "/sitemap.xml\n";
}
