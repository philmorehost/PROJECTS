<?php
/**
 * §42 — every back-office page that calls the admin shell must load it.
 *
 * bc-spadmin/BruteForceSecurity.php and bc-spadmin/VendorKYC.php called
 * bc_adm_head()/bc_adm_sidebar()/bc_adm_topbar()/bc_adm_foot() without ever
 * including func/bc-plate-admin.php — a guaranteed "Call to undefined
 * function bc_adm_head()" fatal the moment the page is opened (reported live
 * from portal.mzeevtu.com). This scan walks every bc-admin/bc-spadmin page in
 * both editions and fails if any page uses the shell without loading it, so
 * the whole class of bug stays dead.
 */
chdir(dirname(__DIR__));
$fail = 0;
function chk($cond, $label) { global $fail; echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$cond) $fail++; }

$dirs = [
    'DGV7.0-SAAS/bc-admin', 'DGV7.0-SAAS/bc-spadmin', 'DGV7.0-NON-SAAS/bc-admin',
];
$shell_calls = ['bc_adm_head(', 'bc_adm_sidebar(', 'bc_adm_topbar(', 'bc_adm_foot('];
$scanned = 0;

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.php') as $file) {
        $src = file_get_contents($file);
        $uses = false;
        foreach ($shell_calls as $fn) {
            if (strpos($src, $fn) !== false) { $uses = true; break; }
        }
        if (!$uses) continue;
        $scanned++;
        chk(strpos($src, 'bc-plate-admin.php') !== false, "$file loads bc-plate-admin.php");
    }
}
chk($scanned > 50, "scan covered the back-office surface ($scanned shell pages)");

/* The two reported pages, pinned explicitly. */
foreach (['DGV7.0-SAAS/bc-spadmin/BruteForceSecurity.php', 'DGV7.0-SAAS/bc-spadmin/VendorKYC.php'] as $file) {
    $src = file_get_contents($file);
    $plate_at = strpos($src, 'bc-plate-admin.php');
    $head_at = strpos($src, 'bc_adm_head(');
    chk($plate_at !== false && $head_at !== false && $plate_at < $head_at, "$file includes the plate before bc_adm_head()");
}

echo PHP_EOL . ($fail === 0 ? 'plateinclude42: all checks passed' : "plateinclude42: $fail FAILURES") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
