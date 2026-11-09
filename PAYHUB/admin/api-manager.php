<?php
// php-version/admin/api-manager.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'API Manager - Admin Hub';

$db = Database::connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_keys') {
    $keys = [
        'paystack_public_key', 'paystack_secret_key',
        'paystack_test_public_key', 'paystack_test_secret_key'
    ];
    foreach ($keys as $key) {
        $val = sanitize($_POST[$key] ?? '');
        $stmt = $db->prepare("INSERT INTO config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
        $stmt->execute([$key, $val, $val]);
    }
    $success_msg = "Paystack integration keys updated.";
}

$pk = getConfig('paystack_public_key');
$sk = getConfig('paystack_secret_key');
$tpk = getConfig('paystack_test_public_key');
$tsk = getConfig('paystack_test_secret_key');

// Fetch API Logs
$logs = [];
try {
    $logs = $db->query("SELECT * FROM api_logs ORDER BY created_at DESC LIMIT 50")->fetchAll();
} catch (\Throwable $e) {
    error_log("API Manager Log Fetch Error: " . $e->getMessage());
}

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
                <h1 class="text-2xl font-bold text-slate-900 mb-2">API Manager</h1>
                <p class="text-slate-500">The Paystack API is integrated as the source for the PayHub Payment Gateway</p>
            </div>

            <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm max-w-2xl">
                <div class="flex items-center gap-3 mb-8">
                    <div class="p-3 bg-indigo-50 rounded-2xl text-indigo-600">
                        <i data-lucide="key" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold">Gateway Configuration</h2>
                        <p class="text-sm text-slate-500">Configure your Paystack credentials for platform-wide processing</p>
                    </div>
                </div>

                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="update_keys">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Live Public Key</label>
                            <input type="text" name="paystack_public_key" value="<?php echo $pk; ?>" placeholder="pk_live_..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none font-mono text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Live Secret Key</label>
                            <input type="password" name="paystack_secret_key" value="<?php echo $sk; ?>" placeholder="sk_live_..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none font-mono text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Test Public Key</label>
                            <input type="text" name="paystack_test_public_key" value="<?php echo $tpk; ?>" placeholder="pk_test_..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none font-mono text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Test Secret Key</label>
                            <input type="password" name="paystack_test_secret_key" value="<?php echo $tsk; ?>" placeholder="sk_test_..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none font-mono text-sm">
                        </div>
                    </div>

                    <div class="p-4 bg-amber-50 rounded-2xl border border-amber-100 flex items-start gap-3">
                        <i data-lucide="shield-alert" class="text-amber-500 w-5 h-5 shrink-0"></i>
                        <p class="text-xs text-amber-700 leading-relaxed">
                            <strong>Security Warning:</strong> These keys are extremely sensitive. They allow full access to your Paystack account funds and data. Never share them or expose them in client-side code.
                        </p>
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Update Integration</button>
                </form>
            </div>

            <div class="mt-12 bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden mb-12">
                <div class="p-8 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-lg text-slate-900">Outgoing Paystack API Logs</h3>
                        <p class="text-sm text-slate-500">Real-time monitoring of platform communication with Paystack</p>
                    </div>
                    <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-full text-xs font-bold">
                        <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
                        Live Monitoring
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50/50">
                                <th class="px-4 sm:px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Time</th>
                                <th class="px-4 sm:px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Method & Endpoint</th>
                                <th class="hidden sm:table-cell px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Status</th>
                                <th class="hidden md:table-cell px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Payload</th>
                                <th class="hidden lg:table-cell px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Response</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($logs as $l): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-4 sm:px-8 py-4 text-xs text-slate-500 whitespace-nowrap"><?php echo date('H:i:s d M', strtotime($l['created_at'])); ?></td>
                                    <td class="px-4 sm:px-8 py-4">
                                        <div class="flex items-center gap-2">
                                            <span class="px-2 py-0.5 rounded bg-slate-900 text-white text-[9px] font-bold"><?php echo $l['method']; ?></span>
                                            <span class="text-xs font-mono text-slate-600 truncate max-w-[80px] sm:max-w-none"><?php echo $l['endpoint']; ?></span>
                                        </div>
                                    </td>
                                    <td class="hidden sm:table-cell px-8 py-4">
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold <?php echo $l['status_code'] < 300 ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600'; ?>">
                                            <?php echo $l['status_code']; ?>
                                        </span>
                                    </td>
                                    <td class="px-8 py-4">
                                        <div class="max-w-[150px] truncate text-[10px] font-mono text-slate-400" title='<?php echo htmlspecialchars($l['payload']); ?>'>
                                            <?php echo htmlspecialchars($l['payload']); ?>
                                        </div>
                                    </td>
                                    <td class="px-8 py-4">
                                        <div class="max-w-[200px] truncate text-[10px] font-mono text-slate-400" title='<?php echo htmlspecialchars($l['response']); ?>'>
                                            <?php echo htmlspecialchars($l['response']); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" class="px-8 py-12 text-center text-slate-500">No API logs found.</td>
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