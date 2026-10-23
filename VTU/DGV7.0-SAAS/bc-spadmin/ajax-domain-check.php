<?php
session_start();
include("../func/bc-connect.php");
include_once("../func/bc-func.php");
include("../func/whmcs-func.php");

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

$result = whmcsDomainLookup($domain);

if (($result['status'] ?? '') == 'available') {
    // Determine extension (handle multi-part TLDs like .com.ng)
    $first_dot = strpos($domain, '.');
    $ext = ($first_dot !== false) ? substr($domain, $first_dot) : '';
    $ext_esc = mysqli_real_escape_string($connection_server, $ext);

    $q_price = mysqli_query($connection_server, "SELECT price, promo_price FROM sas_domain_extensions WHERE extension='$ext_esc' LIMIT 1");
    if($r_price = mysqli_fetch_assoc($q_price)) {
        $result['price'] = ($r_price['promo_price'] > 0) ? (float)$r_price['promo_price'] : (float)$r_price['price'];
    } else {
        // Extension not supported for registration via this platform
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
    while($er = mysqli_fetch_assoc($ext_q)) $all_exts[] = $er['extension'];
    if(empty($all_exts)) $all_exts = ['.com', '.ng', '.com.ng'];

    // 1. Try other extensions
    foreach($all_exts as $e) {
        if(count($suggestions) >= 5) break;
        $candidate = $name_only . $e;
        if($candidate !== $domain) $suggestions[] = $candidate;
    }

    // 2. Try prefix/suffix variants with primary extension
    $first_dot = strpos($domain, '.');
    $primary_ext = ($first_dot !== false) ? substr($domain, $first_dot) : '.com';
    foreach($variants as $v) {
        if(count($suggestions) >= 5) break;
        $suggestions[] = $name_only . $v . $primary_ext;
    }

    $result['suggestions'] = array_slice($suggestions, 0, 6);
}

echo json_encode($result);
?>
