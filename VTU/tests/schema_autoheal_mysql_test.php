<?php
if (getenv('BC_SCHEMA_AUTOHEAL_CI') !== '1') {
    echo "SKIP schema_autoheal_mysql_test (set BC_SCHEMA_AUTOHEAL_CI=1 to run)\n";
    exit(0);
}

$edition = getenv('BC_TEST_EDITION') ?: 'SAAS';
if (!in_array($edition, ['SAAS', 'NON-SAAS'], true)) {
    throw new RuntimeException('Invalid BC_TEST_EDITION');
}

require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-schema-autoheal.php';

$n = 0;
function sah_db_ok($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
$user = getenv('MYSQL_USER') ?: 'root';
$pass = getenv('MYSQL_PASSWORD') ?: 'disposable-ci-only';
$port = (int)(getenv('MYSQL_PORT') ?: 3306);

$db = new mysqli($host, $user, $pass, '', $port);
if ($db->connect_error) {
    throw new RuntimeException('MySQL connect failed: ' . $db->connect_error);
}
$dbName = 'autoheal_ci_' . bin2hex(random_bytes(8));
$db->query("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db($dbName);

try {
    // Seed prerequisite legacy tables created earlier in bc-tables.php
    $db->query("CREATE TABLE sas_users (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, username VARCHAR(120) NOT NULL, balance DECIMAL(30,8) NOT NULL DEFAULT 0, is_blocked TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB");
    $db->query("CREATE TABLE sas_site_details (vendor_id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB");
    $db->query("CREATE TABLE sas_service_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, type VARCHAR(32) NOT NULL, provider VARCHAR(64) NOT NULL, payload LONGTEXT) ENGINE=InnoDB");

    // 1. Refuses inside an open transaction (autocommit=0)
    $db->autocommit(false);
    $refused = bc_schema_autoheal($db);
    sah_db_ok(!$refused['ok'] && $refused['code'] === 'outer_transaction_refused', 'refuses autoheal when autocommit=0');
    $db->rollback();
    $db->autocommit(true);

    // 2. First run applies all VTU/migrations/*.sql idempotently
    $r1 = bc_schema_autoheal($db);
    sah_db_ok($r1['ok'] === true && $r1['code'] === 'healed' && count($r1['applied']) >= 10 && count($r1['skipped']) === 0, 'first autoheal run applies all migrations');

    // 3. Second run skips all applied migrations without re-running DDL
    $r2 = bc_schema_autoheal($db);
    sah_db_ok($r2['ok'] === true && count($r2['applied']) === 0 && count($r2['skipped']) === count($r1['applied']), 'second autoheal run skips all applied migrations');

    // 4. Verify key tables exist with ENGINE=InnoDB
    foreach ([
        'schema_migrations_applied',
        'sas_payout_reservations',
        'sas_virtualcard_orders',
        'sas_giftcard_orders',
        'sas_checkout_intents',
        'sas_service_dispatch_leases',
        'sas_crypto_trade_orders',
        'sas_crypto_chain_deposits',
        'sas_crypto_signer_outbox',
    ] as $tbl) {
        $q = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . $db->real_escape_string($dbName) . "' AND TABLE_NAME='" . $db->real_escape_string($tbl) . "'");
        $row = $q ? $q->fetch_assoc() : null;
        sah_db_ok($row && strtoupper((string)$row['ENGINE']) === 'INNODB', "autohealed table $tbl is InnoDB");
    }

    echo "PASS $n schema auto-heal MySQL checks ($edition)\n";
} finally {
    $db->query("DROP DATABASE IF EXISTS `$dbName`");
    $db->close();
}
