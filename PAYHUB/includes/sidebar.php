<?php
// php-version/includes/sidebar.php
require_once __DIR__ . '/functions.php';
$user = getAuthUser();
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'merchant';
?>
<!-- Mobile Overlay -->
<div x-show="$store.nav.mobileMenuOpen"
     x-transition:enter="transition-opacity ease-linear duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition-opacity ease-linear duration-300"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @click="$store.nav.mobileMenuOpen = false"
     class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 md:hidden" x-cloak></div>

<aside
    :class="$store.nav.mobileMenuOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
    class="fixed md:static inset-y-0 left-0 w-64 bg-white border-r border-slate-200 flex flex-col z-[60] transition-transform duration-300 shrink-0"
>
    <div class="p-6 border-b border-slate-100 flex items-center justify-between">
        <a href="<?php echo BASE_URL; ?>index.php" class="flex items-center gap-2">
            <?php $logo = getConfig('site_logo'); ?>
            <?php if ($logo): ?>
                <img src="<?php echo BASE_URL; ?>uploads/<?php echo $logo; ?>" alt="Logo" class="h-10 object-contain">
            <?php else: ?>
                <div class="w-10 h-10 bg-indigo-600 rounded-lg flex items-center justify-center">
                    <i data-lucide="credit-card" class="text-white w-6 h-6"></i>
                </div>
            <?php endif; ?>
            <span class="text-xl font-bold tracking-tight text-slate-900"><?php echo getConfig('site_name', 'Payhub'); ?></span>
        </a>
        <button @click="$store.nav.mobileMenuOpen = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
    </div>
    
    <nav class="flex-1 p-4 space-y-1 overflow-y-auto">
        <?php if ($role === 'admin'): ?>
            <div class="px-3 mb-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Operations</div>
            <a href="<?php echo BASE_URL; ?>admin/index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'index.php' && strpos($_SERVER['REQUEST_URI'], '/admin/') !== false ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                Overview
            </a>
            <?php /* Was not linked from anywhere, so the full transaction history - the place to
                     check whether merchants are running tests - was only reachable by typing the
                     URL. Scoped to the reporting lens, so in test mode it lists test data only. */ ?>
            <a href="<?php echo BASE_URL; ?>admin/transactions.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'transactions.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="receipt" class="w-5 h-5"></i>
                Transactions
                <?php if (admin_reporting_is_test()): ?>
                    <span class="ml-auto text-[8px] font-bold bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-md">TEST</span>
                <?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/merchants.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'merchants.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="users" class="w-5 h-5"></i>
                Merchants
            </a>
            <a href="<?php echo BASE_URL; ?>admin/compliance.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'compliance.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="shield-check" class="w-5 h-5"></i>
                Compliance
            </a>
            <a href="<?php echo BASE_URL; ?>admin/settlements.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'settlements.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="credit-card" class="w-5 h-5"></i>
                Settlements
            </a>
            <a href="<?php echo BASE_URL; ?>admin/disputes.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'disputes.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="gavel" class="w-5 h-5"></i>
                Disputes
            </a>
            <a href="<?php echo BASE_URL; ?>admin/virtual-accounts.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'virtual-accounts.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="wallet" class="w-5 h-5"></i>
                Virtual Accounts
            </a>

            <div class="pt-4 pb-2 px-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">System</div>
            <a href="<?php echo BASE_URL; ?>admin/health.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'health.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="activity" class="w-5 h-5"></i>
                System Health
            </a>
            <a href="<?php echo BASE_URL; ?>admin/webhooks.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'webhooks.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="webhook" class="w-5 h-5"></i>
                Webhook Logs
            </a>
            <a href="<?php echo BASE_URL; ?>admin/api-manager.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo ($current_page === 'api-manager.php' || $current_page === 'api-logs.php') ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="key" class="w-5 h-5"></i>
                API Manager
            </a>
            <a href="<?php echo BASE_URL; ?>admin/cron-manager.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'cron-manager.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="clock" class="w-5 h-5"></i>
                Cron Manager
            </a>

            <div class="pt-4 pb-2 px-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Content & Support</div>
            <a href="<?php echo BASE_URL; ?>admin/tickets.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'tickets.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="ticket" class="w-5 h-5"></i>
                Tickets
            </a>
            <a href="<?php echo BASE_URL; ?>admin/blog.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'blog.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="file-text" class="w-5 h-5"></i>
                Blog Manager
            </a>
            <a href="<?php echo BASE_URL; ?>admin/email-marketing.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'email-marketing.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="mail" class="w-5 h-5"></i>
                Email Marketing
            </a>

            <div class="pt-4 pb-2 px-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Configuration</div>
            <a href="<?php echo BASE_URL; ?>admin/staff.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'staff.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="users" class="w-5 h-5"></i>
                Staff Management
            </a>
            <a href="<?php echo BASE_URL; ?>admin/email-settings.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'email-settings.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="bell" class="w-5 h-5"></i>
                Email Settings
            </a>
            <a href="<?php echo BASE_URL; ?>admin/config.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'config.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="settings" class="w-5 h-5"></i>
                Platform Config
            </a>
            <a href="<?php echo BASE_URL; ?>admin/security.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'security.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="shield-check" class="w-5 h-5"></i>
                Security
            </a>
        <?php else: ?>
            <?php $is_verified = (int)($user['is_kyc_verified'] ?? 0) === 1; ?>
            <div class="px-3 mb-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center justify-between">
                <span>Main</span>
                <?php if (!$is_verified): ?>
                    <span class="text-[9px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full flex items-center gap-1">
                        <i data-lucide="lock" class="w-3 h-3"></i> Locked
                    </span>
                <?php endif; ?>
            </div>
            <a href="<?php echo BASE_URL; ?>merchant/dashboard.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'dashboard.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                    Dashboard
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>merchant/compliance.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'compliance.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="shield-alert" class="w-5 h-5"></i>
                    Compliance
                </div>
                <?php if (!$is_verified): ?>
                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                <?php endif; ?>
            </a>

            <div class="pt-4 pb-2 px-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Collections</div>
            <a href="<?php echo BASE_URL; ?>merchant/transactions.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'transactions.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="arrow-up-right" class="w-5 h-5"></i>
                    Transactions
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>merchant/invoices.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'invoices.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="file-text" class="w-5 h-5"></i>
                    Invoices
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>merchant/subscriptions.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'subscriptions.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="refresh-ccw" class="w-5 h-5"></i>
                    Subscriptions
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>

            <div class="pt-4 pb-2 px-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Finance</div>
            <a href="<?php echo BASE_URL; ?>merchant/payouts.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'payouts.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="arrow-down-left" class="w-5 h-5"></i>
                    Payouts
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>merchant/ledger.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'ledger.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="activity" class="w-5 h-5"></i>
                    Balance Ledger
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>merchant/disputes.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'disputes.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                    Disputes
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>

            <div class="pt-4 pb-2 px-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Management</div>
            <a href="<?php echo BASE_URL; ?>merchant/customers.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'customers.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="users" class="w-5 h-5"></i>
                    Customers
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>merchant/tickets.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'tickets.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <i data-lucide="ticket" class="w-5 h-5"></i>
                Support
            </a>
            <a href="<?php echo BASE_URL; ?>merchant/sub-accounts.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'sub-accounts.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="layers" class="w-5 h-5"></i>
                    Sub-accounts
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>

            <div class="pt-4 pb-2 px-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Developer</div>
            <a href="<?php echo BASE_URL; ?>merchant/api-keys.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'api-keys.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="code" class="w-5 h-5"></i>
                    API Keys
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>merchant/reconcile.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'reconcile.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="refresh-cw" class="w-5 h-5"></i>
                    Reconcile
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>merchant/settings.php" class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-bold transition-all <?php echo $current_page === 'settings.php' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'; ?>">
                <div class="flex items-center gap-3">
                    <i data-lucide="settings" class="w-5 h-5"></i>
                    Settings
                </div>
                <?php if (!$is_verified): ?><i data-lucide="lock" class="w-4 h-4 text-slate-400"></i><?php endif; ?>
            </a>
        <?php endif; ?>
    </nav>

    <?php if (isset($_SESSION['admin_user_id'])): ?>
        <div class="p-4 border-t border-indigo-100 bg-indigo-50">
            <form method="POST" action="<?php echo BASE_URL; ?>admin/merchants.php">
                <input type="hidden" name="action" value="exit_impersonation">
                <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold text-indigo-700 hover:bg-indigo-100 transition-all">
                    <i data-lucide="shield-off" class="w-5 h-5"></i>
                    Exit Impersonation
                </button>
            </form>
        </div>
    <?php endif; ?>

    <div class="p-4 border-t border-slate-100">
        <a href="<?php echo BASE_URL; ?>logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold text-red-500 hover:bg-red-50 transition-all">
            <i data-lucide="log-out" class="w-5 h-5"></i>
            Logout
        </a>
    </div>
</aside>
