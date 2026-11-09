<?php
// php-version/merchant/api-keys.php
require_once '../includes/functions.php';

if (!isLoggedIn()) redirect('../login.php');
requireCompliance();

$user = getAuthUser();
$pageTitle = 'API Keys - Payhub';

$db = Database::connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'regenerate') {
    $pk = generateApiKey('pk_live_');
    $sk = generateApiKey('sk_live_');
    $tpk = generateApiKey('pk_test_');
    $tsk = generateApiKey('sk_test_');
    $stmt = $db->prepare("UPDATE users SET public_key = ?, secret_key = ?, test_public_key = ?, test_secret_key = ? WHERE id = ?");
    $stmt->execute([$pk, $sk, $tpk, $tsk, $user['id']]);
    $success_msg = "API keys regenerated successfully!";
    $user = getAuthUser();
}

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="max-w-4xl mx-auto">
                <div class="mb-8">
                    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 mb-2">API Keys & Webhooks</h1>
                    <p class="text-slate-500">Access your Public and Secret keys to integrate Payhub into your applications</p>
                </div>

                <?php if (isset($success_msg)): ?>
                    <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium"><?php echo $success_msg; ?></div>
                <?php endif; ?>

                <div class="grid lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2 space-y-6">
                        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                            <div class="space-y-8">
                                <?php if ($user['is_kyc_verified'] == 1): ?>
                                    <div class="p-6 bg-slate-50 rounded-2xl border border-slate-100">
                                        <div class="flex justify-between items-center mb-4">
                                            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Live Public Key</p>
                                            <span class="text-[10px] bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded font-bold uppercase">Live</span>
                                        </div>
                                        <div class="flex gap-2">
                                            <input readonly value="<?php echo $user['public_key']; ?>" class="flex-1 px-4 py-3 bg-white border border-slate-200 rounded-xl font-mono text-sm focus:outline-none">
                                            <button onclick="navigator.clipboard.writeText('<?php echo $user['public_key']; ?>'); alert('Copied!');" class="px-6 py-3 bg-white border border-slate-200 rounded-xl text-sm font-bold hover:bg-slate-50 transition-all active:scale-95">Copy</button>
                                        </div>
                                    </div>

                                    <div class="p-6 bg-slate-50 rounded-2xl border border-slate-100">
                                        <div class="flex justify-between items-center mb-4">
                                            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Live Secret Key</p>
                                            <span class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded font-bold uppercase">Private</span>
                                        </div>
                                        <div class="flex gap-2">
                                            <input type="password" readonly value="<?php echo $user['secret_key']; ?>" class="flex-1 px-4 py-3 bg-white border border-slate-200 rounded-xl font-mono text-sm focus:outline-none">
                                            <button onclick="navigator.clipboard.writeText('<?php echo $user['secret_key']; ?>'); alert('Copied!');" class="px-6 py-3 bg-white border border-slate-200 rounded-xl text-sm font-bold hover:bg-slate-50 transition-all active:scale-95">Copy</button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="p-6 bg-amber-50 rounded-2xl border border-amber-100 mb-8">
                                        <div class="flex gap-3">
                                            <i data-lucide="lock" class="text-amber-600"></i>
                                            <div>
                                                <p class="text-sm font-bold text-amber-900">Live API access is restricted</p>
                                                <p class="text-xs text-amber-700 mt-1">Complete your compliance review to unlock live payments. You can use Test Keys for integration testing.</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="p-6 bg-slate-50 rounded-2xl border border-slate-100">
                                    <div class="flex justify-between items-center mb-4">
                                        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Test Public Key</p>
                                        <span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded font-bold uppercase">Test</span>
                                    </div>
                                    <div class="flex gap-2">
                                        <input readonly value="<?php echo $user['test_public_key']; ?>" class="flex-1 px-4 py-3 bg-white border border-slate-200 rounded-xl font-mono text-sm focus:outline-none">
                                        <button onclick="navigator.clipboard.writeText('<?php echo $user['test_public_key']; ?>'); alert('Copied!');" class="px-6 py-3 bg-white border border-slate-200 rounded-xl text-sm font-bold hover:bg-slate-50 transition-all active:scale-95">Copy</button>
                                    </div>
                                </div>

                                <div class="p-6 bg-slate-50 rounded-2xl border border-slate-100">
                                    <div class="flex justify-between items-center mb-4">
                                        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">Test Secret Key</p>
                                        <span class="text-[10px] bg-slate-200 text-slate-700 px-2 py-0.5 rounded font-bold uppercase">Test</span>
                                    </div>
                                    <div class="flex gap-2">
                                        <input type="password" readonly value="<?php echo $user['test_secret_key']; ?>" class="flex-1 px-4 py-3 bg-white border border-slate-200 rounded-xl font-mono text-sm focus:outline-none">
                                        <button onclick="navigator.clipboard.writeText('<?php echo $user['test_secret_key']; ?>'); alert('Copied!');" class="px-6 py-3 bg-white border border-slate-200 rounded-xl text-sm font-bold hover:bg-slate-50 transition-all active:scale-95">Copy</button>
                                    </div>
                                </div>

                                <div class="pt-6 border-t border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
                                    <div>
                                        <h4 class="font-bold text-slate-900 text-sm">Danger Zone</h4>
                                        <p class="text-xs text-slate-400 mt-1">Regenerating keys will break existing integrations.</p>
                                    </div>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to regenerate your keys? This will immediately break any existing integrations using the current keys.');">
                                        <input type="hidden" name="action" value="regenerate">
                                        <button type="submit" class="bg-red-50 text-red-600 px-6 py-3 rounded-xl font-bold text-sm hover:bg-red-100 transition-all">Regenerate Keys</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lg:col-span-1 space-y-6">
                        <div class="bg-indigo-600 p-8 rounded-[2rem] text-white shadow-xl shadow-indigo-200">
                            <i data-lucide="book-open" size="32" class="mb-4 opacity-80"></i>
                            <h3 class="font-bold mb-2">Integration Guide</h3>
                            <p class="text-sm text-indigo-100 mb-6">Read our documentation to learn how to accept payments using these keys.</p>
                            <a href="../docs.php" class="inline-block bg-white/20 hover:bg-white/30 px-6 py-3 rounded-xl text-sm font-bold transition-all">View Docs</a>
                        </div>
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