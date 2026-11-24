<?php
/**
 * Durable payout execution bridge.
 * Never runs unless BC_PAYOUT_WORKER_ENABLED=1.
 * Routes bank-transfer requests through bc_payout_reserve() (state='held')
 * and admin approvals/cancellations through bc_payout_dispatch_one() / bc_payout_cancel_held(),
 * never issuing unsafe legacy refunds on ambiguous/unknown provider results.
 */
require_once __DIR__ . '/bc-payout-reservations.php';
require_once __DIR__ . '/bc-payout-worker.php';

if (!function_exists('bc_payout_execution_enabled')) {
    function bc_payout_execution_enabled() {
        return getenv('BC_PAYOUT_WORKER_ENABLED') === '1';
    }
}

if (!function_exists('bc_payout_execute_user_request')) {
    function bc_payout_execute_user_request($vendor_id, array $user, $provider, $bank_code, $account_number, $amount, $fee, $narration, $request_key, $bank_name_db = '', $account_name = '', $session_id_db = '') {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $user_id = (int)($user['id'] ?? 0);
        if (!bc_payout_execution_enabled()) {
            return ['ok' => false, 'code' => 'PAYOUT_SECURITY_HOLD'];
        }
        $res = bc_payout_reserve(
            $vendor_id,
            $user_id,
            strtolower(trim((string)$provider)),
            trim((string)$bank_code),
            trim((string)$account_number),
            $amount,
            $fee,
            trim((string)$narration),
            $request_key
        );
        if (empty($res['ok'])) {
            return $res;
        }
        $ref = (string)$res['reference'];
        if (empty($res['replayed']) && $connection_server instanceof mysqli) {
            $tbl = $connection_server->query("SHOW TABLES LIKE 'sas_bank_transfer_history'");
            if ($tbl && $tbl->num_rows > 0) {
                $uname = (string)($user['username'] ?? '');
                $total_debit = (float)$res['total_debit'];
                $amt_f = (float)$res['amount'];
                $bname = $bank_name_db !== '' ? $bank_name_db : (string)$bank_code;
                $aname = (string)$account_name;
                $sid = (string)$session_id_db;
                $narr = trim((string)$narration);
                $stmt = $connection_server->prepare(
                    'INSERT IGNORE INTO sas_bank_transfer_history (vendor_id, reference, username, amount, amount_charged, bank_code, bank_name, account_name, account_number, narration, session_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                if ($stmt) {
                    $stmt->bind_param('issddssssss', $vendor_id, $ref, $uname, $amt_f, $total_debit, $bank_code, $bname, $aname, $account_number, $narr, $sid);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
        return $res;
    }
}

if (!function_exists('bc_payout_dispatch_one')) {
    function bc_payout_dispatch_one($vendor_id, $tx_reference, $expected_version, callable $transport) {
        if (!bc_payout_worker_enabled()) {
            return ['ok' => false, 'code' => 'PAYOUT_SECURITY_HOLD'];
        }
        $claim = bc_payout_claim_dispatch((int)$vendor_id, (string)$tx_reference, (int)$expected_version);
        if (empty($claim['ok'])) {
            return $claim;
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
        $evKey  = hash('sha256', json_encode(['payout_dispatch_one', (int)$vendor_id, (string)$tx_reference, $dispVer], JSON_THROW_ON_ERROR));
        $evHash = hash('sha256', json_encode($evidencePayload, JSON_THROW_ON_ERROR));
        return bc_payout_observe_outcome((int)$vendor_id, (string)$tx_reference, $evKey, $outcome, $evHash, $provRef, $dispVer);
    }
}

if (!function_exists('bc_payout_admin_action')) {
    function bc_payout_admin_action($vendor_id, $admin_email, $tx_reference, $action, $reason = '', callable $transport = null) {
        $vendor_id = (int)$vendor_id;
        $tx_reference = trim((string)$tx_reference);
        $action = trim((string)$action);
        if (!bc_payout_execution_enabled()) {
            return ['ok' => false, 'code' => 'PAYOUT_SECURITY_HOLD'];
        }
        if ($vendor_id <= 0 || $tx_reference === '') {
            return ['ok' => false, 'code' => 'invalid'];
        }

        $rows = bc_payout_sql(
            'SELECT * FROM sas_payout_reservations WHERE vendor_id=? AND reference=? LIMIT 1',
            [$vendor_id, $tx_reference]
        );
        $res = $rows[0] ?? null;
        if (!$res) {
            return ['ok' => false, 'code' => 'reservation_not_found'];
        }
        $ver = (int)$res['version'];

        if ($action === 'approve') {
            if ($transport === null) {
                $ep = trim((string)(getenv('BC_PAYOUT_PROVIDER_ENDPOINT') ?: ''));
                $sec = trim((string)(getenv('BC_PAYOUT_PROVIDER_HMAC_SECRET') ?: ''));
                if ($ep === '' || strlen($sec) < 16) {
                    return ['ok' => false, 'code' => 'provider_transport_unconfigured'];
                }
                $transport = bc_payout_make_http_transport($ep, $sec);
            }
            return bc_payout_dispatch_one($vendor_id, $tx_reference, $ver, $transport);
        }

        if ($action === 'reject' || $action === 'cancel') {
            $event_key = hash('sha256', json_encode(['admin_cancel', $vendor_id, $tx_reference, $action, $admin_email, $ver], JSON_THROW_ON_ERROR));
            $reason_hash = hash('sha256', json_encode(['reason' => (string)$reason, 'actor' => (string)$admin_email], JSON_THROW_ON_ERROR));
            return bc_payout_cancel_held($vendor_id, $tx_reference, $event_key, $reason_hash, $ver);
        }

        return ['ok' => false, 'code' => 'unsupported_action'];
    }
}
