<?php
/**
 * DGV7 account_type backfill — admin side.
 *
 * Included from bc-admin/ServiceControl.php's PHP preamble. It runs a dry run first; the operator
 * then has to press the button again to actually write. The underlying routine only touches rows
 * whose account_type is NULL/empty, so pressing it twice is harmless and is the whole point of the
 * "idempotent" requirement. account_level is never written.
 *
 * Expects: $connection_server, $matrix_vendor_id (set by bc-service-matrix.php).
 */

require_once __DIR__ . '/bc-fintech-accounts.php';

$bc_backfill_vendor = (int)($matrix_vendor_id ?? 0);
$bc_backfill_counts = ['personal' => 0, 'business' => 0, 'already_set' => 0, 'unknown_level' => 0, 'invalid_tier' => 0];
$bc_backfill_done   = null;

if ($bc_backfill_vendor > 0) {
    if (isset($_POST['bc_run_backfill'])) {
        // Two-step confirm: the write only happens when the POST carries the confirmation flag, so a
        // stray double-submit or a prefetch cannot silently change account tiers.
        if (!empty($_POST['bc_backfill_confirm'])) {
            $bc_backfill_done = bc_fintech_backfill_account_types($bc_backfill_vendor, false);
            $_SESSION['bc_backfill_flash'] = ($bc_backfill_done === false)
                ? 'Backfill could not run. Check the account policy schema.'
                : 'Backfill complete: ' . (int)$bc_backfill_done['updated'] . ' account(s) classified, '
                    . (int)$bc_backfill_done['skipped_unknown'] . ' left unclassified.';
        } else {
            $_SESSION['bc_backfill_flash'] = 'Confirm the dry run before writing.';
        }
        if (!headers_sent()) { header('Location: ' . $matrix_return_url); exit; }
    }
    $bc_backfill_counts = bc_fintech_backfill_dry_run($bc_backfill_vendor);
}
$bc_backfill_total = $bc_backfill_counts['personal'] + $bc_backfill_counts['business'];
$bc_backfill_flash = '';
if (!empty($_SESSION['bc_backfill_flash'])) {
    $bc_backfill_flash = (string)$_SESSION['bc_backfill_flash'];
    unset($_SESSION['bc_backfill_flash']);
}
