<?php
/** Paired PayHub -> VTU disposable MySQL integration tests (no real network or money). */
if (getenv('PAYHUB_PAIRED_CI') !== '1') throw new RuntimeException('Disposable CI only.');
set_exception_handler(function ($e) {
    $msg = str_replace(['%', "\r", "\n"], ['%25', '', '%0A'], $e->getMessage());
    echo '::error file=VTU/tests/payhub_paired_stack_mysql_test.php,line=' . $e->getLine() . '::' . $msg . "\n";
    exit(1);
});

$edition = getenv('BC_TEST_EDITION') ?: 'SAAS';
if (!in_array($edition, ['SAAS', 'NON-SAAS'], true)) throw new RuntimeException('Invalid edition.');

require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-payhub-deposits.php';
require_once __DIR__ . '/../../PAYHUB/includes/webhook-recovery.php';

$n = 0;
function pcheck($ok, $label) { global $n; if (!$ok) throw new RuntimeException($label); $n++; }
function prefused(callable $fn, string $code) {
    try { $fn(); throw new RuntimeException("Expected refusal $code"); }
    catch (Throwable $e) { pcheck($e->getMessage() === $code, "Expected $code got " . $e->getMessage()); }
}
function run_sql_file(PDO $db, string $file) {
    $native = $db->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    try {
        $stmt = $db->query(file_get_contents($file));
        do { if ($stmt->columnCount()) $stmt->fetchAll(); } while ($stmt->nextRowset());
        $stmt->closeCursor();
    } finally { $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, $native); }
}

/** Exact PayHub /api/transaction/verify.php response contract from persisted PayHub row. */
function paired_verify_response(PDO $ph, int $merchantId, string $ref): array {
    $q = $ph->prepare('SELECT * FROM transactions WHERE reference = ? AND user_id = ? LIMIT 1');
    $q->execute([$ref, $merchantId]);
    $tx = $q->fetch(PDO::FETCH_ASSOC);
    if (!$tx) throw new RuntimeException('verification_unavailable');
    return [
        'status' => true,
        'message' => 'Transaction retrieved',
        'paid' => ($tx['status'] === 'success'),
        'data' => [
            'id' => $tx['id'],
            'status' => $tx['status'],
            'payment_status' => $tx['status'],
            'paid' => ($tx['status'] === 'success'),
            'reference' => $tx['reference'],
            'receiving_account_number' => $tx['receiving_account_number'] ?? null,
            'deposit_evidence_version' => !empty($tx['receiving_account_number']) ? 1 : null,
            'amount' => to_minor_units($tx['amount']),
            'currency' => (string)($tx['currency'] ?? ''),
            'domain' => ((int)$tx['is_test'] === 1) ? 'test' : 'live',
            'gateway_amount' => ($tx['gateway_amount'] !== null && $tx['gateway_amount'] !== '') ? to_minor_units($tx['gateway_amount']) : null,
            'fee_amount' => to_minor_units($tx['fee_amount'] ?? 0),
            'settled_amount' => to_minor_units($tx['settled_amount'] ?? 0),
        ],
    ];
}

function ph_payload_from_row(array $tx, string $domain): array {
    $gross = to_minor_units($tx['gateway_amount']);
    $req = to_minor_units($tx['amount']);
    $fee = to_minor_units($tx['fee_amount']);
    $net = to_minor_units($tx['settled_amount']);
    return [
        'event' => 'charge.success',
        'data' => [
            'id' => (int)$tx['gateway_reference'],
            'reference' => $tx['reference'],
            'receiving_account_number' => $tx['receiving_account_number'],
            'deposit_evidence_version' => !empty($tx['receiving_account_number']) ? 1 : null,
            'money_contract_version' => 1,
            'amount_unit' => 'minor',
            'amount_basis' => 'gateway',
            'amount' => $gross,
            'requested_amount' => $req,
            'gateway_amount' => $gross,
            'fee_amount' => $fee,
            'settled_amount' => $net,
            'status' => 'success',
            'domain' => $domain,
            'currency' => $tx['currency'],
            'customer' => ['email' => $tx['customer_email']],
            'metadata' => ['username' => 'attacker', 'vendor_id' => 99],
            'channel' => $tx['payment_method'],
            'paid_at' => $tx['created_at'],
        ],
    ];
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$phRoot = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'disposable-ci-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$vtuRoot = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', '', 3306);

$suffix = bin2hex(random_bytes(6));
$phName = 'payhub_paired_ci_' . $suffix;
$vtuName = 'vtu_paired_ci_' . $suffix;

$phRoot->exec("CREATE DATABASE `$phName`");
$vtuRoot->query("CREATE DATABASE `$vtuName`");

$liveSecret = 'sk_live_' . str_repeat('a', 32);
$testSecret = 'sk_test_' . str_repeat('b', 32);

try {
    $ph = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$phName", 'root', 'disposable-ci-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $ph->exec('CREATE TABLE users(id INT AUTO_INCREMENT PRIMARY KEY, webhook_url VARCHAR(255), secret_key VARCHAR(100), test_secret_key VARCHAR(100)) ENGINE=InnoDB');
    $ph->prepare('INSERT INTO users(id, webhook_url, secret_key, test_secret_key) VALUES(1, ?, ?, ?)')->execute(['https://vtu.example.test/users-payhub.php', $liveSecret, $testSecret]);
    $ph->exec("CREATE TABLE transactions(id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, reference VARCHAR(100), amount DECIMAL(15,2), gateway_amount DECIMAL(15,2), fee_amount DECIMAL(15,2), settled_amount DECIMAL(15,2), status VARCHAR(20), is_test TINYINT DEFAULT 0, currency VARCHAR(10) DEFAULT 'NGN', gateway_reference VARCHAR(100), customer_email VARCHAR(255), payment_method VARCHAR(50), metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $ph->exec('CREATE TABLE ledger(id INT AUTO_INCREMENT PRIMARY KEY, transaction_id INT, amount DECIMAL(15,2)) ENGINE=InnoDB');
    run_sql_file($ph, __DIR__ . '/../../PAYHUB/install/20261118_deposit_evidence.sql');
    run_sql_file($ph, __DIR__ . '/../../PAYHUB/install/20261118_webhook_log_compat.sql');
    run_sql_file($ph, __DIR__ . '/../../PAYHUB/install/20261118_webhook_outbox.sql');
    run_sql_file($ph, __DIR__ . '/../../PAYHUB/install/20261118_webhook_retry_claims.sql');
    run_sql_file($ph, __DIR__ . '/../../PAYHUB/install/20261118_webhook_initial_claims.sql');

    $vtu = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', $vtuName, 3306);
    $vtu->query('CREATE TABLE sas_users(id INT PRIMARY KEY, vendor_id INT, username VARCHAR(225), balance DECIMAL(30,2), status INT, is_blocked INT) ENGINE=InnoDB');
    $vtu->query('CREATE TABLE sas_vendors(id INT PRIMARY KEY, balance DECIMAL(30,2), status INT, is_blocked INT) ENGINE=InnoDB');
    $vtu->query("INSERT INTO sas_users VALUES(1, 7, 'alice', 100.00, 1, 0), (2, 8, 'other', 100.00, 1, 0)");
    $vtu->query('INSERT INTO sas_vendors VALUES(7, 100.00, 1, 0), (8, 100.00, 1, 0)');
    foreach (['sas_user_banks', 'sas_vendor_banks'] as $table) {
        $vtu->query("CREATE TABLE $table(id INT PRIMARY KEY, vendor_id INT, username VARCHAR(225), account_number VARCHAR(225), gateway_name VARCHAR(100), status INT, bank_name VARCHAR(225) DEFAULT 'Fixture Bank', account_name VARCHAR(225) DEFAULT 'Alice Fixture') ENGINE=InnoDB");
    }
    $vtu->query("INSERT INTO sas_user_banks(id, vendor_id, username, account_number, gateway_name, status) VALUES(1, 7, 'alice', '0123456789', 'payhub', 1)");
    $vtu->query("INSERT INTO sas_vendor_banks(id, vendor_id, username, account_number, gateway_name, status) VALUES(2, 7, '', '1234567890', 'payhub', 1)");
    foreach (['sas_payment_gateways', 'sas_super_admin_payment_gateways'] as $table) {
        $vtu->query("CREATE TABLE $table(vendor_id INT, gateway_name VARCHAR(100), secret_key VARCHAR(225), status INT) ENGINE=InnoDB");
        $vtu->query("INSERT INTO $table VALUES(7, 'payhub', '$liveSecret', 1)");
    }
    $history = 'vendor_id INT, product_unique_id VARCHAR(225), type_alternative VARCHAR(225), reference VARCHAR(225), amount DECIMAL(30,2), discounted_amount DECIMAL(30,2), balance_before DECIMAL(30,2), balance_after DECIMAL(30,2), description TEXT, api_website VARCHAR(225), status INT';
    $vtu->query("CREATE TABLE sas_vendor_transactions($history) ENGINE=InnoDB");
    $vtu->query("CREATE TABLE sas_transactions($history, username VARCHAR(225), api_reference VARCHAR(225), mode VARCHAR(225)) ENGINE=InnoDB");
    $vtu->query('CREATE TABLE sas_wallet_mutation_events(event_key CHAR(64) PRIMARY KEY, payload_hash CHAR(64), vendor_id INT, actor VARCHAR(225), direction VARCHAR(10), reference VARCHAR(225), amount DECIMAL(30,2)) ENGINE=InnoDB');
    $vtu->query(file_get_contents(__DIR__ . '/../migrations/20261118_payhub_deposits.sql'));

    $verifyFn = static function ($secret, $ref) use ($ph, $liveSecret) {
        pcheck(hash_equals($liveSecret, $secret), 'VTU sends exact merchant live secret');
        return paired_verify_response($ph, 1, $ref);
    };
    $userBalance = static fn() => (string)$vtu->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'];
    $otherBalance = static fn() => (string)$vtu->query('SELECT balance FROM sas_users WHERE id=2')->fetch_assoc()['balance'];
    $vendorBalance = static fn() => (string)$vtu->query('SELECT balance FROM sas_vendors WHERE id=7')->fetch_assoc()['balance'];

    // A. Paystack-forwarded live DVA deposit -> PayHub outbox + initial claim -> VTU user credit + PayHub ack
    $ph->beginTransaction();
    $ph->exec("INSERT INTO transactions(id, user_id, reference, amount, gateway_amount, fee_amount, settled_amount, status, is_test, currency, gateway_reference, customer_email, payment_method, receiving_account_number) VALUES(101, 1, 'DVA-LIVE-101', 500.00, 500.00, 7.50, 492.50, 'success', 0, 'NGN', '900101', 'alice@example.test', 'bank_transfer', '0123456789')");
    $row101 = $ph->query('SELECT t.*, u.webhook_url, u.secret_key, u.test_secret_key FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=101')->fetch(PDO::FETCH_ASSOC);
    $body101 = json_encode(ph_payload_from_row($row101, 'live'), JSON_THROW_ON_ERROR);
    payhub_enqueue_paystack_outbox($ph, 101, $body101);
    $ph->commit();

    $claim101 = payhub_claim_initial_webhook($ph, $row101, $body101);
    $res101 = bc_ph_receive($vtu, $claim101['delivery']['payload'], $claim101['signature'], 'user', $verifyFn);
    pcheck($res101['code'] === 'CREDITED' && $userBalance() === '592.50' && $otherBalance() === '100.00', 'paired live Paystack-forwarded DVA credits exact net once');
    pcheck(payhub_finish_webhook_retry($ph, $claim101, 200, true) === true, 'PayHub finalizes claim after VTU 200');
    pcheck($ph->query('SELECT state FROM webhook_retry_claims WHERE transaction_id=101')->fetchColumn() === 'succeeded', 'PayHub claim marked succeeded');

    // Duplicate delivery of the exact same signed payload -> ALREADY_CREDITED, no second balance mutation
    $dup101 = bc_ph_receive($vtu, $claim101['delivery']['payload'], $claim101['signature'], 'user', $verifyFn);
    pcheck($dup101['code'] === 'ALREADY_CREDITED' && $userBalance() === '592.50', 'paired duplicate delivery idempotent');

    // B. Reconciled DVA (va_reconcile) on vendor dedicated account -> PayHub recovery transport -> VTU vendor credit
    $ph->beginTransaction();
    $ph->exec("INSERT INTO transactions(id, user_id, reference, amount, gateway_amount, fee_amount, settled_amount, status, is_test, currency, gateway_reference, customer_email, payment_method, receiving_account_number) VALUES(102, 1, 'DVA-REC-102', 200.00, 200.00, 3.00, 197.00, 'success', 0, 'NGN', '900102', 'vendor@example.test', 'dedicated_account', '1234567890')");
    $row102 = $ph->query('SELECT t.*, u.webhook_url, u.secret_key, u.test_secret_key FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=102')->fetch(PDO::FETCH_ASSOC);
    $body102 = json_encode(ph_payload_from_row($row102, 'live'), JSON_THROW_ON_ERROR);
    payhub_enqueue_webhook_outbox($ph, 102, $body102, 'va_reconcile');
    $ph->commit();

    putenv('PAYHUB_OUTBOX_RECOVERY_ENABLED=1');
    $recStatus = payhub_recover_outbox($ph, 102, true, static function ($url, $body, $sig) use ($vtu, $verifyFn) {
        $out = bc_ph_receive($vtu, $body, $sig, 'vendor', $verifyFn);
        pcheck($out['code'] === 'CREDITED', 'paired vendor DVA recovery credited');
        return [200, true];
    });
    putenv('PAYHUB_OUTBOX_RECOVERY_ENABLED');
    pcheck($recStatus === 'acknowledged' && $vendorBalance() === '297.00', 'paired PayHub recovery -> VTU vendor route succeeds once');
    pcheck($ph->query('SELECT state FROM webhook_retry_claims WHERE transaction_id=102')->fetchColumn() === 'succeeded', 'recovery claim succeeded');

    // C. VTU refusal (e.g. restricted wallet -> HTTP 503) permanently fences PayHub claim as unknown
    $ph->beginTransaction();
    $ph->exec("INSERT INTO transactions(id, user_id, reference, amount, gateway_amount, fee_amount, settled_amount, status, is_test, currency, gateway_reference, customer_email, payment_method, receiving_account_number) VALUES(103, 1, 'DVA-HOLD-103', 300.00, 300.00, 4.50, 295.50, 'success', 0, 'NGN', '900103', 'alice@example.test', 'bank_transfer', '0123456789')");
    $row103 = $ph->query('SELECT t.*, u.webhook_url, u.secret_key, u.test_secret_key FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=103')->fetch(PDO::FETCH_ASSOC);
    $body103 = json_encode(ph_payload_from_row($row103, 'live'), JSON_THROW_ON_ERROR);
    payhub_enqueue_paystack_outbox($ph, 103, $body103);
    $ph->commit();

    $vtu->query('UPDATE sas_users SET is_blocked=1 WHERE id=1');
    putenv('PAYHUB_OUTBOX_RECOVERY_ENABLED=1');
    $unknownStatus = payhub_recover_outbox($ph, 103, true, static function ($url, $body, $sig) use ($vtu, $verifyFn) {
        prefused(fn() => bc_ph_receive($vtu, $body, $sig, 'user', $verifyFn), 'wallet_restricted');
        return [503, true];
    });
    pcheck($unknownStatus === 'unknown' && $userBalance() === '592.50', 'VTU 503 leaves PayHub claim unknown and no credit');
    pcheck(payhub_recover_outbox($ph, 103, true, static fn() => [200, true]) === 'already_claimed', 'unknown PayHub claim never auto-replays');
    putenv('PAYHUB_OUTBOX_RECOVERY_ENABLED');
    $vtu->query('UPDATE sas_users SET is_blocked=0 WHERE id=1');

    // D. Sandbox PayHub event signed with test_secret_key -> VTU rejects signature & test domain
    $ph->beginTransaction();
    $ph->exec("INSERT INTO transactions(id, user_id, reference, amount, gateway_amount, fee_amount, settled_amount, status, is_test, currency, gateway_reference, customer_email, payment_method, receiving_account_number) VALUES(104, 1, 'DVA-TEST-104', 100.00, 100.00, 1.50, 98.50, 'success', 1, 'NGN', '900104', 'alice@example.test', 'bank_transfer', '0123456789')");
    $row104 = $ph->query('SELECT t.*, u.webhook_url, u.secret_key, u.test_secret_key FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=104')->fetch(PDO::FETCH_ASSOC);
    $body104 = json_encode(ph_payload_from_row($row104, 'test'), JSON_THROW_ON_ERROR);
    payhub_enqueue_webhook_outbox($ph, 104, $body104, 'simulation');
    $ph->commit();
    $claim104 = payhub_claim_initial_webhook($ph, $row104, $body104);
    prefused(fn() => bc_ph_receive($vtu, $claim104['delivery']['payload'], $claim104['signature'], 'user', $verifyFn), 'invalid_signature');
    prefused(fn() => bc_ph_receive($vtu, $claim104['delivery']['payload'], hash_hmac('sha256', $claim104['delivery']['payload'], $liveSecret), 'user', $verifyFn), 'payment_evidence_requires_review');
    pcheck($userBalance() === '592.50', 'sandbox PayHub payment never credits VTU wallet');

    // E. Checkout without receiving_account_number, non-NGN currency, and net<=0 refused
    $ph->beginTransaction();
    $ph->exec("INSERT INTO transactions(id, user_id, reference, amount, gateway_amount, fee_amount, settled_amount, status, is_test, currency, gateway_reference, customer_email, payment_method, receiving_account_number) VALUES(105, 1, 'CHK-LIVE-105', 100.00, 100.00, 1.50, 98.50, 'success', 0, 'NGN', '900105', 'alice@example.test', 'card', NULL)");
    $row105 = $ph->query('SELECT t.*, u.webhook_url, u.secret_key, u.test_secret_key FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=105')->fetch(PDO::FETCH_ASSOC);
    $body105 = json_encode(ph_payload_from_row($row105, 'live'), JSON_THROW_ON_ERROR);
    payhub_enqueue_webhook_outbox($ph, 105, $body105, 'api_verify');
    $ph->commit();
    $claim105 = payhub_claim_initial_webhook($ph, $row105, $body105);
    prefused(fn() => bc_ph_receive($vtu, $claim105['delivery']['payload'], $claim105['signature'], 'user', $verifyFn), 'account_evidence_missing');

    $ph->beginTransaction();
    $ph->exec("INSERT INTO transactions(id, user_id, reference, amount, gateway_amount, fee_amount, settled_amount, status, is_test, currency, gateway_reference, customer_email, payment_method, receiving_account_number) VALUES(106, 1, 'DVA-USD-106', 100.00, 100.00, 1.50, 98.50, 'success', 0, 'USD', '900106', 'alice@example.test', 'bank_transfer', '0123456789')");
    $row106 = $ph->query('SELECT t.*, u.webhook_url, u.secret_key, u.test_secret_key FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=106')->fetch(PDO::FETCH_ASSOC);
    $body106 = json_encode(ph_payload_from_row($row106, 'live'), JSON_THROW_ON_ERROR);
    payhub_enqueue_paystack_outbox($ph, 106, $body106);
    $ph->commit();
    $claim106 = payhub_claim_initial_webhook($ph, $row106, $body106);
    prefused(fn() => bc_ph_receive($vtu, $claim106['delivery']['payload'], $claim106['signature'], 'user', $verifyFn), 'payment_evidence_requires_review');

    $ph->beginTransaction();
    $ph->exec("INSERT INTO transactions(id, user_id, reference, amount, gateway_amount, fee_amount, settled_amount, status, is_test, currency, gateway_reference, customer_email, payment_method, receiving_account_number) VALUES(107, 1, 'DVA-ZERO-107', 20.00, 20.00, 50.00, 0.00, 'success', 0, 'NGN', '900107', 'alice@example.test', 'bank_transfer', '0123456789')");
    $row107 = $ph->query('SELECT t.*, u.webhook_url, u.secret_key, u.test_secret_key FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=107')->fetch(PDO::FETCH_ASSOC);
    $body107 = json_encode(ph_payload_from_row($row107, 'live'), JSON_THROW_ON_ERROR);
    payhub_enqueue_paystack_outbox($ph, 107, $body107);
    $ph->commit();
    $claim107 = payhub_claim_initial_webhook($ph, $row107, $body107);
    prefused(fn() => bc_ph_receive($vtu, $claim107['delivery']['payload'], $claim107['signature'], 'user', $verifyFn), 'payment_amount_requires_review');

    pcheck($userBalance() === '592.50' && $otherBalance() === '100.00' && $vendorBalance() === '297.00', 'all rejected paired events preserve exact balances');
    echo "PASS $n paired PayHub -> VTU MySQL integration checks ($edition, local DBs only; not live network/TLS/provider acceptance)\n";
} finally {
    $phRoot->exec("DROP DATABASE IF EXISTS `$phName`");
    $vtuRoot->query("DROP DATABASE IF EXISTS `$vtuName`");
}
