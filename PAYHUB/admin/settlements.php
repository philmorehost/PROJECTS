<?php
// php-version/admin/settlements.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Settlements - Admin Hub';

$db = Database::connect();

// Process payout actions with visible feedback and a CSRF-protected, pending-only transition.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_payout') {
    $payoutId = filter_var($_POST['payout_id'] ?? null, FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';
    $details = trim((string)($_POST['details'] ?? ''));

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Your session security token expired. Refresh the page and try again.';
    } elseif (!$payoutId || !in_array($status, ['processed', 'declined'], true) || $details === '') {
        $error_msg = 'Invalid payout action. Choose approve or decline and include internal notes.';
    } else {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT p.*, u.settlement_bank_code, u.settlement_account_name FROM payouts p JOIN users u ON p.user_id = u.id WHERE p.id = ? FOR UPDATE");
            $stmt->execute([$payoutId]);
            $payout = $stmt->fetch();
            if (!$payout) throw new RuntimeException('Payout request was not found. Refresh the list and try again.');
            if ($payout['status'] !== 'pending') throw new RuntimeException('This payout is no longer pending and cannot be processed again.');

            if ($status === 'processed') {
                $payoutAmount = (float)$payout['net_amount'];
                if ($payoutAmount <= 0) $payoutAmount = (float)$payout['amount']; // Legacy requests
                if (empty($payout['settlement_bank_code']) || empty($payout['account_number'])) {
                    throw new RuntimeException('The merchant settlement bank details are incomplete. Ask the merchant to update their bank account.');
                }
                $res = paystack_payout((int)$payout['user_id'], $payoutAmount, 'PayHub payout approval #' . $payoutId, [
                    'bank_name' => $payout['bank_name'],
                    'bank_code' => $payout['settlement_bank_code'],
                    'account_number' => $payout['account_number'],
                    'account_name' => $payout['settlement_account_name'] ?: $payout['bank_name']
                ]);
                if (!$res || empty($res['status'])) {
                    $gatewayMessage = $res['message'] ?? 'Paystack did not return a successful transfer response.';
                    file_put_contents(BASE_PATH . 'payout_debug.log', "[" . date('Y-m-d H:i:s') . "] Payout Approval Failed for ID $payoutId: " . json_encode($res) . PHP_EOL, FILE_APPEND | LOCK_EX);
                    throw new RuntimeException('Paystack transfer failed: ' . $gatewayMessage);
                }
                $details .= ' | Paystack transfer: ' . ($res['data']['transfer_code'] ?? $res['data']['reference'] ?? 'initiated');
                $stmt = $db->prepare("UPDATE payouts SET status = 'processed', status_details = ? WHERE id = ? AND status = 'pending'");
                $stmt->execute([$details, $payoutId]);
            } else {
                $stmt = $db->prepare("UPDATE payouts SET status = 'declined', status_details = ? WHERE id = ? AND status = 'pending'");
                $stmt->execute([$details, $payoutId]);
                // The original request debited `amount` (amount + fee); return that full hold.
                $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
                $stmt->execute([$payout['amount'], $payout['user_id']]);
                $stmt = $db->prepare("INSERT INTO ledger (user_id, amount, type, category, description, balance_after) SELECT id, ?, 'credit', 'payout_refund', ?, wallet_balance FROM users WHERE id = ?");
                $stmt->execute([$payout['amount'], 'Declined payout refund #' . $payoutId, $payout['user_id']]);
            }
            $db->commit();
            $success_msg = $status === 'processed' ? 'Payout approved and sent to Paystack.' : 'Payout declined and the held balance was returned to the merchant.';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error_msg = 'Failed to process payout: ' . $e->getMessage();
        }
    }
}

$stmt = $db->query("SELECT p.*, u.business_name FROM payouts p JOIN users u ON p.user_id = u.id ORDER BY p.request_date DESC");
$payouts = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden" x-data="{ showProcess: false, payout: {} }">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if (!empty($success_msg)): ?>
                <div role="status" class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                    <?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_msg)): ?>
                <div role="alert" class="mb-6 p-4 bg-rose-50 text-rose-700 rounded-2xl border border-rose-200 font-medium">
                    <?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-900 mb-2">Settlement Requests</h1>
                <p class="text-slate-500">Review and process payout requests from merchants</p>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 font-bold flex justify-between items-center">
                    <span>Pending Settlements</span>
                    <span class="text-xs font-normal text-slate-500"><?php echo count(array_filter($payouts, fn($p) => $p['status'] === 'pending')); ?> requests pending review</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50/50">
                                    <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Merchant</th>
                                    <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Bank Details</th>
                                    <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Amount</th>
                                    <th class="hidden sm:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                                    <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($payouts as $p): ?>
                                <tr class="hover:bg-slate-50/30 transition-colors">
                                        <td class="px-4 sm:px-6 py-4">
                                            <div class="font-bold text-slate-900 truncate max-w-[120px] sm:max-w-none"><?php echo $p['business_name']; ?></div>
                                        <div class="text-xs text-slate-500"><?php echo date('M d, Y', strtotime($p['request_date'])); ?></div>
                                    </td>
                                        <td class="hidden md:table-cell px-6 py-4">
                                        <div class="text-sm font-bold text-slate-900"><?php echo $p['bank_name']; ?></div>
                                        <div class="text-xs text-slate-500 font-mono"><?php echo $p['account_number']; ?></div>
                                    </td>
                                        <td class="px-4 sm:px-6 py-4">
                                        <div class="text-sm font-bold text-slate-900"><?php echo formatCurrency($p['amount']); ?></div>
                                    </td>
                                        <td class="hidden sm:table-cell px-6 py-4">
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $p['status'] === 'processed' ? 'bg-emerald-100 text-emerald-700' : ($p['status'] === 'declined' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'); ?>">
                                            <?php echo $p['status']; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if ($p['status'] === 'pending'): ?>
                                            <button @click="showProcess = true; payout = <?php echo htmlspecialchars(json_encode($p)); ?>" class="bg-indigo-600 text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-indigo-700 transition-colors shadow-sm shadow-indigo-100">Process</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Process Modal -->
        <div x-show="showProcess" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-sm overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <h3 class="font-bold text-slate-900">Process Payout</h3>
                    <button @click="showProcess = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <p class="text-sm font-medium text-slate-500 mb-6">Process payout of <span class="text-indigo-600 font-bold" x-text="'₦'+payout.amount"></span> to <span class="text-slate-900 font-bold" x-text="payout.business_name"></span></p>
                    <form method="POST">
                        <input type="hidden" name="action" value="process_payout">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="payout_id" :value="payout.id">
                        <div class="mb-4">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Internal Status Details</label>
                            <textarea name="details" required placeholder="Transaction reference, receipt ID, or reason for decline..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none h-24 mb-4"></textarea>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" name="status" value="processed" class="flex-1 bg-emerald-600 text-white py-3 rounded-xl font-bold">Approve</button>
                            <button type="submit" name="status" value="declined" class="flex-1 bg-red-50 text-red-600 border border-red-200 py-3 rounded-xl font-bold">Decline</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
<script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>