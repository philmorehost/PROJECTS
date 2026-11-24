<?php
/** Real MySQL 8.0 concurrency, rollback, and unknown-outcome tests for bc-payout-reservations.php. */
if (getenv('BC_PAYOUT_RES_CI') !== '1') throw new RuntimeException('Disposable CI only.');
set_exception_handler(function ($e) {
    $msg = str_replace(['%', "\r", "\n"], ['%25', '', '%0A'], $e->getMessage());
    echo '::error file=VTU/tests/payout_reservations_mysql_test.php,line=' . $e->getLine() . '::' . $msg . "\n";
    exit(1);
});

$edition = getenv('BC_TEST_EDITION') ?: 'SAAS';
if (!in_array($edition, ['SAAS', 'NON-SAAS'], true)) throw new RuntimeException('Invalid edition.');

require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-payout-reservations.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function po_db($name = '') {
    $m = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', $name, 3306);
    $m->set_charset('utf8mb4');
    return $m;
}

if (in_array($argv[1] ?? '', ['race-reserve', 'race-fail-outcome'], true)) {
    $name = $argv[2] ?? '';
    if (!preg_match('/\Abc_po_ci_[a-f0-9]{12}\z/D', $name)) throw new RuntimeException('db');
    $GLOBALS['connection_server'] = po_db($name);
    usleep(150000);
    if ($argv[1] === 'race-reserve') {
        $reqKey = $argv[3] ?? '';
        echo json_encode(bc_payout_reserve(7, 1, 'flutterwave', '058', '0123456789', '40.00', '5.00', 'Rent', $reqKey));
    } else {
        $ref = $argv[3] ?? '';
        $evtKey = hash('sha256', 'fail-' . ($argv[4] ?? '0'));
        $evHash = hash('sha256', 'evidence-fail');
        echo json_encode(bc_payout_observe_outcome(7, $ref, $evtKey, 'confirmed_failure', $evHash, 'FLW-PO-FAIL-1', 0));
    }
    exit;
}

$n = 0;
function po_check($ok, $label) { global $n; if (!$ok) throw new RuntimeException($label); $n++; }

$root = po_db();
$name = 'bc_po_ci_' . bin2hex(random_bytes(6));
$root->query("CREATE DATABASE `$name`");
$db = po_db($name);
$GLOBALS['connection_server'] = $db;

try {
    $db->query('CREATE TABLE sas_users(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, username VARCHAR(225), balance DECIMAL(65,30) DEFAULT 0, status INT DEFAULT 1, is_blocked TINYINT(1) DEFAULT 0, force_password_change TINYINT(1) DEFAULT 0) ENGINE=InnoDB');
    $db->query("INSERT INTO sas_users(id, vendor_id, username, balance, status) VALUES(1, 7, 'alice', 200.00, 1), (2, 8, 'alice', 200.00, 1)");
    $db->query('CREATE TABLE sas_transactions(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, product_unique_id VARCHAR(225), type_alternative VARCHAR(225), reference VARCHAR(225), api_reference VARCHAR(225), username VARCHAR(225), amount DECIMAL(65,30), discounted_amount DECIMAL(65,30), balance_before DECIMAL(65,30), balance_after DECIMAL(65,30), description TEXT, mode VARCHAR(100), api_website VARCHAR(225), status INT) ENGINE=InnoDB');

    $tables_src = file_get_contents(__DIR__ . '/../DGV7.0-' . $edition . '/func/bc-tables.php');
    if (!preg_match('/CREATE TABLE IF NOT EXISTS sas_wallet_mutation_events \(.*?\) ENGINE=InnoDB/s', $tables_src, $ddl)) {
        throw new RuntimeException('Missing sas_wallet_mutation_events DDL');
    }
    $db->query($ddl[0]);

    $mig = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../migrations/20261118_payout_reservations.sql'));
    foreach (array_filter(array_map('trim', explode(';', $mig))) as $stmt) {
        if ($stmt !== '') $db->query($stmt);
    }

    // 1. Atomic reservation -> state='held', replay, conflict, insufficient balance, blocked user
    $rk1 = hash('sha256', 'payout-req-1');
    $r1 = bc_payout_reserve(7, 1, 'flutterwave', '058', '0123456789', '50.00', '10.00', 'Invoice', $rk1);
    po_check($r1['ok'] && $r1['code'] === 'reserved' && $r1['state'] === 'held' && $r1['total_debit'] === '60.00', 'reserves in held state');
    po_check((float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 140.0, 'balance debited atomically');
    po_check((float)$db->query('SELECT balance FROM sas_users WHERE id=2')->fetch_assoc()['balance'] === 200.0, 'other tenant untouched');

    $r1_rep = bc_payout_reserve(7, 1, 'flutterwave', '058', '0123456789', '50.00', '10.00', 'Invoice', $rk1);
    po_check($r1_rep['ok'] && $r1_rep['replayed'] === true && $r1_rep['reference'] === $r1['reference'], 'replay recovers reference');
    po_check((float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 140.0, 'replay does not double debit');

    $r1_conf = bc_payout_reserve(7, 1, 'flutterwave', '058', '0123456789', '90.00', '10.00', 'Invoice', $rk1);
    po_check(!$r1_conf['ok'] && $r1_conf['code'] === 'conflict', 'altered payload conflicts');

    $over = bc_payout_reserve(7, 1, 'flutterwave', '058', '0123456789', '500.00', '10.00', 'Too much', hash('sha256', 'over'));
    po_check(!$over['ok'] && $over['code'] === 'insufficient_balance' && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 140.0, 'overdraft refused');

    $db->query('UPDATE sas_users SET is_blocked=1 WHERE id=1');
    $blk = bc_payout_reserve(7, 1, 'flutterwave', '058', '0123456789', '10.00', '0', 'Blocked', hash('sha256', 'blk'));
    po_check(!$blk['ok'] && $blk['code'] === 'restricted', 'blocked user refused');
    $db->query('UPDATE sas_users SET is_blocked=0 WHERE id=1');

    // 2. Provider observations cannot complete held reservation; cancel_held refunds exactly once
    $obsHeld = bc_payout_observe_outcome(7, $r1['reference'], hash('sha256', 'obs-held'), 'confirmed_success', hash('sha256', 'ev'), 'FLW-X', 0);
    po_check(!$obsHeld['ok'] && $obsHeld['code'] === 'held' && $obsHeld['state'] === 'held', 'held cannot be marked succeeded');

    $cancelKey = hash('sha256', 'cancel-1');
    $reasonHash = hash('sha256', 'user cancelled');
    $can = bc_payout_cancel_held(7, $r1['reference'], $cancelKey, $reasonHash, 0);
    po_check($can['ok'] && $can['code'] === 'cancelled' && $can['state'] === 'cancelled', 'cancel_held moves held -> cancelled');
    po_check((float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 200.0, 'cancel_held refunds full debit');
    $canRep = bc_payout_cancel_held(7, $r1['reference'], $cancelKey, $reasonHash, 0);
    po_check($canRep['ok'] && $canRep['replayed'] === true && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 200.0, 'cancel_held replay does not double refund');

    // 3. Dispatched -> unknown (NO refund) -> confirmed_success (status=1, immutable provider_ref)
    $r2 = bc_payout_reserve(7, 1, 'flutterwave', '058', '0123456789', '70.00', '5.00', 'Vendor pay', hash('sha256', 'payout-req-2'));
    po_check($r2['ok'] && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 125.0, 'second reservation debits 75');
    // Fixture transition to 'dispatched' representing a future authorized worker dispatch
    $stmt = $db->prepare("UPDATE sas_payout_reservations SET state='dispatched' WHERE vendor_id=7 AND reference=?");
    $stmt->bind_param('s', $r2['reference']);
    $stmt->execute();
    $stmt->close();

    $unk = bc_payout_observe_outcome(7, $r2['reference'], hash('sha256', 'obs-unk'), 'unknown', hash('sha256', 'timeout'), 'FLW-PO-2', 0);
    po_check($unk['ok'] && $unk['state'] === 'unknown' && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 125.0, 'unknown outcome never auto-refunds');
    $canUnk = bc_payout_cancel_held(7, $r2['reference'], hash('sha256', 'cancel-unk'), $reasonHash, 1);
    po_check(!$canUnk['ok'] && $canUnk['code'] === 'not_held' && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 125.0, 'cancel_held refuses unknown payout');

    $badRef = bc_payout_observe_outcome(7, $r2['reference'], hash('sha256', 'obs-badref'), 'confirmed_success', hash('sha256', 'ok'), 'FLW-OTHER', 1);
    po_check(!$badRef['ok'] && $badRef['code'] === 'conflict', 'mismatched provider_reference refused');

    $succ = bc_payout_observe_outcome(7, $r2['reference'], hash('sha256', 'obs-succ'), 'confirmed_success', hash('sha256', 'ok'), 'FLW-PO-2', 1);
    po_check($succ['ok'] && $succ['state'] === 'confirmed_success', 'unknown -> confirmed_success');
    po_check((int)$db->query("SELECT status FROM sas_transactions WHERE reference='{$r2['reference']}' LIMIT 1")->fetch_assoc()['status'] === 1, 'sas_transactions updated to status=1');

    // 4. Trigger failure rollback atomicity (balance + wallet event + tx + reservation all rolled back)
    $db->query("CREATE TRIGGER fail_po_res BEFORE INSERT ON sas_payout_reservations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
    $trig = bc_payout_reserve(7, 1, 'flutterwave', '058', '0123456789', '20.00', '0', 'Trig', hash('sha256', 'trig'));
    $db->query('DROP TRIGGER fail_po_res');
    po_check(!$trig['ok'] && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 125.0, 'trigger failure rolls back balance');

    // 5. Outer transaction & MyISAM refusal
    $db->begin_transaction();
    $db->query('UPDATE sas_users SET balance=999 WHERE id=2');
    $outer = bc_payout_reserve(7, 1, 'flutterwave', '058', '0123456789', '10.00', '0', 'Outer', hash('sha256', 'outer'));
    po_check(!$outer['ok'] && $outer['code'] === 'outer_transaction', 'outer tx refused');
    $db->rollback();
    po_check((float)$db->query('SELECT balance FROM sas_users WHERE id=2')->fetch_assoc()['balance'] === 200.0, 'caller outer tx preserved');

    $db->query('ALTER TABLE sas_users ENGINE=MyISAM');
    $myisam = bc_payout_reserve(7, 1, 'flutterwave', '058', '0123456789', '10.00', '0', 'MyISAM', hash('sha256', 'myisam'));
    po_check(!$myisam['ok'] && $myisam['code'] === 'nontransactional', 'MyISAM refused');
    $db->query('ALTER TABLE sas_users ENGINE=InnoDB');

    // 6. Concurrent reservation race (4 workers racing same request_key -> 1 reserved, 3 replayed, 1x debit)
    $db->query('UPDATE sas_users SET balance=100.00 WHERE id=1');
    $raceKey = hash('sha256', 'race-req');
    $procs = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, 'race-reserve', $name, $raceKey], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $procs[] = [$p, $pipes];
    }
    $resCnt = 0; $repCnt = 0; $refs = [];
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        po_check(proc_close($p) === 0 && $err === '', 'clean reserve race worker: ' . $err . $out);
        $r = json_decode($out, true);
        if (($r['code'] ?? '') === 'reserved') $resCnt++;
        elseif (($r['code'] ?? '') === 'replayed') $repCnt++;
        $refs[] = $r['reference'] ?? '';
    }
    po_check($resCnt === 1 && $repCnt === 3 && count(array_unique($refs)) === 1 && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 55.0, 'concurrent reservation debits exactly once');

    // 7. Concurrent confirmed_failure outcome race -> exactly 1 refund, zero double refund!
    $raceRef = $refs[0];
    $stmt = $db->prepare("UPDATE sas_payout_reservations SET state='dispatched' WHERE vendor_id=7 AND reference=?");
    $stmt->bind_param('s', $raceRef);
    $stmt->execute();
    $stmt->close();

    $procs = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, 'race-fail-outcome', $name, $raceRef, (string)$i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $procs[] = [$p, $pipes];
    }
    $recCnt = 0; $finCnt = 0;
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        po_check(proc_close($p) === 0 && $err === '', 'clean fail-outcome race worker: ' . $err . $out);
        $r = json_decode($out, true);
        if (($r['code'] ?? '') === 'recorded') $recCnt++;
        elseif (($r['code'] ?? '') === 'already_final') $finCnt++;
    }
    po_check($recCnt === 1 && $finCnt === 3 && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 100.0, 'concurrent failure refunds exactly once');

    echo "PASS $n payout reservation MySQL integration checks ($edition)\n";
} finally {
    $root->query("DROP DATABASE IF EXISTS `$name`");
}
