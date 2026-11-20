<?php
/**
 * DGV7 gateway retirement policy.
 *
 * WHY
 *   Plisio.net is defunct. Its API is still referenced by the crypto hub, the payment gateway
 *   settings, the super-admin marketplace and the `users-plisio.php` webhook. Leaving it
 *   reachable means a customer can start a crypto deposit against a dead provider and never be
 *   credited.
 *
 * WHAT THIS DOES NOT DO
 *   - It does not delete the gateway row, its keys, `sas_vendors.plisio_activated`, the
 *     `plisio_tx_id` column, or any transaction. Historical records must stay auditable.
 *   - It does not rewrite existing rows, so nothing is unrecoverable.
 *   - It does not pretend a replacement exists. Klasha/Flutterwave adapters are Phase 3 and are
 *     not implemented; the crypto hub is refused with a clear message until then.
 */

if (!function_exists('bc_retired_gateways')) {
    /**
     * Gateway slugs that must not accept new traffic, mapped to the record that explains why.
     * Add a replacement provider here only once its adapter is implemented AND verified.
     */
    function bc_retired_gateways() {
        return [
            'plisio' => [
                'reason'      => 'This crypto payment service is no longer operating. Crypto deposits and payouts now run through the replacement gateway APIs (Klasha / Flutterwave) once activated for your store.',
                'replacement' => 'klasha,flutterwave',
            ],
        ];
    }
}

if (!function_exists('bc_gateway_is_retired')) {
    /** True when the gateway may not be used for NEW traffic. Matching is case-insensitive. */
    function bc_gateway_is_retired($gateway_name) {
        $name = strtolower(trim((string)$gateway_name));
        if ($name === '') return false;
        // Stored names vary ('plisio', 'plisio-v2', ...): match the family.
        foreach (bc_retired_gateways() as $slug => $meta) {
            if ($name === $slug || strpos($name, $slug . '-') === 0 || strpos($name, $slug . '_') === 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('bc_retired_gateway_message')) {
    /** User-facing explanation for a retired gateway. */
    function bc_retired_gateway_message($gateway_name) {
        $name = strtolower(trim((string)$gateway_name));
        foreach (bc_retired_gateways() as $slug => $meta) {
            if ($name === $slug || strpos($name, $slug . '-') === 0 || strpos($name, $slug . '_') === 0) {
                return $meta['reason'];
            }
        }
        return '';
    }
}

if (!function_exists('bc_gateway_allowed_for_new_traffic')) {
    /**
     * Single gate for new gateway traffic. Existing records, refunds and reconciliation reads
     * must NOT call this: retirement stops new transactions, it does not rewrite history.
     */
    function bc_gateway_allowed_for_new_traffic($gateway_name) {
        return !bc_gateway_is_retired($gateway_name);
    }
}
