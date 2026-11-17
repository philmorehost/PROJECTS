<?php
// php-version/merchant/ledger.php
require_once '../includes/functions.php';

if (!isLoggedIn()) redirect('../login.php');
requireCompliance();

$user = getAuthUser();
$pageTitle = 'Balance Ledger - Payhub';

$is_test = $user['is_test_mode'];
$db = Database::connect();
$stmt = $db->prepare("SELECT * FROM ledger WHERE user_id = ? AND description " . ($is_test ? "LIKE '[TEST]%'" : "NOT LIKE '[TEST]%'") . " ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$ledger = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="max-w-6xl mx-auto">
                <div class="mb-8 flex flex-col sm:flex-row sm:justify-between sm:items-end gap-4">
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 mb-2">Balance Ledger</h1>
                        <p class="text-slate-500">Detailed history of all wallet balance movements</p>
                    </div>
                    <div class="bg-white p-4 px-6 rounded-2xl border border-slate-200 shadow-sm">
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Available Balance</p>
                        <p class="text-xl font-bold text-indigo-600"><?php echo formatCurrency($user['wallet_balance']); ?></p>
                    </div>
                </div>

                <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                        <h2 class="font-bold text-slate-900">Wallet Activities</h2>
                        <div class="flex items-center gap-2 text-xs font-bold text-slate-400 uppercase tracking-wider">
                            <i data-lucide="activity" class="w-4 h-4"></i> Real-time
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-100">
                                    <th class="px-4 sm:px-6 py-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Date & Time</th>
                                    <th class="hidden md:table-cell px-6 py-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Description</th>
                                    <th class="hidden sm:table-cell px-6 py-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Category</th>
                                    <th class="px-4 sm:px-6 py-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Amount</th>
                                    <th class="px-4 sm:px-6 py-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($ledger as $entry): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-bold text-slate-900"><?php echo date('M d, Y', strtotime($entry['created_at'])); ?></div>
                                            <div class="text-[10px] text-slate-400 font-medium"><?php echo date('h:i A', strtotime($entry['created_at'])); ?></div>
                                        </td>
                                        <td class="hidden md:table-cell px-6 py-4">
                                            <p class="text-sm text-slate-600 font-medium truncate max-w-xs"><?php echo $entry['description']; ?></p>
                                        </td>
                                        <td class="hidden sm:table-cell px-6 py-4">
                                            <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider
                                                <?php echo $entry['category'] === 'payout' ? 'bg-amber-50 text-amber-600' : ($entry['category'] === 'refund' ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600'); ?>">
                                                <?php echo $entry['category']; ?>
                                            </span>
                                        </td>
                                        <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-bold <?php echo $entry['type'] === 'credit' ? 'text-emerald-600' : 'text-red-600'; ?>">
                                                <?php echo $entry['type'] === 'credit' ? '+' : '-'; ?> <?php echo formatCurrency($entry['amount']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-right whitespace-nowrap">
                                            <span class="text-sm font-mono font-bold text-slate-900"><?php echo formatCurrency($entry['balance_after']); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($ledger)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-20 text-center">
                                            <div class="flex flex-col items-center gap-2 opacity-30">
                                                <i data-lucide="file-text" class="w-12 h-12"></i>
                                                <p class="font-bold">No transactions logged yet</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php include "../includes/merchant-quick-actions.php"; ?>
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