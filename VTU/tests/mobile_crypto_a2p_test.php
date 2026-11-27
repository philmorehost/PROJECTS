<?php
$root = dirname(__DIR__);
$n = 0;
function mchk($ok, $msg) {
    global $n;
    $n++;
    if (!$ok) {
        throw new RuntimeException("mobile_crypto_a2p_test failed [$n]: $msg");
    }
    echo "ok $msg\n";
}

$saasApi = file_get_contents($root . '/DGV7.0-SAAS/web/api/crypto.php');
$nonSaasApi = file_get_contents($root . '/DGV7.0-NON-SAAS/web/api/crypto.php');
mchk($saasApi !== false && $saasApi === $nonSaasApi, 'web/api/crypto.php byte-identical across SAAS and NON-SAAS');
mchk(strpos($saasApi, 'bc-crypto-a2p.php') !== false, 'web/api/crypto.php includes bc-crypto-a2p.php');
mchk(strpos($saasApi, 'GATEWAY_RETIRED') !== false && strpos($saasApi, "bc_gateway_is_retired('plisio')") !== false, 'web/api/crypto.php preserves retired-provider guard');
mchk(stripos($saasApi, 'createPlisioInvoice') === false && stripos($saasApi, 'bybit') === false, 'web/api/crypto.php has no Plisio invoice or Bybit dependency');
mchk(strpos($saasApi, '"buy_markup_pct"') === false && strpos($saasApi, '"sell_markdown_pct"') === false, 'web/api/crypto.php hides admin markup/spread percentages from mobile users');
foreach (['a2p_desk', 'a2p_buy', 'p2a_sell', 'a2p_swap', 'get_a2p_rate', 'bc_crypto_a2p_submit_buy', 'bc_crypto_a2p_submit_sell', 'bc_crypto_a2p_execute_swap', 'isKYCEnforced', 'bc_verify_pin_secure', 'withdraw_channels'] as $tok) {
    mchk(strpos($saasApi, $tok) !== false, "web/api/crypto.php implements $tok");
}

function isServiceEnabled($name, $vid) { return true; }
require_once $root . '/DGV7.0-SAAS/func/bc-mobile-catalogue.php';
mchk(bc_mobile_destination('crypto', 1) === '/web/CryptoHub.php', 'mobile catalogue routes crypto to /web/CryptoHub.php');
mchk(bc_mobile_destination('local', 1) === '/web/SendFund.php', 'mobile catalogue routes local to /web/SendFund.php');
mchk(bc_mobile_destination('international', 1) === '/web/IntlTransfer.php', 'mobile catalogue routes international to /web/IntlTransfer.php');

echo "PASS $n mobile API CryptoHub A2P/P2A checks\n";
