<?php
// php-version/invoice.php
require_once 'includes/functions.php';

$ref = sanitize($_GET['ref'] ?? '');
if (!$ref) die("Invalid Invoice");

$db = Database::connect();
// We fetch business_name and is_test_mode. We avoid selecting potentially non-existent public_key columns
// and instead rely on platform-level configuration for now, or check for them safely.
$stmt = $db->prepare("SELECT i.*, u.business_name, u.email as merchant_email, u.is_test_mode
                      FROM invoices i
                      JOIN users u ON i.user_id = u.id
                      WHERE i.reference = ?");
$stmt->execute([$ref]);
$inv = $stmt->fetch();

if (!$inv) die("Invoice not found");

if ($inv['status'] === 'paid') {
    $pageTitle = "Invoice Paid - " . $inv['reference'];
} else {
    $pageTitle = "Pay Invoice - " . $inv['reference'];
}

include 'includes/header.php';
?>

<div class="pt-32 pb-20 bg-slate-50 min-h-screen relative">
    <?php if ($inv['is_test_mode']): ?>
        <div class="fixed top-0 left-0 right-0 bg-amber-500 text-white text-[10px] font-bold uppercase tracking-[0.2em] py-2 text-center z-[100] flex items-center justify-center gap-2">
            <i data-lucide="shield-alert" class="w-3 h-3"></i>
            Test Mode - No real money will be processed
            <i data-lucide="shield-alert" class="w-3 h-3"></i>
        </div>
    <?php endif; ?>
    <div class="max-w-4xl mx-auto px-4">
        <div class="bg-white rounded-[2.5rem] shadow-xl border border-slate-100 overflow-hidden">
            <div class="p-8 lg:p-12 border-b border-slate-100 flex flex-col md:flex-row justify-between items-start md:items-center gap-6 bg-slate-50/50">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white">
                            <i data-lucide="file-text" class="w-6 h-6"></i>
                        </div>
                        <h1 class="text-2xl font-bold text-slate-900">Invoice <?php echo $inv['reference']; ?></h1>
                    </div>
                    <p class="text-slate-500 font-medium">Issued by <?php echo $inv['business_name']; ?></p>
                </div>
                <div class="text-right">
                    <span class="px-4 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider <?php echo $inv['status'] === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
                        <?php echo $inv['status']; ?>
                    </span>
                    <p class="text-[10px] text-slate-400 font-bold uppercase mt-2">Due Date: <?php echo date('M d, Y', strtotime($inv['due_date'])); ?></p>
                </div>
            </div>

            <div class="p-8 lg:p-12">
                <div class="grid md:grid-cols-2 gap-12 mb-12">
                    <div>
                        <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-4">Bill To</h4>
                        <p class="text-lg font-bold text-slate-900"><?php echo $inv['customer_name']; ?></p>
                        <p class="text-slate-500 font-medium"><?php echo $inv['customer_email']; ?></p>
                    </div>
                    <div class="md:text-right">
                        <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-4">Total Amount</h4>
                        <p class="text-4xl font-extrabold text-indigo-600"><?php echo formatCurrency($inv['amount']); ?></p>
                    </div>
                </div>

                <div class="bg-slate-50 rounded-3xl p-8 mb-12 border border-slate-100">
                    <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-4">Description</h4>
                    <p class="text-slate-700 leading-relaxed"><?php echo nl2br($inv['description']); ?></p>
                </div>

                <?php if ($inv['status'] !== 'paid'): ?>
                    <div class="flex flex-col items-center gap-6">
                        <button onclick="payInvoice()" class="w-full max-w-md <?php echo $inv['is_test_mode'] ? 'bg-amber-500 hover:bg-amber-600 shadow-amber-100' : 'bg-indigo-600 hover:bg-indigo-700 shadow-indigo-200'; ?> text-white py-5 rounded-2xl font-bold text-lg shadow-xl transition-all flex items-center justify-center gap-3">
                            <i data-lucide="<?php echo $inv['is_test_mode'] ? 'beaker' : 'shield-check'; ?>" class="w-6 h-6"></i>
                            <?php echo $inv['is_test_mode'] ? 'Simulate Success' : 'Pay Now with Payhub'; ?>
                        </button>
                        <p class="text-[10px] text-slate-400 uppercase tracking-widest flex items-center gap-2">
                            <i data-lucide="lock" class="w-3 h-3"></i>
                            Secure Payment Processing by Payhub
                        </p>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12 bg-emerald-50 rounded-3xl border border-emerald-100">
                        <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i data-lucide="check" class="w-8 h-8"></i>
                        </div>
                        <h3 class="text-xl font-bold text-emerald-900">This invoice has been paid</h3>
                        <p class="text-emerald-700 mt-2">Thank you for your business!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://js.paystack.co/v2/inline.js"></script>
<script>
function payInvoice() {
    const paystack = new PaystackPop();
    paystack.newTransaction({
        key: '<?php echo $inv['is_test_mode'] ? getConfig('paystack_test_public_key') : getConfig('paystack_public_key'); ?>',
        email: '<?php echo $inv['customer_email']; ?>',
        amount: <?php echo $inv['amount'] * 100; ?>,
        reference: 'INV_<?php echo $inv['reference']; ?>_' + Math.floor(Math.random() * 1000000),
        metadata: {
            invoice_id: <?php echo $inv['id']; ?>,
            custom_fields: [
                { display_name: "Invoice Reference", variable_name: "invoice_ref", value: "<?php echo $inv['reference']; ?>" }
            ]
        },
        onSuccess: function(response){
            window.location.href = "verify.php?reference=" + response.reference;
        },
        onCancel: function(){
            alert('Payment cancelled');
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>
