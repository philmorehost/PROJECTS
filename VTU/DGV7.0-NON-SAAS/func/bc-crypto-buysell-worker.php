<?php
/**
 * Isolated signer-outbox worker for replacement crypto Buy/Sell orders.
 * Disabled unless CRYPTO_BUYSELL_EXECUTION_ENABLED=1.
 * Never holds blockchain seeds/private keys; forwards queued_for_signer outbox
 * rows to an isolated signer transport and records outcome via bc_cbs_observe_payout_outcome.
 */
require_once __DIR__ . '/bc-crypto-buysell.php';

function bc_cbs_sign_signer_request($secret, $timestamp, $method, $path, $body) {
    if (!is_string($secret) || strlen($secret) < 16) {
        throw new InvalidArgumentException('Signer HMAC secret must be at least 16 chars.');
    }
    $canonical = (string)$timestamp . '.' . strtoupper((string)$method) . '.' . (string)$path . '.' . (string)$body;
    return hash_hmac('sha256', $canonical, $secret);
}

function bc_cbs_run_signer_worker(mysqli $db, $vendorId, callable $transport, $limit = 10) {
    if (!bc_cbs_execution_enabled()) {
        return ['ok' => false, 'code' => 'execution_disabled', 'processed' => 0];
    }
    $vendorId = (int)$vendorId; $limit = (int)$limit;
    if ($vendorId < 1 || $limit < 1 || $limit > 100) {
        return ['ok' => false, 'code' => 'invalid', 'processed' => 0];
    }
    bc_cbs_assert_innodb($db);

    $q = $db->prepare("SELECT o.id AS outbox_id, o.order_id, o.asset, o.network, o.destination_address, o.crypto_units_1e8, o.payload_sha256, t.request_id FROM sas_crypto_signer_outbox o JOIN sas_crypto_trade_orders t ON t.id = o.order_id WHERE o.vendor_id=? AND o.dispatch_state='queued_for_signer' ORDER BY o.id ASC LIMIT ?");
    $q->bind_param('ii', $vendorId, $limit);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();

    $results = [];
    foreach ($rows as $row) {
        $orderId = (int)$row['order_id'];
        // Claim row inside transaction before external network call
        $db->begin_transaction();
        try {
            $cq = $db->prepare("SELECT dispatch_state FROM sas_crypto_signer_outbox WHERE order_id=? AND vendor_id=? LIMIT 1 FOR UPDATE");
            $cq->bind_param('ii', $orderId, $vendorId);
            $cq->execute();
            $cur = $cq->get_result()->fetch_assoc();
            $cq->close();
            if (!$cur || $cur['dispatch_state'] !== 'queued_for_signer') {
                $db->rollback();
                continue;
            }
            $up = $db->prepare("UPDATE sas_crypto_signer_outbox SET dispatch_state='unknown' WHERE order_id=? AND dispatch_state='queued_for_signer'");
            $up->bind_param('i', $orderId);
            $up->execute();
            $up->close();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        $outcome = 'unknown';
        $details = [];
        try {
            $resp = $transport($row);
            $cand = (string)($resp['outcome'] ?? 'unknown');
            if (in_array($cand, ['rejected_before_broadcast', 'broadcast', 'confirmed', 'unknown'], true)) {
                $outcome = $cand;
            }
            $details = [
                'provider_ref' => (string)($resp['provider_ref'] ?? ''),
                'tx_hash'      => (string)($resp['tx_hash'] ?? ''),
                'error'        => (string)($resp['error'] ?? ''),
            ];
        } catch (Throwable $e) {
            $outcome = 'unknown';
            $details = ['error' => $e->getMessage()];
        }

        $obs = bc_cbs_observe_payout_outcome($db, $vendorId, $orderId, $outcome, $details);
        $results[] = ['order_id' => $orderId, 'state' => $obs['state'] ?? 'unknown'];
    }
    return ['ok' => true, 'code' => 'completed', 'processed' => count($results), 'results' => $results];
}
