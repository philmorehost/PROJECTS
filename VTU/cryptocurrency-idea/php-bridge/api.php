<?php
// Browser-facing endpoint used by the Buy/Sell widget.
//   GET  api.php?action=init            → csrf token, assets, rates, limits, wallet balance
//   POST api.php?action=quote           {side, asset, cryptoAmount | fiatAmount}
//   POST api.php?action=buy             {quoteId, address}
//   POST api.php?action=sell            {quoteId}
//   GET  api.php?action=status&ref=...  → live order status
//   GET  api.php?action=history         → user's last 20 orders
declare(strict_types=1);
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';
$uid = mz_require_user();
$db = mz_db();

switch ($action) {

    case 'init': {
        $r = mz_service('GET', '/v1/assets');
        if (!$r['ok']) mz_fail(mz_service_error($r), 503);
        $w = mz_cfg()['wallet'];
        $bal = $db->prepare("SELECT `{$w['balance_col']}` FROM `{$w['table']}` WHERE `{$w['id_col']}` = ?");
        $bal->execute([$uid]);
        mz_json(['ok' => true, 'csrf' => mz_csrf_token(), 'balance' => (string) $bal->fetchColumn()] + $r['data']);
    }

    case 'quote': {
        mz_require_csrf();
        mz_rate_limit('quote', 20);
        $in = mz_input();
        $body = [
            'side'  => in_array($in['side'] ?? '', ['buy', 'sell'], true) ? $in['side'] : mz_fail('Invalid side'),
            'asset' => preg_match('/^[A-Z_]{2,20}$/', (string) ($in['asset'] ?? '')) ? $in['asset'] : mz_fail('Invalid asset'),
        ];
        if (!empty($in['fiatAmount'])) $body['fiatAmount'] = (string) $in['fiatAmount'];
        elseif (!empty($in['cryptoAmount'])) $body['cryptoAmount'] = (string) $in['cryptoAmount'];
        else mz_fail('Enter an amount');
        $r = mz_service('POST', '/v1/quotes', $body);
        if (!$r['ok']) mz_fail(mz_service_error($r), $r['status'] ?: 503);
        mz_json(['ok' => true, 'quote' => $r['data']]);
    }

    case 'buy': {
        mz_require_csrf();
        mz_rate_limit('order', 6);
        if (!mz_user_can_trade($uid)) mz_fail('Please complete verification (KYC) to trade crypto', 403);
        $in = mz_input();
        $quoteId = (string) ($in['quoteId'] ?? '');
        $address = trim((string) ($in['address'] ?? ''));
        if (!preg_match('/^[\w-]{36}$/', $quoteId)) mz_fail('Invalid quote');

        // The price comes from the service, never from the browser.
        $q = mz_service('GET', '/v1/quotes/' . rawurlencode($quoteId));
        if (!$q['ok']) mz_fail(mz_service_error($q), $q['status'] ?: 503);
        $quote = $q['data'];
        if ($quote['side'] !== 'buy' || $quote['used']) mz_fail('This quote can no longer be used');
        if ($quote['expiresAt'] / 1000 < time() + 3) mz_fail('Price expired — please get a new quote', 410);
        if (!mz_valid_address($quote['addressType'] ?? '', $address)) {
            mz_fail("Enter a valid {$quote['network']} address for {$quote['symbol']}");
        }

        $amount = $quote['fiatAmount'];
        $ref = 'MZB' . bin2hex(random_bytes(10));
        $desc = "Buy {$quote['cryptoAmount']} {$quote['symbol']}";

        // 1) debit the user's wallet and record the order (atomic)
        $db->beginTransaction();
        if (!mz_wallet_debit($db, $uid, $amount, $ref, $desc)) {
            $db->rollBack();
            mz_fail('Insufficient wallet balance — fund your wallet and try again');
        }
        $db->prepare("INSERT INTO mz_crypto_orders (client_ref, user_id, side, asset, crypto_amount, fiat_amount, status, quote_id, address)
                      VALUES (?, ?, 'buy', ?, ?, ?, 'pending', ?, ?)")
           ->execute([$ref, $uid, $quote['asset'], $quote['cryptoAmount'], $amount, $quoteId, $address]);
        $db->commit();

        // 2) ask the service to send the crypto (idempotent on $ref, so retries are safe)
        $r = mz_service_retry('POST', '/v1/orders/buy', [
            'quoteId' => $quoteId, 'clientRef' => $ref, 'userRef' => (string) $uid, 'destination' => $address,
        ]);

        if ($r['ok']) {
            $db->prepare('UPDATE mz_crypto_orders SET node_order_id = ?, status = ? WHERE client_ref = ?')
               ->execute([$r['data']['id'], $r['data']['status'], $ref]);
            mz_json(['ok' => true, 'order' => $r['data']]);
        }

        if (!$r['network_error']) {
            // definite rejection (expired quote, no liquidity…) → refund now
            $db->beginTransaction();
            $upd = $db->prepare("UPDATE mz_crypto_orders SET status = 'refunded' WHERE client_ref = ? AND status = 'pending'");
            $upd->execute([$ref]);
            if ($upd->rowCount() === 1) mz_wallet_credit($db, $uid, $amount, $ref . 'R', "Refund: $desc");
            $db->commit();
            mz_fail(mz_service_error($r) . ' — your wallet has been refunded', $r['status']);
        }

        // unknown outcome: keep the debit; status page / admin reconciles via clientRef
        $db->prepare("UPDATE mz_crypto_orders SET status = 'unknown' WHERE client_ref = ?")->execute([$ref]);
        mz_json(['ok' => true, 'order' => ['clientRef' => $ref, 'status' => 'processing']]);
    }

    case 'sell': {
        mz_require_csrf();
        mz_rate_limit('order', 6);
        if (!mz_user_can_trade($uid)) mz_fail('Please complete verification (KYC) to trade crypto', 403);
        $in = mz_input();
        $quoteId = (string) ($in['quoteId'] ?? '');
        if (!preg_match('/^[\w-]{36}$/', $quoteId)) mz_fail('Invalid quote');
        $ref = 'MZS' . bin2hex(random_bytes(10));

        $r = mz_service_retry('POST', '/v1/orders/sell', ['quoteId' => $quoteId, 'clientRef' => $ref, 'userRef' => (string) $uid]);
        if (!$r['ok']) mz_fail(mz_service_error($r), $r['status'] ?: 503);
        $o = $r['data'];
        $db->prepare("INSERT INTO mz_crypto_orders (client_ref, node_order_id, user_id, side, asset, crypto_amount, fiat_amount, status, quote_id, address)
                      VALUES (?, ?, ?, 'sell', ?, ?, ?, ?, ?, ?)")
           ->execute([$ref, $o['id'], $uid, $o['asset'], $o['cryptoAmount'], $o['fiatAmount'], $o['status'], $quoteId, $o['depositAddress']]);
        mz_json(['ok' => true, 'order' => $o]);
    }

    case 'status': {
        $ref = (string) ($_GET['ref'] ?? '');
        $st = $db->prepare('SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec FROM mz_crypto_orders WHERE client_ref = ? AND user_id = ?');
        $st->execute([$ref, $uid]);
        $local = $st->fetch();
        if (!$local) mz_fail('Order not found', 404);
        $r = mz_service('GET', '/v1/orders/by-ref/' . rawurlencode($ref));

        // A buy whose request never reached the service: once its quote has expired (5 min is
        // well past the 2-min TTL) it can never be created, so refunding is safe.
        if ($r['status'] === 404 && $local['side'] === 'buy' && in_array($local['status'], ['pending', 'unknown'], true)
            && (int) $local['age_sec'] > 300) {
            $db->beginTransaction();
            $upd = $db->prepare("UPDATE mz_crypto_orders SET status = 'refunded' WHERE id = ? AND status IN ('pending','unknown')");
            $upd->execute([$local['id']]);
            if ($upd->rowCount() === 1) {
                mz_wallet_credit($db, $uid, (string) $local['fiat_amount'], $ref . 'R', 'Refund: crypto buy not processed');
            }
            $db->commit();
            mz_json(['ok' => true, 'order' => ['clientRef' => $ref, 'side' => 'buy', 'status' => 'failed', 'refunded' => true]]);
        }
        if (!$r['ok']) mz_json(['ok' => true, 'order' => ['clientRef' => $ref, 'status' => $local['status']]]);
        $o = $r['data'];
        if ($local['node_order_id'] === null) {
            $db->prepare('UPDATE mz_crypto_orders SET node_order_id = ? WHERE client_ref = ?')->execute([$o['id'], $ref]);
        }
        mz_json(['ok' => true, 'order' => $o]);
    }

    case 'history': {
        $st = $db->prepare('SELECT client_ref, side, asset, crypto_amount, fiat_amount, status, tx_hash, created_at
                            FROM mz_crypto_orders WHERE user_id = ? ORDER BY id DESC LIMIT 20');
        $st->execute([$uid]);
        mz_json(['ok' => true, 'orders' => $st->fetchAll()]);
    }

    default:
        mz_fail('Unknown action', 404);
}
