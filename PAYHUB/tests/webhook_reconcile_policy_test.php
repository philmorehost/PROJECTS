<?php
require_once __DIR__ . '/../includes/webhook-reconcile.php';

$n = 0;
function rec_ok($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }
function rec_reject($fn, $msg) {
    try { $fn(); } catch (Throwable $e) { rec_ok(true, $msg); return; }
    throw new RuntimeException('Invalid input accepted: ' . $msg);
}

$valid = [
    'request_id'         => 'recon-req-0001',
    'preparer_ref'       => 'operator-prep-01',
    'approver_ref'       => 'operator-app-02',
    'evidence_ref'       => 'case-evid-0001',
    'evidence_sha256'    => str_repeat('a', 64),
    'observation_sha256' => str_repeat('b', 64),
    'merchant_id'        => 7,
    'reference'          => 'PH-TX-1001',
    'currency'           => 'NGN',
    'amount'             => '1500.00',
    'account_number'     => '9012345678',
    'disposition'        => 'confirmed_settled',
];

payhub_reconcile_input($valid);
rec_ok(true, 'valid input accepted');

// Same preparer and approver refused
$sameOp = $valid;
$sameOp['approver_ref'] = $sameOp['preparer_ref'];
rec_reject(fn() => payhub_reconcile_input($sameOp), 'dual-operator separation enforced');

// Dispositions
foreach (['confirmed_settled', 'mismatch_held', 'manual_credit_required', 'no_action_required'] as $d) {
    $v = $valid;
    $v['disposition'] = $d;
    payhub_reconcile_input($v);
    rec_ok(true, 'valid disposition ' . $d);
}
$badDisp = $valid;
$badDisp['disposition'] = 'auto_credit';
rec_reject(fn() => payhub_reconcile_input($badDisp), 'invalid disposition refused');

// Exact evidence matching
$tx = ['user_id' => 7, 'reference' => 'PH-TX-1001', 'currency' => 'NGN', 'amount' => '1500.0000', 'receiving_account_number' => '9012345678'];
rec_ok(payhub_reconcile_evidence_matches($tx, $valid), 'exact evidence matches');

$badAmt = $valid; $badAmt['amount'] = '1500.01';
rec_ok(!payhub_reconcile_evidence_matches($tx, $badAmt), 'amount mismatch detected');

$badCur = $valid; $badCur['currency'] = 'USD';
rec_ok(!payhub_reconcile_evidence_matches($tx, $badCur), 'currency mismatch detected');

$badMer = $valid; $badMer['merchant_id'] = 8;
rec_ok(!payhub_reconcile_evidence_matches($tx, $badMer), 'merchant mismatch detected');

$badAcct = $valid; $badAcct['account_number'] = '9012345679';
rec_ok(!payhub_reconcile_evidence_matches($tx, $badAcct), 'account number mismatch detected');

// Static containment guard: reconciliation never touches delivery/claim modules
foreach (['webhook-recovery.php', 'webhook-initial.php', 'webhook-retry.php', 'webhook-outbox.php'] as $file) {
    rec_ok(strpos(file_get_contents(__DIR__ . '/../includes/' . $file), 'webhook_reconciliation_resolutions') === false, 'reconciliation isolated from ' . $file);
}

echo "PASS $n webhook reconciliation policy checks\n";
