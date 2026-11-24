<?php
/** Static source checks for PayHub DVA reconciliation evidence & verify response parity. */
$n = 0;
function drcheck($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }
$root = dirname(__DIR__);

// 1. All 4 DVA reconciliation writers must persist currency and server-owned receiving_account_number,
// and refuse ambiguous virtual_accounts ownership rather than picking arbitrarily.
foreach ([
    'api/transaction/reconcile.php' => ['LIMIT 2', 'count($vaccRows) > 1', '$receivingAcc', 'receiving_account_number', 'currency'],
    'merchant/reconcile.php' => ['LIMIT 2', 'count($vaccRows) > 1', '$receivingAcc', 'receiving_account_number', 'currency'],
    'api/virtual-accounts.php' => ['LIMIT 2', 'count($vaRows) === 1', '$receivingAcc', 'receiving_account_number', 'currency'],
    'includes/functions.php' => ['SELECT COUNT(*) FROM virtual_accounts', '$receivingAcc', 'receiving_account_number', 'currency'],
] as $rel => $needles) {
    $src = file_get_contents($root . '/' . $rel);
    foreach ($needles as $needle) {
        drcheck(strpos($src, $needle) !== false, "$rel missing $needle");
    }
}

// 2. api/transaction/verify.php must expose every field required by VTU bc_ph_verified().
$verify = file_get_contents($root . '/api/transaction/verify.php');
foreach ([
    "'status' => true",
    "'paid' => (\$tx['status'] === 'success')",
    "'receiving_account_number' => \$tx['receiving_account_number'] ?? null",
    "'deposit_evidence_version' => !empty(\$tx['receiving_account_number']) ? 1 : null",
    "'currency' => (string)(\$tx['currency'] ?? '')",
    "'domain' => ((int)\$tx['is_test'] === 1) ? 'test' : 'live'",
    "'gateway_amount' =>",
    "'fee_amount' => to_minor_units",
    "'settled_amount' => to_minor_units",
] as $needle) {
    drcheck(strpos($verify, $needle) !== false, "verify.php missing $needle");
}

echo "PASS $n PayHub DVA reconciliation & verify contract checks (no database or network)\n";
