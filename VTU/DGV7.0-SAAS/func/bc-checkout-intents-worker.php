<?php
/**
 * Held checkout-intent expiry worker bridge.
 * Never runs unless BC_CHECKOUT_EXPIRE_ENABLED=1.
 * Closes only expired created/provider_pending intents via bc_chk_close_intent;
 * never modifies settled/mismatch_held intents or lifts PAYMENT_INTENT_REQUIRED.
 */
require_once __DIR__ . '/bc-checkout-intents.php';

if (!function_exists('bc_chk_expire_worker_enabled')) {
    function bc_chk_expire_worker_enabled() {
        return getenv('BC_CHECKOUT_EXPIRE_ENABLED') === '1';
    }
}

if (!function_exists('bc_chk_run_expire_worker')) {
    function bc_chk_run_expire_worker($vendor_id, $limit = 25) {
        if (!bc_chk_expire_worker_enabled()) {
            return ['ok' => false, 'code' => 'worker_disabled', 'processed' => 0];
        }
        if (!is_int($vendor_id) || $vendor_id <= 0 || !is_int($limit) || $limit < 1 || $limit > 200) {
            return ['ok' => false, 'code' => 'invalid', 'processed' => 0];
        }

        $rows = bc_chk_sql(
            "SELECT reference, version FROM sas_checkout_intents WHERE vendor_id=? AND state IN ('created','provider_pending') AND expires_at <= NOW() ORDER BY expires_at ASC LIMIT $limit",
            [$vendor_id]
        );
        $results = [];
        foreach ($rows as $row) {
            $ref = (string)$row['reference'];
            $ver = (int)$row['version'];
            $reasonHash = hash('sha256', json_encode(['ttl_expired', $vendor_id, $ref, $ver], JSON_THROW_ON_ERROR));
            $eventKey   = hash('sha256', json_encode(['expire_worker', $vendor_id, $ref, $ver], JSON_THROW_ON_ERROR));
            $res = bc_chk_close_intent($vendor_id, $ref, 'expire', $eventKey, $reasonHash, $ver);
            $results[] = [
                'reference' => $ref,
                'ok'        => !empty($res['ok']),
                'code'      => $res['code'] ?? 'unconfirmed',
                'state'     => $res['state'] ?? null,
            ];
        }
        return ['ok' => true, 'code' => 'completed', 'processed' => count($results), 'results' => $results];
    }
}
