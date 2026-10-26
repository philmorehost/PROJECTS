<?php
session_start();
include("../func/bc-connect.php");
include_once("../func/bc-tables.php");
include_once("../func/bc-func.php");
include_once("../func/whmp-func.php");

header('Content-Type: application/json');

$domain_raw = strtolower(trim($_GET['domain'] ?? ''));
$domain = mysqli_real_escape_string($connection_server, $domain_raw);

if (empty($domain)) {
    echo json_encode(['status' => 'error', 'message' => 'Domain name is required']);
    exit();
}

// Basic validation
if (!bc_is_valid_domain_name($domain_raw)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid domain format']);
    exit();
}

// Match the longest configured full extension (including multi-label suffixes such as .com.ng).
$ext = '';
$extension_query = mysqli_query($connection_server, "SELECT extension FROM sas_domain_extensions ORDER BY LENGTH(extension) DESC");
while ($extension_row = $extension_query ? mysqli_fetch_assoc($extension_query) : null) {
    if ($extension_row && substr($domain_raw, -strlen($extension_row['extension'])) === strtolower($extension_row['extension'])) {
        $ext = strtolower($extension_row['extension']);
        break;
    }
}
if ($ext === '') {
    foreach (whmp_get_domain_extensions() as $whmp_extension) {
        $candidate_extension = strtolower((string)($whmp_extension['extension'] ?? ''));
        if ($candidate_extension !== '' && substr($domain_raw, -strlen($candidate_extension)) === $candidate_extension && strlen($candidate_extension) > strlen($ext)) {
            $ext = $candidate_extension;
        }
    }
}
if ($ext === '') {
    echo json_encode(['status' => 'unsupported', 'message' => 'This domain extension is not available in the WHMP extension catalog.']);
    exit();
}
$first_dot = strpos($domain, '.');

// Domain search and registration are WHMP-only; never silently fall back to another registrar.
if (!whmp_is_configured()) {
    echo json_encode(['status' => 'error', 'message' => 'WHMP is not configured. Please contact the administrator.']);
    exit();
}

// ─── WHMP Integration: Use RDAP for availability + WHMP reseller pricing ───────────────────────────
{
    // Check domain availability via RDAP
    $availability = whmp_check_domain_availability($domain);

    if ($availability['status'] === 'available') {
        // WHMP returns USD prices; never expose those as if they were already Naira.
        $whmp_usd_price = whmp_get_domain_price($ext);
        $usd_to_ngn_rate = whmp_get_usd_to_ngn_rate();
        if ($whmp_usd_price !== null && $whmp_usd_price > 0 && $usd_to_ngn_rate > 0) {
            $price = whmp_convert_usd_to_ngn($whmp_usd_price, $usd_to_ngn_rate);
            $price_source = 'whmp';
            $price_message = '';
        } elseif ($whmp_usd_price !== null && $whmp_usd_price > 0) {
            // Never present a USD quote as Naira when the admin has not configured a rate.
            echo json_encode(['status' => 'unsupported', 'message' => 'Save a USD to NGN rate in Super Admin Domain Settings before requesting registration.']);
            exit();
        } else {
            echo json_encode(['status' => 'unsupported', 'message' => 'WHMP has no usable price for this extension. Ask the administrator to sync the WHMP extension catalog and set the USD-to-NGN rate.']);
            exit();
        }

        echo json_encode([
            'status' => 'available',
            'domain' => $domain,
            'price' => $price,
            'currency' => 'NGN',
            'price_source' => $price_source,
            'message' => $price_message,
            'source' => 'whmp',
        ]);
        exit();
    } elseif ($availability['status'] === 'registered') {
        // Generate Suggestions
        $name_only = substr($domain, 0, -strlen($ext));

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
    } else {
        echo json_encode(['status' => 'error', 'message' => $availability['message'] ?? 'WHMP availability lookup failed. Please try again later.']);
        exit();
    }
}
