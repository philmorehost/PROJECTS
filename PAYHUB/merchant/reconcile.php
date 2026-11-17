<?php
// php-version/merchant/reconcile.php
require_once '../includes/functions.php';

if (!isLoggedIn()) redirect('../login.php');
requireCompliance();

$user = getAuthUser();
$pageTitle = 'Reconcile Payments - Payhub';
$db = Database::connect();
ensure_payment_schema();

$success_msg = '';
$error_msg = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reconcile') {
    $channel = $_POST['channel'] ?? 'all';
    $date = $_POST['date'] ?? date('Y-m-d');

    // Fetch Transactions from Paystack
    $from = $date . 'T00:00:00Z';
    $to = $date . 'T23:59:59Z';
    $params = "from=" . urlencode($from) . "&to=" . urlencode($to) . "&status=success";

    $response = paystack_call("transaction?" . $params, 'GET');

    if ($response && $response['status']) {
        $remote_txs = $response['data'];
        $reconciled_count = 0;

        foreach ($remote_txs as $rtx) {
            $remote_merchant_id = $rtx['metadata']['merchant_id'] ?? null;
            $ref = $rtx['reference'];
            $is_mine = ($remote_merchant_id == $user['id']);
            $vaccMeta = [];

            $acc_num = (string)($rtx['dedicated_account']['account_number']
                ?? ($rtx['authorization']['receiver_bank_account_number']
                ?? ($rtx['receiver_bank_account_number']
                ?? ($rtx['metadata']['receiver_account_number'] ?? ''))));
            if (!$is_mine && $acc_num !== '') {
                $cleanAcc = ltrim($acc_num, '0');
                $stmt = $db->prepare("SELECT user_id, metadata FROM virtual_accounts WHERE account_number = ? OR account_number = ? OR account_number = ? LIMIT 1");
                $stmt->execute([$acc_num, str_pad($cleanAcc, 10, '0', STR_PAD_LEFT), $cleanAcc]);
                $vacc = $stmt->fetch();
                if ($vacc && $vacc['user_id'] == $user['id']) {
                    $is_mine = true;
                    $vaccMeta = normalize_metadata_array($vacc['metadata'] ?? null);
                }
            }

            if ($is_mine) {
                $stmt = $db->prepare("SELECT id FROM transactions WHERE reference = ?");
                $stmt->execute([$ref]);
                if (!$stmt->fetch()) {
                    $amount = $rtx['amount'] / 100;
                    $email = $rtx['customer']['email'];
                    $channel_type = $rtx['channel'];
                    $gatewayFee = (isset($rtx['fees']) && is_numeric($rtx['fees']) && (float)$rtx['fees'] > 0)
                        ? ((float)$rtx['fees'] / 100)
                        : null;
                    $isIntl = is_international_payment($rtx['currency'] ?? 'NGN', $rtx['authorization'] ?? [], $channel_type);
                    $fee = calculate_fees($amount, $isIntl, $user['id'], $gatewayFee, $channel_type);
                    $settled = round(max(0.0, $amount - $fee), 2);
                    $txMetaJson = json_encode(array_merge(normalize_metadata_array($rtx['metadata'] ?? null), $vaccMeta));

                    $db->beginTransaction();
                    try {
                        $stmt = $db->prepare("INSERT IGNORE INTO transactions (user_id, reference, amount, status, customer_email, payment_method, is_test, fee_amount, settled_amount, gateway_reference, metadata) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)");
                        $is_test = ($user['is_test_mode'] == 1);
                        $stmt->execute([$user['id'], $ref, $amount, $email, $channel_type, $is_test ? 1 : 0, $fee, $settled, $rtx['id'], $txMetaJson]);
                        if ($stmt->rowCount() !== 1) {
                            $db->rollBack();
                            $results[] = ['reference' => $ref, 'amount' => $amount, 'status' => 'Already recorded'];
                            continue;
                        }
                        $t_id = $db->lastInsertId();
                        if (!claim_transaction_for_fulfilment($t_id)) {
                            $db->rollBack();
                            $results[] = ['reference' => $ref, 'amount' => $amount, 'status' => 'Already recorded'];
                            continue;
                        }

                        // The amount came straight from the gateway list response, so
                        // record it as the verified figure this row was created from.
                        record_gateway_amount($t_id, $amount);

                        log_transaction_event($t_id, 'reconciled', "Payment manually reconciled via self-service.");
                        log_ledger_entry($user['id'], $settled, 'credit', 'payment', "Reconciled Payment: $ref", $is_test);

                        $db->commit();
                        trigger_merchant_webhook($t_id);
                        $reconciled_count++;
                        $results[] = ['reference' => $ref, 'amount' => $amount, 'status' => 'Reconciled'];
                    } catch (Exception $e) {
                        $db->rollBack();
                        $results[] = ['reference' => $ref, 'amount' => $amount, 'status' => 'Error: ' . $e->getMessage()];
                    }
                } else {
                    $results[] = ['reference' => $ref, 'amount' => $rtx['amount']/100, 'status' => 'Already recorded'];
                }
            }
        }
        $success_msg = $reconciled_count > 0 ? "Successfully reconciled $reconciled_count transaction(s)." : "No missing transactions found.";
    } else {
        $error_msg = "Gateway Error: " . ($response['message'] ?? 'Unknown error');
    }
}

// Fetch merchant's virtual accounts for the dropdown
$stmt = $db->prepare("SELECT * FROM virtual_accounts WHERE user_id = ?");
$stmt->execute([$user['id']]);
$my_accounts = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data="{ mobileMenuOpen: false }">
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="max-w-4xl mx-auto">
                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-slate-900 mb-2">Reconcile Payments</h1>
                    <p class="text-slate-500">Sync missing gateway payments to your wallet</p>
                </div>

                <?php if ($success_msg): ?>
                    <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium"><?php echo $success_msg; ?></div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-2xl border border-red-100 font-medium"><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm mb-8">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="action" value="reconcile">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Transaction Date</label>
                                <input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Payment Channel</label>
                                <select name="channel" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                    <option value="all">All Channels</option>
                                    <option value="card">Card / Inline Checkout</option>
                                    <option value="dedicated_account">Virtual Account Transfer</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg">Scan & Reconcile</button>
                    </form>
                </div>

                <?php if (!empty($results)): ?>
                    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                        <div class="p-6 border-b border-slate-100 font-bold">Results</div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-slate-50 text-[10px] uppercase font-bold text-slate-400 tracking-widest">
                                    <tr><th class="px-6 py-4">Reference</th><th class="px-6 py-4">Amount</th><th class="px-6 py-4">Status</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($results as $r): ?>
                                        <tr>
                                            <td class="px-6 py-4 font-mono"><?php echo $r['reference']; ?></td>
                                            <td class="px-6 py-4 font-bold"><?php echo formatCurrency($r['amount']); ?></td>
                                            <td class="px-6 py-4">
                                                <span class="px-2 py-1 rounded-lg text-[10px] font-bold uppercase <?php echo $r['status'] === 'Reconciled' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'; ?>"><?php echo $r['status']; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
