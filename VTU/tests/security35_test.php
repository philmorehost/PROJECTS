<?php
/** Run: php -n VTU/tests/security35_test.php
 * §35 (2026-11) checks — malicious-vendor / loophole hardening:
 *  - stored-XSS into the super-admin console closed (vendor data escaped
 *    in HTML, JS-string and href contexts on the vendor list pages)
 *  - cron endpoints are no longer web-invokable (CLI or cron secret)
 *  - abuse guard: authenticated actors are scored (code injection 40,
 *    webshell upload 50, URL probe 25); 100 pts/24h auto-suspends a SAAS
 *    vendor (status=2) or blocks a probing IP for 24h in sas_blocked_ips
 *  - save-point scans on vendor plain-text fields + the public vendor
 *    registration form; blog uploads take their extension from the
 *    validated server MIME type, never the client filename
 * Pure functions + source assertions only; no DB needed.
 */
$root = dirname(__DIR__);
$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');

function src35($root, $ed, $rel) {
    $p = $root . '/' . $ed . '/' . $rel;
    if (!is_file($p)) throw new RuntimeException("missing $p");
    return file_get_contents($p);
}

$count = 0;
function chk35($label, $cond) {
    global $count;
    $count++;
    if (!$cond) throw new RuntimeException('security35: ' . $label);
    echo "ok $label\n";
}

chk35('func/bc-abuse-guard.php identical across editions',
    src35($root, 'DGV7.0-SAAS', 'func/bc-abuse-guard.php') === src35($root, 'DGV7.0-NON-SAAS', 'func/bc-abuse-guard.php'));

require $root . '/DGV7.0-SAAS/func/bc-abuse-guard.php';

// ── content scanner (pure) ─────────────────────────────────────────────
chk35('scanner flags a PHP tag', bc_content_code_scan('<?php system($_GET[1]);') === true);
chk35('scanner flags a script tag', bc_content_code_scan('<script>alert(1)</script>') === true);
chk35('scanner flags an event-handler attribute', bc_content_code_scan('x" onerror="alert(1)') === true);
chk35('scanner flags SQLi in a text field', bc_content_code_scan("1 UNION SELECT password FROM sas_users") === true);
chk35('scanner flags javascript: URLs', bc_content_code_scan('javascript:alert(1)') === true);
chk35('scanner passes a normal site title', bc_content_code_scan('Philmore Data Hub — cheap data & airtime!') === false);
chk35('scanner passes ordinary marketing copy', bc_content_code_scan('Contact us on WhatsApp for bulk discounts') === false);
chk35('scanner passes quotes and punctuation', bc_content_code_scan('Best "mega" deals, 100% legit') === false);
chk35('scanner passes empty input', bc_content_code_scan('') === false);
$w = bc_abuse_weights();
chk35('weights: two webshell attempts reach the 100pt auto-block',
    $w['webshell_upload_attempt'] * 2 >= 100 && $w['code_injection_attempt'] === 40 && $w['request_probe'] === 25);

// ── guard engine ───────────────────────────────────────────────────────
$mod = src35($root, 'DGV7.0-SAAS', 'func/bc-abuse-guard.php');
chk35('signals persist in sas_abuse_signals', strpos($mod, 'CREATE TABLE IF NOT EXISTS sas_abuse_signals') !== false);
chk35('auto-suspend flips the vendor to status 2', strpos($mod, 'UPDATE sas_vendors SET status=2') !== false);
chk35('auto-suspend is SAAS-only (NON-SAAS owner is never locked out)',
    strpos($mod, "function_exists('bc_is_saas_edition')") !== false);
chk35('probing IPs are blocked 24h in the brute-force table',
    strpos($mod, 'INSERT INTO sas_blocked_ips') !== false && strpos($mod, 'INTERVAL 1 DAY') !== false);
chk35('blocks are audited and alerted',
    strpos($mod, 'bc_sec_audit') !== false && strpos($mod, 'bc_trigger_high_alert') !== false);
chk35('request inspection covers traversal + SQLi + code probes',
    strpos($mod, 'etc\\/passwd') !== false && strpos($mod, 'union\\s+(all\\s+)?select') !== false);
chk35('per-request signal budget prevents signal-table flooding',
    strpos($mod, 'static $budget = 10') !== false);

// ── bootstrap wiring ───────────────────────────────────────────────────
foreach ($editions as $ed) {
    chk35("$ed bc-connect loads the abuse guard",
        strpos(src35($root, $ed, 'func/bc-connect.php'), 'bc-abuse-guard.php') !== false);
    chk35("$ed vendor bootstrap inspects requests as the vendor",
        strpos(src35($root, $ed, 'func/bc-admin-config.php'), "bc_abuse_inspect_request((int)\$get_logged_admin_details['id'], 'vendor'") !== false);
    $web = src35($root, $ed, 'func/bc-config.php');
    chk35("$ed web bootstrap inspects requests as the user and enforces IP blocks",
        strpos($web, "'user', (string)(\$get_logged_user_details['username']") !== false
        && strpos($web, "=== 'ip_blocked'") !== false);
}

// ── cron lockdown ──────────────────────────────────────────────────────
$crons = array('aggregator_monitor.php', 'ai_daily_briefing.php', 'ai_model_status.php',
    'ai_monthly_blueprint.php', 'ai_predictive_refill.php', 'ai_weekly_error_report.php',
    'dormant_user_alert.php', 'perf_report.php', 'process_bulk_queue.php');
foreach ($editions as $ed) {
    foreach ($crons as $cron) {
        $c = src35($root, $ed, 'cron/' . $cron);
        chk35("$ed cron/$cron requires CLI or the cron secret",
            strpos($c, "PHP_SAPI !== 'cli'") !== false && strpos($c, 'bc_get_or_create_cron_secret') !== false);
    }
    chk35("$ed cron/process_mail_queue.php guard retained",
        strpos(src35($root, $ed, 'cron/process_mail_queue.php'), 'bc_get_or_create_cron_secret') !== false);
    chk35("$ed update cron stays CLI-only",
        strpos(src35($root, $ed, $ed === 'DGV7.0-SAAS' ? 'bc-spadmin/cron_update.php' : 'bc-admin/cron_update.php'), "php_sapi_name() !== 'cli'") !== false);
}
chk35('SAAS cron/process_requery_queue.php requires CLI or the cron secret',
    strpos(src35($root, 'DGV7.0-SAAS', 'cron/process_requery_queue.php'), 'bc_get_or_create_cron_secret') !== false);

// ── stored-XSS: super-admin vendor surfaces ────────────────────────────
$vendors = src35($root, 'DGV7.0-SAAS', 'bc-spadmin/Vendors.php');
chk35('Vendors.php has no raw vendor-field echoes left',
    strpos($vendors, '<?php echo $user[') === false);
chk35('Vendors.php escapes names for HTML context',
    strpos($vendors, "htmlspecialchars((string)(\$user['firstname'].' '.\$user['lastname']), ENT_QUOTES, 'UTF-8')") !== false);
chk35('Vendors.php JS-string contexts addslashes before escaping',
    strpos($vendors, "htmlspecialchars(addslashes((string)\$user['email']), ENT_QUOTES, 'UTF-8')") !== false);
chk35('Vendors.php site link uses the cleaned host, never the raw string',
    strpos($vendors, 'bc_clean_vendor_domain') !== false && strpos($vendors, 'href="//<?php echo $user') === false);
chk35('Vendors.php casts vendor ids to int at output',
    strpos($vendors, "<?php echo (int)\$user['id']; ?>") !== false);
$regs = src35($root, 'DGV7.0-SAAS', 'bc-spadmin/VendorRegistrations.php');
chk35('VendorRegistrations.php escapes the attacker-controlled payment method',
    strpos($regs, "htmlspecialchars(ucwords(str_replace('_', ' ', (string)\$row['payment_method']))") !== false);

// ── save-point scans + upload hardening ────────────────────────────────
foreach ($editions as $ed) {
    $adm = src35($root, $ed, 'bc-admin/AccountSettings.php');
    chk35("$ed site-details save refuses + scores code payloads",
        strpos($adm, "'code_injection_attempt', 'site-details field '") !== false
        && strpos($adm, 'text settings cannot contain code or script content') !== false);
    chk35("$ed custom code fields refuse PHP tags (scripts stay a feature)",
        strpos($adm, 'custom code cannot contain PHP tags') !== false);
    chk35("$ed profile save scores code payloads",
        strpos($adm, "'code_injection_attempt', 'profile field '") !== false);
    $blog = src35($root, $ed, 'bc-admin/BlogPostEdit.php');
    chk35("$ed blog title refuses + scores code payloads",
        strpos($blog, "'code_injection_attempt', 'blog post title'") !== false);
}
$blogS = src35($root, 'DGV7.0-SAAS', 'bc-admin/BlogPostEdit.php');
chk35('blog upload no longer trusts the client-supplied MIME type',
    strpos($blogS, "?: \$_FILES['featured_image']['type']") === false);
chk35('blog upload extension derives from the validated MIME type',
    strpos($blogS, "\$bc_mime_ext[\$file_type]") !== false);
chk35('executable blog-upload names are scored as webshell attempts',
    strpos($blogS, "'webshell_upload_attempt'") !== false);
$reg = src35($root, 'DGV7.0-SAAS', 'bc-spadmin/VendorReg.php');
chk35('public vendor registration refuses + scores code payloads by IP',
    strpos($reg, "'code_injection_attempt', 'vendor registration field '") !== false);

// ── visibility panels ──────────────────────────────────────────────────
foreach (array(array('DGV7.0-SAAS', 'bc-spadmin/BruteForceSecurity.php'),
               array('DGV7.0-SAAS', 'bc-admin/BruteForceSecurity.php'),
               array('DGV7.0-NON-SAAS', 'bc-admin/BruteForceSecurity.php')) as $panel) {
    chk35($panel[0] . ' ' . $panel[1] . ' shows the abuse-guard panel',
        strpos(src35($root, $panel[0], $panel[1]), 'bc_abuse_recent_signals') !== false);
}

echo "security35: $count checks passed\n";
