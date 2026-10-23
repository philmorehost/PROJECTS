<?php
/**
 * Measures the SCHEMA VERSION GATE at the top of func/bc-tables.php.
 *
 * Run with:  C:\xampp\php\php.exe -n tests/bc_tables_gate_test.php
 *
 * Why this exists: bc-tables.php is included by every mobile API request, every admin page, every
 * super-admin page, a couple of web endpoints and every cron run. Before the gate it issued its whole
 * DDL set (528 mysqli_query call sites: 146 CREATE TABLE IF NOT EXISTS, 54 SHOW COLUMNS, 23 SHOW INDEX,
 * 127 conditional ALTERs) on every one of those requests, which is what made every page slow and made
 * transactions look like they hung before doing anything.
 *
 * The file is executed here for real, against a stubbed mysqli layer, in the two states that matter:
 *   - "fresh"  : no recorded schema version  -> the migrations must run and then record the version
 *   - "current": the recorded version matches -> the gate must take the fast path (a couple of queries)
 */

$src_file = __DIR__ . '/../func/bc-tables.php';
$src = file_get_contents($src_file);
if ($src === false) { fwrite(STDERR, "cannot read $src_file\n"); exit(1); }
$src = preg_replace('#^<\?php#', '', $src, 1);

// Static size of the thing we are avoiding (reported, not asserted, so it is visible in the output).
$call_sites    = substr_count($src, 'mysqli_query(');
$create_tables = substr_count($src, 'CREATE TABLE IF NOT EXISTS');

// Read the version the file itself declares instead of hard-coding it, so bumping the schema
// version (required whenever DDL is added) does not silently turn every scenario below into
// "an older recorded version" and fail the steady-state assertions.
preg_match("/BC_TABLES_VERSION',\s*'([^']+)'/", $src, $bc_t_version_match);
$bc_tables_version = $bc_t_version_match[1] ?? '';

$fails = 0;
$checks = 0;
function bc_assert($label, $actual, $expected) {
    global $fails, $checks;
    $checks++;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL  $label  (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    } else {
        echo "ok    $label\n";
    }
}

// ── Stub the database layer (this test runs with -n, so ext-mysqli is absent) ─────────────────────
$GLOBALS['bc_t_count'] = 0;
$GLOBALS['bc_t_seen']  = [];
$GLOBALS['bc_t_marker'] = null;      // what the options lookup should return
$GLOBALS['bc_t_core']   = true;      // does the core table exist?

if (!function_exists('mysqli_query')) {
    function mysqli_query($conn, $sql) {
        $GLOBALS['bc_t_count']++;
        $norm = strtoupper(preg_replace('/\s+/', ' ', trim($sql)));
        $GLOBALS['bc_t_seen'][] = strtolower(substr($norm, 0, 60));

        if (strpos($norm, 'SHOW TABLES LIKE') === 0) {
            return $GLOBALS['bc_t_core'] ? 'CORE' : 'EMPTY';
        }
        if (strpos($norm, 'SELECT OPTION_VALUE FROM SAS_SUPER_ADMIN_OPTIONS') === 0 && strpos($norm, 'BC_SCHEMA_VERSION') !== false) {
            return $GLOBALS['bc_t_marker'] === null ? 'EMPTY' : 'MARKER';
        }
        return 'GENERIC';
    }
}
if (!function_exists('mysqli_num_rows')) {
    function mysqli_num_rows($r) { return ($r === 'EMPTY') ? 0 : 1; }
}
if (!function_exists('mysqli_fetch_assoc')) {
    function mysqli_fetch_assoc($r) {
        if ($r === 'MARKER') return ['option_value' => $GLOBALS['bc_t_marker']];
        return null; // ends the SHOW COLUMNS / SELECT while-loops in the migration file
    }
}
if (!function_exists('mysqli_real_escape_string')) {
    function mysqli_real_escape_string($c, $s) { return addslashes((string)$s); }
}
if (!function_exists('mysqli_error')) {
    function mysqli_error($c) { return ''; }
}
if (!function_exists('getSuperAdminOption')) {
    function getSuperAdminOption($name, $default = '') { return $default; }
}

/** Reset the stub state before a run. */
function bc_t_setup($marker, $core_exists = true) {
    $GLOBALS['bc_t_count']  = 0;
    $GLOBALS['bc_t_seen']   = [];
    $GLOBALS['bc_t_marker'] = $marker;
    $GLOBALS['bc_t_core']   = $core_exists;
}

/** Write the shipped source to a temp file to include. NOTE: the include itself must happen at the
 *  top level of this script - the migration file reads $connection_server as a normal variable, and a
 *  global is not visible from inside a function's scope. */
function bc_t_prepare($src) {
    $tmp = tempnam(sys_get_temp_dir(), 'bct') . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    return $tmp;
}

function bc_t_result() {
    return ['count' => $GLOBALS['bc_t_count'], 'seen' => $GLOBALS['bc_t_seen']];
}

// Every run below is: set the stub state, include at top level, collect.
$connection_server = 'STUB';

echo "bc-tables.php: $call_sites mysqli_query call sites, $create_tables CREATE TABLE IF NOT EXISTS\n\n";

// ── 1. Steady state: the recorded version matches this file ──────────────────────────────────────
bc_t_setup($bc_tables_version);
$bc_t_file = bc_t_prepare($src);
include $bc_t_file;
@unlink($bc_t_file);
$current = bc_t_result();
bc_assert('a request with the current schema version issues at most 3 statements', $current['count'] <= 3, true);
echo "      (issued " . $current['count'] . ": " . implode(' | ', $current['seen']) . ")\n";
$ran_create = false;
foreach ($current['seen'] as $s) { if (strpos($s, 'create table') === 0) $ran_create = true; }
bc_assert('no CREATE TABLE runs on a normal request', $ran_create, false);
bc_assert('the gate checked the recorded version', strpos(implode(' ', $current['seen']), 'sas_super_admin_options') !== false, true);

// ── 2. Fresh / new version: no marker recorded -> the migrations must run ────────────────────────
bc_t_setup(null);
$bc_t_file = bc_t_prepare($src);
include $bc_t_file;
@unlink($bc_t_file);
$fresh = bc_t_result();
bc_assert('an unrecorded schema still runs the migrations', $fresh['count'] > 50, true);
echo "      (issued " . $fresh['count'] . " statements - the full DDL set)\n";
$recorded = false;
foreach ($fresh['seen'] as $s) { if (strpos($s, 'insert into sas_super_admin_options') === 0) $recorded = true; }
bc_assert('a completed run records the schema version for next time', $recorded, true);

// ── 3. Restored / emptied database: marker present but the core table is gone ─────────────────────
bc_t_setup($bc_tables_version, false);
$bc_t_file = bc_t_prepare($src);
include $bc_t_file;
@unlink($bc_t_file);
$restored = bc_t_result();
bc_assert('a missing core table re-runs the migrations instead of trusting the marker', $restored['count'] > 50, true);

// ── 4. The escape hatch for installers and debugging ─────────────────────────────────────────────
$GLOBALS['bc_tables_force_run'] = true;
bc_t_setup($bc_tables_version);
$bc_t_file = bc_t_prepare($src);
include $bc_t_file;
@unlink($bc_t_file);
$forced = bc_t_result();
unset($GLOBALS['bc_tables_force_run']);
bc_assert('bc_tables_force_run=true runs the migrations even when the version matches', $forced['count'] > 50, true);

// ── 5. A wrong/older recorded version must not skip the work ─────────────────────────────────────
bc_t_setup('2025.01.01-0');
$bc_t_file = bc_t_prepare($src);
include $bc_t_file;
@unlink($bc_t_file);
$older = bc_t_result();
bc_assert('an older recorded version re-runs the migrations', $older['count'] > 50, true);

echo "\n$checks checks, $fails failures\n";
echo "Reduction on a normal request: $call_sites potential statements -> " . $current['count'] . "\n";
exit($fails === 0 ? 0 : 1);
