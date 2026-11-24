<?php
/** Real MySQL 8.0 concurrency, capability-gate, crash-reaper, and rollback tests for bc-service-dispatch-lease.php. */
if (getenv('BC_SVC_DISPATCH_LEASE_CI') !== '1') throw new RuntimeException('Disposable CI only.');
set_exception_handler(function ($e) {
    $msg = str_replace(['%', "\r", "\n"], ['%25', '', '%0A'], $e->getMessage());
    echo '::error file=VTU/tests/service_dispatch_lease_mysql_test.php,line=' . $e->getLine() . '::' . $msg . "\n";
    exit(1);
});

$edition = getenv('BC_TEST_EDITION') ?: 'SAAS';
if (!in_array($edition, ['SAAS', 'NON-SAAS'], true)) throw new RuntimeException('Invalid edition.');

require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-service-dispatch-lease.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function sdl_db($name = '') {
    $m = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', $name, 3306);
    $m->set_charset('utf8mb4');
    return $m;
}

if (($argv[1] ?? '') === 'race-lease') {
    $name = $argv[2] ?? '';
    if (!preg_match('/\Abc_sdl_ci_[a-f0-9]{12}\z/D', $name)) throw new RuntimeException('db');
    $GLOBALS['connection_server'] = sdl_db($name);
    usleep(150000);
    echo json_encode(bc_sdl_acquire_lease(7, (int)($argv[3] ?? 0), 'worker-' . ($argv[4] ?? '0'), 60, 0));
    exit;
}

$n = 0;
function sdl_test($ok, $label) { global $n; if (!$ok) throw new RuntimeException($label); $n++; }

$root = sdl_db();
$name = 'bc_sdl_ci_' . bin2hex(random_bytes(6));
$root->query("CREATE DATABASE `$name`");
$db = sdl_db($name);
$GLOBALS['connection_server'] = $db;

try {
    $db->query('CREATE TABLE sas_vendors(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, status INT DEFAULT 1) ENGINE=InnoDB');
    $db->query('INSERT INTO sas_vendors(id, status) VALUES(7, 1), (8, 0)');
    $db->query('CREATE TABLE sas_users(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, username VARCHAR(225), status INT DEFAULT 1, is_blocked TINYINT(1) DEFAULT 0, force_password_change TINYINT(1) DEFAULT 0) ENGINE=InnoDB');
    $db->query("INSERT INTO sas_users(id, vendor_id, username, status) VALUES(1, 7, 'alice', 1), (2, 7, 'blocked', 1)");
    $db->query('UPDATE sas_users SET is_blocked=1 WHERE id=2');
    $db->query('CREATE TABLE sas_service_requests(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, reference VARCHAR(64) NOT NULL, type VARCHAR(64) NOT NULL, provider VARCHAR(96) NOT NULL, payload LONGTEXT NOT NULL, status VARCHAR(32) NOT NULL DEFAULT "pending") ENGINE=InnoDB');

    $mig1 = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../migrations/20261118_service_attempt_journal.sql'));
    foreach (array_filter(array_map('trim', explode(';', $mig1))) as $stmt) {
        if ($stmt !== '') $db->query($stmt);
    }
    $mig2 = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../migrations/20261118_service_dispatch_leases.sql'));
    foreach (array_filter(array_map('trim', explode(';', $mig2))) as $stmt) {
        if ($stmt !== '') $db->query($stmt);
    }

    // Seed requests and hold attempts via bc_saj_hold
    $p1 = json_encode(['currency' => 'USD', 'amount_usd' => 100]);
    $db->query("INSERT INTO sas_service_requests(id, vendor_id, user_id, reference, type, provider, payload) VALUES(10, 7, 1, 'SVC-10', 'intl_transfer', 'klasha', '$p1'), (11, 7, 2, 'SVC-11', 'intl_transfer', 'klasha', '$p1'), (12, 7, 1, 'SVC-12', 'intl_transfer', 'klasha', '$p1'), (13, 7, 1, 'SVC-13', 'intl_transfer', 'klasha', '$p1')");
    foreach ([10, 11, 12, 13] as $rid) {
        $h = bc_saj_hold(7, $rid);
        sdl_test($h['ok'] && $h['state'] === 'held', "held attempt $rid");
    }

    // 1. Capability matrix absent or incomplete -> capability_denied
    sdl_test(bc_sdl_acquire_lease(7, 10, 'worker-a', 60, 0)['code'] === 'capability_denied', 'denies lease without capability matrix');
    $evDig = str_repeat('a', 64);
    $db->query("INSERT INTO sas_service_capability_matrix(vendor_id, service_type, provider, currency, is_authorized, idempotency_verified, status_query_verified, evidence_digest) VALUES(7, 'intl_transfer', 'klasha', 'USD', 1, 1, 0, '$evDig')");
    sdl_test(bc_sdl_acquire_lease(7, 10, 'worker-a', 60, 0)['code'] === 'capability_denied', 'denies lease when status_query_verified=0');
    $db->query("UPDATE sas_service_capability_matrix SET status_query_verified=1 WHERE vendor_id=7 AND service_type='intl_transfer'");

    // Blocked user refused even with verified capability
    sdl_test(bc_sdl_acquire_lease(7, 11, 'worker-a', 60, 0)['code'] === 'restricted', 'denies lease for blocked user');

    // 2. Concurrent worker race on request 10 -> only 1 worker acquires lease
    $procs = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, 'race-lease', $name, '10', (string)$i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $procs[] = [$p, $pipes];
    }
    $leased = []; $denied = 0;
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        sdl_test(proc_close($p) === 0 && $err === '', 'clean lease race worker: ' . $err . $out);
        $r = json_decode($out, true);
        if (($r['code'] ?? '') === 'leased') $leased[] = $r;
        elseif (($r['code'] ?? '') === 'not_held') $denied++;
    }
    sdl_test(count($leased) === 1 && $denied === 3, 'exactly one concurrent worker acquires lease');
    $token10 = $leased[0]['lease_token'];

    // Wrong token refused; valid token completes -> confirmed_success
    sdl_test(bc_sdl_complete_lease(7, 10, str_repeat('f', 64), 'confirmed_success', str_repeat('b', 64), 'KL-10')['code'] === 'conflict', 'rejects wrong lease token');
    $comp = bc_sdl_complete_lease(7, 10, $token10, 'confirmed_success', str_repeat('b', 64), 'KL-10');
    sdl_test($comp['ok'] && $comp['state'] === 'confirmed_success' && $comp['version'] === 2, 'completes lease atomically');
    sdl_test(bc_sdl_acquire_lease(7, 10, 'worker-b', 60, 2)['code'] === 'not_held', 'cannot re-lease completed attempt');

    // 3. Expired lease crash recovery -> reap moves to unknown (NEVER back to held), then reconcilable via bc_saj_observe
    $l12 = bc_sdl_acquire_lease(7, 12, 'worker-crash', 60, 0);
    sdl_test($l12['ok'], 'acquires lease for req 12');
    $db->query('UPDATE sas_service_dispatch_leases SET expires_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE vendor_id=7 AND request_id=12');
    sdl_test(bc_sdl_complete_lease(7, 12, $l12['lease_token'], 'confirmed_success', str_repeat('c', 64), 'KL-12')['code'] === 'lease_expired', 'refuses completing expired lease');
    $reap = bc_sdl_reap_expired_lease(7, 12, hash('sha256', 'reap-12'));
    sdl_test($reap['ok'] && $reap['code'] === 'reaped_unknown' && $reap['state'] === 'unknown' && $reap['version'] === 2, 'reaper moves expired lease to unknown');
    sdl_test(bc_sdl_acquire_lease(7, 12, 'worker-retry', 60, 2)['code'] === 'not_held', 'unknown attempt cannot be re-leased or retried');
    $rec = bc_saj_observe(7, 12, hash('sha256', 'obs-12'), 'confirmed_success', str_repeat('d', 64), 'KL-12', 2);
    sdl_test($rec['ok'] && $rec['state'] === 'confirmed_success', 'unknown reaped attempt reconciles cleanly via bc_saj_observe');

    // 4. Outer tx, MyISAM refusal, trigger rollback on req 13
    $db->begin_transaction();
    sdl_test(bc_sdl_acquire_lease(7, 13, 'worker-o', 60, 0)['code'] === 'outer_transaction', 'outer tx refused');
    $db->rollback();

    $db->query('ALTER TABLE sas_users ENGINE=MyISAM');
    sdl_test(bc_sdl_acquire_lease(7, 13, 'worker-m', 60, 0)['code'] === 'nontransactional', 'MyISAM refused');
    $db->query('ALTER TABLE sas_users ENGINE=InnoDB');

    $db->query("CREATE TRIGGER fail_sdl_ins BEFORE INSERT ON sas_service_dispatch_leases FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
    $trig = bc_sdl_acquire_lease(7, 13, 'worker-t', 60, 0);
    $db->query('DROP TRIGGER fail_sdl_ins');
    sdl_test(!$trig['ok'] && $db->query('SELECT state FROM sas_service_attempts WHERE vendor_id=7 AND request_id=13')->fetch_assoc()['state'] === 'held', 'trigger failure rolls back attempt state to held');

    echo "PASS $n service dispatch lease MySQL integration checks ($edition)\n";
} finally {
    $root->query("DROP DATABASE IF EXISTS `$name`");
}
