<?php
/**
 * Held durable virtual-card reservation & reconciliation foundation.
 * Internal library ONLY: no HTTP routes, no runtime DDL, no provider network calls.
 * Preserves CARD_SECURITY_HOLD and security_review_required until operator migration,
 * historical card reconciliation, PCI-safe masked-PAN enforcement, and provider acceptance.
 */

if (!function_exists('bc_vc_sql')) {
    function bc_vc_sql($sql, $params = []) {
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

if (!function_exists('bc_vc_begin')) {
    function bc_vc_begin() {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) throw new RuntimeException('unavailable');
        $auto = bc_vc_sql('SELECT @@session.autocommit AS enabled');
        if ((int)($auto[0]['enabled'] ?? 0) !== 1) throw new RuntimeException('outer_transaction');
        try {
            if (!$connection_server->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')) {
                throw new RuntimeException('outer_transaction');
            }
        } catch (Throwable $e) {
            throw new RuntimeException('outer_transaction');
        }
        $tables = ['sas_users', 'sas_transactions', 'sas_wallet_mutation_events', 'sas_virtual_cards_v2', 'sas_virtualcard_orders', 'sas_virtualcard_order_events'];
        $rows = bc_vc_sql("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('" . implode("','", $tables) . "')");
        $engines = [];
        foreach ($rows as $r) $engines[(string)$r['TABLE_NAME']] = strtoupper((string)$r['ENGINE']);
        foreach ($tables as $t) {
            if (!isset($engines[$t])) throw new RuntimeException('schema_unavailable');
            if ($engines[$t] !== 'INNODB') throw new RuntimeException('nontransactional');
        }
        if (!$connection_server->begin_transaction()) throw new RuntimeException('unavailable');
    }
}

if (!function_exists('bc_vc_uint_add')) {
    function bc_vc_uint_add($a, $b) {
        $a = ltrim((string)$a, '0'); $b = ltrim((string)$b, '0');
        if ($a === '') $a = '0'; if ($b === '') $b = '0';
        $i = strlen($a) - 1; $j = strlen($b) - 1; $carry = 0; $out = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $da = $i >= 0 ? (int)$a[$i--] : 0;
            $db = $j >= 0 ? (int)$b[$j--] : 0;
            $s = $da + $db + $carry;
            $out = ($s % 10) . $out;
            $carry = intdiv($s, 10);
        }
        return $out;
    }
}

if (!function_exists('bc_vc_uint_mul')) {
    function bc_vc_uint_mul($a, $b) {
        $a = ltrim((string)$a, '0'); $b = ltrim((string)$b, '0');
        if ($a === '' || $b === '') return '0';
        $la = strlen($a); $lb = strlen($b);
        $acc = array_fill(0, $la + $lb, 0);
        for ($i = $la - 1; $i >= 0; $i--) {
            $da = (int)$a[$i];
            for ($j = $lb - 1; $j >= 0; $j--) {
                $p = $da * (int)$b[$j] + $acc[$i + $j + 1];
                $acc[$i + $j + 1] = $p % 10;
                $acc[$i + $j] += intdiv($p, 10);
            }
        }
        $res = ltrim(implode('', $acc), '0');
        return $res === '' ? '0' : $res;
    }
}

if (!function_exists('bc_vc_decimal_parse')) {
    function bc_vc_decimal_parse($value, $scale, $allow_zero = false) {
        if (is_bool($value) || !is_scalar($value)) return null;
        $raw = trim((string)$value);
        if ($raw === '' || $raw[0] === '-' || $raw[0] === '+') return null;
        if (strpos($raw, '.') !== false) $raw = rtrim(rtrim($raw, '0'), '.');
        if (!preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,' . (int)$scale . '})?\z/D', $raw)) return null;
        $parts = explode('.', $raw, 2);
        $whole = ltrim($parts[0], '0');
        if ($whole === '') $whole = '0';
        $frac = str_pad($parts[1] ?? '', $scale, '0', STR_PAD_RIGHT);
        $digits = ltrim($whole . $frac, '0');
        if ($digits === '') $digits = '0';
        if (!$allow_zero && $digits === '0') return null;
        return [
            'formatted' => $whole . ($scale > 0 ? '.' . $frac : ''),
            'digits'    => $digits,
        ];
    }
}

if (!function_exists('bc_vc_fmt_cents')) {
    function bc_vc_fmt_cents($digits) {
        $digits = ltrim((string)$digits, '0');
        if ($digits === '') $digits = '0';
        $padded = str_pad($digits, 3, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, strlen($padded) - 2);
        $frac  = substr($padded, -2);
        return $whole . '.' . $frac;
    }
}

if (!function_exists('bc_vc_compute_ngn')) {
    /** Fixed-point USD->NGN quote: (amount_usd + fee_usd) * fx_rate_ngn_per_usd, half-up to 2dp. */
    function bc_vc_compute_ngn($amount_usd, $fee_usd, $fx_rate) {
        $a = bc_vc_decimal_parse($amount_usd, 2, false);
        $f = bc_vc_decimal_parse($fee_usd, 2, true);
        $r = bc_vc_decimal_parse($fx_rate, 6, false);
        if (!$a || !$f || !$r) return null;
        $total_usd_cents = bc_vc_uint_add($a['digits'], $f['digits']); // scale 2
        $raw = bc_vc_uint_mul($total_usd_cents, $r['digits']);        // scale 8 -> drop 6
        if (strlen($raw) <= 6) $raw = str_pad($raw, 7, '0', STR_PAD_LEFT);
        $keep = substr($raw, 0, strlen($raw) - 6);
        $drop = substr($raw, strlen($raw) - 6);
        $ngn_cents = ((int)$drop[0]) >= 5 ? bc_vc_uint_add($keep, '1') : $keep;
        if ($ngn_cents === '0') return null;
        return [
            'amount_usd' => $a['formatted'],
            'fee_usd'    => $f['formatted'],
            'total_usd'  => bc_vc_fmt_cents($total_usd_cents),
            'fx_rate'    => $r['formatted'],
            'total_ngn'  => bc_vc_fmt_cents($ngn_cents),
        ];
    }
}

if (!function_exists('bc_vc_validate_masked_card')) {
    /** Require masked last-4 PAN and forbid persisting CVV or unmasked PAN. */
    function bc_vc_validate_masked_card($payload) {
        if (!is_array($payload)) return null;
        $cvv = (string)($payload['cvv'] ?? '');
        if ($cvv !== '') return null;
        $pan = trim((string)($payload['masked_pan'] ?? ''));
        if (!preg_match('/\A\*{4} \*{4} \*{4} [0-9]{4}\z/D', $pan)) return null;
        $em = trim((string)($payload['expiry_month'] ?? ''));
        $ey = trim((string)($payload['expiry_year'] ?? ''));
        if (!preg_match('/\A(0[1-9]|1[0-2])\z/D', $em) || !preg_match('/\A[0-9]{2,4}\z/D', $ey)) return null;
        return ['masked_pan' => $pan, 'expiry_month' => $em, 'expiry_year' => $ey];
    }
}

if (!function_exists('bc_vc_order_transition')) {
    function bc_vc_order_transition($state, $outcome) {
        if (!in_array($outcome, ['unknown', 'confirmed_success', 'confirmed_failure'], true)) {
            return ['code' => 'invalid', 'state' => $state];
        }
        if ($state === 'held') return ['code' => 'held', 'state' => $state];
        if (in_array($state, ['confirmed_success', 'confirmed_failure', 'cancelled'], true)) {
            return ['code' => $state === $outcome ? 'already_final' : 'conflict', 'state' => $state];
        }
        if (!in_array($state, ['dispatched', 'unknown'], true)) {
            return ['code' => 'invalid', 'state' => $state];
        }
        return ['code' => 'recorded', 'state' => $outcome];
    }
}

if (!function_exists('bc_vc_reserve_internal')) {
    function bc_vc_reserve_internal($action, $vendor_id, $user_id, $card_ref, $amount_usd, $fee_usd, $fx_rate, $card_name, $request_key) {
        global $connection_server;
        $begun = false;
        if (!in_array($action, ['issue', 'fund'], true) || !is_int($vendor_id) || $vendor_id <= 0 || !is_int($user_id) || $user_id <= 0) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        if (!is_string($request_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $request_key)) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $card_ref = $card_ref === null ? null : trim((string)$card_ref);
        if ($action === 'issue') {
            $card_name = trim(strip_tags((string)$card_name));
            if ($card_name === '' || strlen($card_name) > 255) return ['ok' => false, 'code' => 'invalid'];
            $card_ref = null;
        } else {
            if ($card_ref === null || !preg_match('/\A[A-Za-z0-9_-]{4,100}\z/D', $card_ref)) {
                return ['ok' => false, 'code' => 'invalid'];
            }
            $card_name = '';
        }

        $quote = bc_vc_compute_ngn($amount_usd, $fee_usd, $fx_rate);
        if (!$quote) return ['ok' => false, 'code' => 'invalid'];

        $request_key_hash = hash('sha256', $request_key);
        $payload_hash = hash('sha256', json_encode([
            $vendor_id, $user_id, $action, $card_ref, $quote['amount_usd'], $quote['fee_usd'], $quote['fx_rate'], $quote['total_ngn'], $card_name,
        ], JSON_THROW_ON_ERROR));

        try {
            bc_vc_begin();
            $begun = true;

            $user = bc_vc_sql(
                'SELECT id, username, balance, status, COALESCE(is_blocked, 0) AS is_blocked, COALESCE(force_password_change, 0) AS force_password_change FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE',
                [$vendor_id, $user_id]
            )[0] ?? null;
            if (!$user) throw new RuntimeException('not_found');
            if ((int)$user['status'] !== 1 || (int)$user['is_blocked'] !== 0 || (int)$user['force_password_change'] !== 0) {
                throw new RuntimeException('restricted');
            }

            $old = bc_vc_sql(
                'SELECT id, payload_hash, reference, provider_key, state, version, total_ngn FROM sas_virtualcard_orders WHERE vendor_id=? AND user_id=? AND request_key_hash=? LIMIT 1',
                [$vendor_id, $user_id, $request_key_hash]
            )[0] ?? null;
            if ($old) {
                $connection_server->rollback();
                $begun = false;
                if (!hash_equals((string)$old['payload_hash'], $payload_hash)) return ['ok' => false, 'code' => 'conflict'];
                return [
                    'ok' => true, 'code' => 'replayed', 'replayed' => true,
                    'reference' => (string)$old['reference'], 'provider_key' => (string)$old['provider_key'],
                    'state' => (string)$old['state'], 'version' => (int)$old['version'], 'total_ngn' => (string)$old['total_ngn'],
                ];
            }

            $username = strtolower(trim((string)$user['username']));
            if ($action === 'fund') {
                $card = bc_vc_sql(
                    'SELECT id, username, status, provider FROM sas_virtual_cards_v2 WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE',
                    [$vendor_id, $card_ref]
                )[0] ?? null;
                if (!$card || strtolower(trim((string)$card['username'])) !== $username) throw new RuntimeException('not_found');
                if ((string)$card['provider'] !== 'flutterwave' || (string)$card['status'] !== 'active') {
                    throw new RuntimeException('card_unavailable');
                }
                $card_name = (string)$card_ref;
            }

            $total_ngn = $quote['total_ngn'];
            $before = (string)$user['balance'];
            if (bc_vc_sql('UPDATE sas_users SET balance = balance - ? WHERE vendor_id=? AND id=? AND balance >= ?', [$total_ngn, $vendor_id, $user_id, $total_ngn]) !== 1) {
                throw new RuntimeException('insufficient_balance');
            }
            $fresh = bc_vc_sql('SELECT balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1', [$vendor_id, $user_id])[0] ?? null;
            if (!$fresh) throw new RuntimeException('unconfirmed');

            $reference    = 'VC-' . strtoupper(bin2hex(random_bytes(12)));
            $provider_key = bin2hex(random_bytes(32));
            $actor        = 'user:' . $username;
            $event_key    = hash('sha256', json_encode([$vendor_id, $actor, 'debit', 'reference:' . $reference], JSON_THROW_ON_ERROR));
            $event_hash   = hash('sha256', json_encode([$vendor_id, $actor, 'debit', $total_ngn, 'reference:' . $reference], JSON_THROW_ON_ERROR));

            bc_vc_sql(
                'INSERT INTO sas_wallet_mutation_events(event_key, payload_hash, vendor_id, actor, direction, reference, amount) VALUES(?,?,?,?,?,?,?)',
                [$event_key, $event_hash, $vendor_id, $actor, 'debit', $reference, $total_ngn]
            );
            $desc = ($action === 'issue' ? 'Virtual card issuance hold USD ' : 'Virtual card funding hold USD ') . $quote['total_usd'];
            bc_vc_sql(
                'INSERT INTO sas_transactions(vendor_id, product_unique_id, type_alternative, reference, api_reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,2)',
                [$vendor_id, 'virtual_card_' . $action, 'VirtualCard', $reference, '', $username, $total_ngn, $total_ngn, $before, (string)$fresh['balance'], $desc, 'VIRTUAL_CARD', 'flutterwave']
            );
            bc_vc_sql(
                'INSERT INTO sas_virtualcard_orders(vendor_id, user_id, username, action, card_ref, request_key_hash, payload_hash, reference, provider_key, provider, amount_usd, fee_usd, total_usd, fx_rate_ngn_per_usd, total_ngn, card_name, state, version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)',
                [$vendor_id, $user_id, $username, $action, $card_ref, $request_key_hash, $payload_hash, $reference, $provider_key, 'flutterwave', $quote['amount_usd'], $quote['fee_usd'], $quote['total_usd'], $quote['fx_rate'], $total_ngn, $card_name, 'held']
            );

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            return [
                'ok' => true, 'code' => 'reserved', 'replayed' => false,
                'reference' => $reference, 'provider_key' => $provider_key,
                'state' => 'held', 'version' => 0,
                'total_usd' => $quote['total_usd'], 'total_ngn' => $total_ngn,
            ];
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'restricted', 'conflict', 'card_unavailable', 'insufficient_balance', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_vc_reserve_issue')) {
    function bc_vc_reserve_issue($vendor_id, $user_id, $amount_usd, $fee_usd, $fx_rate, $card_name, $request_key) {
        return bc_vc_reserve_internal('issue', $vendor_id, $user_id, null, $amount_usd, $fee_usd, $fx_rate, $card_name, $request_key);
    }
}

if (!function_exists('bc_vc_reserve_fund')) {
    function bc_vc_reserve_fund($vendor_id, $user_id, $card_ref, $amount_usd, $fee_usd, $fx_rate, $request_key) {
        return bc_vc_reserve_internal('fund', $vendor_id, $user_id, $card_ref, $amount_usd, $fee_usd, $fx_rate, '', $request_key);
    }
}

if (!function_exists('bc_vc_observe_outcome')) {
    /** Reconcile verified virtual-card provider outcome. Never stores CVV or full PAN. */
    function bc_vc_observe_outcome($vendor_id, $reference, $event_key, $outcome, $evidence_hash, $provider_reference, $expected_version, $card_payload = []) {
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
            bc_vc_begin();
            $begun = true;

            $ord = bc_vc_sql('SELECT * FROM sas_virtualcard_orders WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE', [$vendor_id, $reference])[0] ?? null;
            if (!$ord) throw new RuntimeException('not_found');
            $ord_id = (int)$ord['id'];

            $valid_card = null;
            if ($outcome === 'confirmed_success') {
                if ($provider_reference === '') throw new RuntimeException('invalid');
                if ((string)$ord['action'] === 'issue') {
                    $valid_card = bc_vc_validate_masked_card($card_payload);
                    if (!$valid_card) throw new RuntimeException('invalid_card_data');
                }
            }

            $ev = bc_vc_sql('SELECT * FROM sas_virtualcard_order_events WHERE vendor_id=? AND order_id=? AND event_key=? LIMIT 1 FOR UPDATE', [$vendor_id, $ord_id, $event_key])[0] ?? null;
            if ($ev) {
                if (!hash_equals((string)$ev['observation_hash'], $digest)) throw new RuntimeException('conflict');
                $out = ['code' => (string)$ev['disposition'], 'state' => (string)$ev['result_state'], 'version' => (int)$ev['result_version'], 'replayed' => true];
            } else {
                $out = bc_vc_order_transition((string)$ord['state'], $outcome);
                $version = (int)$ord['version'];
                if ($expected_version !== $version && $out['code'] === 'recorded') $out = ['code' => 'stale', 'state' => (string)$ord['state']];
                if ($ord['provider_reference'] !== null && $provider_reference !== '' && !hash_equals((string)$ord['provider_reference'], $provider_reference)) {
                    $out = ['code' => 'conflict', 'state' => (string)$ord['state']];
                }
                $prov_ref = $ord['provider_reference'];
                if ($out['code'] === 'recorded') {
                    if ($prov_ref === null && $provider_reference !== '') $prov_ref = $provider_reference;
                    $version++;
                    if ($out['state'] === 'confirmed_success') {
                        if ((string)$ord['action'] === 'issue') {
                            bc_vc_sql(
                                "INSERT INTO sas_virtual_cards_v2(vendor_id, username, reference, provider_card_id, card_name, masked_pan, expiry_month, expiry_year, cvv, balance_usd, status, provider) VALUES(?,?,?,?,?,?,?,?,'',?,'active',?)",
                                [$vendor_id, (string)$ord['username'], $reference, $prov_ref, (string)$ord['card_name'], $valid_card['masked_pan'], $valid_card['expiry_month'], $valid_card['expiry_year'], (string)$ord['amount_usd'], (string)$ord['provider']]
                            );
                        } else {
                            $card = bc_vc_sql('SELECT id FROM sas_virtual_cards_v2 WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE', [$vendor_id, (string)$ord['card_ref']])[0] ?? null;
                            if (!$card) throw new RuntimeException('not_found');
                            if (bc_vc_sql('UPDATE sas_virtual_cards_v2 SET balance_usd = balance_usd + ? WHERE vendor_id=? AND reference=?', [(string)$ord['amount_usd'], $vendor_id, (string)$ord['card_ref']]) !== 1) {
                                throw new RuntimeException('unconfirmed');
                            }
                        }
                        bc_vc_sql('UPDATE sas_transactions SET api_reference=?, status=1 WHERE vendor_id=? AND reference=? AND status=2', [$prov_ref, $vendor_id, $reference]);
                        bc_vc_sql('UPDATE sas_virtualcard_orders SET state=?, provider_reference=?, version=?, settled_at=NOW(), updated_at=NOW() WHERE vendor_id=? AND id=?', [$out['state'], $prov_ref, $version, $vendor_id, $ord_id]);
                    } elseif ($out['state'] === 'confirmed_failure') {
                        if ($ord['refunded_at'] !== null) throw new RuntimeException('already_refunded');
                        $user = bc_vc_sql('SELECT id, username, balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE', [$vendor_id, (int)$ord['user_id']])[0] ?? null;
                        if (!$user) throw new RuntimeException('not_found');
                        $ngn = (string)$ord['total_ngn'];
                        $before = (string)$user['balance'];
                        if (bc_vc_sql('UPDATE sas_users SET balance = balance + ? WHERE vendor_id=? AND id=?', [$ngn, $vendor_id, (int)$ord['user_id']]) !== 1) {
                            throw new RuntimeException('unconfirmed');
                        }
                        $fresh = bc_vc_sql('SELECT balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1', [$vendor_id, (int)$ord['user_id']])[0] ?? null;
                        if (!$fresh) throw new RuntimeException('unconfirmed');
                        $uname = strtolower(trim((string)$user['username']));
                        $actor = 'user:' . $uname;
                        $ref_tx = 'RFD-' . $reference;
                        $r_key = hash('sha256', json_encode([$vendor_id, $actor, 'credit', 'refund:' . $reference], JSON_THROW_ON_ERROR));
                        $r_hash = hash('sha256', json_encode([$vendor_id, $actor, 'credit', $ngn, $reference], JSON_THROW_ON_ERROR));
                        bc_vc_sql('INSERT INTO sas_wallet_mutation_events(event_key, payload_hash, vendor_id, actor, direction, reference, amount) VALUES(?,?,?,?,?,?,?)', [$r_key, $r_hash, $vendor_id, $actor, 'credit', $ref_tx, $ngn]);
                        bc_vc_sql('INSERT INTO sas_transactions(vendor_id, product_unique_id, type_alternative, reference, api_reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,1)', [$vendor_id, 'virtual_card_refund', 'Refund', $ref_tx, $prov_ref ?? '', $uname, $ngn, $ngn, $before, (string)$fresh['balance'], "Virtual card refund Ref: '$reference'", 'REFUND', 'flutterwave']);
                        bc_vc_sql('UPDATE sas_transactions SET status=3 WHERE vendor_id=? AND reference=? AND status=2', [$vendor_id, $reference]);
                        bc_vc_sql('UPDATE sas_virtualcard_orders SET state=?, provider_reference=?, version=?, refunded_at=NOW(), updated_at=NOW() WHERE vendor_id=? AND id=?', [$out['state'], $prov_ref, $version, $vendor_id, $ord_id]);
                    } else {
                        bc_vc_sql('UPDATE sas_virtualcard_orders SET state=?, provider_reference=?, version=?, updated_at=NOW() WHERE vendor_id=? AND id=?', [$out['state'], $prov_ref, $version, $vendor_id, $ord_id]);
                    }
                }
                bc_vc_sql('INSERT INTO sas_virtualcard_order_events(vendor_id, order_id, event_key, observation_hash, evidence_hash, observed_outcome, observed_reference, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?,?,?,?)', [$vendor_id, $ord_id, $event_key, $digest, $evidence_hash, $outcome, $provider_reference, $out['code'], $out['state'], $version]);
                $out['version'] = $version;
                $out['replayed'] = false;
            }
            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            $out['ok'] = in_array($out['code'], ['recorded', 'already_final'], true);
            return $out;
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['invalid', 'invalid_card_data', 'not_found', 'conflict', 'already_refunded', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_vc_cancel_held')) {
    function bc_vc_cancel_held($vendor_id, $reference, $event_key, $reason_hash, $expected_version) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($expected_version) || $expected_version < 0
            || !is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{4,64}\z/D', $reference)
            || !is_string($event_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $event_key)
            || !is_string($reason_hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $reason_hash)) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $digest = hash('sha256', json_encode(['cancelled', $reason_hash, $expected_version], JSON_THROW_ON_ERROR));

        try {
            bc_vc_begin();
            $begun = true;
            $ord = bc_vc_sql('SELECT * FROM sas_virtualcard_orders WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE', [$vendor_id, $reference])[0] ?? null;
            if (!$ord) throw new RuntimeException('not_found');
            $ord_id = (int)$ord['id'];

            $ev = bc_vc_sql('SELECT * FROM sas_virtualcard_order_events WHERE vendor_id=? AND order_id=? AND event_key=? LIMIT 1 FOR UPDATE', [$vendor_id, $ord_id, $event_key])[0] ?? null;
            if ($ev) {
                if (!hash_equals((string)$ev['observation_hash'], $digest)) throw new RuntimeException('conflict');
                $res = ['code' => (string)$ev['disposition'], 'state' => (string)$ev['result_state'], 'version' => (int)$ev['result_version'], 'replayed' => true];
            } else {
                $version = (int)$ord['version'];
                $state = (string)$ord['state'];
                if ($state === 'cancelled') $res = ['code' => 'already_final', 'state' => 'cancelled'];
                elseif ($state !== 'held') $res = ['code' => 'conflict', 'state' => $state];
                elseif ($expected_version !== $version) $res = ['code' => 'stale', 'state' => $state];
                else {
                    if ($ord['refunded_at'] !== null) throw new RuntimeException('already_refunded');
                    $user = bc_vc_sql('SELECT id, username, balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE', [$vendor_id, (int)$ord['user_id']])[0] ?? null;
                    if (!$user) throw new RuntimeException('not_found');
                    $ngn = (string)$ord['total_ngn'];
                    $before = (string)$user['balance'];
                    if (bc_vc_sql('UPDATE sas_users SET balance = balance + ? WHERE vendor_id=? AND id=?', [$ngn, $vendor_id, (int)$ord['user_id']]) !== 1) {
                        throw new RuntimeException('unconfirmed');
                    }
                    $fresh = bc_vc_sql('SELECT balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1', [$vendor_id, (int)$ord['user_id']])[0] ?? null;
                    if (!$fresh) throw new RuntimeException('unconfirmed');
                    $uname = strtolower(trim((string)$user['username']));
                    $actor = 'user:' . $uname;
                    $ref_tx = 'RFD-' . $reference;
                    $r_key = hash('sha256', json_encode([$vendor_id, $actor, 'credit', 'refund:' . $reference], JSON_THROW_ON_ERROR));
                    $r_hash = hash('sha256', json_encode([$vendor_id, $actor, 'credit', $ngn, $reference], JSON_THROW_ON_ERROR));
                    bc_vc_sql('INSERT INTO sas_wallet_mutation_events(event_key, payload_hash, vendor_id, actor, direction, reference, amount) VALUES(?,?,?,?,?,?,?)', [$r_key, $r_hash, $vendor_id, $actor, 'credit', $ref_tx, $ngn]);
                    bc_vc_sql('INSERT INTO sas_transactions(vendor_id, product_unique_id, type_alternative, reference, api_reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,1)', [$vendor_id, 'virtual_card_refund', 'Refund', $ref_tx, '', $uname, $ngn, $ngn, $before, (string)$fresh['balance'], "Cancelled virtual card hold Ref: '$reference'", 'REFUND', 'flutterwave']);
                    bc_vc_sql('UPDATE sas_transactions SET status=3 WHERE vendor_id=? AND reference=? AND status=2', [$vendor_id, $reference]);
                    $version++;
                    bc_vc_sql("UPDATE sas_virtualcard_orders SET state='cancelled', version=?, refunded_at=NOW(), updated_at=NOW() WHERE vendor_id=? AND id=?", [$version, $vendor_id, $ord_id]);
                    $res = ['code' => 'recorded', 'state' => 'cancelled'];
                }
                bc_vc_sql('INSERT INTO sas_virtualcard_order_events(vendor_id, order_id, event_key, observation_hash, evidence_hash, observed_outcome, observed_reference, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?,?,?,?)', [$vendor_id, $ord_id, $event_key, $digest, $reason_hash, 'cancelled', '', $res['code'], $res['state'], $version]);
                $res['version'] = $version;
                $res['replayed'] = false;
            }
            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            $res['ok'] = in_array($res['code'], ['recorded', 'already_final'], true);
            return $res;
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'conflict', 'already_refunded', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}
