<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-admin-config.php");
include_once("../func/bc-transaction-receipt.php");

header('Content-Type: application/json');

// §32 receipt endpoint (vendor back office). Scoped to this vendor's
// transactions; staff payload adds investigation fields (still no keys).
$ref = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['ref'] ?? ''));
$admin = $GLOBALS['get_logged_admin_details'] ?? null;

if (!is_array($admin) || empty($admin['id']) || $ref === '') {
    echo json_encode(array('ok' => false, 'message' => 'Transaction not found.'));
    exit;
}

$vid = (int)$admin['id'];
$row = bc_receipt_fetch($connection_server, "t.vendor_id='$vid'", $ref);
$site = (string)($GLOBALS['get_all_site_details']['site_title'] ?? ($admin['website_url'] ?? 'Receipt'));

echo json_encode(bc_receipt_payload($row, true, $site));
