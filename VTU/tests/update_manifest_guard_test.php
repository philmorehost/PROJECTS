<?php
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-update-manifest-guard.php';

$n = 0;
function umg_ok($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

$saas = file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/bc-update-manifest-guard.php');
$non  = file_get_contents(__DIR__ . '/../DGV7.0-NON-SAAS/func/bc-update-manifest-guard.php');
umg_ok($saas === $non, 'SAAS and NON-SAAS bc-update-manifest-guard.php must be identical');

$tmp = sys_get_temp_dir() . '/umg_' . bin2hex(random_bytes(4));
mkdir($tmp . '/files/func', 0755, true);
file_put_contents($tmp . '/files/func/test.php', "<?php echo 1;\n");
$sha = hash_file('sha256', $tmp . '/files/func/test.php');

bc_update_validate_manifest_staging([
    'files_to_delete' => ['old/file.php'],
    'file_sha256' => ['func/test.php' => $sha],
], $tmp);
umg_ok(true, 'valid manifest staging passes');

$threw = false;
try {
    bc_update_validate_manifest_staging(['files_to_delete' => ['../../etc/passwd']], $tmp);
} catch (Exception $e) { $threw = true; }
umg_ok($threw, 'path traversal in files_to_delete refused');

$threw = false;
try {
    bc_update_validate_manifest_staging(['file_sha256' => ['func/test.php' => str_repeat('0', 64)]], $tmp);
} catch (Exception $e) { $threw = true; }
umg_ok($threw, 'sha256 mismatch refused');

@unlink($tmp . '/files/func/test.php');
@rmdir($tmp . '/files/func');
@rmdir($tmp . '/files');
@rmdir($tmp);

echo "PASS $n update manifest guard checks\n";
