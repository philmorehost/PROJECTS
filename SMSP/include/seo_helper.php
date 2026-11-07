<?php
// SEO Optimization & Metadata Helper

class SEOHelper {
    /**
     * Renders standard SEO Meta tags dynamically.
     */
    public static function renderMeta($title, $description, $keywords = '', $ogImage = '') {
        $canonical = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $titleEsc = htmlspecialchars($title);
        $descEsc = htmlspecialchars($description);
        $keyEsc = htmlspecialchars($keywords);

        $html = "<title>{$titleEsc}</title>\n";
        $html .= "    <meta name=\"description\" content=\"{$descEsc}\" />\n";
        if (!empty($keywords)) {
            $html .= "    <meta name=\"keywords\" content=\"{$keyEsc}\" />\n";
        }
        $html .= "    <link rel=\"canonical\" href=\"{$canonical}\" />\n";

        // OpenGraph Meta
        $html .= "    <meta property=\"og:title\" content=\"{$titleEsc}\" />\n";
        $html .= "    <meta property=\"og:description\" content=\"{$descEsc}\" />\n";
        $html .= "    <meta property=\"og:type\" content=\"website\" />\n";
        $html .= "    <meta property=\"og:url\" content=\"{$canonical}\" />\n";
        if (!empty($ogImage)) {
            $html .= "    <meta property=\"og:image\" content=\"" . htmlspecialchars($ogImage) . "\" />\n";
        }
        return $html;
    }

    /**
     * Generates and returns JSON-LD School Schema markup.
     */
    public static function getSchoolJSONLD($name, $motto, $logo, $address, $phone, $email) {
        $schema = [
            "@context" => "https://schema.org",
            "@type" => "EducationalOrganization",
            "name" => $name,
            "description" => $motto,
            "logo" => $logo,
            "address" => [
                "@type" => "PostalAddress",
                "streetAddress" => $address
            ],
            "telephone" => $phone,
            "email" => $email
        ];
        return "<script type=\"application/ld+json\">\n" . json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</script>";
    }

    /**
     * Generates sitemap XML content dynamically.
     */
    public static function generateSitemapXML($pdo, $schoolId) {
        $domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
        
        $urls = [
            ['loc' => $domain . '/', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => $domain . '/login.php', 'changefreq' => 'monthly', 'priority' => '0.8']
        ];

        // Fetch notices or alerts published to add dynamically if any
        try {
            $stmt = $pdo->prepare("SELECT notice_id, start_date FROM sm_notices WHERE school_id_number = ? ORDER BY notice_id DESC LIMIT 50");
            $stmt->execute([$schoolId]);
            $notices = $stmt->fetchAll();
            foreach ($notices as $notice) {
                $urls[] = [
                    'loc' => $domain . '/notice.php?id=' . $notice['notice_id'],
                    'lastmod' => $notice['start_date'],
                    'changefreq' => 'weekly',
                    'priority' => '0.6'
                ];
            }
        } catch (Exception $e) {
            // Ignore if tables are not fully set up yet
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
            if (isset($url['lastmod'])) {
                $xml .= "    <lastmod>" . htmlspecialchars($url['lastmod']) . "</lastmod>\n";
            }
            $xml .= "    <changefreq>" . htmlspecialchars($url['changefreq']) . "</changefreq>\n";
            $xml .= "    <priority>" . htmlspecialchars($url['priority']) . "</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= "</urlset>";
        return $xml;
    }
}
?>
