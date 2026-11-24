<?php
$_SESSION = ['_csrf_token' => 'widget-test-csrf'];
$get_logged_user_details = ['balance' => 250000.00];
ob_start();
require __DIR__ . '/../DGV7.0-SAAS/func/bc-crypto-buysell-widget.php';
$widgetHtml = ob_get_clean();

$page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Crypto Buy/Sell Widget Fixture</title></head><body><main>' . $widgetHtml . '</main></body></html>';
echo json_encode(['html' => $page], JSON_THROW_ON_ERROR);
