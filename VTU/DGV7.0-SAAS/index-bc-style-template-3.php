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
            $template_name_pattern = '%template-3%';
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
    // Default logo or placeholder (light logo for dark theme)
    $site_logo = "https://via.placeholder.com/150x44/111827/00f5ff?text=" . urlencode($site_title);
}

// Header Image
if (!empty($header_image_url) && file_exists("uploaded-image/" . $header_image_url)) {
    $hero_image = "uploaded-image/" . $header_image_url;
} else {
    $hero_image = "asset/vtu-fintech.jpg"; // Use an existing asset
    if (!file_exists($hero_image)) {
        $hero_image = "https://via.placeholder.com/600x400/111827/00f5ff?text=" . urlencode($site_title);
    }
}

// Social Share URLs
$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$share_text = urlencode("Check out " . $site_title . " for automated billing services!");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seo_render_head_tags($site_details, $vendor_account_details); ?>
    
    <!-- Outfit Typography & CSS Preconnects -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Deferred FontAwesome for PageSpeed Optimization -->
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    
    <style>
        /* ── Dark Premium Cyberpunk Design System ───────────────────────── */
        :root {
            --primary: #00f5ff;
            --primary-glow: rgba(0, 245, 255, 0.15);
            --secondary: #bd00ff;
            --secondary-glow: rgba(189, 0, 255, 0.15);
            --dark: #0b0f19;
            --card-bg: #131926;
            --light-text: #f3f4f6;
            --gray-text: #9ca3af;
            --border-color: rgba(255, 255, 255, 0.05);
            --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Outfit', sans-serif;
            background: var(--dark);
            color: var(--light-text);
            line-height: 1.6;
            overflow-x: hidden;
        }

        .container {
            width: 100%;
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* ── Header Cyberpunk Nav ───────────────────────────────────────── */
        #siteHeader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
            background: rgba(11, 15, 25, 0.85);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        #siteHeader.scrolled {
            box-shadow: var(--shadow);
            border-bottom-color: rgba(0, 245, 255, 0.2);
        }
        .header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 0;
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
            color: var(--gray-text);
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.2s;
            cursor: pointer;
        }
        nav a:hover { color: var(--primary); text-shadow: 0 0 10px var(--primary-glow); }
        
        .nav-cta {
            background: linear-gradient(135deg, var(--primary), var(--secondary)) !important;
            color: var(--dark) !important;
            padding: 12px 28px;
            border-radius: 50px;
            font-weight: 800 !important;
            box-shadow: 0 8px 20px var(--primary-glow);
            transition: all 0.3s ease !important;
        }
        .nav-cta:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 15px 30px var(--primary-glow), 0 0 20px var(--secondary-glow);
        }

        .nav-toggle {
            display: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--primary);
        }

        /* ── Hero Cyberpunk Banner ──────────────────────────────────────── */
        .hero-wrap {
            position: relative;
            padding: 200px 0 130px;
            overflow: hidden;
        }
        
        .glow-sphere-1 {
            position: absolute;
            width: 500px; height: 500px;
            background: radial-gradient(circle, var(--primary-glow) 0%, transparent 70%);
            top: -100px; left: -100px;
            pointer-events: none;
        }
        .glow-sphere-2 {
            position: absolute;
            width: 600px; height: 600px;
            background: radial-gradient(circle, var(--secondary-glow) 0%, transparent 70%);
            bottom: -150px; right: -50px;
            pointer-events: none;
        }

        .hero {
            display: flex;
            align-items: center;
            gap: 60px;
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
            background: rgba(0, 245, 255, 0.05);
            color: var(--primary);
            padding: 8px 18px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 24px;
            border: 1px solid rgba(0, 245, 255, 0.15);
        }
        .hero-badge .dot {
            width: 8px; height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.4); opacity: 0.5; }
        }

        .hero h1 {
            font-size: clamp(2.6rem, 5.5vw, 4.4rem);
            font-weight: 900;
            line-height: 1.05;
            margin-bottom: 24px;
            letter-spacing: -0.03em;
            background: linear-gradient(135deg, white 40%, var(--primary) 75%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .hero p {
            font-size: 1.18rem;
            color: var(--gray-text);
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
            color: var(--dark);
            box-shadow: 0 8px 20px var(--primary-glow);
        }
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 35px var(--primary-glow), 0 0 15px var(--secondary-glow);
        }
        .btn-secondary {
            background: transparent;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.03);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .hero-image {
            flex: 0.8;
            min-width: 300px;
            display: flex;
            justify-content: center;
            position: relative;
        }
        .hero-image::after {
            content: '';
            position: absolute;
            top: 20px; left: 20px;
            right: 20px; bottom: 20px;
            box-shadow: 0 0 50px var(--primary-glow);
            border-radius: 36px;
            pointer-events: none;
            z-index: 0;
        }
        .hero-image img {
            max-width: 100%;
            height: auto;
            border-radius: 36px;
            border: 1px solid var(--border-color);
            position: relative;
            z-index: 1;
        }

        /* ── Cyber Service Grid ────────────────────────────────────────── */
        .section-wrap { padding: 100px 0; }
        .section-header { text-align: center; margin-bottom: 60px; }
        .section-eyebrow {
            display: inline-block;
            font-size: 0.82rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--primary);
            background: rgba(0, 245, 255, 0.05);
            padding: 6px 16px;
            border-radius: 50px;
            margin-bottom: 16px;
            border: 1px solid rgba(0, 245, 255, 0.1);
        }
        .section-header h2 { font-size: clamp(2rem, 3.5vw, 2.8rem); font-weight: 800; color: white; margin-bottom: 16px; letter-spacing: -0.01em; }
        .section-header p { color: var(--gray-text); font-size: 1.08rem; max-width: 600px; margin: 0 auto; }

        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 24px;
        }
        .service-card {
            background: var(--card-bg);
            padding: 36px 24px;
            border-radius: 28px;
            text-align: center;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        .service-card:hover {
            transform: translateY(-8px);
            border-color: var(--primary);
            box-shadow: 0 15px 30px var(--primary-glow);
        }
        .service-icon-wrap {
            width: 70px; height: 70px;
            margin: 0 auto 24px;
            background: rgba(0, 245, 255, 0.05);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.85rem; color: var(--primary);
            border: 1px solid rgba(0, 245, 255, 0.1);
        }
        .service-card h3 { font-size: 1.15rem; font-weight: 700; color: white; margin-bottom: 8px; }
        .service-card p { color: var(--gray-text); font-size: 0.88rem; }

        /* ── Dynamic Stats Bar ──────────────────────────────────────────── */
        .stats-bar {
            background: #111827;
            padding: 48px 0;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 32px;
            text-align: center;
        }
        .stat-item { color: white; }
        .stat-number { font-size: 2.6rem; font-weight: 900; display: block; line-height: 1; margin-bottom: 8px; color: var(--primary); text-shadow: 0 0 10px var(--primary-glow); }
        .stat-label { font-size: 0.95rem; color: var(--gray-text); font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }

        /* ── Cyber Accordion ── */
        .accordion-block {
            background: var(--card-bg);
            border-radius: 36px;
            padding: 40px;
            border: 1px solid var(--border-color);
            max-width: 900px;
            margin: 0 auto;
        }
        .accordion-item {
            border-bottom: 1px solid var(--border-color);
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
            color: white;
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
            color: var(--gray-text);
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

    <!-- Header Navigation -->
    <header id="siteHeader">
        <div class="container">
            <div class="header-inner">
                <a href="/" class="logo">
                    <img src="<?php echo $site_logo; ?>" alt="<?php echo htmlspecialchars($site_title); ?>" style="filter: brightness(0.9);">
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

    <!-- Hero Banner -->
    <section class="hero-wrap">
        <div class="glow-sphere-1"></div>
        <div class="glow-sphere-2"></div>
        <div class="container">
            <div class="hero">
                <div class="hero-content">
                    <div class="hero-badge">
                        <span class="dot"></span> Fully Automated Infrastructure
                    </div>
                    <h1>Digital Utility Payments, Re-imagined</h1>
                    <p>Recharge airtime, buy cheap mobile data, pay electricity bills and renew cable TV subscriptions instantly using our highly secured next-generation automation gateway.</p>
                    <div class="hero-buttons">
                        <a href="/web/Register.php" class="btn btn-primary"><i class="fas fa-rocket"></i> Get Started</a>
                        <a href="/web/Login.php" class="btn btn-secondary"><i class="fas fa-sign-in-alt"></i> Access Portal</a>
                    </div>
                </div>
                <div class="hero-image">
                    <img src="<?php echo $hero_image; ?>" alt="<?php echo htmlspecialchars($site_title); ?> Interface Mockup" width="600" height="400" loading="eager">
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Bar -->
    <section class="stats-bar">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <span class="stat-number">99.99%</span>
                    <span class="stat-label">Automation Success</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">24/7</span>
                    <span class="stat-label">Live Operation</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">3 Secs</span>
                    <span class="stat-label">Avg. Delivery Speed</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">Secured</span>
                    <span class="stat-label">End-to-End Encryption</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="section-wrap" id="services">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Our Products</span>
                <h2>What We Offer</h2>
                <p>Fully automated and cheap services with instant status responses and callbacks.</p>
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
    <section class="section-wrap" style="background:#0c111e;" id="about">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Who We Are</span>
                <h2>About Our Technology</h2>
                <p>Advanced VTU systems designed to make payments robust and effortless.</p>
            </div>
            <div class="accordion-block">
                <div class="accordion-item active">
                    <div class="accordion-header">Our Mission & Objective <i class="fas fa-plus"></i></div>
                    <div class="accordion-content">
                        <div class="about-text">
                            <p>We provide standard digital utility transaction channels for end-consumers and resellers alike. We are committed to rendering top-notch services with an automation standard that never sleeps.</p>
                            <p>Our dynamic system keeps track of provider uptimes, automatically switching channels to guarantee a 100% transaction completion rate.</p>
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <div class="accordion-header">Bulletproof Security Standards <i class="fas fa-plus"></i></div>
                    <div class="accordion-content">
                        <p>We use SSL encryption protocols, double-hash password storage, and rigorous API authentication methodologies to keep your funds, logs, and account records fully protected at all times.</p>
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
            <div class="accordion-block" style="background:transparent; box-shadow:none; padding:0; border:none;">
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
    <footer style="background:#090d16; color:var(--gray-text); padding:60px 0; border-top:1px solid var(--border-color);">
        <div class="container" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:30px;">
            <div>
                <img src="<?php echo $site_logo; ?>" alt="<?php echo htmlspecialchars($site_title); ?>" style="height:35px; margin-bottom:12px;">
                <p style="opacity:0.6; font-size:0.9rem;">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_title); ?>. All Rights Reserved.</p>
            </div>
            <div style="display:flex; gap:20px;">
                <a href="/web/policy/privacy.php" style="color:var(--gray-text); text-decoration:none; font-size:0.9rem;">Privacy Policy</a>
                <a href="/web/policy/terms.php" style="color:var(--gray-text); text-decoration:none; font-size:0.9rem;">Terms of Service</a>
            </div>
        </div>
    </footer>

    <!-- Scripting -->
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
                    siteNav.style.background = 'var(--card-bg)';
                    siteNav.style.padding = '20px';
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
                document.querySelectorAll('.accordion-item').forEach(el => el.classList.remove('active'));
                if (!isActive) item.classList.add('active');
            });
        });
    </script>
    
    <?php seo_render_custom_footer($site_details); ?>
</body>
</html>
