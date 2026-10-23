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
    $site_logo = "https://via.placeholder.com/150x44/312e81/ffffff?text=" . urlencode($site_title);
}

// Header Image
if (!empty($header_image_url) && file_exists("uploaded-image/" . $header_image_url)) {
    $hero_image = "uploaded-image/" . $header_image_url;
} else {
    $hero_image = "asset/vtu-fintech.jpg";
    if (!file_exists($hero_image)) {
        $hero_image = "https://via.placeholder.com/600x400/6366f1/ffffff?text=" . urlencode($site_title);
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
    <title><?php echo htmlspecialchars($site_title); ?> — Vibrant Gradient Flow Digital Hub</title>
    <meta name="description" content="<?php echo htmlspecialchars($site_title); ?> represents Nigeria's premier dynamic VTU system. Premium glowing flows.">
    <?php if (!empty($meta_keywords)): ?>
    <meta name="keywords" content="<?php echo htmlspecialchars($meta_keywords); ?>">
    <?php endif; ?>
    
    <!-- Google Font: Plus Jakarta Sans (Vibrant premium SaaS font) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"></noscript>
    <link rel="icon" type="image/png" href="<?php echo $site_logo; ?>">
    
    <style>
        /* ── Vibrant Gradient Flow Style ── */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #ffffff;
            color: #1e1b4b;
            line-height: 1.6;
            overflow-x: hidden;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(99, 102, 241, 0.1);
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
        }
        .nav-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 80px;
        }
        .logo-img { height: 38px; }
        .nav-links { display: flex; gap: 32px; list-style: none; }
        .nav-links a { text-decoration: none; color: #4338ca; font-weight: 600; font-size: 15px; transition: all 0.2s; }
        .nav-links a:hover { color: #818cf8; }
        .nav-cta {
            background: linear-gradient(135deg, #4f46e5 0%, #312e81 100%);
            color: white !important;
            padding: 10px 26px;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.25);
            transition: all 0.2s;
        }
        .nav-cta:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4); }

        /* Hero */
        .hero {
            padding: 140px 0 100px;
            position: relative;
            background: radial-gradient(circle at 80% 20%, rgba(99, 102, 241, 0.15) 0%, rgba(255, 255, 255, 0) 50%);
        }
        .hero-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 48px;
            align-items: center;
        }
        .hero h1 {
            font-size: 52px;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #312e81 0%, #4f46e5 50%, #d946ef 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -1.5px;
        }
        .hero p { font-size: 19px; color: #4b5563; margin-bottom: 36px; }
        
        .btn-group { display: flex; gap: 16px; }
        .btn {
            padding: 14px 32px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #d946ef 100%);
            color: white;
            box-shadow: 0 4px 20px rgba(79, 70, 229, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 26px rgba(79, 70, 229, 0.5);
        }
        .btn-secondary {
            background: #f5f3ff;
            color: #4f46e5;
            border: 1px solid rgba(79, 70, 229, 0.15);
        }
        .btn-secondary:hover { background: #ede9fe; transform: translateY(-2px); }

        .hero-img-container {
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(79, 70, 229, 0.15);
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.1) 0%, rgba(217, 70, 239, 0.1) 100%);
            padding: 10px;
        }
        .hero-img-container img { width: 100%; display: block; border-radius: 20px; }

        /* Features Section */
        .features { padding: 90px 0; background: #fbfbfe; }
        .section-header { text-align: center; max-width: 600px; margin: 0 auto 60px; }
        .section-header h2 { font-size: 38px; font-weight: 800; margin-bottom: 12px; color: #1e1b4b; }
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 32px;
        }
        .feature-card {
            background: white;
            border: 1px solid rgba(99, 102, 241, 0.08);
            border-radius: 24px;
            padding: 40px 30px;
            transition: all 0.3s;
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.02);
        }
        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(99, 102, 241, 0.08);
            border-color: rgba(99, 102, 241, 0.2);
        }
        .feature-icon {
            width: 54px; height: 54px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.1) 0%, rgba(217, 70, 239, 0.1) 100%);
            color: #4f46e5;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 24px;
        }

        /* Footer */
        footer {
            background: #0f1026;
            color: #e0e0ff;
            padding: 80px 0 40px;
            margin-top: 80px;
        }
        .footer-grid {
            display: grid;
            grid-template-columns: 1.5fr repeat(3, 1fr);
            gap: 40px;
            margin-bottom: 50px;
        }
        .footer-logo { font-size: 26px; font-weight: 800; color: #ffffff; margin-bottom: 16px; background: linear-gradient(135deg, #ffffff 0%, #818cf8 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .footer-desc { color: #a5a6c5; font-size: 15px; }
        .footer-col h4 { font-size: 15px; font-weight: 700; margin-bottom: 20px; color: #818cf8; text-transform: uppercase; letter-spacing: 1px; }
        .footer-col ul { list-style: none; }
        .footer-col ul li { margin-bottom: 12px; }
        .footer-col ul li a { text-decoration: none; color: #a5a6c5; font-size: 14px; transition: color 0.2s; }
        .footer-col ul li a:hover { color: #ffffff; }

        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,0.06);
            padding-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            color: #797b9e;
        }

        @media (max-width: 768px) {
            .hero-grid { grid-template-columns: 1fr; }
            .footer-grid { grid-template-columns: 1fr; }
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
                <li><a href="/web/APIDocs.php">API Hub</a></li>
                <li><a href="/web/Login.php" class="nav-cta">Get Started</a></li>
            </ul>
        </div>
    </header>

    <main class="hero">
        <div class="container hero-grid">
            <div>
                <h1>Supercharge your digital bills automation flow.</h1>
                <p>Welcome to the ultimate payment gateway. Recharge airtime, buy high speed data plans, activate decoders, print tokens instantly with premium dynamic gradient excellence.</p>
                <div class="btn-group">
                    <a href="/web/Register.php" class="btn btn-primary">Join Today <i class="fas fa-magic"></i></a>
                    <?php if(!empty($apk_download_url)): ?>
                    <a href="<?php echo htmlspecialchars($apk_download_url); ?>" class="btn btn-secondary"><i class="fab fa-android"></i> App Client</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-img-container">
                <img src="<?php echo htmlspecialchars($hero_image); ?>" alt="Vtu gradient theme">
            </div>
        </div>
    </main>

    <section class="features">
        <div class="container">
            <div class="section-header">
                <h2>Designed to feel extremely fast</h2>
                <p>Highly optimized transaction channels responding with absolute speed and gorgeous color flow.</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-bolt"></i></div>
                    <h3>Instant Data</h3>
                    <p>Experience extreme data delivery channels to all major Nigerian networks.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-mobile"></i></div>
                    <h3>Vibrant VTU</h3>
                    <p>Recharge airtime with instant cashback bonuses and high commissions.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-play"></i></div>
                    <h3>Cable Television</h3>
                    <p>Gotv, Dstv, and Startimes activation channels operating 24/7/365.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-plug"></i></div>
                    <h3>Power Tokens</h3>
                    <p>Recharge prepaid meters instantly. Zero processing fee overhead.</p>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <div class="container footer-grid">
            <div class="footer-col">
                <div class="footer-logo"><?php echo htmlspecialchars($site_title); ?></div>
                <p class="footer-desc">Premium payment portal providing dynamic automated delivery of daily digital utility services.</p>
            </div>
            <div class="footer-col">
                <h4>Products</h4>
                <ul>
                    <li><a href="/web/Register.php">Register Free</a></li>
                    <li><a href="/web/Login.php">Client Dashboard</a></li>
                    <li><a href="/web/Pricing.php">Rates & Pricing</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Developers</h4>
                <ul>
                    <li><a href="/web/APIDocs.php">APIs Reference</a></li>
                    <li><a href="/web/APIDocs.php">Integration Guide</a></li>
                </ul>
            </div>
        </div>
        <div class="container footer-bottom">
            <div>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_title); ?>. All rights reserved.</div>
            <div>Powered by Dynamic Gradient Flow.</div>
        </div>
    </footer>

    <?php echo $custom_footer; ?>
</body>
</html>
<?php
ob_end_flush();
?>
