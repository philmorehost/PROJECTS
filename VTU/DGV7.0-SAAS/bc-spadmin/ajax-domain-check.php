<?php
session_start();
include("../func/bc-connect.php");
include_once("../func/bc-func.php");
include("../func/whmcs-func.php");
include_once("../func/whmp-func.php");

header('Content-Type: application/json');

$domain = mysqli_real_escape_string($connection_server, trim($_GET['domain'] ?? ''));

if (empty($domain)) {
    echo json_encode(['status' => 'error', 'message' => 'Domain name is required']);
    exit();
}

// Basic validation
if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{1,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$/', $domain)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid domain format']);
    exit();
}

// Determine extension (handle multi-part TLDs like .com.ng)
$first_dot = strpos($domain, '.');
$ext = ($first_dot !== false) ? substr($domain, $first_dot) : '';
$ext_esc = mysqli_real_escape_string($connection_server, $ext);

// ─── WHMP Integration: Use RDAP for availability + WHMP reseller pricing ───────────────────────────
if (whmp_is_configured()) {
    // Check domain availability via RDAP
    $availability = whmp_check_domain_availability($domain);

    if ($availability['status'] === 'available') {
        // Get WHMP reseller price for this extension
        $whmp_price = whmp_get_domain_price($ext);
        $price = ($whmp_price !== null && $whmp_price > 0) ? $whmp_price : 0;

        echo json_encode([
            'status' => 'available',
            'domain' => $domain,
            'price' => $price,
            'source' => 'whmp',
        ]);
        exit();
    } elseif ($availability['status'] === 'registered') {
        // Generate Suggestions
        $parts = explode('.', $domain);
        $name_only = $parts[0];

        $suggestions = [];
        $variants = ['hub', 'pay', 'vtu', 'app', 'online', 'digital', 'store'];

        // Fetch all supported extensions from WHMP pricing
        $all_exts = [];
        $whmp_exts = whmp_get_domain_extensions();
        foreach ($whmp_exts as $we) {
            $all_exts[] = $we['extension'];
        }
        // Fall back to DB extensions if WHMP returned nothing
        if (empty($all_exts)) {
            $ext_q = mysqli_query($connection_server, "SELECT extension FROM sas_domain_extensions");
            while ($er = mysqli_fetch_assoc($ext_q)) $all_exts[] = $er['extension'];
        }
        if (empty($all_exts)) $all_exts = ['.com', '.ng', '.com.ng'];

        // 1. Try other extensions
        foreach ($all_exts as $e) {
            if (count($suggestions) >= 5) break;
            $candidate = $name_only . $e;
            if ($candidate !== $domain) $suggestions[] = $candidate;
        }

        // 2. Try prefix/suffix variants with primary extension
        $primary_ext = ($first_dot !== false) ? $ext : '.com';
        foreach ($variants as $v) {
            if (count($suggestions) >= 5) break;
            $suggestions[] = $name_only . $v . $primary_ext;
        }

        echo json_encode([
            'status' => 'registered',
            'domain' => $domain,
            'suggestions' => array_slice($suggestions, 0, 6),
            'source' => 'whmp',
        ]);
        exit();
    } elseif ($availability['status'] === 'error') {
        // RDAP failed - fall through to WHMCS fallback
        $rdap_error = $availability['message'];
    }
}

// ─── WHMCS Fallback ────────────────────────────────────────────────────────────────────────────────
$result = whmcsDomainLookup($domain);

if (($result['status'] ?? '') == 'available') {
    $q_price = mysqli_query($connection_server, "SELECT price, promo_price FROM sas_domain_extensions WHERE extension='$ext_esc' LIMIT 1");
    if ($r_price = mysqli_fetch_assoc($q_price)) {
        $result['price'] = ($r_price['promo_price'] > 0) ? (float)$r_price['promo_price'] : (float)$r_price['price'];
    } else {
        $result['status'] = 'unsupported';
        $result['message'] = "The extension $ext is not supported for automated registration. Please choose another or contact support.";
    }
} else {
    // Generate Suggestions
    $parts = explode('.', $domain);
    $name_only = $parts[0];

    $suggestions = [];
    $variants = ['hub', 'pay', 'vtu', 'app', 'online', 'digital', 'store'];

    // Fetch all supported extensions
    $all_exts = [];
    $ext_q = mysqli_query($connection_server, "SELECT extension FROM sas_domain_extensions");
    while ($er = mysqli_fetch_assoc($ext_q)) $all_exts[] = $er['extension'];
    if (empty($all_exts)) $all_exts = ['.com', '.ng', '.com.ng'];

    // 1. Try other extensions
    foreach ($all_exts as $e) {
        if (count($suggestions) >= 5) break;
        $candidate = $name_only . $e;
        if ($candidate !== $domain) $suggestions[] = $candidate;
    }

    // 2. Try prefix/suffix variants with primary extension
    $primary_ext = ($first_dot !== false) ? $ext : '.com';
    foreach ($variants as $v) {
        if (count($suggestions) >= 5) break;
        $suggestions[] = $name_only . $v . $primary_ext;
    }

    $result['suggestions'] = array_slice($suggestions, 0, 6);
}

$result['source'] = 'whmcs';
echo json_encode($result);
