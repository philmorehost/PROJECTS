<?php
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw_peek = @file_get_contents('php://input');
    if (is_string($raw_peek) && trim($raw_peek) !== '') {
        $json_peek = json_decode($raw_peek, true);
        if (is_array($json_peek)) {
            $d = is_array($json_peek['data'] ?? null) ? $json_peek['data'] : $json_peek;
            $has_dva = trim((string)($d['receiving_account_number'] ?? ($json_peek['receiving_account_number'] ?? ''))) !== '';
            $has_ref = trim((string)($d['reference'] ?? ($json_peek['reference'] ?? ''))) !== '';
            if (!$has_dva && $has_ref) {
                require_once __DIR__ . '/func/bc-connect.php';
                require_once __DIR__ . '/func/bc-payment-gateway-settlement.php';
                if (bc_pg_try_handle_payhub_checkout_webhook($connection_server)) {
                    exit;
                }
            }
        }
    }
}
require_once __DIR__ . '/func/bc-payhub-deposit-endpoint.php';
bc_ph_endpoint('user');
