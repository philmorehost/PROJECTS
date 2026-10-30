<?php
// Name and Domain Availability Checker API
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once dirname(dirname(dirname(__DIR__))) . '/config/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/include/db.php';

$response = [
    'available' => false,
    'message' => 'Invalid parameters provided.'
];

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$domain = isset($_GET['domain']) ? trim($_GET['domain']) : '';

if (!empty($slug)) {
    // Basic slug validation
    if (!preg_match('/^[a-zA-Z0-9\-]+$/', $slug)) {
        $response['message'] = 'Slug must only contain alphanumeric characters and hyphens.';
        echo json_encode($response);
        exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sm_schools WHERE slug = ?");
    $stmt->execute([$slug]);
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        $response['available'] = true;
        $response['message'] = 'School slug is available!';
    } else {
        $response['available'] = false;
        $response['message'] = 'School slug is already taken.';
    }
} elseif (!empty($domain)) {
    // Basic domain validation
    if (!filter_var('http://' . $domain, FILTER_VALIDATE_URL)) {
        $response['message'] = 'Invalid custom domain format.';
        echo json_encode($response);
        exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sm_schools WHERE custom_domain = ?");
    $stmt->execute([$domain]);
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        $response['available'] = true;
        $response['message'] = 'Custom domain is available!';
    } else {
        $response['available'] = false;
        $response['message'] = 'Custom domain is already registered.';
    }
}

echo json_encode($response);
exit;
?>
