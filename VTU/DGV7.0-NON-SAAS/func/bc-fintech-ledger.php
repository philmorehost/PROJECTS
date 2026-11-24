<?php
/**
 * DGV7 phase 3 — the ONE atomic ledger primitive for provider money movement.
 *
 * WHY THIS EXISTS
 *   Provider adapters used to credit balances themselves, each in its own way
 *   (chargeUser() for NGN, updateUserCryptoBalance() for stablecoins). That is
 *   how a retried webhook becomes two credits. This file is the only writer for
 *   provider money movement; adapters never run their own UPDATE.
 *
 * ROUTING (no third balance store)
 *   NGN        -> sas_users.balance                       (DECIMAL(65,30))
 *   USD/GHS/ZAR/USDC/USDT -> sas_user_crypto_wallets       (balance, ledger_balance)
 *
 * ATOMICITY
 *   One MySQL transaction per post: lock the balance row FOR UPDATE, apply the
 *   delta with sprintf('%.18F') (exponent notation is rejected by MySQL), read
 *   the fresh balance, write the audit row, commit. A failure at any step rolls
 *   back, so a refused post leaves NO balance change and NO audit row.
 *
 *   The NGN path mirrors the pattern of bc_atomic_debit_user()
 *   (func/bc-security.php) rather than calling it, because the audit row has to
 *   commit in the SAME transaction as the balance change. Calling a helper that
 *   commits internally would leave a committed debit with no audit row when the
 *   insert then failed.
 *
 * AUDIT
 *   Every post writes one sas_fintech_ledger row. NGN posts additionally mirror
 *   into sas_transactions so the credit appears in the customer's history, which
 *   is what chargeUser() does for every other NGN credit today.
 *
 * This file is side-effect free: including it defines functions only. The tables
 * are created by bc-fintech-schema.php.
 */

if (!function_exists('bc_fintech_ledger_currencies')) {
    /** Currencies this primitive may move. NGN is custodied by sas_users; the rest by crypto wallets. */
    function bc_fintech_ledger_currencies() {
        return ['NGN', 'USD', 'GHS', 'ZAR', 'USDC', 'USDT'];
    }
}

if (!function_exists('bc_fintech_ledger_is_naira')) {
    function bc_fintech_ledger_is_naira($currency) {
        return strtoupper(trim((string)$currency)) === 'NGN';
    }
}

if (!function_exists('bc_fintech_ledger_valid_amount')) {
    /**
     * Amount guard. Rejects non-numeric, non-finite, zero and NEGATIVE values:
     * the direction argument carries the sign, so a negative amount would reverse
     * it and mint money.
     */
    function bc_fintech_ledger_valid_amount($amount) {
        if ($amount === null || $amount === '' || is_bool($amount) || !is_numeric($amount)) return false;
        $value = (float)$amount;
        return is_finite($value) && $value > 0;
    }
}

if (!function_exists('bc_fintech_ledger_balance')) {
    /**
     * Current balance for one currency, or null when it cannot be read.
     * NGN reads sas_users.balance; everything else reads sas_user_crypto_wallets.
     */
    function bc_fintech_ledger_balance($vendor_id, $username, $currency) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $currency = strtoupper(trim((string)$currency));
        $username = (string)$username;
        if ($vendor_id <= 0 || $username === '' || $currency === '' || !($connection_server instanceof mysqli)) return null;

        if (bc_fintech_ledger_is_naira($currency)) {
            $q = mysqli_query($connection_server, "SELECT balance FROM sas_users
                WHERE vendor_id='$vendor_id' AND username='" . mysqli_real_escape_string($connection_server, $username) . "' LIMIT 1");
            if (!$q || mysqli_num_rows($q) !== 1) return null;
            return (float)mysqli_fetch_assoc($q)['balance'];
        }

        $curr = mysqli_real_escape_string($connection_server, $currency);
        $user = mysqli_real_escape_string($connection_server, $username);
        $q = mysqli_query($connection_server, "SELECT balance FROM sas_user_crypto_wallets
            WHERE vendor_id='$vendor_id' AND username='$user' AND currency_code='$curr' LIMIT 1");
        if (!$q || mysqli_num_rows($q) !== 1) return null;
        return (float)mysqli_fetch_assoc($q)['balance'];
    }
}

if (!function_exists('bc_fintech_ledger_post')) {
    /**
     * Apply one provider money movement.
     *
     * @param int    $vendor_id
     * @param string $username
     * @param string $currency  NGN, USD, GHS, ZAR, USDC, USDT
     * @param string $direction 'credit' | 'debit'
     * @param mixed  $amount    positive magnitude; never negative
     * @param string $reference unique provider reference, recorded in the audit row
     * @param array  $context   provider, event_id, fee, raw status — audit only
     *
     * @return array ['ok' => bool, 'reason' => string, 'currency', 'direction',
     *               'amount' => float, 'balance_before' => float|null,
     *               'balance_after' => float|null]
     */
    function bc_fintech_ledger_post($vendor_id, $username, $currency, $direction, $amount, $reference, array $context = []) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $username = (string)$username;
        $currency = strtoupper(trim((string)$currency));
        $direction = strtolower(trim((string)$direction));
        $reference = substr(trim((string)$reference), 0, 225);

        $result = [
            'ok' => false, 'reason' => '', 'currency' => $currency, 'direction' => $direction,
            'amount' => null, 'balance_before' => null, 'balance_after' => null,
        ];

        if (!($connection_server instanceof mysqli)) { $result['reason'] = 'db_unavailable'; return $result; }
        if (function_exists('bc_fintech_schema_ready') && !bc_fintech_schema_ready()) {
            $result['reason'] = 'schema_not_ready';
            return $result;
        }
        if ($vendor_id <= 0 || $username === '') { $result['reason'] = 'invalid_account'; return $result; }
        if (!in_array($currency, bc_fintech_ledger_currencies(), true)) { $result['reason'] = 'invalid_currency'; return $result; }
        if (!in_array($direction, ['credit', 'debit'], true)) { $result['reason'] = 'invalid_direction'; return $result; }
        if (!bc_fintech_ledger_valid_amount($amount)) { $result['reason'] = 'invalid_amount'; return $result; }
        if ($reference === '') { $result['reason'] = 'invalid_reference'; return $result; }

        $amount = (float)$amount;
        // sprintf never emits exponent notation, which MySQL rejects.
        $sql_amount = sprintf('%.18F', $amount);
        $operator  = $direction === 'credit' ? '+' : '-';
        $user_esc  = mysqli_real_escape_string($connection_server, $username);
        $curr_esc  = mysqli_real_escape_string($connection_server, $currency);
        $is_naira  = bc_fintech_ledger_is_naira($currency);

        // Fail closed on active caller transactions or missing/non-InnoDB storage.
        try {
            $auto_q = mysqli_query($connection_server, 'SELECT @@session.autocommit AS auto_commit');
            $auto_r = $auto_q ? mysqli_fetch_assoc($auto_q) : null;
            if (!$auto_r || (int)($auto_r['auto_commit'] ?? 0) !== 1 || mysqli_query($connection_server, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') !== true) {
                $result['reason'] = 'transaction_unavailable';
                return $result;
            }
        } catch (Throwable $e) {
            $result['reason'] = 'transaction_unavailable';
            return $result;
        }

        $required_tables = $is_naira
            ? ['sas_users', 'sas_transactions', 'sas_fintech_ledger', 'sas_provider_settlement_claims']
            : ['sas_user_crypto_wallets', 'sas_fintech_ledger', 'sas_provider_settlement_claims'];
        $table_list = "'" . implode("','", $required_tables) . "'";
        $eng_q = mysqli_query($connection_server, "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($table_list)");
        $engines = [];
        while ($eng_q && ($eng_r = mysqli_fetch_assoc($eng_q))) {
            $engines[$eng_r['TABLE_NAME']] = strtoupper((string)$eng_r['ENGINE']);
        }
        foreach ($required_tables as $req_table) {
            if (($engines[$req_table] ?? '') !== 'INNODB') {
                $result['reason'] = 'transactional_schema_required';
                return $result;
            }
        }

        $provider = mysqli_real_escape_string($connection_server, substr((string)($context['provider'] ?? ''), 0, 40));
        $ref_esc  = mysqli_real_escape_string($connection_server, $reference);
        $settlement_key = hash('sha256', json_encode(array($vendor_id,(string)($context['provider'] ?? ''),$direction,$reference)));
        $payload_hash = hash('sha256', json_encode(array($username,$currency,$direction,$sql_amount)));
        if (!mysqli_begin_transaction($connection_server)) { $result['reason']='transaction_failed'; return $result; }
        try {
            $claim = false;
            $claim_errno = 0;
            try {
                $claim = (bool)mysqli_query($connection_server, "INSERT INTO sas_provider_settlement_claims(settlement_key,payload_hash) VALUES('$settlement_key','$payload_hash')");
                if (!$claim) $claim_errno = (int)mysqli_errno($connection_server);
            } catch (Throwable $claim_ex) {
                $claim = false;
                $claim_errno = (int)$claim_ex->getCode();
                if ($claim_errno !== 1062) throw $claim_ex;
            }
            if (!$claim) {
                if ($claim_errno !== 1062) throw new Exception('settlement_claim_insert_failed');
                $old_q = mysqli_query($connection_server, "SELECT payload_hash FROM sas_provider_settlement_claims WHERE settlement_key='$settlement_key' LIMIT 1");
                $old = $old_q ? mysqli_fetch_assoc($old_q) : null;
                mysqli_rollback($connection_server);
                if ($old && hash_equals((string)$old['payload_hash'],$payload_hash)) { $result['ok']=true; $result['reason']='already_settled'; }
                else $result['reason']='settlement_claim_failed';
                return $result;
            }
            if ($is_naira) {
                $lock = mysqli_query($connection_server, "SELECT id, balance, status, is_blocked FROM sas_users
                    WHERE vendor_id='$vendor_id' AND username='$user_esc' LIMIT 1 FOR UPDATE");
                if (!$lock) throw new Exception('lock_failed');
                $row = mysqli_fetch_assoc($lock);
                if (!is_array($row)) { mysqli_rollback($connection_server); $result['reason'] = 'user_not_found'; return $result; }
                if ((int)($row['status'] ?? 0) !== 1) { mysqli_rollback($connection_server); $result['reason'] = 'account_inactive'; return $result; }
                if (!empty($row['is_blocked'])) { mysqli_rollback($connection_server); $result['reason'] = 'account_restricted'; return $result; }

                $hist_q = mysqli_query($connection_server, "SELECT reference FROM sas_transactions WHERE vendor_id='$vendor_id' AND username='$user_esc' AND status=1 AND (reference='$ref_esc' OR (api_reference<>'' AND api_reference='$ref_esc')) LIMIT 1");
                if (!$hist_q || mysqli_num_rows($hist_q) > 0) {
                    mysqli_rollback($connection_server);
                    $result['reason'] = 'historical_reference_requires_reconciliation';
                    return $result;
                }

                $before = (float)$row['balance'];
                if ($direction === 'debit' && $before < $amount) {
                    mysqli_rollback($connection_server);
                    $result['reason'] = 'insufficient_balance';
                    return $result;
                }
                $id_esc = (int)$row['id'];
                $debit_guard = $direction === 'debit' ? " AND balance >= $sql_amount" : '';
                $update = mysqli_query($connection_server, "UPDATE sas_users SET balance=balance$operator$sql_amount WHERE id='$id_esc'$debit_guard");
                if (!$update || mysqli_affected_rows($connection_server) !== 1) {
                    if ($direction === 'debit') {
                        mysqli_rollback($connection_server);
                        $result['reason'] = 'insufficient_balance';
                        return $result;
                    }
                    throw new Exception('update_failed');
                }
            } else {
                // Make sure the wallet row exists, then lock it. INSERT IGNORE is a no-op
                // when the UNIQUE (vendor_id, username, currency_code) row is already there.
                mysqli_query($connection_server, "INSERT IGNORE INTO sas_user_crypto_wallets (vendor_id, username, currency_code)
                    VALUES ('$vendor_id', '$user_esc', '$curr_esc')");
                $lock = mysqli_query($connection_server, "SELECT id, balance, ledger_balance FROM sas_user_crypto_wallets
                    WHERE vendor_id='$vendor_id' AND username='$user_esc' AND currency_code='$curr_esc' LIMIT 1 FOR UPDATE");
                if (!$lock) throw new Exception('lock_failed');
                $row = mysqli_fetch_assoc($lock);
                if (!is_array($row)) { mysqli_rollback($connection_server); $result['reason'] = 'wallet_not_found'; return $result; }

                $before = (float)$row['balance'];
                if ($direction === 'debit' && $before < $amount) {
                    mysqli_rollback($connection_server);
                    $result['reason'] = 'insufficient_balance';
                    return $result;
                }
                $id_esc = (int)$row['id'];
                $debit_guard = $direction === 'debit' ? " AND balance >= $sql_amount AND ledger_balance >= $sql_amount" : '';
                $update = mysqli_query($connection_server, "UPDATE sas_user_crypto_wallets
                    SET balance=balance$operator$sql_amount, ledger_balance=ledger_balance$operator$sql_amount
                    WHERE id='$id_esc'$debit_guard");
                if (!$update || mysqli_affected_rows($connection_server) !== 1) {
                    if ($direction === 'debit') {
                        mysqli_rollback($connection_server);
                        $result['reason'] = 'insufficient_balance';
                        return $result;
                    }
                    throw new Exception('update_failed');
                }
            }

            $ledger_hist_q = mysqli_query($connection_server, "SELECT id FROM sas_fintech_ledger WHERE vendor_id='$vendor_id' AND provider='$provider' AND direction='$direction' AND reference='$ref_esc' LIMIT 1");
            if (!$ledger_hist_q || mysqli_num_rows($ledger_hist_q) > 0) {
                mysqli_rollback($connection_server);
                $result['reason'] = 'historical_reference_requires_reconciliation';
                return $result;
            }

            // Fresh balance for the audit row.
            if ($is_naira) {
                $re = mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE id='$id_esc' LIMIT 1");
            } else {
                $re = mysqli_query($connection_server, "SELECT balance FROM sas_user_crypto_wallets WHERE id='$id_esc' LIMIT 1");
            }
            $after = ($re && ($r = mysqli_fetch_assoc($re))) ? (float)$r['balance'] : null;

            // ── audit row ────────────────────────────────────────────────
            $event_id = mysqli_real_escape_string($connection_server, substr((string)($context['event_id'] ?? ''), 0, 225));
            $audit_context = mysqli_real_escape_string($connection_server, json_encode(array_intersect_key($context, array_flip([
                'event_id', 'fee', 'provider_status', 'channel',
            ]))));
            $before_sql = sprintf('%.18F', (float)$before);
            $after_sql  = sprintf('%.18F', (float)$after);
            $audit = mysqli_query($connection_server, "INSERT INTO sas_fintech_ledger
                (vendor_id, username, currency_code, direction, amount, balance_before, balance_after, provider, event_id, reference, context, created_at)
                VALUES ('$vendor_id', '$user_esc', '$curr_esc', '$direction', '$sql_amount', '$before_sql', '$after_sql', '$provider', '$event_id', '$ref_esc', '$audit_context', NOW())");
            if (!$audit) throw new Exception('audit_failed');

            // ── NGN history mirror ───────────────────────────────────────
            // sas_users.balance is the NGN ledger, but the customer-facing history is
            // sas_transactions. Without this row a provider deposit would change the
            // balance and never appear in the statement.
            if ($is_naira) {
                $description = mysqli_real_escape_string($connection_server, 'Provider deposit (' . (string)($context['provider'] ?? 'provider') . ')');
                $mirror = mysqli_query($connection_server, "INSERT INTO sas_transactions
                    (vendor_id, product_unique_id, type_alternative, reference, api_reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status)
                    VALUES ('$vendor_id', '$provider', 'Wallet', '$ref_esc', '$event_id', '$user_esc', '$sql_amount', '$sql_amount', '$before_sql', '$after_sql', '$description', 'API', 'provider', 1)");
                if (!$mirror) throw new Exception('history_mirror_failed');
            }

            if (!mysqli_commit($connection_server)) throw new Exception('commit_failed');
            $result['ok'] = true;
            $result['reason'] = 'ok';
            $result['amount'] = $amount;
            $result['balance_before'] = (float)$before;
            $result['balance_after'] = $after;
            return $result;
        } catch (Throwable $e) {
            try { mysqli_rollback($connection_server); } catch (Throwable $ignored) {}
            error_log('[DGV-FINTECH] ledger post failed: ' . $e->getMessage() . " vendor=$vendor_id user=$username currency=$currency direction=$direction");
            $result['reason'] = 'failed';
            return $result;
        }
    }
}
