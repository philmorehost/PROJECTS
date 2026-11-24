<?php
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-crypto-buysell.php';

$n = 0;
function cbs_ok($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

// 1. Edition parity
$saas = file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/bc-crypto-buysell.php');
$non  = file_get_contents(__DIR__ . '/../DGV7.0-NON-SAAS/func/bc-crypto-buysell.php');
cbs_ok($saas === $non, 'SAAS and NON-SAAS bc-crypto-buysell.php must be identical');

// 2. Fixed-point quote arithmetic (100 USDT @ 1650.00 NGN + 2500.00 NGN fee)
$buyTotal = bc_cbs_compute_fiat_total('10000000000', 165000, 250000, 'buy');
cbs_ok($buyTotal === 16750000, 'buy fixed-point total exact');

$sellTotal = bc_cbs_compute_fiat_total('10000000000', 162000, 100000, 'sell');
cbs_ok($sellTotal === 16100000, 'sell fixed-point total exact');

// 3. Network address checks
cbs_ok(bc_cbs_validate_destination('TRON', 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL'), 'valid TRON address');
cbs_ok(!bc_cbs_validate_destination('TRON', '0x1111111111111111111111111111111111111111'), 'rejects EVM address on TRON');
cbs_ok(bc_cbs_validate_destination('BSC', '0x1111111111111111111111111111111111111111'), 'valid BSC address');
cbs_ok(bc_cbs_validate_destination('BTC', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'), 'valid BTC bech32 address');
cbs_ok(!bc_cbs_validate_destination('BTC', 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL'), 'rejects TRON address on BTC');

// 4. Held by default & zero runtime DDL / Bybit references
cbs_ok(!bc_cbs_execution_enabled(), 'held by default');
cbs_ok(!preg_match('/\b(CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\b/i', $saas), 'no runtime DDL');
cbs_ok(stripos($saas, 'bybit') === false, 'no Bybit dependency in replacement crypto foundation');

echo "PASS $n crypto Buy/Sell foundation checks\n";
