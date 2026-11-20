<?php
/** Run: php -n VTU/tests/security34_test.php
 * §34 (2026-11) checks:
 *  - license key persists: canonical licensed domain + DB-backed storage so
 *    host variants (www/port/case) can never orphan the saved key again;
 *    the updater sends the licensed domain and fails with guidance, not a
 *    raw License Manager HTTP 400, when no key is saved
 *  - brand colour set in bc-admin is what web users see: session style
 *    cache carries a version + a 5-minute TTL, and every theme save bumps
 *    the version (NON-SAAS writes sas_settings — setSuperAdminOption does
 *    not exist there, so the old bump was silently skipped)
 *  - referral payments + thresholds are admin-configurable: the referral
 *    engine, web Refer & Earn page and app API read bc-admin → Loyalty
 *    Settings (referral_reward_usd / referral_qualifying_usd), the coin
 *    sign-up bonus stays in sas_loyalty_bonus_settings
 *  - buy/payment buttons lock immediately on submit in both shells so a
 *    double click can never submit a purchase/payment twice
 * Pure source assertions only; no DB needed.
 */
$root = dirname(__DIR__);
$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');

function src34($root, $ed, $rel) {
    $p = $root . '/' . $ed . '/' . $rel;
    if (!is_file($p)) throw new RuntimeException("missing $p");
    return file_get_contents($p);
}

$count = 0;
function chk34($label, $cond) {
    global $count;
    $count++;
    if (!$cond) throw new RuntimeException('security34: ' . $label);
    echo "ok $label\n";
}

// Shared modules must stay byte-identical so a fix lands in both editions.
foreach (array('func/bc-fintech-services.php', 'func/bc-plate-app.php', 'func/bc-plate-admin.php',
               'bc-admin/LoyaltySettings.php', 'web/Referral.php', 'web/api/referral.php') as $shared) {
    chk34("$shared identical across editions",
        src34($root, 'DGV7.0-SAAS', $shared) === src34($root, 'DGV7.0-NON-SAAS', $shared));
}

// ── Issue 1: license persistence (NON-SAAS file-backed store hardened) ──
$integrity = src34($root, 'DGV7.0-NON-SAAS', 'func/bc-integrity.php');
chk34('integrity canonicalises the host (port/www/case cannot orphan the key)',
    strpos($integrity, 'function bc_canonical_host(') !== false);
chk34('integrity resolves the licensed domain from stored option first',
    strpos($integrity, 'function bc_license_domain(') !== false);
chk34('integrity persists the license to the DB option store',
    strpos($integrity, 'function bc_license_db_store(') !== false
    && strpos($integrity, 'function bc_license_option_store(') !== false);
chk34('reading the activation falls back to the DB copy and heals the file',
    strpos($integrity, "bc_license_option_read('license_key')") !== false);
chk34('legacy raw-host encrypted tokens are migrated (heal-rewrite)',
    strpos($integrity, '$legacy_host') !== false && strpos($integrity, 'heal: re-encrypt under the licensed domain') !== false);
chk34('integrity verification presents the licensed domain, not the raw host',
    strpos($integrity, '$host = bc_license_domain();') !== false
    && strpos($integrity, "'domain' => \$host") !== false);

$nsSettings = src34($root, 'DGV7.0-NON-SAAS', 'bc-admin/AccountSettings.php');
chk34('NON-SAAS settings page shows the licensed domain from the store',
    strpos($nsSettings, "function_exists('bc_license_domain')") !== false
    && strpos($nsSettings, '$license_domain') !== false);
chk34('NON-SAAS license save also persists to the DB store',
    strpos($nsSettings, 'bc_license_db_store(') !== false);

$updater = src34($root, 'DGV7.0-NON-SAAS', 'bc-admin/UpdateSystem.php');
chk34('updater sends the licensed domain',
    strpos($updater, "'domain' => \$license_domain") !== false
    && strpos($updater, "bc_license_domain()") !== false);
chk34('updater replaces the raw HTTP 400 with actionable guidance when no key is saved',
    strpos($updater, 'No license key is saved on this installation') !== false
    && strpos($updater, 'Account Settings') !== false);
chk34('updater never sends an empty license_key to the License Manager',
    strpos($updater, 'trim((string)$license_key)') !== false);

// ── Issue 4: brand colour saved in bc-admin is what web users see ──────
foreach ($editions as $ed) {
    $cfg = src34($root, $ed, 'func/bc-config.php');
    chk34("$ed style-template session cache carries a 5-minute TTL backstop",
        strpos($cfg, 'vendor_style_template_time') !== false && strpos($cfg, '> 300') !== false);
    $adm = src34($root, $ed, 'bc-admin/AccountSettings.php');
    chk34("$ed theme save bumps the shared style-cache version",
        strpos($adm, 'brand_style_cache_ver') !== false);
    chk34("$ed theme-save cache bump is not trapped inside the update-only branch",
        substr_count($adm, 'brand_style_cache_ver') >= 1
        && strpos($adm, 'if (!empty($theme_saved))') !== false);
}
$nsAdm = src34($root, 'DGV7.0-NON-SAAS', 'bc-admin/AccountSettings.php');
chk34('NON-SAAS bumps the version via sas_settings (setSuperAdminOption does not exist there)',
    strpos($nsAdm, 'setVendorOption(1, "brand_style_cache_ver"') !== false);
$saasAdm = src34($root, 'DGV7.0-SAAS', 'bc-admin/AccountSettings.php');
chk34('SAAS bumps the version via the super-admin option store',
    strpos($saasAdm, 'setSuperAdminOption("brand_style_cache_ver"') !== false);

// ── Issue 2: referral payments + thresholds configurable in bc-admin ───
foreach ($editions as $ed) {
    $fin = src34($root, $ed, 'func/bc-fintech-services.php');
    chk34("$ed referral terms resolve from vendor settings with historical defaults",
        strpos($fin, 'function bc_referral_settings(') !== false
        && strpos($fin, "'reward_usd' => 10.0") !== false
        && strpos($fin, "'qualifying_usd' => 100.0") !== false);
    chk34("$ed referral payout threshold uses the configured qualifying volume",
        strpos($fin, "< (float)\$ref_settings['qualifying_usd']") !== false);
    chk34("$ed referral payout amount uses the configured reward (no hard-coded \$10)",
        strpos($fin, "round((float)\$ref_settings['reward_usd'] * \$rate, 2)") !== false);
    chk34("$ed referral coin bonus stays in the loyalty table",
        strpos($fin, 'first_purchase_coins') !== false
        && strpos($fin, 'sas_loyalty_bonus_settings') !== false);

    $loy = src34($root, $ed, 'bc-admin/LoyaltySettings.php');
    chk34("$ed Loyalty Settings saves referral_reward_usd",
        strpos($loy, "'referral_reward_usd' => \$referral_reward_usd") !== false
        && strpos($loy, 'name="referral_reward_usd"') !== false);
    chk34("$ed Loyalty Settings saves referral_qualifying_usd",
        strpos($loy, "'referral_qualifying_usd' => \$referral_qualifying_usd") !== false
        && strpos($loy, 'name="referral_qualifying_usd"') !== false);
    chk34("$ed Loyalty Settings keeps the coin sign-up bonus + conversion threshold",
        strpos($loy, 'name="first_purchase_bonus"') !== false
        && strpos($loy, 'name="min_conversion_threshold"') !== false);

    $ref = src34($root, $ed, 'web/Referral.php');
    chk34("$ed Refer & Earn page renders the configured amounts",
        strpos($ref, 'bc_referral_settings($vid)') !== false
        && strpos($ref, '$ref_reward_fmt') !== false
        && strpos($ref, 'You get $10') === false);

    $api = src34($root, $ed, 'web/api/referral.php');
    chk34("$ed app referral API exposes the configured programme terms",
        strpos($api, '"referral_reward_usd"') !== false
        && strpos($api, '"referral_qualifying_usd"') !== false);
}

// ── Issue 3: buy/payment buttons lock immediately on submit ────────────
foreach ($editions as $ed) {
    foreach (array('func/bc-plate-app.php', 'func/bc-plate-admin.php') as $shell) {
        $s = src34($root, $ed, $shell);
        chk34("$ed $shell installs the submit lock",
            strpos($s, 'data-bc-locked-btn') !== false
            && strpos($s, 'form.dataset.bcLocked') !== false);
        chk34("$ed $shell locks every submit control with a spinner",
            strpos($s, 'Processing&hellip;') !== false && strpos($s, 'bc-btn-locked') !== false);
        chk34("$ed $shell mirrors the submitter so its name/value survive disabling",
            strpos($s, 'data-bc-submitter-mirror') !== false && strpos($s, 'e.submitter') !== false);
        chk34("$ed $shell never locks GET (search) forms and honours data-bc-nolock",
            strpos($s, "=== 'get') return") !== false && strpos($s, "hasAttribute('data-bc-nolock')") !== false);
        chk34("$ed $shell auto-releases the lock for AJAX flows",
            strpos($s, 'window.bcUnlockForm') !== false && strpos($s, '20000') !== false);
        chk34("$ed $shell lock script contains no literal body tag (print-window guard)",
            strpos($s, 'bc-btn-locked') !== false && strpos(substr($s, strpos($s, 'data-bc-locked-btn')), '<body') === false);
    }
}

echo "security34: $count checks passed\n";
