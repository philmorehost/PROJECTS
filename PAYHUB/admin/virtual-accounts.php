<?php
// php-version/admin/virtual-accounts.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Virtual Accounts - Admin Hub';

$db = Database::connect();

$search = sanitize($_GET['search'] ?? '');
if ($search) {
    $stmt = $db->prepare("SELECT v.*, u.business_name, u.email as merchant_email FROM virtual_accounts v JOIN users u ON v.user_id = u.id WHERE u.business_name LIKE ? OR u.email LIKE ? OR v.account_number LIKE ? OR v.account_name LIKE ? ORDER BY v.created_at DESC");
    $stmt->execute(["%$search%", "%$search%", "%$search%", "%$search%"]);
} else {
    $stmt = $db->query("SELECT v.*, u.business_name, u.email as merchant_email FROM virtual_accounts v JOIN users u ON v.user_id = u.id ORDER BY v.created_at DESC");
}
$accounts = $stmt->fetchAll();

// Handle Admin Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    $accId = (int)$_POST['account_id'];
    $stmt = $db->prepare("DELETE FROM virtual_accounts WHERE id = ?");
    $stmt->execute([$accId]);
    $success_msg = "Virtual account record deleted.";
    $stmt = $db->query("SELECT v.*, u.business_name, u.email as merchant_email FROM virtual_accounts v JOIN users u ON v.user_id = u.id ORDER BY v.created_at DESC");
    $accounts = $stmt->fetchAll();
}

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if (isset($success_msg)): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium"><?php echo $success_msg; ?></div>
            <?php endif; ?>

            <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 mb-2">Platform Virtual Accounts</h1>
                    <p class="text-slate-500">Overview of all dedicated bank accounts generated for merchants</p>
                </div>
                <div class="w-full md:w-72">
                    <form method="GET" class="relative">
                        <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 w-4 h-4"></i>
                        <input type="text" name="search" value="<?php echo $search; ?>" placeholder="Search merchant or account..." class="w-full pl-10 pr-4 py-2.5 bg-white border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500/20 outline-none">
                    </form>
                </div>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 font-bold">Active Accounts</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Merchant</th>
                                <th class="hidden sm:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Bank</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Account Details</th>
                                <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Date</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($accounts as $a): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-4 sm:px-6 py-4">
                                        <div class="text-sm font-bold text-slate-900 truncate max-w-[120px] sm:max-w-none"><?php echo $a['business_name'] ?: 'Unknown'; ?></div>
                                        <div class="hidden sm:block text-[10px] text-slate-400"><?php echo $a['merchant_email']; ?></div>
                                    </td>
                                    <td class="hidden sm:table-cell px-6 py-4 text-sm text-slate-700"><?php echo $a['bank_name'] ?: 'N/A'; ?></td>
                                    <td class="px-4 sm:px-6 py-4">
                                        <div class="text-sm font-mono font-bold text-indigo-600"><?php echo $a['account_number'] ?: 'N/A'; ?></div>
                                        <div class="text-[10px] text-slate-500"><?php echo $a['account_name'] ?: 'N/A'; ?></div>
                                    </td>
                                    <td class="hidden md:table-cell px-6 py-4 text-xs text-slate-400"><?php echo (isset($a['created_at']) && $a['created_at']) ? date('M d, Y', strtotime($a['created_at'])) : 'Recently'; ?></td>
                                    <td class="px-4 sm:px-6 py-4 text-right">
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this record?');">
                                            <input type="hidden" name="action" value="delete_account">
                                            <input type="hidden" name="account_id" value="<?php echo $a['id']; ?>">
                                            <button type="submit" class="text-red-400 hover:text-red-600 transition-colors"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($accounts)): ?>
                                <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500 font-medium">No virtual accounts generated yet.</td></tr>
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