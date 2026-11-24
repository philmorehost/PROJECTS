<?php
/** Disposable MySQL 8.0 tests for checkout-intent expiry, virtual-card & gift-card workers across SAAS & NON-SAAS. */
set_exception_handler(function ($e) {
    $msg = str_replace(['%', "\r", "\n"], ['%25', '', '%0A'], $e->getMessage());
    echo '::error file=VTU/tests/held_lifecycle_workers_mysql_test.php,line=' . $e->getLine() . '::' . $msg . "\n";
    exit(1);
});

$edition = getenv('BC_TEST_EDITION') ?: 'SAAS';
if ($edition !== 'SAAS' && $edition !== 'NON-SAAS') throw new RuntimeException('Invalid edition');
require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-checkout-intents-worker.php';
require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-virtualcard-worker.php';
require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-giftcard-worker.php';

$root = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', '', 3306);
$dbName = 'hlw_ci_' . strtolower(str_replace('-', '', $edition)) . '_' . bin2hex(random_bytes(5));
$root->query("CREATE DATABASE `$dbName`");
$GLOBALS['connection_server'] = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', $dbName, 3306);
$db = $GLOBALS['connection_server'];
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$n = 0;
function hlw_m($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

try {
    $db->query("CREATE TABLE sas_users(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, username VARCHAR(225), balance DECIMAL(65,30) DEFAULT 0, status INT DEFAULT 1, is_blocked TINYINT(1) DEFAULT 0, force_password_change TINYINT(1) DEFAULT 0) ENGINE=InnoDB");
    $db->query("INSERT INTO sas_users(id, vendor_id, username, balance, status) VALUES(1, 7, 'alice', 200000.00, 1)");
    $db->query("CREATE TABLE sas_transactions(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, product_unique_id VARCHAR(225), type_alternative VARCHAR(225), reference VARCHAR(225), api_reference VARCHAR(225), username VARCHAR(225), amount DECIMAL(65,30), discounted_amount DECIMAL(65,30), balance_before DECIMAL(65,30), balance_after DECIMAL(65,30), description TEXT, mode VARCHAR(100), api_website VARCHAR(225), status INT) ENGINE=InnoDB");
    $db->query("CREATE TABLE sas_virtual_cards_v2(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT, username VARCHAR(100), reference VARCHAR(100) UNIQUE, provider_card_id VARCHAR(255), card_name VARCHAR(255), masked_pan VARCHAR(25), expiry_month VARCHAR(5), expiry_year VARCHAR(5), cvv VARCHAR(10), balance_usd DECIMAL(20,2) DEFAULT 0.00, status ENUM('active','frozen','terminated') DEFAULT 'active', is_frozen_auto TINYINT(1) DEFAULT 0, metadata TEXT, provider VARCHAR(50) DEFAULT 'flutterwave', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $db->query("CREATE TABLE sas_vendor_giftcard_products(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, reloadly_product_id INT UNSIGNED, product_name VARCHAR(255), vendor_markup DECIMAL(10,2) DEFAULT 0.00, status TINYINT(1) DEFAULT 1) ENGINE=InnoDB");
    $db->query("INSERT INTO sas_vendor_giftcard_products(vendor_id, reloadly_product_id, product_name, vendor_markup, status) VALUES(7, 101, 'Amazon US', 2.00, 1)");
    $db->query("CREATE TABLE sas_giftcard_inventory(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, reloadly_tx_id VARCHAR(255), reloadly_product_id INT, product_name VARCHAR(255), card_code TEXT, card_pin VARCHAR(255), face_value DECIMAL(20,2), currency VARCHAR(10), current_owner_id INT, source VARCHAR(50) DEFAULT 'api', is_for_sale TINYINT(1) DEFAULT 0, sale_price_ngn DECIMAL(20,2) DEFAULT 0.00) ENGINE=InnoDB");

    $tables_src = file_get_contents(__DIR__ . '/../DGV7.0-' . $edition . '/func/bc-tables.php');
    foreach (['sas_wallet_mutation_events', 'sas_secure_payment_intents'] as $tbl) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $tbl . ' \(.*?\) ENGINE=InnoDB/s', $tables_src, $ddl)) {
            throw new RuntimeException('Missing ' . $tbl . ' DDL');
        }
        $db->query($ddl[0]);
    }

    foreach (['20261118_checkout_intent_lifecycle.sql', '20261118_virtualcard_settlement.sql', '20261118_giftcard_settlement.sql'] as $sqlFile) {
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../migrations/' . $sqlFile));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') $db->query($stmt);
        }
    }

    // 1. Checkout intent expiry worker
    $c1 = bc_chk_create_intent(7, 1, 'paystack', 'NGN', '5000.00', 60, hash('sha256', 'chk-exp-1'));
    $c2 = bc_chk_create_intent(7, 1, 'paystack', 'NGN', '5000.00', 600, hash('sha256', 'chk-act-2'));
    hlw_m($c1['ok'] && $c2['ok'], 'two checkout intents created');
    $stExp = $db->prepare("UPDATE sas_checkout_intents SET expires_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE vendor_id=7 AND reference=?");
    $stExp->bind_param('s', $c1['reference']);
    $stExp->execute();
    $stExp->close();

    putenv('BC_CHECKOUT_EXPIRE_ENABLED=1');
    $chkRes = bc_chk_run_expire_worker(7);
    hlw_m($chkRes['ok'] && $chkRes['processed'] === 1 && $chkRes['results'][0]['state'] === 'expired', 'checkout expire worker closes only expired intent');
    putenv('BC_CHECKOUT_EXPIRE_ENABLED');

    // 2. Virtual card worker: success (masked PAN), failure (refund), timeout (unknown, no refund)
    $v1 = bc_vc_reserve_issue(7, 1, '10.00', '1.00', '1500.000000', 'Alice Card', hash('sha256', 'vc-w-1'));
    $v2 = bc_vc_reserve_issue(7, 1, '10.00', '1.00', '1500.000000', 'Alice Card 2', hash('sha256', 'vc-w-2'));
    $v3 = bc_vc_reserve_issue(7, 1, '10.00', '1.00', '1500.000000', 'Alice Card 3', hash('sha256', 'vc-w-3'));
    hlw_m($v1['ok'] && $v2['ok'] && $v3['ok'], 'three held virtualcard orders created');

    putenv('BC_VIRTUALCARD_WORKER_ENABLED=1');
    $vcRes = bc_vc_run_worker(7, function ($ord) use ($v1, $v2) {
        if ($ord['reference'] === $v1['reference']) {
            return [
                'outcome' => 'confirmed_success',
                'provider_reference' => 'FLW_CARD_1',
                'card_payload' => ['masked_pan' => '**** **** **** 1111', 'expiry_month' => '12', 'expiry_year' => '29'],
            ];
        }
        if ($ord['reference'] === $v2['reference']) {
            return ['outcome' => 'confirmed_failure', 'provider_reference' => 'FLW_FAIL_2'];
        }
        throw new RuntimeException('flw_timeout');
    });
    hlw_m($vcRes['ok'] && $vcRes['processed'] === 3, 'virtualcard worker processed 3 orders');
    $vcStates = [];
    foreach ($vcRes['results'] as $r) $vcStates[$r['reference']] = $r['state'];
    hlw_m($vcStates[$v1['reference']] === 'confirmed_success', 'v1 confirmed_success');
    hlw_m($vcStates[$v2['reference']] === 'confirmed_failure', 'v2 confirmed_failure refunded');
    hlw_m($vcStates[$v3['reference']] === 'unknown', 'v3 timeout preserved as unknown');
    putenv('BC_VIRTUALCARD_WORKER_ENABLED');

    // 3. Gift card worker: success (inventory), failure (refund), timeout (unknown, no refund)
    $g1 = bc_gc_reserve_order(7, 1, 101, '10.00', 'USD', '1500.000000', '1.00', hash('sha256', 'gc-w-1'));
    $g2 = bc_gc_reserve_order(7, 1, 101, '10.00', 'USD', '1500.000000', '1.00', hash('sha256', 'gc-w-2'));
    $g3 = bc_gc_reserve_order(7, 1, 101, '10.00', 'USD', '1500.000000', '1.00', hash('sha256', 'gc-w-3'));
    hlw_m($g1['ok'] && $g2['ok'] && $g3['ok'], 'three held giftcard orders created');

    putenv('BC_GIFTCARD_WORKER_ENABLED=1');
    $gcRes = bc_gc_run_worker(7, function ($ord) use ($g1, $g2) {
        if ($ord['reference'] === $g1['reference']) {
            return ['outcome' => 'confirmed_success', 'provider_reference' => 'RLD_OK_1', 'encrypted_code' => 'ENC:CODE:1', 'encrypted_pin' => 'ENC:PIN:1'];
        }
        if ($ord['reference'] === $g2['reference']) {
            return ['outcome' => 'confirmed_failure', 'provider_reference' => 'RLD_FAIL_2'];
        }
        throw new RuntimeException('reloadly_timeout');
    });
    hlw_m($gcRes['ok'] && $gcRes['processed'] === 3, 'giftcard worker processed 3 orders');
    $gcStates = [];
    foreach ($gcRes['results'] as $r) $gcStates[$r['reference']] = $r['state'];
    hlw_m($gcStates[$g1['reference']] === 'confirmed_success', 'g1 confirmed_success');
    hlw_m($gcStates[$g2['reference']] === 'confirmed_failure', 'g2 confirmed_failure refunded');
    hlw_m($gcStates[$g3['reference']] === 'unknown', 'g3 timeout preserved as unknown');
    putenv('BC_GIFTCARD_WORKER_ENABLED');

    // Verify exact final balance: 200000 - 2*16500 (v1+v3) - 2*15450 (g1+g3) = 200000 - 33000 - 30900 = 136100.00
    $bal = (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'];
    hlw_m(abs($bal - 136100.0) < 0.01, "exact final balance after failure refunds & unknown non-refunds ($bal)");

    echo "PASS $n held lifecycle worker MySQL checks ($edition)\n";
} finally {
    $root->query("DROP DATABASE IF EXISTS `$dbName`");
    $db->close();
    $root->close();
}
