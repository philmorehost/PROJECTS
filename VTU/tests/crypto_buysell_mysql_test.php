<?php
/** Disposable MySQL 8.0 tests for replacement crypto Buy/Sell foundation across SAAS & NON-SAAS. */
$edition = getenv('VTU_TEST_EDITION') ?: 'SAAS';
if ($edition !== 'SAAS' && $edition !== 'NON-SAAS') throw new RuntimeException('Invalid edition');
$helper = __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-crypto-buysell.php';
require_once $helper;

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$port = (int)(getenv('DB_PORT') ?: '3306');
if ($pass === '') throw new RuntimeException('CI DB_PASS required.');

$root = new mysqli($host, $user, $pass, '', $port);
$dbName = 'cbs_ci_' . strtolower(str_replace('-', '', $edition)) . '_' . bin2hex(random_bytes(5));
$root->query("CREATE DATABASE `$dbName`");
$db = new mysqli($host, $user, $pass, $dbName, $port);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$n = 0;
function cbs_chk($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

try {
    $db->query("CREATE TABLE sas_users (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, username VARCHAR(64) NOT NULL, balance DECIMAL(20,2) NOT NULL DEFAULT 0.00, is_blocked TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB");
    $db->query("CREATE TABLE sas_wallet_mutation_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, user_id INT NOT NULL, reference VARCHAR(160) NOT NULL, mutation_kind VARCHAR(64) NOT NULL, amount DECIMAL(20,2) NOT NULL, UNIQUE KEY uq_mut_ref (vendor_id, reference)) ENGINE=InnoDB");
    $db->query("CREATE TABLE sas_transactions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, username VARCHAR(64) NOT NULL, product_unique_id VARCHAR(64) NOT NULL, type_alternative VARCHAR(64) NOT NULL, reference VARCHAR(160) NOT NULL, amount DECIMAL(20,2) NOT NULL, discounted_amount DECIMAL(20,2) NOT NULL, balance_before DECIMAL(20,2) NOT NULL, balance_after DECIMAL(20,2) NOT NULL, description VARCHAR(255) NOT NULL, mode VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL) ENGINE=InnoDB");

    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../migrations/20261118_crypto_buysell_foundation.sql'));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') $db->query($stmt);
    }

    $db->query("INSERT INTO sas_users(id, vendor_id, username, balance, is_blocked) VALUES(10, 1, 'alice', 500000.00, 0)");

    // 1. Quote lock + Buy execution (100 USDT @ 1600 NGN + 1000 NGN fee = 161,000 NGN)
    $q = bc_cbs_create_quote($db, [
        'vendor_id' => 1, 'user_id' => 10, 'request_id' => 'q-buy-0001',
        'side' => 'buy', 'asset' => 'USDT', 'network' => 'TRON', 'fiat_currency' => 'NGN',
        'crypto_units_1e8' => '10000000000', 'rate_fiat_minor' => 160000, 'margin_bps' => 50, 'fee_fiat_minor' => 100000,
        'destination_address' => 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL', 'kyc_verified' => 1, 'ttl_seconds' => 120,
    ]);
    cbs_chk($q['total_fiat_minor'] === 16100000, 'quote total exact 161000.00 NGN');

    $ord = bc_cbs_execute_buy_order($db, 1, 10, $q['quote_id'], 'ord-buy-0001');
    cbs_chk($ord['state'] === 'held' && !$ord['replayed'], 'buy order parked in held');
    $ordReplay = bc_cbs_execute_buy_order($db, 1, 10, $q['quote_id'], 'ord-buy-0001');
    cbs_chk($ordReplay['replayed'] && $ordReplay['order_id'] === $ord['order_id'], 'idempotent buy order replay');

    $bal = (float)$db->query("SELECT balance FROM sas_users WHERE id=10")->fetch_assoc()['balance'];
    cbs_chk(abs($bal - 339000.00) < 0.001, 'wallet debited atomically');

    // 2. Rejected before broadcast -> single refund
    $ref = bc_cbs_observe_payout_outcome($db, 1, $ord['order_id'], 'rejected_before_broadcast', ['error' => 'policy_check']);
    cbs_chk($ref['state'] === 'refunded', 'pre-broadcast rejection refunded');
    $bal2 = (float)$db->query("SELECT balance FROM sas_users WHERE id=10")->fetch_assoc()['balance'];
    cbs_chk(abs($bal2 - 500000.00) < 0.001, 'wallet restored once');

    // 3. Unknown broadcast outcome -> NEVER refunds!
    $q2 = bc_cbs_create_quote($db, [
        'vendor_id' => 1, 'user_id' => 10, 'request_id' => 'q-buy-0002',
        'side' => 'buy', 'asset' => 'USDT', 'network' => 'TRON', 'fiat_currency' => 'NGN',
        'crypto_units_1e8' => '5000000000', 'rate_fiat_minor' => 160000, 'margin_bps' => 50, 'fee_fiat_minor' => 100000,
        'destination_address' => 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL', 'kyc_verified' => 1, 'ttl_seconds' => 120,
    ]);
    $ord2 = bc_cbs_execute_buy_order($db, 1, 10, $q2['quote_id'], 'ord-buy-0002');
    $unk = bc_cbs_observe_payout_outcome($db, 1, $ord2['order_id'], 'unknown', ['error' => 'rpc_timeout']);
    cbs_chk($unk['state'] === 'unknown', 'timeout marks order unknown');
    $threw = false;
    try { bc_cbs_observe_payout_outcome($db, 1, $ord2['order_id'], 'rejected_before_broadcast'); } catch (RuntimeException $e) { $threw = true; }
    cbs_chk($threw, 'refuses refund after unknown outcome');

    // 4. SELL deposit finality, wrong-network, reorg & idempotent Naira credit
    $wrongNet = bc_cbs_record_sell_deposit($db, [
        'vendor_id' => 1, 'user_id' => 10, 'network' => 'BSC', 'expected_network' => 'TRON',
        'asset' => 'USDT', 'tx_hash' => '0xabc1', 'log_index' => 0, 'deposit_address' => '0x1111111111111111111111111111111111111111',
        'expected_units_1e8' => '10000000000', 'observed_units_1e8' => '10000000000', 'confirmations' => 20, 'required_confirmations' => 15,
    ]);
    cbs_chk($wrongNet['state'] === 'wrong_network_held', 'wrong network held');

    $pend = bc_cbs_record_sell_deposit($db, [
        'vendor_id' => 1, 'user_id' => 10, 'network' => 'TRON', 'expected_network' => 'TRON',
        'asset' => 'USDT', 'tx_hash' => 'tx_tron_1', 'log_index' => 0, 'deposit_address' => 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL',
        'expected_units_1e8' => '5000000000', 'observed_units_1e8' => '5000000000', 'confirmations' => 3, 'required_confirmations' => 19,
    ]);
    cbs_chk($pend['state'] === 'pending_confirmations', 'unconfirmed deposit held');

    $final = bc_cbs_record_sell_deposit($db, [
        'vendor_id' => 1, 'user_id' => 10, 'network' => 'TRON', 'expected_network' => 'TRON',
        'asset' => 'USDT', 'tx_hash' => 'tx_tron_1', 'log_index' => 0, 'deposit_address' => 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL',
        'expected_units_1e8' => '5000000000', 'observed_units_1e8' => '5000000000', 'confirmations' => 20, 'required_confirmations' => 19,
    ]);
    cbs_chk($final['state'] === 'matched_final', 'confirmed deposit matched_final');

    $cr1 = bc_cbs_settle_sell_deposit($db, 1, $final['deposit_id'], 8000000);
    $cr2 = bc_cbs_settle_sell_deposit($db, 1, $final['deposit_id'], 8000000);
    cbs_chk($cr1['state'] === 'credited' && !$cr1['replayed'] && $cr2['replayed'], 'sell deposit credited once');

    // 5. MyISAM refusal on non-FK table
    $db->query("ALTER TABLE sas_users ENGINE=MyISAM");
    $myisamRefused = false;
    try { bc_cbs_assert_innodb($db); } catch (RuntimeException $e) { $myisamRefused = true; }
    cbs_chk($myisamRefused, 'refuses non-InnoDB sas_users');

    echo "PASS $n crypto Buy/Sell MySQL checks ($edition)\n";
} finally {
    $root->query("DROP DATABASE IF EXISTS `$dbName`");
}
