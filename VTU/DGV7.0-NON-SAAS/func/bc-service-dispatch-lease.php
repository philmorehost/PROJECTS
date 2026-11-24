<?php
/**
 * Held service-execution capability gate, exclusive worker lease, and crash-recovery reaper.
 * Internal library ONLY: no HTTP routes, no runtime DDL, no provider network calls.
 * Preserves intake-only service pages and never auto-retries expired or unknown leases.
 */
require_once __DIR__ . '/bc-service-attempt-journal.php';

if (!function_exists('bc_sdl_sql')) {
    function bc_sdl_sql($sql, $params = []) {
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

if (!function_exists('bc_sdl_begin')) {
    function bc_sdl_begin() {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) throw new RuntimeException('unavailable');
        $auto = bc_sdl_sql('SELECT @@session.autocommit AS enabled');
        if ((int)($auto[0]['enabled'] ?? 0) !== 1) throw new RuntimeException('outer_transaction');
        try {
            if (!$connection_server->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')) {
                throw new RuntimeException('outer_transaction');
            }
        } catch (Throwable $e) {
            throw new RuntimeException('outer_transaction');
        }
        $tables = [
            'sas_vendors', 'sas_users', 'sas_service_requests',
            'sas_service_attempts', 'sas_service_attempt_events',
            'sas_service_capability_matrix', 'sas_service_dispatch_leases',
        ];
        $rows = bc_sdl_sql("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('" . implode("','", $tables) . "')");
        $engines = [];
        foreach ($rows as $r) $engines[(string)$r['TABLE_NAME']] = strtoupper((string)$r['ENGINE']);
        foreach ($tables as $t) {
            if (!isset($engines[$t])) throw new RuntimeException('schema_unavailable');
            if ($engines[$t] !== 'INNODB') throw new RuntimeException('nontransactional');
        }
        if (!$connection_server->begin_transaction()) throw new RuntimeException('unavailable');
    }
}

if (!function_exists('bc_sdl_payload_currency')) {
    function bc_sdl_payload_currency($payload_json) {
        try {
            $data = json_decode((string)$payload_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return '';
        }
        if (!is_array($data)) return '';
        $cur = strtoupper(trim((string)($data['currency'] ?? 'USD')));
        return preg_match('/\A[A-Z]{3}\z/D', $cur) ? $cur : '';
    }
}

if (!function_exists('bc_sdl_acquire_lease')) {
    /**
     * Atomically acquire an exclusive one-shot worker dispatch lease for a held attempt.
     * Requires active vendor, active/unblocked user, matching fingerprint, verified
     * capability matrix entry, and no prior lease for (vendor_id, request_id).
     */
    function bc_sdl_acquire_lease($vendor_id, $request_id, $worker_id, $ttl_seconds, $expected_version) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($request_id) || $request_id <= 0
            || !is_int($expected_version) || $expected_version < 0
            || !is_string($worker_id) || !preg_match('/\A[A-Za-z0-9_.:-]{3,96}\z/D', $worker_id)
            || !is_int($ttl_seconds) || $ttl_seconds < 10 || $ttl_seconds > 600) {
            return ['ok' => false, 'code' => 'invalid'];
        }

        try {
            bc_sdl_begin();
            $begun = true;

            $req = bc_sdl_sql('SELECT * FROM sas_service_requests WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE', [$vendor_id, $request_id])[0] ?? null;
            $att = bc_sdl_sql('SELECT * FROM sas_service_attempts WHERE vendor_id=? AND request_id=? LIMIT 1 FOR UPDATE', [$vendor_id, $request_id])[0] ?? null;
            if (!$req || !$att) throw new RuntimeException('not_found');

            if (!hash_equals((string)$att['input_hash'], bc_saj_fingerprint($req))) {
                throw new RuntimeException('conflict');
            }
            if ((string)$att['state'] !== 'held') throw new RuntimeException('not_held');
            if ((int)$att['version'] !== $expected_version) throw new RuntimeException('stale');

            $vendor = bc_sdl_sql('SELECT id, status FROM sas_vendors WHERE id=? LIMIT 1 FOR UPDATE', [$vendor_id])[0] ?? null;
            $user   = bc_sdl_sql('SELECT id, status, COALESCE(is_blocked, 0) AS is_blocked, COALESCE(force_password_change, 0) AS force_password_change FROM sas_users WHERE vendor_id=? AND id=? LIMIT 1 FOR UPDATE', [$vendor_id, (int)$req['user_id']])[0] ?? null;
            if (!$vendor || !$user) throw new RuntimeException('not_found');
            if ((int)$vendor['status'] !== 1 || (int)$user['status'] !== 1 || (int)$user['is_blocked'] !== 0 || (int)$user['force_password_change'] !== 0) {
                throw new RuntimeException('restricted');
            }

            $currency = bc_sdl_payload_currency((string)$req['payload']);
            if ($currency === '') throw new RuntimeException('capability_denied');
            $cap = bc_sdl_sql(
                'SELECT is_authorized, idempotency_verified, status_query_verified FROM sas_service_capability_matrix WHERE vendor_id=? AND service_type=? AND provider=? AND currency=? LIMIT 1 FOR UPDATE',
                [$vendor_id, (string)$req['type'], (string)$req['provider'], $currency]
            )[0] ?? null;
            if (!$cap || (int)$cap['is_authorized'] !== 1 || (int)$cap['idempotency_verified'] !== 1 || (int)$cap['status_query_verified'] !== 1) {
                throw new RuntimeException('capability_denied');
            }

            $existingLease = bc_sdl_sql('SELECT lease_state FROM sas_service_dispatch_leases WHERE vendor_id=? AND request_id=? LIMIT 1 FOR UPDATE', [$vendor_id, $request_id])[0] ?? null;
            if ($existingLease) throw new RuntimeException('already_leased');

            $lease_token = bin2hex(random_bytes(32));
            $new_version = $expected_version + 1;
            $event_key   = hash('sha256', json_encode(['lease', $vendor_id, $request_id, $new_version], JSON_THROW_ON_ERROR));
            $ev_hash     = hash('sha256', json_encode([$worker_id, $lease_token], JSON_THROW_ON_ERROR));
            $obs_hash    = hash('sha256', json_encode(['in_flight', $ev_hash, '', $expected_version], JSON_THROW_ON_ERROR));

            bc_sdl_sql("UPDATE sas_service_attempts SET state='in_flight', version=?, updated_at=NOW() WHERE vendor_id=? AND request_id=?", [$new_version, $vendor_id, $request_id]);
            bc_sdl_sql(
                "INSERT INTO sas_service_attempt_events(vendor_id, request_id, event_key, observation_hash, evidence_hash, observed_outcome, observed_reference, disposition, result_state, result_version) VALUES(?,?,?,?,?,'in_flight','','leased','in_flight',?)",
                [$vendor_id, $request_id, $event_key, $obs_hash, $ev_hash, $new_version]
            );
            bc_sdl_sql(
                "INSERT INTO sas_service_dispatch_leases(vendor_id, request_id, worker_id, lease_token, attempt_version, lease_state, expires_at) VALUES(?,?,?,?,?,'active',DATE_ADD(NOW(), INTERVAL ? SECOND))",
                [$vendor_id, $request_id, $worker_id, $lease_token, $new_version, $ttl_seconds]
            );

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            return [
                'ok' => true, 'code' => 'leased',
                'lease_token' => $lease_token,
                'provider_key' => (string)$att['provider_key'],
                'state' => 'in_flight', 'version' => $new_version,
            ];
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'conflict', 'not_held', 'stale', 'restricted', 'capability_denied', 'already_leased', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_sdl_complete_lease')) {
    /** Complete an active non-expired lease and record verified outcome atomically. */
    function bc_sdl_complete_lease($vendor_id, $request_id, $lease_token, $outcome, $evidence_hash, $provider_reference = '') {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($request_id) || $request_id <= 0
            || !is_string($lease_token) || !preg_match('/\A[a-f0-9]{64}\z/D', $lease_token)
            || !in_array($outcome, ['unknown', 'confirmed_success', 'confirmed_failure'], true)
            || !is_string($evidence_hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $evidence_hash)
            || !is_string($provider_reference) || strlen($provider_reference) > 160
            || ($provider_reference !== '' && !preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $provider_reference))) {
            return ['ok' => false, 'code' => 'invalid'];
        }

        try {
            bc_sdl_begin();
            $begun = true;

            $att   = bc_sdl_sql('SELECT * FROM sas_service_attempts WHERE vendor_id=? AND request_id=? LIMIT 1 FOR UPDATE', [$vendor_id, $request_id])[0] ?? null;
            $lease = bc_sdl_sql('SELECT *, (expires_at <= NOW()) AS is_expired FROM sas_service_dispatch_leases WHERE vendor_id=? AND request_id=? LIMIT 1 FOR UPDATE', [$vendor_id, $request_id])[0] ?? null;
            if (!$att || !$lease) throw new RuntimeException('not_found');
            if (!hash_equals((string)$lease['lease_token'], $lease_token)) throw new RuntimeException('conflict');
            if ((string)$lease['lease_state'] !== 'active') throw new RuntimeException('lease_closed');
            if ((int)$lease['is_expired'] === 1) throw new RuntimeException('lease_expired');
            if ((string)$att['state'] !== 'in_flight') throw new RuntimeException('conflict');

            $prov_ref = $att['provider_reference'];
            if ($prov_ref !== null && $provider_reference !== '' && !hash_equals((string)$prov_ref, $provider_reference)) {
                throw new RuntimeException('conflict');
            }
            if ($prov_ref === null && $provider_reference !== '') $prov_ref = $provider_reference;

            $old_version = (int)$att['version'];
            $new_version = $old_version + 1;
            $event_key   = hash('sha256', json_encode(['lease_complete', $lease_token, $outcome], JSON_THROW_ON_ERROR));
            $obs_hash    = hash('sha256', json_encode([$outcome, $evidence_hash, $provider_reference, $old_version], JSON_THROW_ON_ERROR));
            $next_lease  = $outcome === 'unknown' ? 'expired_unknown' : 'completed';

            bc_sdl_sql('UPDATE sas_service_attempts SET state=?, provider_reference=?, version=?, updated_at=NOW() WHERE vendor_id=? AND request_id=?', [$outcome, $prov_ref, $new_version, $vendor_id, $request_id]);
            bc_sdl_sql(
                "INSERT INTO sas_service_attempt_events(vendor_id, request_id, event_key, observation_hash, evidence_hash, observed_outcome, observed_reference, disposition, result_state, result_version) VALUES(?,?,?,?,?,?,?,'recorded',?,?)",
                [$vendor_id, $request_id, $event_key, $obs_hash, $evidence_hash, $outcome, $provider_reference, $outcome, $new_version]
            );
            bc_sdl_sql('UPDATE sas_service_dispatch_leases SET lease_state=?, updated_at=NOW() WHERE vendor_id=? AND request_id=?', [$next_lease, $vendor_id, $request_id]);

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            return ['ok' => true, 'code' => 'recorded', 'state' => $outcome, 'version' => $new_version];
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'conflict', 'lease_closed', 'lease_expired', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_sdl_reap_expired_lease')) {
    /** Crash-recovery reaper: moves expired active leases to 'unknown', NEVER back to 'held'. */
    function bc_sdl_reap_expired_lease($vendor_id, $request_id, $event_key) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($request_id) || $request_id <= 0
            || !is_string($event_key) || !preg_match('/\A[a-f0-9]{64}\z/D', $event_key)) {
            return ['ok' => false, 'code' => 'invalid'];
        }

        try {
            bc_sdl_begin();
            $begun = true;

            $att   = bc_sdl_sql('SELECT * FROM sas_service_attempts WHERE vendor_id=? AND request_id=? LIMIT 1 FOR UPDATE', [$vendor_id, $request_id])[0] ?? null;
            $lease = bc_sdl_sql('SELECT *, (expires_at <= NOW()) AS is_expired FROM sas_service_dispatch_leases WHERE vendor_id=? AND request_id=? LIMIT 1 FOR UPDATE', [$vendor_id, $request_id])[0] ?? null;
            if (!$att || !$lease) throw new RuntimeException('not_found');

            if ((string)$lease['lease_state'] !== 'active') throw new RuntimeException('lease_closed');
            if ((int)$lease['is_expired'] !== 1) throw new RuntimeException('not_expired');
            if ((string)$att['state'] !== 'in_flight') throw new RuntimeException('conflict');

            $old_version = (int)$att['version'];
            $new_version = $old_version + 1;
            $ev_hash  = hash('sha256', json_encode(['lease_expired', (string)$lease['lease_token']], JSON_THROW_ON_ERROR));
            $obs_hash = hash('sha256', json_encode(['unknown', $ev_hash, '', $old_version], JSON_THROW_ON_ERROR));

            bc_sdl_sql("UPDATE sas_service_attempts SET state='unknown', version=?, updated_at=NOW() WHERE vendor_id=? AND request_id=?", [$new_version, $vendor_id, $request_id]);
            bc_sdl_sql(
                "INSERT INTO sas_service_attempt_events(vendor_id, request_id, event_key, observation_hash, evidence_hash, observed_outcome, observed_reference, disposition, result_state, result_version) VALUES(?,?,?,?,?,'unknown','','lease_expired','unknown',?)",
                [$vendor_id, $request_id, $event_key, $obs_hash, $ev_hash, $new_version]
            );
            bc_sdl_sql("UPDATE sas_service_dispatch_leases SET lease_state='expired_unknown', updated_at=NOW() WHERE vendor_id=? AND request_id=?", [$vendor_id, $request_id]);

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            return ['ok' => true, 'code' => 'reaped_unknown', 'state' => 'unknown', 'version' => $new_version];
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'lease_closed', 'not_expired', 'conflict', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}
