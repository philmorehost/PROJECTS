<?php
$root = dirname(__DIR__);
$count = 0;
function loading_check($ok, $label) {
    global $count;
    if (!$ok) throw new RuntimeException($label);
    $count++;
}
foreach (['func/bc-plate-app.php'] as $file) {
    loading_check(file_get_contents($root.'/DGV7.0-SAAS/'.$file) === file_get_contents($root.'/DGV7.0-NON-SAAS/'.$file), 'edition parity: '.$file);
}
foreach (['DGV7.0-SAAS','DGV7.0-NON-SAAS'] as $edition) {
    $src = file_get_contents($root.'/'.$edition.'/func/bc-plate-app.php');
    loading_check(strpos($src, 'cdnjs.cloudflare.com/ajax/libs/bootstrap-icons') === false, 'icons do not depend on remote CSS');
    loading_check(strpos($src, 'cdn.jsdelivr.net/npm/sweetalert2') === false, 'alerts do not depend on remote JS');
    loading_check(strpos($src, '/jsfile/bc-custom-all.js" defer') !== false, 'helper does not block parser');
    loading_check(strpos($src, 'media="print" onload="this.media=\'all\'"') !== false, 'optional font is not render blocking');
    loading_check(strpos($src, "document.addEventListener('DOMContentLoaded', presentFlash") !== false, 'feedback waits for deferred library');
    loading_check(strpos($src, "close.textContent = 'Dismiss'") !== false, 'nonblocking fallback is dismissible');
    loading_check(strpos($src, "receipt.textContent = 'View Receipt'") !== false, 'fallback retains receipt access');
}
require $root.'/DGV7.0-SAAS/func/bc-plate-app.php';
$_SERVER['HTTP_HOST'] = 'console.example.test'; $_SERVER['HTTPS'] = 'on';
loading_check(bc_app_base() === 'https://console.example.test', 'fallback base uses the host, not a variable-variable');
echo "$count web-loading checks passed\n";
