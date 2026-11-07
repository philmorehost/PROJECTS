<?php
/**
 * Contract test for the app-update source feature (web/api/app-update.php).
 *
 * Run with:  C:\xampp\php\php.exe -n tests/app_update_source_test.php
 *
 * The SHIPPED endpoint source is loaded (with its DB access stubbed) so this fails if the response
 * contract regresses — in particular that Google Play mode never hands out a local apk_url.
 */

$api_file = __DIR__ . '/../web/api/app-update.php';
$src = file_get_contents($api_file);
if ($src === false) { fwrite(STDERR, "cannot read $api_file\n"); exit(1); }

// Strip the bootstrap include (it would try to reach a real database) and the opening tag, plus the
// CLI-hostile bits: the HTTP header call and the endpoint's own exit (which would kill this test run;
// at top level of the included file a return unwinds just the include).
$src = preg_replace('#include_once\("\.\./\.\./func/bc-connect\.php"\);#', '', $src, 1);
$src = str_replace('header("Content-Type: application/json");', '', $src);
$src = str_replace('exit;', 'return;', $src);
$src = preg_replace('#^<\?php#', '', $src, 1);

$fails = 0; $checks = 0;
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

/** Run the endpoint with a fake settings row + host, return the decoded JSON. */
function run_endpoint($src, $settings_row, $host, $stub_apk_realpath) {
    $tmp_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dgv7_appupdate_test';
    @mkdir($tmp_root . DIRECTORY_SEPARATOR . 'api', 0777, true);
    $file = $tmp_root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'app-update.php';

    $stub = "<?php\n"
        . "if (!function_exists('resolveVendorID')) { function resolveVendorID() { global \$__fake_vendor; return \$__fake_vendor; } }\n"
        . "if (!function_exists('bc_safe_host')) { function bc_safe_host() { return \$_SERVER['HTTP_HOST'] ?? 'localhost'; } }\n"
        . "if (!defined('MYSQLI_ASSOC')) { define('MYSQLI_ASSOC', 1); }\n"
        . "if (!function_exists('mysqli_query')) { function mysqli_query(\$c, \$q) { global \$__fake_row; return \$__fake_row === null ? 'NO_ROW_RESULT' : 'ROW_RESULT'; } }\n"
        . "if (!function_exists('mysqli_num_rows')) { function mysqli_num_rows(\$r) { global \$__fake_row; return (\$r === 'ROW_RESULT') ? 1 : 0; } }\n"
        . "if (!function_exists('mysqli_fetch_assoc')) { function mysqli_fetch_assoc(\$r) { global \$__fake_row; return \$r === 'ROW_RESULT' ? \$__fake_row : null; } }\n"
        . "if (!function_exists('mysqli_real_escape_string')) { function mysqli_real_escape_string(\$c, \$s) { return addslashes(\$s); } }\n"
        . "global \$__fake_row, \$__fake_vendor;\n"
        . "\$__fake_row = " . var_export($settings_row, true) . ";\n"
        . "\$__fake_vendor = " . ($settings_row === null ? 0 : 7) . ";\n"
        . "\$connection_server = true;\n"
        . "\$_SERVER['HTTP_HOST'] = " . var_export($host, true) . ";\n";
    // NOTE: the stub deliberately has no closing PHP tag, and the shipped source is appended with its
    // opening tag stripped so it stays inside the same PHP block (otherwise it is echoed as text
    // instead of executed). Avoid writing a PHP close tag anywhere in a comment - it still closes PHP.

    file_put_contents($file, $stub . $src);

    ob_start();
    include $file;
    $out = ob_get_clean();
    @unlink($file);

    $decoded = json_decode($out, true);
    if ($decoded === null && trim((string)$out) !== '') {
        echo "      [raw output] " . str_replace("\n", " | ", trim((string)$out)) . "\n";
    }
    return $decoded;
}

$local_apk = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'apk';
@mkdir($local_apk, 0777, true);
// The endpoint looks for __DIR__/../../apk/<filename>; __DIR__ is <tmp>/dgv7_appupdate_test/api.
file_put_contents($local_apk . DIRECTORY_SEPARATOR . 'datagifting-7.7.7.apk', 'dummy');

// 1. No settings row at all -> legacy behaviour, local source, APK missing -> up_to_date.
$r = run_endpoint($src, null, 'shop.example.com', null);
bc_assert('no settings row -> up_to_date', $r['status'] ?? null, 'up_to_date');
bc_assert('no settings row -> source defaults to local', $r['update_source'] ?? null, 'local');

// 2. Google Play selected with a URL -> update_available with the store link and NO local apk_url.
$row = ['update_source' => 'play', 'play_store_url' => 'https://play.google.com/store/apps/details?id=com.demo.app',
        'apk_version_code' => 12, 'apk_version_name' => '2.3.0', 'apk_filename' => null, 'changelog' => 'Faster checkout'];
$r = run_endpoint($src, $row, 'shop.example.com', null);
bc_assert('play -> update_available', $r['status'] ?? null, 'update_available');
bc_assert('play -> update_source=play', $r['update_source'] ?? null, 'play');
bc_assert('play -> play_url returned', $r['play_url'] ?? null, $row['play_store_url']);
bc_assert('play -> store_url alias returned', $r['store_url'] ?? null, $row['play_store_url']);
bc_assert('play -> apk_url NOT exposed', array_key_exists('apk_url', $r), false);
bc_assert('play -> version code from settings', $r['version_code'] ?? null, 12);
bc_assert('play -> version name from settings', $r['version_name'] ?? null, '2.3.0');
bc_assert('play -> changelog from settings', $r['changelog'] ?? null, 'Faster checkout');

// 3. Google Play selected but no URL -> never nag users.
$row = ['update_source' => 'play', 'play_store_url' => '', 'apk_version_code' => 12, 'apk_version_name' => '2.3.0'];
$r = run_endpoint($src, $row, 'shop.example.com', null);
bc_assert('play without URL -> up_to_date', $r['status'] ?? null, 'up_to_date');

// 4. Local source (explicit) with the APK present -> classic APK response.
$row = ['update_source' => 'local', 'play_store_url' => 'https://play.google.com/x',
        'apk_version_code' => 8, 'apk_version_name' => '7.7.7', 'apk_filename' => 'datagifting-7.7.7.apk', 'changelog' => 'Fix'];
$r = run_endpoint($src, $row, 'shop.example.com', null);
bc_assert('local -> update_available', $r['status'] ?? null, 'update_available');
bc_assert('local -> update_source=local', $r['update_source'] ?? null, 'local');
bc_assert('local -> apk_url points at /apk', $r['apk_url'] ?? null, 'https://shop.example.com/apk/datagifting-7.7.7.apk');
bc_assert('local -> no play_url', array_key_exists('play_url', $r), false);
bc_assert('local -> version code from settings', $r['version_code'] ?? null, 8);

// 5. Local source, APK filename that does not exist -> up_to_date (never point at a missing file).
$row['apk_filename'] = 'datagifting-missing.apk';
$r = run_endpoint($src, $row, 'shop.example.com', null);
bc_assert('local with missing APK -> up_to_date', $r['status'] ?? null, 'up_to_date');

// 6. Unknown/junk source value falls back to local, not to play.
$row = ['update_source' => 'PLAY!', 'play_store_url' => 'https://play.google.com/x', 'apk_version_code' => 3, 'apk_version_name' => '3.0.0', 'apk_filename' => 'datagifting-missing.apk'];
$r = run_endpoint($src, $row, 'shop.example.com', null);
bc_assert('unknown source -> treated as local', $r['update_source'] ?? null, 'local');

@unlink($local_apk . DIRECTORY_SEPARATOR . 'datagifting-7.7.7.apk');
@rmdir($local_apk);
@rmdir(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dgv7_appupdate_test' . DIRECTORY_SEPARATOR . 'api');
@rmdir(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dgv7_appupdate_test');

echo "\n$checks checks, $fails failures\n";
exit($fails === 0 ? 0 : 1);
