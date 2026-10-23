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
    // Fetch Site Details and optional header image in one query for faster page load
    if (isset($connection_server)) {
        $cacheKey = 'vendor_site_style_' . md5((int)$vendor_account_details['id']);
        $cachedStyle = function_exists('bc_cache_get') ? bc_cache_get($cacheKey, 300) : null;

        if (is_array($cachedStyle)) {
            $site_details     = $cachedStyle;
            $site_title       = $cachedStyle['site_title'] ?? $site_title;
            $apk_download_url = $cachedStyle['apk_download_url'] ?? $apk_download_url;
            $header_image_url = $cachedStyle['header_image'] ?? $header_image_url;
        } else {
            $template_name_pattern = '%template-1%';
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
    $site_logo = "https://via.placeholder.com/150x44/1e3a8a/ffffff?text=" . urlencode($site_title);
}

// Header Image
if (!empty($header_image_url) && file_exists("uploaded-image/" . $header_image_url)) {
    $hero_image = "uploaded-image/" . $header_image_url;
} else {
    $hero_image = "asset/vtu-fintech.jpg"; // Use an existing asset
    if (!file_exists($hero_image)) {
        $hero_image = "https://via.placeholder.com/600x400/1e3a8a/ffffff?text=" . urlencode($site_title);
    }
}

// Social Share URLs
$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$share_text = urlencode("Check out " . $site_title . " for Digital Payments Made Simple!");
$facebook_share = "https://www.facebook.com/sharer/sharer.php?u=" . urlencode($current_url);
$twitter_share = "https://twitter.com/intent/tweet?url=" . urlencode($current_url) . "&text=" . $share_text;
$linkedin_share = "https://www.linkedin.com/shareArticle?mini=true&url=" . urlencode($current_url);
$email_share = "mailto:?subject=" . $share_text . "&body=" . urlencode($current_url);
$contact_email = "mailto:" . (($vendor_account_details['email'] ?? '') ?: '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seo_render_head_tags($site_details, $vendor_account_details); ?>
    <!-- Font Awesome 6 (free) -->
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"></noscript>
    <!-- Google Font: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800;14..32,900&display=swap" rel="stylesheet">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?php echo $site_logo; ?>">
    <!-- PWA -->
    <meta name="theme-color" content="#1e3a8a">
    <?php if (!empty($hero_image)): ?>
        <link rel="preload" as="image" href="<?php echo htmlspecialchars($hero_image); ?>">
    <?php endif; ?>
    <style>
        /* ── Reset & base ──────────────────────────────────────────── */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* ── Sticky header / glass nav ─────────────────────────────── */
        #siteHeader {
            position: sticky;
            top: 0;
            z-index: 900;
            background: rgba(248,250,252,0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid transparent;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        #siteHeader.scrolled {
            border-bottom-color: #e2e8f0;
            box-shadow: 0 4px 20px -8px rgba(30,58,138,0.12);
        }
        .header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 0;
        }
        .nav-cta {
            background: #1e3a8a !important;
            color: white !important;
            padding: 10px 22px;
            border-radius: 50px;
            font-weight: 600 !important;
            transition: background 0.2s, transform 0.15s !important;
        }
        .nav-cta:hover { background: #0f2b6b !important; transform: translateY(-1px); }

        /* ── Hero ─────────────────────────────────────────────────── */
        .hero-wrap {
            position: relative;
            overflow: hidden;
            padding: 80px 0 100px;
        }
        .hero-blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.22;
            animation: floatBlob 8s ease-in-out infinite alternate;
            pointer-events: none;
        }
        .hero-blob-1 { width: 500px; height: 500px; background: #3b82f6; top: -150px; left: -100px; animation-duration: 9s; }
        .hero-blob-2 { width: 400px; height: 400px; background: #a855f7; bottom: -100px; right: -80px; animation-duration: 11s; animation-delay: -3s; }
        .hero-blob-3 { width: 300px; height: 300px; background: #fbbf24; top: 60%; left: 40%; animation-duration: 7s; animation-delay: -5s; }
        @keyframes floatBlob {
            0%   { transform: translate(0,0) scale(1); }
            100% { transform: translate(30px,20px) scale(1.08); }
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #eff6ff;
            color: #1e3a8a;
            border: 1px solid #bfdbfe;
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 0.87rem;
            font-weight: 600;
            margin-bottom: 22px;
        }
        .hero-badge .dot {
            width: 8px; height: 8px;
            background: #22c55e;
            border-radius: 50%;
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50%       { transform: scale(1.4); opacity: 0.6; }
        }

        /* ── Stats bar ────────────────────────────────────────────── */
        .stats-bar { background: #1e3a8a; padding: 36px 0; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            text-align: center;
        }
        .stat-item { color: white; }
        .stat-number { font-size: 2.2rem; font-weight: 900; display: block; line-height: 1; margin-bottom: 6px; }
        .stat-label { font-size: 0.9rem; opacity: 0.8; font-weight: 500; }

        /* ── Section helpers ──────────────────────────────────────── */
        .section-wrap { padding: 80px 0; }
        .section-header { text-align: center; margin-bottom: 56px; }
        .section-eyebrow {
            display: inline-block;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #1e3a8a;
            background: #eff6ff;
            padding: 4px 14px;
            border-radius: 50px;
            margin-bottom: 14px;
        }
        .section-header h2 { font-size: clamp(1.8rem, 3vw, 2.6rem); font-weight: 800; color: #0b2b4a; margin-bottom: 14px; }
        .section-header p { color: #64748b; font-size: 1.02rem; max-width: 560px; margin: 0 auto; }

        /* ── How it works ─────────────────────────────────────────── */
        .steps-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            position: relative;
        }
        .steps-grid::before {
            content: '';
            position: absolute;
            top: 44px; left: 16.5%; right: 16.5%;
            height: 2px;
            background: linear-gradient(90deg, #bfdbfe, #1e3a8a, #bfdbfe);
            z-index: 0;
        }
        .step-card {
            background: white;
            border-radius: 28px;
            padding: 36px 28px;
            text-align: center;
            border: 1px solid #e2e8f0;
            box-shadow: 0 8px 24px -12px #94a3b8;
            position: relative;
            z-index: 1;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .step-card:hover { transform: translateY(-5px); box-shadow: 0 20px 32px -12px #1e3a8a40; }
        .step-num {
            width: 56px; height: 56px;
            margin: 0 auto 20px;
            background: #1e3a8a;
            color: white;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; font-weight: 900;
            box-shadow: 0 8px 20px -6px #1e3a8a80;
        }
        .step-card h3 { font-size: 1.15rem; font-weight: 700; color: #0b2b4a; margin-bottom: 10px; }
        .step-card p { color: #64748b; font-size: 0.93rem; line-height: 1.6; }

        /* ── Testimonials ─────────────────────────────────────────── */
        .testimonials-wrap { background: #f0f6ff; }
        .testimonials-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; }
        .testimonial-card {
            background: white; border-radius: 24px; padding: 32px 26px;
            border: 1px solid #e2e8f0; box-shadow: 0 8px 24px -12px #94a3b860;
            transition: transform 0.2s;
        }
        .testimonial-card:hover { transform: translateY(-4px); }
        .stars { color: #fbbf24; font-size: 1.05rem; margin-bottom: 14px; }
        .testimonial-card blockquote {
            font-size: 0.96rem; color: #334155; line-height: 1.7;
            font-style: italic; margin-bottom: 20px; border: none; padding: 0;
        }
        .testimonial-author { display: flex; align-items: center; gap: 12px; }
        .author-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; font-weight: 700; color: white; flex-shrink: 0;
        }
        .author-name { font-weight: 700; font-size: 0.93rem; color: #0b2b4a; }
        .author-role { font-size: 0.78rem; color: #64748b; }

        /* ── App Download ─────────────────────────────────────────── */
        .app-download-section {
            background: linear-gradient(135deg, #0b2b4a 0%, #1e3a8a 60%, #3b82f6 100%);
            border-radius: 40px; padding: 64px 48px;
            display: flex; align-items: center; gap: 48px; flex-wrap: wrap;
            overflow: hidden; position: relative;
        }
        .app-download-section::before { content:''; position:absolute; right:-80px; top:-80px; width:350px; height:350px; background:rgba(255,255,255,0.04); border-radius:50%; }
        .app-download-section::after  { content:''; position:absolute; left:-60px; bottom:-80px; width:280px; height:280px; background:rgba(255,255,255,0.04); border-radius:50%; }
        .app-info { flex: 1; min-width: 250px; position: relative; z-index: 1; }
        .app-info h2 { font-size: 2rem; font-weight: 900; color: white; margin-bottom: 14px; line-height: 1.25; }
        .app-info p  { color: rgba(255,255,255,0.75); font-size: 1rem; margin-bottom: 28px; line-height: 1.7; }
        .app-badges { display: flex; gap: 14px; flex-wrap: wrap; }
        .app-badge {
            display: inline-flex; align-items: center; gap: 10px;
            background: rgba(255,255,255,0.12); color: white;
            padding: 12px 20px; border-radius: 14px;
            text-decoration: none; font-weight: 600; font-size: 0.93rem;
            border: 1px solid rgba(255,255,255,0.2);
            transition: background 0.2s, transform 0.15s;
        }
        .app-badge:hover { background: rgba(255,255,255,0.22); transform: translateY(-2px); }
        .app-badge i { font-size: 1.4rem; }
        .app-badge-sub  { font-size: 0.7rem; font-weight: 400; opacity: 0.8; display: block; }
        .app-badge-main { font-size: 0.97rem; display: block; line-height: 1.2; }
        .app-img { flex: 0 0 auto; position: relative; z-index: 1; }
        .app-img img { height: 190px; border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,0.3); }

        /* ── FAQ ──────────────────────────────────────────────────── */
        .faq-list { max-width: 820px; margin: 0 auto; }
        .faq-item {
            background: white; border-radius: 16px; margin-bottom: 12px;
            border: 1px solid #e2e8f0; overflow: hidden;
            box-shadow: 0 4px 12px -6px #94a3b840;
        }
        .faq-q {
            display: flex; justify-content: space-between; align-items: center;
            padding: 20px 24px; font-weight: 600; font-size: 1rem;
            cursor: pointer; color: #0f2b4f; user-select: none;
        }
        .faq-q i { transition: transform 0.3s; color: #1e3a8a; flex-shrink: 0; margin-left: 12px; }
        .faq-a {
            max-height: 0; overflow: hidden;
            transition: max-height 0.35s ease, padding 0.2s;
            padding: 0 24px; color: #475569; font-size: 0.96rem; line-height: 1.7;
        }
        .faq-item.open .faq-a { max-height: 400px; padding: 0 24px 20px; }
        .faq-item.open .faq-q i { transform: rotate(45deg); }

        /* ── CTA Banner ───────────────────────────────────────────── */
        .cta-banner {
            background: linear-gradient(135deg, #0b2b4a 0%, #1e3a8a 100%);
            border-radius: 32px; padding: 64px 48px; text-align: center;
            position: relative; overflow: hidden;
        }
        .cta-banner h2 { font-size: 2.2rem; font-weight: 900; color: white; margin-bottom: 12px; }
        .cta-banner p  { color: rgba(255,255,255,0.75); font-size: 1.05rem; margin-bottom: 32px; }
        .cta-banner .btn-primary { background: white; color: #1e3a8a; }
        .cta-banner .btn-primary:hover { background: #f1f5f9; }

        /* ── Scroll reveal ────────────────────────────────────────── */
        .reveal { opacity: 0; transform: translateY(22px); transition: opacity 0.6s ease, transform 0.6s ease; }
        .reveal.visible { opacity: 1; transform: none; }
        .reveal-delay-1 { transition-delay: 0.1s; }
        .reveal-delay-2 { transition-delay: 0.2s; }
        .reveal-delay-3 { transition-delay: 0.3s; }

        /* ── modern floating social - lighter, glassy ── */
        .float-share {
            position: fixed;
            right: 24px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            flex-direction: column;
            gap: 12px;
            z-index: 999;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            padding: 12px 8px;
            border-radius: 32px;
            box-shadow: 0 10px 30px -10px rgba(0,20,50,0.2);
            border: 1px solid rgba(255,255,255,0.5);
        }
        .float-share a {
            color: #1e3a8a;
            background: white;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 1.3rem;
            transition: 0.2s;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.02);
        }
        .float-share a:hover {
            background: #1e3a8a;
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 15px 20px -8px #1e3a8a60;
        }


        /* container */
        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 32px;
        }

        /* header - rendered inside sticky #siteHeader */
        .logo img {
            height: 42px;
            width: auto;
            display: block;
        }
        nav {
            display: flex;
            gap: 28px;
            align-items: center;
        }
        nav a {
            font-weight: 500;
            color: #334155;
            text-decoration: none;
            font-size: 0.98rem;
            transition: color 0.2s;
            cursor: pointer;
            white-space: nowrap;
        }
        nav a:hover { color: #1e3a8a; }
        .nav-toggle {
            display: none;
            font-size: 1.4rem;
            cursor: pointer;
            color: #1e3a8a;
        }

        /* hero - two-col with blobs */
        .hero {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 60px;
            flex-wrap: wrap;
        }
        .hero-content { flex: 1; min-width: 300px; }
        .hero h1 {
            font-size: clamp(2.4rem, 5vw, 3.8rem);
            font-weight: 900;
            line-height: 1.15;
            background: linear-gradient(145deg, #0b2b4a 0%, #1e3a8a 60%, #3b82f6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
        }
        .hero p {
            font-size: 1.15rem;
            color: #475569;
            max-width: 480px;
            margin-bottom: 34px;
            line-height: 1.7;
        }
        .hero-buttons { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 30px;
            border-radius: 50px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.97rem;
        }
        .btn-primary {
            background: #1e3a8a;
            color: white;
            box-shadow: 0 8px 20px -6px #1e3a8a80;
        }
        .btn-primary:hover { background: #0f2b6b; transform: translateY(-2px); box-shadow: 0 16px 28px -8px #0f2b6b80; }
        .btn-secondary {
            background: #fbbf24;
            color: #1e293b;
            box-shadow: 0 8px 20px -6px #fbbf2480;
        }
        .btn-secondary:hover { background: #f59e0b; transform: translateY(-2px); }
        .hero-image { flex: 1; min-width: 280px; display: flex; justify-content: center; }
        .hero-image img {
            max-width: 100%;
            height: auto;
            border-radius: 28px;
            box-shadow: 0 30px 60px -20px #1e3a8a60;
            border: 1px solid rgba(255,255,255,0.4);
        }

        /* section titles (legacy) */
        .section-title {
            font-size: 2rem;
            font-weight: 800;
            margin: 60px 0 24px;
            color: #0b2b4a;
            position: relative;
            padding-bottom: 10px;
        }
        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0;
            width: 60px; height: 4px;
            background: #1e3a8a;
            border-radius: 4px;
        }

        /* service cards */
        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
            gap: 22px;
            margin: 0 0 40px;
        }
        .service-card {
            background: white;
            padding: 28px 16px;
            border-radius: 24px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid #f1f5f9;
            box-shadow: 0 6px 20px -10px #94a3b8;
        }
        .service-card:hover { transform: translateY(-6px); box-shadow: 0 20px 30px -12px #1e3a8a50; border-color: #cbd5e1; }
        .service-icon-wrap {
            width: 64px; height: 64px;
            margin: 0 auto 16px;
            background: linear-gradient(145deg, #e0f2fe, #f0f9ff);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.75rem; color: #1e3a8a;
            box-shadow: 0 4px 10px #b0c4de28;
        }
        .service-card h3 { font-size: 1rem; font-weight: 700; color: #0b2b4a; margin-bottom: 5px; }
        .service-card p  { color: #64748b; font-size: 0.82rem; }

        /* why choose us cards */
        .why-vtu { display: flex; gap: 28px; flex-wrap: wrap; margin: 0 0 40px; }
        .why-card {
            flex: 1;
            background: white;
            padding: 36px 28px;
            border-radius: 32px;
            box-shadow: 0 12px 28px -16px #1e293b;
            border: 1px solid #f1f5f9;
            transition: box-shadow 0.2s;
            min-width: 220px;
        }
        .why-card:hover { box-shadow: 0 24px 36px -16px #1e3a8a60; }
        .why-card i { font-size: 2.6rem; color: #1e3a8a; margin-bottom: 18px; }
        .why-card h3 { font-size: 1.4rem; font-weight: 700; color: #0b2b4a; margin-bottom: 12px; }
        .why-card p { color: #475569; line-height: 1.7; }

        /* about accordion - modern */
        .accordion-block {
            background: white;
            border-radius: 32px;
            padding: 24px 32px;
            margin: 60px 0 20px;
            box-shadow: 0 20px 35px -20px #1e293b;
            border: 1px solid #eef2f6;
        }
        .accordion-item {
            border-bottom: 1px solid #e2e8f0;
        }
        .accordion-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            font-weight: 600;
            font-size: 1.5rem;
            cursor: pointer;
            color: #0f2b4f;
        }
        .accordion-header i {
            transition: transform 0.3s;
            color: #1e3a8a;
        }
        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease;
            padding: 0 8px 0 0;
            color: #334155;
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
            line-height: 1.7;
        }
        .about-text p {
            margin-bottom: 16px;
        }

        /* privacy static - clean */
        .privacy-static {
            background: white;
            border-radius: 32px;
            padding: 40px;
            margin: 60px 0 20px;
            box-shadow: 0 20px 35px -20px #1e293b;
            border: 1px solid #eef2f6;
        }
        .privacy-static h2 {
            font-size: 2rem;
            color: #0b2b4a;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .privacy-text {
            column-count: 2;
            column-gap: 40px;
            line-height: 1.7;
            color: #334155;
        }
        .privacy-text p {
            margin-bottom: 16px;
        }

        /* hidden owner note (not used) */
        .owner-note {
            display: none;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: white;
            max-width: 450px;
            width: 90%;
            padding: 40px;
            border-radius: 48px;
            box-shadow: 0 30px 60px -20px #0b2b4a;
            position: relative;
            border: 1px solid #eef2f6;
        }
        .modal-close {
            position: absolute;
            top: 24px;
            right: 24px;
            font-size: 1.5rem;
            color: #94a3b8;
            cursor: pointer;
            transition: 0.2s;
        }
        .modal-close:hover {
            color: #1e293b;
        }
        .modal-content h2 {
            font-size: 2rem;
            color: #0b2b4a;
            margin-bottom: 12px;
        }
        .modal-content p {
            color: #475569;
            margin-bottom: 28px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 500;
            color: #334155;
            margin-bottom: 6px;
        }
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 14px 18px;
            border: 1px solid #e2e8f0;
            border-radius: 30px;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            transition: 0.2s;
            background: #f8fafc;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px #1e3a8a20;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        .modal-send {
            background: #25D366;
            color: white;
            border: none;
            padding: 16px 28px;
            border-radius: 60px;
            font-weight: 600;
            font-size: 1.1rem;
            width: 100%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: 0.2s;
            box-shadow: 0 10px 20px -8px #075e54;
        }
        .modal-send:hover {
            background: #20b859;
            transform: translateY(-2px);
            box-shadow: 0 18px 25px -10px #065e4e;
        }

        /* PWA Styles */
        .network-status {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%) translateY(-100px);
            padding: 12px 24px;
            border-radius: 50px;
            font-weight: 600;
            z-index: 10001;
            transition: 0.5s;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .network-status.active {
            transform: translateX(-50%) translateY(0);
        }
        .network-status.online { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .network-status.offline { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .pwa-modal {
            display: none;
            position: fixed;
            bottom: 30px;
            left: 0;
            width: 100%;
            padding: 0 20px;
            z-index: 10002;
            animation: slideUp 0.5s ease-out;
        }
        .pwa-modal.active { display: block; }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }

        .pwa-content {
            background: white;
            border-radius: 30px;
            padding: 24px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            max-width: 500px;
            margin: 0 auto;
            border: 1px solid #eef2f6;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .pwa-icon img { width: 60px; height: 60px; border-radius: 15px; }
        .pwa-info { flex: 1; }
        .pwa-info h4 { margin: 0; color: #0b2b4a; }
        .pwa-info p { margin: 4px 0 0; font-size: 0.9rem; color: #64748b; }
        .pwa-actions { display: flex; gap: 10px; }
        .btn-install { background: #1e3a8a; color: white; border: none; padding: 12px 24px; border-radius: 15px; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .btn-install:hover { background: #0f172a; }
        .btn-close-pwa { background: #f1f5f9; color: #64748b; border: none; padding: 12px; border-radius: 15px; cursor: pointer; }

        /* footer modern - grid layout */
        footer {
            background: #0b1e33;
            color: #cbd5e1;
            padding: 64px 0 36px;
            margin-top: 40px;
            border-radius: 48px 48px 0 0;
        }
        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 48px;
            margin-bottom: 48px;
        }
        .footer-brand p { font-size: 0.9rem; color: #94a3b8; line-height: 1.7; max-width: 280px; margin-bottom: 20px; }
        .footer-brand img { height: 38px; filter: brightness(0) invert(1); opacity: 0.85; margin-bottom: 14px; display: block; }
        .footer-social { display: flex; gap: 10px; }
        .footer-social a {
            width: 38px; height: 38px;
            background: rgba(255,255,255,0.07);
            color: #cbd5e1;
            display: flex; align-items: center; justify-content: center;
            border-radius: 10px; font-size: 1rem;
            transition: all 0.2s; text-decoration: none;
        }
        .footer-social a:hover { background: #1e3a8a; color: white; transform: translateY(-2px); }
        .footer-col h5 { font-weight: 700; color: white; margin-bottom: 18px; font-size: 0.88rem; text-transform: uppercase; letter-spacing: 0.08em; }
        .footer-col a {
            display: block; color: #94a3b8; text-decoration: none;
            margin-bottom: 10px; font-size: 0.88rem;
            transition: color 0.2s; cursor: pointer;
        }
        .footer-col a:hover { color: white; }
        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,0.07);
            padding-top: 28px;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 14px;
        }
        .footer-copyright { font-size: 0.85rem; color: #64748b; }
        .footer-badges { display: flex; gap: 16px; color: #64748b; font-size: 0.8rem; flex-wrap: wrap; }

        /* responsiveness */
        @media (max-width: 1024px) {
            .footer-grid { grid-template-columns: 1fr 1fr; }
            .steps-grid { gap: 20px; }
            .steps-grid::before { display: none; }
        }
        @media (max-width: 768px) {
            .container { padding: 0 20px; }
            .float-share { display: none; }
            .hero-wrap { padding: 50px 0 60px; }
            .hero h1 { font-size: 2.3rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .steps-grid { grid-template-columns: 1fr; }
            .testimonials-grid { grid-template-columns: 1fr; }
            .about-text, .privacy-text { column-count: 1; }
            .app-download-section { flex-direction: column; padding: 44px 28px; }
            .cta-banner { padding: 44px 28px; }
            .cta-banner h2 { font-size: 1.8rem; }
            .nav-toggle { display: block; }
            nav {
                display: none;
                flex-direction: column;
                position: absolute;
                top: 74px; left: 0;
                width: 100%;
                background: white;
                padding: 18px 20px;
                box-shadow: 0 12px 20px rgba(0,0,0,0.08);
                z-index: 1000;
                gap: 12px;
            }
            nav.active { display: flex; }
            .nav-cta { display: inline-flex; width: fit-content; }
        }
        @media (max-width: 480px) {
            .footer-grid { grid-template-columns: 1fr; }
            .footer-bottom { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

<?php if(isServiceEnabled('crypto_hub')): ?>
<script defer src="https://www.livecoinwatch.com/static/lcw-widget.js"></script>
<div class="livecoinwatch-widget-5" lcw-base="USD" lcw-color-tx="#0f172a" lcw-marquee-1="coins" lcw-marquee-2="movers" lcw-marquee-items="15" lcw-font-weight="600" style="border-bottom: 1px solid #e2e8f0; background: #ffffff;"></div>
<?php endif; ?>

<!-- FLOATING SOCIAL SHARE -->
<div class="float-share">
    <a href="<?php echo $facebook_share; ?>" target="_blank" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
    <a href="<?php echo $twitter_share; ?>" target="_blank" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
    <a href="<?php echo $linkedin_share; ?>" target="_blank" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
    <a href="<?php echo $email_share; ?>" aria-label="Email"><i class="fa fa-envelope"></i></a>
</div>

<!-- ══════════ STICKY HEADER ══════════ -->
<header id="siteHeader">
<div class="container header-inner">
    <div class="logo">
        <img src="<?php echo $site_logo; ?>" alt="<?php echo htmlspecialchars($site_title); ?>">
    </div>
    <div class="nav-toggle" onclick="toggleNav()">
        <i class="fas fa-bars"></i>
    </div>
    <nav id="mainNav">
        <a href="#services" onclick="closeNav()">Services</a>
        <a href="#how-it-works" onclick="closeNav()">How It Works</a>
        <a href="blog.php" onclick="closeNav()">Blog</a>
        <a href="web/APIDocs.php" onclick="closeNav()">API Docs</a>
        <a href="#about" onclick="closeNav()">About</a>
        <a href="<?php echo $contact_email; ?>" onclick="closeNav();">Contact</a>
        <a href="web/Register.php" class="nav-cta" onclick="closeNav()"><i class="fas fa-bolt"></i> Get Started</a>
    </nav>
</div>
</header>

<main>

<!-- ══════════ HERO ══════════ -->
<div class="hero-wrap">
    <div class="hero-blob hero-blob-1"></div>
    <div class="hero-blob hero-blob-2"></div>
    <div class="hero-blob hero-blob-3"></div>
    <div class="container">
        <div class="hero">
            <div class="hero-content reveal">
                <div class="hero-badge"><span class="dot"></span> Trusted by 50K+ users across Nigeria</div>
                <h1>Digital Payments<br>Made Simple</h1>
                <p>Your all-in-one platform for instant Airtime, Data, TV subscriptions, Electricity bills, Crypto, and much more — 24/7, zero downtime.</p>
                <div class="hero-buttons">
                    <a href="web/Register.php" class="btn btn-primary"><i class="fas fa-bolt"></i> Get started free</a>
                    <a href="web/Login.php" class="btn btn-secondary"><i class="fas fa-sign-in-alt"></i> Login</a>
                    <?php echo getAndroidDownloadButton(); ?>
                </div>
            </div>
            <div class="hero-image reveal reveal-delay-2">
                <img src="<?php echo $hero_image; ?>" alt="<?php echo htmlspecialchars($site_title); ?> dashboard">
            </div>
        </div>
    </div>
</div>

<!-- ══════════ STATS BAR ══════════ -->
<div class="stats-bar">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-item reveal">
                <span class="stat-number" data-target="50000">0</span>
                <span class="stat-label">Happy Users</span>
            </div>
            <div class="stat-item reveal reveal-delay-1">
                <span class="stat-number" data-target="1000000">0</span>
                <span class="stat-label">Transactions Processed</span>
            </div>
            <div class="stat-item reveal reveal-delay-2">
                <span class="stat-number" data-target="99" data-suffix=".9%">0</span>
                <span class="stat-label">Platform Uptime</span>
            </div>
            <div class="stat-item reveal reveal-delay-3">
                <span class="stat-number">24/7</span>
                <span class="stat-label">Customer Support</span>
            </div>
        </div>
    </div>
</div>

<!-- ══════════ SERVICES ══════════ -->
<div class="container" id="services" style="padding:80px 32px 40px;">
    <div class="section-header reveal">
        <span class="section-eyebrow">What we offer</span>
        <h2>⚡ Digital Services at Your Fingertips</h2>
        <p>Everything you need in one place — fast, affordable, and available round the clock.</p>
    </div>
    <div class="service-grid">
        <?php if(isServiceEnabled('airtime')): ?>
        <div class="service-card reveal"><div class="service-icon-wrap"><i class="fas fa-phone-alt"></i></div><h3>Buy Airtime</h3><p>MTN, Glo, Airtel, 9mobile &amp; discounts</p></div>
        <?php endif; ?>
        <?php if(isServiceEnabled('data')): ?>
        <div class="service-card reveal reveal-delay-1"><div class="service-icon-wrap"><i class="fas fa-wifi"></i></div><h3>Buy Data</h3><p>Fast bundles, all networks</p></div>
        <?php endif; ?>
        <?php if(isServiceEnabled('electric')): ?>
        <div class="service-card reveal reveal-delay-2"><div class="service-icon-wrap"><i class="fas fa-bolt"></i></div><h3>Electricity</h3><p>Prepaid/postpaid token instantly</p></div>
        <?php endif; ?>
        <?php if(isServiceEnabled('cable')): ?>
        <div class="service-card reveal"><div class="service-icon-wrap"><i class="fas fa-tv"></i></div><h3>Cable TV</h3><p>DSTV, GOtv, StarTimes renewal</p></div>
        <?php endif; ?>
        <?php if(isServiceEnabled('bank_transfer')): ?>
        <div class="service-card reveal reveal-delay-1"><div class="service-icon-wrap"><i class="fas fa-university"></i></div><h3>Wallet to Bank</h3><p>Instant withdrawal to any bank</p></div>
        <?php endif; ?>
        <?php if(isServiceEnabled('bulk_sms')): ?>
        <div class="service-card reveal reveal-delay-2"><div class="service-icon-wrap"><i class="fas fa-comment-dots"></i></div><h3>Bulk SMS</h3><p>Customized, high delivery</p></div>
        <?php endif; ?>
        <?php if(isServiceEnabled('exam')): ?>
        <div class="service-card reveal"><div class="service-icon-wrap"><i class="fas fa-graduation-cap"></i></div><h3>Exam Pin</h3><p>WAEC, NECO, NABTEB e-pins</p></div>
        <?php endif; ?>
        <?php if(isServiceEnabled('recharge_card')): ?>
        <div class="service-card reveal reveal-delay-1"><div class="service-icon-wrap"><i class="fas fa-print"></i></div><h3>Recharge Card Printing</h3><p>Print &amp; sell e-pins</p></div>
        <?php endif; ?>
        <?php if(isServiceEnabled('data_card')): ?>
        <div class="service-card reveal reveal-delay-2"><div class="service-icon-wrap"><i class="fas fa-database"></i></div><h3>Data Card Printing</h3><p>Generate data bundle pins</p></div>
        <?php endif; ?>
        <?php if(isServiceEnabled('gift_card')): ?>
        <div class="service-card reveal"><div class="service-icon-wrap"><i class="fas fa-gift"></i></div><h3>Gift Cards</h3><p>Trade local &amp; international</p></div>
        <?php endif; ?>
        <?php if(isServiceEnabled('virtual_card')): ?>
        <div class="service-card reveal reveal-delay-1"><div class="service-icon-wrap"><i class="fas fa-credit-card"></i></div><h3>Virtual Cards</h3><p>Dollar &amp; Naira for online payments</p></div>
        <?php endif; ?>
        <?php if(isServiceEnabled('crypto_hub')): ?>
        <div class="service-card reveal reveal-delay-2"><div class="service-icon-wrap"><i class="fas fa-coins"></i></div><h3>Crypto Hub</h3><p>Send, Swap, Hold Stable Coins</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════ HOW IT WORKS ══════════ -->
<div id="how-it-works" style="background:white; padding:80px 0;">
<div class="container">
    <div class="section-header reveal">
        <span class="section-eyebrow">Simple process</span>
        <h2>Get started in 3 easy steps</h2>
        <p>No paperwork. No waiting. Just fast, reliable digital payments in minutes.</p>
    </div>
    <div class="steps-grid">
        <div class="step-card reveal">
            <div class="step-num">1</div>
            <h3>Create an Account</h3>
            <p>Sign up in under 60 seconds. Provide your name, email, and phone number — that's all you need to get started.</p>
        </div>
        <div class="step-card reveal reveal-delay-1">
            <div class="step-num">2</div>
            <h3>Fund Your Wallet</h3>
            <p>Top up instantly via Paystack, Monnify, or bank transfer. Your balance is available immediately.</p>
        </div>
        <div class="step-card reveal reveal-delay-2">
            <div class="step-num">3</div>
            <h3>Buy Any Service</h3>
            <p>Select a service, enter the details, and confirm. Delivery is instant — airtime, data, electricity tokens, and more.</p>
        </div>
    </div>
</div>
</div>

<!-- ══════════ WHY CHOOSE US ══════════ -->
<div class="container" style="padding:80px 32px 20px;">
    <div class="section-header reveal">
        <span class="section-eyebrow">Our edge</span>
        <h2>Why thousands choose us</h2>
    </div>
    <div class="why-vtu">
        <div class="why-card reveal">
            <i class="fas fa-rocket"></i>
            <h3>Instant &amp; Reliable</h3>
            <p>We aggregate the best discounts across MTN, Glo, Airtel, and 9mobile. With real-time wallet funding and 24/7 automated delivery, you never have to wait. 99.9% uptime guaranteed.</p>
        </div>
        <div class="why-card reveal reveal-delay-1">
            <i class="fas fa-shield-alt"></i>
            <h3>Secure &amp; Trusted</h3>
            <p>Industry-standard encryption protects every transaction. We partner with major providers for electricity (IKEDC, EKEDC, KEDCO) and TV (DSTV, GOtv, StarTimes). No hidden charges — ever.</p>
        </div>
        <div class="why-card reveal reveal-delay-2">
            <i class="fas fa-chart-line"></i>
            <h3>Earn as You Grow</h3>
            <p>Become an agent and earn commissions on every transaction. Our reseller programme lets you build a business selling airtime, data, and bills with full automation support.</p>
        </div>
    </div>
</div>

<!-- ══════════ TESTIMONIALS ══════════ -->
<div style="background:#f0f6ff; padding:80px 0; margin-top:40px;">
<div class="container">
    <div class="section-header reveal">
        <span class="section-eyebrow">What users say</span>
        <h2>Loved by customers</h2>
        <p>Join tens of thousands who rely on us every day for seamless digital payments.</p>
    </div>
    <div class="testimonials-grid">
        <div class="testimonial-card reveal">
            <div class="stars">★★★★★</div>
            <blockquote>"Fastest airtime top-up I've ever used. The instant delivery is just incredible — I've never had a failed transaction in over 6 months."</blockquote>
            <div class="testimonial-author">
                <div class="author-avatar" style="background:#1e3a8a;">AK</div>
                <div><div class="author-name">Adaeze K.</div><div class="author-role">Regular User, Lagos</div></div>
            </div>
        </div>
        <div class="testimonial-card reveal reveal-delay-1">
            <div class="stars">★★★★★</div>
            <blockquote>"I run a data reselling business and this platform cut my costs by 30%. The agent pricing and instant commission system is unmatched."</blockquote>
            <div class="testimonial-author">
                <div class="author-avatar" style="background:#0891b2;">TM</div>
                <div><div class="author-name">Tunde M.</div><div class="author-role">Data Reseller, Abuja</div></div>
            </div>
        </div>
        <div class="testimonial-card reveal reveal-delay-2">
            <div class="stars">★★★★★</div>
            <blockquote>"The electricity token delivery is real-time. I've been paying my IKEDC bills here for over a year — zero stress, always instant."</blockquote>
            <div class="testimonial-author">
                <div class="author-avatar" style="background:#059669;">FN</div>
                <div><div class="author-name">Fatima N.</div><div class="author-role">Homeowner, Port Harcourt</div></div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ══════════ APP DOWNLOAD ══════════ -->
<div class="container" id="download" style="padding:80px 32px;">
    <div class="app-download-section reveal">
        <div class="app-info">
            <h2>Take us everywhere<br>with the mobile app</h2>
            <p>Download our Android app for the ultimate experience. Push notifications for transactions, biometric login, and offline balance viewing — all in your pocket.</p>
            <div class="app-badges">
                <?php if (!empty($apk_download_url)): ?>
                <a href="<?php echo htmlspecialchars($apk_download_url); ?>" class="app-badge" target="_blank" rel="noopener">
                    <i class="fab fa-android"></i>
                    <div><span class="app-badge-sub">Download APK</span><span class="app-badge-main">Android App</span></div>
                </a>
                <?php endif; ?>
                <a href="#" class="app-badge" onclick="if(window.deferredPWAPrompt){installPWA();}else{alert('Add this page to your home screen via your browser menu.');}return false;">
                    <i class="fas fa-mobile-alt"></i>
                    <div><span class="app-badge-sub">Add to home screen</span><span class="app-badge-main">Install PWA</span></div>
                </a>
            </div>
        </div>
        <div class="app-img">
            <img src="<?php echo $hero_image; ?>" alt="App screenshot">
        </div>
    </div>
</div>

<!-- ══════════ ABOUT (accordion) ══════════ -->
<div class="container" id="about" style="padding:0 32px 40px;">
    <div class="accordion-block reveal">
        <div class="accordion-item active" id="aboutAccordion">
            <div class="accordion-header" onclick="toggleAccordion('aboutAccordion')">
                <span><i class="fas fa-address-card" style="margin-right:12px;color:#1e3a8a;"></i> About Us</span>
                <i class="fas fa-plus-circle"></i>
            </div>
            <div class="accordion-content">
                <div class="about-text">
                    <p><strong><?php echo htmlspecialchars($site_title); ?></strong> is a next-gen digital services platform built for the Nigerian VTU market. We connect you to essential daily utilities — airtime, data, electricity, cable TV, exam pins, and even card printing — all in one unified interface. Our mission: simplify payments through innovation and reliability.</p>
                    <p>We serve thousands of users across Nigeria and are continuously expanding. Our platform is secure, fast, and built with love by developers who understand the local market. 🚀</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════ FAQ ══════════ -->
<div style="padding:60px 0;">
<div class="container">
    <div class="section-header reveal">
        <span class="section-eyebrow">FAQ</span>
        <h2>Frequently Asked Questions</h2>
        <p>Everything you need to know before getting started.</p>
    </div>
    <div class="faq-list">
        <div class="faq-item reveal">
            <div class="faq-q" onclick="toggleFAQ(this)">How do I fund my wallet?<i class="fas fa-plus"></i></div>
            <div class="faq-a">You can fund your wallet instantly using Paystack (card or bank transfer), Monnify, or any of our other supported payment gateways. Simply navigate to <strong>Fund Wallet</strong> in your dashboard, enter the amount, choose your preferred channel, and complete the payment. Your balance is credited immediately.</div>
        </div>
        <div class="faq-item reveal reveal-delay-1">
            <div class="faq-q" onclick="toggleFAQ(this)">How long does airtime / data delivery take?<i class="fas fa-plus"></i></div>
            <div class="faq-a">Delivery is instant — typically within a few seconds. In rare cases during network congestion, delivery can take up to 5 minutes. If a transaction takes longer than 10 minutes, use the <strong>Requery</strong> feature in your transaction history to check the status and trigger a re-delivery.</div>
        </div>
        <div class="faq-item reveal reveal-delay-2">
            <div class="faq-q" onclick="toggleFAQ(this)">Can I become a reseller / agent?<i class="fas fa-plus"></i></div>
            <div class="faq-a">Yes! We have a full reseller programme. After registering as a standard user, you can upgrade your account level to <strong>Agent</strong> or <strong>API</strong> to access discounted prices and higher transaction limits. Contact support to learn more about the upgrade process.</div>
        </div>
        <div class="faq-item reveal">
            <div class="faq-q" onclick="toggleFAQ(this)">Is my personal data secure?<i class="fas fa-plus"></i></div>
            <div class="faq-a">Absolutely. We use TLS encryption for all data in transit. Passwords are hashed and never stored in plain text. Payment credentials are tokenised and processed by PCI-DSS compliant gateways — we never see or store your card details.</div>
        </div>
        <div class="faq-item reveal reveal-delay-1">
            <div class="faq-q" onclick="toggleFAQ(this)">What if I make a wrong transaction?<i class="fas fa-plus"></i></div>
            <div class="faq-a">Please double-check all details before confirming, as completed VTU transactions cannot be reversed by the network operator. If the transaction fails or you were charged but nothing was delivered, the amount will be reversed to your wallet automatically within minutes. Contact support if it doesn't resolve.</div>
        </div>
    </div>
</div>
</div>

<!-- ══════════ PRIVACY TEASER ══════════ -->
<div class="container" id="privacy" style="padding:0 32px 60px;">
    <div class="privacy-static reveal" style="text-align:center;">
        <h2><i class="fas fa-lock" style="color:#1e3a8a;"></i> Your Privacy Matters</h2>
        <div class="privacy-text">
            <p><strong>Your data belongs to you.</strong> We collect only the information needed to process your transactions securely. We never sell your personal data.</p>
            <p>We are fully committed to compliance with the <strong>Nigeria Data Protection Act 2023</strong>, CBN KYC/AML guidelines, and global best practices.</p>
            <p style="margin-top:14px;">
                <a href="web/policy/privacy.php" style="color:#1e3a8a;font-weight:700;text-decoration:underline;">Read our full Privacy Policy →</a>
            </p>
        </div>
    </div>
</div>

<!-- ══════════ CTA BANNER ══════════ -->
<div class="container" style="padding:0 32px 80px;">
    <div class="cta-banner reveal">
        <h2>One platform — infinite possibilities</h2>
        <p>Join 50K+ Nigerians already using <?php echo htmlspecialchars($site_title); ?> for fast, reliable digital payments.</p>
        <a href="web/Register.php" class="btn btn-primary"><i class="fas fa-bolt"></i> Create free account</a>
    </div>
</div>

<div id="contact" class="owner-note"></div>

</main>

<div id="networkStatus" class="network-status"></div>

<div id="pwaInstallModal" class="pwa-modal">
    <div class="pwa-content">
        <div class="pwa-icon">
            <img src="<?php echo $site_logo; ?>" alt="App Icon">
        </div>
        <div class="pwa-info">
            <h4>Install our App</h4>
            <p>Get the best experience on your home screen.</p>
        </div>
        <div class="pwa-actions">
            <button class="btn-install" onclick="installPWA()">Install</button>
            <button class="btn-close-pwa" onclick="closeInstallModal()"><i class="fas fa-times"></i></button>
        </div>
    </div>
</div>

<!-- ══════════ FOOTER ══════════ -->
<footer>
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <img src="<?php echo $site_logo; ?>" alt="<?php echo htmlspecialchars($site_title); ?>">
                <p>Your all-in-one VTU platform for instant airtime, data, electricity, cable TV, and more. Fast, secure, and available 24/7.</p>
                <div class="footer-social">
                    <a href="<?php echo $facebook_share; ?>" target="_blank" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="<?php echo $twitter_share; ?>" target="_blank" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="<?php echo $linkedin_share; ?>" target="_blank" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
            <div class="footer-col">
                <h5>Company</h5>
                <a href="#about">About Us</a>
                <a href="blog.php">Blog</a>
                <a href="web/Pricing.php">Service Pricing</a>
                <a href="web/APIDocs.php">API Docs</a>
                <a href="web/policy/terms.php">Terms of Service</a>
                <a href="web/policy/privacy.php">Privacy Policy</a>
            </div>
            <div class="footer-col">
                <h5>Services</h5>
                <a href="web/Login.php">Buy Airtime</a>
                <a href="web/Login.php">Buy Data</a>
                <a href="web/Login.php">Pay Electricity</a>
                <a href="web/Login.php">Cable TV</a>
                <a href="web/Login.php">Crypto Hub</a>
                <a href="web/Login.php">Gift Cards</a>
            </div>
            <div class="footer-col">
                <h5>Legal</h5>
                <a href="web/policy/aml.php">AML Policy</a>
                <a href="web/policy/kyc.php">KYC Policy</a>
                <a href="web/policy/refund.php">Refund Policy</a>
                <a href="web/policy/risk-disclosure.php">Risk Disclosure</a>
            </div>
        </div>
        <div class="footer-bottom">
            <div class="footer-copyright">© <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_title); ?> — VTU &amp; Digital Payments. All rights reserved.</div>
            <div class="footer-badges">
                <span><i class="fas fa-shield-alt"></i> Secure Platform</span>
                <span>MTN • Glo • Airtel • 9mobile</span>
                <span>DSTV • GOtv • WAEC • NECO</span>
            </div>
        </div>
    </div>
</footer>

<!-- GDPR COOKIE CONSENT BANNER -->
<div id="cookieBanner" style="display:none; position:fixed; bottom:0; left:0; right:0; z-index:9999;
    background:rgba(11,27,51,0.97); color:#e2e8f0; padding:20px 28px;
    box-shadow:0 -4px 24px rgba(0,0,0,0.4); backdrop-filter:blur(8px);
    font-family:'Inter',sans-serif; animation:slideUpCookie 0.4s ease;">
    <style>
        @keyframes slideUpCookie { from { transform:translateY(100%); opacity:0; } to { transform:translateY(0); opacity:1; } }
        #cookieBanner a { color:#93c5fd; text-decoration:underline; }
        #cookieBanner .cookie-inner { max-width:1100px; margin:0 auto; display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
        #cookieBanner p { font-size:0.9rem; flex:1; min-width:220px; line-height:1.6; }
        #cookieBanner .cookie-btns { display:flex; gap:10px; flex-shrink:0; }
        #cookieBanner .btn-accept { background:#1e3a8a; color:white; border:none; padding:10px 24px; border-radius:6px; font-weight:700; cursor:pointer; font-size:0.88rem; transition:background 0.2s; }
        #cookieBanner .btn-accept:hover { background:#1d4ed8; }
        #cookieBanner .btn-decline { background:transparent; color:#94a3b8; border:1px solid #334155; padding:10px 18px; border-radius:6px; cursor:pointer; font-size:0.88rem; transition:all 0.2s; }
        #cookieBanner .btn-decline:hover { border-color:#94a3b8; color:#e2e8f0; }
    </style>
    <div class="cookie-inner">
        <p><i class="fas fa-cookie-bite" style="color:#fbbf24;margin-right:6px;"></i> We use cookies to enhance your experience, analyse traffic, and for security purposes. By continuing to use our platform you agree to our use of essential cookies. <a href="web/policy/privacy.php#cookies">Learn more</a></p>
        <div class="cookie-btns">
            <button class="btn-accept" onclick="acceptCookies()">Accept &amp; Continue</button>
            <button class="btn-decline" onclick="declineCookies()">Decline</button>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
    /* ── Sticky header scroll effect ── */
    const siteHeader = document.getElementById('siteHeader');
    window.addEventListener('scroll', () => {
        siteHeader.classList.toggle('scrolled', window.scrollY > 10);
    }, { passive: true });

    /* ── Mobile nav ── */
    function toggleNav() { document.getElementById('mainNav').classList.toggle('active'); }
    function closeNav()  { document.getElementById('mainNav').classList.remove('active'); }


    /* ── About accordion ── */
    function toggleAccordion(id) { document.getElementById(id).classList.toggle('active'); }
    window.addEventListener('load', () => document.getElementById('aboutAccordion').classList.add('active'));

    /* ── FAQ accordion ── */
    function toggleFAQ(header) {
        const item   = header.parentElement;
        const isOpen = item.classList.contains('open');
        document.querySelectorAll('.faq-item.open').forEach(i => i.classList.remove('open'));
        if (!isOpen) item.classList.add('open');
    }

    /* ── Animated counters ── */
    function animateCounter(el) {
        const target = parseInt(el.dataset.target, 10);
        const suffix = el.dataset.suffix || '';
        if (isNaN(target)) return;
        const duration = 1800, step = 16;
        const increment = target / (duration / step);
        let current = 0;
        const timer = setInterval(() => {
            current = Math.min(current + increment, target);
            let display = Math.floor(current);
            if (target >= 1000000) display = (display / 1000000).toFixed(1) + 'M+';
            else if (target >= 1000) display = (display / 1000).toFixed(0) + 'K+';
            el.textContent = display + suffix;
            if (current >= target) clearInterval(timer);
        }, step);
    }

    /* ── Scroll-reveal via IntersectionObserver ── */
    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('visible');
            revealObserver.unobserve(entry.target);
        });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));

    /* ── Trigger stat counters when stats bar enters viewport ── */
    const statsBar = document.querySelector('.stats-bar');
    if (statsBar) {
        new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                entry.target.querySelectorAll('[data-target]').forEach(el => {
                    if (!el.dataset.animated) { el.dataset.animated = '1'; animateCounter(el); }
                });
                entry.target.querySelectorAll('.reveal').forEach(el => el.classList.add('visible'));
            });
        }, { threshold: 0.3 }).observe(statsBar);
    }

    /* ── GDPR Cookie Consent ── */
    function acceptCookies() {
        localStorage.setItem('cookie_consent', 'accepted');
        document.getElementById('cookieBanner').style.display = 'none';
    }
    function declineCookies() {
        localStorage.setItem('cookie_consent', 'declined');
        document.getElementById('cookieBanner').style.display = 'none';
    }
    (function() {
        if (!localStorage.getItem('cookie_consent')) {
            setTimeout(function() {
                document.getElementById('cookieBanner').style.display = 'block';
            }, 1500);
        }
    })();
</script>
<script src="asset/pwa-handler.js"></script>
<?php seo_render_custom_footer($site_details); ?>
</body>
</html>
