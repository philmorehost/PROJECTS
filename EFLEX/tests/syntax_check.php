<?php
/**
 * Walks the project and parses every PHP file, reporting syntax errors.
 * No PHP binary is spawned (works on hosts where shell exec is disabled):
 * token_get_all(..., TOKEN_PARSE) runs the real PHP parser.
 *
 * Run: php tests/syntax_check.php
 */
$root = realpath(__DIR__ . '/..');
$skip = array('/includes/phpmailer/');
$files = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (substr($path, -4) !== '.php') continue;
    foreach ($skip as $skipdir) {
        if (strpos($path, $skipdir) !== false) {
            continue 2;
        }
    }
    $files[] = $path;
}
sort($files);

$bad = 0;
foreach ($files as $path) {
    try {
        token_get_all(file_get_contents($path), TOKEN_PARSE);
    } catch (ParseError $e) {
        $bad++;
        echo 'SYNTAX ERROR: ' . str_replace($root, '', $path) . ' — ' . $e->getMessage() . ' (line ' . $e->getLine() . ")\n";
    } catch (Throwable $e) {
        $bad++;
        echo 'ERROR: ' . str_replace($root, '', $path) . ' — ' . $e->getMessage() . "\n";
    }
}
echo count($files) . ' files parsed, ' . $bad . " with syntax errors\n";
exit($bad === 0 ? 0 : 1);
