<?php
/**
 * Multi-party audited webhook reconciliation & evidence matching.
 * Never resets webhook_retry_claims, alters webhook_outbox.payload, or mutates balances.
 */
require_once __DIR__ . '/webhook-review.php';

function payhub_reconcile_norm_amount($value) {
    if (is_bool($value) || !is_scalar($value)) return null;
    $raw = trim((string)$value);
    if ($raw === '' || $raw[0] === '-' || $raw[0] === '+') return null;
    if (strpos($raw, '.') !== false) $raw = rtrim(rtrim($raw, '0'), '.');
    if (!preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/D', $raw)) return null;
    $parts = explode('.', $raw, 2);
    $whole = ltrim($parts[0], '0');
    if ($whole === '') $whole = '0';
    $frac = str_pad($parts[1] ?? '', 2, '0', STR_PAD_RIGHT);
    if ($whole === '0' && $frac === '00') return null;
    return $whole . '.' . $frac;
}

function payhub_reconcile_input(array $input) {
    $expectedKeys = [
        'request_id', 'preparer_ref', 'approver_ref', 'evidence_ref',
        'evidence_sha256', 'observation_sha256',
        'merchant_id', 'reference', 'currency', 'amount', 'account_number', 'disposition',
    ];
    if (array_keys($input) !== $expectedKeys) throw new InvalidArgumentException('Invalid reconciliation fields.');
    foreach (['request_id' => 64, 'preparer_ref' => 80, 'approver_ref' => 80, 'evidence_ref' => 120] as $field => $max) {
        if (!is_string($input[$field]) || strlen($input[$field]) < 8 || strlen($input[$field]) > $max
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]*\z/D', $input[$field])) {
            throw new InvalidArgumentException('Invalid opaque reference.');
        }
    }
    if (hash_equals($input['preparer_ref'], $input['approver_ref'])) {
        throw new InvalidArgumentException('Preparer and approver must be distinct operators.');
    }
    foreach (['evidence_sha256', 'observation_sha256'] as $field) {
        if (!is_string($input[$field]) || !preg_match('/\A[a-f0-9]{64}\z/D', $input[$field])) {
            throw new InvalidArgumentException('Invalid digest.');
        }
    }
    if (!is_int($input['merchant_id']) || $input['merchant_id'] < 1 || $input['merchant_id'] > 2147483647) {
        throw new InvalidArgumentException('Invalid merchant identifier.');
    }
    if (!is_string($input['reference']) || $input['reference'] === '' || strlen($input['reference']) > 120
        || !preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $input['reference'])) {
        throw new InvalidArgumentException('Invalid transaction reference.');
    }
    if (!is_string($input['currency']) || !preg_match('/\A[A-Z]{3}\z/D', $input['currency'])) {
        throw new InvalidArgumentException('Invalid currency code.');
    }
    if (payhub_reconcile_norm_amount($input['amount']) === null) {
        throw new InvalidArgumentException('Invalid amount.');
    }
    if (!is_string($input['account_number']) || ($input['account_number'] !== '' && !preg_match('/\A[0-9]{10}\z/D', $input['account_number']))) {
        throw new InvalidArgumentException('Invalid account number.');
    }
    if (!in_array($input['disposition'], ['confirmed_settled', 'mismatch_held', 'manual_credit_required', 'no_action_required'], true)) {
        throw new InvalidArgumentException('Invalid reconciliation disposition.');
    }
}

function payhub_reconcile_evidence_matches(array $tx, array $input) {
    $txMerchant = (int)($tx['user_id'] ?? 0);
    $txRef      = (string)($tx['reference'] ?? '');
    $txCur      = strtoupper(trim((string)($tx['currency'] ?? 'NGN')));
    $txAmt      = payhub_reconcile_norm_amount($tx['amount'] ?? null);
    $inAmt      = payhub_reconcile_norm_amount($input['amount'] ?? null);
    $txAcct     = trim((string)($tx['receiving_account_number'] ?? ''));
    $inAcct     = trim((string)($input['account_number'] ?? ''));

    if ($txMerchant !== (int)$input['merchant_id']) return false;
    if (!hash_equals($txRef, (string)$input['reference'])) return false;
    if (!hash_equals($txCur, (string)$input['currency'])) return false;
    if ($txAmt === null || $inAmt === null || !hash_equals($txAmt, $inAmt)) return false;
    if ($txAcct !== '' && !hash_equals($txAcct, $inAcct)) return false;
    if ($txAcct === '' && $inAcct !== '') return false;
    return true;
}

function payhub_record_webhook_reconciliation(PDO $db, $id, array $input) {
    payhub_review_id($id);
    payhub_reconcile_input($input);
    if ($db->inTransaction()) throw new RuntimeException('Independent reconciliation connection required.');

    $tables = ['transactions', 'users', 'webhook_outbox', 'webhook_retry_claims', 'webhook_logs', 'webhook_reconciliation_resolutions'];
    $engines = $db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('transactions','users','webhook_outbox','webhook_retry_claims','webhook_logs','webhook_reconciliation_resolutions')")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($tables as $t) {
        if (strtolower((string)($engines[$t] ?? '')) !== 'innodb') {
            throw new RuntimeException('Transactional reconciliation schema unavailable.');
        }
    }

    $normAmount = payhub_reconcile_norm_amount($input['amount']);
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT transaction_id, request_id, preparer_ref, approver_ref, evidence_ref, evidence_sha256, observation_sha256, matched_merchant_id, matched_reference, matched_currency, matched_amount, matched_account_number, disposition FROM webhook_reconciliation_resolutions WHERE request_id=?');
        $q->execute([$input['request_id']]);
        $existing = $q->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $same = (int)$existing['transaction_id'] === $id
                && hash_equals((string)$existing['preparer_ref'], $input['preparer_ref'])
                && hash_equals((string)$existing['approver_ref'], $input['approver_ref'])
                && hash_equals((string)$existing['evidence_ref'], $input['evidence_ref'])
                && hash_equals((string)$existing['evidence_sha256'], $input['evidence_sha256'])
                && hash_equals((string)$existing['observation_sha256'], $input['observation_sha256'])
                && (int)$existing['matched_merchant_id'] === $input['merchant_id']
                && hash_equals((string)$existing['matched_reference'], $input['reference'])
                && hash_equals((string)$existing['matched_currency'], $input['currency'])
                && payhub_reconcile_norm_amount($existing['matched_amount']) === $normAmount
                && hash_equals((string)$existing['matched_account_number'], $input['account_number'])
                && hash_equals((string)$existing['disposition'], $input['disposition']);
            if (!$same) throw new RuntimeException('Reconciliation request conflict.');
            $db->rollBack();
            return 'already_recorded';
        }

        $snapshot = payhub_review_snapshot($db, $id);
        if (!hash_equals($snapshot['observation_sha256'], $input['observation_sha256'])) {
            throw new RuntimeException('Observation changed; inspect again.');
        }

        $tq = $db->prepare('SELECT id, user_id, reference, currency, amount, receiving_account_number FROM transactions WHERE id=? LIMIT 1 FOR UPDATE');
        $tq->execute([$id]);
        $tx = $tq->fetch(PDO::FETCH_ASSOC);
        if (!$tx) throw new RuntimeException('Transaction unavailable.');

        $matched = payhub_reconcile_evidence_matches($tx, $input);
        if (!$matched && $input['disposition'] !== 'mismatch_held') {
            throw new RuntimeException('Evidence mismatch; only mismatch_held disposition is permitted.');
        }

        $ins = $db->prepare('INSERT INTO webhook_reconciliation_resolutions(transaction_id, request_id, preparer_ref, approver_ref, evidence_ref, evidence_sha256, observation_sha256, matched_merchant_id, matched_reference, matched_currency, matched_amount, matched_account_number, disposition) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([
            $id, $input['request_id'], $input['preparer_ref'], $input['approver_ref'],
            $input['evidence_ref'], $input['evidence_sha256'], $input['observation_sha256'],
            $input['merchant_id'], $input['reference'], $input['currency'],
            $normAmount, $input['account_number'], $input['disposition'],
        ]);
        $db->commit();
        return 'recorded';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
