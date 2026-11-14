<?php
// Admin payout queue and settlement history.
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Settlements - Admin Hub';
$db = Database::connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_payout') {
    $payoutId = filter_var($_POST['payout_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = $_POST['decision'] ?? (($_POST['status'] ?? '') === 'processed' ? 'approve' : (($_POST['status'] ?? '') === 'declined' ? 'cancel_refund' : ''));
    $details = trim((string)($_POST['details'] ?? ''));
    $status = $decision === 'approve' ? 'processed' : 'declined';
    $refundFunds = $decision !== 'cancel_keep';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Your session security token expired. Refresh the page and try again.';
    } elseif (!$payoutId || !in_array($decision, ['approve', 'cancel_refund', 'cancel_keep'], true) || $details === '') {
        $error_msg = 'Invalid payout action. Choose approve or one of the cancellation options, and include internal notes.';
    } else {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT p.*, u.settlement_bank_code, u.settlement_account_name FROM payouts p JOIN users u ON p.user_id = u.id WHERE p.id = ? FOR UPDATE");
            $stmt->execute([$payoutId]);
            $payout = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payout) throw new RuntimeException('Payout request was not found. Refresh the list and try again.');
            if ($payout['status'] !== 'pending') throw new RuntimeException('This payout is no longer pending and cannot be processed again.');
            $payoutDetailsLower = strtolower((string)($payout['status_details'] ?? ''));
            if (strpos($payoutDetailsLower, 'transfer outcome uncertain') !== false
                || strpos($payoutDetailsLower, 'transfer submission started') !== false
                || strpos($payoutDetailsLower, 'transfer submission pending') !== false) {
                throw new RuntimeException('The provider may already have accepted this transfer. Reconcile it with Paystack before retrying or releasing the reserved funds.');
            }
            if ($status === 'processed' && strpos($payoutDetailsLower, 'bank details changed') !== false) {
                throw new RuntimeException('The merchant bank details changed after this payout was reserved. Update/reconcile the destination before processing.');
            }

            if ($status === 'processed') {
                $payoutAmount = (float)$payout['net_amount'];
                if ($payoutAmount <= 0) $payoutAmount = (float)$payout['amount'];
                if (empty($payout['settlement_bank_code']) || empty($payout['account_number'])) {
                    throw new RuntimeException('The merchant settlement bank details are incomplete. Ask the merchant to update their bank account.');
                }
                $res = paystack_payout((int)$payout['user_id'], $payoutAmount, 'PayHub payout approval #' . $payoutId, [
                    'bank_name' => $payout['bank_name'],
                    'bank_code' => $payout['settlement_bank_code'],
                    'account_number' => $payout['account_number'],
                    'account_name' => $payout['settlement_account_name'] ?: $payout['bank_name']
                ]);
                if (!$res || empty($res['status'])) {
                    $gatewayMessage = $res['message'] ?? 'The payment provider did not return a successful transfer response.';
                    file_put_contents(BASE_PATH . 'payout_debug.log', "[" . date('Y-m-d H:i:s') . "] Payout Approval Failed for ID $payoutId: " . json_encode($res) . PHP_EOL, FILE_APPEND | LOCK_EX);
                    throw new RuntimeException('Transfer failed: ' . $gatewayMessage);
                }
                $details .= ' | Transfer: ' . ($res['data']['transfer_code'] ?? $res['data']['reference'] ?? 'initiated');
                $stmt = $db->prepare("UPDATE payouts SET status = 'processed', status_details = ? WHERE id = ? AND status = 'pending'");
                $stmt->execute([$details, $payoutId]);
            } else {
                $cancelNote = $refundFunds
                    ? 'Cancelled by admin — funds returned to merchant wallet. '
                    : 'Cancelled without wallet reversal — manual offline resolution required. ';
                $stmt = $db->prepare("UPDATE payouts SET status = 'declined', status_details = ? WHERE id = ? AND status = 'pending'");
                $stmt->execute([$cancelNote . $details, $payoutId]);
                if ($refundFunds) {
                    // Payout requests reserve amount + fee, so reverse the complete hold.
                    $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
                    $stmt->execute([$payout['amount'], $payout['user_id']]);
                    $stmt = $db->prepare("INSERT INTO ledger (user_id, amount, type, category, description, balance_after) SELECT id, ?, 'credit', 'payout_refund', ?, wallet_balance FROM users WHERE id = ?");
                    $stmt->execute([$payout['amount'], 'Admin cancellation refund for payout #' . $payoutId, $payout['user_id']]);
                }
            }
            $db->commit();
            if ($decision === 'approve') {
                $success_msg = 'Payout approved and submitted to the payment provider.';
            } elseif ($refundFunds) {
                $success_msg = 'Payout cancelled; the full held amount was returned to the merchant wallet.';
            } else {
                $success_msg = 'Payout cancelled without wallet reversal. It is marked for manual offline resolution; funds remain debited.';
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error_msg = 'Failed to process payout: ' . $e->getMessage();
        }
    }
}

// Summary statistics cover the full queue and history, independent of the selected page/filter.
$statsStmt = $db->query("SELECT
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) AS pending_gross,
    SUM(CASE WHEN status IN ('processed', 'completed') THEN 1 ELSE 0 END) AS sent_count,
    SUM(CASE WHEN status IN ('processed', 'completed') THEN COALESCE(NULLIF(net_amount, 0), amount) ELSE 0 END) AS sent_total,
    SUM(CASE WHEN status IN ('declined', 'failed') THEN 1 ELSE 0 END) AS closed_count,
    SUM(CASE WHEN status IN ('declined', 'failed') THEN amount ELSE 0 END) AS closed_total
    FROM payouts");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$pendingCount = (int)($stats['pending_count'] ?? 0);
$sentCount = (int)($stats['sent_count'] ?? 0);
$closedCount = (int)($stats['closed_count'] ?? 0);

$view = (($_GET['view'] ?? 'pending') === 'history') ? 'history' : 'pending';
$search = trim((string)($_GET['q'] ?? ''));
$allowedHistoryStatuses = ['processed', 'completed', 'declined', 'failed', 'processing'];
$statusFilter = $view === 'history' && in_array($_GET['status'] ?? '', $allowedHistoryStatuses, true) ? $_GET['status'] : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$where = $view === 'pending' ? "p.status = 'pending'" : "p.status <> 'pending'";
$params = [];
if ($statusFilter !== '') {
    $where .= ' AND p.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where .= ' AND (u.business_name LIKE ? OR p.account_number LIKE ? OR CAST(p.id AS CHAR) LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}
$countStmt = $db->prepare("SELECT COUNT(*) FROM payouts p JOIN users u ON p.user_id = u.id WHERE $where");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$listStmt = $db->prepare("SELECT p.*, u.business_name FROM payouts p JOIN users u ON p.user_id = u.id WHERE $where ORDER BY p.request_date DESC, p.id DESC LIMIT $perPage OFFSET $offset");
$listStmt->execute($params);
$payouts = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$csrfToken = csrf_token();

$paginationUrl = function ($targetPage) use ($view, $search, $statusFilter) {
    $query = ['view' => $view, 'page' => $targetPage];
    if ($search !== '') $query['q'] = $search;
    if ($statusFilter !== '') $query['status'] = $statusFilter;
    return '?' . http_build_query($query);
};

function render_settlement_actions($payout, $csrfToken) {
    $id = (int)$payout['id'];
    $serialized = htmlspecialchars(json_encode($payout), ENT_QUOTES, 'UTF-8');
    $token = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
    $details = strtolower((string)($payout['status_details'] ?? ''));
    $bankDetailsChanged = strpos($details, 'bank details changed') !== false;
    $transferMayHaveBeenSent = strpos($details, 'transfer outcome uncertain') !== false
        || strpos($details, 'transfer submission started') !== false
        || strpos($details, 'transfer submission pending') !== false;
    if ($transferMayHaveBeenSent) {
        ?>
        <div class="max-w-[230px] rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold leading-relaxed text-amber-800">
            Transfer outcome needs reconciliation. Check Paystack before retrying to avoid sending twice.
        </div>
        <?php
        return;
    }
    if ($bankDetailsChanged) {
        ?>
        <div class="max-w-[230px] space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold leading-relaxed text-amber-800">
            <p>Bank details changed after reservation. Update the destination or safely release the reserve.</p>
            <form method="POST" onsubmit="return confirm('Cancel this payout and return the reserved funds to the merchant wallet?');">
                <input type="hidden" name="action" value="process_payout"><input type="hidden" name="csrf_token" value="<?php echo $token; ?>"><input type="hidden" name="payout_id" value="<?php echo $id; ?>"><input type="hidden" name="details" value="Bank details changed; reserve returned."><input type="hidden" name="decision" value="cancel_refund">
                <button type="submit" class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-xs font-bold text-amber-900 hover:bg-amber-100">Cancel &amp; Return Reserve</button>
            </form>
        </div>
        <?php
        return;
    }
    ?>
    <div class="min-w-[190px] space-y-2">
        <button type="button" @click="showProcess = true; payout = <?php echo $serialized; ?>" class="w-full rounded-xl bg-indigo-600 px-3 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-indigo-700">Approve / Process</button>
        <form method="POST" onsubmit="return confirm('Cancel this payout and reverse the held amount to the merchant wallet?');">
            <input type="hidden" name="action" value="process_payout"><input type="hidden" name="csrf_token" value="<?php echo $token; ?>"><input type="hidden" name="payout_id" value="<?php echo $id; ?>"><input type="hidden" name="details" value="Admin cancelled request and returned held funds to wallet."><input type="hidden" name="decision" value="cancel_refund">
            <button type="submit" class="w-full rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs font-bold text-amber-800 hover:bg-amber-100">Cancel &amp; Reverse</button>
        </form>
        <form method="POST" onsubmit="return confirm('Cancel without reversing funds to the wallet? Funds remain debited and this payout is marked for manual offline resolution.');">
            <input type="hidden" name="action" value="process_payout"><input type="hidden" name="csrf_token" value="<?php echo $token; ?>"><input type="hidden" name="payout_id" value="<?php echo $id; ?>"><input type="hidden" name="details" value="Manual offline resolution required; no wallet reversal requested."><input type="hidden" name="decision" value="cancel_keep">
            <button type="submit" class="w-full rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-xs font-bold text-rose-700 hover:bg-rose-100">Cancel Without Reverse<span class="mt-0.5 block text-[10px] font-medium">Manual offline resolution</span></button>
        </form>
    </div>
    <?php
}

function settlement_status_class($status) {
    if (in_array($status, ['processed', 'completed'], true)) return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (in_array($status, ['declined', 'failed'], true)) return 'bg-rose-50 text-rose-700 ring-rose-200';
    if ($status === 'processing') return 'bg-sky-50 text-sky-700 ring-sky-200';
    return 'bg-amber-50 text-amber-700 ring-amber-200';
}

include '../includes/dashboard-head.php';
?>
<body class="flex h-screen overflow-hidden bg-slate-50 text-slate-900" x-data="{ showProcess: false, payout: {} }">
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex min-w-0 flex-1 flex-col overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="mb-2 text-xs font-bold uppercase tracking-[0.18em] text-indigo-600">Finance operations</p>
                        <h1 class="text-3xl font-extrabold tracking-tight text-slate-950">Settlements</h1>
                        <p class="mt-2 text-sm text-slate-500">Review payout requests, take action, and follow completed or cancelled settlements.</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm">
                        <span class="text-slate-400">Showing</span>
                        <strong class="ml-1 text-slate-900"><?php echo $view === 'pending' ? 'Pending queue' : 'Payout history'; ?></strong>
                    </div>
                </header>

                <?php if (!empty($success_msg)): ?>
                    <div role="status" class="mb-6 flex gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800"><i data-lucide="check-circle-2" class="h-5 w-5 shrink-0"></i><span><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <?php endif; ?>
                <?php if (!empty($error_msg)): ?>
                    <div role="alert" class="mb-6 flex gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800"><i data-lucide="alert-circle" class="h-5 w-5 shrink-0"></i><span><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <?php endif; ?>

                <section aria-label="Payout statistics" class="mb-7 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between"><span class="text-sm font-semibold text-slate-500">Pending requests</span><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600"><i data-lucide="clock-3" class="h-5 w-5"></i></span></div>
                        <p class="mt-4 text-3xl font-extrabold text-slate-950"><?php echo number_format($pendingCount); ?></p><p class="mt-1 text-xs text-slate-500">Awaiting admin action</p>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between"><span class="text-sm font-semibold text-slate-500">Pending amount</span><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600"><i data-lucide="wallet" class="h-5 w-5"></i></span></div>
                        <p class="mt-4 text-2xl font-extrabold text-slate-950"><?php echo formatCurrency((float)($stats['pending_gross'] ?? 0)); ?></p><p class="mt-1 text-xs text-slate-500">Gross held across the full queue</p>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between"><span class="text-sm font-semibold text-slate-500">Sent to provider</span><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"><i data-lucide="circle-check" class="h-5 w-5"></i></span></div>
                        <p class="mt-4 text-3xl font-extrabold text-slate-950"><?php echo number_format($sentCount); ?></p><p class="mt-1 text-xs text-slate-500"><?php echo formatCurrency((float)($stats['sent_total'] ?? 0)); ?> net payouts</p>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between"><span class="text-sm font-semibold text-slate-500">Cancelled / failed</span><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50 text-rose-600"><i data-lucide="circle-x" class="h-5 w-5"></i></span></div>
                        <p class="mt-4 text-3xl font-extrabold text-slate-950"><?php echo number_format($closedCount); ?></p><p class="mt-1 text-xs text-slate-500"><?php echo formatCurrency((float)($stats['closed_total'] ?? 0)); ?> gross requests</p>
                    </article>
                </section>

                <div class="mb-5 flex flex-col gap-4 border-b border-slate-200 sm:flex-row sm:items-center sm:justify-between">
                    <nav class="flex gap-2" aria-label="Settlement views">
                        <a href="?view=pending" class="inline-flex items-center gap-2 rounded-t-xl border-b-2 px-4 py-3 text-sm font-bold transition-colors <?php echo $view === 'pending' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-800'; ?>">Pending queue<span class="rounded-full px-2 py-0.5 text-xs <?php echo $view === 'pending' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-600'; ?>"><?php echo number_format($pendingCount); ?></span></a>
                        <a href="?view=history" class="inline-flex items-center gap-2 rounded-t-xl border-b-2 px-4 py-3 text-sm font-bold transition-colors <?php echo $view === 'history' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-800'; ?>">History</a>
                    </nav>
                    <form method="GET" class="mb-3 flex w-full flex-col gap-2 sm:mb-2 sm:w-auto sm:flex-row">
                        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="relative flex-1 sm:w-64"><i data-lucide="search" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i><input type="search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Merchant, account, or ID" class="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-10 pr-3 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"></label>
                        <?php if ($view === 'history'): ?>
                            <select name="status" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-indigo-400"><option value="">All history</option><?php foreach ($allowedHistoryStatuses as $historyStatus): ?><option value="<?php echo $historyStatus; ?>" <?php echo $statusFilter === $historyStatus ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($historyStatus), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                        <?php endif; ?>
                        <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700">Search</button>
                        <?php if ($search !== '' || $statusFilter !== ''): ?><a href="?view=<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-center text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a><?php endif; ?>
                    </form>
                </div>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-1 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div><h2 class="font-bold text-slate-900"><?php echo $view === 'pending' ? 'All pending payouts' : 'Payout history'; ?></h2><p class="text-xs text-slate-500"><?php echo number_format($totalRows); ?> matching request<?php echo $totalRows === 1 ? '' : 's'; ?> · Page <?php echo $page; ?> of <?php echo $totalPages; ?></p></div>
                        <span class="text-xs text-slate-400">Newest requests first</span>
                    </div>

                    <?php if (!$payouts): ?>
                        <div class="px-6 py-16 text-center"><span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><i data-lucide="inbox" class="h-7 w-7"></i></span><h3 class="mt-4 font-bold text-slate-900">No payouts found</h3><p class="mt-1 text-sm text-slate-500"><?php echo $view === 'pending' ? 'There are no pending requests matching this search.' : 'Completed and cancelled payout activity will appear here.'; ?></p></div>
                    <?php else: ?>
                        <!-- Compact cards for phones -->
                        <div class="divide-y divide-slate-100 md:hidden">
                            <?php foreach ($payouts as $p): ?>
                                <article class="space-y-4 p-4">
                                    <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate font-bold text-slate-900"><?php echo htmlspecialchars($p['business_name'] ?? 'Merchant', ENT_QUOTES, 'UTF-8'); ?></p><p class="mt-1 text-xs text-slate-500">Request #<?php echo (int)$p['id']; ?> · <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($p['request_date'])), ENT_QUOTES, 'UTF-8'); ?></p></div><span class="shrink-0 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase ring-1 <?php echo settlement_status_class($p['status']); ?>"><?php echo htmlspecialchars($p['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                                    <div class="grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 text-xs"><div><p class="text-slate-400">Bank / account</p><p class="mt-1 font-semibold text-slate-700"><?php echo htmlspecialchars($p['bank_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($p['account_number'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></p></div><div class="text-right"><p class="text-slate-400">Gross / net</p><p class="mt-1 font-bold text-slate-900"><?php echo formatCurrency((float)$p['amount']); ?><span class="font-medium text-slate-500"> / <?php echo formatCurrency((float)($p['net_amount'] ?: $p['amount'])); ?></span></p></div></div>
                                    <?php if (!empty($p['status_details'])): ?><p class="break-words text-xs text-slate-500"><?php echo htmlspecialchars($p['status_details'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                                    <?php if ($view === 'pending'): render_settlement_actions($p, $csrfToken); endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <!-- Full detail table for tablet and desktop -->
                        <div class="hidden overflow-x-auto md:block">
                            <table class="min-w-[900px] w-full text-left">
                                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400"><tr><th class="px-5 py-4">Merchant / reference</th><th class="px-5 py-4">Destination</th><th class="px-5 py-4 text-right">Gross</th><th class="px-5 py-4 text-right">Fee</th><th class="px-5 py-4 text-right">Net payout</th><th class="px-5 py-4">Status</th><th class="px-5 py-4">Requested</th><?php if ($view === 'pending'): ?><th class="px-5 py-4">Actions</th><?php endif; ?></tr></thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($payouts as $p): ?>
                                        <tr class="align-top transition-colors hover:bg-slate-50/70">
                                            <td class="px-5 py-4"><p class="max-w-[180px] truncate text-sm font-bold text-slate-900"><?php echo htmlspecialchars($p['business_name'] ?? 'Merchant', ENT_QUOTES, 'UTF-8'); ?></p><p class="mt-1 text-xs text-slate-400">Request #<?php echo (int)$p['id']; ?></p></td>
                                            <td class="px-5 py-4"><p class="text-sm font-semibold text-slate-800"><?php echo htmlspecialchars($p['bank_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></p><p class="mt-1 font-mono text-xs text-slate-500"><?php echo htmlspecialchars($p['account_number'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></p></td>
                                            <td class="whitespace-nowrap px-5 py-4 text-right text-sm font-semibold text-slate-800"><?php echo formatCurrency((float)$p['amount']); ?></td>
                                            <td class="whitespace-nowrap px-5 py-4 text-right text-sm text-slate-500"><?php echo formatCurrency((float)($p['fee_amount'] ?? 0)); ?></td>
                                            <td class="whitespace-nowrap px-5 py-4 text-right text-sm font-bold text-slate-900"><?php echo formatCurrency((float)($p['net_amount'] ?: $p['amount'])); ?></td>
                                            <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase ring-1 <?php echo settlement_status_class($p['status']); ?>"><?php echo htmlspecialchars($p['status'], ENT_QUOTES, 'UTF-8'); ?></span><?php if (!empty($p['status_details'])): ?><p class="mt-2 max-w-[200px] break-words text-[10px] leading-relaxed text-slate-500"><?php echo htmlspecialchars($p['status_details'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?></td>
                                            <td class="whitespace-nowrap px-5 py-4 text-xs text-slate-500"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($p['request_date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <?php if ($view === 'pending'): ?><td class="px-5 py-4"><?php render_settlement_actions($p, $csrfToken); ?></td><?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <footer class="flex flex-col gap-3 border-t border-slate-100 bg-slate-50/70 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <p class="text-xs font-medium text-slate-500">Showing <strong class="text-slate-800"><?php echo $totalRows ? $offset + 1 : 0; ?>–<?php echo min($offset + count($payouts), $totalRows); ?></strong> of <strong class="text-slate-800"><?php echo number_format($totalRows); ?></strong></p>
                        <div class="flex items-center gap-2">
                            <a href="<?php echo htmlspecialchars($paginationUrl(max(1, $page - 1)), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous page" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 <?php echo $page <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-slate-100'; ?>"><i data-lucide="chevron-left" class="h-4 w-4"></i><span>Previous</span></a>
                            <span class="px-2 text-xs font-semibold text-slate-500">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>
                            <a href="<?php echo htmlspecialchars($paginationUrl(min($totalPages, $page + 1)), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next page" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 <?php echo $page >= $totalPages ? 'pointer-events-none opacity-40' : 'hover:bg-slate-100'; ?>"><span>Next</span><i data-lucide="chevron-right" class="h-4 w-4"></i></a>
                        </div>
                    </footer>
                </section>
            </div>
        </div>

        <div x-show="showProcess" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
            <div @click.outside="showProcess = false" class="w-full max-w-md overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><div><p class="text-xs font-bold uppercase tracking-widest text-indigo-600">Admin action</p><h3 class="mt-1 text-lg font-extrabold text-slate-900">Approve payout</h3></div><button type="button" @click="showProcess = false" aria-label="Close" class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-800"><i data-lucide="x" class="h-5 w-5"></i></button></div>
                <div class="p-6"><p class="mb-5 text-sm text-slate-600">Submit <strong class="text-slate-900" x-text="'₦' + Number(payout.net_amount || payout.amount || 0).toLocaleString()"></strong> to <strong class="text-slate-900" x-text="payout.business_name"></strong>.</p>
                    <form method="POST" class="space-y-4"><input type="hidden" name="action" value="process_payout"><input type="hidden" name="decision" value="approve"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="payout_id" :value="payout.id">
                        <label class="block"><span class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-500">Internal note</span><textarea name="details" required placeholder="Add a short admin note or reference" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"></textarea></label>
                        <button type="submit" class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white hover:bg-emerald-700">Confirm &amp; Send Payout</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof lucide !== 'undefined') lucide.createIcons();
    });
</script>
</body>
</html>
