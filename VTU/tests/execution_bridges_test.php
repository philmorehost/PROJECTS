<?php
/**
 * Offline policy & security contract checks for the wired execution bridges
 * across DGV7.0-SAAS and DGV7.0-NON-SAAS.
 */
$checks = 0;
function eb_chk($cond, $label) {
    global $checks;
    $checks++;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$sharedHelpers = [
    'func/bc-service-intake-bridge.php',
    'func/bc-checkout-execution.php',
    'func/bc-payout-execution.php',
    'func/bc-virtualcard-execution.php',
    'func/bc-giftcard-execution.php',
    'func/bc-giftcard-safety.php',
    'func/bc-giftcard-func.php',
    'func/bc-virtualcard-flutterwave.php',
    'web/api/create-checkout.php',
    'web/api/payhub-checkout.php',
    'web/api/virtual-card.php',
    'web/api/virtual-card-v2.php',
];

foreach ($sharedHelpers as $rel) {
    $saas = @file_get_contents("$root/DGV7.0-SAAS/$rel");
    $non  = @file_get_contents("$root/DGV7.0-NON-SAAS/$rel");
    eb_chk($saas !== false && $non !== false && $saas === $non, "SAAS/NON-SAAS parity: $rel");
}

foreach (['SAAS', 'NON-SAAS'] as $ed) {
    $base = "$root/DGV7.0-$ed";
    $bt = file_get_contents("$base/web/func/bank-transfer.php");
    eb_chk(strpos($bt, "getenv('BC_PAYOUT_WORKER_ENABLED') !== '1'") !== false, "$ed bank-transfer fails closed unless BC_PAYOUT_WORKER_ENABLED=1");
    eb_chk(strpos($bt, 'bc_payout_execute_user_request(') !== false, "$ed bank-transfer wired to durable payout reservation bridge");

    $wd = file_get_contents("$base/bc-admin/Withdrawals.php");
    eb_chk(strpos($wd, "getenv('BC_PAYOUT_WORKER_ENABLED') !== '1'") !== false, "$ed Withdrawals.php fails closed unless BC_PAYOUT_WORKER_ENABLED=1");
    eb_chk(strpos($wd, 'bc_payout_admin_action(') !== false, "$ed Withdrawals.php wired to durable payout admin bridge");

    $cc = file_get_contents("$base/web/api/create-checkout.php");
    $pc = file_get_contents("$base/web/api/payhub-checkout.php");
    eb_chk(strpos($cc, "getenv('BC_CHECKOUT_EXECUTION_ENABLED') !== '1'") !== false, "$ed create-checkout fails closed unless BC_CHECKOUT_EXECUTION_ENABLED=1");
    eb_chk(strpos($pc, "getenv('BC_CHECKOUT_EXECUTION_ENABLED') !== '1'") !== false, "$ed payhub-checkout fails closed unless BC_CHECKOUT_EXECUTION_ENABLED=1");

    $vc2 = file_get_contents("$base/web/api/virtual-card-v2.php");
    eb_chk(strpos($vc2, "getenv('BC_VIRTUALCARD_WORKER_ENABLED') !== '1'") !== false, "$ed virtual-card-v2 fails closed unless BC_VIRTUALCARD_WORKER_ENABLED=1");

    $gc = file_get_contents("$base/web/GiftCard.php");
    $gcApi = file_get_contents("$base/web/api/gift-card.php");
    eb_chk(strpos($gc, 'bc_gc_execute_purchase(') !== false && strpos($gc, 'bc_gc_execute_p2p_buy(') !== false, "$ed GiftCard.php wired to durable giftcard execution");
    eb_chk(strpos($gcApi, 'bc_gc_execute_purchase(') !== false && strpos($gcApi, 'bc_gc_execute_p2p_buy(') !== false, "$ed api/gift-card.php wired to durable giftcard execution");

    foreach (['IntlTransfer.php', 'Tuition.php', 'POSRequest.php'] as $svcPage) {
        $src = file_get_contents("$base/web/$svcPage");
        eb_chk(strpos($src, 'bc_svc_enqueue_held_attempt(') !== false, "$ed web/$svcPage enqueues held service attempt");
    }
}

// Verify bc-giftcard-safety.php fail-closed by default & enabled when BC_GIFTCARD_WORKER_ENABLED=1
putenv('BC_GIFTCARD_WORKER_ENABLED=');
require_once "$root/DGV7.0-SAAS/func/bc-giftcard-func.php";
eb_chk(bc_giftcard_action_held('purchase_card') === true, 'giftcard action held when env disabled');
eb_chk(bc_giftcard_action_held('buy_p2p') === true, 'giftcard p2p held when env disabled');

putenv('BC_GIFTCARD_WORKER_ENABLED=1');
eb_chk(bc_giftcard_action_held('purchase_card') === false, 'giftcard action enabled when BC_GIFTCARD_WORKER_ENABLED=1');

// Verify AES-256-GCM KMS gift-card encryption refuses missing key in active mode and round-trips with KMS key
$connection_server = null;
$threw = false;
try {
    putenv('BC_GIFTCARD_KMS_KEY=');
    encryptGiftCode('TEST-CODE-1234', 1);
} catch (RuntimeException $e) {
    $threw = ($e->getMessage() === 'giftcard_kms_key_unconfigured');
}
eb_chk($threw, 'encryptGiftCode refuses hardcoded default key when worker enabled without KMS key');

if (function_exists('openssl_encrypt')) {
    putenv('BC_GIFTCARD_KMS_KEY=0123456789abcdef0123456789abcdef0123456789abcdef');
    $ct = encryptGiftCode('GIFT-GCM-9988', 7);
    eb_chk(strpos($ct, 'v2:') === 0, 'encryptGiftCode emits authenticated v2 AES-256-GCM envelope');
    eb_chk(decryptGiftCode($ct, 7) === 'GIFT-GCM-9988', 'decryptGiftCode decrypts v2 envelope for same vendor');
    eb_chk(decryptGiftCode($ct, 8) === '', 'decryptGiftCode isolates vendor key derivation');
}
putenv('BC_GIFTCARD_WORKER_ENABLED=');
putenv('BC_GIFTCARD_KMS_KEY=');

// 6. VirtualCard UI routes cleanly through virtual-card-v2.php (zero legacy virtual-card-ajax.php or CVV reveal) & webhook syncs checkout intents
foreach (['SAAS', 'NON-SAAS'] as $ed) {
    $vcPage = file_get_contents("$root/DGV7.0-$ed/web/VirtualCard.php");
    eb_chk(strpos($vcPage, 'virtual-card-ajax.php') === false, "$ed VirtualCard.php no longer calls retired virtual-card-ajax.php");
    eb_chk(strpos($vcPage, 'api/virtual-card-v2.php') !== false && strpos($vcPage, 'X-CSRF-Token') !== false, "$ed VirtualCard.php routes mutations through CSRF-protected api/virtual-card-v2.php");
    eb_chk(strpos($vcPage, 'revealSecurity') === false && strpos($vcPage, 'd.cvv') === false, "$ed VirtualCard.php never exposes plaintext CVV");

    $whFile = file_get_contents("$root/DGV7.0-$ed/func/bc-fintech-webhook.php");
    eb_chk(strpos($whFile, 'bc_chk_reconcile_settlement(') !== false && strpos($whFile, 'BC_CHECKOUT_EXECUTION_ENABLED') !== false, "$ed bc-fintech-webhook.php syncs hosted checkout intents when enabled");
}

echo "PASS $checks execution bridge policy checks\n";
