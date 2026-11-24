<?php
/** Restricted local operator CLI for multi-party webhook reconciliation. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$options = [];
$record = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') {
        echo "Usage: php bin/reconcile-webhook-outbox.php --transaction-id=N\n"
           . "Inspection returns an observation digest only. To record a dual-operator resolution, add --record plus\n"
           . "--request-id=OPAQUE --preparer-ref=OPAQUE --approver-ref=OPAQUE --evidence-ref=OPAQUE --evidence-sha256=HEX\n"
           . "--observation-sha256=HEX --merchant-id=N --reference=REF --currency=ISO3 --amount=DEC [--account-number=10DIGITS]\n"
           . "--disposition=confirmed_settled|mismatch_held|manual_credit_required|no_action_required\n"
           . "Requires PAYHUB_WEBHOOK_RECONCILE_ENABLED=1. Never resets claims, overwrites outbox bytes, or mutates balances.\n";
        exit;
    }
    if ($arg === '--record') { $record = true; continue; }
    if (!preg_match('/\A--(transaction-id|request-id|preparer-ref|approver-ref|evidence-ref|evidence-sha256|observation-sha256|merchant-id|reference|currency|amount|account-number|disposition)=(.*)\z/D', $arg, $m) || isset($options[$m[1]])) {
        fwrite(STDERR, "Invalid option. Use --help.\n");
        exit(2);
    }
    $options[$m[1]] = $m[2];
}

require_once __DIR__ . '/../includes/webhook-reconcile.php';

try {
    $rawId = $options['transaction-id'] ?? '';
    if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', $rawId) || (float)$rawId > 2147483647) {
        throw new InvalidArgumentException('Invalid ID.');
    }
    $id = (int)$rawId;

    if ($record) {
        $rawMid = $options['merchant-id'] ?? '';
        if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', $rawMid) || (float)$rawMid > 2147483647) {
            throw new InvalidArgumentException('Invalid merchant ID.');
        }
        $input = [
            'request_id'         => $options['request-id'] ?? null,
            'preparer_ref'       => $options['preparer-ref'] ?? null,
            'approver_ref'       => $options['approver-ref'] ?? null,
            'evidence_ref'       => $options['evidence-ref'] ?? null,
            'evidence_sha256'    => $options['evidence-sha256'] ?? null,
            'observation_sha256' => $options['observation-sha256'] ?? null,
            'merchant_id'        => (int)$rawMid,
            'reference'          => $options['reference'] ?? null,
            'currency'           => $options['currency'] ?? null,
            'amount'             => $options['amount'] ?? null,
            'account_number'     => $options['account-number'] ?? '',
            'disposition'        => $options['disposition'] ?? null,
        ];
        payhub_reconcile_input($input);
        if (getenv('PAYHUB_WEBHOOK_RECONCILE_ENABLED') !== '1') {
            throw new InvalidArgumentException('Reconciliation disabled.');
        }
    } elseif (count($options) !== 1) {
        throw new InvalidArgumentException('Reconciliation fields require --record.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Invalid or disabled reconciliation request. Use --help.\n");
    exit(2);
}

try {
    require __DIR__ . '/../includes/config.php';
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $result = $record
        ? ['transaction_id' => $id, 'status' => payhub_record_webhook_reconciliation($db, $id, $input), 'advisory_only' => true]
        : payhub_inspect_webhook_review($db, $id);
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Reconciliation unavailable, changed, or conflicting. Inspect again with restricted operator tools.\n");
    exit(1);
}
