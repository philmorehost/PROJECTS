<?php
/**
 * Contract test for the brand-aware default APK filename (web/api/app-update.php).
 *
 * Run with:  C:\xampp\php\php.exe -n tests/app_update_brand_default_test.php
 *
 * Both the REAL bc_app_brand_slug() (extracted from func/bc-func.php) and the SHIPPED
 * app-update.php are loaded, with mysqli stubbed so no database is needed. Extracting the helper
 * rather than reimplementing it is the point: if someone changes the slug rules, this fails.
 *
 * The bug being protected against: the default filename was the literal "datagifting-<ver>.apk"
 * in every installation. For any other brand that file cannot exist, and because the endpoint only
 * reports an update when the APK is on disk, those apps were told "up_to_date" for ever.
 */

$api_file  = __DIR__ . '/../web/api/app-update.php';
$func_file = __DIR__ . '/../func/bc-func.php';

// ── Pull the real bc_app_brand_slug() out of bc-func.php (brace-matched, not regex-guessed) ──
$bcFunc = file_get_contents($func_file);
if ($bcFunc === false) { fwrite(STDERR, "cannot read $func_file\n"); exit(1); }
$start = strpos($bcFunc, 'function bc_app_brand_slug');
if ($start === false) { fwrite(STDERR, "bc_app_brand_slug not found in bc-func.php\n"); exit(1); }
$brace = strpos($bcFunc, '{', $start);
$depth = 0; $end = $brace;
for (; $end < strlen($bcFunc); $end++) {
    if ($bcFunc[$end] === '{') { $depth++; }
    elseif ($bcFunc[$end] === '}') { $depth--; if ($depth === 0) { break; } }
}
$real_helper = substr($bcFunc, $start, $end - $start + 1);

// ── Pull the shipped endpoint, minus the DB bootstrap and the CLI-hostile bits ──
$src = file_get_contents($api_file);
if ($src === false) { fwrite(STDERR, "cannot read $api_file\n"); exit(1); }
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
        echo "FAIL  $label\n        expected " . var_export($expected, true) . "\n        got      " . var_export($actual, true) . "\n";
    } else {
        echo "ok    $label\n";
    }
}

$tmp_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dgv7_brand_test';
$apk_dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'apk';
@mkdir($tmp_root . DIRECTORY_SEPARATOR . 'api', 0777, true);
@mkdir($apk_dir, 0777, true);

/**
 * Run the endpoint. mysqli_query routes by SQL text so the settings row and the site_details row
 * can differ - the real helper and the endpoint issue two different queries.
 */
function run_endpoint($src, $real_helper, $settings_row, $site_title, $vendor_id, $host) {
    $tmp_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dgv7_brand_test';
    $file = $tmp_root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'app-update.php';

    $stub = "<?php\n"
        . "if (!function_exists('resolveVendorID')) { function resolveVendorID(\$f = false) { global \$__fake_vendor; return \$__fake_vendor; } }\n"
        . "if (!function_exists('bc_safe_host')) { function bc_safe_host() { return \$_SERVER['HTTP_HOST'] ?? 'localhost'; } }\n"
        . "if (!defined('MYSQLI_ASSOC')) { define('MYSQLI_ASSOC', 1); }\n"
        . "if (!function_exists('mysqli_query')) { function mysqli_query(\$c, \$q) {\n"
        . "    global \$__fake_settings, \$__fake_site;\n"
        . "    if (stripos(\$q, 'sas_site_details') !== false) { return \$__fake_site === null ? 'NO_SITE' : 'SITE_ROW'; }\n"
        . "    if (stripos(\$q, 'sas_app_update_settings') !== false) { return \$__fake_settings === null ? 'NO_SET' : 'SET_ROW'; }\n"
        . "    return 'OTHER';\n} }\n"
        . "if (!function_exists('mysqli_num_rows')) { function mysqli_num_rows(\$r) { return in_array(\$r, ['NO_SITE','NO_SET','OTHER'], true) ? 0 : 1; } }\n"
        . "if (!function_exists('mysqli_fetch_assoc')) { function mysqli_fetch_assoc(\$r) {\n"
        . "    global \$__fake_settings, \$__fake_site;\n"
        . "    if (\$r === 'SITE_ROW') { return \$__fake_site; }\n"
        . "    if (\$r === 'SET_ROW')  { return \$__fake_settings; }\n"
        . "    return null;\n} }\n"
        . "if (!function_exists('mysqli_real_escape_string')) { function mysqli_real_escape_string(\$c, \$s) { return addslashes(\$s); } }\n"
        // Guarded the same way bc-func.php guards it: this file is included once per case.
        . "if (!function_exists('bc_app_brand_slug')) {\n" . $real_helper . "\n}\n"
        . "global \$__fake_settings, \$__fake_site, \$__fake_vendor;\n"
        . "\$__fake_settings = " . var_export($settings_row, true) . ";\n"
        . "\$__fake_site = " . ($site_title === null ? 'null' : var_export(['site_title' => $site_title], true)) . ";\n"
        . "\$__fake_vendor = " . (int) $vendor_id . ";\n"
        . "\$connection_server = true;\n"
        . "\$_SERVER['HTTP_HOST'] = " . var_export($host, true) . ";\n";

    file_put_contents($file, $stub . $src);
    ob_start();
    include $file;
    $out = ob_get_clean();
    @unlink($file);
    return json_decode($out, true);
}

$settings = ['update_source' => 'local', 'play_store_url' => '', 'apk_version_code' => 4,
             'apk_version_name' => '1.0.3', 'apk_filename' => '', 'changelog' => ''];

// ── The default filename must follow the vendor's own brand ──
$cases = [
    // site_title,     vendor, expected filename,                                   why
    ['DataGifting',        7, 'datagifting-1.0.3.apk',    'DataGifting keeps the exact name it had before (backward compatible)'],
    ['JIKABIZ',            7, 'jikabiz-1.0.3.apk',        'JIKABIZ is no longer pointed at datagifting-*.apk'],
    ['MZEEVTU',            7, 'mzeevtu-1.0.3.apk',        'MZEEVTU is no longer pointed at datagifting-*.apk'],
    ['PayHub Guest',       7, 'payhub-guest-1.0.3.apk',   'spaces and case are slugified'],
    ['JIKABIZ Global Ltd', 7, 'jikabiz-global-ltd-1.0.3.apk', 'punctuation collapses to single hyphens'],
    ['',                   7, '1.0.3.apk',                'blank site title -> unbranded fallback'],
    // With no resolvable vendor the settings row is never read, so the version also falls back to
    // the endpoint's built-in default (1.0.0) - the point here is that no brand name is invented.
    ['JIKABIZ',            0, '1.0.0.apk',                'unresolvable vendor -> unbranded fallback, never a wrong brand'],
];

foreach ($cases as $c) {
    list($title, $vendor, $expected, $why) = $c;
    // The endpoint only reports an update when the file is on disk, so place it.
    file_put_contents($apk_dir . DIRECTORY_SEPARATOR . $expected, 'dummy');
    $r = run_endpoint($src, $real_helper, $settings, $title, $vendor, 'shop.example.com');
    $url = $r['apk_url'] ?? null;
    bc_assert($why, $url, "https://shop.example.com/apk/$expected");
    @unlink($apk_dir . DIRECTORY_SEPARATOR . $expected);
}

// ── Guard the actual reported bug: no other brand may inherit the DataGifting filename ──
foreach (['JIKABIZ', 'MZEEVTU', 'PayHub Guest'] as $brand) {
    $slug = 'x-1.0.3.apk';
    file_put_contents($apk_dir . DIRECTORY_SEPARATOR . $slug, 'dummy');
    $r = run_endpoint($src, $real_helper, $settings, $brand, 7, 'shop.example.com');
    @unlink($apk_dir . DIRECTORY_SEPARATOR . $slug);
    $reported = $r['apk_url'] ?? '';
    bc_assert("$brand does not fall back to a datagifting-* filename",
              (bool) preg_match('#/apk/datagifting-#', (string) $reported), false);
}

// ── An admin-set filename must still win over the default ──
$settings_admin = $settings;
$settings_admin['apk_filename'] = 'custom-release.apk';
file_put_contents($apk_dir . DIRECTORY_SEPARATOR . 'custom-release.apk', 'dummy');
$r = run_endpoint($src, $real_helper, $settings_admin, 'JIKABIZ', 7, 'shop.example.com');
bc_assert('an explicit admin filename still overrides the brand default',
          $r['apk_url'] ?? null, 'https://shop.example.com/apk/custom-release.apk');
@unlink($apk_dir . DIRECTORY_SEPARATOR . 'custom-release.apk');

// ── The helper itself ──
bc_assert('bc_app_brand_slug() with no DB returns empty', bc_app_brand_slug(false, 7), '');
bc_assert('bc_app_brand_slug() with vendor 0 returns empty', bc_app_brand_slug(true, 0), '');

@rmdir($apk_dir);
@rmdir($tmp_root . DIRECTORY_SEPARATOR . 'api');
@rmdir($tmp_root);

echo "\n$checks checks, $fails failures\n";
exit($fails === 0 ? 0 : 1);
