<?php
/**
 * DGV7.0-SAAS - Per-Vendor SEO & Analytics Engine
 * Provides dynamic SEO tags, Open Graph, Twitter Card, Canonical URL, Schema.org JSON-LD,
 * custom tracking scripts, and automatic image alt attribute processing.
 */

if (!defined('SEO_INCLUDED')) {
    define('SEO_INCLUDED', true);
}

/**
 * Renders SEO meta, Open Graph, Twitter, Favicon, Canonical, Schema.org tags, and tracking scripts
 * 
 * @param array $site_details Result of `sas_site_details`
 * @param array $vendor Result of `sas_vendors`
 * @param string $page_title Optional override title
 * @param string $page_desc Optional override description
 */
function seo_render_head_tags($site_details, $vendor, $page_title = '', $page_desc = '') {
    $site_title = !empty($site_details['site_title']) ? $site_details['site_title'] : 'Digital Payments';
    $site_desc = !empty($site_details['site_desc']) ? $site_details['site_desc'] : 'Fast and secure digital VTU services.';
    
    // Fallback meta values
    $final_title = !empty($page_title) ? $page_title : $site_title;
    $final_desc = !empty($page_desc) ? $page_desc : $site_desc;
    
    $keywords = !empty($site_details['meta_keywords']) ? $site_details['meta_keywords'] : 'vtu, bill payment, airtime, data, electricity, cable tv';
    $author = !empty($site_details['meta_author']) ? $site_details['meta_author'] : 'Philmore Codes';
    
    // Canonical URL
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    $request_uri = strtok($_SERVER['REQUEST_URI'], '?');
    $canonical_url = $protocol . $domain . $request_uri;
    
    // Images & Favicon
    $favicon = !empty($site_details['favicon_url']) ? $site_details['favicon_url'] : '';
    if (empty($favicon)) {
        $logo_filename = str_replace([".", ":"], "-", $domain) . "_logo.png";
        $favicon = file_exists(__DIR__ . "/../uploaded-image/" . $logo_filename) ? "uploaded-image/" . $logo_filename : '';
    }
    
    $og_img = !empty($site_details['og_image']) ? $site_details['og_image'] : '';
    if (empty($og_img)) {
        $logo_filename = str_replace([".", ":"], "-", $domain) . "_logo.png";
        $og_img = file_exists(__DIR__ . "/../uploaded-image/" . $logo_filename) ? "uploaded-image/" . $logo_filename : '';
    }
    
    // Render standard SEO tags
    echo '    <title>' . htmlspecialchars($final_title) . '</title>' . "\n";
    echo '    <meta name="description" content="' . htmlspecialchars($final_desc) . '">' . "\n";
    echo '    <meta name="keywords" content="' . htmlspecialchars($keywords) . '">' . "\n";
    echo '    <meta name="author" content="' . htmlspecialchars($author) . '">' . "\n";
    echo '    <link rel="canonical" href="' . htmlspecialchars($canonical_url) . '">' . "\n";
    
    // Favicon
    if (!empty($favicon)) {
        echo '    <link rel="icon" type="image/png" href="' . htmlspecialchars($favicon) . '">' . "\n";
    }
    
    // Open Graph
    echo '    <!-- Open Graph / Facebook -->' . "\n";
    echo '    <meta property="og:type" content="website">' . "\n";
    echo '    <meta property="og:url" content="' . htmlspecialchars($canonical_url) . '">' . "\n";
    echo '    <meta property="og:title" content="' . htmlspecialchars($final_title) . '">' . "\n";
    echo '    <meta property="og:description" content="' . htmlspecialchars($final_desc) . '">' . "\n";
    if (!empty($og_img)) {
        echo '    <meta property="og:image" content="' . htmlspecialchars($og_img) . '">' . "\n";
    }
    
    // Twitter
    $twitter_handle = !empty($site_details['social_twitter']) ? $site_details['social_twitter'] : '';
    echo '    <!-- Twitter -->' . "\n";
    echo '    <meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '    <meta name="twitter:url" content="' . htmlspecialchars($canonical_url) . '">' . "\n";
    echo '    <meta name="twitter:title" content="' . htmlspecialchars($final_title) . '">' . "\n";
    echo '    <meta name="twitter:description" content="' . htmlspecialchars($final_desc) . '">' . "\n";
    if (!empty($og_img)) {
        echo '    <meta name="twitter:image" content="' . htmlspecialchars($og_img) . '">' . "\n";
    }
    if (!empty($twitter_handle)) {
        echo '    <meta name="twitter:site" content="@' . htmlspecialchars(ltrim($twitter_handle, '@')) . '">' . "\n";
    }
    
    // JSON-LD Structured Data
    $schema_type = !empty($site_details['schema_org_type']) ? $site_details['schema_org_type'] : 'Organization';
    $schema_phone = !empty($site_details['schema_org_phone']) ? $site_details['schema_org_phone'] : ($vendor['phone_number'] ?? '');
    $schema_address = !empty($site_details['schema_org_address']) ? $site_details['schema_org_address'] : ($vendor['home_address'] ?? '');
    
    $schema_data = [
        "@context" => "https://schema.org",
        "@type" => $schema_type,
        "name" => $site_title,
        "url" => $protocol . $domain,
        "description" => $site_desc
    ];
    
    if (!empty($favicon)) {
        $schema_data["logo"] = $protocol . $domain . '/' . ltrim($favicon, '/');
    }
    if (!empty($schema_phone)) {
        $schema_data["contactPoint"] = [
            "@type" => "ContactPoint",
            "telephone" => $schema_phone,
            "contactType" => "customer service"
        ];
    }
    if (!empty($schema_address)) {
        $schema_data["address"] = [
            "@type" => "PostalAddress",
            "streetAddress" => $schema_address
        ];
    }
    
    $same_as = [];
    if (!empty($site_details['social_facebook'])) $same_as[] = $site_details['social_facebook'];
    if (!empty($site_details['social_instagram'])) $same_as[] = $site_details['social_instagram'];
    if (!empty($site_details['social_twitter'])) $same_as[] = 'https://twitter.com/' . ltrim($site_details['social_twitter'], '@');
    if (!empty($same_as)) {
        $schema_data["sameAs"] = $same_as;
    }
    
    echo '    <script type="application/ld+json">' . "\n";
    echo json_encode($schema_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    echo '    </script>' . "\n";
    
    // Inject Analytics scripts
    seo_render_analytics($site_details);
    
    // Inject Custom Head Code
    seo_render_custom_head($site_details);
}

/**
 * Injects analytics and pixel scripts into <head>
 */
function seo_render_analytics($site_details) {
    // 1. Google Analytics (GA4)
    if (!empty($site_details['ga_tracking_id'])) {
        $ga_id = htmlspecialchars($site_details['ga_tracking_id']);
        echo '    <!-- Google Analytics (gtag.js) -->' . "\n";
        echo '    <script async src="https://www.googletagmanager.com/gtag/js?id=' . $ga_id . '"></script>' . "\n";
        echo '    <script>' . "\n";
        echo '      window.dataLayer = window.dataLayer || [];' . "\n";
        echo '      function gtag(){dataLayer.push(arguments);}' . "\n";
        echo '      gtag("js", new Date());' . "\n";
        echo '      gtag("config", "' . $ga_id . '");' . "\n";
        echo '    </script>' . "\n";
    }
    
    // 2. Google Tag Manager
    if (!empty($site_details['gtm_id'])) {
        $gtm_id = htmlspecialchars($site_details['gtm_id']);
        echo '    <!-- Google Tag Manager -->' . "\n";
        echo '    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":' . "\n";
        echo '    new Date().getTime(),event:"gtm.js"});var f=d.getElementsByTagName(s)[0],' . "\n";
        echo '    j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";j.async=true;j.src=' . "\n";
        echo '    "https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);' . "\n";
        echo '    })(window,document,"script","dataLayer","' . $gtm_id . '");</script>' . "\n";
    }
    
    // 3. Facebook/Meta Pixel
    if (!empty($site_details['fb_pixel_id'])) {
        $pixel_id = htmlspecialchars($site_details['fb_pixel_id']);
        echo '    <!-- Meta Pixel Code -->' . "\n";
        echo '    <script>' . "\n";
        echo '    !function(f,b,e,v,n,t,s)' . "\n";
        echo '    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?' . "\n";
        echo '    n.callMethod.apply(n,arguments):n.queue.push(arguments)};' . "\n";
        echo '    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";' . "\n";
        echo '    n.queue=[];t=b.createElement(e);t.async=!0;' . "\n";
        echo '    t.src=v;s=b.getElementsByTagName(e)[0];' . "\n";
        echo '    s.parentNode.insertBefore(t,s)}(window, document,"script",' . "\n";
        echo '    "https://connect.facebook.net/en_US/fbevents.js");' . "\n";
        echo '    fbq("init", "' . $pixel_id . '");' . "\n";
        echo '    fbq("track", "PageView");' . "\n";
        echo '    </script>' . "\n";
        echo '    <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=' . $pixel_id . '&ev=PageView&noscript=1" /></noscript>' . "\n";
    }
}

/**
 * Injects custom head tracking code
 */
function seo_render_custom_head($site_details) {
    if (!empty($site_details['custom_head_code'])) {
        echo "\n" . '    <!-- Vendor Custom Head Code -->' . "\n";
        echo $site_details['custom_head_code'] . "\n";
    }
}

/**
 * Injects GTM noscript and custom footer tracking code
 */
function seo_render_custom_footer($site_details) {
    // Google Tag Manager noscript fallback
    if (!empty($site_details['gtm_id'])) {
        $gtm_id = htmlspecialchars($site_details['gtm_id']);
        echo '    <!-- Google Tag Manager (noscript) -->' . "\n";
        echo '    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $gtm_id . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
    }
    
    if (!empty($site_details['custom_footer_code'])) {
        echo "\n" . '    <!-- Vendor Custom Footer Code -->' . "\n";
        echo $site_details['custom_footer_code'] . "\n";
    }
}

/**
 * Automatically parses HTML and adds descriptive SEO-friendly "alt" attributes to any <img> tags that lack them
 * 
 * @param string $html Output HTML
 * @param string $site_title The site/vendor name
 * @return string Processed HTML
 */
function seo_auto_alt_tags($html, $site_title) {
    $site_title = htmlspecialchars($site_title);
    
    // Regex callback to examine each img tag
    return preg_replace_callback('/<img\b([^>]*)>/i', function($matches) use ($site_title) {
        $attributes_string = $matches[1];
        
        // Extract src attribute to analyze filename
        $src = '';
        if (preg_match('/src=["\']([^"\']+)["\']/i', $attributes_string, $src_matches)) {
            $src = basename($src_matches[1]);
        }
        
        // Analyze src to make a smart descriptive alt tag
        $descriptive_alt = $site_title . ' image';
        if (!empty($src)) {
            $filename_no_ext = pathinfo($src, PATHINFO_FILENAME);
            // Replace hyphens/underscores with spaces
            $clean_name = str_replace(['-', '_'], ' ', $filename_no_ext);
            
            if (stripos($clean_name, 'logo') !== false) {
                $descriptive_alt = $site_title . ' Official Brand Logo';
            } elseif (stripos($clean_name, 'hero') !== false || stripos($clean_name, 'banner') !== false) {
                $descriptive_alt = $site_title . ' Payments and VTU Platform Banner';
            } elseif (stripos($clean_name, 'app') !== false || stripos($clean_name, 'mock') !== false) {
                $descriptive_alt = $site_title . ' Mobile App Mockup and Interface';
            } elseif (stripos($clean_name, 'airtime') !== false) {
                $descriptive_alt = 'Instant Airtime Recharge on ' . $site_title;
            } elseif (stripos($clean_name, 'data') !== false) {
                $descriptive_alt = 'Cheap Mobile Data Bundle Recharge on ' . $site_title;
            } elseif (stripos($clean_name, 'electric') !== false || stripos($clean_name, 'power') !== false) {
                $descriptive_alt = 'Electricity Token Bill Payment on ' . $site_title;
            } else {
                $descriptive_alt = ucwords($clean_name) . ' - ' . $site_title;
            }
        }
        
        // Check if alt attribute already exists
        if (preg_match('/alt=["\']([^"\']*)["\']/i', $attributes_string, $alt_matches)) {
            $existing_alt = trim($alt_matches[1]);
            // If the alt exists but is empty or uninformative, replace it
            if ($existing_alt === '' || strtolower($existing_alt) === 'image' || strtolower($existing_alt) === 'app screenshot' || strtolower($existing_alt) === 'app icon') {
                $attributes_string = preg_replace('/alt=["\']([^"\']*)["\']/i', 'alt="' . $descriptive_alt . '"', $attributes_string);
            }
        } else {
            // Append the new alt tag
            $attributes_string .= ' alt="' . $descriptive_alt . '"';
        }
        
        return '<img ' . trim($attributes_string) . '>';
    }, $html);
}

/**
 * Setup buffer capture to automatically inject alt tags to all images output by the page
 */
function seo_start_ob_auto_alt($site_title) {
    ob_start(function($buffer) use ($site_title) {
        return seo_auto_alt_tags($buffer, $site_title);
    });
}
