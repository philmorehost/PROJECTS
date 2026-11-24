<?php
/** Real MySQL 8.0 concurrency, PCI-safe masked-PAN guard, and rollback tests for bc-virtualcard-settlement.php. */
if (getenv('BC_VIRTUALCARD_SETTLE_CI') !== '1') throw new RuntimeException('Disposable CI only.');
set_exception_handler(function ($e) {
    $msg = str_replace(['%', "\r", "\n"], ['%25', '', '%0A'], $e->getMessage());
    echo '::error file=VTU/tests/virtualcard_settlement_mysql_test.php,line=' . $e->getLine() . '::' . $msg . "\n";
    exit(1);
});

$edition = getenv('BC_TEST_EDITION') ?: 'SAAS';
if (!in_array($edition, ['SAAS', 'NON-SAAS'], true)) throw new RuntimeException('Invalid edition.');

require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-virtualcard-settlement.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function vc_db($name = '') {
    $m = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', $name, 3306);
    $m->set_charset('utf8mb4');
    return $m;
}

if (($argv[1] ?? '') === 'race-issue') {
    $name = $argv[2] ?? '';
    if (!preg_match('/\Abc_vc_ci_[a-f0-9]{12}\z/D', $name)) throw new RuntimeException('db');
    $GLOBALS['connection_server'] = vc_db($name);
    usleep(150000);
    echo json_encode(bc_vc_reserve_issue(7, 1, '10.00', '2.00', '1500.000000', 'Travel Card', $argv[3] ?? ''));
    exit;
}

$n = 0;
function vc_check($ok, $label) { global $n; if (!$ok) throw new RuntimeException($label); $n++; }

$root = vc_db();
$name = 'bc_vc_ci_' . bin2hex(random_bytes(6));
$root->query("CREATE DATABASE `$name`");
$db = vc_db($name);
$GLOBALS['connection_server'] = $db;

try {
    $db->query('CREATE TABLE sas_users(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, username VARCHAR(225), balance DECIMAL(65,30) DEFAULT 0, status INT DEFAULT 1, is_blocked TINYINT(1) DEFAULT 0, force_password_change TINYINT(1) DEFAULT 0) ENGINE=InnoDB');
    $db->query("INSERT INTO sas_users(id, vendor_id, username, balance, status) VALUES(1, 7, 'alice', 60000.00, 1), (2, 7, 'blocked', 60000.00, 1), (3, 8, 'alice', 60000.00, 1)");
    $db->query('UPDATE sas_users SET is_blocked=1 WHERE id=2');
    $db->query('CREATE TABLE sas_transactions(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, product_unique_id VARCHAR(225), type_alternative VARCHAR(225), reference VARCHAR(225), api_reference VARCHAR(225), username VARCHAR(225), amount DECIMAL(65,30), discounted_amount DECIMAL(65,30), balance_before DECIMAL(65,30), balance_after DECIMAL(65,30), description TEXT, mode VARCHAR(100), api_website VARCHAR(225), status INT) ENGINE=InnoDB');
    $db->query("CREATE TABLE sas_virtual_cards_v2(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT, username VARCHAR(100), reference VARCHAR(100) UNIQUE, provider_card_id VARCHAR(255), card_name VARCHAR(255), masked_pan VARCHAR(25), expiry_month VARCHAR(5), expiry_year VARCHAR(5), cvv VARCHAR(10), balance_usd DECIMAL(20,2) DEFAULT 0.00, status ENUM('active','frozen','terminated') DEFAULT 'active', is_frozen_auto TINYINT(1) DEFAULT 0, metadata TEXT, provider VARCHAR(50) DEFAULT 'flutterwave', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");

    $tables_src = file_get_contents(__DIR__ . '/../DGV7.0-' . $edition . '/func/bc-tables.php');
    if (!preg_match('/CREATE TABLE IF NOT EXISTS sas_wallet_mutation_events \(.*?\) ENGINE=InnoDB/s', $tables_src, $ddl)) {
        throw new RuntimeException('Missing sas_wallet_mutation_events DDL');
    }
    $db->query($ddl[0]);

    $mig = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../migrations/20261118_virtualcard_settlement.sql'));
    foreach (array_filter(array_map('trim', explode(';', $mig))) as $stmt) {
        if ($stmt !== '') $db->query($stmt);
    }

    // 1. Issue reservation: (10 + 2) * 1500 = 18000.00 NGN
    $rk1 = hash('sha256', 'vc-issue-1');
    $r1 = bc_vc_reserve_issue(7, 1, '10.00', '2.00', '1500.000000', 'Alice Card', $rk1);
    vc_check($r1['ok'] && $r1['code'] === 'reserved' && $r1['state'] === 'held' && $r1['total_ngn'] === '18000.00', 'reserves card issue in held state');
    vc_check((float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 42000.0, 'alice debited atomically');
    vc_check((float)$db->query('SELECT balance FROM sas_users WHERE id=3')->fetch_assoc()['balance'] === 60000.0, 'other tenant isolated');

    // Replay & conflict & blocked user
    $rep = bc_vc_reserve_issue(7, 1, '10.00', '2.00', '1500.000000', 'Alice Card', $rk1);
    vc_check($rep['ok'] && $rep['replayed'] === true && $rep['reference'] === $r1['reference'], 'issue replay returns original hold');
    $conf = bc_vc_reserve_issue(7, 1, '15.00', '2.00', '1500.000000', 'Alice Card', $rk1);
    vc_check(!$conf['ok'] && $conf['code'] === 'conflict', 'changed USD amount conflicts');
    vc_check(bc_vc_reserve_issue(7, 2, '10.00', '2.00', '1500.000000', 'Blocked', hash('sha256', 'blk'))['code'] === 'restricted', 'blocked user refused');

    // Cancel held restores balance once
    $can = bc_vc_cancel_held(7, $r1['reference'], hash('sha256', 'can-1'), hash('sha256', 'reason'), 0);
    vc_check($can['ok'] && $can['state'] === 'cancelled' && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 60000.0, 'cancel_held restores balance');

    // 2. Dispatched -> unknown (never auto-refunds) -> PCI check -> confirmed_success
    $r2 = bc_vc_reserve_issue(7, 1, '10.00', '2.00', '1500.000000', 'Alice Card', hash('sha256', 'vc-issue-2'));
    $stmt = $db->prepare("UPDATE sas_virtualcard_orders SET state='dispatched' WHERE vendor_id=7 AND reference=?");
    $stmt->bind_param('s', $r2['reference']);
    $stmt->execute();
    $stmt->close();

    $unk = bc_vc_observe_outcome(7, $r2['reference'], hash('sha256', 'unk-2'), 'unknown', hash('sha256', 'ev-unk'), 'FLW-VC-2', 0);
    vc_check($unk['ok'] && $unk['state'] === 'unknown' && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 42000.0, 'unknown outcome never auto-refunds');

    $badPci = bc_vc_observe_outcome(7, $r2['reference'], hash('sha256', 'bad-pci'), 'confirmed_success', hash('sha256', 'ev-bad'), 'FLW-VC-2', 1, ['masked_pan' => '**** **** **** 4242', 'expiry_month' => '08', 'expiry_year' => '29', 'cvv' => '999']);
    vc_check(!$badPci['ok'] && $badPci['code'] === 'invalid_card_data', 'refuses persisting CVV');

    $succ = bc_vc_observe_outcome(7, $r2['reference'], hash('sha256', 'succ-2'), 'confirmed_success', hash('sha256', 'ev-succ'), 'FLW-VC-2', 1, ['masked_pan' => '**** **** **** 4242', 'expiry_month' => '08', 'expiry_year' => '29', 'cvv' => '']);
    vc_check($succ['ok'] && $succ['state'] === 'confirmed_success', 'unknown -> confirmed_success');
    $cardRow = $db->query("SELECT masked_pan, cvv, balance_usd, status FROM sas_virtual_cards_v2 WHERE vendor_id=7 AND reference='{$r2['reference']}' LIMIT 1")->fetch_assoc();
    vc_check($cardRow && $cardRow['masked_pan'] === '**** **** **** 4242' && $cardRow['cvv'] === '' && (float)$cardRow['balance_usd'] === 10.0 && $cardRow['status'] === 'active', 'card created atomically with masked PAN and empty CVV');

    // 3. Fund existing Flutterwave card (5 + 0.50) * 1500 = 8250.00 NGN -> confirmed_success increments balance_usd to 15.00
    $f1 = bc_vc_reserve_fund(7, 1, $r2['reference'], '5.00', '0.50', '1500.000000', hash('sha256', 'vc-fund-1'));
    vc_check($f1['ok'] && $f1['total_ngn'] === '8250.00' && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 33750.0, 'fund reservation debited NGN');
    $stmt = $db->prepare("UPDATE sas_virtualcard_orders SET state='dispatched' WHERE vendor_id=7 AND reference=?");
    $stmt->bind_param('s', $f1['reference']);
    $stmt->execute();
    $stmt->close();
    $fSucc = bc_vc_observe_outcome(7, $f1['reference'], hash('sha256', 'fund-succ'), 'confirmed_success', hash('sha256', 'ev-f'), 'FLW-F-1', 0);
    vc_check($fSucc['ok'] && (float)$db->query("SELECT balance_usd FROM sas_virtual_cards_v2 WHERE reference='{$r2['reference']}'")->fetch_assoc()['balance_usd'] === 15.0, 'card USD balance credited atomically');

    // Frozen card and retired provider card refused
    $db->query("UPDATE sas_virtual_cards_v2 SET status='frozen' WHERE reference='{$r2['reference']}'");
    vc_check(bc_vc_reserve_fund(7, 1, $r2['reference'], '5.00', '0.50', '1500.000000', hash('sha256', 'vc-fund-frozen'))['code'] === 'card_unavailable', 'frozen card refused');

    // 4. Confirmed failure refunds once
    $r3 = bc_vc_reserve_issue(7, 1, '10.00', '2.00', '1500.000000', 'Fail Card', hash('sha256', 'vc-issue-3'));
    $stmt = $db->prepare("UPDATE sas_virtualcard_orders SET state='dispatched' WHERE vendor_id=7 AND reference=?");
    $stmt->bind_param('s', $r3['reference']);
    $stmt->execute();
    $stmt->close();
    $fail = bc_vc_observe_outcome(7, $r3['reference'], hash('sha256', 'fail-3'), 'confirmed_failure', hash('sha256', 'ev-fail'), '', 0);
    vc_check($fail['ok'] && $fail['state'] === 'confirmed_failure' && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 33750.0, 'confirmed_failure refunds once');

    // 5. Outer tx, MyISAM refusal, trigger rollback
    $db->begin_transaction();
    $db->query('UPDATE sas_users SET balance=999 WHERE id=3');
    vc_check(bc_vc_reserve_issue(7, 1, '10.00', '2.00', '1500.000000', 'Outer', hash('sha256', 'vc-outer'))['code'] === 'outer_transaction', 'outer tx refused');
    $db->rollback();
    vc_check((float)$db->query('SELECT balance FROM sas_users WHERE id=3')->fetch_assoc()['balance'] === 60000.0, 'caller outer tx untouched');

    $db->query('ALTER TABLE sas_users ENGINE=MyISAM');
    vc_check(bc_vc_reserve_issue(7, 1, '10.00', '2.00', '1500.000000', 'MyISAM', hash('sha256', 'vc-myisam'))['code'] === 'nontransactional', 'MyISAM refused');
    $db->query('ALTER TABLE sas_users ENGINE=InnoDB');

    $db->query("CREATE TRIGGER fail_vc_ord BEFORE INSERT ON sas_virtualcard_orders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
    $trig = bc_vc_reserve_issue(7, 1, '10.00', '2.00', '1500.000000', 'Trig', hash('sha256', 'vc-trig'));
    $db->query('DROP TRIGGER fail_vc_ord');
    vc_check(!$trig['ok'] && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 33750.0, 'trigger failure rolls back debit');

    // 6. Concurrent issue race (4 workers same key -> 1 reserved, 3 replayed, 1x debit)
    $db->query('UPDATE sas_users SET balance=30000.00 WHERE id=1');
    $raceKey = hash('sha256', 'vc-race-1');
    $procs = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, 'race-issue', $name, $raceKey], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $procs[] = [$p, $pipes];
    }
    $resCnt = 0; $repCnt = 0;
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        vc_check(proc_close($p) === 0 && $err === '', 'clean vc race worker: ' . $err . $out);
        $r = json_decode($out, true);
        if (($r['code'] ?? '') === 'reserved') $resCnt++;
        elseif (($r['code'] ?? '') === 'replayed') $repCnt++;
    }
    vc_check($resCnt === 1 && $repCnt === 3 && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 12000.0, 'concurrent issue debits once');

    echo "PASS $n virtualcard settlement MySQL integration checks ($edition)\n";
} finally {
    $root->query("DROP DATABASE IF EXISTS `$name`");
}
