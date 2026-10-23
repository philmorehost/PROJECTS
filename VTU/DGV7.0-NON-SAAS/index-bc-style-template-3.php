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
    $site_logo = "https://via.placeholder.com/150x44/121214/ff007f?text=" . urlencode($site_title);
}

// Header Image
if (!empty($header_image_url) && file_exists("uploaded-image/" . $header_image_url)) {
    $hero_image = "uploaded-image/" . $header_image_url;
} else {
    $hero_image = "asset/vtu-fintech.jpg";
    if (!file_exists($hero_image)) {
        $hero_image = "https://via.placeholder.com/600x400/121214/00f0ff?text=" . urlencode($site_title);
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
    <title><?php echo htmlspecialchars($site_title); ?> — Futuristic Cyberpunk VTU Portal</title>
    <meta name="description" content="<?php echo htmlspecialchars($site_title); ?> is Nigeria's high-tech VTU network. Electric speed cyberpunk payment nodes.">
    <?php if (!empty($meta_keywords)): ?>
    <meta name="keywords" content="<?php echo htmlspecialchars($meta_keywords); ?>">
    <?php endif; ?>
    
    <!-- Google Font: Space Grotesk (Extremely futuristic & tech-friendly font) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"></noscript>
    <link rel="icon" type="image/png" href="<?php echo $site_logo; ?>">
    
    <style>
        /* ── Cyberpunk Neon Style ── */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Space Grotesk', sans-serif;
            background: #020205;
            color: #e2e8f0;
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* Tech Cyber Grid Background */
        .cyber-grid {
            position: absolute;
            top: 0; left: 0; right: 0; height: 100%;
            background-image: linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
            background-size: 40px 40px;
            z-index: -2;
            pointer-events: none;
        }
        
        .neon-glow {
            position: absolute;
            top: -150px; right: 10%;
            width: 350px; height: 350px;
            background: rgba(255, 0, 127, 0.2);
            filter: blur(140px);
            border-radius: 50%;
            z-index: -1;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(2, 2, 5, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 2px solid #00f0ff;
            box-shadow: 0 0 15px rgba(0, 240, 255, 0.15);
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
        .logo-img { height: 40px; filter: drop-shadow(0 0 5px rgba(255, 0, 127, 0.7)); }
        .nav-links { display: flex; gap: 32px; list-style: none; }
        .nav-links a { text-decoration: none; color: #94a3b8; font-weight: 500; transition: all 0.3s; }
        .nav-links a:hover { color: #00f0ff; text-shadow: 0 0 8px #00f0ff; }
        .nav-cta {
            background: transparent;
            color: #ff007f !important;
            border: 2px solid #ff007f;
            padding: 8px 24px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 0 10px rgba(255, 0, 127, 0.2);
            transition: all 0.3s;
        }
        .nav-cta:hover {
            background: #ff007f;
            color: white !important;
            box-shadow: 0 0 20px #ff007f;
            transform: translateY(-1px);
        }

        /* Hero */
        .hero { padding: 120px 0 80px; position: relative; }
        .hero-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
            align-items: center;
        }
        .cyber-card {
            background: rgba(10, 10, 18, 0.8);
            border: 2px solid rgba(0, 240, 255, 0.3);
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 0 30px rgba(0, 240, 255, 0.05);
            position: relative;
        }
        .cyber-card::after {
            content: '';
            position: absolute;
            top: -2px; right: -2px; width: 20px; height: 20px;
            border-top: 4px solid #ff007f;
            border-right: 4px solid #ff007f;
        }
        .hero h1 { font-size: 48px; font-weight: 800; line-height: 1.1; margin-bottom: 20px; text-transform: uppercase; }
        .hero h1 span { color: #00f0ff; text-shadow: 0 0 10px rgba(0, 240, 255, 0.5); }
        .hero p { font-size: 18px; color: #94a3b8; margin-bottom: 30px; }
        
        .btn-group { display: flex; gap: 16px; }
        .btn {
            padding: 14px 32px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #00f0ff;
            color: #020205;
            box-shadow: 0 0 15px rgba(0, 240, 255, 0.4);
        }
        .btn-primary:hover {
            background: #00c8d6;
            box-shadow: 0 0 25px rgba(0, 240, 255, 0.7);
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: transparent;
            color: #e2e8f0;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        .btn-secondary:hover { border-color: #ff007f; color: #ff007f; box-shadow: 0 0 15px rgba(255, 0, 127, 0.3); transform: translateY(-2px); }

        .hero-img-container {
            border: 2px solid #ff007f;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 0 30px rgba(255, 0, 127, 0.25);
        }
        .hero-img-container img { width: 100%; display: block; filter: saturate(1.2); }

        /* Features Section */
        .features { padding: 80px 0; }
        .section-header { text-align: center; max-width: 600px; margin: 0 auto 50px; }
        .section-header h2 { font-size: 36px; font-weight: 800; margin-bottom: 12px; text-transform: uppercase; }
        .section-header h2 span { color: #ff007f; }
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
        }
        .feature-card {
            background: rgba(10, 10, 18, 0.9);
            border: 1px solid rgba(0, 240, 255, 0.2);
            border-radius: 8px;
            padding: 30px;
            transition: all 0.3s;
            text-align: center;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            border-color: #ff007f;
            box-shadow: 0 0 20px rgba(255, 0, 127, 0.3);
        }
        .feature-icon {
            width: 60px; height: 60px;
            border-radius: 8px;
            background: rgba(0, 240, 255, 0.1);
            color: #00f0ff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 20px;
            text-shadow: 0 0 5px #00f0ff;
        }

        /* Footer */
        footer {
            background: #05050a;
            border-top: 2px solid #ff007f;
            padding: 60px 0 30px;
            margin-top: 80px;
        }
        .footer-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 40px;
            margin-bottom: 40px;
        }
        .footer-logo { font-size: 24px; font-weight: 800; color: #ff007f; margin-bottom: 16px; text-transform: uppercase; text-shadow: 0 0 8px #ff007f; }
        .footer-desc { color: #94a3b8; font-size: 14px; }
        .footer-col h4 { font-size: 16px; font-weight: 700; margin-bottom: 20px; color: #00f0ff; text-transform: uppercase; }
        .footer-col ul { list-style: none; }
        .footer-col ul li { margin-bottom: 12px; }
        .footer-col ul li a { text-decoration: none; color: #94a3b8; font-size: 14px; transition: color 0.3s; }
        .footer-col ul li a:hover { color: #ff007f; }

        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,0.05);
            padding-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .hero-grid { grid-template-columns: 1fr; }
            .footer-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
    <?php echo $custom_header; ?>
</head>
<body>
    <div class="cyber-grid"></div>
    <div class="neon-glow"></div>

    <header>
        <div class="container nav-wrapper">
            <div class="logo">
                <a href="/"><img src="<?php echo $site_logo; ?>" class="logo-img" alt="Logo"></a>
            </div>
            <ul class="nav-links">
                <li><a href="/">Home</a></li>
                <li><a href="/web/Pricing.php">Pricing</a></li>
                <li><a href="/web/APIDocs.php">Developer API</a></li>
                <li><a href="/web/Login.php" class="nav-cta">Access Node</a></li>
            </ul>
        </div>
    </header>

    <main class="hero">
        <div class="container hero-grid">
            <div class="cyber-card">
                <h1>High-Tech <span>Digital Bills</span> Automation</h1>
                <p>Welcome to the next-generation digital transaction node. Execute instant Airtime, dynamic data pipelines, high-voltage electricity token generators, and gaming wallets at cyberpunk speed.</p>
                <div class="btn-group">
                    <a href="/web/Register.php" class="btn btn-primary">Initialize Node <i class="fas fa-terminal"></i></a>
                    <?php if(!empty($apk_download_url)): ?>
                    <a href="<?php echo htmlspecialchars($apk_download_url); ?>" class="btn btn-secondary"><i class="fab fa-android"></i> Get Android Client</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-img-container">
                <img src="<?php echo htmlspecialchars($hero_image); ?>" alt="Vtu cyber">
            </div>
        </div>
    </main>

    <section class="features">
        <div class="container">
            <div class="section-header">
                <h2>System <span>Capabilities</span></h2>
                <p>High-speed, secured server nodes operating 24/7 with zero downtime latency.</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-server"></i></div>
                    <h3>Gigabyte Data</h3>
                    <p>Low latency instant gigabyte data pipelines direct to major carriers.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-signal"></i></div>
                    <h3>Neon Airtime</h3>
                    <p>Immediate mobile frequency frequency topups across networks.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-network-wired"></i></div>
                    <h3>Digital TV Sub</h3>
                    <p>Decoders re-activation protocols initiated within milliseconds.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-microchip"></i></div>
                    <h3>Smart Grid Power</h3>
                    <p>Prepaid tokens transmitted with extreme encryption straight to your device.</p>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <div class="container footer-grid">
            <div class="footer-col">
                <div class="footer-logo"><?php echo htmlspecialchars($site_title); ?></div>
                <p class="footer-desc">Automated futuristic gateway distributing top level VTU services with military-grade safety.</p>
            </div>
            <div class="footer-col">
                <h4>System Links</h4>
                <ul>
                    <li><a href="/web/Register.php">Register Node</a></li>
                    <li><a href="/web/Login.php">Auth Terminal</a></li>
                    <li><a href="/web/Pricing.php">Exchange Pricing</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Core Protocols</h4>
                <ul>
                    <li><a href="/web/APIDocs.php">API Terminal Docs</a></li>
                    <li><a href="/web/APIDocs.php">Webhook Webhook</a></li>
                </ul>
            </div>
        </div>
        <div class="container footer-bottom">
            <div>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_title); ?>. Terminal Active.</div>
            <div>Powered by Cyberpunk Neon Engine.</div>
        </div>
    </footer>

    <?php echo $custom_footer; ?>
</body>
</html>
<?php
ob_end_flush();
?>
