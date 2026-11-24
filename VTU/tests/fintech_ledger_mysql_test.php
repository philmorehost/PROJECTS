<?php
/** Real MySQL 8.0 validation for bc_fintech_ledger_post() and bc_fintech_webhook_handle(). */
if (getenv('BC_FINTECH_LEDGER_CI') !== '1') throw new RuntimeException('Disposable CI only.');
set_exception_handler(function ($e) {
    $msg = str_replace(['%', "\r", "\n"], ['%25', '', '%0A'], $e->getMessage());
    echo '::error file=VTU/tests/fintech_ledger_mysql_test.php,line=' . $e->getLine() . '::' . $msg . "\n";
    exit(1);
});

$edition = getenv('BC_TEST_EDITION') ?: 'SAAS';
if (!in_array($edition, ['SAAS', 'NON-SAAS'], true)) throw new RuntimeException('Invalid edition.');

$dir = __DIR__ . '/../DGV7.0-' . $edition . '/func/';
require_once $dir . 'bc-stepup.php';
require_once $dir . 'bc-fintech-schema.php';
require_once $dir . 'bc-fintech-providers.php';
require_once $dir . 'bc-fintech-ledger.php';
require_once $dir . 'bc-fintech-webhook.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function fl_db($name = '') {
    $m = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', $name, 3306);
    $m->set_charset('utf8mb4');
    return $m;
}

if (in_array($argv[1] ?? '', ['race-credit', 'race-debit'], true)) {
    $name = $argv[2] ?? '';
    if (!preg_match('/\Abc_fl_ci_[a-f0-9]{12}\z/D', $name)) throw new RuntimeException('db');
    $GLOBALS['connection_server'] = fl_db($name);
    usleep(150000);
    if ($argv[1] === 'race-credit') {
        echo json_encode(bc_fintech_ledger_post(7, 'alice', 'NGN', 'credit', 25, 'RACE-CRED-1', ['provider' => 'flutterwave', 'event_id' => 'evt-race']));
    } else {
        $seq = (string)($argv[3] ?? '0');
        echo json_encode(bc_fintech_ledger_post(7, 'alice', 'NGN', 'debit', 40, 'RACE-DEB-' . $seq, ['provider' => 'flutterwave']));
    }
    exit;
}

$n = 0;
function fl_check($ok, $label) { global $n; if (!$ok) throw new RuntimeException($label); $n++; }

$root = fl_db();
$name = 'bc_fl_ci_' . bin2hex(random_bytes(6));
$root->query("CREATE DATABASE `$name`");
$db = fl_db($name);
$GLOBALS['connection_server'] = $db;

try {
    $db->query('CREATE TABLE sas_vendors(id INT UNSIGNED PRIMARY KEY, balance DECIMAL(30,2), status INT, is_blocked INT DEFAULT 0) ENGINE=InnoDB');
    $db->query('INSERT INTO sas_vendors VALUES(7, 500.00, 1, 0)');
    $db->query('CREATE TABLE sas_users(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, username VARCHAR(225), email VARCHAR(225), balance DECIMAL(65,30) DEFAULT 0, status INT DEFAULT 1, is_blocked TINYINT(1) DEFAULT 0, account_level INT DEFAULT 1) ENGINE=InnoDB');
    $db->query("INSERT INTO sas_users(id, vendor_id, username, email, balance, status, is_blocked) VALUES(1, 7, 'alice', 'alice@example.test', 100.00, 1, 0), (2, 8, 'alice', 'other@example.test', 100.00, 1, 0)");
    $db->query('CREATE TABLE sas_site_details(vendor_id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $db->query('INSERT INTO sas_site_details VALUES(7)');
    $db->query('CREATE TABLE sas_service_control(vendor_id INT UNSIGNED, service_name VARCHAR(100), status INT DEFAULT 1) ENGINE=InnoDB');
    $db->query('CREATE TABLE sas_transactions(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, product_unique_id VARCHAR(225), type_alternative VARCHAR(225), reference VARCHAR(225), api_reference VARCHAR(225), username VARCHAR(225), amount DECIMAL(65,30), discounted_amount DECIMAL(65,30), balance_before DECIMAL(65,30), balance_after DECIMAL(65,30), description TEXT, mode VARCHAR(100), api_website VARCHAR(225), status INT) ENGINE=InnoDB');
    $db->query('CREATE TABLE sas_user_crypto_wallets(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, username VARCHAR(100) NOT NULL, currency_code VARCHAR(20) NOT NULL, balance DECIMAL(65,18) DEFAULT 0, ledger_balance DECIMAL(65,18) DEFAULT 0, UNIQUE KEY user_currency(vendor_id, username, currency_code)) ENGINE=InnoDB');
    $db->query('CREATE TABLE sas_payment_gateways(vendor_id INT UNSIGNED, gateway_name VARCHAR(225), public_key VARCHAR(500), secret_key VARCHAR(500), encrypt_key VARCHAR(500), percentage VARCHAR(225), status INT UNSIGNED) ENGINE=InnoDB');
    $db->query("INSERT INTO sas_payment_gateways VALUES(7, 'flutterwave', 'pk_flw', 'sk_flw', 'wh_secret_flw', '0', 1)");
    $db->query('CREATE TABLE sas_super_admin_payment_gateways(gateway_name VARCHAR(225), public_key VARCHAR(500), secret_key VARCHAR(500), encrypt_key VARCHAR(500), status INT UNSIGNED) ENGINE=InnoDB');

    $tables_src = file_get_contents($dir . 'bc-tables.php');
    foreach (['sas_secure_payment_intents', 'sas_provider_settlement_claims'] as $tbl) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($tbl, '/') . ' \(.*?\) ENGINE=InnoDB/s', $tables_src, $ddl)) {
            throw new RuntimeException('Missing DDL for ' . $tbl);
        }
        $db->query($ddl[0]);
    }

    fl_check(bc_fintech_schema_ready() === true, 'fintech schema ready in InnoDB');

    // 1. NGN credit + replay + conflict
    $cred = bc_fintech_ledger_post(7, 'alice', 'NGN', 'credit', 250.5, 'FLW-MYSQL-1', ['provider' => 'flutterwave', 'event_id' => 'evt-1']);
    fl_check($cred['ok'] && $cred['reason'] === 'ok' && abs($cred['balance_after'] - 350.5) < 0.0001, 'NGN credit commits');
    fl_check((float)$db->query('SELECT balance FROM sas_users WHERE id=2')->fetch_assoc()['balance'] === 100.0, 'other tenant isolated');
    fl_check((int)$db->query('SELECT COUNT(*) n FROM sas_fintech_ledger')->fetch_assoc()['n'] === 1, 'audit row written');
    fl_check((int)$db->query('SELECT COUNT(*) n FROM sas_transactions')->fetch_assoc()['n'] === 1, 'NGN history mirror written');

    $replay = bc_fintech_ledger_post(7, 'alice', 'NGN', 'credit', 250.5, 'FLW-MYSQL-1', ['provider' => 'flutterwave', 'event_id' => 'evt-1']);
    fl_check($replay['ok'] && $replay['reason'] === 'already_settled' && abs(bc_fintech_ledger_balance(7, 'alice', 'NGN') - 350.5) < 0.0001, 'idempotent replay does not double credit');

    $conflict = bc_fintech_ledger_post(7, 'alice', 'NGN', 'credit', 999, 'FLW-MYSQL-1', ['provider' => 'flutterwave']);
    fl_check(!$conflict['ok'] && $conflict['reason'] === 'settlement_claim_failed', 'conflicting payload refused');

    // 2. Debit & SQL overdraft guard & blocked user
    $deb = bc_fintech_ledger_post(7, 'alice', 'NGN', 'debit', 50.5, 'FLW-DEB-1', ['provider' => 'flutterwave']);
    fl_check($deb['ok'] && abs(bc_fintech_ledger_balance(7, 'alice', 'NGN') - 300.0) < 0.0001, 'NGN debit succeeds');
    $over = bc_fintech_ledger_post(7, 'alice', 'NGN', 'debit', 999.0, 'FLW-DEB-OVER', ['provider' => 'flutterwave']);
    fl_check(!$over['ok'] && $over['reason'] === 'insufficient_balance' && abs(bc_fintech_ledger_balance(7, 'alice', 'NGN') - 300.0) < 0.0001, 'overdraft refused');

    $db->query('UPDATE sas_users SET is_blocked=1 WHERE id=1');
    $blk = bc_fintech_ledger_post(7, 'alice', 'NGN', 'credit', 10, 'FLW-BLK-1', ['provider' => 'flutterwave']);
    fl_check(!$blk['ok'] && $blk['reason'] === 'account_restricted', 'blocked user refused');
    $db->query('UPDATE sas_users SET is_blocked=0 WHERE id=1');

    // 3. Historical pre-upgrade collision guard
    $db->query("INSERT INTO sas_transactions(vendor_id, reference, api_reference, username, amount, status) VALUES(7, 'LEGACY-REF-1', 'LEGACY-API-1', 'alice', 10, 1)");
    $hist = bc_fintech_ledger_post(7, 'alice', 'NGN', 'credit', 10, 'LEGACY-API-1', ['provider' => 'flutterwave']);
    fl_check(!$hist['ok'] && $hist['reason'] === 'historical_reference_requires_reconciliation', 'pre-upgrade NGN receipt refused');

    // 4. Injected trigger failure rolls back balance + claim + ledger atomically
    $claimsBefore = (int)$db->query('SELECT COUNT(*) n FROM sas_provider_settlement_claims')->fetch_assoc()['n'];
    $db->query("CREATE TRIGGER fail_ledger BEFORE INSERT ON sas_fintech_ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
    $trig = bc_fintech_ledger_post(7, 'alice', 'NGN', 'credit', 75, 'FLW-TRIG-1', ['provider' => 'flutterwave']);
    $db->query('DROP TRIGGER fail_ledger');
    fl_check(!$trig['ok'] && abs(bc_fintech_ledger_balance(7, 'alice', 'NGN') - 300.0) < 0.0001, 'trigger failure rolls back balance');
    fl_check((int)$db->query('SELECT COUNT(*) n FROM sas_provider_settlement_claims')->fetch_assoc()['n'] === $claimsBefore, 'trigger failure rolls back settlement claim');

    // Retry after transient failure succeeds once
    $afterTrig = bc_fintech_ledger_post(7, 'alice', 'NGN', 'credit', 75, 'FLW-TRIG-1', ['provider' => 'flutterwave']);
    fl_check($afterTrig['ok'] && abs(bc_fintech_ledger_balance(7, 'alice', 'NGN') - 375.0) < 0.0001, 'retry after rolled-back failure succeeds');

    // 5. Outer transaction refusal preserves caller work
    $db->begin_transaction();
    $db->query('UPDATE sas_users SET balance=999 WHERE id=2');
    $outer = bc_fintech_ledger_post(7, 'alice', 'NGN', 'credit', 10, 'FLW-OUTER-1', ['provider' => 'flutterwave']);
    fl_check(!$outer['ok'] && $outer['reason'] === 'transaction_unavailable', 'outer transaction refused');
    $db->rollback();
    fl_check((float)$db->query('SELECT balance FROM sas_users WHERE id=2')->fetch_assoc()['balance'] === 100.0, 'caller outer transaction not committed');

    // 6. Non-InnoDB table refused
    $db->query('ALTER TABLE sas_fintech_ledger ENGINE=MyISAM');
    $myisam = bc_fintech_ledger_post(7, 'alice', 'NGN', 'credit', 10, 'FLW-MYISAM-1', ['provider' => 'flutterwave']);
    fl_check(!$myisam['ok'] && $myisam['reason'] === 'transactional_schema_required', 'MyISAM ledger refused');
    $db->query('ALTER TABLE sas_fintech_ledger ENGINE=InnoDB');

    // 7. Stablecoin USDC atomicity + MyISAM crypto wallet refusal
    $usdc = bc_fintech_ledger_post(7, 'alice', 'USDC', 'credit', 12.5, 'KL-MYSQL-1', ['provider' => 'klasha']);
    fl_check($usdc['ok'] && abs(bc_fintech_ledger_balance(7, 'alice', 'USDC') - 12.5) < 0.0001, 'USDC credit succeeds');
    $usdcDeb = bc_fintech_ledger_post(7, 'alice', 'USDC', 'debit', 2.5, 'KL-MYSQL-2', ['provider' => 'klasha']);
    fl_check($usdcDeb['ok'] && abs(bc_fintech_ledger_balance(7, 'alice', 'USDC') - 10.0) < 0.0001, 'USDC debit succeeds');
    $db->query('ALTER TABLE sas_user_crypto_wallets ENGINE=MyISAM');
    $myisamCrypto = bc_fintech_ledger_post(7, 'alice', 'USDC', 'credit', 1, 'KL-MYISAM', ['provider' => 'klasha']);
    fl_check(!$myisamCrypto['ok'] && $myisamCrypto['reason'] === 'transactional_schema_required', 'MyISAM crypto wallet refused');
    $db->query('ALTER TABLE sas_user_crypto_wallets ENGINE=InnoDB');

    // 8. Multi-process concurrency: 4 credits racing same reference, and 4 debits racing 125 NGN
    $db->query('UPDATE sas_users SET balance=100.00 WHERE id=1');
    $procs = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, 'race-credit', $name], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $procs[] = [$p, $pipes];
    }
    $okCnt = 0; $dupCnt = 0;
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        fl_check(proc_close($p) === 0 && $err === '', 'clean credit race worker: ' . $err . $out);
        $r = json_decode($out, true);
        if (($r['reason'] ?? '') === 'ok') $okCnt++;
        elseif (($r['reason'] ?? '') === 'already_settled') $dupCnt++;
    }
    fl_check($okCnt === 1 && $dupCnt === 3 && abs(bc_fintech_ledger_balance(7, 'alice', 'NGN') - 125.0) < 0.0001, 'concurrent credit settles exactly once');

    // Balance is 125.00; 4 concurrent 40.00 debits -> max 3 can succeed (120.00), leaving 5.00
    $procs = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, 'race-debit', $name, (string)$i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $procs[] = [$p, $pipes];
    }
    $debOk = 0; $debFail = 0;
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        fl_check(proc_close($p) === 0 && $err === '', 'clean debit race worker: ' . $err . $out);
        $r = json_decode($out, true);
        if (!empty($r['ok'])) $debOk++; else $debFail++;
    }
    fl_check($debOk === 3 && $debFail === 1 && abs(bc_fintech_ledger_balance(7, 'alice', 'NGN') - 5.0) < 0.0001, 'concurrent debit never overdrafts');

    // 9. Full bc_fintech_webhook_handle() pipeline in real MySQL with intent binding
    $db->query("INSERT INTO sas_secure_payment_intents(reference, vendor_id, username, provider, currency, amount) VALUES('FLW-INTENT-1', 7, 'alice', 'flutterwave', 'NGN', 100.00)");
    $rawBody = json_encode(['event' => 'charge.completed', 'data' => ['id' => 'evt-flw-100', 'tx_ref' => 'FLW-INTENT-1', 'amount' => 100, 'currency' => 'NGN', 'status' => 'successful']]);
    $forged = bc_fintech_webhook_handle('flutterwave', 7, $rawBody, ['verif-hash' => 'wrong'], static fn() => ['ok' => true]);
    fl_check(!$forged['ok'] && $forged['reason'] === 'signature_mismatch' && (int)$db->query('SELECT COUNT(*) n FROM sas_webhook_events')->fetch_assoc()['n'] === 0, 'forged webhook signature leaves no claim');

    $mismatch = bc_fintech_webhook_handle('flutterwave', 7, $rawBody, ['verif-hash' => 'wh_secret_flw'], static fn() => [
        'ok' => true, 'reference' => 'FLW-INTENT-1', 'amount' => 100.0, 'gross_amount' => 50.0, 'currency' => 'NGN', 'status' => 'successful',
    ]);
    fl_check(!$mismatch['ok'] && $mismatch['reason'] === 'payment_intent_mismatch' && (int)$db->query('SELECT COUNT(*) n FROM sas_webhook_events')->fetch_assoc()['n'] === 0, 'intent mismatch releases webhook claim');

    $good = bc_fintech_webhook_handle('flutterwave', 7, $rawBody, ['verif-hash' => 'wh_secret_flw'], static fn() => [
        'ok' => true, 'reference' => 'FLW-INTENT-1', 'amount' => 98.5, 'gross_amount' => 100.0, 'fee' => 1.5, 'currency' => 'NGN', 'status' => 'successful',
    ]);
    fl_check($good['ok'] && $good['reason'] === 'credited' && abs(bc_fintech_ledger_balance(7, 'alice', 'NGN') - 103.5) < 0.0001, 'valid webhook credits net via ledger');
    $dupHook = bc_fintech_webhook_handle('flutterwave', 7, $rawBody, ['verif-hash' => 'wh_secret_flw'], static function () { throw new RuntimeException('must not re-verify duplicate'); });
    fl_check($dupHook['ok'] && $dupHook['reason'] === 'already_processed' && abs(bc_fintech_ledger_balance(7, 'alice', 'NGN') - 103.5) < 0.0001, 'duplicate webhook delivery skips verifier and credit');

    echo "PASS $n fintech ledger & webhook MySQL integration checks ($edition)\n";
} finally {
    $root->query("DROP DATABASE IF EXISTS `$name`");
}
