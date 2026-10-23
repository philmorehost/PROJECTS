<?php
/**
 * GATE: every page that calls the password helpers must be able to load them.
 *
 * THE BUG THIS CATCHES (production, 2026-09-28):
 *   bc-spadmin/VendorReg.php died with
 *     "Call to undefined function bc_hash_password()" at line 86
 *   after a new vendor placed an order.
 *
 * WHY: the helpers live in func/bc-security.php, which is only loaded by the full configs
 * (bc-config.php, bc-admin-config.php, bc-spadmin-config.php). VendorReg.php deliberately
 * bootstraps with the "basic configs" (bc-connect + bc-tables + bc-email-templates + bc-func +
 * whmcs-func) so it can run before a super admin is signed in - and none of those load
 * bc-security.php. PHP only fails on an undefined function when the call is *reached*, so this
 * sat dormant until someone actually registered a vendor.
 *
 * This checks every caller in both editions and reports any whose include chain cannot reach
 * bc-security.php. It is transitive (page -> config -> bc-security) and it is edition-agnostic.
 *
 * A pinned pre-fix revision is used as a NEGATIVE CONTROL: the checker must flag that file.
 * A gate nobody has seen fail is a gate nobody should trust.
 *
 * Usage: php -n scratch/check_password_helper_wiring.php
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '1');

$repoRoot = dirname(dirname(__DIR__));
$helpers  = array('bc_hash_password', 'bc_verify_password', 'bc_password_is_legacy');
$security = 'bc-security.php';

// The commit that still contained the bug. Re-pin only if history is rewritten.
$preFixRev = 'd6d3108';
$preFixFile = 'DGV7.0-SAAS/bc-spadmin/VendorReg.php';

$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');

$checks = 0;
$fails  = 0;
function ok($cond, $label)
{
    global $checks, $fails;
    $checks++;
    if ($cond) { echo "ok   : $label\n"; }
    else { $fails++; echo "FAIL : $label\n"; }
}

/** Collect include/require targets, resolved relative to the including file. */
function include_targets($content, $dir)
{
    $out = array();
    // Capture the whole include expression, not just an immediately-quoted path: the configs use
    // `include_once(__DIR__ . "/bc-security.php")`, which a quote-right-after-the-paren regex misses.
    if (preg_match_all('/\b(?:include|include_once|require|require_once)\s*\(?\s*([^;\n]*)/i', $content, $m)) {
        foreach ($m[1] as $expr) {
            if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $expr, $q)) {
                foreach ($q[1] as $t) {
                    // Both "x.php" and __DIR__ . "/x.php" resolve against the including file's dir.
                    $out[] = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($t, '/'));
                }
            }
        }
    }
    return $out;
}

/** Breadth-first: can this file reach bc-security.php through its include chain? */
function reaches_security($path, $visited = array(), $depth = 0)
{
    if ($depth > 6 || in_array($path, $visited, true) || !is_file($path)) return false;
    $visited[] = $path;
    $content = (string)file_get_contents($path);
    $dir = dirname($path);
    foreach (include_targets($content, $dir) as $target) {
        if (basename($target) === 'bc-security.php') return true;
        if (reaches_security($target, $visited, $depth + 1)) return true;
    }
    return false;
}

/** Remove comments so a mention of "bc_hash_password()" in prose is not mistaken for a call. */
function strip_php_comments($src)
{
    $src = preg_replace('/\/\*.*?\*\//s', ' ', $src);
    $src = preg_replace('/^\s*\/\/.*$/m', '', $src);
    $src = preg_replace('/(^|\s)\/\/[^\n]*/', '$1', $src);
    return $src;
}

/** Every file whose source *calls* one of the helpers (definition + tests excluded). */
function callers($dir, $helpers)
{
    $hits = array();
    if (!is_dir($dir)) return $hits;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (strtolower($f->getExtension()) !== 'php') continue;
        $p = $f->getPathname();
        if (strpos($p, 'bc-security.php') !== false) continue;          // the definitions
        // Skip harness/tooling trees by path SEGMENT (escaping-safe, unlike a char class here).
        $segments = explode(DIRECTORY_SEPARATOR, $p);
        if (in_array('tests', $segments, true) || in_array('scratch', $segments, true)) continue;
        if (preg_match('/[\\\/]tests[\\\/]/', $p)) continue;         // test harnesses
        $src = strip_php_comments((string)file_get_contents($p));
        foreach ($helpers as $h) {
            if (preg_match('/[^a-zA-Z_$]' . preg_quote($h, '/') . '\s*\(/', $src)) { $hits[] = $p; break; }
        }
    }
    return $hits;
}

echo "=== A. every password-helper caller must reach bc-security.php ===\n";
foreach ($editions as $edition) {
    // One recursive walk per edition covers every subdirectory exactly once (listing subdirs too
    // would report each file twice).
    $editionDir = $repoRoot . DIRECTORY_SEPARATOR . $edition;
    foreach (callers($editionDir, $helpers) as $file) {
        $rel = str_replace($repoRoot . DIRECTORY_SEPARATOR, '', $file);
        ok(reaches_security($file), "$rel -> can load bc-security.php");
    }
}

echo "\n=== B. NEGATIVE CONTROL: the checker must flag the pre-fix VendorReg.php ===\n";
$preFixContent = shell_exec('git -C ' . escapeshellarg($repoRoot) . ' show ' . escapeshellarg($preFixRev . ':' . $preFixFile) . ' 2>&1');
if (!is_string($preFixContent) || $preFixContent === '' || strpos($preFixContent, 'fatal:') === 0) {
    echo "FATAL: cannot read $preFixRev:$preFixFile - the gate is unverified.\n";
    exit(3);
}

// The fixture must genuinely be the pre-fix revision: it CALLS the helper and never mentions
// bc-security.php. Otherwise we would be "proving" the checker on the fixed file.
$preFixSrc = strip_php_comments($preFixContent);
$isRealPreFix = (strpos($preFixSrc, 'bc_hash_password(') !== false
                 && strpos($preFixContent, 'bc-security.php') === false);
if (!$isRealPreFix) {
    echo "FATAL: $preFixRev is NOT the pre-fix revision of $preFixFile.\n";
    echo "  expected: calls bc_hash_password(), never mentions bc-security.php\n";
    echo "Re-pin \$preFixRev to the commit that contained the bug. The gate is unverified.\n";
    exit(3);
}

// Run the IDENTICAL reachability code on the pre-fix file. It is written beside the real one so
// its '../func/...' includes resolve exactly as they do in production.
$preFixDir  = $repoRoot . DIRECTORY_SEPARATOR . 'DGV7.0-SAAS' . DIRECTORY_SEPARATOR . 'bc-spadmin';
$preFixTmp  = $preFixDir . DIRECTORY_SEPARATOR . '__prefix_control_probe.php';
$controlRan = false;
$controlFlagged = false;
try {
    if (@file_put_contents($preFixTmp, $preFixContent) === false) {
        echo "FATAL: cannot write the control fixture into $preFixDir.\n";
        exit(3);
    }
    $controlRan = true;
    $controlFlagged = !reaches_security($preFixTmp);
} finally {
    if ($controlRan && is_file($preFixTmp)) @unlink($preFixTmp);
}

ok($controlRan && !is_file($preFixTmp), 'control fixture was written and cleaned up again');
ok($controlFlagged, 'pre-fix VendorReg.php is correctly flagged by the same checker (bug reproduced)');

if ($controlRan && is_file($preFixTmp)) {
    echo "FATAL: could not remove the control fixture $preFixTmp - delete it by hand.\n";
    exit(3);
}

echo "\n=== SUMMARY ===\n";
echo ($fails === 0 ? "PASS" : "FAIL") . ": $checks checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
