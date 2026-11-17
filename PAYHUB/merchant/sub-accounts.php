<?php
// php-version/merchant/sub-accounts.php
require_once '../includes/functions.php';

if (!isLoggedIn()) redirect('../login.php');
requireCompliance();

$user = getAuthUser();
$pageTitle = 'Sub-accounts - Payhub';

$db = Database::connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_subaccount' && $user['parent_id'] === null) {
        $email = sanitize($_POST['email']);
        $biz_name = sanitize($_POST['business_name']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        $pk = generateApiKey('pk_live_');
        $sk = generateApiKey('sk_live_');

        try {
            $stmt = $db->prepare("INSERT INTO users (email, business_name, password_hash, public_key, secret_key, parent_id, role) VALUES (?, ?, ?, ?, ?, ?, 'merchant')");
            $stmt->execute([$email, $biz_name, $password, $pk, $sk, $user['id']]);
            $success_msg = "Sub-account created successfully! It must complete compliance before going live.";
        } catch (Exception $e) {
            $error_msg = "Creation failed: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'switch_account') {
        $targetId = (int)$_POST['sub_id'];
        $mainId = $user['parent_id'] ?: $user['id'];

        // Security check: is it the main account or a sub-account of the same main account?
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND (id = ? OR parent_id = ?)");
        $stmt->execute([$targetId, $mainId, $mainId]);
        if ($stmt->fetch()) {
            $_SESSION['user_id'] = $targetId;
            redirect('dashboard.php');
        }
    }
}

$mainId = $user['parent_id'] ?: $user['id'];
$stmt = $db->prepare("SELECT * FROM users WHERE (id = ? OR parent_id = ?) AND id != ?");
$stmt->execute([$mainId, $mainId, $user['id']]);
$otherAccounts = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden" x-data="{ showAdd: false }">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 mb-2">Accounts & Branches</h1>
                    <p class="text-slate-500">Manage and switch between your different business units</p>
                </div>
                <?php if ($user['parent_id'] === null): ?>
                <button @click="showAdd = true" class="bg-indigo-600 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">
                    <i data-lucide="plus" class="w-5 h-5"></i> Create Sub-account
                </button>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($otherAccounts as $acc): ?>
                    <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm hover:border-indigo-600 transition-all group">
                        <div class="flex justify-between items-start mb-6">
                            <div class="w-12 h-12 bg-slate-50 rounded-2xl flex items-center justify-center text-slate-400 group-hover:bg-indigo-50 group-hover:text-indigo-600 transition-all">
                                <i data-lucide="building" class="w-6 h-6"></i>
                            </div>
                            <div class="flex flex-col items-end gap-2">
                                <?php if ($acc['parent_id'] === null): ?>
                                    <span class="px-2 py-0.5 bg-slate-100 text-slate-600 rounded-lg text-[10px] font-bold uppercase tracking-wider">Main Account</span>
                                <?php endif; ?>
                                <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider <?php echo $acc['is_kyc_verified'] == 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
                                    <?php echo $acc['is_kyc_verified'] == 1 ? 'Verified' : 'Unverified'; ?>
                                </span>
                            </div>
                        </div>
                        <h3 class="font-bold text-xl text-slate-900 mb-1"><?php echo $acc['business_name']; ?></h3>
                        <p class="text-sm text-slate-400 mb-8"><?php echo $acc['email']; ?></p>
                        <form method="POST">
                            <input type="hidden" name="action" value="switch_account">
                            <input type="hidden" name="sub_id" value="<?php echo $acc['id']; ?>">
                            <button type="submit" class="w-full py-3 bg-slate-50 text-slate-600 font-bold rounded-xl hover:bg-indigo-600 hover:text-white transition-all text-sm">Switch to Account</button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($otherAccounts) && $user['parent_id'] !== null): ?>
                    <!-- Should not happen if data is consistent, but for safety -->
                <?php elseif (empty($otherAccounts)): ?>
                    <div class="md:col-span-2 lg:col-span-3 text-center py-20 bg-white rounded-[2rem] border border-dashed border-slate-300">
                        <i data-lucide="layers" class="w-12 h-12 text-slate-200 mx-auto mb-4"></i>
                        <p class="text-slate-500 font-medium">You haven't created any sub-accounts yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add Modal -->
        <div x-show="showAdd" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-md overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <h3 class="font-bold text-slate-900">New Sub-account</h3>
                    <button @click="showAdd = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="create_subaccount">
                        <input type="text" name="business_name" required placeholder="Business Name" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        <input type="email" name="email" required placeholder="Contact Email" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        <input type="password" name="password" required placeholder="Account Password" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Create Account</button>
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