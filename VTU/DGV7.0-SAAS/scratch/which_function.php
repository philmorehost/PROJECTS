<?php
/**
 * Throwaway recon helper: report which top-level function encloses a given line, so the guard
 * can be inserted at an unambiguous choke point. Read-only.
 *
 * Usage: php -n scratch/which_function.php <file> <line> [<needle>]
 */
$file = $argv[1] ?? '';
$line = (int)($argv[2] ?? 0);
$needle = $argv[3] ?? '';

if (!is_file($file) || $line <= 0) {
    fwrite(STDERR, "usage: php which_function.php <file> <line> [needle]\n");
    exit(2);
}

$lines = file($file);
$funcs = [];
foreach ($lines as $i => $l) {
    if (preg_match('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $l, $m)) {
        $funcs[] = ['line' => $i + 1, 'name' => $m[1]];
    }
}

$enclosing = '(none - top level)';
foreach ($funcs as $f) {
    if ($f['line'] <= $line) { $enclosing = $f['name'] . ' (defined at line ' . $f['line'] . ')'; }
    else { break; }
}

echo "file           : $file\n";
echo "raw lines      : " . count($lines) . "\n";
echo "top-level funcs: " . count($funcs) . "\n";
echo "line $line is inside: $enclosing\n";
echo "content of line $line: " . rtrim($lines[$line - 1] ?? '') . "\n";

if ($needle !== '') {
    $hits = [];
    foreach ($lines as $i => $l) {
        if (strpos($l, $needle) !== false) {
            $owner = '(top level)';
            foreach ($funcs as $f) { if ($f['line'] <= $i + 1) { $owner = $f['name']; } else { break; } }
            $hits[] = '  line ' . ($i + 1) . '  in ' . $owner;
        }
    }
    echo "needle \"$needle\" found " . count($hits) . " time(s):\n" . implode("\n", $hits) . "\n";
}
