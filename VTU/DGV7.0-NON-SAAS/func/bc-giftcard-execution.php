<?php
/**
 * Durable gift-card purchase & P2P escrow execution bridge.
 * Fail-closed unless BC_GIFTCARD_WORKER_ENABLED=1.
 * Routes purchases through bc_gc_reserve_order() (state='held') for cron/process_giftcard_orders.php
 * and P2P escrow purchases through bc_gc_p2p_hold() + bc_gc_p2p_release().
 */
require_once __DIR__ . '/bc-giftcard-settlement.php';
require_once __DIR__ . '/bc-giftcard-func.php';

if (!function_exists('bc_gc_execution_enabled')) {
    function bc_gc_execution_enabled() {
        return getenv('BC_GIFTCARD_WORKER_ENABLED') === '1';
    }
}

if (!function_exists('bc_gc_execute_purchase')) {
    function bc_gc_execute_purchase($vendor_id, array $user, $product_id, $face_value, $request_key = '') {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $user_id = (int)($user['id'] ?? 0);
        $product_id = (int)$product_id;
        if (!bc_gc_execution_enabled()) {
            return ['ok' => false, 'code' => 'GIFTCARD_SETTLEMENT_HOLD'];
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/D', (string)$request_key)) {
            $request_key = bin2hex(random_bytes(32));
        }
        $q = $connection_server->prepare('SELECT reloadly_product_id, product_name, currency_code, vendor_markup FROM sas_vendor_giftcard_products WHERE vendor_id=? AND reloadly_product_id=? AND status=1 LIMIT 1');
        if (!$q) return ['ok' => false, 'code' => 'unavailable'];
        $q->bind_param('ii', $vendor_id, $product_id);
        $q->execute();
        $product = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$product) return ['ok' => false, 'code' => 'product_not_found'];

        $cur = strtoupper(trim((string)($product['currency_code'] ?: 'USD')));
        $rate = (float)getLiveExchangeRate($cur, 'NGN', $vendor_id, 'gift-card');
        if (!is_finite($rate) || $rate <= 0) return ['ok' => false, 'code' => 'rate_unavailable'];

        $qv = $connection_server->prepare('SELECT giftcard_fee_percent FROM sas_vendors WHERE id=? LIMIT 1');
        $vendor_fee = 0.0;
        if ($qv) {
            $qv->bind_param('i', $vendor_id);
            $qv->execute();
            $vrow = $qv->get_result()->fetch_assoc();
            $qv->close();
            $vendor_fee = (float)($vrow['giftcard_fee_percent'] ?? 0);
        }
        $total_fee_pct = max(0.0, (float)($product['vendor_markup'] ?? 0) + $vendor_fee);

        return bc_gc_reserve_order(
            $vendor_id,
            $user_id,
            $product_id,
            $face_value,
            $cur,
            sprintf('%.6F', $rate),
            sprintf('%.2F', $total_fee_pct),
            $request_key
        );
    }
}

if (!function_exists('bc_gc_execute_p2p_buy')) {
    function bc_gc_execute_p2p_buy($vendor_id, array $buyer, $card_id, $request_key = '') {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $buyer_id = (int)($buyer['id'] ?? 0);
        $card_id = (int)$card_id;
        if (!bc_gc_execution_enabled()) {
            return ['ok' => false, 'code' => 'GIFTCARD_SETTLEMENT_HOLD'];
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/D', (string)$request_key)) {
            $request_key = bin2hex(random_bytes(32));
        }

        $qv = $connection_server->prepare('SELECT giftcard_fee_percent FROM sas_vendors WHERE id=? LIMIT 1');
        $vendor_fee = 0.0;
        if ($qv) {
            $qv->bind_param('i', $vendor_id);
            $qv->execute();
            $vrow = $qv->get_result()->fetch_assoc();
            $qv->close();
            $vendor_fee = (float)($vrow['giftcard_fee_percent'] ?? 0);
        }

        $held = bc_gc_p2p_hold($vendor_id, $buyer_id, $card_id, sprintf('%.2F', max(0.0, $vendor_fee)), $request_key);
        if (empty($held['ok'])) {
            return $held;
        }
        $ref = (string)$held['reference'];
        $ver = (int)$held['version'];
        $ev_key = hash('sha256', json_encode(['gc_p2p_release', $vendor_id, $ref, $ver], JSON_THROW_ON_ERROR));
        $rel = bc_gc_p2p_release($vendor_id, $ref, $ev_key, $ver);
        return !empty($rel['ok']) ? array_merge($rel, ['reference' => $ref]) : $rel;
    }
}
