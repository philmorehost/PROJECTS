<?php
/**
 * §45 — page-load performance. The site crawled (up to ~1 minute per page)
 * because work that should happen at most once was repeating on ordinary
 * requests:
 *
 *  1. The web, bc-admin and bc-spadmin bootstraps each demanded a DIFFERENT
 *     session value for "migrations done" ('6.9.11-ai' vs '6.9.1' vs
 *     '2026.10.25-2'), so switching areas re-ran the 500+-statement
 *     bc-tables.php migration file on every switch. One shared marker now.
 *  2. The SAAS license integrity check trusted only a file cache that every
 *     redeploy wipes (and that cannot be written when func/ is read-only):
 *     in that state every page load re-contacted the license server and ran
 *     five backfill WRITE queries. It now keeps a 1-hour verdict in the
 *     session (NON-SAAS already did) and writes only what changed.
 *  3. getBruteForceSettings() was re-queried several times per page (no
 *     cache), and bc_release_expired_blocks() ran up to 3 DELETE sweeps per
 *     page. Settings are memoized per request; sweeps run <=1x per 5 min.
 *  4. A Google token-info call had no timeout; SweetAlert blocked parsing.
 */
chdir(dirname(__DIR__));
$fail = 0;
function chk($cond, $label) { global $fail; echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$cond) $fail++; }

$roots = ['SAAS' => 'DGV7.0-SAAS', 'NON-SAAS' => 'DGV7.0-NON-SAAS'];

foreach ($roots as $name => $root) {
    $sec = file_get_contents($root . '/func/bc-security.php');
    chk(strpos($sec, 'function bc_migrations_current()') !== false && strpos($sec, 'function bc_migrations_mark_done()') !== false,
        "$name: shared migration gate helpers exist");
    chk(strpos($sec, "array('6.9.11-ai', '6.9.11-ai-login-otp-1')") !== false,
        "$name: shared gate accepts the existing deployment markers");

    $acfg = file_get_contents($root . '/func/bc-admin-config.php');
    chk(strpos($acfg, 'if (!bc_migrations_current())') !== false && strpos($acfg, 'bc_migrations_mark_done();') !== false,
        "$name bc-admin: uses the shared migration gate");
    chk(strpos($acfg, "'6.9.1'") === false || strpos($acfg, "migrations_completed_version'] !== '6.9.1'") === false,
        "$name bc-admin: private '6.9.1' session marker gone (no area-switch re-runs)");
    if (strpos($acfg, 'checkmate_super_admin_table_exists') !== false) {
        chk(strpos($acfg, '$checkmate_super_admin_table_exists = mysqli_query($connection_server, "SELECT id FROM sas_super_admin LIMIT 1");') !== false,
            "$name bc-admin: super-admin existence probe is a cheap LIMIT 1");
    }

    $fn = file_get_contents($root . '/func/bc-func.php');
    chk(strpos($fn, 'static $bf_settings_cache = array();') !== false && strpos($fn, 'if (isset($bf_settings_cache[$vendor_id])) return $bf_settings_cache[$vendor_id];') !== false,
        "$name: brute-force settings memoized per request");
    chk(strpos($fn, "bc_rel_") !== false && strpos($fn, "< 300) return;") !== false,
        "$name: expired-block sweep throttled to once per 5 minutes per actor");
    $gt = strpos($fn, 'function verifyGoogleToken');
    $gtblock = $gt !== false ? substr($fn, $gt, 1200) : '';
    chk(strpos($gtblock, 'CURLOPT_TIMEOUT') !== false && strpos($gtblock, 'CURLOPT_CONNECTTIMEOUT') !== false,
        "$name: Google token-info call is time-bounded");

    $wcfg = file_get_contents($root . '/func/bc-config.php');
    chk(strpos($wcfg, "define('SYSTEM_VERSION', '6.9.11-ai')") !== false,
        "$name web: legacy migration version define untouched");
    if ($name === 'SAAS') {
        chk(strpos($wcfg, '$bc_compatible_system_versions') !== false,
            "$name web: pinned legacy migration gate untouched (user_site_migration_gate_test)");
    }

    foreach (['bc-plate-app.php', 'bc-plate-admin.php'] as $plate) {
        $pl = file_get_contents($root . '/func/' . $plate);
        chk(strpos($pl, 'sweetalert2.all.min.js" defer') !== false, "$name $plate: SweetAlert no longer parser-blocking");
    }
}

$sp = file_get_contents('DGV7.0-SAAS/func/bc-spadmin-config.php');
chk(strpos($sp, 'if (!bc_migrations_current())') !== false && strpos($sp, "migrations_completed_version'] !== '2026.10.25-2'") === false,
    "SAAS bc-spadmin: shared migration gate, private marker gone");
chk(strpos($sp, "strpos((string)\$bc_sk, 'bc_integrity_ok_') === 0") !== false,
    "SAAS bc-spadmin: saving a license drops session integrity verdicts (forces fresh check)");

$lv = file_get_contents('DGV7.0-SAAS/func/bc-levelup.php');
chk(strpos($lv, "bc_integrity_ok_") !== false && strpos($lv, "< 3600") !== false,
    "SAAS integrity: 1-hour per-session verdict fast-path");
chk(substr_count($lv, 'bc_license_db_backfill($code, $domain);') === 1,
    "SAAS integrity: backfill writes happen only after a real remote validation");
chk(strpos($lv, 'CURLOPT_TIMEOUT, 3') !== false && strpos($lv, 'CURLOPT_CONNECTTIMEOUT, 2') !== false,
    "SAAS integrity: license call capped at 3s/2s on page paths");
chk(strpos($lv, "(\$existing['license_domain'] ?? null) !== \$domain") !== false,
    "SAAS integrity: backfill writes only values that actually differ");

$ns = file_get_contents('DGV7.0-NON-SAAS/func/bc-integrity.php');
chk(strpos($ns, 'sys_integrity_verified') !== false, "NON-SAAS integrity: session verdict fast-path still present (regression guard)");

echo PHP_EOL . ($fail === 0 ? 'perf45: all checks passed' : "perf45: $fail FAILURES") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
