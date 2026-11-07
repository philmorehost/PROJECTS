<?php
// Initialize SEO engine and variables
include_once(__DIR__ . "/func/bc-seo.php");

$site_title       = 'FintechFlow';
$site_logo        = '';
$header_image_url = '';
$apk_download_url = '';
$site_details     = [];

// Check if vendor details are available from index.php
if (isset($vendor_account_details) && is_array($vendor_account_details)) {
    // Fetch Site Details
    if (isset($connection_server)) {
        $cacheKey = 'vendor_site_style_' . md5((int)$vendor_account_details['id']);
        $cachedStyle = function_exists('bc_cache_get') ? bc_cache_get($cacheKey, 300) : null;

        if (is_array($cachedStyle)) {
            $site_details     = $cachedStyle;
            $site_title       = $cachedStyle['site_title'] ?? $site_title;
            $apk_download_url = $cachedStyle['apk_download_url'] ?? $apk_download_url;
            $header_image_url = $cachedStyle['header_image'] ?? $header_image_url;
        } else {
            $template_name_pattern = '%template-2%';
            $stmt_site = mysqli_prepare($connection_server, "SELECT sd.*, vst.header_image FROM sas_site_details sd LEFT JOIN sas_vendor_style_templates vst ON vst.vendor_id = sd.vendor_id AND vst.template_name LIKE ? WHERE sd.vendor_id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt_site, "si", $template_name_pattern, $vendor_account_details["id"]);
            mysqli_stmt_execute($stmt_site);
            $result_site = mysqli_stmt_get_result($stmt_site);
            if ($site_row = mysqli_fetch_assoc($result_site)) {
                $site_details     = $site_row;
                $site_title       = $site_row['site_title'] ?: $site_title;
                $apk_download_url = $site_row['apk_download_url'] ?: $apk_download_url;
                $header_image_url = $site_row['header_image'] ?: $header_image_url;

                if (function_exists('bc_cache_set')) {
                    bc_cache_set($cacheKey, $site_row);
                }
            }
            mysqli_stmt_close($stmt_site);
        }
    }
}

// Start Output Buffer for dynamic Alt Tags insertion
seo_start_ob_auto_alt($site_title);

// Logo path
$logo_filename = str_replace([".", ":"], "-", $_SERVER["HTTP_HOST"]) . "_logo.png";
if (file_exists("uploaded-image/" . $logo_filename)) {
    $site_logo = "uploaded-image/" . $logo_filename;
} else {
    // Default logo or placeholder
    $site_logo = "https://via.placeholder.com/150x44/6366f1/ffffff?text=" . urlencode($site_title);
}

// Header Image
if (!empty($header_image_url) && file_exists("uploaded-image/" . $header_image_url)) {
    $hero_image = "uploaded-image/" . $header_image_url;
} else {
    $hero_image = "asset/vtu-fintech.jpg"; // Use an existing asset
    if (!file_exists($hero_image)) {
        $hero_image = "https://via.placeholder.com/600x400/6366f1/ffffff?text=" . urlencode($site_title);
    }
}

// Social Share URLs
$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$share_text = urlencode("Check out " . $site_title . " for Premium Digital Payments!");
$facebook_share = "https://www.facebook.com/sharer/sharer.php?u=" . urlencode($current_url);
$twitter_share = "https://twitter.com/intent/tweet?url=" . urlencode($current_url) . "&text=" . $share_text;
$linkedin_share = "https://www.linkedin.com/shareArticle?mini=true&url=" . urlencode($current_url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seo_render_head_tags($site_details, $vendor_account_details); ?>
    
    <!-- Premium Fonts & CSS Preconnects -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Deferred FontAwesome for PageSpeed Optimization -->
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    
    <style>
        /* ── Premium Modern Design System ────────────────────────────── */
        :root {
            --primary: #6366f1;
            --primary-rgb: 99, 102, 241;
            --secondary: #7c3aed;
            --success: #10b981;
            --dark: #0f172a;
            --light: #f8fafc;
            --gray: #64748b;
            --glass-bg: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.5);
            --shadow: 0 20px 40px -15px rgba(99, 102, 241, 0.12);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Outfit', sans-serif;
            background: #fafafa;
            color: var(--dark);
            line-height: 1.6;
            overflow-x: hidden;
        }

        .container {
            width: 100%;
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* ── Header Glassmorphism Navigation ────────────────────────────── */
        #siteHeader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
            background: rgba(250, 250, 250, 0.8);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--glass-border);
            transition: all 0.3s ease;
        }
        #siteHeader.scrolled {
            box-shadow: var(--shadow);
            border-bottom-color: rgba(0,0,0,0.05);
        }
        .header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 0;
        }
        .logo img {
            height: 38px;
            width: auto;
            display: block;
        }
        nav {
            display: flex;
            align-items: center;
            gap: 32px;
        }
        nav a {
            font-weight: 600;
            color: var(--dark);
            text-decoration: none;
            font-size: 0.95rem;
            transition: color 0.2s;
            cursor: pointer;
        }
        nav a:hover { color: var(--primary); }
        
        .nav-cta {
            background: linear-gradient(135deg, var(--primary), var(--secondary)) !important;
            color: white !important;
            padding: 12px 28px;
            border-radius: 50px;
            font-weight: 700 !important;
            box-shadow: 0 10px 20px -8px rgba(99, 102, 241, 0.4);
            transition: all 0.3s ease !important;
        }
        .nav-cta:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 15px 25px -5px rgba(99, 102, 241, 0.5); 
        }

        .nav-toggle {
            display: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--primary);
        }

        /* ── Hero Aurora Section ────────────────────────────────────────── */
        .hero-wrap {
            position: relative;
            padding: 180px 0 120px;
            overflow: hidden;
            background: radial-gradient(circle at 10% 20%, rgba(99, 102, 241, 0.05) 0%, transparent 40%),
                        radial-gradient(circle at 90% 80%, rgba(124, 58, 237, 0.06) 0%, transparent 45%);
        }
        
        .hero-aurora-1 {
            position: absolute;
            width: 600px; height: 600px;
            background: linear-gradient(45deg, #a5b4fc, #c084fc);
            border-radius: 50%;
            filter: blur(140px);
            opacity: 0.3;
            top: -200px; left: -100px;
            animation: aurora-float 12s infinite alternate ease-in-out;
            pointer-events: none;
        }
        .hero-aurora-2 {
            position: absolute;
            width: 500px; height: 500px;
            background: linear-gradient(45deg, #818cf8, #f472b6);
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.25;
            bottom: -150px; right: -50px;
            animation: aurora-float 9s infinite alternate-reverse ease-in-out;
            pointer-events: none;
        }
        @keyframes aurora-float {
            0% { transform: translate(0, 0) scale(1) rotate(0deg); }
            100% { transform: translate(60px, 40px) scale(1.15) rotate(36deg); }
        }

        .hero {
            display: flex;
            align-items: center;
            gap: 70px;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }
        .hero-content {
            flex: 1.2;
            min-width: 320px;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(99, 102, 241, 0.08);
            color: var(--primary);
            padding: 8px 18px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 24px;
            border: 1px solid rgba(99, 102, 241, 0.15);
        }
        .hero-badge .dot {
            width: 8px; height: 8px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.4); opacity: 0.5; }
        }

        .hero h1 {
            font-size: clamp(2.6rem, 5.5vw, 4.2rem);
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 24px;
            letter-spacing: -0.02em;
            background: linear-gradient(135deg, var(--dark) 30%, var(--primary) 70%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .hero p {
            font-size: 1.18rem;
            color: var(--gray);
            margin-bottom: 38px;
            max-width: 540px;
        }
        .hero-buttons {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 16px 36px;
            border-radius: 50px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            font-size: 1rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 12px 24px -10px rgba(99, 102, 241, 0.4);
        }
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 35px -8px rgba(99, 102, 241, 0.5);
        }
        .btn-secondary {
            background: white;
            color: var(--dark);
            border: 1px solid rgba(0, 0, 0, 0.08);
            box-shadow: 0 10px 20px -8px rgba(0,0,0,0.04);
        }
        .btn-secondary:hover {
            background: #fdfdfd;
            border-color: rgba(0, 0, 0, 0.15);
            transform: translateY(-2px);
        }

        .hero-image {
            flex: 0.8;
            min-width: 300px;
            display: flex;
            justify-content: center;
            position: relative;
        }
        .hero-image img {
            max-width: 100%;
            height: auto;
            border-radius: 36px;
            box-shadow: 0 35px 70px -15px rgba(0, 0, 0, 0.15);
            border: 4px solid white;
        }

        /* ── Service Responsive Grid ──────────────────────────────────────── */
        .section-wrap { padding: 100px 0; }
        .section-header { text-align: center; margin-bottom: 60px; }
        .section-eyebrow {
            display: inline-block;
            font-size: 0.82rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--primary);
            background: rgba(99, 102, 241, 0.08);
            padding: 6px 16px;
            border-radius: 50px;
            margin-bottom: 16px;
        }
        .section-header h2 { font-size: clamp(2rem, 3.5vw, 2.8rem); font-weight: 800; color: var(--dark); margin-bottom: 16px; letter-spacing: -0.01em; }
        .section-header p { color: var(--gray); font-size: 1.08rem; max-width: 600px; margin: 0 auto; }

        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 24px;
        }
        .service-card {
            background: white;
            padding: 36px 24px;
            border-radius: 28px;
            text-align: center;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 10px 30px -15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .service-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 30px 50px -20px rgba(99, 102, 241, 0.2);
            border-color: rgba(99, 102, 241, 0.15);
        }
        .service-icon-wrap {
            width: 70px; height: 70px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(124, 58, 237, 0.08));
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.85rem; color: var(--primary);
        }
        .service-card h3 { font-size: 1.15rem; font-weight: 700; color: var(--dark); margin-bottom: 8px; }
        .service-card p { color: var(--gray); font-size: 0.88rem; }

        /* ── Dynamic Stats Bar ──────────────────────────────────────────── */
        .stats-bar {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            padding: 48px 0;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px -10px rgba(99, 102, 241, 0.3);
        }
        .stats-bar::before {
            content: '';
            position: absolute;
            width: 300px; height: 300px;
            background: rgba(255,255,255,0.03);
            border-radius: 50%;
            top: -150px; right: -50px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 32px;
            text-align: center;
        }
        .stat-item { color: white; position: relative; z-index: 2; }
        .stat-number { font-size: 2.6rem; font-weight: 900; display: block; line-height: 1; margin-bottom: 8px; letter-spacing: -0.01em; }
        .stat-label { font-size: 0.95rem; opacity: 0.85; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }

        /* ── Premium Accordion ── */
        .accordion-block {
            background: white;
            border-radius: 36px;
            padding: 40px;
            box-shadow: 0 15px 40px -20px rgba(0,0,0,0.08);
            border: 1px solid rgba(0,0,0,0.02);
            max-width: 900px;
            margin: 0 auto;
        }
        .accordion-item {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        .accordion-item:last-child { border-bottom: none; }
        .accordion-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px 0;
            font-weight: 700;
            font-size: 1.18rem;
            cursor: pointer;
            color: var(--dark);
            transition: color 0.2s;
        }
        .accordion-header:hover { color: var(--primary); }
        .accordion-header i {
            transition: transform 0.3s;
            color: var(--primary);
        }
        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s cubic-bezier(0.16, 1, 0.3, 1), padding 0.2s;
            color: var(--gray);
            font-size: 0.98rem;
            line-height: 1.7;
        }
        .accordion-item.active .accordion-content {
            max-height: 400px;
            padding-bottom: 24px;
        }
        .accordion-item.active .accordion-header i {
            transform: rotate(45deg);
        }
        .about-text {
            column-count: 2;
            column-gap: 40px;
            line-height: 1.75;
        }
        .about-text p { margin-bottom: 16px; }


        /* ── Modal Design ── */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: white;
            max-width: 460px;
            width: 90%;
            padding: 40px;
            border-radius: 36px;
            box-shadow: 0 40px 80px -20px rgba(15, 23, 42, 0.3);
            position: relative;
            border: 1px solid rgba(0, 0, 0, 0.03);
            animation: scaleIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        @keyframes scaleIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .modal-close {
            position: absolute;
            top: 24px; right: 24px;
            font-size: 1.4rem;
            color: var(--gray);
            cursor: pointer;
            transition: 0.2s;
        }
        .modal-close:hover { color: var(--dark); }
        .modal-content h2 { font-size: 1.8rem; font-weight: 800; color: var(--dark); margin-bottom: 12px; }
        .modal-content p { color: var(--gray); margin-bottom: 28px; font-size: 0.95rem; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; color: var(--dark); margin-bottom: 8px; font-size: 0.88rem; }
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 14px 20px;
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 16px;
            font-family: inherit;
            font-size: 0.98rem;
            transition: all 0.2s;
            background: #f8fafc;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.12);
            background: white;
        }
        .modal-send {
            background: #25D366;
            color: white;
            border: none;
            padding: 16px 28px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1.05rem;
            width: 100%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
            box-shadow: 0 10px 20px -5px rgba(37, 211, 102, 0.4);
        }
        .modal-send:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px -5px rgba(37, 211, 102, 0.5);
        }

        /* ── Responsive Styling ── */
        @media (max-width: 991px) {
            nav { display: none; }
            .nav-toggle { display: block; }
            .hero { gap: 40px; text-align: center; }
            .hero-content { display: flex; flex-direction: column; align-items: center; }
            .hero p { margin: 0 auto 32px; }
            .hero-buttons { justify-content: center; }
            .about-text { column-count: 1; }
        }
    </style>
</head>
<body>

    <!-- Header Glassmorphism Navigation -->
    <header id="siteHeader">
        <div class="container">
            <div class="header-inner">
                <a href="/" class="logo">
                    <img src="<?php echo $site_logo; ?>" alt="<?php echo htmlspecialchars($site_title); ?>">
                </a>
                <nav id="siteNav">
                    <a href="#services">Services</a>
                    <a href="#about">About</a>
                    <a href="#faq">FAQ</a>
                    <a href="/web/Login.php" class="nav-cta">Get Started</a>
                </nav>
                <div class="nav-toggle" id="navToggle"><i class="fas fa-bars"></i></div>
            </div>
        </div>
    </header>

    <!-- Hero Aurora Section -->
    <section class="hero-wrap">
        <div class="hero-aurora-1"></div>
        <div class="hero-aurora-2"></div>
        <div class="container">
            <div class="hero">
                <div class="hero-content">
                    <div class="hero-badge">
                        <span class="dot"></span> 24/7 Auto Instant Delivery
                    </div>
                    <h1>Simplify Your Digital Payments</h1>
                    <p>Experience the easiest way to recharge airtime, purchase internet data, buy electricity tokens, subscribe cable TVs, and fund your wallet instantly with 100% security.</p>
                    <div class="hero-buttons">
                        <a href="/web/Register.php" class="btn btn-primary"><i class="fas fa-rocket"></i> Join Now</a>
                        <a href="/web/Login.php" class="btn btn-secondary"><i class="fas fa-sign-in-alt"></i> Login</a>
                    </div>
                </div>
                <div class="hero-image">
                    <img src="<?php echo $hero_image; ?>" alt="<?php echo htmlspecialchars($site_title); ?> Dashboard UI Mockup" width="600" height="400" loading="eager">
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Bar -->
    <section class="stats-bar">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <span class="stat-number">99.9%</span>
                    <span class="stat-label">System Uptime</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">10k+</span>
                    <span class="stat-label">Active Users</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">2M+</span>
                    <span class="stat-label">Completed Orders</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">Instant</span>
                    <span class="stat-label">Wallet Funding</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Grid -->
    <section class="section-wrap" id="services">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Our Products</span>
                <h2>Services Crafted For You</h2>
                <p>We provide instant automated delivery on all bill payment and utility services with highly discounted rates.</p>
            </div>
            <div class="service-grid">
                <?php if(isServiceEnabled('airtime')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-phone-alt"></i></div>
                    <h3>Buy Airtime</h3>
                    <p>MTN, Glo, Airtel, 9mobile & discounts</p>
                </div>
                <?php endif; ?>
                <?php if(isServiceEnabled('data')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-wifi"></i></div>
                    <h3>Buy Data</h3>
                    <p>Fast bundles, all networks</p>
                </div>
                <?php endif; ?>
                <?php if(isServiceEnabled('electric')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-bolt"></i></div>
                    <h3>Electricity</h3>
                    <p>Prepaid/postpaid token instantly</p>
                </div>
                <?php endif; ?>
                <?php if(isServiceEnabled('cable')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-tv"></i></div>
                    <h3>Cable TV</h3>
                    <p>DSTV, GOtv, StarTimes renewal</p>
                </div>
                <?php endif; ?>
                <?php if(isServiceEnabled('bank_transfer')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-university"></i></div>
                    <h3>Wallet to Bank</h3>
                    <p>Instant withdrawal to any bank</p>
                </div>
                <?php endif; ?>
                <?php if(isServiceEnabled('bulk_sms')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-comment-dots"></i></div>
                    <h3>Bulk SMS</h3>
                    <p>Customized, high delivery</p>
                </div>
                <?php endif; ?>
                <?php if(isServiceEnabled('exam')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-graduation-cap"></i></div>
                    <h3>Exam Pin</h3>
                    <p>WAEC, NECO, NABTEB e-pins</p>
                </div>
                <?php endif; ?>
                <?php if(isServiceEnabled('recharge_card')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-print"></i></div>
                    <h3>Recharge Card Printing</h3>
                    <p>Print & sell e-pins</p>
                </div>
                <?php endif; ?>
                <?php if(isServiceEnabled('data_card')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-database"></i></div>
                    <h3>Data Card Printing</h3>
                    <p>Generate data bundle pins</p>
                </div>
                <?php endif; ?>
                <?php if(isServiceEnabled('gift_card')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-gift"></i></div>
                    <h3>Gift Cards</h3>
                    <p>Trade local & international</p>
                </div>
                <?php endif; ?>
                <?php if(isServiceEnabled('virtual_card')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-credit-card"></i></div>
                    <h3>Virtual Cards</h3>
                    <p>Dollar & Naira for online payments</p>
                </div>
                <?php endif; ?>
                <?php if(isServiceEnabled('crypto_hub')): ?>
                <div class="service-card">
                    <div class="service-icon-wrap"><i class="fas fa-coins"></i></div>
                    <h3>Crypto Hub</h3>
                    <p>Send, Swap, Hold Stable Coins</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- About Section Accordion -->
    <section class="section-wrap" style="background:#f4f5f8;" id="about">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Who We Are</span>
                <h2>About Our Platform</h2>
                <p>Learn more about how we enable fast and seamless financial VTU operations across the nation.</p>
            </div>
            <div class="accordion-block">
                <div class="accordion-item active">
                    <div class="accordion-header">Our Mission & Objective <i class="fas fa-plus"></i></div>
                    <div class="accordion-content">
                        <div class="about-text">
                            <p>Our mission is to create a seamless bill payment experience for individuals and businesses alike. We bridge the gap between service providers and end consumers, ensuring fast utility transactions at discounted prices.</p>
                            <p>Through advanced automation, we ensure that every airtime recharge, data purchase, and bill payment occurs instantly without human delay.</p>
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <div class="accordion-header">Why Choose Us? <i class="fas fa-plus"></i></div>
                    <div class="accordion-content">
                        <p>We leverage cutting-edge security and robust API channels to process transactions under 3 seconds. Our platform offers multiple auto-wallet funding choices (bank transfer, cards), detailed real-time transaction tracking, and round-the-clock supportive customer service.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="section-wrap" id="faq">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Got Questions?</span>
                <h2>Frequently Asked Questions</h2>
                <p>Quick answers to some common inquiries about our payment operations.</p>
            </div>
            <div class="accordion-block" style="background:transparent; box-shadow:none; padding:0;">
                <div class="accordion-item">
                    <div class="accordion-header">Are transactions automated? <i class="fas fa-plus"></i></div>
                    <div class="accordion-content">
                        <p>Yes, all transactions are processed automatically via secure API channels and delivered in under 3 seconds.</p>
                    </div>
                </div>
                <div class="accordion-item">
                    <div class="accordion-header">How do I fund my wallet? <i class="fas fa-plus"></i></div>
                    <div class="accordion-content">
                        <p>Once registered, you get dynamic virtual bank accounts dedicated to your wallet. Funding any of these banks automatically credits your wallet instantly.</p>
                    </div>
                </div>
                <div class="accordion-item">
                    <div class="accordion-header">Is customer support available? <i class="fas fa-plus"></i></div>
                    <div class="accordion-content">
                        <p>Yes, we have 24/7 dedicated customer service. You can reach us via mail for immediate resolutions.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer style="background:var(--dark); color:white; padding:60px 0; border-top:1px solid rgba(255,255,255,0.05);">
        <div class="container" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:30px;">
            <div>
                <img src="<?php echo $site_logo; ?>" alt="<?php echo htmlspecialchars($site_title); ?>" style="height:35px; filter:brightness(0) invert(1); margin-bottom:12px;">
                <p style="opacity:0.6; font-size:0.9rem;">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_title); ?>. All Rights Reserved.</p>
            </div>
            <div style="display:flex; gap:20px;">
                <a href="/web/policy/privacy.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:0.9rem;">Privacy Policy</a>
                <a href="/web/policy/terms.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:0.9rem;">Terms of Service</a>
            </div>
        </div>
    </footer>

    <!-- Responsive Navigation JS -->
    <script>
        const navToggle = document.getElementById('navToggle');
        const siteNav = document.getElementById('siteNav');
        if (navToggle) {
            navToggle.addEventListener('click', () => {
                siteNav.style.display = siteNav.style.display === 'flex' ? 'none' : 'flex';
                if(siteNav.style.display === 'flex') {
                    siteNav.style.flexDirection = 'column';
                    siteNav.style.position = 'absolute';
                    siteNav.style.top = '100%';
                    siteNav.style.left = '0';
                    siteNav.style.width = '100%';
                    siteNav.style.background = 'white';
                    siteNav.style.padding = '20px';
                    siteNav.style.boxShadow = '0 10px 20px rgba(0,0,0,0.05)';
                }
            });
        }

        // Header scroll effect
        window.addEventListener('scroll', () => {
            const header = document.getElementById('siteHeader');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Accordion script
        document.querySelectorAll('.accordion-header').forEach(header => {
            header.addEventListener('click', () => {
                const item = header.parentElement;
                const isActive = item.classList.contains('active');
                
                // Close all items
                document.querySelectorAll('.accordion-item').forEach(el => el.classList.remove('active'));
                
                if (!isActive) {
                    item.classList.add('active');
                }
            });
        });
    </script>
    
    <?php seo_render_custom_footer($site_details); ?>
</body>
</html>
