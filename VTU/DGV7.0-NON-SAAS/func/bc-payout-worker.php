<?php
/**
 * Held payout reservation dispatch worker bridge.
 * Never runs unless BC_PAYOUT_WORKER_ENABLED=1.
 * Transitions held -> dispatched BEFORE any external provider call;
 * transport timeouts/exceptions record 'unknown' and never auto-refund or retry.
 */
require_once __DIR__ . '/bc-payout-reservations.php';
require_once __DIR__ . '/bc-worker-http.php';

if (!function_exists('bc_payout_worker_enabled')) {
    function bc_payout_worker_enabled() {
        return getenv('BC_PAYOUT_WORKER_ENABLED') === '1';
    }
}

if (!function_exists('bc_payout_claim_dispatch')) {
    function bc_payout_claim_dispatch($vendor_id, $reference, $expected_version) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($expected_version) || $expected_version < 0
            || !is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{4,64}\z/D', $reference)) {
            return ['ok' => false, 'code' => 'invalid'];
        }

        $event_key = hash('sha256', json_encode(['claim_dispatch', $vendor_id, $reference, $expected_version], JSON_THROW_ON_ERROR));
        $ev_hash   = hash('sha256', json_encode(['dispatched', $expected_version], JSON_THROW_ON_ERROR));
        $obs_hash  = hash('sha256', json_encode(['dispatched', $ev_hash, '', $expected_version], JSON_THROW_ON_ERROR));

        try {
            bc_payout_begin();
            $begun = true;

            $res = bc_payout_sql(
                'SELECT * FROM sas_payout_reservations WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE',
                [$vendor_id, $reference]
            )[0] ?? null;
            if (!$res) throw new RuntimeException('not_found');
            if ((string)$res['state'] !== 'held') throw new RuntimeException('not_held');
            if ((int)$res['version'] !== $expected_version) throw new RuntimeException('stale');

            $new_version = $expected_version + 1;
            bc_payout_sql(
                "UPDATE sas_payout_reservations SET state='dispatched', version=?, updated_at=NOW() WHERE vendor_id=? AND id=? AND state='held'",
                [$new_version, $vendor_id, (int)$res['id']]
            );
            bc_payout_sql(
                "INSERT INTO sas_payout_reservation_events(vendor_id, reservation_id, event_key, observation_hash, evidence_hash, observed_outcome, observed_reference, disposition, result_state, result_version) VALUES(?,?,?,?,?,'dispatched','','claimed_dispatch','dispatched',?)",
                [$vendor_id, (int)$res['id'], $event_key, $obs_hash, $ev_hash, $new_version]
            );

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            return ['ok' => true, 'code' => 'dispatched', 'version' => $new_version, 'reservation' => $res];
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'not_held', 'stale', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_payout_run_worker')) {
    function bc_payout_run_worker($vendor_id, callable $transport, $limit = 10) {
        if (!bc_payout_worker_enabled()) {
            return ['ok' => false, 'code' => 'worker_disabled', 'processed' => 0];
        }
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($limit) || $limit < 1 || $limit > 100) {
            return ['ok' => false, 'code' => 'invalid', 'processed' => 0];
        }

        $rows = bc_payout_sql(
            "SELECT reference, version FROM sas_payout_reservations WHERE vendor_id=? AND state='held' ORDER BY id ASC LIMIT $limit",
            [$vendor_id]
        );
        $results = [];
        foreach ($rows as $row) {
            $ref = (string)$row['reference'];
            $ver = (int)$row['version'];
            $claim = bc_payout_claim_dispatch($vendor_id, $ref, $ver);
            if (empty($claim['ok'])) {
                $results[] = ['reference' => $ref, 'ok' => false, 'code' => $claim['code']];
                continue;
            }
            $dispVer = (int)$claim['version'];
            $outcome = 'unknown';
            $provRef = '';
            $evidencePayload = [];
            try {
                $resp = $transport($claim['reservation']);
                $cand = (string)($resp['outcome'] ?? 'unknown');
                if (in_array($cand, ['confirmed_success', 'confirmed_failure', 'unknown'], true)) {
                    $outcome = $cand;
                }
                $provRef = (string)($resp['provider_reference'] ?? '');
                $evidencePayload = is_array($resp) ? $resp : ['raw' => (string)$resp];
            } catch (Throwable $e) {
                $outcome = 'unknown';
                $evidencePayload = ['exception' => $e->getMessage()];
            }
            $evKey  = hash('sha256', json_encode(['worker_obs', $vendor_id, $ref, $dispVer], JSON_THROW_ON_ERROR));
            $evHash = hash('sha256', json_encode($evidencePayload, JSON_THROW_ON_ERROR));
            $obs = bc_payout_observe_outcome($vendor_id, $ref, $evKey, $outcome, $evHash, $provRef, $dispVer);
            $results[] = ['reference' => $ref, 'ok' => !empty($obs['ok']), 'state' => $obs['state'] ?? 'unknown'];
        }
        return ['ok' => true, 'code' => 'completed', 'processed' => count($results), 'results' => $results];
    }
}

if (!function_exists('bc_payout_make_http_transport')) {
    function bc_payout_make_http_transport($endpointUrl, $hmacSecret) {
        $sender = bc_worker_make_signed_json_transport($endpointUrl, $hmacSecret);
        return static function (array $reservation) use ($sender) {
            $ref = (string)($reservation['reference'] ?? '');
            $payload = [
                'action'         => 'payout_dispatch',
                'vendor_id'      => (int)($reservation['vendor_id'] ?? 0),
                'user_id'        => (int)($reservation['user_id'] ?? 0),
                'reference'      => $ref,
                'amount_minor'   => (string)($reservation['amount_minor'] ?? '0'),
                'currency'       => (string)($reservation['currency'] ?? 'NGN'),
                'channel'        => (string)($reservation['channel'] ?? ''),
                'destination_id' => (string)($reservation['destination_hash'] ?? ''),
            ];
            $resp = $sender($payload, $ref);
            $cand = (string)($resp['outcome'] ?? 'unknown');
            if (!in_array($cand, ['confirmed_success', 'confirmed_failure', 'unknown'], true)) {
                $cand = 'unknown';
            }
            return [
                'outcome'            => $cand,
                'provider_reference' => (string)($resp['provider_reference'] ?? ($resp['provider_ref'] ?? '')),
                'error'              => (string)($resp['error'] ?? ''),
            ];
        };
    }
}
