<?php
// Called ONLY by the Node service (signed). Credits / refunds user wallets.
// Events: sell.credit, buy.refund, buy.completed. Each event is applied exactly once.
declare(strict_types=1);
require __DIR__ . '/prototype-hold.php';
require __DIR__ . '/lib.php';

$raw = file_get_contents('php://input') ?: '';
$ts  = $_SERVER['HTTP_X_MZ_TIMESTAMP'] ?? '';
$sig = $_SERVER['HTTP_X_MZ_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', "$ts.$raw", mz_cfg()['callback_secret']);
if (!ctype_digit($ts) || abs(time() - (int) $ts) > 300 || !hash_equals($expected, (string) $sig)) {
    mz_json(['ok' => false, 'error' => 'bad signature'], 401);
}

$ev = json_decode($raw, true);
if (!is_array($ev) || empty($ev['eventId']) || empty($ev['order']['clientRef'])) {
    mz_json(['ok' => false, 'error' => 'bad payload'], 400);
}
$o = $ev['order'];
$db = mz_db();

try {
    $db->beginTransaction();

    // idempotency: an event id can only be inserted once
    $ins = $db->prepare('INSERT IGNORE INTO mz_crypto_events (event_id, type, payload) VALUES (?, ?, ?)');
    $ins->execute([$ev['eventId'], $ev['type'], $raw]);
    if ($ins->rowCount() === 0) {
        $db->commit();
        mz_json(['ok' => true, 'duplicate' => true]);
    }

    $st = $db->prepare('SELECT * FROM mz_crypto_orders WHERE client_ref = ? FOR UPDATE');
    $st->execute([$o['clientRef']]);
    $local = $st->fetch();
    if (!$local || (string) $local['user_id'] !== (string) $ev['userRef']) {
        throw new RuntimeException('order/user mismatch for ' . $o['clientRef']);
    }
    $uid = (int) $local['user_id'];

    switch ($ev['type']) {
        case 'sell.credit':
            if ($local['status'] === 'completed') break;
            $amount = $o['fiatAmount'];               // actual amount for what was received
            mz_wallet_credit($db, $uid, $amount, $o['clientRef'], "Sold {$o['receivedAmount']} {$o['symbol']}");
            $db->prepare("UPDATE mz_crypto_orders SET status = 'completed', fiat_amount = ?, crypto_amount = ? WHERE id = ?")
               ->execute([$amount, $o['receivedAmount'] ?? $o['cryptoAmount'], $local['id']]);
            break;

        case 'buy.refund':
            if (in_array($local['status'], ['refunded', 'completed'], true)) break;
            // refund exactly what WE debited (our own record), not what the payload says
            mz_wallet_credit($db, $uid, (string) $local['fiat_amount'], $o['clientRef'] . 'R', "Refund: buy {$o['symbol']}");
            $db->prepare("UPDATE mz_crypto_orders SET status = 'refunded' WHERE id = ?")->execute([$local['id']]);
            break;

        case 'buy.completed':
            $db->prepare("UPDATE mz_crypto_orders SET status = 'completed', tx_hash = ? WHERE id = ?")
               ->execute([$o['txHash'], $local['id']]);
            break;

        default:
            throw new RuntimeException('unknown event ' . $ev['type']);
    }

    $db->commit();
    mz_json(['ok' => true]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[mz-crypto callback] ' . $e->getMessage());
    mz_json(['ok' => false, 'error' => 'failed'], 500); // service will retry
}
