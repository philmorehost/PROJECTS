<?php
// Initialize variables to be used in the template
$site_title       = 'FintechFlow';
$site_logo        = '';
$header_image_url = '';
$apk_download_url = '';
$meta_keywords    = '';
$custom_header    = '';
$custom_footer    = '';

// Check if vendor details are available from index.php
if (isset($vendor_account_details) && is_array($vendor_account_details)) {

    // Fetch Site Details and optional header image in one query for faster page load
    if (isset($connection_server)) {
        $cacheKey = 'vendor_site_style_' . md5((int)$vendor_account_details['id']);
        $cachedStyle = function_exists('bc_cache_get') ? bc_cache_get($cacheKey, 300) : null;

        if (is_array($cachedStyle)) {
            $site_title       = $cachedStyle['site_title'] ?? $site_title;
            $apk_download_url = $cachedStyle['apk_download_url'] ?? $apk_download_url;
            $header_image_url = $cachedStyle['header_image'] ?? $header_image_url;
            $meta_keywords    = $cachedStyle['meta_keywords'] ?? '';
            $custom_header    = $cachedStyle['custom_header_code'] ?? '';
            $custom_footer    = $cachedStyle['custom_footer_code'] ?? '';
        } else {
            $stmt_site = mysqli_prepare($connection_server, "SELECT sd.site_title, sd.apk_download_url, sd.meta_keywords, sd.custom_head_code, sd.custom_footer_code, vst.header_image FROM sas_site_details sd LEFT JOIN sas_vendor_style_templates vst ON vst.vendor_id = sd.vendor_id WHERE sd.vendor_id = ? LIMIT 1");
            if ($stmt_site) {
                mysqli_stmt_bind_param($stmt_site, "i", $vendor_account_details["id"]);
                mysqli_stmt_execute($stmt_site);
                $result_site = mysqli_stmt_get_result($stmt_site);
                if ($site_row = mysqli_fetch_assoc($result_site)) {
                    $site_title       = $site_row['site_title'] ?: $site_title;
                    $apk_download_url = $site_row['apk_download_url'] ?: $apk_download_url;
                    $header_image_url = $site_row['header_image'] ?: $header_image_url;
                    $meta_keywords    = $site_row['meta_keywords'] ?: '';
                    $custom_header    = $site_row['custom_head_code'] ?: '';
                    $custom_footer    = $site_row['custom_footer_code'] ?: '';

                    if (function_exists('bc_cache_set')) {
                        bc_cache_set($cacheKey, [
                            'site_title' => $site_title,
                            'apk_download_url' => $apk_download_url,
                            'header_image' => $header_image_url,
                            'meta_keywords' => $meta_keywords,
                            'custom_header_code' => $custom_header,
                            'custom_footer_code' => $custom_footer,
                        ]);
                    }
                }
                mysqli_stmt_close($stmt_site);
            }
        }
    }
}

// Include the SEO Alt Engine
@include_once __DIR__ . '/func/seo-alt-engine.php';
if (function_exists('seo_auto_alt')) {
    ob_start(function($buffer) use ($site_title) {
        return seo_auto_alt($buffer, $site_title);
    });
} else {
    ob_start();
}

// Logo path
$logo_filename = str_replace([".", ":"], "-", $_SERVER["HTTP_HOST"]) . "_logo.png";
if (file_exists("uploaded-image/" . $logo_filename)) {
    $site_logo = "uploaded-image/" . $logo_filename;
} else {
    $site_logo = "https://via.placeholder.com/150x44/ffffff/0f172a?text=" . urlencode($site_title);
}

// Header Image
if (!empty($header_image_url) && file_exists("uploaded-image/" . $header_image_url)) {
    $hero_image = "uploaded-image/" . $header_image_url;
} else {
    $hero_image = "asset/vtu-fintech.jpg";
    if (!file_exists($hero_image)) {
        $hero_image = "https://via.placeholder.com/600x400/f8fafc/0f172a?text=" . urlencode($site_title);
    }
}

$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$share_text = urlencode("Check out " . $site_title . " for Digital Payments Made Simple!");
$facebook_share = "https://www.facebook.com/sharer/sharer.php?u=" . urlencode($current_url);
$twitter_share = "https://twitter.com/intent/tweet?url=" . urlencode($current_url) . "&text=" . $share_text;
$linkedin_share = "https://www.linkedin.com/shareArticle?mini=true&url=" . urlencode($current_url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_title); ?> — Minimalist Clean Digital Payments</title>
    <meta name="description" content="<?php echo htmlspecialchars($site_title); ?> is Nigeria's clean, lightning-fast VTU web platform. Spacious minimalist layout.">
    <?php if (!empty($meta_keywords)): ?>
    <meta name="keywords" content="<?php echo htmlspecialchars($meta_keywords); ?>">
    <?php endif; ?>
    
    <!-- Google Font: Inter (The absolute gold standard for clean SaaS design) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"></noscript>
    <link rel="icon" type="image/png" href="<?php echo $site_logo; ?>">
    
    <style>
        /* ── Minimalist SaaS Style ── */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', sans-serif;
            background: #ffffff;
            color: #0f172a;
            line-height: 1.6;
            overflow-x: hidden;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 24px;
        }
        .nav-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 72px;
        }
        .logo-img { height: 32px; }
        .nav-links { display: flex; gap: 40px; list-style: none; }
        .nav-links a { text-decoration: none; color: #475569; font-weight: 500; font-size: 15px; transition: color 0.2s; }
        .nav-links a:hover { color: #000000; }
        .nav-cta {
            background: #0f172a;
            color: #ffffff !important;
            padding: 8px 20px;
            border-radius: 6px;
            font-weight: 600;
            transition: background 0.2s;
        }
        .nav-cta:hover { background: #1e293b; }

        /* Hero */
        .hero { padding: 100px 0 70px; background: #fafafa; border-bottom: 1px solid #f1f5f9; }
        .hero-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 60px;
            align-items: center;
        }
        .hero h1 { font-size: 44px; font-weight: 800; line-height: 1.15; margin-bottom: 20px; color: #0f172a; letter-spacing: -1px; }
        .hero p { font-size: 17px; color: #475569; margin-bottom: 30px; font-weight: 400; }
        
        .btn-group { display: flex; gap: 12px; }
        .btn {
            padding: 12px 28px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary { background: #0f172a; color: #ffffff; }
        .btn-primary:hover { background: #1e293b; transform: translateY(-1px); }
        .btn-secondary { background: #ffffff; color: #475569; border: 1px solid #e2e8f0; }
        .btn-secondary:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-1px); }

        .hero-img-container {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            background: #ffffff;
            padding: 6px;
        }
        .hero-img-container img { width: 100%; display: block; border-radius: 4px; }

        /* Features Section */
        .features { padding: 80px 0; }
        .section-header { text-align: center; max-width: 550px; margin: 0 auto 60px; }
        .section-header h2 { font-size: 32px; font-weight: 800; margin-bottom: 12px; letter-spacing: -0.5px; }
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 32px;
        }
        .feature-card {
            border: 1px solid #f1f5f9;
            border-radius: 8px;
            padding: 30px;
            transition: border-color 0.2s;
        }
        .feature-card:hover { border-color: #cbd5e1; }
        .feature-icon {
            font-size: 24px;
            color: #0f172a;
            margin-bottom: 16px;
        }
        .feature-card h3 { font-size: 18px; font-weight: 700; margin-bottom: 10px; color: #0f172a; }
        .feature-card p { font-size: 14px; color: #64748b; }

        /* Footer */
        footer {
            border-top: 1px solid #f1f5f9;
            padding: 60px 0 30px;
            background: #ffffff;
            margin-top: 80px;
        }
        .footer-grid {
            display: grid;
            grid-template-columns: 1.5fr repeat(3, 1fr);
            gap: 40px;
            margin-bottom: 40px;
        }
        .footer-logo { font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 16px; letter-spacing: -0.5px; }
        .footer-desc { color: #64748b; font-size: 14px; line-height: 1.5; }
        .footer-col h4 { font-size: 14px; font-weight: 700; margin-bottom: 16px; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; }
        .footer-col ul { list-style: none; }
        .footer-col ul li { margin-bottom: 10px; }
        .footer-col ul li a { text-decoration: none; color: #64748b; font-size: 14px; transition: color 0.2s; }
        .footer-col ul li a:hover { color: #0f172a; }

        .footer-bottom {
            border-top: 1px solid #f1f5f9;
            padding-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: #94a3b8;
        }

        @media (max-width: 768px) {
            .hero-grid { grid-template-columns: 1fr; gap: 40px; }
            .footer-grid { grid-template-columns: 1fr; gap: 30px; }
        }
    </style>
    <?php echo $custom_header; ?>
</head>
<body>

    <header>
        <div class="container nav-wrapper">
            <div class="logo">
                <a href="/"><img src="<?php echo $site_logo; ?>" class="logo-img" alt="Logo"></a>
            </div>
            <ul class="nav-links">
                <li><a href="/">Home</a></li>
                <li><a href="/web/Pricing.php">Pricing</a></li>
                <li><a href="/web/APIDocs.php">Developers</a></li>
                <li><a href="/web/Login.php" class="nav-cta">Log In</a></li>
            </ul>
        </div>
    </header>

    <main class="hero">
        <div class="container hero-grid">
            <div>
                <h1>A cleaner way to handle everyday utility bills.</h1>
                <p>Buy discounted data plans, recharge airtime, clear electricity tokens, and manage cable subscriptions on an incredibly simple, robust, high-performance platform.</p>
                <div class="btn-group">
                    <a href="/web/Register.php" class="btn btn-primary">Start Free <i class="fas fa-arrow-right"></i></a>
                    <?php if(!empty($apk_download_url)): ?>
                    <a href="<?php echo htmlspecialchars($apk_download_url); ?>" class="btn btn-secondary"><i class="fab fa-android"></i> Android Client</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-img-container">
                <img src="<?php echo htmlspecialchars($hero_image); ?>" alt="Vtu clean layout">
            </div>
        </div>
    </main>

    <section class="features">
        <div class="container">
            <div class="section-header">
                <h2>Designed for simple utility management</h2>
                <p>Skip the complex workflows. Zero clutter. Pure automation designed for modern merchants.</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-database"></i></div>
                    <h3>Fast Data Bundles</h3>
                    <p>Direct gigabyte data pipelines with real-time delivery status reports.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-mobile-alt"></i></div>
                    <h3>Simple VTU Airtime</h3>
                    <p>Recharge airtime on all networks with a clean, instant UI.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-tv"></i></div>
                    <h3>Cable Subscriptions</h3>
                    <p>Instantly renew Gotv, Dstv, Startimes plans with zero hassle.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-plug"></i></div>
                    <h3>Electricity Tokens</h3>
                    <p>Prepaid tokens delivered via SMS and email with absolute speed.</p>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <div class="container footer-grid">
            <div class="footer-col">
                <div class="footer-logo"><?php echo htmlspecialchars($site_title); ?></div>
                <p class="footer-desc">High speed minimalist utility platform. Automated secure Nigerian VTU service provider.</p>
            </div>
            <div class="footer-col">
                <h4>Portal</h4>
                <ul>
                    <li><a href="/web/Register.php">Create Account</a></li>
                    <li><a href="/web/Login.php">Login Terminal</a></li>
                    <li><a href="/web/Pricing.php">Services & Pricing</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>API</h4>
                <ul>
                    <li><a href="/web/APIDocs.php">Documentation</a></li>
                    <li><a href="/web/APIDocs.php">Webhooks Integration</a></li>
                </ul>
            </div>
        </div>
        <div class="container footer-bottom">
            <div>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_title); ?>. Clean SaaS setup.</div>
            <div>Minimalist payment portal style.</div>
        </div>
    </footer>

    <?php echo $custom_footer; ?>
</body>
</html>
<?php
ob_end_flush();
?>
