<?php
/**
 * Internal durable payout reservation & reconciliation foundation (HELD).
 * No HTTP route, no runtime DDL, no provider cURL calls, and no hold release.
 */
if (!function_exists('bc_payout_sql')) {
    function bc_payout_sql($sql, array $params = []) {
        global $connection_server;
        $stmt = $connection_server->prepare($sql);
        if (!$stmt) throw new RuntimeException('unavailable');
        try {
            if ($params) {
                $types = '';
                foreach ($params as $p) $types .= is_int($p) ? 'i' : 's';
                $stmt->bind_param($types, ...$params);
            }
            if (!$stmt->execute()) throw new RuntimeException('unavailable');
            $res = $stmt->get_result();
            return $res ? $res->fetch_all(MYSQLI_ASSOC) : $stmt->affected_rows;
        } finally {
            $stmt->close();
        }
    }
}

if (!function_exists('bc_payout_money_parse')) {
    function bc_payout_money_parse($value, $allow_zero = false) {
        if (is_bool($value) || !is_scalar($value)) return null;
        if (is_float($value)) {
            if (!is_finite($value) || abs($value - round($value, 2)) > 1e-9) return null;
            $value = sprintf('%.2F', $value);
        } elseif (is_int($value)) {
            $value = (string)$value;
        } else {
            $value = trim((string)$value);
        }
        if (!preg_match('/\A([0-9]{1,9})(?:\.([0-9]{1,2}))?\z/D', $value, $m)) return null;
        $whole_str = ltrim($m[1], '0');
        $whole = $whole_str === '' ? 0 : (int)$whole_str;
        $frac  = (int)str_pad($m[2] ?? '0', 2, '0', STR_PAD_RIGHT);
        if (!$allow_zero && $whole === 0 && $frac === 0) return null;
        return ['whole' => $whole, 'frac' => $frac, 'decimal' => sprintf('%d.%02d', $whole, $frac)];
    }
}

if (!function_exists('bc_payout_money_add')) {
    function bc_payout_money_add(array $a, array $b) {
        $frac_sum = $a['frac'] + $b['frac'];
        $carry    = intdiv($frac_sum, 100);
        $frac     = $frac_sum % 100;
        $whole    = $a['whole'] + $b['whole'] + $carry;
        return ['whole' => $whole, 'frac' => $frac, 'decimal' => sprintf('%d.%02d', $whole, $frac)];
    }
}

if (!function_exists('bc_payout_begin')) {
    function bc_payout_begin() {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) throw new RuntimeException('unavailable');
        $auto = bc_payout_sql('SELECT @@session.autocommit AS enabled');
        if ((int)($auto[0]['enabled'] ?? 0) !== 1) throw new RuntimeException('outer_transaction');
        try {
            $iso = $connection_server->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        } catch (Throwable $e) {
            throw new RuntimeException('outer_transaction');
        }
        if (!$iso) throw new RuntimeException('outer_transaction');
        $required = ['sas_users', 'sas_transactions', 'sas_wallet_mutation_events', 'sas_payout_reservations', 'sas_payout_reservation_events'];
        $engines = bc_payout_sql(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('sas_users','sas_transactions','sas_wallet_mutation_events','sas_payout_reservations','sas_payout_reservation_events')"
        );
        if (count($engines) !== count($required)) throw new RuntimeException('schema_unavailable');
        foreach ($engines as $e) {
            if (strtoupper((string)($e['ENGINE'] ?? '')) !== 'INNODB') throw new RuntimeException('nontransactional');
        }
        if (!$connection_server->begin_transaction()) throw new RuntimeException('unavailable');
    }
}

if (!function_exists('bc_payout_reserve')) {
    /**
     * Atomically reserve funds for a payout in state='held'.
     * Replays of the same request_key + identical details recover the existing reservation without double-debiting.
     */
    function bc_payout_reserve($vendor_id, $user_id, $provider, $bank_code, $account_number, $amount, $fee, $narration, $request_key) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($user_id) || $user_id <= 0) return ['ok' => false, 'code' => 'invalid'];
        if (!is_string($provider) || !preg_match('/\A[a-z0-9_-]{2,40}\z/D', $provider)) return ['ok' => false, 'code' => 'invalid'];
        if (!is_string($bank_code) || !preg_match('/\A[A-Za-z0-9_-]{2,32}\z/D', $bank_code)) return ['ok' => false, 'code' => 'invalid'];
        if (!is_string($account_number) || !preg_match('/\A[0-9]{6,20}\z/D', $account_number)) return ['ok' => false, 'code' => 'invalid'];
        if (!is_string($request_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $request_key)) return ['ok' => false, 'code' => 'invalid'];
        if (!is_string($narration)) return ['ok' => false, 'code' => 'invalid'];
        $narration = trim($narration);
        if (strlen($narration) > 140) return ['ok' => false, 'code' => 'invalid'];

        $amt = bc_payout_money_parse($amount, false);
        $feep = bc_payout_money_parse($fee, true);
        if (!$amt || !$feep) return ['ok' => false, 'code' => 'invalid'];
        $total = bc_payout_money_add($amt, $feep);
        $total_str = $total['decimal'];

        $request_key_hash = hash('sha256', $request_key);
        $payload_hash = hash('sha256', json_encode([
            $vendor_id, $user_id, $provider, $bank_code, $account_number,
            $amt['decimal'], $feep['decimal'], $total_str, $narration,
        ], JSON_THROW_ON_ERROR));

        try {
            bc_payout_begin();
            $begun = true;

            $user = bc_payout_sql(
                'SELECT id, username, balance, status, COALESCE(is_blocked, 0) AS is_blocked, COALESCE(force_password_change, 0) AS force_password_change FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE',
                [$vendor_id, $user_id]
            )[0] ?? null;
            if (!$user) throw new RuntimeException('not_found');
            if ((int)$user['status'] !== 1 || (int)$user['is_blocked'] !== 0 || (int)$user['force_password_change'] !== 0) {
                throw new RuntimeException('restricted');
            }

            $old = bc_payout_sql(
                'SELECT id, payload_hash, reference, provider_key, state, version, total_debit FROM sas_payout_reservations WHERE vendor_id=? AND user_id=? AND request_key_hash=? LIMIT 1 FOR UPDATE',
                [$vendor_id, $user_id, $request_key_hash]
            )[0] ?? null;
            if ($old) {
                $connection_server->rollback();
                $begun = false;
                if (!hash_equals((string)$old['payload_hash'], $payload_hash)) {
                    return ['ok' => false, 'code' => 'conflict'];
                }
                return [
                    'ok'           => true,
                    'code'         => 'replayed',
                    'replayed'     => true,
                    'reference'    => (string)$old['reference'],
                    'provider_key' => (string)$old['provider_key'],
                    'state'        => (string)$old['state'],
                    'version'      => (int)$old['version'],
                    'total_debit'  => (string)$old['total_debit'],
                ];
            }

            $before = (string)$user['balance'];
            $affected = bc_payout_sql(
                'UPDATE sas_users SET balance = balance - ? WHERE vendor_id=? AND id=? AND balance >= ?',
                [$total_str, $vendor_id, $user_id, $total_str]
            );
            if ($affected !== 1) throw new RuntimeException('insufficient_balance');

            $fresh = bc_payout_sql('SELECT balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1', [$vendor_id, $user_id])[0] ?? null;
            if (!$fresh) throw new RuntimeException('unconfirmed');
            $after = (string)$fresh['balance'];

            $username = strtolower(trim((string)$user['username']));
            if ($username === '') throw new RuntimeException('restricted');
            $reference = 'PO-' . strtoupper(bin2hex(random_bytes(12)));
            $provider_key = bin2hex(random_bytes(32));
            $account_last4 = substr($account_number, -4);
            $account_hash = hash('sha256', $vendor_id . ':' . $bank_code . ':' . $account_number);
            $actor = 'user:' . $username;
            $event_key = hash('sha256', json_encode([$vendor_id, $actor, 'debit', 'reference:' . $reference], JSON_THROW_ON_ERROR));
            $event_payload_hash = hash('sha256', json_encode([$vendor_id, $actor, 'debit', $total_str, 'reference:' . $reference], JSON_THROW_ON_ERROR));

            bc_payout_sql(
                'INSERT INTO sas_wallet_mutation_events(event_key, payload_hash, vendor_id, actor, direction, reference, amount) VALUES(?,?,?,?,?,?,?)',
                [$event_key, $event_payload_hash, $vendor_id, $actor, 'debit', $reference, $total_str]
            );

            $desc = 'Bank payout reservation ****' . $account_last4 . ($narration !== '' ? ' - ' . $narration : '');
            bc_payout_sql(
                'INSERT INTO sas_transactions(vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status, username, api_reference, mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$vendor_id, '****' . $account_last4, 'Bank Transfer', $reference, $amt['decimal'], $total_str, $before, $after, $desc, '', 2, $username, $provider_key, 'RESERVATION']
            );

            bc_payout_sql(
                'INSERT INTO sas_payout_reservations(vendor_id, user_id, username, request_key_hash, payload_hash, reference, provider, provider_key, bank_code, account_last4, account_hash, amount, fee, total_debit, state, version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)',
                [$vendor_id, $user_id, $username, $request_key_hash, $payload_hash, $reference, $provider, $provider_key, $bank_code, $account_last4, $account_hash, $amt['decimal'], $feep['decimal'], $total_str, 'held']
            );

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            return [
                'ok'           => true,
                'code'         => 'reserved',
                'replayed'     => false,
                'reference'    => $reference,
                'provider_key' => $provider_key,
                'state'        => 'held',
                'version'      => 0,
                'total_debit'  => $total_str,
            ];
        } catch (Throwable $e) {
            if ($begun) {
                try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            }
            $known = ['not_found', 'restricted', 'conflict', 'insufficient_balance', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_payout_apply_refund')) {
    /** Internal helper: executes inside an open bc_payout_begin() transaction only. */
    function bc_payout_apply_refund(array $res, $reason_label) {
        $vendor_id = (int)$res['vendor_id'];
        $user_id   = (int)$res['user_id'];
        $reference = (string)$res['reference'];
        $total_str = (string)$res['total_debit'];
        $username  = strtolower(trim((string)$res['username']));
        $actor     = 'user:' . $username;

        $user = bc_payout_sql(
            'SELECT id, balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE',
            [$vendor_id, $user_id]
        )[0] ?? null;
        if (!$user) throw new RuntimeException('not_found');

        $debit_key = hash('sha256', json_encode([$vendor_id, $actor, 'debit', 'reference:' . $reference], JSON_THROW_ON_ERROR));
        $debit_ev = bc_payout_sql('SELECT amount FROM sas_wallet_mutation_events WHERE event_key=? LIMIT 1 FOR UPDATE', [$debit_key])[0] ?? null;
        $debit_parsed = $debit_ev ? bc_payout_money_parse((string)$debit_ev['amount'], false) : null;
        $total_parsed = bc_payout_money_parse($total_str, false);
        if (!$debit_parsed || !$total_parsed || !hash_equals($debit_parsed['decimal'], $total_parsed['decimal'])) {
            throw new RuntimeException('refund_has_no_matching_debit');
        }

        $refund_key = hash('sha256', json_encode([$vendor_id, $actor, 'credit', 'refund:' . $reference], JSON_THROW_ON_ERROR));
        $refund_hash = hash('sha256', json_encode([$vendor_id, $actor, 'credit', $total_str, $reference], JSON_THROW_ON_ERROR));

        $before = (string)$user['balance'];
        $affected = bc_payout_sql(
            'UPDATE sas_users SET balance = balance + ? WHERE vendor_id=? AND id=?',
            [$total_str, $vendor_id, $user_id]
        );
        if ($affected !== 1) throw new RuntimeException('unconfirmed');
        $fresh = bc_payout_sql('SELECT balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1', [$vendor_id, $user_id])[0] ?? null;
        if (!$fresh) throw new RuntimeException('unconfirmed');
        $after = (string)$fresh['balance'];

        bc_payout_sql(
            'INSERT INTO sas_wallet_mutation_events(event_key, payload_hash, vendor_id, actor, direction, reference, amount) VALUES(?,?,?,?,?,?,?)',
            [$refund_key, $refund_hash, $vendor_id, $actor, 'credit', $reference . '-RF', $total_str]
        );

        bc_payout_sql(
            'UPDATE sas_transactions SET status=3 WHERE vendor_id=? AND reference=? AND status=2',
            [$vendor_id, $reference]
        );
        bc_payout_sql(
            'INSERT INTO sas_transactions(vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status, username, api_reference, mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$vendor_id, $username, 'Refund', $reference . '-RF', $total_str, $total_str, $before, $after, $reason_label . " Ref:'" . $reference . "'", '', 1, $username, '', 'RESERVATION']
        );
    }
}

if (!function_exists('bc_payout_outcome_transition')) {
    function bc_payout_outcome_transition($state, $outcome) {
        if (!in_array($outcome, ['unknown', 'confirmed_success', 'confirmed_failure'], true)) return ['code' => 'invalid', 'state' => $state];
        if ($state === 'held') return ['code' => 'held', 'state' => $state];
        if (in_array($state, ['confirmed_success', 'confirmed_failure', 'cancelled'], true)) {
            return ['code' => $state === $outcome ? 'already_final' : 'conflict', 'state' => $state];
        }
        if (!in_array($state, ['dispatched', 'unknown'], true)) return ['code' => 'invalid', 'state' => $state];
        return ['code' => 'recorded', 'state' => $outcome];
    }
}

if (!function_exists('bc_payout_observe_outcome')) {
    /**
     * Record a verified provider outcome against a dispatched/unknown payout reservation.
     * Unknown outcomes remain unknown without refunding or retrying.
     */
    function bc_payout_observe_outcome($vendor_id, $reference, $event_key, $outcome, $evidence_hash, $provider_reference, $expected_version) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($expected_version) || $expected_version < 0
            || !is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{4,64}\z/D', $reference)
            || !is_string($event_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $event_key)
            || !is_string($evidence_hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $evidence_hash)
            || !is_string($provider_reference) || strlen($provider_reference) > 160
            || ($provider_reference !== '' && !preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $provider_reference))
            || !in_array($outcome, ['unknown', 'confirmed_success', 'confirmed_failure'], true)) {
            return ['ok' => false, 'code' => 'invalid'];
        }

        $digest = hash('sha256', json_encode([$outcome, $evidence_hash, $provider_reference, $expected_version], JSON_THROW_ON_ERROR));

        try {
            bc_payout_begin();
            $begun = true;

            $res = bc_payout_sql(
                'SELECT * FROM sas_payout_reservations WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE',
                [$vendor_id, $reference]
            )[0] ?? null;
            if (!$res) throw new RuntimeException('not_found');
            $res_id = (int)$res['id'];

            $ev = bc_payout_sql(
                'SELECT * FROM sas_payout_reservation_events WHERE vendor_id=? AND reservation_id=? AND event_key=? LIMIT 1 FOR UPDATE',
                [$vendor_id, $res_id, $event_key]
            )[0] ?? null;
            if ($ev) {
                if (!hash_equals((string)$ev['observation_hash'], $digest)) throw new RuntimeException('conflict');
                $out = [
                    'code'     => (string)$ev['disposition'],
                    'state'    => (string)$ev['result_state'],
                    'version'  => (int)$ev['result_version'],
                    'replayed' => true,
                ];
            } else {
                $out = bc_payout_outcome_transition((string)$res['state'], $outcome);
                $version = (int)$res['version'];
                if ($expected_version !== $version && $out['code'] === 'recorded') {
                    $out = ['code' => 'stale', 'state' => (string)$res['state']];
                }
                if ($res['provider_reference'] !== null && $provider_reference !== '' && !hash_equals((string)$res['provider_reference'], $provider_reference)) {
                    $out = ['code' => 'conflict', 'state' => (string)$res['state']];
                }
                $prov_ref = $res['provider_reference'];
                if ($out['code'] === 'recorded') {
                    if ($prov_ref === null && $provider_reference !== '') $prov_ref = $provider_reference;
                    $version++;
                    bc_payout_sql(
                        'UPDATE sas_payout_reservations SET state=?, provider_reference=?, version=?, updated_at=NOW() WHERE vendor_id=? AND id=?',
                        [$out['state'], $prov_ref, $version, $vendor_id, $res_id]
                    );
                    if ($outcome === 'confirmed_success') {
                        bc_payout_sql('UPDATE sas_transactions SET status=1 WHERE vendor_id=? AND reference=? AND status=2', [$vendor_id, $reference]);
                    } elseif ($outcome === 'confirmed_failure') {
                        bc_payout_apply_refund($res, 'Payout failed');
                    }
                }
                bc_payout_sql(
                    'INSERT INTO sas_payout_reservation_events(vendor_id, reservation_id, event_key, observation_hash, evidence_hash, observed_outcome, observed_reference, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?,?,?,?)',
                    [$vendor_id, $res_id, $event_key, $digest, $evidence_hash, $outcome, $provider_reference, $out['code'], $out['state'], $version]
                );
                $out['version'] = $version;
                $out['replayed'] = false;
            }

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            $out['ok'] = in_array($out['code'], ['recorded', 'already_final'], true);
            return $out;
        } catch (Throwable $e) {
            if ($begun) {
                try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            }
            $known = ['not_found', 'conflict', 'refund_has_no_matching_debit', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_payout_cancel_held')) {
    /**
     * Explicitly cancel a pre-dispatch (state='held') reservation and refund ONCE.
     * Refuses dispatched/unknown/terminal reservations so an uncertain provider transfer is never auto-refunded.
     */
    function bc_payout_cancel_held($vendor_id, $reference, $event_key, $reason_hash, $expected_version) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($expected_version) || $expected_version < 0
            || !is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{4,64}\z/D', $reference)
            || !is_string($event_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $event_key)
            || !is_string($reason_hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $reason_hash)) {
            return ['ok' => false, 'code' => 'invalid'];
        }

        $digest = hash('sha256', json_encode(['cancelled', $reason_hash, '', $expected_version], JSON_THROW_ON_ERROR));

        try {
            bc_payout_begin();
            $begun = true;

            $res = bc_payout_sql(
                'SELECT * FROM sas_payout_reservations WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE',
                [$vendor_id, $reference]
            )[0] ?? null;
            if (!$res) throw new RuntimeException('not_found');
            $res_id = (int)$res['id'];

            $ev = bc_payout_sql(
                'SELECT * FROM sas_payout_reservation_events WHERE vendor_id=? AND reservation_id=? AND event_key=? LIMIT 1 FOR UPDATE',
                [$vendor_id, $res_id, $event_key]
            )[0] ?? null;
            if ($ev) {
                if (!hash_equals((string)$ev['observation_hash'], $digest)) throw new RuntimeException('conflict');
                $out = [
                    'code'     => (string)$ev['disposition'],
                    'state'    => (string)$ev['result_state'],
                    'version'  => (int)$ev['result_version'],
                    'replayed' => true,
                ];
            } else {
                $version = (int)$res['version'];
                if ($res['state'] === 'cancelled') {
                    $out = ['code' => 'already_final', 'state' => 'cancelled'];
                } elseif ($res['state'] !== 'held') {
                    $out = ['code' => 'not_held', 'state' => (string)$res['state']];
                } elseif ($expected_version !== $version) {
                    $out = ['code' => 'stale', 'state' => (string)$res['state']];
                } else {
                    $version++;
                    bc_payout_sql(
                        'UPDATE sas_payout_reservations SET state=?, version=?, updated_at=NOW() WHERE vendor_id=? AND id=?',
                        ['cancelled', $version, $vendor_id, $res_id]
                    );
                    bc_payout_apply_refund($res, 'Held payout cancelled');
                    $out = ['code' => 'cancelled', 'state' => 'cancelled'];
                }
                bc_payout_sql(
                    'INSERT INTO sas_payout_reservation_events(vendor_id, reservation_id, event_key, observation_hash, evidence_hash, observed_outcome, observed_reference, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?,?,?,?)',
                    [$vendor_id, $res_id, $event_key, $digest, $reason_hash, 'cancelled', '', $out['code'], $out['state'], $version]
                );
                $out['version'] = $version;
                $out['replayed'] = false;
            }

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            $out['ok'] = in_array($out['code'], ['cancelled', 'already_final'], true);
            return $out;
        } catch (Throwable $e) {
            if ($begun) {
                try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            }
            $known = ['not_found', 'conflict', 'refund_has_no_matching_debit', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}
