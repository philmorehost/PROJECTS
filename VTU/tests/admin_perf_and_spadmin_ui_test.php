<?php
$n = 0;
function ap_check($ok, $msg) {
    global $n;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $msg);
    }
    $n++;
}

$root = dirname(__DIR__);

// 1. VendorKYC media previews + HTTP Byte-Range streaming + clean HTML shell
$vkyc = file_get_contents($root . '/DGV7.0-SAAS/bc-spadmin/VendorKYC.php');
ap_check(strpos($vkyc, 'Accept-Ranges: bytes') !== false, 'VendorKYC advertises Accept-Ranges: bytes');
ap_check(strpos($vkyc, 'HTTP_RANGE') !== false && strpos($vkyc, 'http_response_code(206)') !== false, 'VendorKYC supports 206 Partial Content byte-range streaming');
ap_check(strpos($vkyc, 'ob_end_clean') !== false, 'VendorKYC flushes output buffers before media streaming');
ap_check(strpos($vkyc, "'selfie' =>") !== false, 'VendorKYC labels selfie documents');
ap_check(strpos($vkyc, '<!DOCTYPE html>') === false, 'VendorKYC has no duplicate DOCTYPE wrapper around bc_adm_head');
ap_check(strpos($vkyc, '<video controls') !== false && strpos($vkyc, 'vkycPreviewModal') !== false, 'VendorKYC renders inline video/image preview and modal');

// 2. VendorRegistrations Pending vs Blocked tabs
$vr = file_get_contents($root . '/DGV7.0-SAAS/bc-spadmin/VendorRegistrations.php');
ap_check(strpos($vr, 'Pending Vendor Approval') !== false && strpos($vr, 'Blocked Vendor Registrations') !== false, 'VendorRegistrations shows Pending and Blocked tabs');
ap_check(strpos($vr, 'vrPanePending') !== false && strpos($vr, 'vrPaneBlocked') !== false, 'VendorRegistrations separates Pending and Blocked panes');
ap_check(strpos($vr, 'WHERE pv.status IN (0, 2)') !== false, 'VendorRegistrations includes declined status=2 in Blocked evaluation');
ap_check(strpos($vr, 'DELETE FROM sas_pending_vendors') === false && strpos($vr, 'SET status=2') !== false, 'VendorRegistrations preserves archived evidence');

// 3. Super-admin Transactions / UserTransactions vendor identity + invoice modal
$spTx = file_get_contents($root . '/DGV7.0-SAAS/bc-spadmin/Transactions.php');
$spUTx = file_get_contents($root . '/DGV7.0-SAAS/bc-spadmin/UserTransactions.php');
$spDet = file_get_contents($root . '/DGV7.0-SAAS/bc-spadmin/transaction-details.php');
ap_check(strpos($spTx, 'LEFT JOIN sas_vendors') !== false && strpos($spTx, '$show_vendor_info = true') !== false, 'spadmin Transactions joins vendor identity');
ap_check(strpos($spUTx, 'LEFT JOIN sas_vendors') !== false && strpos($spUTx, '$show_vendor_info = true') !== false, 'spadmin UserTransactions joins vendor identity');
ap_check(strpos($spDet, 'bc_receipt_fetch_spadmin(') !== false, 'spadmin transaction-details supports both user and vendor transactions');

require_once $root . '/DGV7.0-SAAS/func/bc-transaction-receipt.php';
$vendorReceiptPayload = bc_receipt_payload([
    'reference' => 'VTX-1001',
    'vendor_id' => 9,
    'vendor_name' => 'Ada Vendor',
    'vendor_email' => 'ada@vendor.test',
    'vendor_website' => 'vendor.test',
    '_source' => 'vendor',
    'type_alternative' => 'Wallet Funding',
    'amount' => '5000.00',
    'discounted_amount' => '5000.00',
    'balance_before' => '1000.00',
    'balance_after' => '6000.00',
    'status' => 1,
    'mode' => 'WEB',
    'date' => '2026-11-18 10:00:00',
    'description' => 'Vendor wallet funding invoice',
], true, 'Platform');
ap_check(($vendorReceiptPayload['ok'] ?? false) === true, 'admin receipt payload accepts vendor transactions');
ap_check(($vendorReceiptPayload['vendor_name'] ?? '') === 'Ada Vendor' && ($vendorReceiptPayload['vendor_email'] ?? '') === 'ada@vendor.test', 'admin receipt payload includes vendor identity');
$userPayload = bc_receipt_payload([
    'reference' => 'UTX-1002',
    'vendor_id' => 9,
    'vendor_name' => 'Ada Vendor',
    'vendor_email' => 'ada@vendor.test',
    'username' => 'alice',
    'status' => 1,
], false, 'Platform');
ap_check(!isset($userPayload['vendor_name'], $userPayload['vendor_email'], $userPayload['description']), 'customer receipt payload never leaks staff/vendor fields');

// 4. Edition parity & performance / Pay Bills spinning fixes
foreach (['func/bc-plate-admin.php', 'func/bc-plate-app.php', 'func/bc-transaction-receipt.php', 'web/ajax-sync-accounts.php', 'web/ajax-check-id-limit.php'] as $parityFile) {
    ap_check(
        file_get_contents($root . '/DGV7.0-SAAS/' . $parityFile) === file_get_contents($root . '/DGV7.0-NON-SAAS/' . $parityFile),
        "edition parity preserved for $parityFile"
    );
}

foreach (['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'] as $ed) {
    $sync = file_get_contents($root . '/' . $ed . '/web/ajax-sync-accounts.php');
    $lim = file_get_contents($root . '/' . $ed . '/web/ajax-check-id-limit.php');
    $mailer = file_get_contents($root . '/' . $ed . '/func/bc-mailer.php');
    $func = file_get_contents($root . '/' . $ed . '/func/bc-func.php');
    $app = file_get_contents($root . '/' . $ed . '/func/bc-plate-app.php');
    $adm = file_get_contents($root . '/' . $ed . '/func/bc-plate-admin.php');
    $hist = file_get_contents($root . '/' . $ed . '/func/history-table.php');
    $cfg = file_get_contents($root . '/' . $ed . '/func/bc-config.php');

    ap_check(strpos($sync, 'session_write_close()') !== false, "$ed ajax-sync-accounts releases PHP session lock before gateway calls");
    ap_check(strpos($lim, 'session_write_close()') !== false, "$ed ajax-check-id-limit releases PHP session lock");
    ap_check(strpos($mailer, 'bc_flush_deferred_mail_queue') !== false && strpos($mailer, 'fastcgi_finish_request') !== false, "$ed bc-mailer supports deferred shutdown flush");
    ap_check(substr_count($mailer, '$message = mailDesignTemplate($subject, $message, array(), true);') === 2, "$ed bc-mailer keeps mailDesignTemplate invariant");
    ap_check(strpos($func, 'function sendVendorEmail($recipient_email, $email_subject, $email_body, $background = null)') !== false, "$ed sendVendorEmail supports background parameter");
    ap_check(strpos($func, 'CURLOPT_CONNECTTIMEOUT, 5') !== false, "$ed bc-func sets connect timeout on external gateways");
    ap_check(strpos($app, 'e.defaultPrevented') !== false && strpos($app, 'pagehide') !== false, "$ed bc-plate-app guards submit lock against prevented/invalid forms");
    ap_check(strpos($adm, 'd.vendor_name') !== false && strpos($adm, 'd.vendor_email') !== false, "$ed bc-plate-admin displays vendor details in transaction modal");
    ap_check(strpos($hist, '$show_vendor_col') !== false, "$ed history-table displays vendor column when enabled");
    ap_check(strpos($cfg, "DELETE FROM sas_") !== false && strpos($cfg, 'system_migration_version') !== false, "$ed bc-config persists system_migration_version without duplicate-key drift");
}

echo "PASS $n admin perf & spadmin UI regression checks\n";
