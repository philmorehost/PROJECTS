<?php
/**
 * Held server-bound hosted checkout intent lifecycle foundation.
 * Internal library ONLY: no HTTP routes, no runtime DDL, no provider network calls.
 * Preserves PAYMENT_INTENT_REQUIRED on create-checkout.php and payhub-checkout.php
 * until operator migration, provider checkout session wiring, and acceptance testing.
 */

if (!function_exists('bc_chk_sql')) {
    function bc_chk_sql($sql, $params = []) {
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

if (!function_exists('bc_chk_begin')) {
    function bc_chk_begin() {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) throw new RuntimeException('unavailable');
        $auto = bc_chk_sql('SELECT @@session.autocommit AS enabled');
        if ((int)($auto[0]['enabled'] ?? 0) !== 1) throw new RuntimeException('outer_transaction');
        try {
            if (!$connection_server->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')) {
                throw new RuntimeException('outer_transaction');
            }
        } catch (Throwable $e) {
            throw new RuntimeException('outer_transaction');
        }
        $tables = ['sas_users', 'sas_secure_payment_intents', 'sas_checkout_intents', 'sas_checkout_intent_events'];
        $rows = bc_chk_sql("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('" . implode("','", $tables) . "')");
        $engines = [];
        foreach ($rows as $r) $engines[(string)$r['TABLE_NAME']] = strtoupper((string)$r['ENGINE']);
        foreach ($tables as $t) {
            if (!isset($engines[$t])) throw new RuntimeException('schema_unavailable');
            if ($engines[$t] !== 'INNODB') throw new RuntimeException('nontransactional');
        }
        if (!$connection_server->begin_transaction()) throw new RuntimeException('unavailable');
    }
}

if (!function_exists('bc_chk_normalize_amount')) {
    function bc_chk_normalize_amount($value) {
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
}

if (!function_exists('bc_chk_create_intent')) {
    /** Atomically create a server-bound checkout intent paired with sas_secure_payment_intents. */
    function bc_chk_create_intent($vendor_id, $user_id, $provider, $currency, $amount, $ttl_seconds, $request_key) {
        global $connection_server;
        $begun = false;
        $provider = strtolower(trim((string)$provider));
        $currency = strtoupper(trim((string)$currency));
        $norm_amount = bc_chk_normalize_amount($amount);
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($user_id) || $user_id <= 0
            || !in_array($provider, ['flutterwave', 'payhub', 'paystack'], true)
            || !in_array($currency, ['NGN', 'USD', 'GBP', 'EUR', 'GHS', 'KES'], true)
            || $norm_amount === null
            || !is_int($ttl_seconds) || $ttl_seconds < 60 || $ttl_seconds > 86400
            || !is_string($request_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $request_key)) {
            return ['ok' => false, 'code' => 'invalid'];
        }

        $request_key_hash = hash('sha256', $request_key);
        $payload_hash = hash('sha256', json_encode([$vendor_id, $user_id, $provider, $currency, $norm_amount], JSON_THROW_ON_ERROR));

        try {
            bc_chk_begin();
            $begun = true;

            $user = bc_chk_sql(
                'SELECT id, username, status, COALESCE(is_blocked, 0) AS is_blocked, COALESCE(force_password_change, 0) AS force_password_change FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE',
                [$vendor_id, $user_id]
            )[0] ?? null;
            if (!$user) throw new RuntimeException('not_found');
            if ((int)$user['status'] !== 1 || (int)$user['is_blocked'] !== 0 || (int)$user['force_password_change'] !== 0) {
                throw new RuntimeException('restricted');
            }

            $old = bc_chk_sql(
                'SELECT id, payload_hash, reference, state, version, amount, currency, provider, expires_at FROM sas_checkout_intents WHERE vendor_id=? AND user_id=? AND request_key_hash=? LIMIT 1',
                [$vendor_id, $user_id, $request_key_hash]
            )[0] ?? null;
            if ($old) {
                $connection_server->rollback();
                $begun = false;
                if (!hash_equals((string)$old['payload_hash'], $payload_hash)) return ['ok' => false, 'code' => 'conflict'];
                return [
                    'ok' => true, 'code' => 'replayed', 'replayed' => true,
                    'reference' => (string)$old['reference'], 'state' => (string)$old['state'],
                    'version' => (int)$old['version'], 'amount' => (string)$old['amount'],
                    'currency' => (string)$old['currency'], 'provider' => (string)$old['provider'],
                    'expires_at' => (string)$old['expires_at'],
                ];
            }

            $username = strtolower(trim((string)$user['username']));
            $reference = 'CHK-' . strtoupper(bin2hex(random_bytes(14)));

            bc_chk_sql(
                'INSERT INTO sas_secure_payment_intents(reference, vendor_id, username, provider, currency, amount) VALUES(?,?,?,?,?,?)',
                [$reference, $vendor_id, $username, $provider, $currency, $norm_amount]
            );
            bc_chk_sql(
                "INSERT INTO sas_checkout_intents(vendor_id, user_id, username, provider, currency, amount, request_key_hash, payload_hash, reference, state, version, expires_at) VALUES(?,?,?,?,?,?,?,?,?,'created',0,DATE_ADD(NOW(), INTERVAL ? SECOND))",
                [$vendor_id, $user_id, $username, $provider, $currency, $norm_amount, $request_key_hash, $payload_hash, $reference, $ttl_seconds]
            );

            $row = bc_chk_sql('SELECT expires_at FROM sas_checkout_intents WHERE vendor_id=? AND reference=? LIMIT 1', [$vendor_id, $reference])[0] ?? null;
            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            return [
                'ok' => true, 'code' => 'created', 'replayed' => false,
                'reference' => $reference, 'state' => 'created', 'version' => 0,
                'amount' => $norm_amount, 'currency' => $currency, 'provider' => $provider,
                'expires_at' => (string)($row['expires_at'] ?? ''),
            ];
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'restricted', 'conflict', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_chk_attach_provider')) {
    /** Transition created -> provider_pending when hosted checkout session/link is generated. */
    function bc_chk_attach_provider($vendor_id, $reference, $event_key, $provider_reference, $expected_version) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($expected_version) || $expected_version < 0
            || !is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{4,100}\z/D', $reference)
            || !is_string($event_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $event_key)
            || !is_string($provider_reference) || $provider_reference === '' || strlen($provider_reference) > 160
            || !preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $provider_reference)) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $digest = hash('sha256', json_encode(['attach', $provider_reference, $expected_version], JSON_THROW_ON_ERROR));

        try {
            bc_chk_begin();
            $begun = true;
            $intent = bc_chk_sql('SELECT *, (expires_at <= NOW()) AS is_expired FROM sas_checkout_intents WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE', [$vendor_id, $reference])[0] ?? null;
            if (!$intent) throw new RuntimeException('not_found');
            $iid = (int)$intent['id'];

            $ev = bc_chk_sql('SELECT * FROM sas_checkout_intent_events WHERE vendor_id=? AND intent_id=? AND event_key=? LIMIT 1 FOR UPDATE', [$vendor_id, $iid, $event_key])[0] ?? null;
            if ($ev) {
                if (!hash_equals((string)$ev['observation_hash'], $digest)) throw new RuntimeException('conflict');
                $res = ['code' => (string)$ev['disposition'], 'state' => (string)$ev['result_state'], 'version' => (int)$ev['result_version'], 'replayed' => true];
            } else {
                $version = (int)$intent['version'];
                $state = (string)$intent['state'];
                if ($expected_version !== $version) $res = ['code' => 'stale', 'state' => $state];
                elseif ($state !== 'created') $res = ['code' => 'conflict', 'state' => $state];
                elseif ((int)$intent['is_expired'] === 1) {
                    $version++;
                    bc_chk_sql("UPDATE sas_checkout_intents SET state='expired', version=?, updated_at=NOW() WHERE vendor_id=? AND id=?", [$version, $vendor_id, $iid]);
                    $res = ['code' => 'expired', 'state' => 'expired'];
                } else {
                    $version++;
                    bc_chk_sql("UPDATE sas_checkout_intents SET state='provider_pending', provider_reference=?, version=?, updated_at=NOW() WHERE vendor_id=? AND id=?", [$provider_reference, $version, $vendor_id, $iid]);
                    $res = ['code' => 'recorded', 'state' => 'provider_pending'];
                }
                bc_chk_sql('INSERT INTO sas_checkout_intent_events(vendor_id, intent_id, event_key, observation_hash, evidence_hash, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?,?)', [$vendor_id, $iid, $event_key, $digest, hash('sha256', $provider_reference), $res['code'], $res['state'], $version]);
                $res['version'] = $version;
                $res['replayed'] = false;
            }
            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            $res['ok'] = $res['code'] === 'recorded';
            return $res;
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'conflict', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_chk_reconcile_settlement')) {
    /** Bind verified provider settlement strictly to intent provider, currency, and gross amount. */
    function bc_chk_reconcile_settlement($vendor_id, $reference, $event_key, $provider, $currency, $gross_amount, $provider_reference, $evidence_hash, $expected_version) {
        global $connection_server;
        $begun = false;
        $provider = strtolower(trim((string)$provider));
        $currency = strtoupper(trim((string)$currency));
        $norm_gross = bc_chk_normalize_amount($gross_amount);
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($expected_version) || $expected_version < 0
            || !is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{4,100}\z/D', $reference)
            || !is_string($event_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $event_key)
            || !is_string($evidence_hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $evidence_hash)
            || $provider === '' || $currency === '' || $norm_gross === null
            || !is_string($provider_reference) || $provider_reference === '' || strlen($provider_reference) > 160
            || !preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $provider_reference)) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $digest = hash('sha256', json_encode(['settle', $provider, $currency, $norm_gross, $provider_reference, $evidence_hash, $expected_version], JSON_THROW_ON_ERROR));

        try {
            bc_chk_begin();
            $begun = true;
            $intent = bc_chk_sql('SELECT *, (expires_at <= NOW()) AS is_expired FROM sas_checkout_intents WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE', [$vendor_id, $reference])[0] ?? null;
            if (!$intent) throw new RuntimeException('not_found');
            $iid = (int)$intent['id'];

            $ev = bc_chk_sql('SELECT * FROM sas_checkout_intent_events WHERE vendor_id=? AND intent_id=? AND event_key=? LIMIT 1 FOR UPDATE', [$vendor_id, $iid, $event_key])[0] ?? null;
            if ($ev) {
                if (!hash_equals((string)$ev['observation_hash'], $digest)) throw new RuntimeException('conflict');
                $res = ['code' => (string)$ev['disposition'], 'state' => (string)$ev['result_state'], 'version' => (int)$ev['result_version'], 'replayed' => true];
            } else {
                $version = (int)$intent['version'];
                $state = (string)$intent['state'];
                $intent_amt = bc_chk_normalize_amount((string)$intent['amount']);
                $ref_conflict = $intent['provider_reference'] !== null && !hash_equals((string)$intent['provider_reference'], $provider_reference);
                $matches_terms = hash_equals((string)$intent['provider'], $provider)
                    && hash_equals((string)$intent['currency'], $currency)
                    && $intent_amt !== null && hash_equals($intent_amt, $norm_gross)
                    && !$ref_conflict;

                if ($state === 'settled') {
                    $res = $matches_terms ? ['code' => 'already_final', 'state' => 'settled'] : ['code' => 'conflict', 'state' => 'settled'];
                } elseif (!in_array($state, ['created', 'provider_pending'], true)) {
                    $res = ['code' => 'conflict', 'state' => $state];
                } elseif ($expected_version !== $version) {
                    $res = ['code' => 'stale', 'state' => $state];
                } elseif (!$matches_terms || (int)$intent['is_expired'] === 1) {
                    $version++;
                    bc_chk_sql("UPDATE sas_checkout_intents SET state='mismatch_held', version=?, updated_at=NOW() WHERE vendor_id=? AND id=?", [$version, $vendor_id, $iid]);
                    $res = ['code' => !$matches_terms ? 'mismatch' : 'expired_hold', 'state' => 'mismatch_held'];
                } else {
                    $version++;
                    $prov_ref = $intent['provider_reference'] ?? $provider_reference;
                    bc_chk_sql("UPDATE sas_checkout_intents SET state='settled', provider_reference=?, version=?, settled_at=NOW(), updated_at=NOW() WHERE vendor_id=? AND id=?", [$prov_ref, $version, $vendor_id, $iid]);
                    $res = ['code' => 'recorded', 'state' => 'settled'];
                }
                bc_chk_sql('INSERT INTO sas_checkout_intent_events(vendor_id, intent_id, event_key, observation_hash, evidence_hash, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?,?)', [$vendor_id, $iid, $event_key, $digest, $evidence_hash, $res['code'], $res['state'], $version]);
                $res['version'] = $version;
                $res['replayed'] = false;
            }
            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            $res['ok'] = in_array($res['code'], ['recorded', 'already_final'], true);
            return $res;
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'conflict', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_chk_close_intent')) {
    /** Expire (when past expires_at) or cancel an unsettled created/provider_pending checkout intent. */
    function bc_chk_close_intent($vendor_id, $reference, $action, $event_key, $reason_hash, $expected_version) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($expected_version) || $expected_version < 0
            || !in_array($action, ['expire', 'cancel'], true)
            || !is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{4,100}\z/D', $reference)
            || !is_string($event_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $event_key)
            || !is_string($reason_hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $reason_hash)) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $target = $action === 'expire' ? 'expired' : 'cancelled';
        $digest = hash('sha256', json_encode([$target, $reason_hash, $expected_version], JSON_THROW_ON_ERROR));

        try {
            bc_chk_begin();
            $begun = true;
            $intent = bc_chk_sql('SELECT *, (expires_at <= NOW()) AS is_expired FROM sas_checkout_intents WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE', [$vendor_id, $reference])[0] ?? null;
            if (!$intent) throw new RuntimeException('not_found');
            $iid = (int)$intent['id'];

            $ev = bc_chk_sql('SELECT * FROM sas_checkout_intent_events WHERE vendor_id=? AND intent_id=? AND event_key=? LIMIT 1 FOR UPDATE', [$vendor_id, $iid, $event_key])[0] ?? null;
            if ($ev) {
                if (!hash_equals((string)$ev['observation_hash'], $digest)) throw new RuntimeException('conflict');
                $res = ['code' => (string)$ev['disposition'], 'state' => (string)$ev['result_state'], 'version' => (int)$ev['result_version'], 'replayed' => true];
            } else {
                $version = (int)$intent['version'];
                $state = (string)$intent['state'];
                if ($state === $target) $res = ['code' => 'already_final', 'state' => $target];
                elseif (!in_array($state, ['created', 'provider_pending'], true)) $res = ['code' => 'conflict', 'state' => $state];
                elseif ($expected_version !== $version) $res = ['code' => 'stale', 'state' => $state];
                elseif ($action === 'expire' && (int)$intent['is_expired'] !== 1) $res = ['code' => 'not_expired', 'state' => $state];
                else {
                    $version++;
                    bc_chk_sql('UPDATE sas_checkout_intents SET state=?, version=?, updated_at=NOW() WHERE vendor_id=? AND id=?', [$target, $version, $vendor_id, $iid]);
                    $res = ['code' => 'recorded', 'state' => $target];
                }
                bc_chk_sql('INSERT INTO sas_checkout_intent_events(vendor_id, intent_id, event_key, observation_hash, evidence_hash, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?,?)', [$vendor_id, $iid, $event_key, $digest, $reason_hash, $res['code'], $res['state'], $version]);
                $res['version'] = $version;
                $res['replayed'] = false;
            }
            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            $res['ok'] = in_array($res['code'], ['recorded', 'already_final'], true);
            return $res;
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'conflict', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}
