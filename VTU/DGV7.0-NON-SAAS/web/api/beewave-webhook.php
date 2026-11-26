<?php
require_once __DIR__ . '/../../func/bc-connect.php';
require_once __DIR__ . '/../../func/bc-payment-gateway-settlement.php';
bc_pg_handle_beewave_webhook($connection_server);
