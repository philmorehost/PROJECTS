<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-config.php");
include_once("../func/bc-transaction-receipt.php");

header('Content-Type: application/json');

// §32 receipt endpoint (customer console). Scoped to the signed-in customer's
// own transactions only; the payload never contains provider internals.
$ref = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['ref'] ?? ''));
$user = $GLOBALS['get_logged_user_details'] ?? null;

if (!is_array($user) || empty($user['username']) || $ref === '') {
    echo json_encode(array('ok' => false, 'message' => 'Transaction not found.'));
    exit;
}

$vid = (int)$user['vendor_id'];
$uname = mysqli_real_escape_string($connection_server, (string)$user['username']);
$row = bc_receipt_fetch($connection_server, "t.vendor_id='$vid' AND t.username='$uname'", $ref);
$site = (string)($GLOBALS['get_all_site_details']['site_title'] ?? 'Receipt');

echo json_encode(bc_receipt_payload($row, false, $site));
