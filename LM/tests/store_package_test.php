<?php
/**
 * tests/store_package_test.php — moving a packaged update into the store.
 *
 * The GitHub pull reported every failure of the final move as
 * "Could not move the package into … (check permissions)", whether the
 * cause was folder permissions, a full disk or a cross-device move.
 * lm_store_package_file() must move the file in the normal cases and
 * name the real cause in the rest.
 *
 * Usage: php tests/store_package_test.php   (exit 0 = all pass)
 */

$failures = 0;
$checks = 0;

function check($name, $condition, $detail = '')
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "ok    {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$name}" . ($detail !== '' ? "\n      " . $detail : '') . "\n";
}

if (!function_exists('lm_log')) {
    function lm_log($channel, $message) { /* silent under test */ }
}

require_once __DIR__ . '/../includes/lm_config.php';
require_once __DIR__ . '/../includes/updates.php';

check('helper is defined', function_exists('lm_store_package_file'));

/* ------------------------------------------------------------- fixtures */

$base = sys_get_temp_dir() . '/lm_store_pkg_' . getmypid();
$work = $base . '/work';
@mkdir($work, 0777, true);

$payload = str_repeat("PK\x03\x04 fake package bytes ", 5000); // ~135 KB

function make_source($work, $payload)
{
    $src = $work . '/gh_pkg_' . bin2hex(random_bytes(4)) . '_normalized.zip';
    file_put_contents($src, $payload);
    return $src;
}

/* ---------------------------------------------------------------- cases */

// 1. Plain move into an existing tier folder.
$dest1 = $base . '/store/saas';
@mkdir($dest1, 0777, true);
$src = make_source($work, $payload);
$r = lm_store_package_file($src, $dest1, $dest1 . '/update_v9.9.9.zip');
check('moves the package into the tier folder', $r['ok'] && is_file($dest1 . '/update_v9.9.9.zip'), $r['error']);
check('moved bytes are identical', is_file($dest1 . '/update_v9.9.9.zip') && file_get_contents($dest1 . '/update_v9.9.9.zip') === $payload);
check('source is gone after a successful move', !file_exists($src));

// 2. Missing tier folder is created.
$dest2 = $base . '/store-fresh/nonsaas';
$src = make_source($work, $payload);
$r = lm_store_package_file($src, $dest2, $dest2 . '/update_v9.9.9.zip');
check('creates a missing tier folder and moves', $r['ok'] && is_file($dest2 . '/update_v9.9.9.zip'), $r['error']);

// 3. A stale partial package from a failed attempt is replaced.
$dest3 = $base . '/store/saas';
file_put_contents($dest3 . '/update_v9.9.9.zip', 'partial');
$src = make_source($work, $payload);
$r = lm_store_package_file($src, $dest3, $dest3 . '/update_v9.9.9.zip');
check('replaces a stale partial package', $r['ok'] && file_get_contents($dest3 . '/update_v9.9.9.zip') === $payload, $r['error']);

// 4. Missing source is reported as missing, not as "permissions".
$r = lm_store_package_file($work . '/nope.zip', $dest1, $dest1 . '/update_v9.9.9.zip');
check('missing source names the real cause', !$r['ok'] && strpos($r['error'], 'missing') !== false, $r['error']);

// 5. A destination blocked by a regular file cannot be created: the
//    error must say so instead of blaming generic permissions.
$blocked = $base . '/blocked';
file_put_contents($blocked, 'i am a file');
$r = lm_store_package_file(make_source($work, $payload), $blocked, $blocked . '/update_v9.9.9.zip');
check('un-creatable folder is diagnosed', !$r['ok'] && strpos($r['error'], 'could not be created') !== false, $r['error']);

// Failed final rename preserves both source and the previous destination.
$src = make_source($work, $payload);
$occupied = $dest1 . '/occupied';
mkdir($occupied);
file_put_contents($occupied . '/keep.txt', 'existing release');
$r = lm_store_package_file($src, $dest1, $occupied);
check('failed install is diagnosed', !$r['ok'] && strpos($r['error'], 'Could not install') !== false, $r['error']);
check('failed install preserves source', is_file($src) && file_get_contents($src) === $payload);
check('failed install preserves destination', file_get_contents($occupied . '/keep.txt') === 'existing release');
check('failed install cleans staging files', count(glob($dest1 . '/.lm-package-*')) === 0);

$empty = $work . '/empty.zip';
file_put_contents($empty, '');
$existing = $dest1 . '/update_v9.9.9.zip';
$before = file_get_contents($existing);
$r = lm_store_package_file($empty, $dest1, $existing);
check('empty source cannot replace a release', !$r['ok'] && file_get_contents($existing) === $before);

// 6. Wiring: the GitHub pull uses the helper and the vague message is gone.
$github = file_get_contents(__DIR__ . '/../includes/github.php');
check('github pull moves via lm_store_package_file', strpos($github, 'lm_store_package_file($normalizedPath, $tierDir, $targetFile)') !== false);
// The trailing period distinguishes the real message from the comments that
// mention it; the source must no longer contain the vague wording.
check('the bare "(check permissions)" message is gone', strpos($github, '(check permissions).') === false);

/* -------------------------------------------------------------- cleanup */

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $file) {
    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
}
@rmdir($base);

echo $failures === 0 ? "store_package: all {$checks} checks passed\n" : "store_package: {$failures} of {$checks} FAILED\n";
exit($failures === 0 ? 0 : 1);
