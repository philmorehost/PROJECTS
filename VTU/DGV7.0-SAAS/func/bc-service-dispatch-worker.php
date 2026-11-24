<?php
/**
 * Held service-execution dispatch worker & crash-recovery reaper.
 * Dispatch requires BC_SERVICE_DISPATCH_WORKER_ENABLED=1.
 * Crash reaping moves expired active leases to 'unknown' without retry.
 */
require_once __DIR__ . '/bc-service-dispatch-lease.php';

if (!function_exists('bc_sdl_worker_enabled')) {
    function bc_sdl_worker_enabled() {
        return getenv('BC_SERVICE_DISPATCH_WORKER_ENABLED') === '1';
    }
}

if (!function_exists('bc_sdl_run_reaper')) {
    function bc_sdl_run_reaper($vendor_id, $limit = 25) {
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($limit) || $limit < 1 || $limit > 200) {
            return ['ok' => false, 'code' => 'invalid', 'reaped' => 0];
        }
        $rows = bc_sdl_sql(
            "SELECT request_id, lease_token FROM sas_service_dispatch_leases WHERE vendor_id=? AND lease_state='active' AND expires_at <= NOW() ORDER BY request_id ASC LIMIT $limit",
            [$vendor_id]
        );
        $results = [];
        foreach ($rows as $row) {
            $reqId = (int)$row['request_id'];
            $evKey = hash('sha256', json_encode(['reap', $vendor_id, $reqId, (string)$row['lease_token']], JSON_THROW_ON_ERROR));
            $r = bc_sdl_reap_expired_lease($vendor_id, $reqId, $evKey);
            $results[] = ['request_id' => $reqId, 'ok' => !empty($r['ok']), 'code' => $r['code'] ?? ''];
        }
        return ['ok' => true, 'code' => 'reaped', 'reaped' => count($results), 'results' => $results];
    }
}

if (!function_exists('bc_sdl_run_worker')) {
    function bc_sdl_run_worker($vendor_id, $worker_id, callable $transport, $ttl_seconds = 60, $limit = 10) {
        if (!bc_sdl_worker_enabled()) {
            return ['ok' => false, 'code' => 'worker_disabled', 'processed' => 0];
        }
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($limit) || $limit < 1 || $limit > 100) {
            return ['ok' => false, 'code' => 'invalid', 'processed' => 0];
        }

        $rows = bc_sdl_sql(
            "SELECT a.request_id, a.version, r.type, r.provider, r.payload FROM sas_service_attempts a JOIN sas_service_requests r ON r.id = a.request_id AND r.vendor_id = a.vendor_id WHERE a.vendor_id=? AND a.state='held' ORDER BY a.request_id ASC LIMIT $limit",
            [$vendor_id]
        );
        $results = [];
        foreach ($rows as $row) {
            $reqId = (int)$row['request_id'];
            $ver   = (int)$row['version'];
            $lease = bc_sdl_acquire_lease($vendor_id, $reqId, $worker_id, $ttl_seconds, $ver);
            if (empty($lease['ok'])) {
                $results[] = ['request_id' => $reqId, 'ok' => false, 'code' => $lease['code']];
                continue;
            }
            $outcome = 'unknown';
            $provRef = '';
            $evPayload = [];
            try {
                $resp = $transport($row, $lease);
                $cand = (string)($resp['outcome'] ?? 'unknown');
                if (in_array($cand, ['confirmed_success', 'confirmed_failure', 'unknown'], true)) {
                    $outcome = $cand;
                }
                $provRef = (string)($resp['provider_reference'] ?? '');
                $evPayload = is_array($resp) ? $resp : ['raw' => (string)$resp];
            } catch (Throwable $e) {
                $outcome = 'unknown';
                $evPayload = ['exception' => $e->getMessage()];
            }
            $evHash = hash('sha256', json_encode($evPayload, JSON_THROW_ON_ERROR));
            $comp = bc_sdl_complete_lease($vendor_id, $reqId, (string)$lease['lease_token'], $outcome, $evHash, $provRef);
            $results[] = ['request_id' => $reqId, 'ok' => !empty($comp['ok']), 'state' => $comp['state'] ?? 'unknown'];
        }
        return ['ok' => true, 'code' => 'completed', 'processed' => count($results), 'results' => $results];
    }
}
