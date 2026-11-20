<?php
/**
 * §40 — license tier pinning (why brand-new installs got "License tier
 * mismatch" 403s while old installs updated fine).
 *
 * Root cause chain, all in LM:
 *  1. getLicenseInsertDefinition() never inserted tier_id — in ANY variant —
 *     and silently dropped the tier argument callers passed. Every licence
 *     created via admin add / request approval / webhook / payment verify /
 *     api-create-license was born tier-less with license_type 'standard'.
 *  2. check-update.php's NULL-tier fallback maps 'standard' to NON-SAAS and
 *     HEALED that guess onto the record — even when the client declared SAAS
 *     (it logged a warning and cemented the wrong pin anyway), after which
 *     the mismatch guard rejected the install forever.
 *
 * Fixes asserted here: the insert definition stores tier_id (explicit tier
 * wins; otherwise derived from license_type: extended=SAAS, standard=
 * NON-SAAS), the LM admin passes license_type on add and re-syncs the pin on
 * edit, and check-update never heals over a client-declared conflict.
 */
$checks = 0;
$failures = 0;
function tchk($cond, $label) {
    global $checks, $failures;
    $checks++;
    if (!$cond) { $failures++; echo "FAIL: $label\n"; }
}

$vtRoot = dirname(__DIR__);
$lm = null;
foreach (array($vtRoot . '/LM', dirname($vtRoot) . '/LM') as $cand) {
    if (is_file($cand . '/db.php')) { $lm = $cand; break; }
}
tchk($lm !== null, 'LM tree located');
if ($lm === null) { echo "license_tier40: $checks checks, $failures failures\n"; exit(1); }

$db = file_get_contents($lm . '/db.php');
tchk(strpos($db, "function getLicenseInsertDefinition") !== false, 'db.php: insert definition exists');
tchk(strpos($db, "\$hasTier = hasTableColumn(\$pdo, 'licenses', 'tier_id')") !== false, 'db.php: tier_id column detected');
tchk(strpos($db, "if (\$hasTier) \$cols[] = 'tier_id';") !== false, 'db.php: tier_id is inserted when the column exists');
tchk(strpos($db, "\$license_type = 'standard', \$tier_id = null") !== false, 'db.php: params accept an explicit tier');
tchk(strpos($db, "(\$license_type === 'extended') ? 'SAAS'") !== false, 'db.php: tier derived from license_type (extended=SAAS)');
tchk(strpos($db, '"SELECT id FROM script_tiers WHERE UPPER(tier_code) = ? LIMIT 1"') !== false, 'db.php: tier id resolved by tier_code, never assumed 1/2');

$admin = file_get_contents($lm . '/admin/licenses.php');
tchk(strpos($admin, "(string) (\$_POST['license_type'] ?? 'standard')\n                );") !== false, 'admin/licenses.php: add passes license_type into the insert');
tchk(strpos($admin, "UPDATE licenses SET tier_id = ? WHERE id = ?") !== false, 'admin/licenses.php: edit re-syncs the tier pin');

$check = file_get_contents($lm . '/check-update.php');
tchk(strpos($check, "if (\$client_edition === '' || \$client_edition === \$tier_code) {") !== false, 'check-update: heal only when the guess agrees with the client');
tchk(strpos($check, 'not healing tier for license') !== false, 'check-update: conflict is logged, record left unpinned');
tchk(strpos($check, "License tier mismatch: this license is assigned to") !== false, 'check-update: mismatch guard still enforced (no silent cross-edition serving)');

$api = file_get_contents($lm . '/api-create-license.php');
tchk(strpos($api, '$resolved_tier_id') !== false && strpos($api, "license_type, \$resolved_tier_id)") !== false, 'api-create-license: explicit tier reaches the insert (no longer dropped)');

/* The clients already declare their edition correctly — pin that down. */
tchk(strpos(file_get_contents($vtRoot . '/DGV7.0-SAAS/bc-spadmin/UpdateSystem.php'), "'expected_tier' => 'SAAS'") !== false, 'SAAS client declares expected_tier=SAAS');
tchk(strpos(file_get_contents($vtRoot . '/DGV7.0-NON-SAAS/bc-admin/UpdateSystem.php'), "'expected_tier' => 'NON-SAAS'") !== false, 'NON-SAAS client declares expected_tier=NON-SAAS');

echo "license_tier40: $checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
