<?php
session_start();

// ─── CSRF Token Generation ──────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ─── Security Headers ───────────────────────────────────────────────────────
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; script-src 'self' 'unsafe-inline';");

// ─── Constants ────────────────────────────────────────────────────────────────
define('APK_FILENAME',   'datagifting.v684.apk');
define('APK_VERSION',    '6.8.4');
define('APK_MIN_ANDROID', 'Android 7+');

// ─── Safe Path Construction ─────────────────────────────────────────────────
$apk_filename = APK_FILENAME;
$base_dir = __DIR__;
$apk_path = realpath($base_dir . DIRECTORY_SEPARATOR . $apk_filename);

// Ensure the file is within the base directory
if ($apk_path === false || strpos($apk_path, realpath($base_dir)) !== 0) {
    $apk_exists = false;
    $apk_size = '';
} else {
    $apk_exists = file_exists($apk_path);
    $apk_size = $apk_exists ? round(filesize($apk_path) / (1024 * 1024), 1) . ' MB' : '';
}
$apk_version    = APK_VERSION;

// Atomically read the current count using an exclusive lock
$download_count = 0;
$counter_file = __DIR__ . '/download_count.txt';
$fh = fopen($counter_file, 'c+');
if ($fh) {
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    $download_count = (int) $raw;
    flock($fh, LOCK_UN);
    fclose($fh);
}

// Handle download request
if (isset($_GET['dl']) && $_GET['dl'] === '1' && $apk_exists) {
    // Validate CSRF token
    if (!isset($_GET['token']) || !hash_equals($_SESSION['csrf_token'], $_GET['token'])) {
        http_response_code(403);
        die('Invalid or missing security token.');
    }
    
    // Optional: Check Referer as secondary measure
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $referer_host = parse_url($referer, PHP_URL_HOST);
    if ($referer_host && !str_contains($referer_host, $_SERVER['HTTP_HOST'])) {
        http_response_code(403);
        die('Invalid request origin.');
    }

    $counter_file = __DIR__ . '/download_count.txt';
    // Atomically increment counter with an exclusive lock
    $fh = fopen($counter_file, 'c+');
    if ($fh) {
        flock($fh, LOCK_EX);
        $current = (int) stream_get_contents($fh);
        fseek($fh, 0);
        ftruncate($fh, 0);
        fwrite($fh, $current + 1);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    // Serve the file
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="' . $apk_filename . '"');
    header('Content-Length: ' . filesize($apk_path));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($apk_path);
    exit;
}

// Format download count nicely
function fmt_count(int $n): string {
    if ($n >= 1000000) return number_format($n / 1000000, 1) . 'M+';
    if ($n >= 1000)    return number_format($n / 1000, 1)    . 'K+';
    return number_format($n);
}
$fmt_count = fmt_count($download_count);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download DataGifting App – Digital Payments Made Simple</title>
    <meta name="description" content="Download the DataGifting Android app. Buy airtime, data, electricity, cable TV and more – all in one app.">
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800;14..32,900&display=swap" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --brand:        #1e3a8a;
            --brand-light:  #2563eb;
            --brand-dark:   #0f172a;
            --accent:       #22d3ee;
            --green:        #22c55e;
            --bg:           #f0f4ff;
            --surface:      #ffffff;
            --text:         #0f172a;
            --muted:        #64748b;
            --border:       #e2e8f0;
            --radius:       20px;
            --shadow-lg:    0 20px 60px -10px rgba(30,58,138,.22);
            --shadow-sm:    0 4px 16px rgba(0,0,0,.06);
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* ── Ticker bar ── */
        .ticker-bar {
            background: var(--brand-dark);
            color: #94a3b8;
            font-size: .78rem;
            padding: 8px 0;
            overflow: hidden;
            white-space: nowrap;
        }
        .ticker-inner {
            display: inline-block;
            animation: ticker 28s linear infinite;
        }
        .ticker-inner span { margin: 0 48px; }
        @keyframes ticker { from { transform: translateX(100vw); } to { transform: translateX(-100%); } }

        /* ── Top bar ── */
        .top-bar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }
        .top-bar-inner {
            max-width: 1140px;
            margin: 0 auto;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .logo-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        .logo-icon {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--brand), var(--brand-light));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
            flex-shrink: 0;
            box-shadow: 0 6px 16px rgba(30,58,138,.35);
        }
        .logo-text {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--brand-dark);
            letter-spacing: -.3px;
        }
        .logo-text span { color: var(--brand-light); }
        .back-link {
            color: var(--brand-light);
            font-size: .9rem;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: .2s;
        }
        .back-link:hover { color: var(--brand); }

        /* ── Container ── */
        .container {
            max-width: 1140px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* ── Hero Section ── */
        .hero {
            background: linear-gradient(135deg, #0b1e3a 0%, #1e3a8a 55%, #1d4ed8 100%);
            padding: 72px 20px 80px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Ccircle cx='30' cy='30' r='1.5' fill='white' fill-opacity='.06'/%3E%3C/svg%3E");
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            color: #bae6fd;
            padding: 6px 16px;
            border-radius: 100px;
            font-size: .82rem;
            font-weight: 600;
            letter-spacing: .4px;
            margin-bottom: 24px;
        }
        .hero-app-icon {
            width: 110px;
            height: 110px;
            background: linear-gradient(135deg, #1d4ed8, var(--accent));
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 28px;
            font-size: 3.2rem;
            color: white;
            box-shadow: 0 20px 40px rgba(0,0,0,.35);
        }
        .hero h1 {
            font-size: clamp(2.2rem, 5vw, 3.4rem);
            font-weight: 900;
            color: white;
            letter-spacing: -1px;
            margin-bottom: 14px;
        }
        .hero h1 span { color: var(--accent); }
        .hero-sub {
            font-size: 1.1rem;
            color: #93c5fd;
            max-width: 560px;
            margin: 0 auto 40px;
        }

        /* ── Download button ── */
        .download-btn-wrap { position: relative; display: inline-block; }
        .download-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            background: linear-gradient(135deg, var(--green) 0%, #16a34a 100%);
            color: white;
            text-decoration: none;
            font-size: 1.35rem;
            font-weight: 800;
            padding: 22px 52px;
            border-radius: 100px;
            box-shadow: 0 18px 40px -8px rgba(34,197,94,.55);
            transition: transform .2s, box-shadow .2s;
            letter-spacing: -.2px;
            min-width: 320px;
            border: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        .download-btn::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(255,255,255,.15) 0%, transparent 60%);
            border-radius: inherit;
        }
        .download-btn:hover {
            transform: translateY(-4px) scale(1.03);
            box-shadow: 0 28px 50px -10px rgba(34,197,94,.65);
        }
        .download-btn:active { transform: translateY(-1px) scale(1.01); }
        .download-btn .btn-icon { font-size: 1.7rem; }
        .download-btn .btn-texts { text-align: left; }
        .download-btn .btn-main { display: block; font-size: 1.2rem; line-height: 1.1; }
        .download-btn .btn-sub  { display: block; font-size: .75rem; font-weight: 500; opacity: .85; }
        .download-pulse {
            position: absolute;
            inset: -6px;
            border-radius: 110px;
            border: 3px solid var(--green);
            animation: pulse 1.8s ease-out infinite;
            opacity: 0;
        }
        @keyframes pulse {
            0%   { opacity: .7; transform: scale(1); }
            100% { opacity: 0;  transform: scale(1.12); }
        }

        .apk-meta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-top: 22px;
            flex-wrap: wrap;
        }
        .apk-chip {
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.18);
            color: #e0f2fe;
            border-radius: 100px;
            padding: 5px 14px;
            font-size: .82rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ── Stats row ── */
        .stats-row {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 48px;
        }
        .stat-card {
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.15);
            backdrop-filter: blur(10px);
            border-radius: var(--radius);
            padding: 20px 28px;
            text-align: center;
            min-width: 130px;
        }
        .stat-number {
            font-size: 1.9rem;
            font-weight: 900;
            color: white;
            display: block;
        }
        .stat-label {
            font-size: .78rem;
            color: #93c5fd;
            font-weight: 600;
            letter-spacing: .3px;
            margin-top: 4px;
        }

        /* ── Section titles ── */
        .section-head {
            text-align: center;
            margin: 72px 0 40px;
        }
        .section-eyebrow {
            display: inline-block;
            background: #eff6ff;
            color: var(--brand-light);
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 5px 14px;
            border-radius: 100px;
            margin-bottom: 14px;
        }
        .section-head h2 {
            font-size: clamp(1.7rem, 3vw, 2.4rem);
            font-weight: 800;
            color: var(--brand-dark);
            letter-spacing: -.5px;
        }
        .section-head p {
            color: var(--muted);
            max-width: 560px;
            margin: 10px auto 0;
        }

        /* ── Services grid ── */
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 20px;
        }
        .service-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px 24px;
            text-align: center;
            transition: transform .2s, box-shadow .2s, border-color .2s;
            position: relative;
            overflow: hidden;
        }
        .service-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--brand), var(--brand-light));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform .25s;
        }
        .service-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
            border-color: #bfdbfe;
        }
        .service-card:hover::before { transform: scaleX(1); }
        .svc-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--brand-light);
            margin: 0 auto 16px;
            transition: .2s;
        }
        .service-card:hover .svc-icon {
            background: linear-gradient(135deg, var(--brand), var(--brand-light));
            color: white;
        }
        .service-card h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--brand-dark);
            margin-bottom: 6px;
        }
        .service-card p {
            font-size: .875rem;
            color: var(--muted);
        }

        /* ── How it works ── */
        .how-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 24px;
        }
        .how-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 32px 24px;
            text-align: center;
            position: relative;
        }
        .how-num {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--brand), var(--brand-light));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 800;
            font-size: 1.1rem;
            margin: 0 auto 18px;
        }
        .how-card h3 { font-size: 1rem; font-weight: 700; color: var(--brand-dark); margin-bottom: 8px; }
        .how-card p  { font-size: .875rem; color: var(--muted); }

        /* ── Features ── */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
            gap: 20px;
        }
        .feature-item {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            transition: box-shadow .2s;
        }
        .feature-item:hover { box-shadow: var(--shadow-sm); }
        .feature-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
        }
        .fi-blue   { background: linear-gradient(135deg,#1d4ed8,#3b82f6); }
        .fi-green  { background: linear-gradient(135deg,#059669,#34d399); }
        .fi-purple { background: linear-gradient(135deg,#7c3aed,#a78bfa); }
        .fi-orange { background: linear-gradient(135deg,#d97706,#fbbf24); }
        .fi-red    { background: linear-gradient(135deg,#dc2626,#f87171); }
        .fi-cyan   { background: linear-gradient(135deg,#0891b2,#22d3ee); }
        .feature-text h4 { font-size: .95rem; font-weight: 700; color: var(--brand-dark); margin-bottom: 4px; }
        .feature-text p  { font-size: .84rem; color: var(--muted); }

        /* ── CTA band ── */
        .cta-band {
            background: linear-gradient(135deg, var(--brand-dark) 0%, var(--brand) 60%, #1d4ed8 100%);
            border-radius: 28px;
            padding: 60px 32px;
            text-align: center;
            margin: 64px 0 80px;
            position: relative;
            overflow: hidden;
        }
        .cta-band::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Ccircle cx='30' cy='30' r='1.5' fill='white' fill-opacity='.05'/%3E%3C/svg%3E");
        }
        .cta-band h2 { font-size: clamp(1.6rem, 3.5vw, 2.4rem); font-weight: 800; color: white; margin-bottom: 12px; }
        .cta-band p  { color: #93c5fd; margin-bottom: 36px; font-size: 1.05rem; }
        .cta-dl-btn {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            background: linear-gradient(135deg, var(--green), #16a34a);
            color: white;
            text-decoration: none;
            padding: 20px 52px;
            border-radius: 100px;
            font-weight: 800;
            font-size: 1.2rem;
            box-shadow: 0 14px 35px -6px rgba(34,197,94,.5);
            transition: transform .2s, box-shadow .2s;
            border: none;
            cursor: pointer;
        }
        .cta-dl-btn:hover {
            transform: translateY(-4px);
            box-shadow: 0 22px 45px -8px rgba(34,197,94,.65);
        }

        /* ── Testimonials ── */
        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
        }
        .testimonial-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px 24px;
        }
        .testimonial-stars { color: #f59e0b; margin-bottom: 12px; font-size: .9rem; }
        .testimonial-text  { font-size: .9rem; color: var(--muted); font-style: italic; margin-bottom: 16px; }
        .testimonial-author { display: flex; align-items: center; gap: 12px; }
        .author-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg, var(--brand), var(--brand-light));
            color: white; font-weight: 700; font-size: 1rem;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .author-name  { font-weight: 700; font-size: .88rem; color: var(--brand-dark); }
        .author-role  { font-size: .78rem; color: var(--muted); }

        /* ── Download count hero widget ── */
        .dl-counter-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(34,197,94,.18);
            border: 1px solid rgba(34,197,94,.35);
            color: #86efac;
            border-radius: 100px;
            padding: 8px 20px;
            font-size: .9rem;
            font-weight: 700;
            margin-top: 18px;
        }
        .dl-counter-badge .dl-num { color: white; font-size: 1.05rem; }

        /* ── Footer ── */
        footer {
            background: var(--brand-dark);
            color: #94a3b8;
            padding: 48px 20px 32px;
            text-align: center;
        }
        .footer-logo { font-size: 1.4rem; font-weight: 800; color: white; margin-bottom: 8px; }
        .footer-logo span { color: var(--accent); }
        .footer-tagline { font-size: .9rem; margin-bottom: 28px; }
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 24px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .footer-links a { color: #cbd5e1; text-decoration: none; font-size: .9rem; transition: color .2s; }
        .footer-links a:hover { color: white; }
        .footer-copy { font-size: .82rem; opacity: .65; }

        /* ── Responsive ── */
        @media (max-width: 640px) {
            .hero { padding: 52px 16px 60px; }
            .hero-app-icon { width: 88px; height: 88px; font-size: 2.6rem; border-radius: 22px; }
            .download-btn { min-width: unset; padding: 18px 28px; font-size: 1.1rem; width: 100%; max-width: 380px; }
            .download-btn .btn-main { font-size: 1rem; }
            .stat-card { padding: 16px 20px; min-width: 110px; }
            .stat-number { font-size: 1.5rem; }
            .cta-band { padding: 44px 20px; }
            .cta-dl-btn { padding: 18px 32px; font-size: 1.05rem; }
        }
        @media (max-width: 480px) {
            .top-bar-inner { flex-wrap: wrap; gap: 8px; }
            .apk-meta { gap: 10px; }
            .stats-row { gap: 8px; }
        }
    </style>
</head>
<body>

<!-- Ticker -->
<div class="ticker-bar">
    <div class="ticker-inner">
        <span>⚡ Instant Airtime Top-Up</span>
        <span>📶 Fast Data Bundles – All Networks</span>
        <span>💡 Electricity Token in Seconds</span>
        <span>📺 DSTV · GOtv · StarTimes</span>
        <span>🎓 WAEC · NECO Exam Pins</span>
        <span>💳 Virtual Dollar &amp; Naira Cards</span>
        <span>₿ Crypto Hub – BTC · USDT</span>
        <span>🎁 Gift Cards – Trade &amp; Earn</span>
        <span>📨 Bulk SMS – High Delivery</span>
        <span>🖨️ Recharge &amp; Data Card Printing</span>
    </div>
</div>

<!-- Top bar -->
<div class="top-bar">
    <div class="top-bar-inner">
        <a href="../" class="logo-wrap">
            <div class="logo-icon"><i class="fas fa-bolt"></i></div>
            <span class="logo-text">Data<span>Gifting</span></span>
        </a>
        <a href="../" class="back-link"><i class="fas fa-arrow-left"></i> Back to Home</a>
    </div>
</div>

<!-- ─── HERO ─── -->
<section class="hero">
    <div class="container">
        <div class="hero-badge"><i class="fas fa-android"></i> Android App</div>

        <div class="hero-app-icon"><i class="fas fa-bolt"></i></div>

        <h1>Download <span>DataGifting</span></h1>
        <p class="hero-sub">Nigeria's all-in-one digital services app. Airtime, data, bills, crypto — all at your fingertips, anytime, anywhere.</p>

        <!-- Big download button -->
        <div class="download-btn-wrap">
            <div class="download-pulse"></div>
            <?php if ($apk_exists): ?>
            <a href="?dl=1&token=<?php echo $_SESSION['csrf_token']; ?>" class="download-btn" id="dlBtn">
                <span class="btn-icon"><i class="fas fa-download"></i></span>
                <span class="btn-texts">
                    <span class="btn-main">Download APK</span>
                    <span class="btn-sub">
                        v<?php echo $apk_version; ?><?php if ($apk_size): ?> &nbsp;•&nbsp; <?php echo $apk_size; ?><?php endif; ?> &nbsp;•&nbsp; Android 7+
                    </span>
                </span>
            </a>
            <?php else: ?>
            <span class="download-btn" style="background:linear-gradient(135deg,#475569,#64748b);cursor:default;box-shadow:none;">
                <span class="btn-icon"><i class="fas fa-clock"></i></span>
                <span class="btn-texts">
                    <span class="btn-main">Coming Soon</span>
                    <span class="btn-sub">APK will be available shortly</span>
                </span>
            </span>
            <?php endif; ?>
        </div>

        <!-- APK meta chips -->
        <div class="apk-meta">
            <span class="apk-chip"><i class="fas fa-shield-alt"></i> Safe &amp; Verified</span>
            <span class="apk-chip"><i class="fab fa-android"></i> Android 7.0+</span>
            <span class="apk-chip"><i class="fas fa-tag"></i> v<?php echo $apk_version; ?></span>
            <?php if ($apk_size): ?>
            <span class="apk-chip"><i class="fas fa-file"></i> <?php echo $apk_size; ?></span>
            <?php endif; ?>
        </div>

        <!-- Download counter -->
        <div>
            <div class="dl-counter-badge" id="dlCounterBadge">
                <i class="fas fa-download"></i>
                <span class="dl-num" id="dlCount"><?php echo $fmt_count; ?></span>
                <span>downloads</span>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <span class="stat-number">50K+</span>
                <div class="stat-label">Active Users</div>
            </div>
            <div class="stat-card">
                <span class="stat-number" id="statDl"><?php echo $fmt_count; ?></span>
                <div class="stat-label">Downloads</div>
            </div>
            <div class="stat-card">
                <span class="stat-number">12+</span>
                <div class="stat-label">Services</div>
            </div>
            <div class="stat-card">
                <span class="stat-number">99.9%</span>
                <div class="stat-label">Uptime</div>
            </div>
        </div>
    </div>
</section>

<!-- ─── SERVICES ─── -->
<div class="container">
    <div class="section-head">
        <span class="section-eyebrow">What's Inside</span>
        <h2>All the Services You Need, One App</h2>
        <p>DataGifting packs more than 12 digital services trusted by Nigerians every day.</p>
    </div>

    <div class="services-grid">

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-phone-alt"></i></div>
            <h3>Buy Airtime</h3>
            <p>Instant top-ups for MTN, Glo, Airtel &amp; 9mobile with exclusive discounts on every recharge.</p>
        </div>

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-wifi"></i></div>
            <h3>Buy Data Bundles</h3>
            <p>Affordable daily, weekly &amp; monthly data plans across all 4 major networks — delivered in seconds.</p>
        </div>

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-bolt"></i></div>
            <h3>Electricity Tokens</h3>
            <p>Pay prepaid &amp; postpaid electricity bills for IKEDC, EKEDC, KEDCO, AEDC and more — get token instantly.</p>
        </div>

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-tv"></i></div>
            <h3>Cable TV Renewal</h3>
            <p>Renew your DSTV, GOtv or StarTimes subscription in under 30 seconds, hassle-free.</p>
        </div>

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-university"></i></div>
            <h3>Wallet to Bank</h3>
            <p>Withdraw your wallet balance to any Nigerian bank account instantly with zero stress.</p>
        </div>

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-graduation-cap"></i></div>
            <h3>Exam Pins</h3>
            <p>Purchase WAEC, NECO &amp; NABTEB result checker e-pins at the best prices, always available.</p>
        </div>

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-comment-dots"></i></div>
            <h3>Bulk SMS</h3>
            <p>Send personalized bulk SMS with a custom Sender ID and enjoy high delivery rates nationwide.</p>
        </div>

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-print"></i></div>
            <h3>Recharge Card Printing</h3>
            <p>Start your own VTU business by printing MTN, Glo, Airtel &amp; 9mobile recharge e-pins at scale.</p>
        </div>

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-database"></i></div>
            <h3>Data Card Printing</h3>
            <p>Generate and print data bundle pins for all networks — great for resellers and businesses.</p>
        </div>

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-gift"></i></div>
            <h3>Gift Cards</h3>
            <p>Trade local &amp; international gift cards (Amazon, iTunes, Steam, etc.) instantly via our P2P platform.</p>
        </div>

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-credit-card"></i></div>
            <h3>Virtual Cards</h3>
            <p>Get a Dollar or Naira virtual card for seamless online payments on any global platform.</p>
        </div>

        <div class="service-card">
            <div class="svc-icon"><i class="fas fa-coins"></i></div>
            <h3>Crypto Hub</h3>
            <p>Send, receive, swap &amp; hold stable coins like BTC and USDT directly in the app.</p>
        </div>

    </div>

    <!-- ─── HOW IT WORKS ─── -->
    <div class="section-head">
        <span class="section-eyebrow">Get Started</span>
        <h2>Download &amp; Be Up in 3 Steps</h2>
        <p>Start transacting in under 2 minutes with zero technical know-how required.</p>
    </div>

    <div class="how-grid">
        <div class="how-card">
            <div class="how-num">1</div>
            <h3>Download the APK</h3>
            <p>Tap the green button above to download the latest DataGifting APK directly to your Android phone.</p>
        </div>
        <div class="how-card">
            <div class="how-num">2</div>
            <h3>Install &amp; Register</h3>
            <p>Allow installs from unknown sources, install the APK, open the app and create your free account in seconds.</p>
        </div>
        <div class="how-card">
            <div class="how-num">3</div>
            <h3>Fund &amp; Transact</h3>
            <p>Fund your wallet via card, bank transfer or automated gateway, then enjoy instant access to all 12+ services.</p>
        </div>
        <div class="how-card">
            <div class="how-num">4</div>
            <h3>Earn &amp; Grow</h3>
            <p>Refer friends, resell services, print recharge cards — and earn while you transact. Multiple income streams await.</p>
        </div>
    </div>

    <!-- ─── WHY CHOOSE US ─── -->
    <div class="section-head">
        <span class="section-eyebrow">Why DataGifting</span>
        <h2>Built for Speed, Trust &amp; Simplicity</h2>
        <p>Everything you need to know about the platform powering thousands of Nigerians daily.</p>
    </div>

    <div class="features-grid">
        <div class="feature-item">
            <div class="feature-icon fi-blue"><i class="fas fa-rocket"></i></div>
            <div class="feature-text">
                <h4>Lightning Fast</h4>
                <p>Transactions complete in under 5 seconds. No waiting, no delays — real-time delivery guaranteed.</p>
            </div>
        </div>
        <div class="feature-item">
            <div class="feature-icon fi-green"><i class="fas fa-shield-alt"></i></div>
            <div class="feature-text">
                <h4>Bank-Grade Security</h4>
                <p>Your funds and data are protected with industry-standard encryption, biometric auth and transaction PINs.</p>
            </div>
        </div>
        <div class="feature-item">
            <div class="feature-icon fi-purple"><i class="fas fa-percentage"></i></div>
            <div class="feature-text">
                <h4>Best Discounts</h4>
                <p>We aggregate the best rates across all operators so you always buy airtime &amp; data at the lowest price.</p>
            </div>
        </div>
        <div class="feature-item">
            <div class="feature-icon fi-orange"><i class="fas fa-headset"></i></div>
            <div class="feature-text">
                <h4>24/7 Support</h4>
                <p>Our dedicated support team is always available via live chat and email — round the clock.</p>
            </div>
        </div>
        <div class="feature-item">
            <div class="feature-icon fi-red"><i class="fas fa-wallet"></i></div>
            <div class="feature-text">
                <h4>Instant Wallet Funding</h4>
                <p>Fund your wallet instantly using Paystack, Monnify, Flutterwave, bank transfer or USSD — your choice.</p>
            </div>
        </div>
        <div class="feature-item">
            <div class="feature-icon fi-cyan"><i class="fas fa-plug"></i></div>
            <div class="feature-text">
                <h4>Developer API</h4>
                <p>Build on top of DataGifting with our REST API. Automate VTU, data &amp; bill payments in your own app.</p>
            </div>
        </div>
    </div>

    <!-- ─── TESTIMONIALS ─── -->
    <div class="section-head">
        <span class="section-eyebrow">User Reviews</span>
        <h2>What Our Users Are Saying</h2>
        <p>Join thousands of satisfied users across Nigeria.</p>
    </div>

    <div class="testimonials-grid">
        <div class="testimonial-card">
            <div class="testimonial-stars"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i></div>
            <p class="testimonial-text">"I've been using DataGifting for 6 months. Best VTU app I've ever used — fast, reliable and really cheap data plans!"</p>
            <div class="testimonial-author">
                <div class="author-avatar">A</div>
                <div>
                    <div class="author-name">Adaeze O.</div>
                    <div class="author-role">Lagos, Nigeria</div>
                </div>
            </div>
        </div>
        <div class="testimonial-card">
            <div class="testimonial-stars"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i></div>
            <p class="testimonial-text">"The recharge card printing feature is a game changer. I run a small VTU business and DataGifting cut my costs by 30%."</p>
            <div class="testimonial-author">
                <div class="author-avatar">E</div>
                <div>
                    <div class="author-name">Emeka C.</div>
                    <div class="author-role">Enugu, Nigeria</div>
                </div>
            </div>
        </div>
        <div class="testimonial-card">
            <div class="testimonial-stars"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i></div>
            <p class="testimonial-text">"Buying electricity token used to take forever. Now I get it under 10 seconds. The UI is clean and very easy to use."</p>
            <div class="testimonial-author">
                <div class="author-avatar">F</div>
                <div>
                    <div class="author-name">Fatima B.</div>
                    <div class="author-role">Abuja, Nigeria</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── CTA BAND ─── -->
    <div class="cta-band">
        <h2>Ready to Take Control of Your Bills?</h2>
        <p>Join 50,000+ Nigerians who save time and money with DataGifting every day.</p>
        <?php if ($apk_exists): ?>
        <a href="?dl=1" class="cta-dl-btn">
            <i class="fas fa-download"></i>
            Download DataGifting APK
        </a>
        <?php else: ?>
        <span class="cta-dl-btn" style="background:linear-gradient(135deg,#475569,#64748b);cursor:default;">
            <i class="fas fa-clock"></i> APK Coming Soon
        </span>
        <?php endif; ?>
    </div>

</div><!-- /container -->

<!-- ─── FOOTER ─── -->
<footer>
    <div class="footer-logo">Data<span>Gifting</span></div>
    <p class="footer-tagline">Nigeria's all-in-one digital payments platform</p>
    <div class="footer-links">
        <a href="../">Home</a>
        <a href="../web/Register.php">Register</a>
        <a href="../web/Login.php">Login</a>
        <a href="../web/APIDocs.php">API Docs</a>
        <a href="../blog.php">Blog</a>
    </div>
    <p class="footer-copy">© <?php echo date('Y'); ?> DataGifting — All rights reserved. &nbsp;|&nbsp; MTN · Glo · Airtel · 9mobile · DSTV · WAEC</p>
</footer>

<script>
    // Increment counter display when user clicks download
    var dlBtn = document.getElementById('dlBtn');
    if (dlBtn) {
        dlBtn.addEventListener('click', function() {
            var el = document.getElementById('dlCount');
            var st = document.getElementById('statDl');
            if (!el) return;
            var raw = <?php echo $download_count; ?> + 1;
            function fmt(n) {
                if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M+';
                if (n >= 1000)    return (n / 1000).toFixed(1)    + 'K+';
                return n.toLocaleString();
            }
            el.textContent = fmt(raw);
            if (st) st.textContent = fmt(raw);
        });
    }
</script>
</body>
</html>
