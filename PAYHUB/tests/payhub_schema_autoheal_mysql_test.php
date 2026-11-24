<?php
/** Disposable MySQL only; tests live PDO auto-heal on existing/legacy PayHub schemas without wedging queries. */
if (getenv('PAYHUB_LOG_SCHEMA_CI') !== '1') {
    throw new RuntimeException('Disposable CI only.');
}

require_once __DIR__ . '/../install/schema-autoheal.php';

$n = 0;
function ph_mysql_heal_check($ok, $msg) {
    global $n;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $msg);
    }
    $n++;
}

$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => true,
];
if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
    $opts[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
}

$admin = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'disposable-ci-only', $opts);
$dbName = 'payhub_heal_ci_' . bin2hex(random_bytes(6));
$admin->exec("CREATE DATABASE `$dbName`");

try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$dbName", 'root', 'disposable-ci-only', $opts);

    // Pre-create existing PayHub tables WITH columns already present so PREPARE/EXECUTE runs 'SELECT 1'
    // and ALTER TABLE hits duplicate column name — the exact live upgrade scenario that triggered HY000 2014.
    $pdo->exec("CREATE TABLE transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reference VARCHAR(100) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'success',
        receiving_account_number VARCHAR(10) DEFAULT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO transactions (reference, status) VALUES ('tx-100', 'success')");

    $pdo->exec("CREATE TABLE webhook_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT NOT NULL,
        status ENUM('success', 'failed') DEFAULT 'failed',
        attempt_count INT DEFAULT 0,
        last_attempt_at TIMESTAMP NULL,
        payload TEXT,
        response TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_webhook_transaction (transaction_id)
    ) ENGINE=InnoDB");

    $res1 = payhub_install_apply_migrations($pdo);
    ph_mysql_heal_check(($res1['ok'] ?? false) === true, 'first auto-heal run succeeds on pre-existing schema: ' . json_encode($res1));
    ph_mysql_heal_check(count($res1['applied'] ?? []) === 7, 'all 7 migrations recorded');

    // Verify the SAME PDO connection is NOT wedged by unconsumed EXECUTE / SELECT 1 rowsets (HY000 2014).
    $tx = $pdo->query("SELECT reference FROM transactions WHERE id = 1")->fetch();
    ph_mysql_heal_check(($tx['reference'] ?? '') === 'tx-100', 'subsequent query on same PDO succeeds without 2014 error');

    $retryStates = $pdo->query('SELECT transaction_id, state FROM webhook_retry_claims')->fetchAll(PDO::FETCH_KEY_PAIR);
    ph_mysql_heal_check(is_array($retryStates), 'webhook_retry_claims queryable immediately after auto-heal');

    // Second run must be an idempotent no-op.
    $res2 = payhub_install_apply_migrations($pdo);
    ph_mysql_heal_check(($res2['ok'] ?? false) === true && count($res2['skipped'] ?? []) === 7 && count($res2['applied'] ?? []) === 0, 'second run skips all 7 migrations');

    echo "PASS $n PayHub live MySQL schema auto-heal checks\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `$dbName`");
}
