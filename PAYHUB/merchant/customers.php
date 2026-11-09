<?php
// php-version/merchant/customers.php
require_once '../includes/functions.php';

if (!isLoggedIn()) redirect('../login.php');
requireCompliance();

$user = getAuthUser();
$pageTitle = 'Customer Directory - Payhub';

$db = Database::connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_customer') {
        $email = sanitize($_POST['email']);
        $name = sanitize($_POST['full_name']);
        $phone = sanitize($_POST['phone']);

        try {
            $stmt = $db->prepare("INSERT INTO customers (user_id, full_name, email, phone) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone)");
            $stmt->execute([$user['id'], $name, $email, $phone]);

            // Attempt to generate Virtual Account immediately
            $va_res = ensure_virtual_account($user['id'], $email, [
                'full_name' => $name,
                'phone' => $phone
            ]);

            if ($va_res['status']) {
                $success_msg = "Customer added and Virtual Account generated!";
            } else {
                $success_msg = "Customer added but VA could not be generated: " . $va_res['message'];
            }
        } catch (Exception $e) {
            $error_msg = "Error: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'sync_customers') {
        $res = paystack_call('customer', 'GET');
        if ($res && $res['status']) {
            $synced = 0;
            foreach ($res['data'] as $c) {
                $merchant_id = $c['metadata']['merchant_id'] ?? null;
                if ($merchant_id == $user['id']) {
                    $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                    if (empty($name)) $name = 'Paystack Customer';
                    $stmt = $db->prepare("INSERT INTO customers (user_id, full_name, email, phone) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone)");
                    $stmt->execute([$user['id'], $name, $c['email'], $c['phone'] ?? '']);
                    $synced++;
                }
            }
            $success_msg = "Synced $synced customers from Paystack.";
        } else {
            $error_msg = "Failed to sync: " . ($res['message'] ?? 'Unknown error');
        }
    } elseif ($_POST['action'] === 'generate_va') {
        $email = sanitize($_POST['email']);
        $res = ensure_virtual_account($user['id'], $email);
        if ($res['status']) {
            $success_msg = "Virtual Account generated: " . $res['data']['account_number'];
        } else {
            $error_msg = "Error: " . $res['message'];
        }
    } elseif ($_POST['action'] === 'delete_customer') {
        $id = (int)$_POST['customer_id'];
        $stmt = $db->prepare("DELETE FROM customers WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user['id']]);
        $success_msg = "Customer removed successfully.";
    } elseif ($_POST['action'] === 'edit_customer') {
        $id = (int)$_POST['customer_id'];
        $name = sanitize($_POST['full_name']);
        $phone = sanitize($_POST['phone']);
        $stmt = $db->prepare("UPDATE customers SET full_name = ?, phone = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$name, $phone, $id, $user['id']]);
        $success_msg = "Customer updated successfully.";
    }
}

$is_test = $user['is_test_mode'];
$stmt = $db->prepare("
    SELECT c.*, va.bank_name, va.account_number, va.account_name
    FROM customers c
    LEFT JOIN virtual_accounts va ON c.email = va.customer_email AND c.user_id = va.user_id
    WHERE c.user_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$user['id']]);
$customers = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data="{ showAdd: false }">
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="max-w-6xl mx-auto">
                <?php if (isset($success_msg)): ?>
                    <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium"><?php echo $success_msg; ?></div>
                <?php endif; ?>
                <?php if (isset($error_msg)): ?>
                    <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-2xl border border-red-100 font-medium"><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h1 class="text-3xl font-bold text-slate-900 mb-2">Customers</h1>
                        <p class="text-slate-500">A centralized database of everyone who has paid you</p>
                    </div>
                    <div class="flex gap-3">
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="sync_customers">
                            <button type="submit" class="bg-white border border-slate-200 text-slate-700 px-6 py-3 rounded-xl font-bold flex items-center gap-2 hover:bg-slate-50 transition-all shadow-sm">
                                <i data-lucide="refresh-cw" class="w-5 h-5"></i> Sync from Paystack
                            </button>
                        </form>
                        <button @click="showAdd = true" class="bg-indigo-600 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">
                            <i data-lucide="plus" class="w-5 h-5"></i> Add Customer
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                        <h3 class="font-bold text-slate-900">All Customers</h3>
                        <div class="relative w-64">
                            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 w-4 h-4"></i>
                            <input type="text" placeholder="Search customers..." class="w-full pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-100">
                                    <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Customer Details</th>
                                    <th class="hidden sm:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Virtual Account</th>
                                    <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Phone Number</th>
                                    <th class="hidden lg:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Date Joined</th>
                                    <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($customers as $c): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="px-4 sm:px-6 py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-10 h-10 bg-slate-100 rounded-full flex items-center justify-center text-slate-500 font-bold text-xs uppercase shrink-0">
                                                    <?php echo substr($c['full_name'], 0, 2); ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <div class="font-bold text-slate-900 truncate"><?php echo $c['full_name']; ?></div>
                                                    <div class="text-[10px] text-slate-400 font-medium truncate"><?php echo $c['email']; ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="hidden sm:table-cell px-6 py-4">
                                            <?php if ($is_test): ?>
                                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest bg-slate-100 px-2 py-1 rounded-lg">Live Only</span>
                                            <?php elseif ($c['account_number']): ?>
                                                <div class="text-sm font-bold text-slate-900"><?php echo $c['account_number']; ?></div>
                                                <div class="text-[10px] text-slate-400 font-bold uppercase"><?php echo $c['bank_name']; ?></div>
                                            <?php else: ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="generate_va">
                                                    <input type="hidden" name="email" value="<?php echo $c['email']; ?>">
                                                    <button type="submit" class="text-[10px] font-bold text-indigo-600 hover:text-indigo-700 uppercase tracking-widest border border-indigo-100 px-2 py-1 rounded-lg bg-indigo-50/50">Generate VA</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                        <td class="hidden md:table-cell px-6 py-4 text-sm font-mono text-slate-600"><?php echo $c['phone']; ?></td>
                                        <td class="hidden lg:table-cell px-6 py-4 text-sm font-medium text-slate-500"><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                                        <td class="px-4 sm:px-6 py-4">
                                            <div class="flex items-center gap-2" x-data="{ editing: false, fullName: '<?php echo addslashes($c['full_name']); ?>', phone: '<?php echo addslashes($c['phone']); ?>' }">
                                                <button @click="editing = true" class="p-2 text-slate-400 hover:text-indigo-600 transition-colors"><i data-lucide="edit-3" class="w-4 h-4"></i></button>
                                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to remove this customer?');">
                                                    <input type="hidden" name="action" value="delete_customer">
                                                    <input type="hidden" name="customer_id" value="<?php echo $c['id']; ?>">
                                                    <button type="submit" class="p-2 text-slate-400 hover:text-red-600 transition-colors"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                                                </form>

                                                <!-- Edit Modal -->
                                                <div x-show="editing" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
                                                    <div class="bg-white rounded-[2rem] w-full max-w-md overflow-hidden shadow-2xl border border-slate-200">
                                                        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                                                            <h3 class="font-bold text-slate-900">Edit Customer</h3>
                                                            <button @click="editing = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                                                                <i data-lucide="x" class="w-5 h-5"></i>
                                                            </button>
                                                        </div>
                                                        <div class="p-8">
                                                            <form method="POST" class="space-y-4 text-left">
                                                                <input type="hidden" name="action" value="edit_customer">
                                                                <input type="hidden" name="customer_id" value="<?php echo $c['id']; ?>">
                                                                <div>
                                                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Full Name</label>
                                                                    <input type="text" name="full_name" x-model="fullName" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                                                </div>
                                                                <div>
                                                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Phone Number</label>
                                                                    <input type="tel" name="phone" x-model="phone" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                                                </div>
                                                                <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100 mt-4">Save Changes</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($customers)): ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-20 text-center">
                                            <div class="flex flex-col items-center gap-2 opacity-30">
                                                <i data-lucide="users" class="w-12 h-12"></i>
                                                <p class="font-bold">No customers found</p>
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
    <!-- Add Customer Modal -->
    <div x-show="showAdd" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
        <div class="bg-white rounded-[2rem] w-full max-w-md overflow-hidden shadow-2xl border border-slate-200">
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="font-bold text-slate-900">Add New Customer</h3>
                <button @click="showAdd = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="p-8">
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_customer">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Full Name</label>
                        <input type="text" name="full_name" required placeholder="John Doe" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Email Address</label>
                        <input type="email" name="email" required placeholder="john@example.com" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Phone Number</label>
                        <input type="tel" name="phone" required placeholder="08012345678" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100 mt-4">Save Customer</button>
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