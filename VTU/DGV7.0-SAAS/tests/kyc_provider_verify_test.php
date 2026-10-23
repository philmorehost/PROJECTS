<?php
/**
 * Automated KYC with an identity provider (Dojah / QoreID / Smile ID / Monnify).
 *
 * Run with:  C:\xampp\php\php.exe -n tests/kyc_provider_verify_test.php
 *
 * Why this exists: the only automated KYC was VoveID's signed webhook. Every other provider was used
 * for lookups only - and the "submit BVN/NIN" handlers just stored whatever 11 digits the user typed,
 * with no provider check at all. This helper now verifies the number against the provider, records who
 * verified it, and approves the account when the provider proved everything the vendor requires -
 * while any vendor that also wants a document/selfie/video/address keeps that submission for a human.
 *
 * Section A runs the SHIPPED helper against a stubbed database, so the whole decision is exercised.
 * Section B asserts the two submission paths actually call the provider, in both editions.
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

// ── Stubbed database ─────────────────────────────────────────────────────────────────────────────
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

$GLOBALS['k_user'] = array();
$GLOBALS['k_enabled'] = array();
$GLOBALS['k_updates'] = array();

function mysqli_query($link, $query) {
    $q = trim(preg_replace('/\s+/', ' ', $query));

    // The vendor's enabled checks (bc_kyc_enabled_checks -> grouped read).
    if (stripos($q, 'sas_kyc_verifications') !== false) {
        $rows = array();
        foreach ($GLOBALS['k_enabled'] as $name) {
            $rows[] = array('verification_name' => $name, 'enabled' => 1);
        }
        return new K_Result($rows);
    }

    if (stripos($q, 'UPDATE sas_users SET') === 0) {
        $GLOBALS['k_updates'][] = $q;
        // Apply the assignments the helper wrote, the way MySQL would: col='value' or col=<literal>.
        $set_part = preg_replace('/^UPDATE sas_users SET /i', '', $q);
        $set_part = preg_replace('/ WHERE .*$/i', '', $set_part);
        preg_match_all("/([a-z_]+)\s*=\s*'([^']*)'|([a-z_]+)\s*=\s*(NOW\(\)|COALESCE\([^)]*\)|NULL)/i", $set_part, $m, PREG_SET_ORDER);
        foreach ($m as $pair) {
            if (!empty($pair[1])) {
                $GLOBALS['k_user'][$pair[1]] = $pair[2];
            } elseif (!empty($pair[3])) {
                $GLOBALS['k_user'][$pair[3]] = ($pair[4] === 'NULL') ? null : 'stamp';
            }
        }
        return true;
    }

    if (stripos($q, 'SELECT * FROM sas_users') === 0) {
        return new K_Result(array($GLOBALS['k_user']));
    }

    return false;
}

// getIdentityProvider() lives in func/bc-func.php; the helper only needs a name back.
function getIdentityProvider($vid = null) { return 'dojah'; }

require_once(__DIR__ . '/../func/bc-security.php');

$connection_server = 'STUB';

function kyc_reset($enabled, $user, $verdict, $kind = 'bvn', $number = '12345678901') {
    $GLOBALS['k_enabled'] = $enabled;
    $GLOBALS['k_updates'] = array();
    $GLOBALS['k_user'] = array_merge(array(
        'id' => 44, 'vendor_id' => 1, 'firstname' => 'Ada', 'lastname' => 'Obi',
        'kyc_status' => 0, 'kyc_id_type' => '', 'bvn' => '', 'nin' => '',
        'govt_id_card' => '', 'kyc_face_image' => '', 'liveliness_picture' => '',
        'liveliness_video' => '', 'proof_of_address' => '',
    ), $user);

    return bc_kyc_provider_verify($GLOBALS['connection_server'] ?? 'STUB', 1, 44, $kind, $number, $verdict, 'KYC-BVN-44');
}

echo "== A. the decision (executed from the shipped helper) ==\n";

$match = array('status' => 'success', 'firstname' => 'Ada', 'lastname' => 'Obi', 'provider' => 'dojah');

// 1. The vendor only requires BVN, and the provider matched it to this account holder.
$r = kyc_reset(array('bvn'), array(), $match);
bc_assert('provider match + BVN-only vendor -> verified', $r['status'], 'verified');
bc_assert('...and approved automatically', $r['auto_approved'], true);
bc_assert('...with kyc_status 2', $r['kyc_status'], 2);
bc_assert('the verified number is stored', $GLOBALS['k_user']['bvn'], '12345678901');
bc_assert('the number is marked provider-verified', (string)$GLOBALS['k_user']['kyc_api_verified'], '1');
bc_assert('the provider is recorded', $GLOBALS['k_user']['kyc_provider'], 'dojah');
bc_assert('a reference is recorded', $GLOBALS['k_user']['kyc_provider_ref'], 'KYC-BVN-44');

// 2. The vendor also wants a live photo: that upload has to be looked at by a person.
$r = kyc_reset(array('bvn', 'liveliness_picture'), array('liveliness_picture' => 'selfie_1.jpg'), $match);
bc_assert('uploaded selfie is NOT auto-approved', $r['auto_approved'], false);
bc_assert('...the account waits for review', $r['kyc_status'], 1);
bc_assert_true('...and the reviewer is told what to look at', strpos($r['message'], 'Live photo') !== false);
bc_assert('...nothing is outstanding from the provider side', $r['still_needed'], array());
bc_assert('the identity is still marked verified', (string)$GLOBALS['k_user']['kyc_api_verified'], '1');

// 3. A vendor wanting BVN *and* NIN: one proved, one outstanding -> still manual.
$r = kyc_reset(array('bvn', 'nin'), array(), $match);
bc_assert('second API check outstanding -> not approved', $r['auto_approved'], false);
bc_assert('...pending review', $r['kyc_status'], 1);
bc_assert('...with NIN listed as still needed', $r['still_needed'], array('nin'));

// 4. A stale rejection is cleared by a successful verification + approval.
$r = kyc_reset(array('bvn'), array('kyc_status' => 3, 'kyc_reject_reason' => 'blurry'), $match);
bc_assert('a re-verification approves again', $r['auto_approved'], true);
bc_assert('...and clears the old rejection reason', $GLOBALS['k_user']['kyc_reject_reason'], null);

// 5. An already-verified account is never downgraded by a second verification.
$r = kyc_reset(array('bvn', 'liveliness_picture'), array('kyc_status' => 2, 'liveliness_picture' => 's.jpg'), $match);
bc_assert('an existing approval stays approved', $r['kyc_status'], 2);
bc_assert('...and is not re-approved/duplicated', $r['auto_approved'], false);

// 6. Anything that is not a definitive provider success must verify nothing.
foreach (array(
    'provider failure'        => array('status' => 'failed', 'message' => 'No record found'),
    'unreadable/empty reply'  => array(),
    'name mismatch (failed)'  => array('status' => 'failed', 'message' => 'name does not match records'),
) as $label => $verdict) {
    $r = kyc_reset(array('bvn'), array(), $verdict);
    bc_assert("$label -> nothing verified", $r['status'], 'failed');
    bc_assert("$label -> never approved", $r['auto_approved'], false);
    // The helper writes nothing at all on a verdict it cannot trust; marking the number as unverified is
    // the caller's job (asserted in section B).
    bc_assert("$label -> no database write", $GLOBALS['k_updates'], array());
}

// 7. No required checks at all: record the verification, approve nothing.
$r = kyc_reset(array(), array(), $match);
bc_assert('vendor with no checks -> not approved', $r['auto_approved'], false);
bc_assert('vendor with no checks -> status untouched', $r['kyc_status'], 0);
bc_assert('vendor with no checks -> the verification is still recorded', (string)$GLOBALS['k_user']['kyc_api_verified'], '1');

// 8. Empty/invalid input never verifies.
$r = kyc_reset(array('bvn'), array(), $match, 'bvn', '');
bc_assert('an empty number never verifies', $r['status'], 'failed');

// 9. Only bvn/nin are treated as provider-provable.
bc_assert('the provider-provable set is bvn+nin', bc_kyc_api_verifiable_checks(), array('bvn', 'nin'));

echo "\n== B. the submission paths actually call the provider ==\n";
foreach ($ED as $edition => $root) {
    echo "-- $edition\n";

    $site = file_get_contents($root . '/web/KYCVerification.php');
    bc_assert_true("$edition: website verifies before saving", strpos($site, 'verifyBvnNin($value, $type') !== false);
    bc_assert_true("$edition: website records the outcome through the helper", strpos($site, 'bc_kyc_provider_verify($connection_server, $vid, $uid, $type, $value, $verification') !== false);
    bc_assert_true("$edition: website no longer stores a typed number as if checked", strpos($site, 'kyc_api_verified=\'0\'') !== false && strpos($site, "\"UPDATE sas_users SET \$type='\$value' WHERE id=") === false);

    $api = file_get_contents($root . '/web/api/kyc.php');
    bc_assert_true("$edition: app verifies before saving", strpos($api, 'verifyBvnNin($value, $type') !== false);
    bc_assert_true("$edition: app reports auto-approval to the app", strpos($api, '"auto_approved"') !== false && strpos($api, 'bc_kyc_provider_verify(') !== false);
    bc_assert_true("$edition: app keeps an unverified number marked unverified", strpos($api, 'kyc_api_verified=\'0\'') !== false);

    $console = file_get_contents($root . '/bc-admin/KYCManagement.php');
    bc_assert_true("$edition: console shows who verified the number", strpos($console, 'kyc_api_verified') !== false && strpos($console, 'Verified with') !== false);
    bc_assert_true("$edition: console warns about an unchecked number", strpos($console, 'not</strong> checked with an identity provider') !== false);

    $config = file_get_contents($root . '/func/bc-config.php');
    bc_assert_true("$edition: audit columns are migrated", strpos($config, '"kyc_api_verified" => "TINYINT(1) DEFAULT 0"') !== false);

    $tables = file_get_contents($root . '/func/bc-tables.php');
    bc_assert_true("$edition: audit columns exist for the admin pages too", strpos($tables, '"kyc_provider_data" => "LONGTEXT NULL"') !== false);
    bc_assert_true("$edition: schema version bumped for them", strpos($tables, "BC_TABLES_VERSION', '2026.09.19-4'") !== false);
}

echo "\n----------------------------------------\n";
echo ($checks - $fails) . "/$checks checks passed" . ($fails ? " — $fails FAILED" : '') . "\n";
exit($fails ? 1 : 0);
