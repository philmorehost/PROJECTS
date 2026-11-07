<?php
/**
 * Triage scan: find superglobals that reach a SQL statement without escaping.
 *
 * Line-based heuristic (deliberately noisy-but-cheap triage, not a proof):
 *   - the line mentions a SQL keyword AND a superglobal
 *   - the superglobal is not wrapped in mysqli_real_escape_string(...) / (int) / intval() etc.
 * Anything it prints should be read by a human before being called a bug.
 *
 * Usage: php -n scratch/scan_sql_injection.php
 */

$root = dirname(dirname(__DIR__));
$roots = array(
    'DGV7.0-SAAS/web', 'DGV7.0-SAAS/web/api', 'DGV7.0-SAAS/bc-admin', 'DGV7.0-SAAS/bc-spadmin', 'DGV7.0-SAAS/api',
    'DGV7.0-NON-SAAS/web', 'DGV7.0-NON-SAAS/web/api', 'DGV7.0-NON-SAAS/bc-admin', 'DGV7.0-NON-SAAS/api',
);

$sqlKw   = '/\b(SELECT\s|INSERT\s+INTO\b|UPDATE\s+\w|DELETE\s+FROM\b|WHERE\s)/i';
$super   = '/\$_(GET|POST|REQUEST|COOKIE)\s*\[/';
$safe    = '/mysqli_real_escape_string|intval\s*\(|floatval\s*\(|number_format|\(int\)|\(float\)|is_numeric|htmlspecialchars|addslashes|preg_replace|serialize/i';

$hits = 0;
$files = 0;

foreach ($roots as $rel) {
    $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_dir($dir)) continue;

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (strtolower($f->getExtension()) !== 'php') continue;
        $files++;
        $lines = file($f->getPathname());
        foreach ($lines as $i => $line) {
            if (!preg_match($super, $line)) continue;
            // Only care when the superglobal sits in a SQL-looking statement.
            $window = $line;
            if (!preg_match($sqlKw, $window)) continue;
            // Look at the immediate call it belongs to: if the escape helper wraps it, skip.
            $pos = strpos($line, '$_');
            $prefix = substr($line, max(0, $pos - 120), 120);
            if (preg_match($safe, $prefix)) continue;

            $hits++;
            echo str_replace($root . DIRECTORY_SEPARATOR, '', $f->getPathname()) . ':' . ($i + 1) . "\n    " . trim($line) . "\n";
        }
    }
}

echo "\nscanned $files PHP files under the user-facing trees\n";
echo "candidate unescaped superglobal-in-SQL lines: $hits\n";
exit($hits === 0 ? 0 : 1);
