<?php
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-crypto-a2p.php';

$n = 0;
function a2p_chk($ok, $msg) {
    global $n;
    $n++;
    if (!$ok) throw new RuntimeException("crypto_a2p_desk_test: $msg");
    echo "ok $msg\n";
}

$root = dirname(__DIR__);
foreach (['func/bc-crypto-a2p.php', 'web/CryptoHub.php', 'bc-admin/CryptoHub.php'] as $rel) {
    $a = file_get_contents($root . '/DGV7.0-SAAS/' . $rel);
    $b = file_get_contents($root . '/DGV7.0-NON-SAAS/' . $rel);
    a2p_chk($a !== false && $a === $b, "edition parity for $rel");
    a2p_chk(stripos($a, 'bybit') === false, "no Bybit dependency in $rel");
    a2p_chk(!preg_match('/\b(CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\b/i', $a), "no runtime DDL in $rel");
}

// Address validation
a2p_chk(bc_crypto_a2p_validate_address('USDT_TRC20', 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL'), 'accepts valid TRON TRC-20 address');
a2p_chk(!bc_crypto_a2p_validate_address('USDT_TRC20', '0x1111111111111111111111111111111111111111'), 'rejects EVM address on TRC-20');
a2p_chk(bc_crypto_a2p_validate_address('USDT_BEP20', '0x1111111111111111111111111111111111111111'), 'accepts valid BEP-20 address');
a2p_chk(bc_crypto_a2p_validate_address('BTC', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'), 'accepts valid BTC bech32 address');
a2p_chk(!bc_crypto_a2p_validate_address('BTC', 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL'), 'rejects TRON address on BTC');

// Quote math with markup and spread
$cfg = bc_crypto_a2p_default_config();
$cfg['rate_mode'] = 'manual';
$cfg['usd_ngn_rate'] = 1600.0;
$cfg['buy_markup_pct'] = 5.0;
$cfg['sell_markdown_pct'] = 2.5;
$rates = bc_crypto_a2p_get_rates(0, $cfg, false);
a2p_chk($rates['source'] === 'manual', 'manual rate mode returns manual source');
$usdtQuote = bc_crypto_a2p_quote_asset($cfg, $rates, 'USDT_TRC20');
a2p_chk(abs($usdtQuote['base_ngn'] - 1600.0) < 0.001, 'base NGN rate computed accurately');
a2p_chk(abs($usdtQuote['buy_rate_ngn'] - 1680.0) < 0.001, 'buy markup (+5%) applied accurately');
a2p_chk(abs($usdtQuote['sell_rate_ngn'] - 1560.0) < 0.001, 'sell spread (-2.5%) applied accurately');
$cfg['assets']['USDT_TRC20']['manual_buy_ngn'] = 1710.0;
$cfg['assets']['USDT_TRC20']['manual_sell_ngn'] = 1590.0;
$staticQuote = bc_crypto_a2p_quote_asset($cfg, $rates, 'USDT_TRC20');
a2p_chk(abs($staticQuote['buy_rate_ngn'] - 1710.0) < 0.001, 'static manual buy NGN rate overrides percentage markup');
a2p_chk(abs($staticQuote['sell_rate_ngn'] - 1590.0) < 0.001, 'static manual sell NGN rate overrides percentage spread');

// User & Admin UI/Security contracts
$webSrc = file_get_contents($root . '/DGV7.0-SAAS/web/CryptoHub.php');
a2p_chk(strpos($webSrc, 'data-ch-tab="buy"') !== false && strpos($webSrc, 'data-ch-tab="sell"') !== false && strpos($webSrc, 'data-ch-tab="swap"') !== false, 'web CryptoHub has Buy/Sell/Swap tabs');
a2p_chk(strpos($webSrc, 'SendFund.php') !== false && strpos($webSrc, 'IntlTransfer.php') !== false, 'web CryptoHub links to SendFund.php and IntlTransfer.php');
a2p_chk(strpos($webSrc, 'bc_validate_csrf') !== false && strpos($webSrc, 'bc_verify_pin_secure') !== false, 'web CryptoHub validates CSRF and transaction PIN');
a2p_chk(strpos($webSrc, 'bc_crypto_a2p_submit_buy') !== false && strpos($webSrc, 'bc_crypto_a2p_submit_sell') !== false && strpos($webSrc, 'bc_crypto_a2p_execute_swap') !== false, 'web CryptoHub wires Buy, Sell, and Swap handlers');
a2p_chk(strpos($webSrc, 'Markup: +') === false && strpos($webSrc, 'Spread: -') === false && strpos($webSrc, 'Admin spread:') === false, 'web CryptoHub keeps admin markup/spread hidden from users');

$admSrc = file_get_contents($root . '/DGV7.0-SAAS/bc-admin/CryptoHub.php');
a2p_chk(strpos($admSrc, 'approve_a2p_buy') !== false && strpos($admSrc, 'reject_a2p_buy') !== false, 'admin CryptoHub handles A2P Buy fulfillment & refund');
a2p_chk(strpos($admSrc, 'approve_p2a_sell') !== false && strpos($admSrc, 'reject_p2a_sell') !== false, 'admin CryptoHub handles P2A Sell settlement & reject');
a2p_chk(strpos($admSrc, 'save_a2p_config') !== false && strpos($admSrc, 'buy_markup_pct') !== false && strpos($admSrc, 'sell_markdown_pct') !== false, 'admin CryptoHub configures rates, markups, and deposit addresses');
a2p_chk(strpos($admSrc, 'bc_stepup_admin_verify') !== false && strpos($admSrc, 'bc_sec_audit') !== false, 'admin CryptoHub enforces step-up 2FA and audit logs');
a2p_chk(strpos($admSrc, 'CryptoPolicies.php') === false && strpos($admSrc, 'CryptoCustody.php') === false, 'admin CryptoHub drops irrelevant V2/Bybit policy links');

// Storage round-trip check under sas_settings VARCHAR(225) constraint
$GLOBALS['__a2p_mock_settings'] = [];
$GLOBALS['__a2p_max_val_len'] = 0;
function getVendorOption($vid, $name, $default = '') {
    return $GLOBALS['__a2p_mock_settings'][$vid . ':' . $name] ?? $default;
}
function setVendorOption($vid, $name, $value) {
    $len = strlen((string)$value);
    if ($len > $GLOBALS['__a2p_max_val_len']) $GLOBALS['__a2p_max_val_len'] = $len;
    if ($len > 225) throw new RuntimeException("sas_settings VARCHAR(225) exceeded for $name ($len bytes)");
    $GLOBALS['__a2p_mock_settings'][$vid . ':' . $name] = (string)$value;
}
$testCfg = bc_crypto_a2p_default_config();
$testCfg['buy_markup_pct'] = 6.75;
$testCfg['sell_markdown_pct'] = 4.25;
$testCfg['swap_fee_pct'] = 1.5;
$testCfg['desk_notice'] = str_repeat('Custom Desk Notice ', 25);
$testCfg['assets']['USDT_TRC20']['deposit_address'] = 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL';
$testCfg['assets']['USDT_TRC20']['manual_buy_ngn'] = 1725.5;
a2p_chk(bc_crypto_a2p_save_config(7, $testCfg) === true, 'bc_crypto_a2p_save_config persists within VARCHAR(225) per row');
a2p_chk($GLOBALS['__a2p_max_val_len'] <= 225, 'all saved sas_settings values stay within 225 bytes');
$loadedCfg = bc_crypto_a2p_get_config(7);
a2p_chk(abs($loadedCfg['buy_markup_pct'] - 6.75) < 0.001 && abs($loadedCfg['sell_markdown_pct'] - 4.25) < 0.001, 'saved buy_markup_pct & sell_markdown_pct reload accurately');
a2p_chk($loadedCfg['assets']['USDT_TRC20']['deposit_address'] === 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL', 'saved deposit_address reloads accurately');
a2p_chk(abs($loadedCfg['assets']['USDT_TRC20']['manual_buy_ngn'] - 1725.5) < 0.001, 'saved manual_buy_ngn reloads accurately');

echo "PASS $n crypto A2P/P2A desk checks\n";
