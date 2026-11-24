<?php
/**
 * Connects verified service intake (IntlTransfer, Tuition, POSRequest)
 * to the durable service attempt journal (sas_service_attempts, state='held')
 * and capability matrix (sas_service_capability_matrix).
 * Never executes a provider call directly; execution remains gated on
 * BC_SERVICE_DISPATCH_WORKER_ENABLED=1 + capability matrix verification.
 */
require_once __DIR__ . '/bc-service-attempt-journal.php';
require_once __DIR__ . '/bc-service-dispatch-lease.php';

if (!function_exists('bc_svc_enqueue_held_attempt')) {
    function bc_svc_enqueue_held_attempt($vendor_id, $reference) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $reference = trim((string)$reference);
        if ($vendor_id <= 0 || $reference === '' || !($connection_server instanceof mysqli)) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $auto = $connection_server->query('SELECT @@session.autocommit AS enabled');
        if ($auto) {
            $rowAuto = $auto->fetch_assoc();
            if ((int)($rowAuto['enabled'] ?? 0) !== 1) {
                return ['ok' => false, 'code' => 'outer_transaction'];
            }
        }
        $tbls = $connection_server->query("SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('sas_service_requests','sas_service_attempts','sas_service_attempt_events')");
        $cnt = $tbls ? (int)($tbls->fetch_assoc()['cnt'] ?? 0) : 0;
        if ($cnt !== 3) {
            return ['ok' => false, 'code' => 'schema_unavailable'];
        }
        $q = $connection_server->prepare('SELECT id FROM sas_service_requests WHERE vendor_id=? AND reference=? LIMIT 1');
        if (!$q) return ['ok' => false, 'code' => 'unavailable'];
        $q->bind_param('is', $vendor_id, $reference);
        $q->execute();
        $req = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$req) return ['ok' => false, 'code' => 'not_found'];
        return bc_saj_hold($vendor_id, (int)$req['id']);
    }
}

if (!function_exists('bc_sdl_set_capability')) {
    function bc_sdl_set_capability($vendor_id, $service_type, $provider, $capability_key, $enabled, $evidence_ref = '') {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $service_type = trim((string)$service_type);
        $provider = trim((string)$provider);
        $capability_key = trim((string)$capability_key);
        $enabled = $enabled ? 1 : 0;
        $evidence_ref = trim((string)$evidence_ref);
        if ($vendor_id <= 0 || $service_type === '' || $provider === '' || $capability_key === '' || !($connection_server instanceof mysqli)) {
            return ['ok' => false, 'code' => 'invalid'];
        }
        $stmt = $connection_server->prepare(
            'INSERT INTO sas_service_capability_matrix(vendor_id, service_type, provider, capability_key, enabled, verified_evidence_ref) VALUES(?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), verified_evidence_ref=VALUES(verified_evidence_ref), updated_at=NOW()'
        );
        if (!$stmt) return ['ok' => false, 'code' => 'unavailable'];
        $stmt->bind_param('isssis', $vendor_id, $service_type, $provider, $capability_key, $enabled, $evidence_ref);
        $ok = $stmt->execute();
        $stmt->close();
        return ['ok' => $ok, 'code' => $ok ? 'saved' : 'unavailable'];
    }
}
