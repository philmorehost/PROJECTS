<?php
/** Real MySQL 8.0 concurrency, deadlock-free P2P escrow, and rollback tests for bc-giftcard-settlement.php. */
if (getenv('BC_GIFTCARD_SETTLE_CI') !== '1') throw new RuntimeException('Disposable CI only.');
set_exception_handler(function ($e) {
    $msg = str_replace(['%', "\r", "\n"], ['%25', '', '%0A'], $e->getMessage());
    echo '::error file=VTU/tests/giftcard_settlement_mysql_test.php,line=' . $e->getLine() . '::' . $msg . "\n";
    exit(1);
});

$edition = getenv('BC_TEST_EDITION') ?: 'SAAS';
if (!in_array($edition, ['SAAS', 'NON-SAAS'], true)) throw new RuntimeException('Invalid edition.');

require_once __DIR__ . '/../DGV7.0-' . $edition . '/func/bc-giftcard-settlement.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function gc_db($name = '') {
    $m = new mysqli('127.0.0.1', 'root', 'disposable-ci-only', $name, 3306);
    $m->set_charset('utf8mb4');
    return $m;
}

if (in_array($argv[1] ?? '', ['race-order', 'race-p2p-buy', 'race-p2p-replay'], true)) {
    $name = $argv[2] ?? '';
    if (!preg_match('/\Abc_gc_ci_[a-f0-9]{12}\z/D', $name)) throw new RuntimeException('db');
    $GLOBALS['connection_server'] = gc_db($name);
    usleep(150000);
    if ($argv[1] === 'race-order') {
        echo json_encode(bc_gc_reserve_order(7, 1, 101, '10.00', 'USD', '1500.000000', '1.00', $argv[3] ?? ''));
    } elseif ($argv[1] === 'race-p2p-replay') {
        echo json_encode(bc_gc_p2p_hold(7, 1, (int)($argv[3] ?? 0), '5.00', $argv[4] ?? ''));
    } else {
        $buyerId = (int)($argv[3] ?? 0);
        $rk = hash('sha256', 'p2p-race-' . $buyerId);
        echo json_encode(bc_gc_p2p_hold(7, $buyerId, (int)($argv[4] ?? 0), '5.00', $rk));
    }
    exit;
}

$n = 0;
function gc_check($ok, $label) { global $n; if (!$ok) throw new RuntimeException($label); $n++; }

$root = gc_db();
$name = 'bc_gc_ci_' . bin2hex(random_bytes(6));
$root->query("CREATE DATABASE `$name`");
$db = gc_db($name);
$GLOBALS['connection_server'] = $db;

try {
    $db->query('CREATE TABLE sas_users(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, username VARCHAR(225), balance DECIMAL(65,30) DEFAULT 0, status INT DEFAULT 1, is_blocked TINYINT(1) DEFAULT 0, force_password_change TINYINT(1) DEFAULT 0) ENGINE=InnoDB');
    $db->query("INSERT INTO sas_users(id, vendor_id, username, balance, status) VALUES(1, 7, 'buyer1', 50000.00, 1), (2, 7, 'seller1', 1000.00, 1), (3, 7, 'buyer2', 50000.00, 1), (4, 8, 'buyer1', 50000.00, 1)");
    $db->query('CREATE TABLE sas_transactions(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, product_unique_id VARCHAR(225), type_alternative VARCHAR(225), reference VARCHAR(225), api_reference VARCHAR(225), username VARCHAR(225), amount DECIMAL(65,30), discounted_amount DECIMAL(65,30), balance_before DECIMAL(65,30), balance_after DECIMAL(65,30), description TEXT, mode VARCHAR(100), api_website VARCHAR(225), status INT) ENGINE=InnoDB');
    $db->query('CREATE TABLE sas_vendor_giftcard_products(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, reloadly_product_id INT UNSIGNED, product_name VARCHAR(255), vendor_markup DECIMAL(10,2) DEFAULT 0.00, status TINYINT(1) DEFAULT 1) ENGINE=InnoDB');
    $db->query("INSERT INTO sas_vendor_giftcard_products(vendor_id, reloadly_product_id, product_name, vendor_markup, status) VALUES(7, 101, 'Amazon US', 2.00, 1)");
    $db->query("CREATE TABLE sas_giftcard_inventory(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT UNSIGNED, reloadly_tx_id VARCHAR(255), reloadly_product_id INT, product_name VARCHAR(255), card_code TEXT, card_pin VARCHAR(255), face_value DECIMAL(20,2), currency VARCHAR(10), current_owner_id INT, source VARCHAR(50) DEFAULT 'api', is_for_sale TINYINT(1) DEFAULT 0, sale_price_ngn DECIMAL(20,2) DEFAULT 0.00) ENGINE=InnoDB");

    $tables_src = file_get_contents(__DIR__ . '/../DGV7.0-' . $edition . '/func/bc-tables.php');
    if (!preg_match('/CREATE TABLE IF NOT EXISTS sas_wallet_mutation_events \(.*?\) ENGINE=InnoDB/s', $tables_src, $ddl)) {
        throw new RuntimeException('Missing sas_wallet_mutation_events DDL');
    }
    $db->query($ddl[0]);

    $mig = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../migrations/20261118_giftcard_settlement.sql'));
    foreach (array_filter(array_map('trim', explode(';', $mig))) as $stmt) {
        if ($stmt !== '') $db->query($stmt);
    }

    // 1. Direct Reloadly purchase reservation: server-side price snapshot (10 * 1500 * 1.03 = 15450.00)
    $rk1 = hash('sha256', 'gc-order-1');
    $o1 = bc_gc_reserve_order(7, 1, 101, '10.00', 'USD', '1500.000000', '1.00', $rk1);
    gc_check($o1['ok'] && $o1['code'] === 'reserved' && $o1['state'] === 'held' && $o1['total_ngn'] === '15450.00', 'reserves order in held state');
    gc_check((float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 34550.0, 'buyer debited atomically');
    gc_check((float)$db->query('SELECT balance FROM sas_users WHERE id=4')->fetch_assoc()['balance'] === 50000.0, 'other tenant isolated');

    $o1Rep = bc_gc_reserve_order(7, 1, 101, '10.00', 'USD', '1500.000000', '1.00', $rk1);
    gc_check($o1Rep['ok'] && $o1Rep['replayed'] === true && $o1Rep['reference'] === $o1['reference'] && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 34550.0, 'replay recovers order without double debit');

    $o1Conf = bc_gc_reserve_order(7, 1, 101, '20.00', 'USD', '1500.000000', '1.00', $rk1);
    gc_check(!$o1Conf['ok'] && $o1Conf['code'] === 'conflict', 'changed face value conflicts');

    // Cancel held order refunds once
    $can = bc_gc_cancel_held_order(7, $o1['reference'], hash('sha256', 'can-1'), hash('sha256', 'reason'), 0);
    gc_check($can['ok'] && $can['state'] === 'cancelled' && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 50000.0, 'cancel_held_order refunds once');

    // 2. Dispatched -> unknown (no refund) -> confirmed_success (atomic inventory insert + status=1)
    $o2 = bc_gc_reserve_order(7, 1, 101, '10.00', 'USD', '1500.000000', '1.00', hash('sha256', 'gc-order-2'));
    $stmt = $db->prepare("UPDATE sas_giftcard_orders SET state='dispatched' WHERE vendor_id=7 AND reference=?");
    $stmt->bind_param('s', $o2['reference']);
    $stmt->execute();
    $stmt->close();

    $unk = bc_gc_observe_order(7, $o2['reference'], hash('sha256', 'unk-2'), 'unknown', hash('sha256', 'ev-unk'), 'RLD-TX-2', 0);
    gc_check($unk['ok'] && $unk['state'] === 'unknown' && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 34550.0, 'unknown order never auto-refunds');

    $succ = bc_gc_observe_order(7, $o2['reference'], hash('sha256', 'succ-2'), 'confirmed_success', hash('sha256', 'ev-succ'), 'RLD-TX-2', 1, 'ENC:CODE:XYZ', 'ENC:PIN:123');
    gc_check($succ['ok'] && $succ['state'] === 'confirmed_success', 'unknown -> confirmed_success');
    gc_check((int)$db->query("SELECT COUNT(*) n FROM sas_giftcard_inventory WHERE vendor_id=7 AND reloadly_tx_id='RLD-TX-2' AND current_owner_id=1")->fetch_assoc()['n'] === 1, 'inventory card delivered atomically');
    gc_check((int)$db->query("SELECT status FROM sas_transactions WHERE reference='{$o2['reference']}' LIMIT 1")->fetch_assoc()['status'] === 1, 'tx marked completed');

    // 3. Dispatched -> confirmed_failure refunds exactly once
    $o3 = bc_gc_reserve_order(7, 1, 101, '10.00', 'USD', '1500.000000', '1.00', hash('sha256', 'gc-order-3'));
    $stmt = $db->prepare("UPDATE sas_giftcard_orders SET state='dispatched' WHERE vendor_id=7 AND reference=?");
    $stmt->bind_param('s', $o3['reference']);
    $stmt->execute();
    $stmt->close();
    $fail = bc_gc_observe_order(7, $o3['reference'], hash('sha256', 'fail-3'), 'confirmed_failure', hash('sha256', 'ev-fail'), '', 0);
    gc_check($fail['ok'] && $fail['state'] === 'confirmed_failure' && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 34550.0, 'confirmed_failure refunds order once');

    // 4. Outer tx & MyISAM refusal + trigger rollback
    $db->begin_transaction();
    $db->query('UPDATE sas_users SET balance=999 WHERE id=4');
    $outer = bc_gc_reserve_order(7, 1, 101, '10.00', 'USD', '1500.000000', '1.00', hash('sha256', 'gc-outer'));
    gc_check(!$outer['ok'] && $outer['code'] === 'outer_transaction', 'outer tx refused');
    $db->rollback();
    gc_check((float)$db->query('SELECT balance FROM sas_users WHERE id=4')->fetch_assoc()['balance'] === 50000.0, 'caller outer tx preserved');

    $db->query('ALTER TABLE sas_users ENGINE=MyISAM');
    $myisam = bc_gc_reserve_order(7, 1, 101, '10.00', 'USD', '1500.000000', '1.00', hash('sha256', 'gc-myisam'));
    gc_check(!$myisam['ok'] && $myisam['code'] === 'nontransactional', 'MyISAM refused');
    $db->query('ALTER TABLE sas_users ENGINE=InnoDB');

    $db->query("CREATE TRIGGER fail_gc_ord BEFORE INSERT ON sas_giftcard_orders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
    $trig = bc_gc_reserve_order(7, 1, 101, '10.00', 'USD', '1500.000000', '1.00', hash('sha256', 'gc-trig'));
    $db->query('DROP TRIGGER fail_gc_ord');
    gc_check(!$trig['ok'] && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 34550.0, 'trigger failure rolls back debit');

    // 5. Concurrent direct-order race (4 workers with same key -> 1 reserved, 3 replayed, 1x debit)
    $db->query('UPDATE sas_users SET balance=30000.00 WHERE id=1');
    $raceKey = hash('sha256', 'gc-race-order');
    $procs = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, 'race-order', $name, $raceKey], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $procs[] = [$p, $pipes];
    }
    $resCnt = 0; $repCnt = 0;
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        gc_check(proc_close($p) === 0 && $err === '', 'clean order race worker: ' . $err . $out);
        $r = json_decode($out, true);
        if (($r['code'] ?? '') === 'reserved') $resCnt++;
        elseif (($r['code'] ?? '') === 'replayed') $repCnt++;
    }
    gc_check($resCnt === 1 && $repCnt === 3 && (float)$db->query('SELECT balance FROM sas_users WHERE id=1')->fetch_assoc()['balance'] === 14550.0, 'concurrent order debits once');

    // 6. P2P Escrow race: seller2 owns listed card #1 (10000.00, 5% fee -> 9500.00 net). Buyer1 & Buyer2 race to buy it!
    $db->query('UPDATE sas_users SET balance=20000.00 WHERE id IN (1, 3)');
    $db->query('UPDATE sas_users SET balance=1000.00 WHERE id=2');
    $db->query("INSERT INTO sas_giftcard_inventory(vendor_id, product_name, card_code, face_value, currency, current_owner_id, source, is_for_sale, sale_price_ngn) VALUES(7, 'Apple US', 'ENC:APPLE', 10.00, 'USD', 2, 'user_upload', 1, 10000.00)");
    $cardId = (int)$db->insert_id;

    $procs = [];
    foreach ([1, 3] as $bId) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, 'race-p2p-buy', $name, (string)$bId, (string)$cardId], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $procs[] = [$bId, $p, $pipes];
    }
    $winners = []; $losers = 0; $rawOuts = [];
    foreach ($procs as [$bId, $p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        gc_check(proc_close($p) === 0 && $err === '', 'clean p2p race worker: ' . $err . $out);
        $rawOuts[] = $out;
        $r = json_decode($out, true);
        if (!empty($r['ok']) && ($r['code'] ?? '') === 'held_escrow') $winners[] = [$bId, $r['reference']];
        elseif (($r['code'] ?? '') === 'card_unavailable') $losers++;
    }
    gc_check(count($winners) === 1 && $losers === 1, 'only one concurrent buyer acquires P2P escrow: ' . implode(' | ', $rawOuts));

    // Concurrent same-buyer P2P replay race (3 workers with identical key -> 1 held_escrow, 2 replayed, 1x debit)
    $db->query("INSERT INTO sas_giftcard_inventory(vendor_id, product_name, card_code, face_value, currency, current_owner_id, source, is_for_sale, sale_price_ngn) VALUES(7, 'Steam US', 'ENC:STEAM', 5.00, 'USD', 2, 'user_upload', 1, 5000.00)");
    $card2Id = (int)$db->insert_id;
    $p2pRepKey = hash('sha256', 'p2p-same-buyer-replay');
    $rProcs = [];
    for ($i = 0; $i < 3; $i++) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __FILE__, 'race-p2p-replay', $name, (string)$card2Id, $p2pRepKey], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $rProcs[] = [$p, $pipes];
    }
    $p2pHeld = 0; $p2pRep = 0; $repOuts = [];
    foreach ($rProcs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        gc_check(proc_close($p) === 0 && $err === '', 'clean p2p replay race worker: ' . $err . $out);
        $repOuts[] = $out;
        $r = json_decode($out, true);
        if (($r['code'] ?? '') === 'held_escrow') $p2pHeld++;
        elseif (($r['code'] ?? '') === 'replayed') $p2pRep++;
    }
    gc_check($p2pHeld === 1 && $p2pRep === 2, 'concurrent same-buyer P2P replay debits once: ' . implode(' | ', $repOuts));
    // Re-credit buyer 1 for the 5000 test debit so assertions below stay clean
    $db->query('UPDATE sas_users SET balance = balance + 5000.00 WHERE id=1');
    [$winBuyerId, $escrowRef] = $winners[0];
    $loseBuyerId = $winBuyerId === 1 ? 3 : 1;
    gc_check((float)$db->query("SELECT balance FROM sas_users WHERE id=$winBuyerId")->fetch_assoc()['balance'] === 10000.0, 'winning buyer debited');
    gc_check((float)$db->query("SELECT balance FROM sas_users WHERE id=$loseBuyerId")->fetch_assoc()['balance'] === 20000.0, 'losing buyer untouched');

    // Atomic P2P release: credits seller net (1000 + 9500 = 10500), transfers ownership to winBuyerId, prevents double-release or post-release refund!
    $rel = bc_gc_p2p_release(7, $escrowRef, hash('sha256', 'rel-1'), 0);
    gc_check($rel['ok'] && $rel['state'] === 'released' && (float)$db->query('SELECT balance FROM sas_users WHERE id=2')->fetch_assoc()['balance'] === 10500.0, 'seller credited net on release');
    gc_check((int)$db->query("SELECT current_owner_id FROM sas_giftcard_inventory WHERE id=$cardId")->fetch_assoc()['current_owner_id'] === $winBuyerId, 'card ownership transferred to buyer');
    $doubleRel = bc_gc_p2p_release(7, $escrowRef, hash('sha256', 'rel-2'), 1);
    gc_check($doubleRel['ok'] && $doubleRel['code'] === 'already_final' && (float)$db->query('SELECT balance FROM sas_users WHERE id=2')->fetch_assoc()['balance'] === 10500.0, 'cannot double-credit seller');
    $postRef = bc_gc_p2p_refund(7, $escrowRef, hash('sha256', 'ref-after'), 1);
    gc_check(!$postRef['ok'] && $postRef['code'] === 'conflict' && (float)$db->query("SELECT balance FROM sas_users WHERE id=$winBuyerId")->fetch_assoc()['balance'] === 10000.0, 'cannot refund after release');

    // Re-list card by new owner and test P2P refund path
    $db->query("UPDATE sas_giftcard_inventory SET is_for_sale=1, sale_price_ngn=5000.00 WHERE id=$cardId");
    $e2 = bc_gc_p2p_hold(7, $loseBuyerId, $cardId, '5.00', hash('sha256', 'p2p-refund-test'));
    gc_check($e2['ok'] && (float)$db->query("SELECT balance FROM sas_users WHERE id=$loseBuyerId")->fetch_assoc()['balance'] === 15000.0, 'second P2P escrow held');
    $ref2 = bc_gc_p2p_refund(7, $e2['reference'], hash('sha256', 'p2p-ref-evt'), 0);
    gc_check($ref2['ok'] && $ref2['state'] === 'refunded' && (float)$db->query("SELECT balance FROM sas_users WHERE id=$loseBuyerId")->fetch_assoc()['balance'] === 20000.0, 'P2P refund restores buyer balance');
    gc_check((int)$db->query("SELECT is_for_sale FROM sas_giftcard_inventory WHERE id=$cardId")->fetch_assoc()['is_for_sale'] === 1, 'P2P refund restores listing');

    echo "PASS $n giftcard settlement & P2P escrow MySQL integration checks ($edition)\n";
} finally {
    $root->query("DROP DATABASE IF EXISTS `$name`");
}
