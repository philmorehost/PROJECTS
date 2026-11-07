<?php
// Custom Domain Availability Search API
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once dirname(dirname(dirname(__DIR__))) . '/config/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/include/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/include/whmcs_helper.php';

$response = [
    'available' => false,
    'message' => 'Invalid parameters provided.',
    'promo_price' => 0.00,
    'normal_price' => 0.00
];

$domain = isset($_GET['domain']) ? trim($_GET['domain']) : '';
$tld_id = isset($_GET['tld_id']) ? (int)$_GET['tld_id'] : 0;

if (!empty($domain) && $tld_id > 0) {
    // Basic domain name check (alphanumeric and hyphens only)
    if (!preg_match('/^[a-zA-Z0-9\-]+$/', $domain)) {
        $response['message'] = 'Domain name must only contain alphanumeric characters and hyphens.';
        echo json_encode($response);
        exit;
    }

    // Fetch TLD details
    $stmt = $pdo->prepare("SELECT * FROM sm_domain_tlds WHERE id = ?");
    $stmt->execute([$tld_id]);
    $tld_row = $stmt->fetch();

    if ($tld_row) {
        $tld = $tld_row['tld'];
        $check = WHMCSHelper::checkDomainAvailability($domain, $tld);
        
        $response['available'] = $check['available'];
        $response['message'] = $check['message'];
        $response['promo_price'] = (float)$tld_row['promo_price'];
        $response['normal_price'] = (float)$tld_row['normal_price'];
    } else {
        $response['message'] = 'Invalid TLD selected.';
    }
}

echo json_encode($response);
exit;
?>
