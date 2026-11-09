<?php
// php-version/admin/webhooks.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Webhook Logs - Admin Hub';

$db = Database::connect();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'retry') {
    $logId = (int)$_POST['log_id'];
    // In a real app, this would trigger a background job
    $stmt = $db->prepare("UPDATE webhook_logs SET attempt_count = attempt_count + 1, last_attempt_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$logId]);
    $success_msg = "Webhook retry triggered for Log #$logId.";
}

$stmt = $db->query("SELECT w.*, t.reference as transaction_ref FROM webhook_logs w JOIN transactions t ON w.transaction_id = t.id WHERE 1=1" . admin_tx_lens_sql('t') . " ORDER BY w.created_at DESC");
$logs = $stmt->fetchAll();

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
                <h1 class="text-2xl font-bold text-slate-900 mb-2">Webhook Logs</h1>
                <p class="text-slate-500">A detailed log of every notification sent to merchant servers</p>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 font-bold">Recent Deliveries</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Transaction</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Status</th>
                                <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Attempts</th>
                                <th class="hidden lg:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Last Attempt</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($logs as $l): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-4 sm:px-6 py-4 text-xs font-mono font-bold"><?php echo $l['transaction_ref']; ?></td>
                                    <td class="px-4 sm:px-6 py-4">
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $l['status'] === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'; ?>">
                                            <?php echo $l['status']; ?>
                                        </span>
                                    </td>
                                    <td class="hidden md:table-cell px-6 py-4 text-sm"><?php echo $l['attempt_count']; ?></td>
                                    <td class="hidden lg:table-cell px-6 py-4 text-xs text-slate-500 font-medium"><?php echo $l['last_attempt_at'] ? date('M d, H:i', strtotime($l['last_attempt_at'])) : 'Never'; ?></td>
                                    <td class="px-4 sm:px-6 py-4">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="retry">
                                            <input type="hidden" name="log_id" value="<?php echo $l['id']; ?>">
                                            <button type="submit" class="text-indigo-600 hover:text-indigo-800" title="Retry Delivery">
                                                <i data-lucide="refresh-ccw" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-slate-500">No webhook logs found.</td>
                                </tr>
                            <?php endif; ?>
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