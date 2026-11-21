<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-spadmin-config.php");
include_once("../func/bc-transaction-receipt.php");

header('Content-Type: application/json');

// §32 receipt endpoint (platform back office). The super admin may view any
// vendor's transaction; staff payload adds investigation fields (no keys).
$ref = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['ref'] ?? ''));
$spadmin = $GLOBALS['get_logged_spadmin_details'] ?? null;

if (!is_array($spadmin) || empty($spadmin['id']) || $ref === '') {
    echo json_encode(array('ok' => false, 'message' => 'Transaction not found.'));
    exit;
}

$row = bc_receipt_fetch($connection_server, "1=1", $ref);
$site = (string)($GLOBALS['get_all_super_admin_site_details']['site_title'] ?? 'Receipt');

echo json_encode(bc_receipt_payload($row, true, $site));
