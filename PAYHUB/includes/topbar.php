<?php
// php-version/topbar.php
require_once 'functions.php';
$user = getAuthUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_env') {
    $new_mode = (int)$_POST['is_test'];
    
    if ($new_mode === 0 && (int)$user['is_kyc_verified'] !== 1) {
        if (!headers_sent()) {
            header("Location: " . BASE_URL . "merchant/compliance.php");
            exit;
        } else {
            echo "<script>window.location.href='" . BASE_URL . "merchant/compliance.php';</script>";
            exit;
        }
    }

    $db = Database::connect();
    $stmt = $db->prepare("UPDATE users SET is_test_mode = ? WHERE id = ?");
    $stmt->execute([$new_mode, $user['id']]);
    if (!headers_sent()) {
        header("Location: " . $_SERVER['PHP_SELF'] . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    } else {
        echo "<script>window.location.href='" . $_SERVER['PHP_SELF'] . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '') . "';</script>";
        exit;
    }
}
?>
<header class="h-20 bg-white border-b border-slate-200 flex items-center justify-between px-4 sm:px-8 shrink-0">
    <div class="flex items-center gap-4">
        <button @click="$store.nav.mobileMenuOpen = true" class="md:hidden flex items-center justify-center text-slate-500 hover:text-slate-900 transition-colors p-2 rounded-lg hover:bg-slate-100 relative z-[70]">
            <i data-lucide="menu" class="w-6 h-6"></i>
        </button>
        <?php if ($user['role'] !== 'admin'): ?>
            <form method="POST" class="hidden md:flex items-center gap-3 px-3 py-1.5 bg-slate-50 border border-slate-100 rounded-2xl">
                <input type="hidden" name="action" value="toggle_env">
                <input type="hidden" name="is_test" value="<?php echo $user['is_test_mode'] ? '0' : '1'; ?>">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full <?php echo $user['is_test_mode'] ? 'bg-amber-500 animate-pulse' : 'bg-emerald-500'; ?>"></div>
                    <span class="text-[10px] font-bold text-slate-600 uppercase tracking-wider"><?php echo $user['is_test_mode'] ? 'Test Mode' : 'Live Mode'; ?></span>
                </div>
                <button type="submit" class="text-[9px] font-bold <?php echo $user['is_test_mode'] ? 'text-indigo-600 hover:text-indigo-700' : 'text-slate-400 hover:text-slate-600'; ?> uppercase tracking-widest border-l border-slate-200 pl-3">
                    Switch to <?php echo $user['is_test_mode'] ? 'Live' : 'Test'; ?>
                </button>
            </form>
        <?php else: ?>
            <?php /*
                * Admin reporting lens. Deliberately separate from the merchant switch above:
                * that one writes users.is_test_mode (which account a merchant operates in),
                * whereas this only decides which world the ADMIN is looking at. It is pure
                * reporting state, so it lives in the session and never touches the database.
                */ ?>
            <form method="POST" action="<?php echo BASE_URL; ?>admin/reporting-mode.php"
                  class="hidden md:flex items-center gap-3 px-3 py-1.5 rounded-2xl border <?php echo admin_reporting_is_test() ? 'bg-amber-50 border-amber-200' : 'bg-slate-50 border-slate-100'; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="mode" value="<?php echo admin_reporting_is_test() ? 'live' : 'test'; ?>">
                <input type="hidden" name="return" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8'); ?>">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full <?php echo admin_reporting_is_test() ? 'bg-amber-500 animate-pulse' : 'bg-emerald-500'; ?>"></div>
                    <span class="text-[10px] font-bold uppercase tracking-wider <?php echo admin_reporting_is_test() ? 'text-amber-700' : 'text-slate-600'; ?>">
                        <?php echo admin_reporting_is_test() ? 'Test Reports' : 'Live Reports'; ?>
                    </span>
                </div>
                <button type="submit" class="text-[9px] font-bold uppercase tracking-widest border-l pl-3 <?php echo admin_reporting_is_test() ? 'text-emerald-600 hover:text-emerald-700 border-amber-200' : 'text-indigo-600 hover:text-indigo-700 border-slate-200'; ?>">
                    View <?php echo admin_reporting_is_test() ? 'Live' : 'Test'; ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="flex items-center gap-6">
        <button class="text-slate-400 hover:text-slate-600 transition-colors relative">
            <i data-lucide="bell" class="w-6 h-6"></i>
            <span class="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full border-2 border-white"></span>
        </button>
        <div class="flex items-center gap-3 pl-6 border-l border-slate-100" x-data="{ open: false }">
            <div class="text-right hidden sm:block">
                <div class="relative">
                    <button @click="open = !open" class="flex items-center gap-2 text-sm font-bold text-slate-900 hover:text-indigo-600 transition-colors">
                        <?php echo $user['role'] === 'admin' ? 'System Administrator' : $user['business_name']; ?>
                        <?php if ($user['role'] === 'merchant'): ?>
                            <i data-lucide="chevron-down" class="w-3 h-3"></i>
                        <?php endif; ?>
                    </button>
                    <p class="text-[10px] text-slate-500 font-medium"><?php echo $user['email']; ?></p>

                    <?php if ($user['role'] === 'merchant'): ?>
                        <div x-show="open" @click.away="open = false" x-cloak class="absolute right-0 mt-2 w-56 bg-white rounded-2xl shadow-xl border border-slate-100 py-2 z-50">
                            <p class="px-4 py-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Switch Account</p>
                            <?php
                            $db = Database::connect();
                            $main_id = $user['parent_id'] ?: $user['id'];
                            // Show the current account first, then others
                            $stmt = $db->prepare("SELECT id, business_name, parent_id FROM users WHERE (id = ? OR parent_id = ?) ORDER BY (id = ?) DESC, (parent_id IS NULL) DESC");
                            $stmt->execute([$main_id, $main_id, $user['id']]);
                            $accounts = $stmt->fetchAll();
                            foreach ($accounts as $acc):
                            ?>
                                <form method="POST" action="<?php echo BASE_URL; ?>merchant/sub-accounts.php">
                                    <input type="hidden" name="action" value="switch_account">
                                    <input type="hidden" name="sub_id" value="<?php echo $acc['id']; ?>">
                                    <button type="submit" class="w-full text-left px-4 py-2 text-sm <?php echo $acc['id'] == $user['id'] ? 'text-indigo-600 font-bold bg-indigo-50' : 'text-slate-600 hover:bg-slate-50'; ?> transition-colors">
                                        <div class="flex items-center justify-between">
                                            <span><?php echo $acc['business_name']; ?></span>
                                            <?php if (!$acc['parent_id']): ?>
                                                <span class="text-[8px] bg-slate-100 px-1.5 py-0.5 rounded-md text-slate-500 font-bold">MAIN</span>
                                            <?php endif; ?>
                                        </div>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="w-10 h-10 <?php echo $user['role'] === 'admin' ? 'bg-slate-900' : 'bg-indigo-100'; ?> rounded-xl flex items-center justify-center <?php echo $user['role'] === 'admin' ? 'text-white' : 'text-indigo-600'; ?> font-bold">
                <?php echo strtoupper(substr($user['role'] === 'admin' ? 'Admin' : $user['business_name'], 0, 1)); ?>
            </div>
        </div>
    </div>
</header>
<?php if ($user['role'] === 'admin' && admin_reporting_is_test()): ?>
    <div class="bg-amber-500 text-white text-[10px] font-bold uppercase tracking-[0.2em] py-2 px-4 text-center shrink-0">
        Test mode reporting &mdash; the figures below are sandbox transactions only and are not part of live GTV
    </div>
<?php endif; ?>
