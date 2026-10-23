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
    $site_logo = "https://via.placeholder.com/150x44/2563eb/ffffff?text=" . urlencode($site_title);
}

// Header Image
if (!empty($header_image_url) && file_exists("uploaded-image/" . $header_image_url)) {
    $hero_image = "uploaded-image/" . $header_image_url;
} else {
    $hero_image = "asset/vtu-fintech.jpg";
    if (!file_exists($hero_image)) {
        $hero_image = "https://via.placeholder.com/600x400/2563eb/ffffff?text=" . urlencode($site_title);
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
    <title><?php echo htmlspecialchars($site_title); ?> — Modern Glassmorphism Digital Payments</title>
    <meta name="description" content="<?php echo htmlspecialchars($site_title); ?> is Nigeria's premier VTU portal. Frosted premium glass design for quick bills.">
    <?php if (!empty($meta_keywords)): ?>
    <meta name="keywords" content="<?php echo htmlspecialchars($meta_keywords); ?>">
    <?php endif; ?>
    
    <!-- Google Font: Outfit (Extremely modern & premium fintech font) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"></noscript>
    <link rel="icon" type="image/png" href="<?php echo $site_logo; ?>">
    
    <style>
        /* ── Glassmorphism UI Style ── */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
            color: #0f172a;
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        /* Premium Floating Shapes background */
        .glass-bg-shapes {
            position: absolute;
            top: 0; left: 0; right: 0; height: 1000px;
            overflow: hidden;
            z-index: -1;
            pointer-events: none;
        }
        .shape {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.15;
        }
        .shape-1 { top: -10%; left: 5%; width: 500px; height: 500px; background: #3b82f6; }
        .shape-2 { top: 20%; right: -5%; width: 600px; height: 600px; background: #a855f7; }
        .shape-3 { top: 50%; left: -10%; width: 400px; height: 400px; background: #06b6d4; }

        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.45);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.4);
            transition: all 0.3s;
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
        .logo-img { height: 40px; }
        .nav-links { display: flex; gap: 32px; list-style: none; }
        .nav-links a { text-decoration: none; color: #1e293b; font-weight: 500; transition: color 0.3s; }
        .nav-links a:hover { color: #2563eb; }
        .nav-cta {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white !important;
            padding: 10px 24px;
            border-radius: 50px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .nav-cta:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(37, 99, 235, 0.35); }

        /* Glass Hero Card */
        .hero { padding: 120px 0 80px; position: relative; }
        .hero-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
            align-items: center;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.45);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.05);
        }
        .hero h1 { font-size: 48px; font-weight: 800; line-height: 1.1; margin-bottom: 20px; }
        .hero p { font-size: 18px; color: #475569; margin-bottom: 30px; }
        
        .btn-group { display: flex; gap: 16px; }
        .btn {
            padding: 14px 32px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.45);
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.6);
            color: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.8);
        }
        .btn-secondary:hover { background: rgba(255, 255, 255, 0.9); transform: translateY(-2px); }

        .hero-img-container {
            position: relative;
            border-radius: 24px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.4);
            box-shadow: 0 20px 40px rgba(0,0,0,0.06);
        }
        .hero-img-container img { width: 100%; display: block; }

        /* Features Section */
        .features { padding: 80px 0; }
        .section-header { text-align: center; max-width: 600px; margin: 0 auto 50px; }
        .section-header h2 { font-size: 36px; font-weight: 800; margin-bottom: 12px; }
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
        }
        .feature-card {
            background: rgba(255, 255, 255, 0.4);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 20px;
            padding: 30px;
            transition: all 0.3s;
            text-align: center;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.6);
            box-shadow: 0 15px 30px rgba(0,0,0,0.05);
        }
        .feature-icon {
            width: 60px; height: 60px;
            border-radius: 16px;
            background: rgba(37, 99, 235, 0.1);
            color: #2563eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 20px;
        }

        /* Footer styling */
        footer {
            background: rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-top: 1px solid rgba(255, 255, 255, 0.4);
            padding: 60px 0 30px;
            margin-top: 80px;
        }
        .footer-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 40px;
            margin-bottom: 40px;
        }
        .footer-logo { font-size: 24px; font-weight: 800; color: #2563eb; margin-bottom: 16px; }
        .footer-desc { color: #475569; font-size: 14px; }
        .footer-col h4 { font-size: 16px; font-weight: 700; margin-bottom: 20px; color: #0f172a; }
        .footer-col ul { list-style: none; }
        .footer-col ul li { margin-bottom: 12px; }
        .footer-col ul li a { text-decoration: none; color: #475569; font-size: 14px; transition: color 0.3s; }
        .footer-col ul li a:hover { color: #2563eb; }

        .footer-bottom {
            border-top: 1px solid rgba(0,0,0,0.05);
            padding-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            color: #64748b;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-grid { grid-template-columns: 1fr; }
            .footer-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
    <?php echo $custom_header; ?>
</head>
<body>
    <div class="glass-bg-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>

    <header>
        <div class="container nav-wrapper">
            <div class="logo">
                <a href="/"><img src="<?php echo $site_logo; ?>" class="logo-img" alt="Logo"></a>
            </div>
            <ul class="nav-links">
                <li><a href="/">Home</a></li>
                <li><a href="/web/Pricing.php">Pricing</a></li>
                <li><a href="/web/APIDocs.php">Developer API</a></li>
                <li><a href="/web/Login.php" class="nav-cta">Login</a></li>
            </ul>
        </div>
    </header>

    <main class="hero">
        <div class="container hero-grid">
            <div class="glass-card">
                <h1>Simplifying Digital Payments & Services</h1>
                <p>Enjoy premium glassmorphism speed for purchasing airtime, data bundles, cable TV subscriptions, electricity tokens, betting funding, and more instantly.</p>
                <div class="btn-group">
                    <a href="/web/Register.php" class="btn btn-primary">Get Started <i class="fas fa-arrow-right"></i></a>
                    <?php if(!empty($apk_download_url)): ?>
                    <a href="<?php echo htmlspecialchars($apk_download_url); ?>" class="btn btn-secondary"><i class="fab fa-android"></i> Download App</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-img-container">
                <img src="<?php echo htmlspecialchars($hero_image); ?>" alt="Vtu banner">
            </div>
        </div>
    </main>

    <section class="features">
        <div class="container">
            <div class="section-header">
                <h2>Our Premium Services</h2>
                <p>Everything you need to automate your bill payments with state-of-the-art visual excellence.</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-wifi"></i></div>
                    <h3>Buy Data Bundle</h3>
                    <p>Get high speed internet connection instantly with cheap rates.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-phone-alt"></i></div>
                    <h3>Airtime VTU</h3>
                    <p>Recharge airtime on MTN, Airtel, Glo, 9mobile easily.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-tv"></i></div>
                    <h3>Cable Subscriptions</h3>
                    <p>Never miss your favorite TV shows. Renew instantly.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-bolt"></i></div>
                    <h3>Electricity Tokens</h3>
                    <p>Avoid darkness. Buy prepay electricity tokens anywhere 24/7.</p>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <div class="container footer-grid">
            <div class="footer-col">
                <div class="footer-logo"><?php echo htmlspecialchars($site_title); ?></div>
                <p class="footer-desc">Premium payment portal providing lightning-fast automated delivery of key daily digital services.</p>
            </div>
            <div class="footer-col">
                <h4>Core Products</h4>
                <ul>
                    <li><a href="/web/Register.php">Create Account</a></li>
                    <li><a href="/web/Login.php">Access Portal</a></li>
                    <li><a href="/web/Pricing.php">VTU Pricing & Rates</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Developers</h4>
                <ul>
                    <li><a href="/web/APIDocs.php">API Documentation</a></li>
                    <li><a href="/web/APIDocs.php">Webhook Setup</a></li>
                </ul>
            </div>
        </div>
        <div class="container footer-bottom">
            <div>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_title); ?>. All rights reserved.</div>
            <div>Designed with Glassmorphism Excellence.</div>
        </div>
    </footer>

    <?php echo $custom_footer; ?>
</body>
</html>
<?php
ob_end_flush();
?>
