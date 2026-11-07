<?php
/**
 * Shared header/branding loader for policy pages.
 * Included at the top of each policy page.
 */
if (!isset($policy_title)) $policy_title = 'Policy';

// Resolve site branding (works whether called via index.php or directly)
$_policy_site_title = 'Our Platform';
$_policy_site_logo  = '';
$_policy_apk_url    = '';

if (isset($connection_server)) {
    $vid = null;
    if (isset($vendor_account_details['id'])) {
        $vid = (int)$vendor_account_details['id'];
    } else {
        // Resolve vendor from host
        $host = preg_replace('/^www\./', '', strtolower($_SERVER['HTTP_HOST']));
        $stmt = mysqli_prepare($connection_server, "SELECT id FROM sas_vendors WHERE website_url=? AND status=1 LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $host);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($r)) $vid = (int)$row['id'];
        mysqli_stmt_close($stmt);
    }
    if ($vid) {
        $stmt2 = mysqli_prepare($connection_server, "SELECT site_title, apk_download_url FROM sas_site_details WHERE vendor_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt2, "i", $vid);
        mysqli_stmt_execute($stmt2);
        $r2 = mysqli_stmt_get_result($stmt2);
        if ($row2 = mysqli_fetch_assoc($r2)) {
            $_policy_site_title = $row2['site_title'];
            $_policy_apk_url    = $row2['apk_download_url'] ?? '';
        }
        mysqli_stmt_close($stmt2);
    }
}

// Logo
$_policy_host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST']);
$_policy_logo_file = dirname(__DIR__, 2) . '/uploaded-image/' . str_replace(['.',':'],'-', $_policy_host) . '_logo.png';
if (file_exists($_policy_logo_file)) {
    $_policy_site_logo = '../../uploaded-image/' . str_replace(['.',':'],'-', $_policy_host) . '_logo.png';
}
$_policy_base_url = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($policy_title); ?> — <?php echo htmlspecialchars($_policy_site_title); ?></title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <?php if (!empty($_policy_site_logo)): ?>
    <link rel="icon" type="image/png" href="<?php echo $_policy_site_logo; ?>">
    <?php endif; ?>
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        html { scroll-behavior:smooth; }
        body { font-family:'Inter',sans-serif; background:#f8fafc; color:#1e293b; line-height:1.7; }
        .policy-nav {
            background:#0b2b4a; padding:16px 0;
            position:sticky; top:0; z-index:100;
        }
        .policy-nav-inner {
            max-width:1080px; margin:0 auto; padding:0 24px;
            display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
        }
        .policy-nav .logo img { height:36px; }
        .policy-nav .logo span { color:white; font-size:1.1rem; font-weight:700; }
        .policy-nav-back a {
            color:rgba(255,255,255,0.8); text-decoration:none; font-size:0.9rem;
            display:inline-flex; align-items:center; gap:6px; transition:color 0.2s;
        }
        .policy-nav-back a:hover { color:white; }
        .policy-wrap { max-width:860px; margin:0 auto; padding:52px 24px 80px; }
        .policy-wrap h1 { font-size:2rem; font-weight:800; color:#0b2b4a; margin-bottom:6px; }
        .policy-wrap .last-updated { color:#64748b; font-size:0.85rem; margin-bottom:36px; }
        .policy-wrap h2 { font-size:1.2rem; font-weight:700; color:#0b2b4a; margin:32px 0 10px; }
        .policy-wrap p, .policy-wrap li { color:#334155; margin-bottom:12px; font-size:0.97rem; }
        .policy-wrap ul, .policy-wrap ol { padding-left:22px; margin-bottom:16px; }
        .policy-wrap li { margin-bottom:6px; }
        .policy-wrap .notice-box {
            background:#eff6ff; border-left:4px solid #1e3a8a; padding:16px 20px;
            border-radius:0 12px 12px 0; margin:20px 0; font-size:0.95rem; color:#1e3a8a;
        }
        .policy-footer { background:#0b1e33; color:#94a3b8; text-align:center; padding:28px 24px; font-size:0.85rem; }
        .policy-footer a { color:#cbd5e1; text-decoration:none; margin:0 8px; }
        .policy-footer a:hover { color:white; }
    </style>
</head>
<body>
<nav class="policy-nav">
    <div class="policy-nav-inner">
        <div class="logo">
            <?php if (!empty($_policy_site_logo)): ?>
            <a href="<?php echo $_policy_base_url; ?>"><img src="<?php echo $_policy_site_logo; ?>" alt="<?php echo htmlspecialchars($_policy_site_title); ?>"></a>
            <?php else: ?>
            <a href="<?php echo $_policy_base_url; ?>" style="text-decoration:none;"><span><?php echo htmlspecialchars($_policy_site_title); ?></span></a>
            <?php endif; ?>
        </div>
        <div class="policy-nav-back">
            <a href="<?php echo $_policy_base_url; ?>"><i class="fas fa-arrow-left"></i> Back to Home</a>
        </div>
    </div>
</nav>
<div class="policy-wrap">
    <h1><?php echo htmlspecialchars($policy_title); ?></h1>
    <div class="last-updated">Last updated: <?php echo date('F d, Y'); ?></div>
