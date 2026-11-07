<?php
/**
 * Advanced SEO Generator
 * Automatically compiles static physical sitemap.xml and robots.txt in the root directory.
 */

function generateSitemap($vendor_id, $connection_server) {
    // Determine the base protocol and host dynamically
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $base_url = rtrim($protocol . $host, '/');

    // Define main static pages
    $pages = [
        ['loc' => $base_url . '/', 'priority' => '1.0', 'changefreq' => 'daily'],
        ['loc' => $base_url . '/web/Login.php', 'priority' => '0.8', 'changefreq' => 'weekly'],
        ['loc' => $base_url . '/web/Register.php', 'priority' => '0.8', 'changefreq' => 'weekly'],
        ['loc' => $base_url . '/web/Pricing.php', 'priority' => '0.9', 'changefreq' => 'daily'],
        ['loc' => $base_url . '/web/APIDocs.php', 'priority' => '0.7', 'changefreq' => 'monthly'],
        ['loc' => $base_url . '/blog.php', 'priority' => '0.7', 'changefreq' => 'daily']
    ];

    // Fetch published blog posts for this vendor
    $stmt = mysqli_prepare($connection_server, "SELECT id, updated_at, created_at FROM blog_posts WHERE status = 'published' AND author_id = ? ORDER BY created_at DESC");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $vendor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $lastmod = !empty($row['updated_at']) ? $row['updated_at'] : $row['created_at'];
            $formatted_date = date('Y-m-d', strtotime($lastmod));
            $pages[] = [
                'loc' => $base_url . '/single-post.php?id=' . $row['id'],
                'priority' => '0.6',
                'changefreq' => 'weekly',
                'lastmod' => $formatted_date
            ];
        }
        mysqli_stmt_close($stmt);
    }

    // Build XML Content
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($pages as $page) {
        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . htmlspecialchars($page['loc'], ENT_QUOTES, 'UTF-8') . '</loc>' . "\n";
        if (isset($page['lastmod'])) {
            $xml .= '    <lastmod>' . $page['lastmod'] . '</lastmod>' . "\n";
        } else {
            $xml .= '    <lastmod>' . date('Y-m-d') . '</lastmod>' . "\n";
        }
        $xml .= '    <changefreq>' . $page['changefreq'] . '</changefreq>' . "\n";
        $xml .= '    <priority>' . $page['priority'] . '</priority>' . "\n";
        $xml .= '  </url>' . "\n";
    }

    $xml .= '</urlset>' . "\n";

    // Write to root physical sitemap.xml
    $file_path = dirname(__DIR__) . '/sitemap.xml';
    return @file_put_contents($file_path, $xml) !== false;
}

function generateRobotsTxt($vendor_id, $connection_server) {
    // Fetch custom robots.txt from database
    $stmt = mysqli_prepare($connection_server, "SELECT robots_txt FROM sas_site_details WHERE vendor_id = ? LIMIT 1");
    $custom_robots = "";
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $vendor_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $custom_robots);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
    }

    if (empty(trim($custom_robots))) {
        // Build search-friendly default robots.txt
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $base_url = rtrim($protocol . $host, '/');

        $custom_robots = "User-agent: *\n";
        $custom_robots .= "Allow: /\n";
        $custom_robots .= "Disallow: /bc-admin/\n";
        $custom_robots .= "Disallow: /func/\n";
        $custom_robots .= "Disallow: /web/assets/\n";
        $custom_robots .= "Disallow: /web/policy/_header.php\n";
        $custom_robots .= "Disallow: /web/policy/_footer.php\n";
        $custom_robots .= "\n";
        $custom_robots .= "Sitemap: " . $base_url . "/sitemap.xml\n";
    }

    // Write to root physical robots.txt
    $file_path = dirname(__DIR__) . '/robots.txt';
    return @file_put_contents($file_path, trim($custom_robots)) !== false;
}
