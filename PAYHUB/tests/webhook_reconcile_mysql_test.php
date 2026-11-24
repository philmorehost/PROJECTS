<?php
/** Real MySQL 8.0 tests for multi-party webhook reconciliation (PAYHUB/includes/webhook-reconcile.php). */
if (getenv('PAYHUB_LOG_SCHEMA_CI') !== '1') throw new RuntimeException('Disposable CI only.');

require_once __DIR__ . '/../includes/webhook-reconcile.php';

$n = 0;
function rec_chk($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

$root = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', 'disposable-ci-only', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$name = 'ph_recon_ci_' . bin2hex(random_bytes(6));
$root->exec("CREATE DATABASE `$name`");
$db = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$name;charset=utf8mb4", 'root', 'disposable-ci-only', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

try {
    $db->exec("CREATE TABLE users(id INT AUTO_INCREMENT PRIMARY KEY, webhook_url VARCHAR(255), secret_key VARCHAR(255), test_secret_key VARCHAR(255)) ENGINE=InnoDB");
    $db->exec("INSERT INTO users(id, webhook_url, secret_key, test_secret_key) VALUES(7, 'https://merchant.test/hook', 'sec_live_1', 'sec_test_1')");
    $db->exec("CREATE TABLE transactions(id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, reference VARCHAR(120) NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'NGN', amount DECIMAL(20,2) NOT NULL, receiving_account_number VARCHAR(32) NOT NULL DEFAULT '', is_test TINYINT(1) NOT NULL DEFAULT 0, status VARCHAR(32) NOT NULL DEFAULT 'success') ENGINE=InnoDB");
    $db->exec("INSERT INTO transactions(id, user_id, reference, currency, amount, receiving_account_number, is_test, status) VALUES(101, 7, 'PH-REC-101', 'NGN', 2500.00, '9012345678', 0, 'success')");

    foreach ([
        '20261118_webhook_outbox.sql',
        '20261118_webhook_retry_claims.sql',
        '20261118_webhook_initial_claims.sql',
        '20261118_webhook_reviews.sql',
        '20261118_webhook_reconciliations.sql',
    ] as $sqlFile) {
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../install/' . $sqlFile));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') $db->exec($stmt);
        }
    }
    $db->exec("CREATE TABLE webhook_logs(id INT AUTO_INCREMENT PRIMARY KEY, transaction_id INT NOT NULL, status VARCHAR(32), attempt_count INT DEFAULT 0, last_attempt_at DATETIME NULL, payload LONGTEXT, response LONGTEXT) ENGINE=InnoDB");

    $payload = json_encode(['reference' => 'PH-REC-101', 'amount' => 2500.00]);
    $pSha = hash('sha256', $payload);
    $snapSha = hash('sha256', 'snap');
    $epSha = hash('sha256', 'https://merchant.test/hook');
    $mkSha = hash('sha256', 'sec_live_1');
    $st = $db->prepare('INSERT INTO webhook_outbox(transaction_id, producer, payload, payload_sha256, snapshot_sha256, endpoint_sha256, merchant_key_sha256) VALUES(101, "reconcile", ?, ?, ?, ?, ?)');
    $st->execute([$payload, $pSha, $snapSha, $epSha, $mkSha]);

    $snap = payhub_inspect_webhook_review($db, 101);
    $obs = $snap['observation_sha256'];

    // 1. Mismatched evidence cannot record confirmed_settled, but CAN record mismatch_held
    $badInput = [
        'request_id' => 'recon-bad-0001', 'preparer_ref' => 'operator-prep-01', 'approver_ref' => 'operator-app-02',
        'evidence_ref' => 'case-bad-0001', 'evidence_sha256' => str_repeat('a', 64), 'observation_sha256' => $obs,
        'merchant_id' => 7, 'reference' => 'PH-REC-101', 'currency' => 'NGN', 'amount' => '2400.00', 'account_number' => '9012345678',
        'disposition' => 'confirmed_settled',
    ];
    $threw = false;
    try { payhub_record_webhook_reconciliation($db, 101, $badInput); } catch (RuntimeException $e) { $threw = true; }
    rec_chk($threw, 'refuses confirmed_settled on amount mismatch');

    $badInput['disposition'] = 'mismatch_held';
    rec_chk(payhub_record_webhook_reconciliation($db, 101, $badInput) === 'recorded', 'records mismatch_held on mismatch');

    // 2. Exact match -> recorded + idempotent replay + conflict refusal
    $goodInput = $badInput;
    $goodInput['request_id'] = 'recon-good-0001';
    $goodInput['amount'] = '2500.00';
    $goodInput['disposition'] = 'confirmed_settled';
    rec_chk(payhub_record_webhook_reconciliation($db, 101, $goodInput) === 'recorded', 'records matching dual-operator reconciliation');
    rec_chk(payhub_record_webhook_reconciliation($db, 101, $goodInput) === 'already_recorded', 'replays identical reconciliation request');

    $conflict = $goodInput;
    $conflict['approver_ref'] = 'operator-app-99';
    $threw = false;
    try { payhub_record_webhook_reconciliation($db, 101, $conflict); } catch (RuntimeException $e) { $threw = true; }
    rec_chk($threw, 'refuses changed request_id reuse');

    // 3. Stale observation refused when outbox/claim changes
    $claimTok = str_repeat('c', 64);
    $db->exec("INSERT INTO webhook_retry_claims(transaction_id, log_id, claim_token, state, delivery_kind, payload_sha256, endpoint_sha256) VALUES(101, 1, '$claimTok', 'attempting', 'admin_retry', '$pSha', '$epSha')");
    $stale = $goodInput;
    $stale['request_id'] = 'recon-stale-0001';
    $threw = false;
    try { payhub_record_webhook_reconciliation($db, 101, $stale); } catch (RuntimeException $e) { $threw = true; }
    rec_chk($threw, 'refuses stale observation after claim state changed');

    // 4. Verify outbox & retry claim untouched
    $claimState = $db->query('SELECT state FROM webhook_retry_claims WHERE transaction_id=101')->fetchColumn();
    rec_chk($claimState === 'attempting', 'retry claim never reset or modified');

    echo "PASS $n PayHub multi-party reconciliation MySQL checks\n";
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$name`");
}
