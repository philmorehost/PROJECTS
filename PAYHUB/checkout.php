<?php
// php-version/checkout.php
require_once 'includes/functions.php';

$ref = sanitize($_GET['ref'] ?? '');
$db = Database::connect();
// Use the transaction's own is_test flag (set at initialize-time from which
// secret key — sk_test_/sk_live_ — the caller used), the same way verify.php
// already does. Previously this joined to the merchant's account-wide
// is_test_mode toggle instead, so a transaction correctly initialized in test
// mode would still render with the live Paystack key here.
$stmt = $db->prepare("SELECT * FROM transactions WHERE reference = ?");
$stmt->execute([$ref]);
$tx = $stmt->fetch();

$isTest = $tx ? (bool)$tx['is_test'] : false;
$pk = $isTest ? getConfig('paystack_test_public_key') : getConfig('paystack_public_key');

/*
 * Sandbox only: issue a single-use token so api/transaction/simulate.php can tell a real
 * visit to this page apart from a blind request against the endpoint. A merchant secret
 * key cannot be used here - this page runs in the payer's browser - and the Authorization
 * header this page used to send was never validated, so it implied a check that did not
 * exist. The token is stored against the transaction and cleared when it is spent.
 */
$simulateToken = '';
if ($isTest && $tx) {
    ensure_payment_schema();
    $simulateToken = bin2hex(random_bytes(16));
    try {
        $db->prepare("UPDATE transactions SET checkout_token = ? WHERE id = ?")->execute([$simulateToken, $tx['id']]);
    } catch (\Throwable $e) {
        $simulateToken = ''; // no token means the sandbox button will refuse, never silently credit
    }
}

$amount = $tx ? (float)$tx['amount'] : (float)($_GET['amount'] ?? 1000);
// Sanitize the query-string branch: only the $tx branch has been through
// sanitize() (at initialize time). This value is rendered into the page and
// into a <script> block below, so an unsanitized ?email= was a reflected XSS
// reachable with any reference that has no transaction.
$email = $tx ? $tx['customer_email'] : sanitize($_GET['email'] ?? '');
if (!$ref) $ref = 'PH_'.time();
$isEmbedded = isset($_GET['embed']) && $_GET['embed'] == '1';

// Where postMessage results may be delivered. inline.js sends its own origin;
// accept it only if it is a well-formed scheme://host[:port], so the value can
// never widen delivery beyond a single concrete origin.
$parentOrigin = (string)($_GET['origin'] ?? '');
if (!preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?$#', $parentOrigin)) {
    $parentOrigin = '';
}

if (!$isEmbedded) {
    include 'includes/header.php';
} else {
    // Basic styles for embedded version
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Plus Jakarta Sans', sans-serif; margin: 0; }
            /*
             * Plain-CSS baseline for the embedded checkout, independent of the
             * Tailwind CDN <script> above. Mobile app WebViews load this page
             * nested two levels deep (app WebView -> our iframe -> this iframe),
             * and the Tailwind CDN runtime-JIT script has been observed to fail
             * or apply too late in that environment, leaving the pay button
             * unstyled/invisible even though the markup is present. These rules
             * guarantee a usable checkout card + button regardless; Tailwind's
             * own utility classes still take visual precedence when it does load
             * (its <style> tag is injected later in the document, so it wins).
             */
            .ph-embed-wrap { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 16px; box-sizing: border-box; }
            .ph-card { max-width: 420px; width: 100%; background: #fff; border-radius: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.15); border: 1px solid #f1f5f9; overflow: hidden; }
            .ph-card-header { padding: 24px; border-bottom: 1px solid #f1f5f9; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; }
            .ph-card-body { padding: 24px; }
            .ph-row { display: flex; align-items: center; gap: 8px; }
            .ph-customer { padding: 16px; background: #f8fafc; border-radius: 16px; border: 1px solid #f1f5f9; margin-bottom: 24px; }
            #pay-btn { display: block; width: 100%; box-sizing: border-box; padding: 16px; border: none; border-radius: 16px; font-family: inherit; font-weight: 700; font-size: 15px; color: #fff; cursor: pointer; }
            #pay-btn.ph-test { background: #f59e0b; }
            #pay-btn.ph-live { background: #4f46e5; }
        </style>
    </head>
    <body class="bg-white">
    <?php
}
?>
<div class="<?php echo $isEmbedded ? 'ph-embed-wrap' : 'pt-32 pb-20 bg-slate-50 min-h-screen flex items-center justify-center p-4'; ?>">
    <?php if ($isTest): ?>
        <div class="fixed top-0 left-0 right-0 bg-amber-500 text-white text-[10px] font-bold uppercase tracking-[0.2em] py-2 text-center z-[100] flex items-center justify-center gap-2">
            <i class="lucide-shield-alert w-3 h-3"></i>
            Test Mode - No real money will be processed
            <i class="lucide-shield-alert w-3 h-3"></i>
        </div>
    <?php endif; ?>
    <div class="ph-card max-w-md w-full bg-white rounded-[2.5rem] shadow-xl border border-slate-100 overflow-hidden relative">
        <div class="ph-card-header p-8 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
            <div class="ph-row flex items-center gap-2">
                <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center text-white">
                    <i class="lucide-credit-card w-4 h-4"></i>
                </div>
                <span class="font-bold text-slate-900 uppercase text-xs tracking-widest">Secure Checkout</span>
            </div>
            <div class="text-right">
                <p class="text-[10px] text-slate-400 font-bold uppercase">Amount</p>
                <p class="text-lg font-bold text-indigo-600"><?php echo formatCurrency($amount); ?></p>
            </div>
        </div>
        <div class="ph-card-body p-8">
            <div class="mb-8">
                <p class="text-sm text-slate-500 mb-1">Paying to</p>
                <p class="font-bold text-slate-900 text-lg"><?php echo getConfig('site_name', 'Payhub'); ?></p>
            </div>

            <div class="space-y-4 mb-8">
                <div class="ph-customer ph-row p-4 bg-slate-50 rounded-2xl border border-slate-100 flex items-center gap-4">
                    <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center text-slate-400">
                        <i class="lucide-user w-5 h-5"></i>
                    </div>
                    <div>
                        <p class="text-[10px] text-slate-400 font-bold uppercase">Customer</p>
                        <p class="text-sm font-bold text-slate-700"><?php echo htmlspecialchars($email ?: 'Guest Customer', ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>
            </div>

            <?php if (!$isTest): ?>
            <script src="https://js.paystack.co/v2/inline.js"></script>
            <?php endif; ?>
            <button onclick="<?php echo $isTest ? 'simulateSuccess()' : 'payWithPaystack()'; ?>" id="pay-btn" class="<?php echo $isTest ? 'ph-test' : 'ph-live'; ?> w-full <?php echo $isTest ? 'bg-amber-500 hover:bg-amber-600 shadow-amber-100' : 'bg-indigo-600 hover:bg-indigo-700 shadow-indigo-200'; ?> text-white py-4 rounded-2xl font-bold shadow-lg transition-all flex items-center justify-center gap-2">
                <i class="lucide-<?php echo $isTest ? 'beaker' : 'shield-check'; ?> w-5 h-5"></i>
                <?php echo $isTest ? 'Simulate Success' : 'Pay Now'; ?>
            </button>

            <p class="mt-6 text-center text-[10px] text-slate-400 uppercase tracking-widest flex items-center justify-center gap-1">
                <i class="lucide-lock w-3 h-3"></i> Secured by Payhub
            </p>
        </div>
    </div>
</div>

<script>
<?php
/*
 * Values interpolated into this block are emitted with json_encode() rather
 * than echoed between quotes. Inside a <script> a JS string is not an HTML
 * text node, so htmlspecialchars() is the wrong escaper — json_encode with
 * the HEX_* flags produces a complete, correctly quoted JS literal and cannot
 * terminate the string or the enclosing </script>.
 */
$jsEnc = static function ($value): string {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
};
// '' would be an invalid postMessage target, so fall back to '*' only when no
// valid origin was supplied — matching the previous behaviour for callers that
// predate the origin parameter, while a known origin is always targeted exactly.
$jsTargetOrigin = $parentOrigin !== '' ? $jsEnc($parentOrigin) : $jsEnc('*');
?>
<?php if ($isTest): ?>
// SANDBOX MODE: Simulate success without going through real Paystack API
function simulateSuccess() {
    const btn = document.getElementById('pay-btn');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    fetch(<?php echo $jsEnc(BASE_URL . 'api/transaction/simulate.php?reference=' . urlencode($ref) . '&token=' . urlencode($simulateToken)); ?>, {
        method: 'GET'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status) {
            <?php if ($isEmbedded): ?>
                window.parent.postMessage({
                    type: 'payhub_success',
                    data: { reference: <?php echo $jsEnc($ref); ?>, status: 'success' }
                }, <?php echo $jsTargetOrigin; ?>);
            <?php else: ?>
                window.location.href = <?php echo $jsEnc('verify.php?reference=' . urlencode($ref)); ?>;
            <?php endif; ?>
        } else {
            btn.disabled = false;
            btn.textContent = 'Simulate Success';
            alert('Simulation failed: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(function(e) {
        btn.disabled = false;
        btn.textContent = 'Simulate Success';
        alert('Network error: ' + e.message);
    });
}
<?php else: ?>
// LIVE MODE: Use real Paystack
function payWithPaystack() {
    const paystack = new PaystackPop();
    paystack.newTransaction({
        key: <?php echo $jsEnc($pk); ?>,
        email: <?php echo $jsEnc($email); ?>,
        amount: <?php echo (int) round($amount * 100); ?>,
        reference: <?php echo $jsEnc($ref); ?>,
        onCancel: function(){
            alert('Transaction cancelled.');
        },
        onSuccess: function(response){
            <?php if ($isEmbedded): ?>
                window.parent.postMessage({
                    type: 'payhub_success',
                    data: response
                }, <?php echo $jsTargetOrigin; ?>);
            <?php else: ?>
                window.location.href = 'verify.php?reference=' + encodeURIComponent(response.reference);
            <?php endif; ?>
        }
    });
}
<?php endif; ?>
</script>

<?php
if (!$isEmbedded) {
    include 'includes/footer.php';
} else {
    ?></body></html><?php
}
?>
