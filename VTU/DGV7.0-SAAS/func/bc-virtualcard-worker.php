<?php
/**
 * Held virtual-card dispatch worker bridge.
 * Never runs unless BC_VIRTUALCARD_WORKER_ENABLED=1.
 * Atomically transitions held -> dispatched BEFORE any external provider call;
 * timeouts/exceptions record 'unknown' and never auto-refund or retry.
 */
require_once __DIR__ . '/bc-virtualcard-settlement.php';

if (!function_exists('bc_vc_worker_enabled')) {
    function bc_vc_worker_enabled() {
        return getenv('BC_VIRTUALCARD_WORKER_ENABLED') === '1';
    }
}

if (!function_exists('bc_vc_claim_dispatch')) {
    function bc_vc_claim_dispatch($vendor_id, $reference, $expected_version) {
        global $connection_server;
        $begun = false;
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($expected_version) || $expected_version < 0
            || !is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{4,64}\z/D', $reference)) {
            return ['ok' => false, 'code' => 'invalid'];
        }

        $event_key = hash('sha256', json_encode(['vc_claim_dispatch', $vendor_id, $reference, $expected_version], JSON_THROW_ON_ERROR));
        $ev_hash   = hash('sha256', json_encode(['dispatched', $expected_version], JSON_THROW_ON_ERROR));
        $obs_hash  = hash('sha256', json_encode(['dispatched', $ev_hash, '', $expected_version], JSON_THROW_ON_ERROR));

        try {
            bc_vc_begin();
            $begun = true;

            $ord = bc_vc_sql(
                'SELECT * FROM sas_virtualcard_orders WHERE vendor_id=? AND reference=? LIMIT 1 FOR UPDATE',
                [$vendor_id, $reference]
            )[0] ?? null;
            if (!$ord) throw new RuntimeException('not_found');
            if ((string)$ord['state'] !== 'held') throw new RuntimeException('not_held');
            if ((int)$ord['version'] !== $expected_version) throw new RuntimeException('stale');

            $new_version = $expected_version + 1;
            bc_vc_sql(
                "UPDATE sas_virtualcard_orders SET state='dispatched', version=?, updated_at=NOW() WHERE vendor_id=? AND id=? AND state='held'",
                [$new_version, $vendor_id, (int)$ord['id']]
            );
            bc_vc_sql(
                "INSERT INTO sas_virtualcard_order_events(vendor_id, order_id, event_key, observation_hash, evidence_hash, observed_outcome, observed_reference, disposition, result_state, result_version) VALUES(?,?,?,?,?,'dispatched','','claimed_dispatch','dispatched',?)",
                [$vendor_id, (int)$ord['id'], $event_key, $obs_hash, $ev_hash, $new_version]
            );

            if (!$connection_server->commit()) throw new RuntimeException('unconfirmed');
            $begun = false;
            return ['ok' => true, 'code' => 'dispatched', 'version' => $new_version, 'order' => $ord];
        } catch (Throwable $e) {
            if ($begun) try { $connection_server->rollback(); } catch (Throwable $ignored) {}
            $known = ['not_found', 'not_held', 'stale', 'outer_transaction', 'schema_unavailable', 'nontransactional'];
            return ['ok' => false, 'code' => in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'unconfirmed'];
        }
    }
}

if (!function_exists('bc_vc_run_worker')) {
    function bc_vc_run_worker($vendor_id, callable $transport, $limit = 10) {
        if (!bc_vc_worker_enabled()) {
            return ['ok' => false, 'code' => 'worker_disabled', 'processed' => 0];
        }
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($limit) || $limit < 1 || $limit > 100) {
            return ['ok' => false, 'code' => 'invalid', 'processed' => 0];
        }

        $rows = bc_vc_sql(
            "SELECT reference, version FROM sas_virtualcard_orders WHERE vendor_id=? AND state='held' ORDER BY id ASC LIMIT $limit",
            [$vendor_id]
        );
        $results = [];
        foreach ($rows as $row) {
            $ref = (string)$row['reference'];
            $ver = (int)$row['version'];
            $claim = bc_vc_claim_dispatch($vendor_id, $ref, $ver);
            if (empty($claim['ok'])) {
                $results[] = ['reference' => $ref, 'ok' => false, 'code' => $claim['code']];
                continue;
            }
            $dispVer = (int)$claim['version'];
            $outcome = 'unknown';
            $provRef = '';
            $cardPayload = [];
            $evidencePayload = [];
            try {
                $resp = $transport($claim['order']);
                $cand = (string)($resp['outcome'] ?? 'unknown');
                if (in_array($cand, ['unknown', 'confirmed_success', 'confirmed_failure'], true)) {
                    $outcome = $cand;
                }
                $provRef = (string)($resp['provider_reference'] ?? '');
                $cardPayload = is_array($resp['card_payload'] ?? null) ? $resp['card_payload'] : [];
                $evidencePayload = is_array($resp) ? $resp : ['raw' => (string)$resp];
            } catch (Throwable $e) {
                $outcome = 'unknown';
                $evidencePayload = ['error' => $e->getMessage()];
            }
            $evHash = hash('sha256', json_encode($evidencePayload, JSON_THROW_ON_ERROR));
            $evKey  = hash('sha256', json_encode(['vc_worker_observe', $vendor_id, $ref, $dispVer], JSON_THROW_ON_ERROR));
            $obs    = bc_vc_observe_outcome($vendor_id, $ref, $evKey, $outcome, $evHash, $provRef, $dispVer, $cardPayload);
            $results[] = [
                'reference' => $ref,
                'ok'        => !empty($obs['ok']),
                'code'      => $obs['code'] ?? 'unconfirmed',
                'state'     => $obs['state'] ?? 'unknown',
            ];
        }
        return ['ok' => true, 'code' => 'completed', 'processed' => count($results), 'results' => $results];
    }
}
