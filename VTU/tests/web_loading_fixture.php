<?php
// Offline, no session/authentication bypass: renders only the real shared UI helpers.
$_SERVER['HTTP_HOST'] = 'performance.test';
$_SERVER['HTTPS'] = 'on';
$_SERVER['PHP_SELF'] = '/web/Dashboard.php';
$GLOBALS['get_all_site_details'] = ['site_title'=>'Performance fixture','primary_color'=>'#287bff'];
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-plate-app.php';
$_SESSION['product_purchase_response'] = 'Payment completed successfully';
$_SESSION['product_purchase_status'] = 'success';
$_SESSION['last_transaction_ref'] = 'test-ref';
bc_app_head('Web loading regression');
echo '<body><main><div class="pk-app__wrap"><h1 id="page-ready">Wallet ready</h1><button id="primary-action" onclick="this.textContent=\'Working\'">Continue</button>';
bc_app_foot();
