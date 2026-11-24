<?php
/**
 * Held Replacement Crypto Buy/Sell Foundation (Admin <-> User A2P/P2A).
 * - Tenant-bound integer/fixed-point arithmetic (1e8 crypto units, minor-unit fiat).
 * - 2-minute (120s) quote lock binding exact rate, margin, fees, asset, network & destination.
 * - Isolated signer outbox: never stores seeds/private keys or signs inside HTTP requests.
 * - Timeout/unknown payout state never auto-refunds or auto-retries.
 * - Live execution remains disabled unless CRYPTO_BUYSELL_EXECUTION_ENABLED=1.
 */

if (!defined('BC_CBS_HOLD_CODE')) define('BC_CBS_HOLD_CODE', 'CRYPTO_BUYSELL_HOLD');

function bc_cbs_execution_enabled() {
    return getenv('CRYPTO_BUYSELL_EXECUTION_ENABLED') === '1';
}

function bc_cbs_supported_networks() {
    return ['TRON', 'BSC', 'ETH', 'BTC', 'SOL'];
}

function bc_cbs_validate_destination($network, $address) {
    $net = strtoupper(trim((string)$network));
    $addr = trim((string)$address);
    if (!in_array($net, bc_cbs_supported_networks(), true) || $addr === '') return false;
    if ($net === 'TRON') return (bool)preg_match('/\AT[1-9A-HJ-NP-Za-km-z]{33}\z/D', $addr);
    if ($net === 'BSC' || $net === 'ETH') return (bool)preg_match('/\A0x[0-9a-fA-F]{40}\z/D', $addr);
    if ($net === 'BTC') {
        return (bool)(preg_match('/\Abc1[0-9ac-hj-np-z]{25,87}\z/D', $addr)
            || preg_match('/\A[13][1-9A-HJ-NP-Za-km-z]{25,34}\z/D', $addr));
    }
    if ($net === 'SOL') return (bool)preg_match('/\A[1-9A-HJ-NP-Za-km-z]{32,44}\z/D', $addr);
    return false;
}

/** Integer-only fixed-point conversion: minor units (e.g. kobo/cents) <-> decimal string. */
function bc_cbs_minor_to_decimal($minorStr) {
    $s = preg_replace('/\A0+(?=[0-9])/', '', trim((string)$minorStr));
    if (!preg_match('/\A[0-9]+\z/D', $s)) throw new InvalidArgumentException('Invalid minor amount.');
    $len = strlen($s);
    if ($len === 1) return '0.0' . $s;
    if ($len === 2) return '0.' . $s;
    return substr($s, 0, $len - 2) . '.' . substr($s, -2);
}

function bc_cbs_uint_str($val, $allowZero = false) {
    if (is_int($val)) {
        if ($val < 0 || (!$allowZero && $val === 0)) throw new InvalidArgumentException('Invalid unsigned integer.');
        return (string)$val;
    }
    if (!is_string($val)) throw new InvalidArgumentException('Invalid unsigned integer.');
    $s = ltrim(trim($val), '0');
    if ($s === '') $s = '0';
    if (!preg_match('/\A[0-9]{1,19}\z/D', $s) || (!$allowZero && $s === '0')) {
        throw new InvalidArgumentException('Invalid unsigned integer.');
    }
    return $s;
}

/** Exact integer multiplication: floor((crypto_1e8 * rate_fiat_minor) / 100_000_000) using 32-bit-safe string math. */
function bc_cbs_compute_fiat_total($cryptoUnits1e8, $rateFiatMinor, $feeFiatMinor, $side) {
    $a = bc_cbs_uint_str($cryptoUnits1e8, false);
    $b = bc_cbs_uint_str($rateFiatMinor, false);
    $feeStr = bc_cbs_uint_str($feeFiatMinor, true);
    $feeInt = (int)$feeStr;
    $la = strlen($a); $lb = strlen($b);
    $res = array_fill(0, $la + $lb, 0);
    for ($i = $la - 1; $i >= 0; $i--) {
        $da = ord($a[$i]) - 48;
        for ($j = $lb - 1; $j >= 0; $j--) {
            $p = $da * (ord($b[$j]) - 48) + $res[$i + $j + 1];
            $res[$i + $j + 1] = $p % 10;
            $res[$i + $j] += (int)($p / 10);
        }
    }
    $prod = ltrim(implode('', $res), '0');
    if ($prod === '') $prod = '0';
    // Divide by 1e8 (strip last 8 digits; round up on BUY if remainder > 0, round down on SELL).
    $plen = strlen($prod);
    $base = $plen > 8 ? substr($prod, 0, $plen - 8) : '0';
    $rem  = $plen > 8 ? substr($prod, -8) : str_pad($prod, 8, '0', STR_PAD_LEFT);
    $baseInt = (int)$base;
    if ($side === 'buy' && ltrim($rem, '0') !== '') $baseInt += 1;
    if ($side === 'buy') {
        $total = $baseInt + $feeInt;
    } else {
        if ($feeInt >= $baseInt) throw new InvalidArgumentException('Sell proceeds below fee.');
        $total = $baseInt - $feeInt;
    }
    if ($total <= 0) throw new InvalidArgumentException('Zero fiat total.');
    return $total;
}

function bc_cbs_assert_innodb(mysqli $db) {
    $ac = $db->query('SELECT @@session.autocommit AS ac, @@session.transaction_isolation AS iso');
    $row = $ac ? $ac->fetch_assoc() : null;
    if (!$row || (string)$row['ac'] !== '1' || stripos((string)$row['iso'], 'REPEATABLE') === false) {
        throw new RuntimeException('Unsafe database transaction state.');
    }
    $needed = [
        'sas_users', 'sas_wallet_mutation_events', 'sas_transactions',
        'sas_crypto_quote_locks', 'sas_crypto_trade_orders', 'sas_crypto_trade_events',
        'sas_crypto_chain_deposits', 'sas_crypto_signer_outbox',
    ];
    $in = "'" . implode("','", $needed) . "'";
    $res = $db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($in)");
    $engines = [];
    while ($res && ($r = $res->fetch_assoc())) $engines[(string)$r['TABLE_NAME']] = strtoupper((string)$r['ENGINE']);
    foreach ($needed as $t) {
        if (($engines[$t] ?? '') !== 'INNODB') throw new RuntimeException("Required InnoDB table missing or non-transactional: $t");
    }
}

function bc_cbs_create_quote(mysqli $db, array $input) {
    bc_cbs_assert_innodb($db);
    $vendorId = (int)($input['vendor_id'] ?? 0);
    $userId   = (int)($input['user_id'] ?? 0);
    $reqId    = trim((string)($input['request_id'] ?? ''));
    $side     = (string)($input['side'] ?? '');
    $asset    = strtoupper(trim((string)($input['asset'] ?? '')));
    $network  = strtoupper(trim((string)($input['network'] ?? '')));
    $currency = strtoupper(trim((string)($input['fiat_currency'] ?? 'NGN')));
    $units    = bc_cbs_uint_str($input['crypto_units_1e8'] ?? '', false);
    $rate     = (int)($input['rate_fiat_minor'] ?? 0);
    $margin   = (int)($input['margin_bps'] ?? 0);
    $fee      = (int)($input['fee_fiat_minor'] ?? 0);
    $dest     = trim((string)($input['destination_address'] ?? ''));
    $kycOk    = !empty($input['kyc_verified']) ? 1 : 0;
    $ttl      = isset($input['ttl_seconds']) ? (int)$input['ttl_seconds'] : 120;

    if ($vendorId < 1 || $userId < 1 || !preg_match('/\A[A-Za-z0-9_.:-]{8,80}\z/D', $reqId)) {
        throw new InvalidArgumentException('Invalid quote identity.');
    }
    if (!in_array($side, ['buy', 'sell'], true)) throw new InvalidArgumentException('Invalid trade side.');
    if (!in_array($asset, ['USDT', 'USDC', 'BTC', 'TRX', 'ETH', 'SOL'], true)) throw new InvalidArgumentException('Unsupported asset.');
    if (!in_array($network, bc_cbs_supported_networks(), true)) throw new InvalidArgumentException('Unsupported network.');
    if (!preg_match('/\A[A-Z]{3}\z/D', $currency)) throw new InvalidArgumentException('Invalid fiat currency.');
    if ($margin < 0 || $margin > 5000) throw new InvalidArgumentException('Invalid margin bps.');
    if ($ttl < 1 || $ttl > 120) throw new InvalidArgumentException('Quote TTL must be between 1 and 120 seconds.');
    if ($kycOk !== 1) throw new RuntimeException('KYC verification required.');
    if ($side === 'buy' && !bc_cbs_validate_destination($network, $dest)) {
        throw new InvalidArgumentException('Invalid destination address for selected network.');
    }

    $totalMinor = bc_cbs_compute_fiat_total($units, $rate, $fee, $side);

    $db->begin_transaction();
    try {
        $uq = $db->prepare('SELECT id, is_blocked FROM sas_users WHERE id=? AND vendor_id=? LIMIT 1 FOR UPDATE');
        $uq->bind_param('ii', $userId, $vendorId);
        $uq->execute();
        $u = $uq->get_result()->fetch_assoc();
        $uq->close();
        if (!$u || (int)$u['is_blocked'] === 1) throw new RuntimeException('Account unavailable or blocked.');

        $eq = $db->prepare('SELECT id, side, asset, network, total_fiat_minor, status FROM sas_crypto_quote_locks WHERE vendor_id=? AND request_id=? LIMIT 1');
        $eq->bind_param('is', $vendorId, $reqId);
        $eq->execute();
        $existing = $eq->get_result()->fetch_assoc();
        $eq->close();
        if ($existing) {
            if ($existing['side'] !== $side || $existing['asset'] !== $asset || $existing['network'] !== $network || (int)$existing['total_fiat_minor'] !== $totalMinor) {
                throw new RuntimeException('Quote request conflict.');
            }
            $db->commit();
            return ['quote_id' => (int)$existing['id'], 'total_fiat_minor' => $totalMinor, 'replayed' => true];
        }

        $ins = $db->prepare('INSERT INTO sas_crypto_quote_locks(vendor_id, user_id, request_id, side, asset, network, fiat_currency, crypto_units_1e8, rate_fiat_minor, margin_bps, fee_fiat_minor, total_fiat_minor, destination_address, kyc_verified, expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL ? SECOND))');
        $ins->bind_param('iissssssiiiisii', $vendorId, $userId, $reqId, $side, $asset, $network, $currency, $units, $rate, $margin, $fee, $totalMinor, $dest, $kycOk, $ttl);
        $ins->execute();
        $qid = (int)$ins->insert_id;
        $ins->close();
        $db->commit();
        return ['quote_id' => $qid, 'total_fiat_minor' => $totalMinor, 'replayed' => false];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function bc_cbs_execute_buy_order(mysqli $db, $vendorId, $userId, $quoteId, $requestId) {
    bc_cbs_assert_innodb($db);
    $vendorId = (int)$vendorId; $userId = (int)$userId; $quoteId = (int)$quoteId;
    $reqId = trim((string)$requestId);
    if ($vendorId < 1 || $userId < 1 || $quoteId < 1 || !preg_match('/\A[A-Za-z0-9_.:-]{8,80}\z/D', $reqId)) {
        throw new InvalidArgumentException('Invalid buy order parameters.');
    }

    $db->begin_transaction();
    try {
        $oq = $db->prepare('SELECT id, quote_id, state, fiat_minor FROM sas_crypto_trade_orders WHERE vendor_id=? AND request_id=? LIMIT 1');
        $oq->bind_param('is', $vendorId, $reqId);
        $oq->execute();
        $existing = $oq->get_result()->fetch_assoc();
        $oq->close();
        if ($existing) {
            if ((int)$existing['quote_id'] !== $quoteId) throw new RuntimeException('Order request conflict.');
            $db->commit();
            return ['order_id' => (int)$existing['id'], 'state' => $existing['state'], 'replayed' => true];
        }

        $uq = $db->prepare('SELECT id, username, balance, is_blocked FROM sas_users WHERE id=? AND vendor_id=? LIMIT 1 FOR UPDATE');
        $uq->bind_param('ii', $userId, $vendorId);
        $uq->execute();
        $user = $uq->get_result()->fetch_assoc();
        $uq->close();
        if (!$user || (int)$user['is_blocked'] === 1) throw new RuntimeException('User account blocked or unavailable.');

        $qq = $db->prepare('SELECT id, side, asset, network, fiat_currency, crypto_units_1e8, total_fiat_minor, destination_address, status, (expires_at < NOW()) AS is_expired FROM sas_crypto_quote_locks WHERE id=? AND vendor_id=? AND user_id=? LIMIT 1 FOR UPDATE');
        $qq->bind_param('iii', $quoteId, $vendorId, $userId);
        $qq->execute();
        $quote = $qq->get_result()->fetch_assoc();
        $qq->close();
        if (!$quote || $quote['side'] !== 'buy') throw new RuntimeException('Buy quote unavailable.');
        if ($quote['status'] !== 'open') throw new RuntimeException('Quote already used or closed.');
        if ((int)$quote['is_expired'] === 1) {
            $exp = $db->prepare("UPDATE sas_crypto_quote_locks SET status='expired' WHERE id=? AND status='open'");
            $exp->bind_param('i', $quoteId);
            $exp->execute();
            $exp->close();
            $db->commit();
            throw new RuntimeException('Quote expired.');
        }

        $fiatMinor = (int)$quote['total_fiat_minor'];
        $fiatDec = bc_cbs_minor_to_decimal($fiatMinor);
        $fiatFloat = (float)$fiatDec;
        $claimRef = 'cbs_buy_' . $vendorId . '_' . $reqId;
        $kind = 'crypto_buy_debit';

        // Atomic wallet mutation claim + guarded balance debit
        $cm = $db->prepare('INSERT INTO sas_wallet_mutation_events(vendor_id, user_id, reference, mutation_kind, amount) VALUES(?,?,?,?,?)');
        $cm->bind_param('iissd', $vendorId, $userId, $claimRef, $kind, $fiatFloat);
        $cm->execute();
        $claimId = (int)$cm->insert_id;
        $cm->close();

        $deb = $db->prepare('UPDATE sas_users SET balance = balance - ? WHERE id=? AND vendor_id=? AND is_blocked=0 AND balance >= ?');
        $deb->bind_param('diid', $fiatFloat, $userId, $vendorId, $fiatFloat);
        $deb->execute();
        if ($deb->affected_rows !== 1) {
            $deb->close();
            throw new RuntimeException('Insufficient wallet balance.');
        }
        $deb->close();

        $balAfter = (float)$user['balance'] - $fiatFloat;
        $txType = 'Crypto Buy';
        $desc = 'Held crypto buy reservation ' . $quote['asset'] . '/' . $quote['network'];
        $txIns = $db->prepare('INSERT INTO sas_transactions(vendor_id, username, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, mode, status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $prod = $quote['asset'] . '-' . $quote['network'];
        $mode = 'CRYPTO_BUY';
        $stStr = '2'; // held/pending
        $balBefore = (float)$user['balance'];
        $txIns->bind_param('issssddddsss', $vendorId, $user['username'], $prod, $txType, $claimRef, $fiatFloat, $fiatFloat, $balBefore, $balAfter, $desc, $mode, $stStr);
        $txIns->execute();
        $txIns->close();

        $uqUp = $db->prepare("UPDATE sas_crypto_quote_locks SET status='consumed' WHERE id=? AND status='open'");
        $uqUp->bind_param('i', $quoteId);
        $uqUp->execute();
        $uqUp->close();

        $ordState = bc_cbs_execution_enabled() ? 'never_attempted' : 'held';
        $insOrd = $db->prepare('INSERT INTO sas_crypto_trade_orders(vendor_id, user_id, quote_id, request_id, side, asset, network, fiat_currency, crypto_units_1e8, fiat_minor, destination_address, state, ledger_claim_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $side = 'buy';
        $units = (string)$quote['crypto_units_1e8'];
        $insOrd->bind_param('iiissssssissi', $vendorId, $userId, $quoteId, $reqId, $side, $quote['asset'], $quote['network'], $quote['fiat_currency'], $units, $fiatMinor, $quote['destination_address'], $ordState, $claimId);
        $insOrd->execute();
        $orderId = (int)$insOrd->insert_id;
        $insOrd->close();

        $outSha = hash('sha256', json_encode([
            'order_id' => $orderId, 'vendor_id' => $vendorId, 'asset' => $quote['asset'],
            'network' => $quote['network'], 'destination_address' => $quote['destination_address'], 'units' => $units,
        ], JSON_THROW_ON_ERROR));
        $outState = bc_cbs_execution_enabled() ? 'queued_for_signer' : 'held';
        $insOut = $db->prepare('INSERT INTO sas_crypto_signer_outbox(order_id, vendor_id, asset, network, destination_address, crypto_units_1e8, payload_sha256, dispatch_state) VALUES(?,?,?,?,?,?,?,?)');
        $insOut->bind_param('iissssss', $orderId, $vendorId, $quote['asset'], $quote['network'], $quote['destination_address'], $units, $outSha, $outState);
        $insOut->execute();
        $insOut->close();

        $ev = $db->prepare('INSERT INTO sas_crypto_trade_events(order_id, vendor_id, from_state, to_state, actor_ref, detail_json) VALUES(?,?,?,?,?,?)');
        $from = 'new'; $actor = 'user_' . $userId; $djson = json_encode(['quote_id' => $quoteId, 'claim_id' => $claimId]);
        $ev->bind_param('iissss', $orderId, $vendorId, $from, $ordState, $actor, $djson);
        $ev->execute();
        $ev->close();

        $db->commit();
        return ['order_id' => $orderId, 'state' => $ordState, 'replayed' => false];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function bc_cbs_create_sell_order(mysqli $db, $vendorId, $userId, $quoteId, $requestId, $depositAddress) {
    bc_cbs_assert_innodb($db);
    $vendorId = (int)$vendorId; $userId = (int)$userId; $quoteId = (int)$quoteId;
    $reqId = trim((string)$requestId);
    $depAddr = trim((string)$depositAddress);
    if ($vendorId < 1 || $userId < 1 || $quoteId < 1 || !preg_match('/\A[A-Za-z0-9_.:-]{8,80}\z/D', $reqId)) {
        throw new InvalidArgumentException('Invalid sell order parameters.');
    }

    $db->begin_transaction();
    try {
        $oq = $db->prepare('SELECT id, quote_id, state, destination_address FROM sas_crypto_trade_orders WHERE vendor_id=? AND request_id=? LIMIT 1');
        $oq->bind_param('is', $vendorId, $reqId);
        $oq->execute();
        $existing = $oq->get_result()->fetch_assoc();
        $oq->close();
        if ($existing) {
            if ((int)$existing['quote_id'] !== $quoteId) throw new RuntimeException('Order request conflict.');
            $db->commit();
            return ['order_id' => (int)$existing['id'], 'state' => $existing['state'], 'deposit_address' => $existing['destination_address'], 'replayed' => true];
        }

        $uq = $db->prepare('SELECT id, is_blocked FROM sas_users WHERE id=? AND vendor_id=? LIMIT 1 FOR UPDATE');
        $uq->bind_param('ii', $userId, $vendorId);
        $uq->execute();
        $user = $uq->get_result()->fetch_assoc();
        $uq->close();
        if (!$user || (int)$user['is_blocked'] === 1) throw new RuntimeException('User account blocked or unavailable.');

        $qq = $db->prepare('SELECT id, side, asset, network, fiat_currency, crypto_units_1e8, total_fiat_minor, status, (expires_at < NOW()) AS is_expired FROM sas_crypto_quote_locks WHERE id=? AND vendor_id=? AND user_id=? LIMIT 1 FOR UPDATE');
        $qq->bind_param('iii', $quoteId, $vendorId, $userId);
        $qq->execute();
        $quote = $qq->get_result()->fetch_assoc();
        $qq->close();
        if (!$quote || $quote['side'] !== 'sell') throw new RuntimeException('Sell quote unavailable.');
        if ($quote['status'] !== 'open') throw new RuntimeException('Quote already used or closed.');
        if ((int)$quote['is_expired'] === 1) {
            $exp = $db->prepare("UPDATE sas_crypto_quote_locks SET status='expired' WHERE id=? AND status='open'");
            $exp->bind_param('i', $quoteId);
            $exp->execute();
            $exp->close();
            $db->commit();
            throw new RuntimeException('Quote expired.');
        }
        if (!bc_cbs_validate_destination($quote['network'], $depAddr)) {
            throw new InvalidArgumentException('Invalid custody deposit address for network.');
        }

        $uqUp = $db->prepare("UPDATE sas_crypto_quote_locks SET status='consumed' WHERE id=? AND status='open'");
        $uqUp->bind_param('i', $quoteId);
        $uqUp->execute();
        $uqUp->close();

        $ordState = bc_cbs_execution_enabled() ? 'never_attempted' : 'held';
        $insOrd = $db->prepare('INSERT INTO sas_crypto_trade_orders(vendor_id, user_id, quote_id, request_id, side, asset, network, fiat_currency, crypto_units_1e8, fiat_minor, destination_address, state) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $side = 'sell';
        $units = (string)$quote['crypto_units_1e8'];
        $fiatMinor = (int)$quote['total_fiat_minor'];
        $insOrd->bind_param('iiissssssiss', $vendorId, $userId, $quoteId, $reqId, $side, $quote['asset'], $quote['network'], $quote['fiat_currency'], $units, $fiatMinor, $depAddr, $ordState);
        $insOrd->execute();
        $orderId = (int)$insOrd->insert_id;
        $insOrd->close();

        $ev = $db->prepare('INSERT INTO sas_crypto_trade_events(order_id, vendor_id, from_state, to_state, actor_ref, detail_json) VALUES(?,?,?,?,?,?)');
        $from = 'new'; $actor = 'user_' . $userId; $djson = json_encode(['quote_id' => $quoteId, 'deposit_address' => $depAddr]);
        $ev->bind_param('iissss', $orderId, $vendorId, $from, $ordState, $actor, $djson);
        $ev->execute();
        $ev->close();

        $db->commit();
        return ['order_id' => $orderId, 'state' => $ordState, 'deposit_address' => $depAddr, 'replayed' => false];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function bc_cbs_observe_payout_outcome(mysqli $db, $vendorId, $orderId, $outcome, array $details = []) {
    bc_cbs_assert_innodb($db);
    $vendorId = (int)$vendorId; $orderId = (int)$orderId;
    if (!in_array($outcome, ['rejected_before_broadcast', 'broadcast', 'confirmed', 'unknown'], true)) {
        throw new InvalidArgumentException('Invalid payout outcome.');
    }
    $providerRef = isset($details['provider_ref']) ? substr((string)$details['provider_ref'], 0, 128) : null;
    $txHash      = isset($details['tx_hash']) ? substr((string)$details['tx_hash'], 0, 128) : null;
    $errMsg      = substr((string)($details['error'] ?? ''), 0, 255);

    $db->begin_transaction();
    try {
        $oq = $db->prepare('SELECT id, user_id, state, fiat_minor, refund_claim_id, request_id FROM sas_crypto_trade_orders WHERE id=? AND vendor_id=? LIMIT 1 FOR UPDATE');
        $oq->bind_param('ii', $orderId, $vendorId);
        $oq->execute();
        $ord = $oq->get_result()->fetch_assoc();
        $oq->close();
        if (!$ord) throw new RuntimeException('Order not found.');

        $cur = $ord['state'];
        // Timeout/unknown is NEVER auto-refunded or overwritten by rejected_before_broadcast!
        if ($cur === 'unknown' && $outcome === 'rejected_before_broadcast') {
            throw new RuntimeException('Cannot refund order after unknown broadcast outcome.');
        }
        if ($cur === 'refunded' || $cur === 'confirmed') {
            $db->commit();
            return ['order_id' => $orderId, 'state' => $cur, 'replayed' => true];
        }

        $newState = $outcome;
        $refundClaimId = $ord['refund_claim_id'] !== null ? (int)$ord['refund_claim_id'] : null;
        if ($outcome === 'rejected_before_broadcast' && $refundClaimId === null) {
            if (!in_array($cur, ['held', 'never_attempted'], true)) {
                throw new RuntimeException('Refund only permitted before broadcast.');
            }
            $userId = (int)$ord['user_id'];
            $fiatFloat = (float)bc_cbs_minor_to_decimal((int)$ord['fiat_minor']);
            $refClaim = 'cbs_refund_' . $vendorId . '_' . $ord['request_id'];
            $rKind = 'crypto_buy_refund';

            $uq = $db->prepare('SELECT id FROM sas_users WHERE id=? AND vendor_id=? LIMIT 1 FOR UPDATE');
            $uq->bind_param('ii', $userId, $vendorId);
            $uq->execute();
            $uq->close();

            $cm = $db->prepare('INSERT INTO sas_wallet_mutation_events(vendor_id, user_id, reference, mutation_kind, amount) VALUES(?,?,?,?,?)');
            $cm->bind_param('iissd', $vendorId, $userId, $refClaim, $rKind, $fiatFloat);
            $cm->execute();
            $refundClaimId = (int)$cm->insert_id;
            $cm->close();

            $cr = $db->prepare('UPDATE sas_users SET balance = balance + ? WHERE id=? AND vendor_id=?');
            $cr->bind_param('dii', $fiatFloat, $userId, $vendorId);
            $cr->execute();
            $cr->close();
            $newState = 'refunded';
        }

        $up = $db->prepare('UPDATE sas_crypto_trade_orders SET state=?, provider_ref=COALESCE(?, provider_ref), tx_hash=COALESCE(?, tx_hash), refund_claim_id=?, last_error=? WHERE id=?');
        $up->bind_param('sssisi', $newState, $providerRef, $txHash, $refundClaimId, $errMsg, $orderId);
        $up->execute();
        $up->close();

        $outState = ($newState === 'refunded') ? 'failed_pre_broadcast' : ($newState === 'unknown' ? 'unknown' : 'sent');
        $uout = $db->prepare('UPDATE sas_crypto_signer_outbox SET dispatch_state=? WHERE order_id=?');
        $uout->bind_param('si', $outState, $orderId);
        $uout->execute();
        $uout->close();

        $ev = $db->prepare('INSERT INTO sas_crypto_trade_events(order_id, vendor_id, from_state, to_state, actor_ref, detail_json) VALUES(?,?,?,?,?,?)');
        $actor = 'signer_observer'; $djson = json_encode(['outcome' => $outcome, 'tx_hash' => $txHash]);
        $ev->bind_param('iissss', $orderId, $vendorId, $cur, $newState, $actor, $djson);
        $ev->execute();
        $ev->close();

        $db->commit();
        return ['order_id' => $orderId, 'state' => $newState, 'replayed' => false];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function bc_cbs_record_sell_deposit(mysqli $db, array $input) {
    bc_cbs_assert_innodb($db);
    $vendorId = (int)($input['vendor_id'] ?? 0);
    $userId   = (int)($input['user_id'] ?? 0);
    $orderId  = isset($input['order_id']) ? (int)$input['order_id'] : null;
    $network  = strtoupper(trim((string)($input['network'] ?? '')));
    $expectedNet = strtoupper(trim((string)($input['expected_network'] ?? $network)));
    $asset    = strtoupper(trim((string)($input['asset'] ?? '')));
    $txHash   = trim((string)($input['tx_hash'] ?? ''));
    $logIdx   = (int)($input['log_index'] ?? 0);
    $addr     = trim((string)($input['deposit_address'] ?? ''));
    $expUnits = bc_cbs_uint_str($input['expected_units_1e8'] ?? '', false);
    $obsUnits = bc_cbs_uint_str($input['observed_units_1e8'] ?? '', false);
    $confs    = (int)($input['confirmations'] ?? 0);
    $reqConfs = (int)($input['required_confirmations'] ?? 1);
    $reorged  = !empty($input['reorg_detected']) ? 1 : 0;

    if ($vendorId < 1 || $userId < 1 || $txHash === '' || $reqConfs < 1) {
        throw new InvalidArgumentException('Invalid deposit observation.');
    }

    $cmp = (strlen($obsUnits) !== strlen($expUnits)) ? (strlen($obsUnits) <=> strlen($expUnits)) : strcmp($obsUnits, $expUnits);
    if ($reorged === 1) {
        $state = 'reorged_held';
    } elseif ($network !== $expectedNet) {
        $state = 'wrong_network_held';
    } elseif ($confs < $reqConfs) {
        $state = 'pending_confirmations';
    } elseif ($cmp < 0) {
        $state = 'underpaid_review';
    } elseif ($cmp > 0) {
        $state = 'overpaid_review';
    } else {
        $state = 'matched_final';
    }

    $db->begin_transaction();
    try {
        $dq = $db->prepare('SELECT id, state FROM sas_crypto_chain_deposits WHERE vendor_id=? AND network=? AND tx_hash=? AND log_index=? LIMIT 1 FOR UPDATE');
        $dq->bind_param('issi', $vendorId, $network, $txHash, $logIdx);
        $dq->execute();
        $existing = $dq->get_result()->fetch_assoc();
        $dq->close();
        if ($existing) {
            if ($existing['state'] === 'credited' && $reorged === 0) {
                $db->commit();
                return ['deposit_id' => (int)$existing['id'], 'state' => 'credited', 'replayed' => true];
            }
            if ($existing['state'] !== 'credited') {
                $up = $db->prepare('UPDATE sas_crypto_chain_deposits SET confirmations=?, reorg_detected=?, state=? WHERE id=?');
                $up->bind_param('iisi', $confs, $reorged, $state, $existing['id']);
                $up->execute();
                $up->close();
            }
            $db->commit();
            return ['deposit_id' => (int)$existing['id'], 'state' => $existing['state'] === 'credited' ? 'credited' : $state, 'replayed' => true];
        }

        $ins = $db->prepare('INSERT INTO sas_crypto_chain_deposits(vendor_id, user_id, order_id, network, expected_network, asset, tx_hash, log_index, deposit_address, expected_units_1e8, observed_units_1e8, confirmations, required_confirmations, reorg_detected, state) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $ins->bind_param('iiissssisssiiis', $vendorId, $userId, $orderId, $network, $expectedNet, $asset, $txHash, $logIdx, $addr, $expUnits, $obsUnits, $confs, $reqConfs, $reorged, $state);
        $ins->execute();
        $depId = (int)$ins->insert_id;
        $ins->close();
        $db->commit();
        return ['deposit_id' => $depId, 'state' => $state, 'replayed' => false];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function bc_cbs_settle_sell_deposit(mysqli $db, $vendorId, $depositId, $fiatMinor) {
    bc_cbs_assert_innodb($db);
    $vendorId = (int)$vendorId; $depositId = (int)$depositId; $fiatMinor = (int)$fiatMinor;
    if ($fiatMinor <= 0) throw new InvalidArgumentException('Invalid fiat credit.');
    $fiatFloat = (float)bc_cbs_minor_to_decimal($fiatMinor);

    $db->begin_transaction();
    try {
        $dq = $db->prepare('SELECT id, user_id, state, credit_claim_id, tx_hash, log_index FROM sas_crypto_chain_deposits WHERE id=? AND vendor_id=? LIMIT 1 FOR UPDATE');
        $dq->bind_param('ii', $depositId, $vendorId);
        $dq->execute();
        $dep = $dq->get_result()->fetch_assoc();
        $dq->close();
        if (!$dep) throw new RuntimeException('Deposit not found.');
        if ($dep['state'] === 'credited') {
            $db->commit();
            return ['deposit_id' => $depositId, 'state' => 'credited', 'replayed' => true];
        }
        if ($dep['state'] !== 'matched_final') {
            throw new RuntimeException('Deposit not eligible for automatic settlement: ' . $dep['state']);
        }

        $userId = (int)$dep['user_id'];
        $uq = $db->prepare('SELECT id, is_blocked FROM sas_users WHERE id=? AND vendor_id=? LIMIT 1 FOR UPDATE');
        $uq->bind_param('ii', $userId, $vendorId);
        $uq->execute();
        $u = $uq->get_result()->fetch_assoc();
        $uq->close();
        if (!$u || (int)$u['is_blocked'] === 1) throw new RuntimeException('User account blocked or unavailable.');

        $claimRef = 'cbs_sell_' . $vendorId . '_' . $dep['id'] . '_' . $dep['log_index'];
        $kind = 'crypto_sell_credit';
        $cm = $db->prepare('INSERT INTO sas_wallet_mutation_events(vendor_id, user_id, reference, mutation_kind, amount) VALUES(?,?,?,?,?)');
        $cm->bind_param('iissd', $vendorId, $userId, $claimRef, $kind, $fiatFloat);
        $cm->execute();
        $claimId = (int)$cm->insert_id;
        $cm->close();

        $cr = $db->prepare('UPDATE sas_users SET balance = balance + ? WHERE id=? AND vendor_id=?');
        $cr->bind_param('dii', $fiatFloat, $userId, $vendorId);
        $cr->execute();
        $cr->close();

        $up = $db->prepare("UPDATE sas_crypto_chain_deposits SET state='credited', credit_claim_id=? WHERE id=? AND state='matched_final'");
        $up->bind_param('ii', $claimId, $depositId);
        $up->execute();
        $up->close();

        $db->commit();
        return ['deposit_id' => $depositId, 'state' => 'credited', 'replayed' => false];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
