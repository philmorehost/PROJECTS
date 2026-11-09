<?php
// php-version/merchant/transactions.php
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCompliance();

$user = getAuthUser();
$pageTitle = 'Transactions - Payhub';

$db = Database::connect();

// Handle Refund Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refund') {
    $txId = (int)$_POST['transaction_id'];

    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ? AND status = 'success'");
    $stmt->execute([$txId, $user['id']]);
    $tx = $stmt->fetch();

    if ($tx) {
        // Perform Refund via Paystack API.
        // The transaction's OWN mode picks the Paystack key: passing nothing let
        // paystack_call fall back to the merchant's account-wide flag, so refunding a
        // sandbox payment could hit the LIVE Paystack API.
        $refund_res = paystack_call('refund', 'POST', [
            'transaction' => $tx['gateway_reference'] ?: $tx['reference'],
            'amount' => $tx['amount'] * 100
        ], (bool)$tx['is_test']);

        if ($refund_res && $refund_res['status']) {
            ensure_payment_schema();
            $db->beginTransaction();
            try {
                // Reverse the credit through the shared, idempotent reversal path. It debits
                // on the same basis as a gateway-initiated refund and records the reversal, so
                // when Paystack also reports refund.processed the merchant is not debited twice.
                $credited = (float)$tx['settled_amount'] > 0 ? (float)$tx['settled_amount'] : (float)$tx['amount'];
                $refund_ref = 'refund:local:' . ($refund_res['data']['id'] ?? ($refund_res['data']['reference'] ?? $tx['reference']));
                $reversal = record_transaction_reversal($txId, $refund_ref, $credited, 'refund');

                $db->commit();

                if ($reversal['applied']) {
                    $success_msg = "Refund processed successfully!";
                } else {
                    // Paystack refunded but our ledger already reflected it (or the charge was
                    // never credited). Never debit twice - surface it for a human instead.
                    $error_msg = "Paystack processed the refund, but no ledger debit was needed (" . $reversal['reason'] . "). Please verify the merchant balance.";
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error_msg = "Database Error: " . $e->getMessage();
            }
        } else {
            $error_msg = "Paystack Refund Failed: " . ($refund_res['message'] ?? 'Unknown Error');
        }
    } else {
        $error_msg = "Invalid transaction or already refunded.";
    }
}

// Fetch Transactions
$is_test = $user['is_test_mode'];
$stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? AND is_test = ? ORDER BY created_at DESC");
$stmt->execute([$user['id'], $is_test]);
$transactions = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>

    <main class="flex-1 flex flex-col min-w-0 overflow-hidden" x-data="{ selectedTx: null, timeline: [], loadingTimeline: false }">
        <?php include '../includes/topbar.php'; ?>

        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if (isset($success_msg)): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>
            <?php if (isset($error_msg)): ?>
                <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-2xl border border-red-100 font-medium">
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 mb-2">Transactions</h1>
                    <p class="text-slate-500">Monitor and manage all your customer payments</p>
                </div>
                <div class="flex gap-2">
                    <button class="p-2.5 bg-white border border-slate-200 text-slate-500 hover:bg-slate-50 rounded-xl shadow-sm transition-all"><i data-lucide="filter" class="w-5 h-5"></i></button>
                    <button class="p-2.5 bg-white border border-slate-200 text-slate-500 hover:bg-slate-50 rounded-xl shadow-sm transition-all"><i data-lucide="download" class="w-5 h-5"></i></button>
                </div>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50/50">
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Reference</th>
                                <th class="hidden lg:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Customer</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Amount</th>
                                <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Fee</th>
                                <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Settled</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($transactions as $tx): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-6 py-4 font-mono text-sm"><?php echo $tx['reference']; ?></td>
                                    <td class="hidden lg:table-cell px-6 py-4 text-sm"><?php echo $tx['customer_email']; ?></td>
                                    <td class="px-6 py-4 text-sm font-bold"><?php echo formatCurrency($tx['amount']); ?></td>
                                    <td class="hidden md:table-cell px-6 py-4 text-sm text-red-500">-<?php echo formatCurrency($tx['fee_amount']); ?></td>
                                    <td class="hidden md:table-cell px-6 py-4 text-sm text-emerald-600 font-bold"><?php echo formatCurrency($tx['settled_amount']); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php
                                            echo $tx['status'] === 'success' ? 'bg-emerald-100 text-emerald-700' :
                                                ($tx['status'] === 'refunded' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700');
                                        ?>">
                                            <?php echo $tx['status']; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2">
                                            <button
                                                @click="selectedTx = <?php echo htmlspecialchars(json_encode($tx)); ?>; loadingTimeline = true; fetch('../api-reference.php?action=get_timeline&id=' + selectedTx.id).then(r => r.json()).then(data => { timeline = data; loadingTimeline = false; })"
                                                class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all"
                                                title="View Timeline"
                                            >
                                                <i data-lucide="activity" class="w-4 h-4"></i>
                                            </button>
                                            <?php if ($tx['status'] === 'success'): ?>
                                                <form method="POST" onsubmit="return confirm('Are you sure you want to refund this transaction?');" class="inline">
                                                    <input type="hidden" name="action" value="refund">
                                                    <input type="hidden" name="transaction_id" value="<?php echo $tx['id']; ?>">
                                                    <button type="submit" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all" title="Initiate Refund">
                                                        <i data-lucide="refresh-ccw" class="w-4 h-4"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center text-slate-500">No transactions found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Timeline Modal -->
        <div x-show="selectedTx" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-3xl border border-slate-200 w-full max-w-lg overflow-hidden shadow-2xl">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <div>
                        <h3 class="font-bold text-slate-900">Transaction Timeline</h3>
                        <p class="text-xs text-slate-500 font-mono mt-1" x-text="selectedTx?.reference"></p>
                    </div>
                    <button @click="selectedTx = null" class="p-2 hover:bg-slate-200 rounded-full transition-colors">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8 max-h-[60vh] overflow-y-auto">
                    <div x-show="loadingTimeline" class="flex justify-center py-12">
                        <i data-lucide="refresh-ccw" class="animate-spin text-indigo-600 w-8 h-8"></i>
                    </div>
                    <div x-show="!loadingTimeline && timeline.length > 0" class="relative space-y-8 before:absolute before:inset-0 before:ml-5 before:-translate-x-px before:h-full before:w-0.5 before:bg-gradient-to-b before:from-indigo-500 before:via-slate-200 before:to-slate-200">
                        <template x-for="event in timeline" :key="event.id">
                            <div class="relative flex items-start gap-6 group">
                                <div class="absolute left-0 w-10 h-10 rounded-full bg-white border-2 border-indigo-500 flex items-center justify-center z-10 shadow-sm">
                                    <div class="w-2 h-2 rounded-full bg-indigo-500"></div>
                                </div>
                                <div class="pl-14">
                                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1" x-text="new Date(event.created_at).toLocaleString()"></p>
                                    <h4 class="font-bold text-slate-900 capitalize" x-text="event.event_type.replace(/_/g, ' ')"></h4>
                                    <p class="text-sm text-slate-500 mt-1" x-text="event.description"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div x-show="!loadingTimeline && timeline.length === 0" class="text-center py-12">
                        <i data-lucide="file-text" class="text-slate-200 w-12 h-12 mx-auto mb-4"></i>
                        <p class="text-slate-500">No events found for this transaction.</p>
                    </div>
                </div>
                <div class="p-6 bg-slate-50 border-t border-slate-100 flex justify-end">
                    <button @click="selectedTx = null" class="px-6 py-2 bg-white border border-slate-200 rounded-xl font-bold text-slate-700 hover:bg-slate-50 transition-colors">Close</button>
                </div>
            </div>
        </div>
    </main>

    <?php include '../includes/merchant-quick-actions.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>
