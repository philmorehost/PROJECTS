<?php
require_once __DIR__ . '/func/bc-connect.php';
require_once __DIR__ . '/func/bc-payment-gateway-settlement.php';
bc_pg_handle_vendor_paystack_webhook($connection_server);
