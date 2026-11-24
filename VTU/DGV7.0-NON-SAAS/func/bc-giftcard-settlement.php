<?php
/**
 * Internal durable gift-card purchase & P2P escrow settlement foundation (HELD).
 * No HTTP route, no runtime DDL, no Reloadly cURL calls, and no GIFTCARD_SETTLEMENT_HOLD release.
 */
if (!function_exists('bc_gc_sql')) {
    function bc_gc_sql($sql, array $params = []) {
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

if (!function_exists('bc_gc_decimal_parse')) {
    function bc_gc_decimal_parse($value, $scale = 2, $allow_zero = false) {
        if (is_bool($value) || !is_scalar($value)) return null;
        if (is_float($value)) {
            if (!is_finite($value)) return null;
            $value = number_format($value, $scale, '.', '');
        } elseif (is_int($value)) {
            $value = (string)$value;
        } else {
            $value = trim((string)$value);
        }
        if ($value !== '' && strpos($value, '.') !== false) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        if (!preg_match('/\A([0-9]{1,10})(?:\.([0-9]{1,' . (int)$scale . '}))?\z/D', $value, $m)) return null;
        $whole = ltrim($m[1], '0');
        if ($whole === '') $whole = '0';
        $frac = str_pad($m[2] ?? '', $scale, '0', STR_PAD_RIGHT);
        if (!$allow_zero && $whole === '0' && trim($frac, '0') === '') return null;
        return ['whole' => $whole, 'frac' => $frac, 'decimal' => $scale > 0 ? ($whole . '.' . $frac) : $whole, 'digits' => ltrim($whole . $frac, '0') ?: '0'];
    }
}

if (!function_exists('bc_gc_uint_mul')) {
    function bc_gc_uint_mul($a, $b) {
        $a = ltrim((string)$a, '0');
        $b = ltrim((string)$b, '0');
        if ($a === '' || $b === '') return '0';
        $la = strlen($a);
        $lb = strlen($b);
        $res = array_fill(0, $la + $lb, 0);
        for ($i = $la - 1; $i >= 0; $i--) {
            $da = (int)$a[$i];
            for ($j = $lb - 1; $j >= 0; $j--) {
                $sum = $res[$i + $j + 1] + ($da * (int)$b[$j]);
                $res[$i + $j + 1] = $sum % 10;
                $res[$i + $j] += intdiv($sum, 10);
            }
        }
        return ltrim(implode('', $res), '0') ?: '0';
    }
}

if (!function_exists('bc_gc_uint_add')) {
    function bc_gc_uint_add($a, $b) {
        $a = (string)$a;
        $b = (string)$b;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $carry = 0;
        $out = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry + ($i >= 0 ? (int)$a[$i--] : 0) + ($j >= 0 ? (int)$b[$j--] : 0);
            $out = ($sum % 10) . $out;
            $carry = intdiv($sum, 10);
        }
        return ltrim($out, '0') ?: '0';
    }
}

if (!function_exists('bc_gc_uint_sub')) {
    function bc_gc_uint_sub($a, $b) {
        $a = ltrim((string)$a, '0') ?: '0';
        $b = ltrim((string)$b, '0') ?: '0';
        if (strlen($a) < strlen($b) || (strlen($a) === strlen($b) && strcmp($a, $b) < 0)) return null;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $borrow = 0;
        $out = '';
        while ($i >= 0) {
            $diff = (int)$a[$i--] - ($j >= 0 ? (int)$b[$j--] : 0) - $borrow;
            if ($diff < 0) { $diff += 10; $borrow = 1; } else { $borrow = 0; }
            $out = $diff . $out;
        }
        return ltrim($out, '0') ?: '0';
    }
}

if (!function_exists('bc_gc_round_to_cents')) {
    /** Convert an unsigned integer scaled by 10^(2+$drop_digits) into 'whole.cc' with half-up rounding. */
    function bc_gc_round_to_cents($digits, $drop_digits) {
        $digits = ltrim((string)$digits, '0') ?: '0';
        if ($drop_digits > 0) {
            if (strlen($digits) <= $drop_digits) {
                $padded = str_pad($digits, $drop_digits, '0', STR_PAD_LEFT);
                $round_up = ((int)$padded[0]) >= 5;
                $cents = $round_up ? '1' : '0';
            } else {
                $keep = substr($digits, 0, strlen($digits) - $drop_digits);
                $drop = substr($digits, strlen($digits) - $drop_digits);
                $cents = ((int)$drop[0]) >= 5 ? bc_gc_uint_add($keep, '1') : $keep;
            }
        } else {
            $cents = $digits;
        }
        $cents = str_pad($cents, 3, '0', STR_PAD_LEFT);
        $whole = substr($cents, 0, strlen($cents) - 2);
        $frac  = substr($cents, -2);
        return ['cents' => ltrim($cents, '0') ?: '0', 'decimal' => $whole . '.' . $frac];
    }
}

if (!function_exists('bc_gc_compute_order_ngn')) {
    function bc_gc_compute_order_ngn($face_value, $quote_rate, $total_markup_percent) {
        $f = bc_gc_decimal_parse($face_value, 2, false);
        $r = bc_gc_decimal_parse($quote_rate, 6, false);
        $m = bc_gc_decimal_parse($total_markup_percent, 2, true);
        if (!$f || !$r || !$m) return null;
        $factor = bc_gc_uint_add('10000', $m['digits']); // 100.00% + markup% scaled by 100
        $raw = bc_gc_uint_mul(bc_gc_uint_mul($f['digits'], $r['digits']), $factor); // scale 2 + 6 + 4 = 12
        $rounded = bc_gc_round_to_cents($raw, 10);
        if ($rounded['cents'] === '0') return null;
        return [
            'face_value' => $f['decimal'],
            'quote_rate' => $r['decimal'],
            'markup_percent' => $m['decimal'],
            'total_ngn' => $rounded['decimal'],
        ];
    }
}

if (!function_exists('bc_gc_compute_p2p_split')) {
    function bc_gc_compute_p2p_split($gross_ngn, $fee_percent) {
        $g = bc_gc_decimal_parse($gross_ngn, 2, false);
        $fp = bc_gc_decimal_parse($fee_percent, 2, true);
        if (!$g || !$fp) return null;
        $raw_fee = bc_gc_uint_mul($g['digits'], $fp['digits']); // scale 2 + 2 + 2(%)= 6 (drop 4)
        $fee = bc_gc_round_to_cents($raw_fee, 4);
        $net_cents = bc_gc_uint_sub($g['digits'], $fee['cents']);
        if ($net_cents === null || $net_cents === '0') return null;
        $net_padded = str_pad($net_cents, 3, '0', STR_PAD_LEFT);
        return [
            'gross_ngn' => $g['decimal'],
            'fee_ngn' => $fee['decimal'],
            'seller_net_ngn' => substr($net_padded, 0, strlen($net_padded) - 2) . '.' . substr($net_padded, -2),
        ];
    }
}

if (!function_exists('bc_gc_begin')) {
    function bc_gc_begin() {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) throw new RuntimeException('unavailable');
        $auto = bc_gc_sql('SELECT @@session.autocommit AS enabled');
        if ((int)($auto[0]['enabled'] ?? 0) !== 1) throw new RuntimeException('outer_transaction');
        try {
            $iso = $connection_server->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        } catch (Throwable $e) {
            throw new RuntimeException('outer_transaction');
        }
        if (!$iso) throw new RuntimeException('outer_transaction');
        $required = [
            'sas_users', 'sas_transactions', 'sas_wallet_mutation_events',
            'sas_vendor_giftcard_products', 'sas_giftcard_inventory',
            'sas_giftcard_orders', 'sas_giftcard_order_events',
            'sas_giftcard_escrows', 'sas_giftcard_escrow_events',
        ];
        $list = "'" . implode("','", $required) . "'";
        $engines = bc_gc_sql("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($list)");
        if (count($engines) !== count($required)) throw new RuntimeException('schema_unavailable');
        foreach ($engines as $e) {
            if (strtoupper((string)($e['ENGINE'] ?? '')) !== 'INNODB') throw new RuntimeException('nontransactional');
        }
        if (!$connection_server->begin_transaction()) throw new RuntimeException('unavailable');
    }
}

if (!function_exists('bc_gc_reserve_order')) {
    /** Reserve NGN funds and create an immutable gift-card purchase order in state='held'. */
    function bc_gc_reserve_order($vendor_id, $user_id, $reloadly_product_id, $face_value, $currency_code, $quote_rate, $vendor_fee_percent, $request_key) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($user_id) || $user_id <= 0 || !is_int($reloadly_product_id) || $reloadly_product_id <= 0) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $currency_code = strtoupper(trim((string)$currency_code));
        if (!preg_match('/\A[A-Z]{3,10}\z/D', $currency_code)) return ['ok' => false, 'code' => 'invalid'];
        if (!is_string($request_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $request_key)) return ['ok' => false, 'code' => 'invalid'];
        $fee_p = bc_gc_decimal_parse($vendor_fee_percent, 2, true);
        if (!$fee_p) return ['ok' => false, 'code' => 'invalid'];
        $face_p = bc_gc_decimal_parse($face_value, 2, false);
        $rate_p = bc_gc_decimal_parse($quote_rate, 6, false);
        if (!$face_p || !$rate_p) return ['ok' => false, 'code' => 'invalid'];

        $request_key_hash = hash('sha256', $request_key);

        try {
            bc_gc_begin();
            $begun = true;

            $user = bc_gc_sql(
                'SELECT id, username, balance, status, COALESCE(is_blocked, 0) AS is_blocked, COALESCE(force_password_change, 0) AS force_password_change FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE',
                [$vendor_id, $user_id]
            )[0] ?? null;
            if (!$user) throw new RuntimeException('not_found');
            if ((int)$user['status'] !== 1 || (int)$user['is_blocked'] !== 0 || (int)$user['force_password_change'] !== 0) {
                throw new RuntimeException('restricted');
            }

            $prod = bc_gc_sql(
                'SELECT product_name, vendor_markup FROM sas_vendor_giftcard_products WHERE vendor_id=? AND reloadly_product_id=? AND status=1 LIMIT 1 FOR UPDATE',
                [$vendor_id, $reloadly_product_id]
            )[0] ?? null;
            if (!$prod) throw new RuntimeException('product_unavailable');

            $vm = bc_gc_decimal_parse((string)$prod['vendor_markup'], 2, true);
            if (!$vm) throw new RuntimeException('invalid');
            $total_markup_cents = bc_gc_uint_add($vm['digits'], $fee_p['digits']);
            $padded_m = str_pad($total_markup_cents, 3, '0', STR_PAD_LEFT);
            $total_markup_str = substr($padded_m, 0, strlen($padded_m) - 2) . '.' . substr($padded_m, -2);

            $quote = bc_gc_compute_order_ngn($face_p['decimal'], $rate_p['decimal'], $total_markup_str);
            if (!$quote) throw new RuntimeException('invalid');

            $payload_hash = hash('sha256', json_encode([
                $vendor_id, $user_id, $reloadly_product_id, $currency_code,
                $quote['face_value'], $quote['quote_rate'], $quote['markup_percent'], $quote['total_ngn'],
            ], JSON_THROW_ON_ERROR));

            $old = bc_gc_sql(
                'SELECT id, payload_hash, reference, provider_key, state, version, total_ngn FROM sas_giftcard_orders WHERE vendor_id=? AND user_id=? AND request_key_hash=? LIMIT 1',
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

            $total_ngn = $quote['total_ngn'];
            $before = (string)$user['balance'];
            $affected = bc_gc_sql(
                'UPDATE sas_users SET balance = balance - ? WHERE vendor_id=? AND id=? AND balance >= ?',
                [$total_ngn, $vendor_id, $user_id, $total_ngn]
            );
            if ($affected !== 1) throw new RuntimeException('insufficient_balance');

            $fresh = bc_gc_sql('SELECT balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1', [$vendor_id, $user_id])[0] ?? null;
            if (!$fresh) throw new RuntimeException('unconfirmed');
            $after = (string)$fresh['balance'];

            $username = strtolower(trim((string)$user['username']));
            $reference = 'GC-' . strtoupper(bin2hex(random_bytes(12)));
            $provider_key = bin2hex(random_bytes(32));
            $actor = 'user:' . $username;
            $event_key = hash('sha256', json_encode([$vendor_id, $actor, 'debit', 'reference:' . $reference], JSON_THROW_ON_ERROR));
            $event_payload_hash = hash('sha256', json_encode([$vendor_id, $actor, 'debit', $total_ngn, 'reference:' . $reference], JSON_THROW_ON_ERROR));

            bc_gc_sql(
                'INSERT INTO sas_wallet_mutation_events(event_key, payload_hash, vendor_id, actor, direction, reference, amount) VALUES(?,?,?,?,?,?,?)',
                [$event_key, $event_payload_hash, $vendor_id, $actor, 'debit', $reference, $total_ngn]
            );

            $product_name = substr((string)$prod['product_name'], 0, 255);
            bc_gc_sql(
                'INSERT INTO sas_transactions(vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status, username, api_reference, mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$vendor_id, 'giftcard_' . $reloadly_product_id, 'Gift Card Purchase', $reference, $total_ngn, $total_ngn, $before, $after, 'Gift Card Reservation: ' . $product_name, '', 2, $username, $provider_key, 'RESERVATION']
            );

            bc_gc_sql(
                'INSERT INTO sas_giftcard_orders(vendor_id, user_id, username, request_key_hash, payload_hash, reference, reloadly_product_id, product_name, face_value, currency_code, quote_rate, markup_percent, total_ngn, provider_key, state, version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)',
                [$vendor_id, $user_id, $username, $request_key_hash, $payload_hash, $reference, $reloadly_product_id, $product_name, $quote['face_value'], $currency_code, $quote['quote_rate'], $quote['markup_percent'], $total_ngn, $provider_key, 'held']
            );

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            return [
                'ok' => true, 'code' => 'reserved', 'replayed' => false,
                'reference' => $reference, 'provider_key' => $provider_key,
                'state' => 'held', 'version' => 0, 'total_ngn' => $total_ngn,
            ];
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'restricted', 'product_unavailable', 'conflict', 'insufficient_balance', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_gc_refund_debit')) {
    function bc_gc_refund_debit($vendor_id, $user_id, $username, $reference, $amount_ngn, $reason_label) {
        $username = strtolower(trim((string)$username));
        $actor = 'user:' . $username;
        $user = bc_gc_sql('SELECT id, balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE', [$vendor_id, $user_id])[0] ?? null;
        if (!$user) throw new RuntimeException('not_found');

        $debit_key = hash('sha256', json_encode([$vendor_id, $actor, 'debit', 'reference:' . $reference], JSON_THROW_ON_ERROR));
        $debit_ev = bc_gc_sql('SELECT amount FROM sas_wallet_mutation_events WHERE event_key=? LIMIT 1 FOR UPDATE', [$debit_key])[0] ?? null;
        $dp = $debit_ev ? bc_gc_decimal_parse((string)$debit_ev['amount'], 2, false) : null;
        $ap = bc_gc_decimal_parse((string)$amount_ngn, 2, false);
        if (!$dp || !$ap || !hash_equals($dp['decimal'], $ap['decimal'])) throw new RuntimeException('refund_has_no_matching_debit');

        $refund_key = hash('sha256', json_encode([$vendor_id, $actor, 'credit', 'refund:' . $reference], JSON_THROW_ON_ERROR));
        $refund_hash = hash('sha256', json_encode([$vendor_id, $actor, 'credit', $ap['decimal'], $reference], JSON_THROW_ON_ERROR));

        $before = (string)$user['balance'];
        if (bc_gc_sql('UPDATE sas_users SET balance = balance + ? WHERE vendor_id=? AND id=?', [$ap['decimal'], $vendor_id, $user_id]) !== 1) {
            throw new RuntimeException('unconfirmed');
        }
        $fresh = bc_gc_sql('SELECT balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1', [$vendor_id, $user_id])[0] ?? null;
        if (!$fresh) throw new RuntimeException('unconfirmed');

        bc_gc_sql(
            'INSERT INTO sas_wallet_mutation_events(event_key, payload_hash, vendor_id, actor, direction, reference, amount) VALUES(?,?,?,?,?,?,?)',
            [$refund_key, $refund_hash, $vendor_id, $actor, 'credit', $reference . '-RF', $ap['decimal']]
        );
        bc_gc_sql('UPDATE sas_transactions SET status=3 WHERE vendor_id=? AND reference=? AND status=2', [$vendor_id, $reference]);
        bc_gc_sql(
            'INSERT INTO sas_transactions(vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status, username, api_reference, mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$vendor_id, $username, 'Refund', $reference . '-RF', $ap['decimal'], $ap['decimal'], $before, (string)$fresh['balance'], $reason_label . " Ref:'" . $reference . "'", '', 1, $username, '', 'RESERVATION']
        );
    }
}

if (!function_exists('bc_gc_order_transition')) {
    function bc_gc_order_transition($state, $outcome) {
        if (!in_array($outcome, ['unknown', 'confirmed_success', 'confirmed_failure'], true)) return ['code' => 'invalid', 'state' => $state];
        if ($state === 'held') return ['code' => 'held', 'state' => $state];
        if (in_array($state, ['confirmed_success', 'confirmed_failure', 'cancelled'], true)) {
            return ['code' => $state === $outcome ? 'already_final' : 'conflict', 'state' => $state];
        }
        if (!in_array($state, ['dispatched', 'unknown'], true)) return ['code' => 'invalid', 'state' => $state];
        return ['code' => 'recorded', 'state' => $outcome];
    }
}

if (!function_exists('bc_gc_observe_order')) {
    /** Journal verified Reloadly outcome. Delivery requires encrypted code; unknown never auto-refunds. */
    function bc_gc_observe_order($vendor_id, $reference, $event_key, $outcome, $evidence_hash, $provider_reference, $expected_version, $encrypted_code = '', $encrypted_pin = '') {
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
        $encrypted_code = (string)$encrypted_code;
        $encrypted_pin  = (string)$encrypted_pin;
        if ($outcome === 'confirmed_success' && ($encrypted_code === '' || $provider_reference === '')) {
            return ['ok' => false, 'code' => 'invalid'];
        }

        $digest = hash('sha256', json_encode([$outcome, $evidence_hash, $provider_reference, $expected_version], JSON_THROW_ON_ERROR));

        try {
            bc_gc_begin();
            $begun = true;

            $order = bc_gc_sql('SELECT * FROM sas_giftcard_orders WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE', [$vendor_id, $reference])[0] ?? null;
            if (!$order) throw new RuntimeException('not_found');
            $order_id = (int)$order['id'];

            $ev = bc_gc_sql('SELECT * FROM sas_giftcard_order_events WHERE vendor_id=? AND order_id=? AND event_key=? LIMIT 1 FOR UPDATE', [$vendor_id, $order_id, $event_key])[0] ?? null;
            if ($ev) {
                if (!hash_equals((string)$ev['observation_hash'], $digest)) throw new RuntimeException('conflict');
                $out = ['code' => (string)$ev['disposition'], 'state' => (string)$ev['result_state'], 'version' => (int)$ev['result_version'], 'replayed' => true];
            } else {
                $out = bc_gc_order_transition((string)$order['state'], $outcome);
                $version = (int)$order['version'];
                if ($expected_version !== $version && $out['code'] === 'recorded') $out = ['code' => 'stale', 'state' => (string)$order['state']];
                if ($order['provider_reference'] !== null && $provider_reference !== '' && !hash_equals((string)$order['provider_reference'], $provider_reference)) {
                    $out = ['code' => 'conflict', 'state' => (string)$order['state']];
                }
                $prov_ref = $order['provider_reference'];
                if ($out['code'] === 'recorded') {
                    if ($prov_ref === null && $provider_reference !== '') $prov_ref = $provider_reference;
                    $version++;
                    bc_gc_sql('UPDATE sas_giftcard_orders SET state=?, provider_reference=?, version=?, updated_at=NOW() WHERE vendor_id=? AND id=?', [$out['state'], $prov_ref, $version, $vendor_id, $order_id]);
                    if ($outcome === 'confirmed_success') {
                        bc_gc_sql('UPDATE sas_transactions SET status=1 WHERE vendor_id=? AND reference=? AND status=2', [$vendor_id, $reference]);
                        bc_gc_sql(
                            'INSERT INTO sas_giftcard_inventory(vendor_id, reloadly_tx_id, reloadly_product_id, product_name, card_code, card_pin, face_value, currency, current_owner_id, source, is_for_sale, sale_price_ngn) VALUES(?,?,?,?,?,?,?,?,?,?,0,0.00)',
                            [$vendor_id, $provider_reference, (int)$order['reloadly_product_id'], (string)$order['product_name'], $encrypted_code, $encrypted_pin, (string)$order['face_value'], (string)$order['currency_code'], (int)$order['user_id'], 'api']
                        );
                    } elseif ($outcome === 'confirmed_failure') {
                        bc_gc_refund_debit($vendor_id, (int)$order['user_id'], (string)$order['username'], $reference, (string)$order['total_ngn'], 'Gift Card order failed');
                    }
                }
                bc_gc_sql(
                    'INSERT INTO sas_giftcard_order_events(vendor_id, order_id, event_key, observation_hash, evidence_hash, observed_outcome, observed_reference, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?,?,?,?)',
                    [$vendor_id, $order_id, $event_key, $digest, $evidence_hash, $outcome, $provider_reference, $out['code'], $out['state'], $version]
                );
                $out['version'] = $version;
                $out['replayed'] = false;
            }

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            $out['ok'] = in_array($out['code'], ['recorded', 'already_final'], true);
            return $out;
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'conflict', 'refund_has_no_matching_debit', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_gc_cancel_held_order')) {
    function bc_gc_cancel_held_order($vendor_id, $reference, $event_key, $reason_hash, $expected_version) {
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
            bc_gc_begin();
            $begun = true;
            $order = bc_gc_sql('SELECT * FROM sas_giftcard_orders WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE', [$vendor_id, $reference])[0] ?? null;
            if (!$order) throw new RuntimeException('not_found');
            $order_id = (int)$order['id'];

            $ev = bc_gc_sql('SELECT * FROM sas_giftcard_order_events WHERE vendor_id=? AND order_id=? AND event_key=? LIMIT 1 FOR UPDATE', [$vendor_id, $order_id, $event_key])[0] ?? null;
            if ($ev) {
                if (!hash_equals((string)$ev['observation_hash'], $digest)) throw new RuntimeException('conflict');
                $out = ['code' => (string)$ev['disposition'], 'state' => (string)$ev['result_state'], 'version' => (int)$ev['result_version'], 'replayed' => true];
            } else {
                $version = (int)$order['version'];
                if ($order['state'] === 'cancelled') $out = ['code' => 'already_final', 'state' => 'cancelled'];
                elseif ($order['state'] !== 'held') $out = ['code' => 'not_held', 'state' => (string)$order['state']];
                elseif ($expected_version !== $version) $out = ['code' => 'stale', 'state' => (string)$order['state']];
                else {
                    $version++;
                    bc_gc_sql('UPDATE sas_giftcard_orders SET state=?, version=?, updated_at=NOW() WHERE vendor_id=? AND id=?', ['cancelled', $version, $vendor_id, $order_id]);
                    bc_gc_refund_debit($vendor_id, (int)$order['user_id'], (string)$order['username'], $reference, (string)$order['total_ngn'], 'Held gift card cancelled');
                    $out = ['code' => 'cancelled', 'state' => 'cancelled'];
                }
                bc_gc_sql(
                    'INSERT INTO sas_giftcard_order_events(vendor_id, order_id, event_key, observation_hash, evidence_hash, observed_outcome, observed_reference, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?,?,?,?)',
                    [$vendor_id, $order_id, $event_key, $digest, $reason_hash, 'cancelled', '', $out['code'], $out['state'], $version]
                );
                $out['version'] = $version;
                $out['replayed'] = false;
            }
            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            $out['ok'] = in_array($out['code'], ['cancelled', 'already_final'], true);
            return $out;
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'conflict', 'refund_has_no_matching_debit', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_gc_p2p_hold')) {
    /**
     * Atomically place a listed card into P2P escrow and debit buyer wallet.
     * Locks card first, then buyer/seller users in ascending ID order to prevent deadlocks.
     */
    function bc_gc_p2p_hold($vendor_id, $buyer_id, $card_id, $fee_percent, $request_key) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($buyer_id) || $buyer_id <= 0 || !is_int($card_id) || $card_id <= 0) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        if (!is_string($request_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $request_key)) return ['ok' => false, 'code' => 'invalid'];
        if (!bc_gc_decimal_parse($fee_percent, 2, true)) return ['ok' => false, 'code' => 'invalid'];

        $request_key_hash = hash('sha256', $request_key);

        try {
            bc_gc_begin();
            $begun = true;

            $card = bc_gc_sql('SELECT id, current_owner_id, is_for_sale, sale_price_ngn FROM sas_giftcard_inventory WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE', [$vendor_id, $card_id])[0] ?? null;
            if (!$card) throw new RuntimeException('not_found');

            $old = bc_gc_sql(
                'SELECT id, card_id, seller_id, payload_hash, reference, gross_ngn, fee_ngn, seller_net_ngn, state, version FROM sas_giftcard_escrows WHERE vendor_id=? AND buyer_id=? AND request_key_hash=? LIMIT 1',
                [$vendor_id, $buyer_id, $request_key_hash]
            )[0] ?? null;
            if ($old) {
                $connection_server->rollback();
                $begun = false;
                if ((int)$old['card_id'] !== $card_id) return ['ok' => false, 'code' => 'conflict'];
                return [
                    'ok' => true, 'code' => 'replayed', 'replayed' => true,
                    'reference' => (string)$old['reference'], 'state' => (string)$old['state'],
                    'version' => (int)$old['version'], 'gross_ngn' => (string)$old['gross_ngn'],
                ];
            }

            $seller_id = (int)$card['current_owner_id'];
            if ((int)$card['is_for_sale'] !== 1 || $seller_id <= 0 || $seller_id === $buyer_id) {
                $connection_server->rollback();
                $begun = false;
                $freshOld = bc_gc_sql(
                    'SELECT id, card_id, seller_id, payload_hash, reference, gross_ngn, fee_ngn, seller_net_ngn, state, version FROM sas_giftcard_escrows WHERE vendor_id=? AND buyer_id=? AND request_key_hash=? LIMIT 1',
                    [$vendor_id, $buyer_id, $request_key_hash]
                )[0] ?? null;
                if ($freshOld) {
                    if ((int)$freshOld['card_id'] !== $card_id) return ['ok' => false, 'code' => 'conflict'];
                    return [
                        'ok' => true, 'code' => 'replayed', 'replayed' => true,
                        'reference' => (string)$freshOld['reference'], 'state' => (string)$freshOld['state'],
                        'version' => (int)$freshOld['version'], 'gross_ngn' => (string)$freshOld['gross_ngn'],
                    ];
                }
                if ($seller_id <= 0 || $seller_id === $buyer_id) return ['ok' => false, 'code' => 'invalid_trade'];
                return ['ok' => false, 'code' => 'card_unavailable'];
            }

            $first_id  = min($buyer_id, $seller_id);
            $second_id = max($buyer_id, $seller_id);
            $u1 = bc_gc_sql('SELECT id, username, balance, status, COALESCE(is_blocked, 0) AS is_blocked, COALESCE(force_password_change, 0) AS force_password_change FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE', [$vendor_id, $first_id])[0] ?? null;
            $u2 = bc_gc_sql('SELECT id, username, balance, status, COALESCE(is_blocked, 0) AS is_blocked, COALESCE(force_password_change, 0) AS force_password_change FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE', [$vendor_id, $second_id])[0] ?? null;
            $buyer  = $buyer_id === $first_id ? $u1 : $u2;
            $seller = $seller_id === $first_id ? $u1 : $u2;
            if (!$buyer || !$seller) throw new RuntimeException('not_found');
            if ((int)$buyer['status'] !== 1 || (int)$buyer['is_blocked'] !== 0 || (int)$buyer['force_password_change'] !== 0 || (int)$seller['status'] !== 1 || (int)$seller['is_blocked'] !== 0) {
                throw new RuntimeException('restricted');
            }

            if ((int)$card['is_for_sale'] !== 1) throw new RuntimeException('card_unavailable');
            $split = bc_gc_compute_p2p_split((string)$card['sale_price_ngn'], $fee_percent);
            if (!$split) throw new RuntimeException('card_unavailable');

            $payload_hash = hash('sha256', json_encode([
                $vendor_id, $card_id, $buyer_id, $seller_id,
                $split['gross_ngn'], $split['fee_ngn'], $split['seller_net_ngn'],
            ], JSON_THROW_ON_ERROR));

            $before = (string)$buyer['balance'];
            if (bc_gc_sql('UPDATE sas_users SET balance = balance - ? WHERE vendor_id=? AND id=? AND balance >= ?', [$split['gross_ngn'], $vendor_id, $buyer_id, $split['gross_ngn']]) !== 1) {
                throw new RuntimeException('insufficient_balance');
            }
            $fresh = bc_gc_sql('SELECT balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1', [$vendor_id, $buyer_id])[0] ?? null;
            if (!$fresh) throw new RuntimeException('unconfirmed');

            $buyer_name  = strtolower(trim((string)$buyer['username']));
            $seller_name = strtolower(trim((string)$seller['username']));
            $reference   = 'P2P-' . strtoupper(bin2hex(random_bytes(11)));
            $actor       = 'user:' . $buyer_name;
            $event_key   = hash('sha256', json_encode([$vendor_id, $actor, 'debit', 'reference:' . $reference], JSON_THROW_ON_ERROR));
            $event_hash  = hash('sha256', json_encode([$vendor_id, $actor, 'debit', $split['gross_ngn'], 'reference:' . $reference], JSON_THROW_ON_ERROR));

            bc_gc_sql(
                'INSERT INTO sas_wallet_mutation_events(event_key, payload_hash, vendor_id, actor, direction, reference, amount) VALUES(?,?,?,?,?,?,?)',
                [$event_key, $event_hash, $vendor_id, $actor, 'debit', $reference, $split['gross_ngn']]
            );
            bc_gc_sql(
                'INSERT INTO sas_transactions(vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status, username, api_reference, mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$vendor_id, 'p2p_card_' . $card_id, 'Gift Card P2P', $reference, $split['gross_ngn'], $split['gross_ngn'], $before, (string)$fresh['balance'], 'P2P Escrow Hold Card #' . $card_id, '', 2, $buyer_name, '', 'ESCROW']
            );
            if (bc_gc_sql('UPDATE sas_giftcard_inventory SET is_for_sale=0 WHERE vendor_id=? AND id=? AND is_for_sale=1', [$vendor_id, $card_id]) !== 1) {
                throw new RuntimeException('card_unavailable');
            }
            bc_gc_sql(
                'INSERT INTO sas_giftcard_escrows(vendor_id, card_id, buyer_id, buyer_username, seller_id, seller_username, request_key_hash, payload_hash, reference, gross_ngn, fee_ngn, seller_net_ngn, state, version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,0)',
                [$vendor_id, $card_id, $buyer_id, $buyer_name, $seller_id, $seller_name, $request_key_hash, $payload_hash, $reference, $split['gross_ngn'], $split['fee_ngn'], $split['seller_net_ngn'], 'held_escrow']
            );

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            return [
                'ok' => true, 'code' => 'held_escrow', 'replayed' => false,
                'reference' => $reference, 'state' => 'held_escrow', 'version' => 0,
                'gross_ngn' => $split['gross_ngn'], 'fee_ngn' => $split['fee_ngn'], 'seller_net_ngn' => $split['seller_net_ngn'],
            ];
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            if ((int)$e->getCode() === 1062) {
                $freshOld = bc_gc_sql(
                    'SELECT id, card_id, reference, state, version, gross_ngn FROM sas_giftcard_escrows WHERE vendor_id=? AND buyer_id=? AND request_key_hash=? LIMIT 1',
                    [$vendor_id, $buyer_id, $request_key_hash]
                )[0] ?? null;
                if ($freshOld) {
                    if ((int)$freshOld['card_id'] !== $card_id) return ['ok' => false, 'code' => 'conflict'];
                    return [
                        'ok' => true, 'code' => 'replayed', 'replayed' => true,
                        'reference' => (string)$freshOld['reference'], 'state' => (string)$freshOld['state'],
                        'version' => (int)$freshOld['version'], 'gross_ngn' => (string)$freshOld['gross_ngn'],
                    ];
                }
            }
            $known = ['not_found', 'invalid_trade', 'restricted', 'conflict', 'card_unavailable', 'insufficient_balance', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_gc_p2p_release')) {
    /** Atomically release held P2P escrow: credit seller net, transfer card ownership, complete buyer tx. */
    function bc_gc_p2p_release($vendor_id, $reference, $event_key, $expected_version) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($expected_version) || $expected_version < 0
            || !is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{4,64}\z/D', $reference)
            || !is_string($event_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $event_key)) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $digest = hash('sha256', json_encode(['released', $expected_version], JSON_THROW_ON_ERROR));

        try {
            bc_gc_begin();
            $begun = true;

            $escrow = bc_gc_sql('SELECT * FROM sas_giftcard_escrows WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE', [$vendor_id, $reference])[0] ?? null;
            if (!$escrow) throw new RuntimeException('not_found');
            $escrow_id = (int)$escrow['id'];

            $ev = bc_gc_sql('SELECT * FROM sas_giftcard_escrow_events WHERE vendor_id=? AND escrow_id=? AND event_key=? LIMIT 1 FOR UPDATE', [$vendor_id, $escrow_id, $event_key])[0] ?? null;
            if ($ev) {
                if (!hash_equals((string)$ev['observation_hash'], $digest)) throw new RuntimeException('conflict');
                $out = ['code' => (string)$ev['disposition'], 'state' => (string)$ev['result_state'], 'version' => (int)$ev['result_version'], 'replayed' => true];
            } else {
                $version = (int)$escrow['version'];
                if ($escrow['state'] === 'released') {
                    $out = ['code' => 'already_final', 'state' => 'released'];
                } elseif ($escrow['state'] !== 'held_escrow') {
                    $out = ['code' => 'conflict', 'state' => (string)$escrow['state']];
                } elseif ($expected_version !== $version) {
                    $out = ['code' => 'stale', 'state' => (string)$escrow['state']];
                } else {
                    $card = bc_gc_sql('SELECT id, current_owner_id FROM sas_giftcard_inventory WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE', [$vendor_id, (int)$escrow['card_id']])[0] ?? null;
                    if (!$card || (int)$card['current_owner_id'] !== (int)$escrow['seller_id']) throw new RuntimeException('ownership_mismatch');

                    $seller = bc_gc_sql('SELECT id, balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE', [$vendor_id, (int)$escrow['seller_id']])[0] ?? null;
                    if (!$seller) throw new RuntimeException('not_found');

                    $seller_name = strtolower(trim((string)$escrow['seller_username']));
                    $seller_net  = (string)$escrow['seller_net_ngn'];
                    $seller_actor = 'user:' . $seller_name;
                    $credit_key  = hash('sha256', json_encode([$vendor_id, $seller_actor, 'credit', 'p2p-release:' . $reference], JSON_THROW_ON_ERROR));
                    $credit_hash = hash('sha256', json_encode([$vendor_id, $seller_actor, 'credit', $seller_net, 'p2p-release:' . $reference], JSON_THROW_ON_ERROR));

                    $s_before = (string)$seller['balance'];
                    if (bc_gc_sql('UPDATE sas_users SET balance = balance + ? WHERE vendor_id=? AND id=?', [$seller_net, $vendor_id, (int)$escrow['seller_id']]) !== 1) {
                        throw new RuntimeException('unconfirmed');
                    }
                    $s_fresh = bc_gc_sql('SELECT balance FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1', [$vendor_id, (int)$escrow['seller_id']])[0] ?? null;
                    if (!$s_fresh) throw new RuntimeException('unconfirmed');

                    bc_gc_sql(
                        'INSERT INTO sas_wallet_mutation_events(event_key, payload_hash, vendor_id, actor, direction, reference, amount) VALUES(?,?,?,?,?,?,?)',
                        [$credit_key, $credit_hash, $vendor_id, $seller_actor, 'credit', $reference . '-REL', $seller_net]
                    );
                    bc_gc_sql(
                        'INSERT INTO sas_transactions(vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status, username, api_reference, mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$vendor_id, 'p2p_card_' . $escrow['card_id'], 'Gift Card Sale', $reference . '-REL', $seller_net, $seller_net, $s_before, (string)$s_fresh['balance'], 'P2P Sale Released #' . $escrow['card_id'], '', 1, $seller_name, '', 'ESCROW']
                    );
                    bc_gc_sql('UPDATE sas_transactions SET status=1 WHERE vendor_id=? AND reference=? AND status=2', [$vendor_id, $reference]);
                    bc_gc_sql('UPDATE sas_giftcard_inventory SET current_owner_id=?, is_for_sale=0 WHERE vendor_id=? AND id=?', [(int)$escrow['buyer_id'], $vendor_id, (int)$escrow['card_id']]);
                    $version++;
                    bc_gc_sql('UPDATE sas_giftcard_escrows SET state=?, version=?, updated_at=NOW() WHERE vendor_id=? AND id=?', ['released', $version, $vendor_id, $escrow_id]);
                    $out = ['code' => 'released', 'state' => 'released'];
                }
                bc_gc_sql(
                    'INSERT INTO sas_giftcard_escrow_events(vendor_id, escrow_id, event_key, observation_hash, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?)',
                    [$vendor_id, $escrow_id, $event_key, $digest, $out['code'], $out['state'], $version]
                );
                $out['version'] = $version;
                $out['replayed'] = false;
            }
            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            $out['ok'] = in_array($out['code'], ['released', 'already_final'], true);
            return $out;
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'conflict', 'ownership_mismatch', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_gc_p2p_refund')) {
    /** Atomically cancel & refund a held_escrow trade ONCE and restore listing availability. */
    function bc_gc_p2p_refund($vendor_id, $reference, $event_key, $expected_version) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($expected_version) || $expected_version < 0
            || !is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{4,64}\z/D', $reference)
            || !is_string($event_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $event_key)) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $digest = hash('sha256', json_encode(['refunded', $expected_version], JSON_THROW_ON_ERROR));

        try {
            bc_gc_begin();
            $begun = true;

            $escrow = bc_gc_sql('SELECT * FROM sas_giftcard_escrows WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE', [$vendor_id, $reference])[0] ?? null;
            if (!$escrow) throw new RuntimeException('not_found');
            $escrow_id = (int)$escrow['id'];

            $ev = bc_gc_sql('SELECT * FROM sas_giftcard_escrow_events WHERE vendor_id=? AND escrow_id=? AND event_key=? LIMIT 1 FOR UPDATE', [$vendor_id, $escrow_id, $event_key])[0] ?? null;
            if ($ev) {
                if (!hash_equals((string)$ev['observation_hash'], $digest)) throw new RuntimeException('conflict');
                $out = ['code' => (string)$ev['disposition'], 'state' => (string)$ev['result_state'], 'version' => (int)$ev['result_version'], 'replayed' => true];
            } else {
                $version = (int)$escrow['version'];
                if ($escrow['state'] === 'refunded') {
                    $out = ['code' => 'already_final', 'state' => 'refunded'];
                } elseif ($escrow['state'] !== 'held_escrow') {
                    $out = ['code' => 'conflict', 'state' => (string)$escrow['state']];
                } elseif ($expected_version !== $version) {
                    $out = ['code' => 'stale', 'state' => (string)$escrow['state']];
                } else {
                    bc_gc_refund_debit($vendor_id, (int)$escrow['buyer_id'], (string)$escrow['buyer_username'], $reference, (string)$escrow['gross_ngn'], 'P2P escrow refunded');
                    bc_gc_sql('UPDATE sas_giftcard_inventory SET is_for_sale=1 WHERE vendor_id=? AND id=? AND current_owner_id=?', [$vendor_id, (int)$escrow['card_id'], (int)$escrow['seller_id']]);
                    $version++;
                    bc_gc_sql('UPDATE sas_giftcard_escrows SET state=?, version=?, updated_at=NOW() WHERE vendor_id=? AND id=?', ['refunded', $version, $vendor_id, $escrow_id]);
                    $out = ['code' => 'refunded', 'state' => 'refunded'];
                }
                bc_gc_sql(
                    'INSERT INTO sas_giftcard_escrow_events(vendor_id, escrow_id, event_key, observation_hash, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?)',
                    [$vendor_id, $escrow_id, $event_key, $digest, $out['code'], $out['state'], $version]
                );
                $out['version'] = $version;
                $out['replayed'] = false;
            }
            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            $out['ok'] = in_array($out['code'], ['refunded', 'already_final'], true);
            return $out;
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'conflict', 'refund_has_no_matching_debit', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}
