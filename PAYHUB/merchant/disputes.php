<?php
// php-version/merchant/disputes.php
require_once '../includes/functions.php';

if (!isLoggedIn()) redirect('../login.php');
requireCompliance();

$user = getAuthUser();
$pageTitle = 'Transaction Disputes - Payhub';

$db = Database::connect();
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_evidence') {
    $disputeId = (int)$_POST['dispute_id'];

    if (isset($_FILES['evidence']) && $_FILES['evidence']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['evidence']['name'], PATHINFO_EXTENSION);
        $filename = 'evidence_' . $disputeId . '_' . time() . '.' . $ext;
        if (!is_dir('../uploads')) mkdir('../uploads');
        move_uploaded_file($_FILES['evidence']['tmp_name'], '../uploads/' . $filename);

        $stmt = $db->prepare("UPDATE disputes SET evidence_path = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$filename, $disputeId, $user['id']]);
        $success_msg = "Evidence uploaded successfully!";
    }
}

$stmt = $db->prepare("SELECT d.*, t.reference as transaction_ref, t.amount FROM disputes d JOIN transactions t ON d.transaction_id = t.id WHERE d.user_id = ? ORDER BY d.created_at DESC");
$stmt->execute([$user['id']]);
$disputes = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data="{ mobileMenuOpen: false, showUpload: false, selectedDispute: null }">
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="max-w-6xl mx-auto">
                <?php if ($success_msg): ?>
                    <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium"><?php echo $success_msg; ?></div>
                <?php endif; ?>

                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-slate-900 mb-2">Disputes</h1>
                    <p class="text-slate-500">Manage chargebacks and provide evidence to defend your transactions</p>
                </div>

                <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-slate-100 font-bold text-slate-900 bg-slate-50/50">Active Disputes</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-100">
                                    <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Transaction</th>
                                    <th class="hidden sm:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Amount</th>
                                    <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Reason</th>
                                    <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                                    <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($disputes as $d): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="px-4 sm:px-6 py-4">
                                            <div class="text-sm font-mono font-bold text-slate-900 truncate max-w-[120px] sm:max-w-none"><?php echo $d['transaction_ref']; ?></div>
                                            <div class="sm:hidden text-[10px] font-bold text-slate-500"><?php echo formatCurrency($d['amount']); ?></div>
                                        </td>
                                        <td class="hidden sm:table-cell px-6 py-4 text-sm font-bold text-slate-900"><?php echo formatCurrency($d['amount']); ?></td>
                                        <td class="hidden md:table-cell px-6 py-4 text-sm font-medium text-slate-600"><?php echo $d['reason']; ?></td>
                                        <td class="px-4 sm:px-6 py-4">
                                            <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider <?php echo $d['status'] === 'open' ? 'bg-amber-100 text-amber-700' : ($d['status'] === 'won' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'); ?>">
                                                <?php echo $d['status']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($d['status'] === 'open'): ?>
                                                <button @click="selectedDispute = <?php echo htmlspecialchars(json_encode($d)); ?>; showUpload = true" class="text-indigo-600 hover:text-indigo-800 font-bold text-xs flex items-center gap-1">
                                                    <i data-lucide="upload" class="w-3 h-3"></i> <?php echo $d['evidence_path'] ? 'Update Evidence' : 'Add Evidence'; ?>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-slate-400 text-xs italic">Closed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($disputes)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-20 text-center">
                                            <div class="flex flex-col items-center gap-2 opacity-30">
                                                <i data-lucide="shield-alert" class="w-12 h-12"></i>
                                                <p class="font-bold">No active disputes</p>
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

        <!-- Upload Evidence Modal -->
        <div x-show="showUpload" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-md overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <h3 class="font-bold text-slate-900">Upload Evidence</h3>
                    <button @click="showUpload = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <p class="text-sm text-slate-500 mb-6">Provide proof of service delivery or product shipment for transaction <span class="font-bold text-slate-900" x-text="selectedDispute?.transaction_ref"></span></p>
                    <form method="POST" enctype="multipart/form-data" class="space-y-6">
                        <input type="hidden" name="action" value="upload_evidence">
                        <input type="hidden" name="dispute_id" :value="selectedDispute?.id">
                        <div class="p-8 border-2 border-dashed border-slate-200 rounded-3xl text-center relative hover:border-indigo-400 transition-all bg-slate-50/50">
                            <input type="file" name="evidence" required class="absolute inset-0 opacity-0 cursor-pointer">
                            <div class="space-y-2">
                                <i data-lucide="upload-cloud" class="w-10 h-10 text-slate-400 mx-auto"></i>
                                <p class="text-sm font-bold text-slate-700">Click or drag files here</p>
                                <p class="text-[10px] text-slate-400">PDF, JPG, or PNG (Max 5MB)</p>
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Submit Evidence</button>
                    </form>
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