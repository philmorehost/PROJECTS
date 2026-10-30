<?php
// Billing & Addon Management View (Invoice Logs, Addon Toggles, Payment Gateways)
if (!defined('PORTAL_PATH')) exit;

if ($_SESSION['user_role'] === 'teacher') {
    $stmtSec = $pdo->prepare("SELECT is_sub_admin FROM sm_teachers WHERE email = ? AND school_id_number = ? LIMIT 1");
    $stmtSec->execute([$_SESSION['user_session'], $school_id]);
    if ((int)$stmtSec->fetchColumn() === 1) {
        http_response_code(403);
        echo "<div style='padding:20px; color:red; font-family:sans-serif; text-align:center;'>⛔ Access Denied. As a teacher designated as a Sub-Admin, you are not authorized to access Billing & Addons.</div>";
        exit;
    }
}

$msg = '';
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Handle submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['renew_subscription'])) {
        $package_id = (int)$_POST['package_id'];
        $payment_method = $_POST['payment_method'];
        
        $stmtPkg = $pdo->prepare("SELECT * FROM sm_subscription_packages WHERE id = ?");
        $stmtPkg->execute([$package_id]);
        $pkg = $stmtPkg->fetch();
        
        if ($pkg) {
            $price = (float)$pkg['price'];
            $ref = 'SUB-' . strtoupper(bin2hex(random_bytes(6)));
            $now = date('Y-m-d H:i:s');
            
            $cycle = $pkg['billing_cycle'];
            if ($cycle === 'monthly') {
                $new_expiry = date('Y-m-d H:i:s', strtotime('+1 month'));
            } elseif ($cycle === 'lifetime') {
                $new_expiry = date('Y-m-d H:i:s', strtotime('+50 years'));
            } else {
                $new_expiry = date('Y-m-d H:i:s', strtotime('+1 year'));
            }

            if ($payment_method === 'online') {
                $stmt = $pdo->prepare("UPDATE sm_schools SET package_id = ?, subscription_status = 'active', status = 'active', subscription_expires_at = ?, subscription_expiry_warned = 0, subscription_suspended_notified = 0 WHERE id = ?");
                $stmt->execute([$package_id, $new_expiry, $school_id]);
                
                $stmtPurchase = $pdo->prepare("INSERT INTO sm_addon_purchases (school_id_number, addon_name, amount_paid, payment_method, transaction_ref, status, purchase_date) VALUES (?, ?, ?, ?, ?, 'approved', ?)");
                $stmtPurchase->execute([$school_id, 'Subscription: ' . $pkg['name'], $price, 'Online Card', $ref, $now]);
                
                $msg = "Subscription '{$pkg['name']}' renewed successfully! Portal unsuspended.";
                
                header("Refresh: 2; URL=dashboard.php");
            } else {
                $stmtPurchase = $pdo->prepare("INSERT INTO sm_addon_purchases (school_id_number, addon_name, amount_paid, payment_method, transaction_ref, status, purchase_date) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
                $stmtPurchase->execute([$school_id, 'Subscription: ' . $pkg['name'], $price, 'Bank Transfer', $ref, $now]);
                
                $msg = "Subscription renewal request submitted! Reference: {$ref}. Please wait for Super Admin approval.";
            }
        }
    }
    if (isset($_POST['save_gateways'])) {
        $pk = trim($_POST['paystack_public']);
        $sk = trim($_POST['paystack_secret']);
        $fp = trim($_POST['flutterwave_public']);
        $fs = trim($_POST['flutterwave_secret']);
        $bank = trim($_POST['bank_name']);
        $acc = trim($_POST['account_number']);
        $name = trim($_POST['account_name']);

        // Check if row exists
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM sm_payment_settings WHERE school_id_number = ?");
        $stmtCheck->execute([$school_id]);
        if ($stmtCheck->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO sm_payment_settings (school_id_number, paystack_public, paystack_secret, flutterwave_public, flutterwave_secret, bank_name, account_number, account_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $pk, $sk, $fp, $fs, $bank, $acc, $name]);
        } else {
            $stmt = $pdo->prepare("UPDATE sm_payment_settings SET paystack_public = ?, paystack_secret = ?, flutterwave_public = ?, flutterwave_secret = ?, bank_name = ?, account_number = ?, account_name = ? WHERE school_id_number = ?");
            $stmt->execute([$pk, $sk, $fp, $fs, $bank, $acc, $name, $school_id]);
        }
        $msg = "Gateway credentials updated successfully!";
    } elseif (isset($_POST['save_sms_settings'])) {
        $sms_acc = trim($_POST['sms_account_name']);
        $sms_key = trim($_POST['sms_api_key']);

        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM sm_sms_settings WHERE school_id_number = ?");
        $stmtCheck->execute([$school_id]);
        if ($stmtCheck->fetchColumn() == 0) {
            $defaultPrice = defined('SMS_PRICE_PER_CREDIT') ? (float)SMS_PRICE_PER_CREDIT : 5.00;
            $stmt = $pdo->prepare("INSERT INTO sm_sms_settings (school_id_number, account_name, api_key, price_per_sms) VALUES (?, ?, ?, ?)");
            $stmt->execute([$school_id, $sms_acc, $sms_key, $defaultPrice]);
        } else {
            $stmt = $pdo->prepare("UPDATE sm_sms_settings SET account_name = ?, api_key = ? WHERE school_id_number = ?");
            $stmt->execute([$sms_acc, $sms_key, $school_id]);
        }
        $msg = "SMS configurations updated successfully!";
    } elseif (isset($_POST['recharge_sms'])) {
        $credits = (int)$_POST['credits'];
        if ($credits <= 0) {
            $msg = "⚠️ Error: Please enter a valid number of credits.";
        } else {
            // Get current price per SMS credit
            $stmtPrice = $pdo->prepare("SELECT price_per_sms FROM sm_sms_settings WHERE school_id_number = ?");
            $stmtPrice->execute([$school_id]);
            $price_per_sms = $stmtPrice->fetchColumn();
            if ($price_per_sms === false || $price_per_sms === null) {
                $price_per_sms = defined('SMS_PRICE_PER_CREDIT') ? (float)SMS_PRICE_PER_CREDIT : 5.00;
            }

            $amount = $credits * $price_per_sms;
            $ref = 'SMS-' . strtoupper(bin2hex(random_bytes(6)));
            $payment_method = $_POST['payment_method'];

            if ($payment_method === 'online') {
                // Instantly credit school wallet balance to simulate online checkouts
                $stmtPurchase = $pdo->prepare("INSERT INTO sm_sms_purchases (school_id_number, amount_paid, credits_purchased, transaction_ref, status) VALUES (?, ?, ?, ?, 'approved')");
                $stmtPurchase->execute([$school_id, $amount, $credits, $ref]);

                $stmtCredit = $pdo->prepare("UPDATE sm_schools SET wallet_balance = wallet_balance + ? WHERE id = ?");
                $stmtCredit->execute([$credits, $school_id]);

                $msg = "Successfully recharged {$credits} SMS credits via Checkout Gateway! Wallet credited.";
            } else {
                // Bank transfer requires Super Admin approval
                $stmtPurchase = $pdo->prepare("INSERT INTO sm_sms_purchases (school_id_number, amount_paid, credits_purchased, transaction_ref, status) VALUES (?, ?, ?, ?, 'pending')");
                $stmtPurchase->execute([$school_id, $amount, $credits, $ref]);

                $msg = "SMS Recharge request for {$credits} credits submitted! Reference: {$ref}. Please wait for administrator approval.";
            }
        }
    } elseif (isset($_POST['buy_addon'])) {
        $addon = trim($_POST['addon_name']);
        $payment_method = $_POST['payment_method'];
        $price = AddonHelper::getAddonPrice($pdo, $addon);
        
        $ref = 'ADDON-' . strtoupper(bin2hex(random_bytes(6)));
        $now = date('Y-m-d H:i:s');

        if ($payment_method === 'online') {
            // Simulated online card checkout: instantly approve and activate
            $stmtPurchase = $pdo->prepare("INSERT INTO sm_addon_purchases (school_id_number, addon_name, amount_paid, payment_method, transaction_ref, status, purchase_date) VALUES (?, ?, ?, ?, ?, 'approved', ?)");
            $stmtPurchase->execute([$school_id, $addon, $price, 'Online Card', $ref, $now]);

            AddonHelper::toggleAddon($pdo, $school_id, $addon, true);
            $msg = "Addon '{$addon}' purchased successfully! It has been activated.";
        } else {
            // Manual bank transfer: set to pending and wait for Super Admin
            $stmtPurchase = $pdo->prepare("INSERT INTO sm_addon_purchases (school_id_number, addon_name, amount_paid, payment_method, transaction_ref, status, purchase_date) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
            $stmtPurchase->execute([$school_id, $addon, $price, 'Bank Transfer', $ref, $now]);

            $msg = "Addon activation request for '{$addon}' submitted! Ref: {$ref}. Please wait for Super Admin approval.";
        }
        // Refresh local cache
        $addons = AddonHelper::getSchoolAddonSummary($pdo, $school_id);
    } elseif (isset($_POST['toggle_addon'])) {
        $addon = trim($_POST['addon_name']);
        // Verify they actually have a valid purchase history of this addon before allowing toggling!
        $purchaseStatus = AddonHelper::getAddonPurchaseStatus($pdo, $school_id, $addon);
        if ($purchaseStatus === 'approved') {
            $status = $_POST['status'] === 'active' ? 'active' : 'inactive';
            AddonHelper::toggleAddon($pdo, $school_id, $addon, $status === 'active');
            $msg = "Addon module '{$addon}' visibility updated!";
        } else {
            $msg = "⚠️ Error: You must purchase the '{$addon}' addon module before activating it.";
        }
        // Refresh local cache
        $addons = AddonHelper::getSchoolAddonSummary($pdo, $school_id);
    }
}

// Check authorization
$can_view_billing_history = false;
$is_admin = ($user_role === 'school_admin' || $user_role === 'super_admin');

if ($is_admin) {
    $can_view_billing_history = true;
} elseif ($user_role === 'teacher') {
    // Check if this teacher is allowed to view payment history
    $stmtTCheck = $pdo->prepare("SELECT can_view_payments FROM sm_teachers WHERE email = ? AND school_id_number = ? LIMIT 1");
    $stmtTCheck->execute([$_SESSION['user_email'] ?? '', $school_id]);
    if ($stmtTCheck->fetchColumn() == 1) {
        $can_view_billing_history = true;
    }
} elseif ($user_role === 'student' || $user_role === 'parent') {
    // Students and parents can see their own invoice history
    $can_view_billing_history = true;
}

// Block access to administrative billing tabs for non-admin roles
if (!$is_admin && in_array($action, ['gateways', 'recharge', 'addons'])) {
    echo '<div class="p-8 text-center text-red-400">⛔ Access Denied. You do not have permission to access billing configuration.</div>';
    return;
}

if (!$can_view_billing_history) {
    echo '<div class="p-8 text-center text-red-400">⛔ Access Denied. You are not authorized to view student payments or billing history.</div>';
    return;
}

$stmtSubSch = $pdo->prepare("SELECT status FROM sm_schools WHERE id = ?");
$stmtSubSch->execute([$school_id]);
$subSchStatus = $stmtSubSch->fetchColumn();
$isSuspended = ($subSchStatus === 'inactive');

// Fetch gateway info
$stmtPay = $pdo->prepare("SELECT * FROM sm_payment_settings WHERE school_id_number = ?");
$stmtPay->execute([$school_id]);
$pay_settings = $stmtPay->fetch();

// Fetch SMS info
$stmtSms = $pdo->prepare("SELECT * FROM sm_sms_settings WHERE school_id_number = ?");
$stmtSms->execute([$school_id]);
$sms_settings = $stmtSms->fetch();

// Fetch search and filter parameters
$search_billing = isset($_GET['search_billing']) ? trim($_GET['search_billing']) : '';
$filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';
$filter_session = isset($_GET['filter_session']) ? trim($_GET['filter_session']) : '';
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';

$school_classes = [];
$school_sessions = [];
if ($is_admin || $user_role === 'teacher') {
    $school_classes = $pdo->prepare("SELECT numeric_class_name, class_name FROM sm_classes WHERE school_id_number = ? ORDER BY class_name");
    $school_classes->execute([$school_id]);
    $school_classes = $school_classes->fetchAll();

    $school_sessions = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number = ? ORDER BY id DESC");
    $school_sessions->execute([$school_id]);
    $school_sessions = $school_sessions->fetchAll();
}

$billing_clauses = ["school_id_number = ?"];
$billing_params = [$school_id];

if ($filter_class !== '') {
    $billing_clauses[] = "numeric_class_name = ?";
    $billing_params[] = $filter_class;
}
if ($filter_session !== '') {
    $billing_clauses[] = "session = ?";
    $billing_params[] = $filter_session;
}
if ($filter_status !== '') {
    $billing_clauses[] = "status = ?";
    $billing_params[] = $filter_status;
}
if ($search_billing !== '') {
    $billing_clauses[] = "(admission_number LIKE ? OR online_ref LIKE ? OR manual_ref LIKE ?)";
    $search_param = '%' . $search_billing . '%';
    $billing_params[] = $search_param;
    $billing_params[] = $search_param;
    $billing_params[] = $search_param;
}

$billing_where = implode(' AND ', $billing_clauses);

// Fetch invoices based on user role context
if ($is_admin || $user_role === 'teacher') {
    $stmtInv = $pdo->prepare("SELECT * FROM sm_fee_payment_lists WHERE $billing_where ORDER BY fee_payment_id DESC LIMIT 50");
    $stmtInv->execute($billing_params);
} elseif ($user_role === 'student') {
    // Get current student's admission number from email/session
    $stmtStudentInfo = $pdo->prepare("SELECT admission_number FROM sm_students WHERE email = ? AND school_id_number = ? LIMIT 1");
    $stmtStudentInfo->execute([$_SESSION['user_email'] ?? '', $school_id]);
    $student_adm = $stmtStudentInfo->fetchColumn() ?: '';
    
    $billing_clauses[] = "admission_number = ?";
    $billing_params[] = $student_adm;
    $billing_where = implode(' AND ', $billing_clauses);
    
    $stmtInv = $pdo->prepare("SELECT * FROM sm_fee_payment_lists WHERE $billing_where ORDER BY fee_payment_id DESC LIMIT 50");
    $stmtInv->execute($billing_params);
} elseif ($user_role === 'parent') {
    // Resolve all children under this parent
    $stmtParentInfo = $pdo->prepare("SELECT id_number FROM sm_parents WHERE email = ? AND school_id_number = ? LIMIT 1");
    $stmtParentInfo->execute([$_SESSION['user_email'] ?? '', $school_id]);
    $parent_id = $stmtParentInfo->fetchColumn() ?: '';
    
    $stmtChildren = $pdo->prepare("SELECT admission_number FROM sm_students WHERE parent_id_number = ? AND school_id_number = ?");
    $stmtChildren->execute([$parent_id, $school_id]);
    $children_adms = $stmtChildren->fetchAll(PDO::FETCH_COLUMN) ?: [];
    
    if (!empty($children_adms)) {
        $placeholders = implode(',', array_fill(0, count($children_adms), '?'));
        $billing_clauses[] = "admission_number IN ($placeholders)";
        $billing_where = implode(' AND ', $billing_clauses);
        
        $stmtInv = $pdo->prepare("SELECT * FROM sm_fee_payment_lists WHERE $billing_where ORDER BY fee_payment_id DESC LIMIT 50");
        $stmtInv->execute(array_merge($billing_params, $children_adms));
    } else {
        // No children, return empty results
        $stmtInv = $pdo->prepare("SELECT * FROM sm_fee_payment_lists WHERE 1=0");
        $stmtInv->execute();
    }
}
$invoices = $stmtInv->fetchAll();

// Fetch SMS recharge transactions history
$stmtSmsPurchases = $pdo->prepare("SELECT * FROM sm_sms_purchases WHERE school_id_number = ? ORDER BY id DESC LIMIT 20");
$stmtSmsPurchases->execute([$school_id]);
$sms_purchases = $stmtSmsPurchases->fetchAll();
?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <h2 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">💳 Invoices & Fees</h2>
        <div class="flex overflow-x-auto gap-2 w-full md:w-auto p-1 scrollbar-thin">
            <?php if (!$isSuspended): ?>
                <?php if ($is_admin): ?>
                    <a href="?page=billing&action=list" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-white/5 border border-white/10 rounded-lg hover:bg-white/10 transition text-sm">Dashboard Overview</a>
                    <a href="?page=billing&action=renew" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-700 hover:to-orange-700 text-white rounded-lg transition text-sm font-semibold shadow-md">Renew Subscription</a>
                    <a href="?page=billing&action=gateways" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition text-sm font-semibold">Gateway Credentials</a>
                    <a href="?page=billing&action=recharge" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition text-sm font-semibold">Recharge SMS</a>
                    <a href="?page=billing&action=addons" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition text-sm font-semibold">Active Addons</a>
                <?php endif; ?>
            <?php else: ?>
                <span class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-red-500/10 border border-red-500/20 text-red-400 rounded-lg text-sm font-bold">⚠️ Subscription Expired</span>
                <?php if ($is_admin): ?>
                    <a href="?page=billing&action=renew" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-700 hover:to-orange-700 text-white rounded-lg transition text-sm font-semibold shadow-md">Renew Now</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg) : ?>
        <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-xl text-sm">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list') : ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <?php if ($is_admin): ?>
                <!-- Payment settings preview -->
                <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                    <h3 class="text-lg font-semibold mb-4 text-blue-300">Active Bank Credentials</h3>
                    <?php if (empty($pay_settings['bank_name'])) : ?>
                        <p class="text-gray-400 text-sm">No payout settings configured. Set up bank transfer options in the Gateway Credentials tab.</p>
                    <?php else : ?>
                        <div class="p-4 bg-white/5 rounded-xl border border-white/5 space-y-2 text-sm text-gray-300">
                            <p>🏦 Bank: <strong class="text-white"><?php echo htmlspecialchars($pay_settings['bank_name']); ?></strong></p>
                            <p>🔢 Account Number: <strong class="text-white"><?php echo htmlspecialchars($pay_settings['account_number']); ?></strong></p>
                            <p>👤 Account Name: <strong class="text-white"><?php echo htmlspecialchars($pay_settings['account_name']); ?></strong></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Invoices lists -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md <?php echo $is_admin ? 'lg:col-span-2' : 'lg:col-span-3'; ?>">
                <h3 class="text-lg font-semibold mb-4 text-indigo-300">Recent Payment Invoices</h3>
                <?php if ($is_admin || $user_role === 'teacher'): ?>
                    <!-- Search & Filters -->
                    <form method="get" class="grid grid-cols-1 sm:grid-cols-5 gap-3 mb-6">
                        <input type="hidden" name="page" value="billing" />
                        <input type="hidden" name="action" value="list" />
                        <div>
                            <input type="text" name="search_billing" placeholder="🔍 Admission/Ref..." value="<?php echo htmlspecialchars($search_billing); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                        </div>
                        <div>
                            <select name="filter_class" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 text-xs">
                                <option value="">All Classes</option>
                                <?php foreach ($school_classes as $cls) : ?>
                                    <option value="<?php echo htmlspecialchars($cls['numeric_class_name']); ?>" <?php echo $filter_class === $cls['numeric_class_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cls['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="filter_session" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 text-xs">
                                <option value="">All Sessions</option>
                                <?php foreach ($school_sessions as $sess) : ?>
                                    <option value="<?php echo htmlspecialchars($sess['session_name']); ?>" <?php echo $filter_session === $sess['session_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sess['session_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="filter_status" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 text-xs">
                                <option value="">All Statuses</option>
                                <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="partial" <?php echo $filter_status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                <option value="unpaid" <?php echo $filter_status === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                            </select>
                        </div>
                        <div class="flex gap-1">
                            <button type="submit" class="flex-1 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold transition">Filter</button>
                            <a href="?page=billing&action=list" class="px-2 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-xs transition flex items-center justify-center">Reset</a>
                        </div>
                    </form>
                <?php endif; ?>
                <?php if (empty($invoices)) : ?>
                    <p class="text-gray-400 text-sm">No invoices recorded yet.</p>
                <?php else : ?>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[650px] text-left text-sm text-gray-300">
                            <thead>
                                <tr class="border-b border-white/10 text-gray-400">
                                    <th class="py-2.5">Ref</th>
                                    <th class="py-2.5">Student</th>
                                    <th class="py-2.5">Amount</th>
                                    <th class="py-2.5">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoices as $inv) : ?>
                                    <tr class="border-b border-white/5 hover:bg-white/5">
                                        <td class="py-3 font-semibold text-white"><?php echo htmlspecialchars($inv['online_ref'] ?: $inv['manual_ref']); ?></td>
                                        <td class="py-3"><?php echo htmlspecialchars($inv['admission_number']); ?></td>
                                        <td class="py-3"><?php echo htmlspecialchars(number_format($inv['amount_paid'], 2)); ?></td>
                                        <td class="py-3">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php echo $inv['status'] === 'paid' ? 'bg-green-500/10 text-green-400 border border-green-500/10' : 'bg-yellow-500/10 text-yellow-400 border border-yellow-500/10'; ?>">
                                                <?php echo htmlspecialchars($inv['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($action === 'gateways') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-6 text-blue-300">Update Checkout Gateways & Payouts</h3>
            <form method="post" class="space-y-4">
                <div class="border-b border-white/10 pb-4 mb-4">
                    <h4 class="font-bold text-sm text-indigo-400 mb-3">Paystack Credentials</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Public Key</label>
                            <input type="text" name="paystack_public" value="<?php echo htmlspecialchars($pay_settings['paystack_public'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Secret Key</label>
                            <input type="password" name="paystack_secret" value="<?php echo htmlspecialchars($pay_settings['paystack_secret'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                        </div>
                    </div>
                </div>

                <div class="border-b border-white/10 pb-4 mb-4">
                    <h4 class="font-bold text-sm text-indigo-400 mb-3">Flutterwave Credentials</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Public Key</label>
                            <input type="text" name="flutterwave_public" value="<?php echo htmlspecialchars($pay_settings['flutterwave_public'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Secret Key</label>
                            <input type="password" name="flutterwave_secret" value="<?php echo htmlspecialchars($pay_settings['flutterwave_secret'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                        </div>
                    </div>
                </div>

                <div>
                    <h4 class="font-bold text-sm text-indigo-400 mb-3">Manual Bank Transfer Details</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Bank Name</label>
                            <input type="text" name="bank_name" value="<?php echo htmlspecialchars($pay_settings['bank_name'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Account No.</label>
                            <input type="text" name="account_number" value="<?php echo htmlspecialchars($pay_settings['account_number'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Account Name</label>
                            <input type="text" name="account_name" value="<?php echo htmlspecialchars($pay_settings['account_name'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                        </div>
                    </div>
                </div>

                <button type="submit" name="save_gateways" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                    Save Gateway Credentials
                </button>
            </form>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto mt-6">
            <h3 class="text-lg font-semibold mb-4 text-indigo-300">📱 SMS Gateway Status</h3>
            <div class="space-y-4 text-sm text-gray-300">
                <div class="p-4 bg-white/5 border border-white/5 rounded-xl space-y-2">
                    <p>Gateway Connection: <span class="text-green-400 font-bold">Active (Centrally Managed)</span></p>
                    <p>Sender ID Routing: <span class="text-indigo-400 font-bold">Assigned via Communications Portal</span></p>
                </div>
                <div class="p-4 bg-white/5 border border-white/5 rounded-xl space-y-2">
                    <p class="text-xs text-gray-400">Price Per SMS Credit (₦):</p>
                    <p class="text-lg font-bold text-white">₦<?php echo number_format((float)($sms_settings['price_per_sms'] ?? (defined('SMS_PRICE_PER_CREDIT') ? SMS_PRICE_PER_CREDIT : 5.00)), 2); ?></p>
                    <p class="text-[10px] text-gray-400">This rate is set and managed by the platform administrator.</p>
                </div>
            </div>
        </div>
        </div>

    <?php elseif ($action === 'recharge') : 
        $sms_price = isset($sms_settings['price_per_sms']) ? (float)$sms_settings['price_per_sms'] : (defined('SMS_PRICE_PER_CREDIT') ? (float)SMS_PRICE_PER_CREDIT : 5.00);
        
        // Fetch current school wallet balance
        $stmtW = $pdo->prepare("SELECT wallet_balance FROM sm_schools WHERE id = ?");
        $stmtW->execute([$school_id]);
        $wallet_bal = $stmtW->fetchColumn() ?: 0;
    ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Purchase Form -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h3 class="text-lg font-semibold mb-4 text-green-300">Buy SMS Credits</h3>
                
                <div class="p-4 bg-white/5 border border-white/5 rounded-xl mb-4 text-xs space-y-2">
                    <p class="text-gray-300">Current SMS Wallet Balance: <strong class="text-white text-base block mt-0.5"><?php echo number_format($wallet_bal); ?> Credits</strong></p>
                    <p class="text-gray-300">Rate: <strong class="text-green-400">₦<?php echo number_format($sms_price, 2); ?></strong> per credit</p>
                </div>

                <form method="post" class="space-y-4">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Number of Credits to Purchase</label>
                        <input type="number" name="credits" id="recharge_credits" required min="1" placeholder="e.g. 500" oninput="calculateCost(this.value)" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-green-500 text-sm" />
                    </div>
                    
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Payment Method</label>
                        <select name="payment_method" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-green-500 text-sm">
                            <option value="online">Debit Card (Online Instant Checkout)</option>
                            <option value="bank_transfer">Manual Bank Transfer / Deposit</option>
                        </select>
                    </div>

                    <div class="p-4 bg-green-500/10 border border-green-500/20 rounded-xl flex justify-between items-center text-sm font-semibold">
                        <span class="text-gray-300">Total Price:</span>
                        <span id="calculated_cost" class="text-green-400 text-lg">₦0.00</span>
                    </div>

                    <button type="submit" name="recharge_sms" class="w-full py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg font-semibold transition text-sm">
                        Confirm Purchase & Pay
                    </button>
                </form>
            </div>

            <!-- Transaction Logs -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md lg:col-span-2">
                <h3 class="text-lg font-semibold mb-4 text-indigo-300">SMS Credit Recharge History</h3>
                
                <?php if (empty($sms_purchases)) : ?>
                    <p class="text-gray-400 text-sm">No transaction requests logged yet.</p>
                <?php else : ?>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[650px] text-left text-xs text-gray-300">
                            <thead>
                                <tr class="border-b border-white/10 text-gray-400 uppercase tracking-wide">
                                    <th class="py-2.5">Reference</th>
                                    <th class="py-2.5 text-center">Credits</th>
                                    <th class="py-2.5">Amount</th>
                                    <th class="py-2.5">Status</th>
                                    <th class="py-2.5">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sms_purchases as $pur) : ?>
                                    <tr class="border-b border-white/5 hover:bg-white/5">
                                        <td class="py-3 font-semibold text-white font-mono"><?php echo htmlspecialchars($pur['transaction_ref']); ?></td>
                                        <td class="py-3 text-center font-bold text-blue-300"><?php echo number_format($pur['credits_purchased']); ?></td>
                                        <td class="py-3">₦<?php echo number_format($pur['amount_paid'], 2); ?></td>
                                        <td class="py-3">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php 
                                                if ($pur['status'] === 'approved') echo 'bg-green-500/10 text-green-400 border border-green-500/10';
                                                elseif ($pur['status'] === 'pending') echo 'bg-yellow-500/10 text-yellow-400 border border-yellow-500/10';
                                                else echo 'bg-red-500/10 text-red-400 border border-red-500/10';
                                            ?>">
                                                <?php echo htmlspecialchars($pur['status']); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 text-gray-400"><?php echo htmlspecialchars($pur['purchase_date']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        function calculateCost(credits) {
            const price = <?php echo $sms_price; ?>;
            const cost = (parseInt(credits) || 0) * price;
            document.getElementById('calculated_cost').innerText = '₦' + cost.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
        </script>

    <?php elseif ($action === 'addons') : ?>
        <div class="max-w-4xl mx-auto space-y-6">
            <div class="text-center max-w-xl mx-auto mb-8">
                <h3 class="text-2xl font-bold bg-gradient-to-r from-indigo-400 to-purple-400 bg-clip-text text-transparent">🧩 Addon Modules Marketplace</h3>
                <p class="text-xs text-gray-400 mt-1">Unlock premium administrative, testing, and LMS academic features. Instantly scale your portal capabilities.</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($addons as $name => $active) : 
                    $price = AddonHelper::getAddonPrice($pdo, $name);
                    $purchaseStatus = AddonHelper::getAddonPurchaseStatus($pdo, $school_id, $name);
                    
                    // Icons and summaries for addons
                    $addonMeta = [
                        'CBT' => ['💻', 'Conduct online tests and computer-based examinations with auto-grading.'],
                        'Bulk Report Card' => ['🗂️', 'Compile, calculate grades, and batch-print student report sheets.'],
                        'Data Cleanup' => ['🧹', 'Reset system databases, wipe demo records, or run audit logs.'],
                        'ID Card' => ['🪪', 'Design and generate digital badges and printable ID cards for students & staff.'],
                        'Live Classes' => ['🎥', 'Stream virtual learning rooms, schedule video links, and integrate LMS curriculum.'],
                        'SMS' => ['📱', 'Broadcast instant SMS alerts, payment notices, and academic reminders to parents.'],
                        'Mobile App' => ['📱', 'Production Android APK and iOS app builds synced to your school database.']
                    ];
                    
                    $meta = $addonMeta[$name] ?? ['🧩', 'Premium addon module.'];
                ?>
                    <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md flex flex-col justify-between hover:border-white/20 transition-all duration-300">
                        <div class="space-y-4">
                            <div class="flex justify-between items-start">
                                <span class="text-4xl"><?php echo $meta[0]; ?></span>
                                <?php if ($purchaseStatus === 'approved'): ?>
                                    <span class="px-2.5 py-0.5 bg-green-500/10 text-green-400 border border-green-500/20 rounded-full text-[10px] font-bold uppercase tracking-wider flex items-center gap-1">
                                        ✨ Active / Purchased
                                    </span>
                                <?php elseif ($purchaseStatus === 'pending'): ?>
                                    <span class="px-2.5 py-0.5 bg-amber-500/10 text-amber-400 border border-amber-500/20 rounded-full text-[10px] font-bold uppercase tracking-wider flex items-center gap-1">
                                        ⏳ Pending Approval
                                    </span>
                                <?php else: ?>
                                    <span class="text-lg font-extrabold text-green-400 tracking-wide block">
                                        ₦<?php echo number_format($price, 2); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div>
                                <h4 class="text-lg font-bold text-white"><?php echo htmlspecialchars($name); ?> Addon</h4>
                                <p class="text-xs text-gray-400 mt-1.5 leading-relaxed"><?php echo htmlspecialchars($meta[1]); ?></p>
                            </div>
                        </div>
                        
                        <div class="mt-6 pt-4 border-t border-white/5 flex items-center justify-between">
                            <span class="text-xs text-gray-500">
                                Status: <strong class="<?php echo $active ? 'text-green-400' : 'text-gray-400'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></strong>
                            </span>
                            
                            <?php if ($active && $purchaseStatus !== 'approved'): ?>
                                <button disabled class="px-4 py-2 bg-green-500/10 border border-green-500/20 text-green-400 rounded-xl text-xs font-semibold cursor-not-allowed">
                                    Included in Package
                                </button>
                            <?php elseif ($purchaseStatus === 'approved'): ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="addon_name" value="<?php echo htmlspecialchars($name); ?>" />
                                    <input type="hidden" name="status" value="<?php echo $active ? 'inactive' : 'active'; ?>" />
                                    <button type="submit" name="toggle_addon" class="px-4 py-2 rounded-xl text-xs font-semibold transition <?php echo $active ? 'bg-red-500/20 text-red-400 hover:bg-red-500/30' : 'bg-green-500/20 text-green-400 hover:bg-green-500/30'; ?>">
                                        <?php echo $active ? 'Disable' : 'Enable'; ?>
                                    </button>
                                </form>
                            <?php elseif ($purchaseStatus === 'pending'): ?>
                                <button disabled class="px-4 py-2 bg-white/5 border border-white/5 text-gray-500 rounded-xl text-xs font-semibold cursor-not-allowed">
                                    Awaiting Approval
                                </button>
                            <?php else: ?>
                                <button onclick="openCheckout('<?php echo htmlspecialchars($name); ?>', <?php echo $price; ?>)" class="px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl text-xs font-bold shadow-lg transition-all">
                                    Unlock Module
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Checkout Overlay Modal -->
        <div id="checkout-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden">
            <div class="bg-gray-900 border border-white/10 rounded-2xl w-full max-w-lg p-8 shadow-2xl mx-4 space-y-6 relative overflow-y-auto max-h-[90vh]">
                <button onclick="closeCheckout()" class="absolute top-4 right-4 text-gray-400 hover:text-white text-lg">✕</button>
                
                <div class="text-center">
                    <h3 class="text-xl font-bold text-white">Secure Checkout</h3>
                    <p class="text-xs text-gray-400 mt-1">Unlock <span id="checkout-addon-name" class="font-bold text-blue-400">...</span> Module</p>
                </div>
                
                <div class="p-4 bg-white/5 border border-white/5 rounded-xl flex justify-between items-center text-sm font-semibold">
                    <span class="text-gray-300">Total Price:</span>
                    <span id="checkout-addon-price" class="text-green-400 text-lg">₦0.00</span>
                </div>

                <form method="post" class="space-y-4">
                    <input type="hidden" name="addon_name" id="form-addon-name" value="" />
                    
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Select Payment Method</label>
                        <select name="payment_method" id="payment-method-select" onchange="togglePaymentFields(this.value)" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm">
                            <option value="online">Online Payment (Simulated Debit Card)</option>
                            <option value="bank_transfer">Direct Bank Transfer (Manual Approval)</option>
                        </select>
                    </div>

                    <!-- Online Card fields -->
                    <div id="card-payment-fields" class="space-y-3">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Card Holder Name</label>
                            <input type="text" placeholder="Cardholder Name" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Card Number</label>
                            <input type="text" placeholder="4000 1234 5678 9010" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs font-mono" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Expiry Date</label>
                                <input type="text" placeholder="MM/YY" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs font-mono" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">CVV</label>
                                <input type="password" placeholder="***" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs font-mono" />
                            </div>
                        </div>
                    </div>

                    <!-- Bank transfer instructions -->
                    <div id="bank-payment-fields" class="p-4 bg-blue-500/5 border border-blue-500/10 rounded-xl space-y-3 hidden">
                        <p class="text-xs text-blue-300 font-medium">Please make payment to the platform administrator's official bank details listed below:</p>
                        <div class="text-xs space-y-1 text-gray-300">
                            <p>🏦 Bank: <strong class="text-white"><?php echo htmlspecialchars(defined('PLATFORM_BANK_NAME') ? PLATFORM_BANK_NAME : 'Not Set'); ?></strong></p>
                            <p>🔢 Account Number: <strong class="text-white"><?php echo htmlspecialchars(defined('PLATFORM_ACCOUNT_NUMBER') ? PLATFORM_ACCOUNT_NUMBER : 'Not Set'); ?></strong></p>
                            <p>👤 Account Name: <strong class="text-white"><?php echo htmlspecialchars(defined('PLATFORM_ACCOUNT_NAME') ? PLATFORM_ACCOUNT_NAME : 'Not Set'); ?></strong></p>
                        </div>
                        <p class="text-[10px] text-gray-400 leading-normal">Submit this form after making your transfer. The super administrator will approve activation immediately upon verification.</p>
                    </div>

                    <button type="submit" name="buy_addon" class="w-full py-2.5 bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 text-white rounded-xl font-bold text-sm shadow-lg transition-all">
                        Complete Secure Purchase
                    </button>
                </form>
            </div>
        </div>

        <script>
        function openCheckout(addonName, price) {
            document.getElementById('checkout-addon-name').innerText = addonName;
            document.getElementById('form-addon-name').value = addonName;
            document.getElementById('checkout-addon-price').innerText = '₦' + price.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('checkout-modal').classList.remove('hidden');
        }

        function closeCheckout() {
            document.getElementById('checkout-modal').classList.add('hidden');
        }

        function togglePaymentFields(method) {
            if (method === 'online') {
                document.getElementById('card-payment-fields').classList.remove('hidden');
                document.getElementById('bank-payment-fields').classList.add('hidden');
            } else {
                document.getElementById('card-payment-fields').classList.add('hidden');
                document.getElementById('bank-payment-fields').classList.remove('hidden');
            }
        }
        </script>
    <?php elseif ($action === 'renew') : 
        $packages = $pdo->query("SELECT * FROM sm_subscription_packages ORDER BY price ASC")->fetchAll();
        // Fetch current subscription status to customize messages
        $stmtSchoolInfo = $pdo->prepare("SELECT s.*, p.name as package_name FROM sm_schools s LEFT JOIN sm_subscription_packages p ON p.id = s.package_id WHERE s.id = ?");
        $stmtSchoolInfo->execute([$school_id]);
        $school_info = $stmtSchoolInfo->fetch() ?: [];
    ?>
        <div class="max-w-4xl mx-auto space-y-6">
            <?php if ($isSuspended): ?>
                <div class="text-center max-w-xl mx-auto mb-8">
                    <h3 class="text-2xl font-bold bg-gradient-to-r from-red-400 to-amber-400 bg-clip-text text-transparent">🔒 Subscription Expired</h3>
                    <p class="text-xs text-gray-400 mt-1">Your portal is currently suspended. Please select a subscription plan below to complete online renewal and restore access instantly.</p>
                </div>
            <?php else: ?>
                <div class="text-center max-w-xl mx-auto mb-8">
                    <h3 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">🔄 Subscription Management</h3>
                    <p class="text-xs text-gray-400 mt-1">Manage and upgrade your portal subscription plan. Your current plan is: <strong class="text-indigo-400"><?php echo htmlspecialchars($school_info['package_name'] ?: 'None (Custom)'); ?></strong> (Expires: <?php echo $school_info['subscription_expires_at'] ? date('M d, Y', strtotime($school_info['subscription_expires_at'])) : 'Never'; ?>)</p>
                </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php foreach ($packages as $pkg) : ?>
                    <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md flex flex-col justify-between hover:border-white/20 transition-all duration-300 relative overflow-hidden group">
                        <div class="space-y-4">
                            <div class="flex justify-between items-start">
                                <span class="text-3xl">📦</span>
                                <span class="px-2.5 py-0.5 bg-blue-500/10 text-blue-400 border border-blue-500/20 rounded-full text-[10px] font-mono font-bold tracking-wider capitalize">
                                    <?php echo htmlspecialchars($pkg['billing_cycle']); ?>
                                </span>
                            </div>
                            
                            <div>
                                <h4 class="text-lg font-bold text-white"><?php echo htmlspecialchars($pkg['name']); ?></h4>
                                <p class="text-xs text-gray-400 mt-1.5 leading-relaxed"><?php echo htmlspecialchars($pkg['description']); ?></p>
                            </div>
                            
                            <div class="pt-2">
                                <span class="text-2xl font-extrabold text-white">₦<?php echo number_format($pkg['price'], 2); ?></span>
                                <span class="text-[10px] text-gray-500 block mt-0.5">Max Students: <?php echo $pkg['max_students'] > 0 ? number_format($pkg['max_students']) : 'Unlimited'; ?></span>
                            </div>
                        </div>
                        
                        <div class="mt-6 pt-4 border-t border-white/5">
                            <button onclick="openRenewCheckout(<?php echo $pkg['id']; ?>, '<?php echo htmlspecialchars(addslashes($pkg['name'])); ?>', <?php echo $pkg['price']; ?>)" class="w-full py-2 bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-700 hover:to-orange-700 text-white rounded-xl text-xs font-bold shadow-lg transition-all">
                                Select & Renew
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Renew Checkout Overlay Modal -->
        <div id="renew-checkout-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden">
            <div class="bg-gray-900 border border-white/10 rounded-2xl w-full max-w-lg p-8 shadow-2xl mx-4 space-y-6 relative overflow-y-auto max-h-[90vh]">
                <button onclick="closeRenewCheckout()" class="absolute top-4 right-4 text-gray-450 hover:text-white text-lg">✕</button>
                
                <div class="text-center">
                    <h3 class="text-xl font-bold text-white">Subscription Checkout</h3>
                    <p class="text-xs text-gray-400 mt-1">Renewing <span id="checkout-pkg-name" class="font-bold text-amber-400">...</span></p>
                </div>
                
                <div class="p-4 bg-white/5 border border-white/5 rounded-xl flex justify-between items-center text-sm font-semibold">
                    <span class="text-gray-300">Total Renewal Fee:</span>
                    <span id="checkout-pkg-price" class="text-green-400 text-lg">₦0.00</span>
                </div>

                <form method="post" class="space-y-4">
                    <input type="hidden" name="package_id" id="form-package-id" value="" />
                    
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Select Payment Method</label>
                        <select name="payment_method" id="renew-payment-method-select" onchange="toggleRenewPaymentFields(this.value)" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-amber-500 text-sm">
                            <option value="online">Online Payment (Simulated Debit Card)</option>
                            <option value="bank_transfer">Direct Bank Transfer (Manual Approval)</option>
                        </select>
                    </div>

                    <!-- Online Card fields -->
                    <div id="renew-card-payment-fields" class="space-y-3">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Card Holder Name</label>
                            <input type="text" placeholder="Cardholder Name" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-amber-500 text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Card Number</label>
                            <input type="text" placeholder="4000 1234 5678 9010" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-amber-500 text-xs font-mono" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Expiry Date</label>
                                <input type="text" placeholder="MM/YY" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-amber-500 text-xs font-mono" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">CVV</label>
                                <input type="password" placeholder="***" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-amber-500 text-xs font-mono" />
                            </div>
                        </div>
                    </div>

                    <!-- Bank transfer instructions -->
                    <div id="renew-bank-payment-fields" class="p-4 bg-blue-500/5 border border-blue-500/10 rounded-xl space-y-3 hidden">
                        <p class="text-xs text-blue-300 font-medium">Please make payment to the platform administrator's official bank details listed below:</p>
                        <div class="text-xs space-y-1 text-gray-300">
                            <p>🏦 Bank: <strong class="text-white"><?php echo htmlspecialchars(defined('PLATFORM_BANK_NAME') ? PLATFORM_BANK_NAME : 'Not Set'); ?></strong></p>
                            <p>🔢 Account Number: <strong class="text-white"><?php echo htmlspecialchars(defined('PLATFORM_ACCOUNT_NUMBER') ? PLATFORM_ACCOUNT_NUMBER : 'Not Set'); ?></strong></p>
                            <p>👤 Account Name: <strong class="text-white"><?php echo htmlspecialchars(defined('PLATFORM_ACCOUNT_NAME') ? PLATFORM_ACCOUNT_NAME : 'Not Set'); ?></strong></p>
                        </div>
                        <p class="text-[10px] text-gray-400 leading-normal">Submit this form after making your transfer. The super administrator will approve activation immediately upon verification.</p>
                    </div>

                    <button type="submit" name="renew_subscription" class="w-full py-2.5 bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-700 hover:to-orange-700 text-white rounded-xl font-bold text-sm shadow-lg transition-all">
                        Complete Renewal & Unlock Portal
                    </button>
                </form>
            </div>
        </div>

        <script>
        function openRenewCheckout(pkgId, pkgName, price) {
            document.getElementById('checkout-pkg-name').innerText = pkgName;
            document.getElementById('form-package-id').value = pkgId;
            document.getElementById('checkout-pkg-price').innerText = '₦' + price.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('renew-checkout-modal').classList.remove('hidden');
        }

        function closeRenewCheckout() {
            document.getElementById('renew-checkout-modal').classList.add('hidden');
        }

        function toggleRenewPaymentFields(method) {
            if (method === 'online') {
                document.getElementById('renew-card-payment-fields').classList.remove('hidden');
                document.getElementById('renew-bank-payment-fields').classList.add('hidden');
            } else {
                document.getElementById('renew-card-payment-fields').classList.add('hidden');
                document.getElementById('renew-bank-payment-fields').classList.remove('hidden');
            }
        }
        </script>
    <?php endif; ?>
</div>
