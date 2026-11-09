<?php
// php-version/admin/disputes.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Dispute Adjudication - Admin Hub';

$db = Database::connect();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'adjudicate') {
        $disputeId = (int)$_POST['dispute_id'];
        $status = sanitize($_POST['status']);
        $stmt = $db->prepare("UPDATE disputes SET status = ? WHERE id = ?");
        $stmt->execute([$status, $disputeId]);
        $success_msg = "Dispute marked as $status.";
    }
}

$stmt = $db->query("SELECT d.*, t.reference as transaction_ref, t.amount, u.business_name FROM disputes d JOIN transactions t ON d.transaction_id = t.id JOIN users u ON d.user_id = u.id WHERE 1=1" . admin_tx_lens_sql('t') . " ORDER BY d.created_at DESC");
$disputes = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if (isset($success_msg)): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-900 mb-2">Dispute Management</h1>
                <p class="text-slate-500">Adjudicate transaction disputes between merchants and customers</p>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 font-bold">All Platform Disputes</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Merchant</th>
                                <th class="hidden sm:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Transaction</th>
                                <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Reason</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Status</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($disputes as $d): ?>
                                <tr class="hover:bg-slate-50/30 transition-colors">
                                    <td class="px-4 sm:px-6 py-4 text-sm font-bold text-slate-900 truncate max-w-[120px] sm:max-w-none"><?php echo $d['business_name']; ?></td>
                                    <td class="hidden sm:table-cell px-6 py-4">
                                        <div class="text-xs font-mono text-slate-600"><?php echo $d['transaction_ref']; ?></div>
                                        <div class="text-xs font-bold text-slate-900"><?php echo formatCurrency($d['amount']); ?></div>
                                    </td>
                                    <td class="hidden md:table-cell px-6 py-4 text-sm text-slate-600"><?php echo $d['reason']; ?></td>
                                    <td class="px-4 sm:px-6 py-4">
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $d['status'] === 'won' ? 'bg-emerald-100 text-emerald-700' : ($d['status'] === 'lost' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'); ?>">
                                            <?php echo $d['status']; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if ($d['status'] === 'open'): ?>
                                            <div class="flex gap-2">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="adjudicate">
                                                    <input type="hidden" name="dispute_id" value="<?php echo $d['id']; ?>">
                                                    <button type="submit" name="status" value="won" class="text-emerald-600 hover:underline text-xs font-bold">Mark Won</button>
                                                </form>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="adjudicate">
                                                    <input type="hidden" name="dispute_id" value="<?php echo $d['id']; ?>">
                                                    <button type="submit" name="status" value="lost" class="text-red-600 hover:underline text-xs font-bold">Mark Lost</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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