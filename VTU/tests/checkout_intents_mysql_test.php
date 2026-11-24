<?php
/** Real MySQL 8.0 concurrency, intent binding, expiry, and rollback tests for bc-checkout-intents.php. */
if (getenv('BC_CHECKOUT_INTENT_CI') !== '1') throw new RuntimeException('Disposable CI only.');
set_exception_handler(function ($e) {
    $msg = str_replace(['%', "\r", "\n"], ['%25', '', '%0A'], $e->getMessage());
    echo '::error file=VTU/tests/checkout_intents_mysql_test.php,line=' . $e->getLine() . '::' . $msg . "\n";
    exit(1);
});

$edition = getenv('BC_TEST_EDITION') ?: 'SAAS';
if (!in_array($edition, ['SAAS', 'NON-SAAS'], true)) throw new RuntimeException('Invalid edition.');

require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-checkout-intents.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function chk_db($name = '') {
    $m = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', $name, 3306);
    $m->set_charset('utf8mb4');
    return $m;
}

if (($argv[1] ?? '') === 'race-create') {
    $name = $argv[2] ?? '';
    if (!preg_match('/\Abc_chk_ci_[a-f0-9]{12}\z/D', $name)) throw new RuntimeException('db');
    $GLOBALS['connection_server'] = chk_db($name);
    usleep(150000);
    echo json_encode(bc_chk_create_intent(7, 1, 'flutterwave', 'NGN', '5000.00', 900, $argv[3] ?? ''));
    exit;
}

$n = 0;
function chk_test($ok, $label) { global $n; if (!$ok) throw new RuntimeException($label); $n++; }

$root = chk_db();
$name = 'bc_chk_ci_' . bin2hex(random_bytes(6));
$root->query("CREATE DATABASE `$name`");
$db = chk_db($name);
$GLOBALS['connection_server'] = $db;

try {
    $db->query('CREATE TABLE sas_users(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, username VARCHAR(225), balance DECIMAL(65,30) DEFAULT 0, status INT DEFAULT 1, is_blocked TINYINT(1) DEFAULT 0, force_password_change TINYINT(1) DEFAULT 0) ENGINE=InnoDB');
    $db->query("INSERT INTO sas_users(id, vendor_id, username, status) VALUES(1, 7, 'alice', 1), (2, 7, 'blocked', 1), (3, 8, 'alice', 1)");
    $db->query('UPDATE sas_users SET is_blocked=1 WHERE id=2');

    $tables_src = file_get_contents(__DIR__ . '/../DGV7.0-' . $edition . '/func/bc-tables.php');
    if (!preg_match('/CREATE TABLE IF NOT EXISTS sas_secure_payment_intents \(.*?\) ENGINE=InnoDB/s', $tables_src, $ddl)) {
        throw new RuntimeException('Missing sas_secure_payment_intents DDL');
    }
    $db->query($ddl[0]);

    $mig = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../migrations/20261118_checkout_intent_lifecycle.sql'));
    foreach (array_filter(array_map('trim', explode(';', $mig))) as $stmt) {
        if ($stmt !== '') $db->query($stmt);
    }

    // 1. Create paired intent, verify sas_secure_payment_intents row, replay, conflict, blocked user
    $rk1 = hash('sha256', 'chk-1');
    $c1 = bc_chk_create_intent(7, 1, 'flutterwave', 'NGN', '2500.00', 900, $rk1);
    chk_test($c1['ok'] && $c1['code'] === 'created' && $c1['state'] === 'created', 'creates checkout intent');
    $sec = $db->query("SELECT username, provider, currency, amount FROM sas_secure_payment_intents WHERE vendor_id=7 AND reference='{$c1['reference']}' LIMIT 1")->fetch_assoc();
    chk_test($sec && $sec['username'] === 'alice' && $sec['provider'] === 'flutterwave' && $sec['currency'] === 'NGN' && $sec['amount'] === '2500.00', 'paired sas_secure_payment_intents created');

    $rep = bc_chk_create_intent(7, 1, 'flutterwave', 'NGN', '2500.00', 900, $rk1);
    chk_test($rep['ok'] && $rep['replayed'] === true && $rep['reference'] === $c1['reference'], 'replay returns original intent');
    chk_test(bc_chk_create_intent(7, 1, 'flutterwave', 'NGN', '3000.00', 900, $rk1)['code'] === 'conflict', 'changed amount conflicts');
    chk_test(bc_chk_create_intent(7, 2, 'flutterwave', 'NGN', '2500.00', 900, hash('sha256', 'blk'))['code'] === 'restricted', 'blocked user refused');

    // 2. Attach provider -> provider_pending -> reconcile matching settlement -> settled + idempotent
    $att = bc_chk_attach_provider(7, $c1['reference'], hash('sha256', 'att-1'), 'FLW-LINK-1', 0);
    chk_test($att['ok'] && $att['state'] === 'provider_pending' && $att['version'] === 1, 'attach provider moves to provider_pending');

    $set = bc_chk_reconcile_settlement(7, $c1['reference'], hash('sha256', 'set-1'), 'flutterwave', 'NGN', '2500.00', 'FLW-LINK-1', hash('sha256', 'ev-1'), 1);
    chk_test($set['ok'] && $set['state'] === 'settled' && $set['version'] === 2, 'matching settlement settles intent');
    $setAgain = bc_chk_reconcile_settlement(7, $c1['reference'], hash('sha256', 'set-2'), 'flutterwave', 'NGN', '2500.00', 'FLW-LINK-1', hash('sha256', 'ev-2'), 2);
    chk_test($setAgain['ok'] && $setAgain['code'] === 'already_final', 'settled intent is terminal idempotent');

    // 3. Mismatched gross amount holds intent as mismatch_held
    $c2 = bc_chk_create_intent(7, 1, 'payhub', 'NGN', '5000.00', 900, hash('sha256', 'chk-2'));
    $mis = bc_chk_reconcile_settlement(7, $c2['reference'], hash('sha256', 'mis-1'), 'payhub', 'NGN', '4900.00', 'PH-TX-2', hash('sha256', 'ev-m'), 0);
    chk_test(!$mis['ok'] && $mis['code'] === 'mismatch' && $mis['state'] === 'mismatch_held', 'amount mismatch moves intent to mismatch_held');

    // 4. Expiry and cancellation lifecycle
    $c3 = bc_chk_create_intent(7, 1, 'paystack', 'NGN', '1000.00', 900, hash('sha256', 'chk-3'));
    chk_test(!bc_chk_close_intent(7, $c3['reference'], 'expire', hash('sha256', 'exp-early'), hash('sha256', 'r'), 0)['ok'], 'cannot expire before expires_at');
    $stmt = $db->prepare('UPDATE sas_checkout_intents SET expires_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE vendor_id=7 AND reference=?');
    $stmt->bind_param('s', $c3['reference']);
    $stmt->execute();
    $stmt->close();
    $exp = bc_chk_close_intent(7, $c3['reference'], 'expire', hash('sha256', 'exp-ok'), hash('sha256', 'r'), 0);
    chk_test($exp['ok'] && $exp['state'] === 'expired', 'expires intent past expires_at');

    $c4 = bc_chk_create_intent(7, 1, 'flutterwave', 'USD', '10.00', 900, hash('sha256', 'chk-4'));
    $can = bc_chk_close_intent(7, $c4['reference'], 'cancel', hash('sha256', 'can-ok'), hash('sha256', 'r'), 0);
    chk_test($can['ok'] && $can['state'] === 'cancelled', 'cancels unsettled intent');

    // 5. Outer tx, MyISAM refusal, trigger rollback
    $db->begin_transaction();
    $db->query("INSERT INTO sas_secure_payment_intents(reference, vendor_id, username, provider, currency, amount) VALUES('OUTER-1', 8, 'alice', 'flutterwave', 'NGN', 100.00)");
    chk_test(bc_chk_create_intent(7, 1, 'flutterwave', 'NGN', '100.00', 900, hash('sha256', 'chk-outer'))['code'] === 'outer_transaction', 'outer tx refused');
    $db->rollback();
    chk_test((int)$db->query("SELECT COUNT(*) n FROM sas_secure_payment_intents WHERE reference='OUTER-1'")->fetch_assoc()['n'] === 0, 'caller outer tx untouched');

    $db->query('ALTER TABLE sas_users ENGINE=MyISAM');
    chk_test(bc_chk_create_intent(7, 1, 'flutterwave', 'NGN', '100.00', 900, hash('sha256', 'chk-myisam'))['code'] === 'nontransactional', 'MyISAM refused');
    $db->query('ALTER TABLE sas_users ENGINE=InnoDB');

    $db->query("CREATE TRIGGER fail_chk_int BEFORE INSERT ON sas_checkout_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
    $trig = bc_chk_create_intent(7, 1, 'flutterwave', 'NGN', '100.00', 900, hash('sha256', 'chk-trig'));
    $db->query('DROP TRIGGER fail_chk_int');
    chk_test(!$trig['ok'] && (int)$db->query("SELECT COUNT(*) n FROM sas_secure_payment_intents WHERE vendor_id=7 AND amount=100.00")->fetch_assoc()['n'] === 0, 'trigger failure rolls back paired sas_secure_payment_intents');

    // 6. Concurrent create race (4 workers same key -> 1 created, 3 replayed, 1 paired row)
    $raceKey = hash('sha256', 'chk-race-1');
    $procs = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, 'race-create', $name, $raceKey], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $procs[] = [$p, $pipes];
    }
    $cre = 0; $repCnt = 0;
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        chk_test(proc_close($p) === 0 && $err === '', 'clean chk race worker: ' . $err . $out);
        $r = json_decode($out, true);
        if (($r['code'] ?? '') === 'created') $cre++;
        elseif (($r['code'] ?? '') === 'replayed') $repCnt++;
    }
    chk_test($cre === 1 && $repCnt === 3 && (int)$db->query('SELECT COUNT(*) n FROM sas_secure_payment_intents WHERE vendor_id=7 AND amount=5000.00')->fetch_assoc()['n'] === 2, 'concurrent create produces single intent');

    echo "PASS $n checkout intent MySQL integration checks ($edition)\n";
} finally {
    $root->query("DROP DATABASE IF EXISTS `$name`");
}
