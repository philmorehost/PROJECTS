<?php
// php-version/admin/webhooks.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Webhook Logs - Admin Hub';

$db = Database::connect();

require_once __DIR__.'/../includes/webhook-retry.php';

// Reserve and commit a single transaction-wide retry BEFORE opening HTTP.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Invalid security token. Refresh the page and try again.';
    } elseif (!function_exists('curl_init')) {
        $error_msg = 'PHP cURL is unavailable; no retry was claimed or sent.';
    } else {
        try {
            $claim = payhub_claim_webhook_retry($db, (int)($_POST['log_id'] ?? 0), ($_POST['confirm_reconciled'] ?? '') === '1');
            $delivery = $claim['delivery'];
            $payload = (string)$delivery['payload'];
            $signature = payhub_merchant_webhook_signature($payload, $delivery, $delivery['is_test'] ?? null);
            $headers = ['Content-Type: application/json', 'X-Payhub-Signature: '.$signature];
                $ch = $signature !== '' ? curl_init($delivery['webhook_url']) : false;
                if ($ch === false) {
                    $httpCode = 0;
                    $responseBody = false;
                    $curlError = $signature === '' ? 'Webhook signing refused: check mode-specific merchant key and payload domain.' : 'Could not initialize cURL.';
                } else {
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                    $responseBody = curl_exec($ch);
                    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);
                }


                $deliverySucceeded = payhub_finish_webhook_retry($db, $claim, $httpCode, $responseBody !== false && $curlError === '');
                if ($deliverySucceeded) {
                    $success_msg = "Webhook retry acknowledged (HTTP $httpCode). This payment cannot be replayed again through Retry.";
                } else {
                    $error_msg = "Retry outcome uncertain (HTTP $httpCode). It is blocked from replay. Independently reconcile merchant receipt/credit before further action.";
                }
        } catch (Throwable $e) {
            // Never expose SQL, merchant endpoints, credentials or remote response bodies.
            $error_msg = ($e instanceof RuntimeException && !($e instanceof PDOException)) ? $e->getMessage() : 'Retry could not complete. It may already be claimed; reconcile before retrying. Check schema and restricted server diagnostics.';
        }
    }
}

$stmt = $db->query("SELECT w.*, t.reference as transaction_ref FROM webhook_logs w JOIN transactions t ON w.transaction_id = t.id WHERE 1=1" . admin_tx_lens_sql('t') . " ORDER BY w.created_at DESC");
$logs = $stmt->fetchAll();
$retryStates = [];
$retrySchemaReady = false;
try {
    $retryStates = $db->query('SELECT transaction_id,state FROM webhook_retry_claims')->fetchAll(PDO::FETCH_KEY_PAIR);
    $retrySchemaReady = true;
} catch (Throwable $e) {
    // Read-only listing remains available before the operator applies the claim migration.
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
                    <?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($error_msg)): ?>
                <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-2xl border border-red-100 font-medium">
                    <?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-900 mb-2">Webhook Logs</h1>
                <p class="text-slate-500">Delivery records do not prove that a merchant credited a payment. Retry requires independent reconciliation.</p>
                <p class="mt-2 text-sm text-amber-800">One claimed retry per payment. Interrupted and non-acknowledged retries stay blocked; no automatic expiry or replay. <?php if (!$retrySchemaReady): ?>Retry is unavailable until the required claim migration is verified.<?php endif; ?></p>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 font-bold">Recent Deliveries</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Transaction</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Status</th>
                                <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Attempts</th>
                                <th class="hidden lg:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Last Attempt</th>
                                <th class="hidden xl:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Last Response</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($logs as $l): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-4 sm:px-6 py-4 text-xs font-mono font-bold"><?php echo $l['transaction_ref']; ?></td>
                                    <td class="px-4 sm:px-6 py-4">
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $l['status'] === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'; ?>">
                                            <?php echo $l['status']; ?>
                                        </span>
                                    </td>
                                    <td class="hidden md:table-cell px-6 py-4 text-sm"><?php echo $l['attempt_count']; ?></td>
                                    <td class="hidden lg:table-cell px-6 py-4 text-xs text-slate-500 font-medium"><?php echo $l['last_attempt_at'] ? date('M d, H:i', strtotime($l['last_attempt_at'])) : 'Never'; ?></td>
                                    <td class="hidden xl:table-cell px-6 py-4 text-xs text-slate-500 max-w-xs truncate">
                                        <?php
                                        $responsePreview = (string)($l['response'] ?? '');
                                        if (strlen($responsePreview) > 180) $responsePreview = substr($responsePreview, 0, 180) . '…';
                                        echo htmlspecialchars($responsePreview, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                        ?>
                                    </td>
                                    <td class="px-4 sm:px-6 py-4">
                                        <?php if ($retrySchemaReady && ($l['status'] ?? '') === 'failed' && !isset($retryStates[$l['transaction_id']])): ?>
                                        <form method="POST" class="inline">
                                            <label class="block text-xs text-slate-600 mb-2"><input type="checkbox" name="confirm_reconciled" value="1" required> Independently checked: merchant has not credited this payment; authorize one retry.</label>
                                            <input type="hidden" name="action" value="retry">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="log_id" value="<?php echo (int)$l['id']; ?>">
                                            <button type="submit" class="text-indigo-600 hover:text-indigo-800" title="Retry Delivery">
                                                <i data-lucide="refresh-ccw" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500"><?php echo isset($retryStates[$l['transaction_id']]) ? (($retryStates[$l['transaction_id']] === 'succeeded') ? 'Retry acknowledged; blocked from replay' : 'Retry claimed; reconciliation required') : 'No retry'; ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-slate-500">No webhook logs found.</td>
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