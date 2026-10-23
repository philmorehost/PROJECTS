<?php
/**
 * KYC checks: one badge per check, whatever the settings table holds.
 *
 * Run with:  C:\xampp\php\php.exe -n tests/kyc_checks_test.php
 *
 * Why this exists: sas_kyc_verifications had no unique key while the installer seeded it with
 * INSERT IGNORE on EVERY request, so each page load appended another copy of the six default checks
 * for every vendor. Consumers that appended to a plain array then showed one entry per copy - the
 * review console listed "Liveliness video / Live photo / Government ID" dozens of times for a single
 * user, and the mobile endpoint asked the app for the same document several times over.
 *
 * Section A runs the SHIPPED helper in func/bc-security.php against a table full of duplicates.
 * Section B asserts the write paths that caused the duplicates are now idempotent.
 * Section C asserts both editions are in sync where this file is duplicated.
 */

$ED = array(
    'SAAS'     => __DIR__ . '/..',
    'NON-SAAS' => __DIR__ . '/../../DGV7.0-NON-SAAS',
);

$fails = 0;
$checks = 0;
function bc_assert($label, $actual, $expected) {
    global $fails, $checks;
    $checks++;
    if ($actual === $expected) {
        echo "  ok   $label\n";
    } else {
        $fails++;
        echo "  FAIL $label\n";
        echo "         expected: " . var_export($expected, true) . "\n";
        echo "         actual  : " . var_export($actual, true) . "\n";
    }
}
function bc_assert_true($label, $cond) { bc_assert($label, (bool)$cond, true); }

// ── A tiny result cursor: every stubbed result ends like a real one, or a while() never stops ────
class K_Result {
    public $rows;
    public $i = 0;
    public function __construct($rows) { $this->rows = $rows; }
}
function mysqli_fetch_assoc($result) {
    if (!($result instanceof K_Result)) return null;
    if ($result->i >= count($result->rows)) return null;
    return $result->rows[$result->i++];
}
function mysqli_real_escape_string($link, $s) { return addslashes($s); }

// Exactly what the production table looked like: the same three checks, several times each.
$GLOBALS['k_check_rows'] = array(
    array('verification_name' => 'bvn', 'status' => '0'),
    array('verification_name' => 'bvn', 'status' => '1'),   // one copy was left enabled
    array('verification_name' => 'bvn', 'status' => '0'),
    array('verification_name' => 'nin', 'status' => '2'),
    array('verification_name' => 'govt_id', 'status' => '1'),
    array('verification_name' => 'govt_id', 'status' => '1'),
    array('verification_name' => 'govt_id', 'status' => '1'),
    array('verification_name' => 'liveliness_video', 'status' => '0'),
    array('verification_name' => 'liveliness_picture', 'status' => '0'),
    array('verification_name' => 'proof_of_address', 'status' => '2'),
);
$GLOBALS['k_queries'] = array();
$GLOBALS['k_group_by_fails'] = false;

function mysqli_query($link, $query) {
    $GLOBALS['k_queries'][] = $query;
    if (stripos($query, 'sas_kyc_verifications') === false) return false;
    if (stripos($query, 'GROUP BY') !== false) {
        if ($GLOBALS['k_group_by_fails']) return false;
        // Mimic what MySQL returns for the grouped query the helper asks for.
        $grouped = array();
        foreach ($GLOBALS['k_check_rows'] as $r) {
            $name = $r['verification_name'];
            $on   = ((int)$r['status'] === 1) ? 1 : 0;
            $grouped[$name] = isset($grouped[$name]) ? max($grouped[$name], $on) : $on;
        }
        $rows = array();
        foreach ($grouped as $name => $on) $rows[] = array('verification_name' => $name, 'enabled' => $on);
        return new K_Result($rows);
    }
    return new K_Result($GLOBALS['k_check_rows']);
}

echo "== A. shipped helper against a table full of duplicates ==\n";
$helper_file = realpath(__DIR__ . '/../func/bc-security.php');
require_once($helper_file);
bc_assert_true('helper is defined by the shipped file', function_exists('bc_kyc_enabled_checks') && function_exists('bc_kyc_effective_statuses'));

$connection_server = 'STUB';

$enabled = bc_kyc_enabled_checks($connection_server, 7);
bc_assert('each enabled check appears exactly once', $enabled, array('bvn', 'govt_id'));
bc_assert('the enabled copy wins over disabled copies of the same check', in_array('bvn', $enabled, true), true);

$statuses = bc_kyc_effective_statuses($connection_server, 7);
bc_assert('exactly one entry per check name', count($statuses), 6);
bc_assert('bvn enabled', $statuses['bvn'], 1);
bc_assert('govt_id enabled', $statuses['govt_id'], 1);
bc_assert('nin disabled (status 2 means off)', $statuses['nin'], 0);
bc_assert('proof_of_address disabled', $statuses['proof_of_address'], 0);
bc_assert_true('the helper asks the database to group, so duplicates cannot reach the caller', (bool)preg_match('/GROUP BY\s+verification_name/i', implode(" \n", $GLOBALS['k_queries'])));

// A second call must be stable (the old append-style loop grew the list on every call site).
$again = bc_kyc_enabled_checks($connection_server, 7);
bc_assert('repeated reads return the same list', $again, $enabled);

// Unexecutable GROUP BY query (unexpected schema): the fallback must still collapse by name.
$GLOBALS['k_group_by_fails'] = true;
$fallback = bc_kyc_enabled_checks($connection_server, 7);
bc_assert('fallback path is still de-duplicated', $fallback, array('bvn', 'govt_id'));
$GLOBALS['k_group_by_fails'] = false;

// Nothing to read -> nothing to render.
bc_assert('no vendor id yields no checks', bc_kyc_enabled_checks($connection_server, 0), array());
bc_assert('no connection yields no checks', bc_kyc_effective_statuses(null, 7), array());

echo "\n== B. write paths can no longer create duplicates ==\n";
foreach ($ED as $edition => $root) {
    echo "-- $edition\n";

    $config = file_get_contents($root . '/func/bc-config.php');
    bc_assert_true("$edition: installer collapses existing duplicate rows", strpos($config, 'DELETE t1 FROM `sas_kyc_verifications` t1 JOIN `sas_kyc_verifications` t2') !== false);
    bc_assert_true("$edition: unique key added for (vendor_id, verification_name)", strpos($config, 'ADD UNIQUE KEY uniq_vendor_check (vendor_id, verification_name)') !== false);
    bc_assert_true("$edition: rows get a stable id first", strpos($config, "LIKE 'id'") !== false && strpos($config, 'AUTO_INCREMENT PRIMARY KEY FIRST') !== false);
    bc_assert_true("$edition: seeding only touches vendors still missing defaults", strpos($config, 'WHERE COALESCE(k.c, 0) < ') !== false);
    bc_assert_true("$edition: seeding still uses INSERT IGNORE", strpos($config, 'INSERT IGNORE INTO sas_kyc_verifications') !== false);

    $pg = file_get_contents($root . '/bc-admin/PaymentGateway.php');
    bc_assert_true("$edition: settings save is idempotent (no bare INSERT)", strpos($pg, 'INSERT INTO sas_kyc_verifications') === false);
    bc_assert_true("$edition: settings save uses INSERT IGNORE + UPDATE", strpos($pg, 'INSERT IGNORE INTO sas_kyc_verifications') !== false && strpos($pg, "UPDATE sas_kyc_verifications SET status=") !== false);
    bc_assert_true("$edition: settings page reads through the helper", strpos($pg, 'bc_kyc_effective_statuses(') !== false);

    $console = file_get_contents($root . '/bc-admin/KYCManagement.php');
    bc_assert_true("$edition: review console reads through the helper", strpos($console, 'bc_kyc_enabled_checks($connection_server, $vid)') !== false);
    bc_assert_true("$edition: review console caps the badge list", strpos($console, 'count($vendor_kyc_checks) > 12') !== false);
    bc_assert_true("$edition: no raw settings SELECT left in the console", strpos($console, 'FROM sas_kyc_verifications') === false);

    $site = file_get_contents($root . '/web/KYCVerification.php');
    bc_assert_true("$edition: website reads through the helper", strpos($site, 'bc_kyc_effective_statuses($connection_server, $vid)') !== false && strpos($site, 'FROM sas_kyc_verifications') === false);

    $api = file_get_contents($root . '/web/api/kyc.php');
    bc_assert_true("$edition: mobile endpoint reads through the helper", strpos($api, 'bc_kyc_enabled_checks($connection_server, $vendor_id)') !== false && strpos($api, 'FROM sas_kyc_verifications') === false);

    bc_assert_true("$edition: request-time KYC gate reads through the helper", strpos($config, '$kyc_data = bc_kyc_effective_statuses($connection_server, $vendor_id);') !== false);
}

echo "\n== C. editions stay in sync ==\n";
foreach (array('bc-admin/KYCManagement.php', 'web/KYCVerification.php', 'web/api/kyc.php') as $rel) {
    bc_assert("$rel is identical in both editions", md5_file($ED['SAAS'] . '/' . $rel), md5_file($ED['NON-SAAS'] . '/' . $rel));
}

echo "\n----------------------------------------\n";
echo ($checks - $fails) . "/$checks checks passed" . ($fails ? " — $fails FAILED" : '') . "\n";
exit($fails ? 1 : 0);
