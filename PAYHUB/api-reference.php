<?php
// php-version/api-reference.php
require_once 'includes/functions.php';

// Handle API requests (e.g., timeline)
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'get_timeline' && isset($_GET['id'])) {
        header('Content-Type: application/json');
        if (!isLoggedIn()) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $txId = (int)$_GET['id'];
        $user = getAuthUser();
        $db = Database::connect();

        // Ensure the transaction belongs to the user or user is admin
        if (isAdmin()) {
            $stmt = $db->prepare("SELECT * FROM transaction_timeline WHERE transaction_id = ? ORDER BY created_at ASC");
            $stmt->execute([$txId]);
        } else {
            $stmt = $db->prepare("SELECT tt.* FROM transaction_timeline tt JOIN transactions t ON tt.transaction_id = t.id WHERE tt.transaction_id = ? AND t.user_id = ? ORDER BY tt.created_at ASC");
            $stmt->execute([$txId, $user['id']]);
        }
        echo json_encode($stmt->fetchAll());
        exit;
    }
}

$pageTitle = 'API Reference - Payhub';
include 'includes/header.php';
?>
<div class="pt-20 flex flex-col md:flex-row min-h-screen" x-data="{ mobileDocNav: false }">
    <!-- Desktop Sidebar -->
    <aside class="w-64 border-r border-slate-100 p-8 hidden md:block sticky top-20 h-[calc(100vh-80px)] overflow-y-auto">
        <h3 class="font-bold text-slate-900 mb-6 uppercase text-xs tracking-widest">API Reference</h3>
        <nav class="space-y-6">
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase mb-3">Core</p>
                <div class="space-y-2">
                    <a href="#authentication" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Authentication</a>
                    <a href="#inline-checkout" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Inline Checkout</a>
                    <a href="#webhooks" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Webhooks & Callback</a>
                </div>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase mb-3">Payouts</p>
                <div class="space-y-2">
                    <a href="#payout-initialize" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Initialize Payout</a>
                </div>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase mb-3">Payments</p>
                <div class="space-y-2">
                    <a href="#initialize" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Initialize Transaction</a>
                    <a href="#verify" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Verify Transaction</a>
                    <a href="#reconcile" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Reconcile Payments</a>
                </div>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase mb-3">Accounts</p>
                <div class="space-y-2">
                    <a href="#virtual-accounts" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Fetch Virtual Accounts</a>
                </div>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase mb-3">Plugins</p>
                <div class="space-y-2">
                    <a href="#woocommerce" class="block text-sm text-emerald-600 font-bold">WooCommerce Plugin</a>
                    <a href="#whmcs" class="block text-sm text-blue-600 font-bold">WHMCS Module</a>
                </div>
            </div>
        </nav>
    </aside>

    <!-- Mobile Sub-Nav -->
    <div class="md:hidden sticky top-20 bg-white/95 backdrop-blur-sm border-b border-slate-100 z-30">
        <button @click="mobileDocNav = !mobileDocNav" class="w-full px-6 py-4 flex items-center justify-between text-indigo-600 font-bold text-sm">
            <span class="flex items-center gap-2">
                <i data-lucide="book-open" class="w-4 h-4"></i>
                Documentation Menu
            </span>
            <i data-lucide="chevron-down" class="w-4 h-4 transition-transform" :class="mobileDocNav ? 'rotate-180' : ''"></i>
        </button>
        <div x-show="mobileDocNav" x-cloak class="p-6 bg-slate-50 border-t border-slate-100 space-y-6 max-h-[60vh] overflow-y-auto">
            <nav class="space-y-6">
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-3 tracking-widest">Core</p>
                    <div class="space-y-3">
                        <a href="#authentication" @click="mobileDocNav = false" class="block text-sm text-slate-600 font-medium">Authentication</a>
                        <a href="#inline-checkout" @click="mobileDocNav = false" class="block text-sm text-slate-600 font-medium">Inline Checkout</a>
                        <a href="#webhooks" @click="mobileDocNav = false" class="block text-sm text-slate-600 font-medium">Webhooks & Callback</a>
                    </div>
                </div>
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-3 tracking-widest">Payouts</p>
                    <div class="space-y-3">
                        <a href="#payout-initialize" @click="mobileDocNav = false" class="block text-sm text-slate-600 font-medium">Initialize Payout</a>
                    </div>
                </div>
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-3 tracking-widest">Payments</p>
                    <div class="space-y-3">
                        <a href="#initialize" @click="mobileDocNav = false" class="block text-sm text-slate-600 font-medium">Initialize Transaction</a>
                        <a href="#verify" @click="mobileDocNav = false" class="block text-sm text-slate-600 font-medium">Verify Transaction</a>
                        <a href="#reconcile" @click="mobileDocNav = false" class="block text-sm text-slate-600 font-medium">Reconcile Payments</a>
                    </div>
                </div>
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-3 tracking-widest">Accounts</p>
                    <div class="space-y-3">
                        <a href="#virtual-accounts" @click="mobileDocNav = false" class="block text-sm text-slate-600 font-medium">Fetch Virtual Accounts</a>
                    </div>
                </div>
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-3 tracking-widest">Plugins</p>
                    <div class="space-y-3">
                        <a href="#woocommerce" @click="mobileDocNav = false" class="block text-sm text-emerald-600 font-bold">WooCommerce Plugin</a>
                        <a href="#whmcs" @click="mobileDocNav = false" class="block text-sm text-blue-600 font-bold">WHMCS Module</a>
                    </div>
                </div>
            </nav>
        </div>
    </div>

    <main class="flex-1 p-6 sm:p-8 lg:p-16 max-w-5xl overflow-hidden">
        <div class="mb-12 border-b border-slate-100 pb-12">
            <h1 class="text-4xl font-extrabold text-slate-900 mb-4">API Documentation</h1>
            <p class="text-xl text-slate-500 leading-relaxed">Everything you need to build powerful payment experiences with Payhub.</p>
        </div>

        <section id="authentication" class="mb-20 scroll-mt-40 md:scroll-mt-24">
            <h2 class="text-2xl font-bold text-slate-900 mb-4">Authentication</h2>
            <p class="text-slate-600 mb-6 leading-relaxed">The Payhub API uses Secret Keys to authenticate requests. You can view and manage your API keys in the <a href="merchant/api-keys.php" class="text-indigo-600 font-bold">Dashboard</a>. Your secret keys carry many privileges, so be sure to keep them secure!</p>
            <div class="bg-slate-900 rounded-2xl p-6 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
                Authorization: Bearer sk_live_xxxxxxxxxxxx
            </div>
        </section>

        <section id="inline-checkout" class="mb-20 scroll-mt-40 md:scroll-mt-24">
            <div class="flex items-center gap-4 mb-6">
                <span class="bg-indigo-100 text-indigo-700 px-3 py-1 rounded-lg font-bold text-[10px] sm:text-xs">JAVASCRIPT</span>
                <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Inline Checkout</h2>
            </div>
            <p class="text-slate-600 mb-8 leading-relaxed">Collect payments without redirecting your customers. Our inline checkout provides a seamless experience for your users.</p>
            <div class="bg-slate-900 rounded-2xl p-6 sm:p-8 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
                <p class="text-slate-500 mb-4">// Add the Payhub Inline Script</p>
                <pre>&lt;script src="<?php echo BASE_URL; ?>inline.js"&gt;&lt;/script&gt;

&lt;script&gt;
  const paymentForm = document.getElementById('paymentForm');
  paymentForm.addEventListener("submit", payWithPayhub, false);

  function payWithPayhub(e) {
    e.preventDefault();

    let handler = PayhubPop.setup({
      key: 'YOUR_PUBLIC_KEY', // Replace with your public key
      email: document.getElementById("email-address").value,
      amount: document.getElementById("amount").value * 100,
      ref: ''+Math.floor((Math.random() * 1000000000) + 1), // generates a pseudo-unique reference.
      onClose: function(){
        alert('Window closed.');
      },
      callback: function(response){
        let message = 'Payment complete! Reference: ' + response.reference;
        alert(message);
        window.location.href = "/success.php?ref=" + response.reference;
      }
    });

    handler.openIframe();
  }
&lt;/script&gt;</pre>
            </div>
        </section>

        <section id="initialize" class="mb-20 scroll-mt-40 md:scroll-mt-24">
            <div class="flex items-center gap-4 mb-6">
                <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-lg font-bold text-[10px] sm:text-xs">POST</span>
                <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Initialize Transaction</h2>
            </div>
            <p class="text-slate-600 mb-8 leading-relaxed">Start a transaction from your server to get a checkout URL. Optionally provide customer details to automate Virtual Account generation. <strong>Amount is in kobo</strong> (e.g. <code>500000</code> = ₦5,000).</p>
            <div class="bg-slate-900 rounded-2xl p-6 sm:p-8 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
                <pre>curl <?php echo BASE_URL; ?>api/transaction/initialize \
-H "Authorization: Bearer YOUR_SECRET_KEY" \
-d email="customer@email.com" \
-d amount=500000 \
-d name="John Doe" \
-d phone="08012345678"</pre>
            </div>
            <div class="mt-4 bg-slate-900 rounded-2xl p-6 sm:p-8 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
<pre>{
  "status": true,
  "message": "Transaction initialized",
  "data": {
    "authorization_url": "https://merchant.payhub.com.ng/checkout.php?ref=PH_abc&amount=500000&email=customer@email.com",
    "access_code": "PH_abc123",
    "reference": "PH_abc123"
  }
}</pre>
            </div>
        </section>

        <section id="verify" class="mb-20 scroll-mt-40 md:scroll-mt-24">
            <div class="flex items-center gap-4 mb-6">
                <span class="bg-amber-100 text-amber-700 px-3 py-1 rounded-lg font-bold text-[10px] sm:text-xs">GET</span>
                <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Verify Transaction</h2>
            </div>
            <p class="text-slate-600 mb-8 leading-relaxed">Confirm the status of a transaction using its reference. This endpoint also performs a real-time gateway check, so it is the authoritative source of truth for a payment's status.</p>
            <div class="bg-slate-900 rounded-2xl p-6 sm:p-8 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
                <pre>curl <?php echo BASE_URL; ?>api/transaction/verify/:reference \
-H "Authorization: Bearer YOUR_SECRET_KEY"</pre>
            </div>
            <div class="mt-6 bg-amber-50 border border-amber-200 rounded-2xl p-5 text-sm text-amber-800">
                <p class="font-bold mb-2">⚠️ Important — read the status correctly</p>
                <p>Top-level <code>status: true</code> only means <em>"transaction retrieved"</em> — it does NOT mean paid. Use the <code>paid</code> boolean or <code>data.status</code> to determine the real outcome:</p>
            </div>
            <div class="mt-4 bg-slate-900 rounded-2xl p-6 sm:p-8 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
<pre>{
  "status": true,
  "message": "Transaction retrieved",
  "paid": true,
  "data": {
    "id": 1,
    "status": "success",
    "payment_status": "success",
    "paid": true,
    "reference": "PH_abc123",
    "amount": 10000,
    "currency": "NGN",
    "customer": { "email": "customer@email.com" },
    "gateway_response": "Successful",
    "created_at": "2025-01-01T00:00:00.000000Z",
    "metadata": []
  }
}</pre>
            </div>
            <p class="text-slate-500 text-xs mt-3">Status values: <code>success</code>, <code>pending</code>, <code>failed</code>. <code>amount</code> is in <strong>kobo</strong>.</p>
        </section>

        <section id="reconcile" class="mb-20 scroll-mt-40 md:scroll-mt-24">
            <div class="flex items-center gap-4 mb-6">
                <span class="bg-indigo-100 text-indigo-700 px-3 py-1 rounded-lg font-bold text-[10px] sm:text-xs">POST</span>
                <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Reconcile Payments</h2>
            </div>
            <p class="text-slate-600 mb-8 leading-relaxed">Scan the gateway for missing successful payments for a specific date and sync them to your wallet. Useful for handling webhook failures.</p>
            <div class="bg-slate-900 rounded-2xl p-6 sm:p-8 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
                <pre>curl <?php echo BASE_URL; ?>api/transaction/reconcile \
-H "Authorization: Bearer YOUR_SECRET_KEY" \
-d date="2023-12-25" \
-d channel="all" <span class="text-slate-500">// Optional: all, card, or dedicated_account</span></pre>
            </div>
        </section>

        <section id="payout-initialize" class="mb-20 scroll-mt-40 md:scroll-mt-24">
            <div class="flex items-center gap-4 mb-6">
                <span class="bg-rose-100 text-rose-700 px-3 py-1 rounded-lg font-bold text-[10px] sm:text-xs">POST</span>
                <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Initialize Payout</h2>
            </div>
            <p class="text-slate-600 mb-8 leading-relaxed">Withdraw funds from your Payhub wallet directly to your registered settlement bank account. Requests are subject to 24-hour rolling limits and administrative review policies. <strong>Amount is in naira</strong>.</p>
            <div class="bg-slate-900 rounded-2xl p-6 sm:p-8 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
                <pre>curl <?php echo BASE_URL; ?>api/payout/initialize \
-H "Authorization: Bearer YOUR_SECRET_KEY" \
-d amount=5000 \
-d reason="Inventory Purchase"</pre>
            </div>
            <div class="mt-6 bg-amber-50 border border-amber-200 rounded-2xl p-5 text-sm text-amber-800">
                <p class="font-bold mb-2">⚠️ Important — read the status correctly</p>
                <p>Top-level <code>status: true</code> only means the request was accepted. A payout can be <strong>queued for review</strong> (<code>processed: false</code>) or actually <strong>executed</strong> (<code>processed: true</code>). Check the <code>processed</code> boolean or <code>data.status</code>.</p>
            </div>
            <div class="mt-4 bg-slate-900 rounded-2xl p-6 sm:p-8 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
<pre>{
  "status": true,
  "message": "Payout initiated successfully",
  "processed": true,
  "data": {
    "id": 12,
    "amount": 5100,
    "fee": 100,
    "net_amount": 5000,
    "status": "processed",
    "processed": true,
    "bank": "GTBank",
    "account": "0123456789"
  }
}</pre>
            </div>
        </section>

        <section id="virtual-accounts" class="mb-20 scroll-mt-40 md:scroll-mt-24">
            <div class="flex items-center gap-4 mb-6">
                <span class="bg-slate-100 text-slate-700 px-3 py-1 rounded-lg font-bold text-[10px] sm:text-xs">GET</span>
                <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Fetch Virtual Accounts</h2>
            </div>
            <p class="text-slate-600 mb-8 leading-relaxed">Retrieve dedicated virtual account details for your customers. Providing <code>account_number</code> and <code>date</code> will also trigger a reconciliation check for that specific account.</p>
            <div class="bg-slate-900 rounded-2xl p-6 sm:p-8 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
                <p class="text-slate-500 mb-4">// List all accounts</p>
                <pre class="mb-6 text-indigo-400">curl <?php echo BASE_URL; ?>api/virtual-accounts \
-H "Authorization: Bearer YOUR_SECRET_KEY"</pre>

                <p class="text-slate-500 mb-4">// Get specific account and reconcile</p>
                <pre class="text-emerald-400">curl "<?php echo BASE_URL; ?>api/virtual-accounts?account_number=9756119108&date=2023-12-25" \
-H "Authorization: Bearer YOUR_SECRET_KEY"</pre>
            </div>
        </section>

        <section id="webhooks" class="mb-20 scroll-mt-40 md:scroll-mt-24">
            <div class="flex items-center gap-4 mb-6">
                <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-lg font-bold text-[10px] sm:text-xs">WEBHOOK</span>
                <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Webhooks & Callback</h2>
            </div>
            <p class="text-slate-600 mb-8 leading-relaxed">Configure your server to listen for events from Payhub. Every webhook is signed so you can verify it genuinely came from Payhub before trusting it.</p>
            <div class="bg-slate-900 rounded-2xl p-6 sm:p-8 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
                <pre>{
  "event": "charge.success",
  "data": {
    "id": 1,
    "reference": "PH_abc123",
    "amount": 10000,
    "status": "success",
    "currency": "NGN",
    "customer": { "email": "customer@email.com" },
    "metadata": [],
    "channel": "card",
    "paid_at": "2025-01-01T00:00:00.000000Z"
  }
}</pre>
            </div>

            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-2xl p-5 text-sm text-blue-800">
                <p class="font-bold mb-2">🔐 Verifying the webhook signature</p>
                <p class="mb-3">Each webhook is signed with your secret key and the signature is sent in the <code class="font-mono">X-Payhub-Signature</code> header:</p>
                <p class="font-mono bg-white rounded-lg p-3 text-xs">X-Payhub-Signature = HMAC-SHA256(raw JSON body, YOUR_SECRET_KEY)</p>
                <p class="mt-3 mb-2">Always verify this signature before crediting a wallet, and reject any request whose signature does not match.</p>
            </div>
            <div class="mt-4 bg-slate-900 rounded-2xl p-6 sm:p-8 text-slate-300 font-mono text-xs sm:text-sm overflow-x-auto">
<pre>// PHP — verify the webhook came from Payhub
$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYHUB_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $body, 'YOUR_SECRET_KEY');
if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}</pre>
            </div>
        </section>

        <section id="woocommerce" class="mb-20 scroll-mt-24">
            <div class="p-8 bg-emerald-50 rounded-[2.5rem] border border-emerald-100 flex flex-col md:flex-row items-center gap-8 relative overflow-hidden">
                <div class="absolute top-4 right-4 bg-emerald-600 text-white px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-widest">Beta / Skeleton</div>
                <div class="w-20 h-20 bg-white rounded-3xl flex items-center justify-center shadow-sm">
                    <i class="lucide-shopping-cart text-emerald-600 w-10 h-10"></i>
                </div>
                <div class="flex-1 text-center md:text-left">
                    <h3 class="text-2xl font-bold text-emerald-900 mb-2">WooCommerce Plugin</h3>
                    <p class="text-emerald-700 mb-6">Accept payments on your WordPress store. This is a reference implementation.</p>
                    <a href="downloads/payhub-woocommerce.zip" class="inline-flex items-center gap-2 bg-emerald-600 text-white px-8 py-3 rounded-xl font-bold hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-200">
                        <i class="lucide-download w-4 h-4"></i> Download Plugin
                    </a>
                </div>
            </div>
        </section>

        <section id="whmcs" class="mb-20 scroll-mt-24">
            <div class="p-8 bg-blue-50 rounded-[2.5rem] border border-blue-100 flex flex-col md:flex-row items-center gap-8 relative overflow-hidden">
                <div class="absolute top-4 right-4 bg-blue-600 text-white px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-widest">Beta / Skeleton</div>
                <div class="w-20 h-20 bg-white rounded-3xl flex items-center justify-center shadow-sm">
                    <i class="lucide-server text-blue-600 w-10 h-10"></i>
                </div>
                <div class="flex-1 text-center md:text-left">
                    <h3 class="text-2xl font-bold text-blue-900 mb-2">WHMCS Payment Module</h3>
                    <p class="text-blue-700 mb-6">Automate your hosting business. This is a reference implementation.</p>
                    <a href="downloads/payhub-whmcs.zip" class="inline-flex items-center gap-2 bg-blue-600 text-white px-8 py-3 rounded-xl font-bold hover:bg-blue-700 transition-all shadow-lg shadow-blue-200">
                        <i class="lucide-download w-4 h-4"></i> Download Module
                    </a>
                </div>
            </div>
        </section>
    </main>
</div>
<?php include 'includes/footer.php'; ?>
