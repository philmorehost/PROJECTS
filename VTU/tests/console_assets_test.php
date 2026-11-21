<?php
$root = dirname(__DIR__);
require $root . '/DGV7.0-SAAS/func/bc-plate-admin.php';
$n = 0;
function asset_check($ok, $label) {
    global $n;
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $n++;
}
foreach (array('bc-admin', 'bc-spadmin') as $area) {
    $_SERVER['PHP_SELF'] = '/' . $area . '/Transactions.php';
    ob_start(); bc_adm_assets($area === 'bc-admin' ? 'admin' : 'spadmin'); $assets = ob_get_clean();
    foreach (array('apexcharts', 'chart.umd.js', 'echarts.min.js', 'quill.js', 'tinymce', 'php-email-form') as $unused) {
        asset_check(strpos($assets, $unused) === false, "$area history excludes $unused");
    }
    asset_check(strpos($assets, 'bc-custom-all.js') !== false, 'shared handlers preserved');
    asset_check(strpos($assets, 'simple-datatables') !== false, 'table dependency preserved');
    $_SERVER['PHP_SELF'] = '/' . $area . '/Dashboard.php';
    ob_start(); bc_adm_assets($area === 'bc-admin' ? 'admin' : 'spadmin'); $assets = ob_get_clean();
    asset_check(strpos($assets, 'apexcharts') !== false, 'dashboard chart dependency preserved');
}
foreach (array('bc-plate-app.php', 'bc-plate-admin.php', 'bc-support-chat-widget.php') as $file) {
    asset_check(file_get_contents($root.'/DGV7.0-SAAS/func/'.$file) === file_get_contents($root.'/DGV7.0-NON-SAAS/func/'.$file), "$file edition parity");
}
$saas_history = file_get_contents($root.'/DGV7.0-SAAS/func/history-table.php');
$nonsaas_history = file_get_contents($root.'/DGV7.0-NON-SAAS/func/history-table.php');
asset_check(explode('// §29:', $saas_history)[0] === explode('// §29:', $nonsaas_history)[0], 'history row click rendering parity (edition-specific financial actions unchanged)');
echo "$n console asset checks passed\n";
