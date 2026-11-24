<?php
/** Disposable MySQL 8.0 tests for bc-payout-worker.php & bc-service-dispatch-worker.php across SAAS & NON-SAAS. */
set_exception_handler(function ($e) {
    $msg = str_replace(['%', "\r", "\n"], ['%25', '', '%0A'], $e->getMessage());
    echo '::error file=VTU/tests/held_workers_mysql_test.php,line=' . $e->getLine() . '::' . $msg . "\n";
    exit(1);
});

$edition = getenv('BC_TEST_EDITION') ?: 'SAAS';
if ($edition !== 'SAAS' && $edition !== 'NON-SAAS') throw new RuntimeException('Invalid edition');
require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-payout-worker.php';
require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-service-dispatch-worker.php';

$root = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', '', 3306);
$dbName = 'hw_ci_' . strtolower(str_replace('-', '', $edition)) . '_' . bin2hex(random_bytes(5));
$root->query("CREATE DATABASE `$dbName`");
$GLOBALS['connection_server'] = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', $dbName, 3306);
$db = $GLOBALS['connection_server'];
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$n = 0;
function hw_chk($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

try {
    $db->query("CREATE TABLE sas_vendors (id INT AUTO_INCREMENT PRIMARY KEY, status TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB");
    $db->query("INSERT INTO sas_vendors(id, status) VALUES(1, 1)");
    $db->query("CREATE TABLE sas_users (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, username VARCHAR(64) NOT NULL, balance DECIMAL(20,2) NOT NULL DEFAULT 0.00, status TINYINT(1) NOT NULL DEFAULT 1, is_blocked TINYINT(1) NOT NULL DEFAULT 0, force_password_change TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB");
    $db->query("INSERT INTO sas_users(id, vendor_id, username, balance, status, is_blocked) VALUES(7, 1, 'bob', 10000.00, 1, 0)");
    $db->query("CREATE TABLE sas_wallet_mutation_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_key CHAR(64) NOT NULL, payload_hash CHAR(64) NOT NULL, vendor_id INT NOT NULL, actor VARCHAR(80) NOT NULL, direction VARCHAR(16) NOT NULL, reference VARCHAR(160) NOT NULL, amount DECIMAL(20,2) NOT NULL, UNIQUE KEY uq_ev (event_key)) ENGINE=InnoDB");
    $db->query("CREATE TABLE sas_transactions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, product_unique_id VARCHAR(64) NOT NULL, type_alternative VARCHAR(64) NOT NULL, reference VARCHAR(160) NOT NULL, amount DECIMAL(20,2) NOT NULL, discounted_amount DECIMAL(20,2) NOT NULL, balance_before DECIMAL(20,2) NOT NULL, balance_after DECIMAL(20,2) NOT NULL, description VARCHAR(255) NOT NULL, api_website VARCHAR(64) NOT NULL, status INT NOT NULL, username VARCHAR(64) NOT NULL, api_reference VARCHAR(128) NOT NULL, mode VARCHAR(32) NOT NULL) ENGINE=InnoDB");

    foreach (['20261118_payout_reservations.sql', '20261118_service_attempt_journal.sql', '20261118_service_dispatch_leases.sql'] as $sqlFile) {
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../migrations/' . $sqlFile));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') $db->query($stmt);
        }
    }

    // 1. Payout worker: held -> dispatched -> confirmed_success, confirmed_failure (single refund), and timeout -> unknown (no refund)
    $r1 = bc_payout_reserve(1, 7, 'paystack', '058', '0123456789', '1000.00', '50.00', 'Test 1', hash('sha256', 'po-1'));
    $r2 = bc_payout_reserve(1, 7, 'paystack', '058', '0123456789', '2000.00', '50.00', 'Test 2', hash('sha256', 'po-2'));
    $r3 = bc_payout_reserve(1, 7, 'paystack', '058', '0123456789', '500.00',  '50.00', 'Test 3', hash('sha256', 'po-3'));
    hw_chk($r1['ok'] && $r2['ok'] && $r3['ok'], 'three held payout reservations created');

    putenv('BC_PAYOUT_WORKER_ENABLED=1');
    $pRes = bc_payout_run_worker(1, function ($res) use ($r1, $r2) {
        if ($res['reference'] === $r1['reference']) return ['outcome' => 'confirmed_success', 'provider_reference' => 'PS_1'];
        if ($res['reference'] === $r2['reference']) return ['outcome' => 'confirmed_failure', 'provider_reference' => 'PS_2'];
        throw new RuntimeException('network_timeout');
    });
    hw_chk($pRes['ok'] && $pRes['processed'] === 3, 'payout worker processed 3 reservations');

    $states = [];
    $q = $db->query("SELECT reference, state FROM sas_payout_reservations WHERE vendor_id=1");
    while ($row = $q->fetch_assoc()) $states[$row['reference']] = $row['state'];
    hw_chk($states[$r1['reference']] === 'confirmed_success', 'r1 confirmed_success');
    hw_chk($states[$r2['reference']] === 'confirmed_failure', 'r2 confirmed_failure');
    hw_chk($states[$r3['reference']] === 'unknown', 'r3 timeout recorded as unknown');

    // Balance started 10000 - (1050 + 2050 + 550) + 2050 refund = 8400.00 (unknown r3 NOT refunded!)
    $bal = (float)$db->query("SELECT balance FROM sas_users WHERE id=7")->fetch_assoc()['balance'];
    hw_chk(abs($bal - 8400.00) < 0.001, 'unknown payout never auto-refunded; confirmed_failure refunded once');

    // 2. Service dispatch worker + crash reaper
    $db->query("CREATE TABLE sas_service_requests(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, reference VARCHAR(64) NOT NULL, type VARCHAR(64) NOT NULL, provider VARCHAR(96) NOT NULL, payload LONGTEXT NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'pending') ENGINE=InnoDB");
    $db->query("INSERT INTO sas_service_capability_matrix(vendor_id, service_type, provider, currency, is_authorized, idempotency_verified, status_query_verified, evidence_digest) VALUES(1, 'intl_transfer', 'klasha', 'USD', 1, 1, 1, '" . str_repeat('e', 64) . "')");
    $db->query("INSERT INTO sas_service_requests(id, vendor_id, user_id, type, provider, reference, status, payload) VALUES(501, 1, 7, 'intl_transfer', 'klasha', 'SVC-501', 'pending', '{\"currency\":\"USD\"}'), (502, 1, 7, 'intl_transfer', 'klasha', 'SVC-502', 'pending', '{\"currency\":\"USD\"}')");
    bc_saj_hold(1, 501);
    bc_saj_hold(1, 502);

    putenv('BC_SERVICE_DISPATCH_WORKER_ENABLED=1');
    // Lease 502 and expire it to simulate crashed worker before running worker on 501
    $l502 = bc_sdl_acquire_lease(1, 502, 'worker-dead', 10, 0);
    hw_chk($l502['ok'], 'acquired lease for 502');
    $db->query("UPDATE sas_service_dispatch_leases SET expires_at=DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE request_id=502");

    $sRes = bc_sdl_run_worker(1, 'worker-ci-1', fn($row, $lease) => ['outcome' => 'confirmed_success', 'provider_reference' => 'KL_501']);
    hw_chk($sRes['ok'] && $sRes['processed'] === 1 && $sRes['results'][0]['state'] === 'confirmed_success', 'service worker leased and completed 501');

    $reap = bc_sdl_run_reaper(1);
    hw_chk($reap['ok'] && $reap['reaped'] === 1, 'crash reaper reaped expired lease');
    $st502 = $db->query("SELECT state FROM sas_service_attempts WHERE request_id=502")->fetch_assoc()['state'];
    hw_chk($st502 === 'unknown', 'reaped attempt transitioned to unknown without retry');

    echo "PASS $n held worker MySQL checks ($edition)\n";
} finally {
    putenv('BC_PAYOUT_WORKER_ENABLED');
    putenv('BC_SERVICE_DISPATCH_WORKER_ENABLED');
    $root->query("DROP DATABASE IF EXISTS `$dbName`");
}
