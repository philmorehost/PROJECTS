<?php
/**
 * Regression checks for isolated temporary vendor domains and host-bound sessions.
 *
 * Run from the DGV7.0-SAAS folder with: php -n tests/temporary_vendor_access_test.php
 */

$source_path = dirname(__DIR__) . '/func/bc-func.php';
$source = file_get_contents($source_path);
if ($source === false) {
    fwrite(STDERR, "Cannot read $source_path\n");
    exit(1);
}

$start = strpos($source, 'function bc_clean_vendor_domain');
$end = strpos($source, 'function bc_log_ai_intelligence', $start);
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "Cannot locate temporary vendor helpers in $source_path\n");
    exit(1);
}

class TemporaryVendorTestResult
{
    public $row;

    public function __construct($row)
    {
        $this->row = $row;
    }
}

$GLOBALS['connection_server'] = 'test-connection';
$GLOBALS['temporary_vendor_option'] = 'temporary.example.com';
$GLOBALS['temporary_vendor_row'] = [
    'id' => 17,
    'email' => 'vendor@example.com',
    'status' => 1,
    'is_blocked' => 0,
    'reg_date' => date('Y-m-d H:i:s', time() - 3600)
];

function getSuperAdminOption($name, $default = '')
{
    if ($name === 'temporary_vendor_domain') return $GLOBALS['temporary_vendor_option'];
    if ($name === 'tmp_url_expiry_days') return 3;
    return $default;
}

function mysqli_real_escape_string($connection, $value)
{
    return addslashes($value);
}

function mysqli_query($connection, $query)
{
    if (strpos($query, 'FROM sas_vendors') !== false) {
        return new TemporaryVendorTestResult($GLOBALS['temporary_vendor_row']);
    }
    return false;
}

function mysqli_fetch_assoc($result)
{
    return $result->row;
}

eval(substr($source, $start, $end - $start));

$assertions = 0;
$failures = 0;
function check_temporary_access($label, $got, $expected)
{
    global $assertions, $failures;
    $assertions++;
    if ($got === $expected) {
        echo "  ok   - $label\n";
    } else {
        $failures++;
        echo "  FAIL - $label (expected " . var_export($expected, true) . ', got ' . var_export($got, true) . ")\n";
    }
}

$_SERVER['HTTP_HOST'] = 'temporary.example.com:8443';
$_SESSION = [
    'tmp_vendor_host' => 'temporary.example.com',
    'tmp_vendor_override' => 17,
    'admin_session' => 'vendor@example.com'
];

check_temporary_access('normalizes port and www aliases', bc_normalize_vendor_host('www.temporary.example.com:8443'), 'temporary.example.com');
check_temporary_access('recognizes configured temporary host', bc_is_temporary_vendor_host(), true);
check_temporary_access('builds portal link on configured HTTPS host', bc_temporary_vendor_portal_url(str_repeat('a', 64)), 'https://temporary.example.com/VendorOrderPortal.php?hash=' . str_repeat('a', 64));
check_temporary_access('accepts the full-length random token format', bc_is_valid_vendor_access_hash(str_repeat('a', 64)), true);
check_temporary_access('rejects legacy 32-character portal hashes', bc_temporary_vendor_portal_url(str_repeat('a', 32)), '');
check_temporary_access('rejects malformed portal token', bc_temporary_vendor_portal_url('short'), '');
check_temporary_access('accepts matching, active vendor session', bc_temporary_vendor_admin_session_is_valid(), true);

$_SERVER['HTTP_HOST'] = 'vendor.example.com';
check_temporary_access('rejects temporary session on vendor website host', bc_temporary_vendor_admin_session_is_valid(), false);
$_SERVER['HTTP_HOST'] = 'temporary.example.com';
$GLOBALS['temporary_vendor_row']['is_blocked'] = 1;
check_temporary_access('rejects blocked vendor session', bc_temporary_vendor_admin_session_is_valid(), false);
$GLOBALS['temporary_vendor_row']['is_blocked'] = 0;
$GLOBALS['temporary_vendor_option'] = '';
check_temporary_access('rejects portal links when domain is unset', bc_temporary_vendor_portal_url(str_repeat('a', 64)), '');
check_temporary_access('does not classify hosts as temporary when domain is unset', bc_is_temporary_vendor_host(), false);

echo "\n$assertions assertions, $failures failures\n";
exit($failures === 0 ? 0 : 1);
