<?php
/**
 * GATE: /web/api/* responses must be pure JSON for machine clients.
 *
 * Why this exists: the mobile apps parse every /web/api/* response as JSON, and they only show
 * "Failed to load data plans. Please check your connection." from a thrown exception - which for a
 * 2xx response means "the body did not parse as JSON". A single notice/warning/deprecation printed
 * by anything in the include chain (host php.ini has display_errors on, and /web/api/* was the only
 * API surface in this codebase with no guard) prefixes the payload and the app blames the network.
 *
 * This gate EXECUTES the real guard block extracted from func/bc-connect.php - not a copy of it -
 * under three simulated request environments, and asserts the effect on display_errors.
 *
 *   /web/api/data-plans.php              -> diagnostics suppressed   (the fix)
 *   /index.php  (browser, no app header) -> diagnostics untouched    (web pages keep their setup)
 *   /index.php  + X-App-Source header    -> diagnostics suppressed   (an app client is an app client)
 *
 * It also asserts the license kill-switches answer API clients with JSON BEFORE the HTML page, and
 * that an already-started response can no longer keep its 200 while carrying that HTML page.
 *
 * NEGATIVE CONTROL: the guard does not exist on the pinned pre-fix revision, so all three
 * environments report "diagnostics on" there. That is what makes a green run on the live tree mean
 * something.
 *
 * Usage: php -n scratch/probe_api_json_purity.php [--file=<path to bc-connect.php>]
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '1');

$argvRest = array_slice($argv, 1);
$optsFile = null;
foreach ($argvRest as $a) { if (strpos($a, '--file=') === 0) $optsFile = substr($a, 7); }

$MARKER = 'PHP 8.1+ Compatibility Fix';   // ASCII anchor: the line right after the guard block

// ─────────────────────────────────────────────────────────────────────────────────────────────
// Parent mode: run every edition live, then re-run the SAME assertions against the pre-fix revision.
// ─────────────────────────────────────────────────────────────────────────────────────────────
if ($optsFile === null) {
    $repoRoot = dirname(dirname(__DIR__));
    // >>> MUST BE THE COMMIT IMMEDIATELY BEFORE THE JSON-PURITY GUARD LANDED. <<<
    // Re-pin with: git rev-parse --short <guard-commit>^
    $preFixRev = 'b185942';
    $editions = ['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'];
    $rel = '/func/bc-connect.php';

    $failures = 0;

    foreach ($editions as $edition) {
        $relPath = $edition . $rel;
        $live = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);

        echo "================ LIVE: $relPath ================\n";
        passthru(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' --file=' . escapeshellarg($live), $liveRc);
        if ($liveRc !== 0) $failures++;

        echo "\n================ NEGATIVE CONTROL: $preFixRev:$relPath ================\n";
        $content = shell_exec('git -C ' . escapeshellarg($repoRoot) . ' show ' . escapeshellarg($preFixRev . ':' . $relPath) . ' 2>&1');
        if (!is_string($content) || $content === '' || strpos($content, 'fatal:') === 0) {
            echo "FATAL: cannot read $preFixRev:$relPath - the gate is unverified.\n";
            exit(3);
        }
        // The fixture must actually BE the pre-fix code, or the comparison proves nothing.
        if (strpos($content, 'bc_connect_is_api_client') !== false) {
            echo "FATAL: $preFixRev ALREADY contains the guard. Re-pin to the commit before it,\n";
            echo "or this control cannot discriminate.\n";
            exit(3);
        }
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bc_api_prefix_' . getmypid() . '_' . md5($edition) . '.php';
        if (file_put_contents($tmp, $content) === false) { echo "FATAL: cannot write fixture $tmp\n"; exit(3); }
        passthru(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' --file=' . escapeshellarg($tmp) . ' --expect-unguarded', $ctrlRc);
        @unlink($tmp);
        if (is_file($tmp)) { echo "FATAL: could not remove the control fixture $tmp\n"; exit(3); }
        if ($ctrlRc !== 0) $failures++;
    }

    echo "\n================ SUMMARY ================\n";
    echo $failures === 0
        ? "PASS - the guard suppresses diagnostics for API/app clients, leaves web requests alone,\n"
          . "       and the pinned pre-fix revision fails exactly those assertions.\n"
        : "FAIL - $failures run(s) did not hold.\n";
    exit($failures === 0 ? 0 : 1);
}

// ─────────────────────────────────────────────────────────────────────────────────────────────
// Child mode: inspect/extract/execute one file.
// ─────────────────────────────────────────────────────────────────────────────────────────────
$expectUnguarded = in_array('--expect-unguarded', $argvRest, true);
$src = @file_get_contents($optsFile);
if (!is_string($src) || $src === '') { echo "FATAL: cannot read $optsFile\n"; exit(3); }

$fail = 0;
$check = function ($label, $ok, $detail = '') use (&$fail) {
    printf("  [%s] %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail === '' ? '' : "  ($detail)");
    if (!$ok) $fail++;
};

// ── 1. Extract the REAL guard block that sits between <?php and the compatibility-fix marker.
$markerPos = strpos($src, $MARKER);
$guard = '';
if ($markerPos !== false) {
    $head = substr($src, 0, $markerPos);
    $open = strpos($head, '<?php');
    if ($open !== false) $guard = substr($head, $open + 5);
}
$hasGuard = $guard !== '' && strpos($guard, 'bc_connect_is_api_client') !== false;

if ($expectUnguarded) {
    $check('pre-fix revision has no guard block (control is real)', !$hasGuard);
} else {
    $check('guard block found above the compatibility-fix marker', $hasGuard);
}

// ── 2. Execute that block under three simulated environments and read back display_errors.
$envs = [
    'api path'            => ['uri' => '/web/api/data-plans.php', 'script' => '/web/api/data-plans.php', 'app' => false, 'want_off' => true],
    'web path (browser)'  => ['uri' => '/index.php',              'script' => '/index.php',              'app' => false, 'want_off' => false],
    'web path + app hdr'  => ['uri' => '/index.php',              'script' => '/index.php',              'app' => true,  'want_off' => true],
];
foreach ($envs as $name => $env) {
    $probe = "<?php\n"
        . "ini_set('display_errors', '1');\n"          // a host whose php.ini displays errors
        . "ini_set('html_errors', '1');\n"
        . "\$_SERVER['REQUEST_URI'] = " . var_export($env['uri'], true) . ";\n"
        . "\$_SERVER['SCRIPT_NAME'] = " . var_export($env['script'], true) . ";\n"
        . ($env['app'] ? "\$_SERVER['HTTP_X_APP_SOURCE'] = 'dgv6-android';\n" : "")
        . $guard . "\n"
        . "echo (ini_get('display_errors') ? '1' : '0') . '|' . (ini_get('html_errors') ? '1' : '0');\n";

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bc_api_env_' . getmypid() . '_' . md5($name) . '.php';
    if (file_put_contents($tmp, $probe) === false) { echo "FATAL: cannot write probe $tmp\n"; exit(3); }
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    if (is_file($tmp)) { echo "FATAL: could not remove the probe $tmp\n"; exit(3); }

    $got = is_string($out) ? trim($out) : '';
    $displayed = ($got === '' || $got[0] === '1');
    if ($expectUnguarded) {
        $check("$name: diagnostics still displayed (no guard)", $displayed, "display_errors=" . ($displayed ? '1' : '0'));
    } else {
        $check(
            "$name: diagnostics " . ($env['want_off'] ? 'suppressed' : 'left alone'),
            $displayed !== $env['want_off'],
            "display_errors=" . ($displayed ? '1' : '0')
        );
    }
}

// ── 3. Licence kill-switches: JSON for API clients must be emitted BEFORE the HTML block page.
foreach ([
    'LICENSE_SUSPENDED' => 'System Suspended',
    'LICENSE_INVALID'   => 'Activation Pending',
] as $code => $htmlMarker) {
    $jsonPos = strpos($src, $code);
    $htmlPos = strpos($src, $htmlMarker);
    if ($expectUnguarded) {
        if ($jsonPos !== false) $check("$code must not exist pre-fix", false, "found at offset $jsonPos");
    } elseif ($htmlPos === false) {
        $check("$code: no such branch in this edition (nothing to assert)", true);
    } else {
        $check("$code JSON precedes the HTML block page", $jsonPos !== false && $jsonPos < $htmlPos);
    }
}

// ── 4. The status can no longer be silently downgraded to 200 on an already-started response.
if (!$expectUnguarded) {
    $check(
        'block-page status changes are guarded by headers_sent()',
        substr_count($src, 'if (!headers_sent()) {') >= 1
    );
}

echo $fail === 0 ? "  -> PASS\n" : "  -> FAIL ($fail)\n";
exit($fail === 0 ? 0 : 1);
