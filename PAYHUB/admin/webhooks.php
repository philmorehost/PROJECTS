<?php
// php-version/admin/webhooks.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Webhook Logs - Admin Hub';

$db = Database::connect();

// Retry a failed merchant delivery immediately. Previously this action only incremented
// attempt_count and displayed a success banner; it never sent an HTTP request.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'retry') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Invalid security token. Refresh the page and try again.';
    } else {
        $logId = (int)($_POST['log_id'] ?? 0);
        $stmt = $db->prepare("SELECT w.id, w.transaction_id, w.payload, t.reference, t.status AS transaction_status, u.webhook_url, u.secret_key, u.test_secret_key FROM webhook_logs w JOIN transactions t ON w.transaction_id = t.id JOIN users u ON t.user_id = u.id WHERE w.id = ? LIMIT 1");
        $stmt->execute([$logId]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            $error_msg = 'Webhook log not found.';
        } elseif (($delivery['transaction_status'] ?? '') !== 'success') {
            $error_msg = 'This transaction is not marked successful in PayHub; no webhook was sent.';
        } elseif (empty($delivery['webhook_url'])) {
            $error_msg = 'No webhook URL is configured for this merchant.';
        } else {
            $payload = (string)($delivery['payload'] ?? '');
            $payloadData = json_decode($payload, true);
            $payloadRef = $payloadData['data']['reference'] ?? '';
            if (!is_array($payloadData) || ($payloadData['event'] ?? '') !== 'charge.success' || $payloadRef !== $delivery['reference']) {
                $error_msg = 'The stored webhook payload is missing or does not match this transaction; it was not sent.';
            } elseif (!function_exists('curl_init')) {
                $error_msg = 'PHP cURL is unavailable, so the webhook could not be sent.';
            } else {
                $secret = (string)($delivery['secret_key'] ?? '');
                if ($secret === '' && !empty($delivery['test_secret_key'])) {
                    $secret = (string)$delivery['test_secret_key'];
                }
                $headers = ['Content-Type: application/json'];
                if ($secret !== '') {
                    $headers[] = 'X-Payhub-Signature: ' . hash_hmac('sha256', $payload, $secret);
                }

                $ch = curl_init($delivery['webhook_url']);
                if ($ch === false) {
                    $httpCode = 0;
                    $responseBody = false;
                    $curlError = 'Could not initialize cURL.';
                } else {
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

                $deliverySucceeded = ($responseBody !== false && $httpCode >= 200 && $httpCode < 300);
                $responseText = trim(substr(str_replace(["\r", "\n"], ' ', (string)$responseBody), 0, 180));
                $responseSummary = 'HTTP ' . $httpCode;
                if ($curlError !== '') $responseSummary .= ' | ' . $curlError;
                elseif ($responseText !== '') $responseSummary .= ' | ' . $responseText;

                $update = $db->prepare("UPDATE webhook_logs SET status = ?, attempt_count = attempt_count + 1, last_attempt_at = CURRENT_TIMESTAMP, response = ? WHERE id = ? AND transaction_id = ?");
                $update->execute([
                    $deliverySucceeded ? 'success' : 'failed',
                    substr($responseSummary, 0, 500),
                    $logId,
                    $delivery['transaction_id']
                ]);

                if ($deliverySucceeded) {
                    $success_msg = "Webhook delivered successfully on retry (HTTP $httpCode).";
                } else {
                    $error_msg = "Webhook retry failed (HTTP $httpCode). " . ($curlError !== '' ? $curlError : ($responseText !== '' ? $responseText : 'The merchant endpoint did not return a successful response.'));
                }
            }
        }
    }
}

$stmt = $db->query("SELECT w.*, t.reference as transaction_ref FROM webhook_logs w JOIN transactions t ON w.transaction_id = t.id WHERE 1=1" . admin_tx_lens_sql('t') . " ORDER BY w.created_at DESC");
$logs = $stmt->fetchAll();

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
                <p class="text-slate-500">A detailed log of every notification sent to merchant servers</p>
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
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="retry">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="log_id" value="<?php echo (int)$l['id']; ?>">
                                            <button type="submit" class="text-indigo-600 hover:text-indigo-800" title="Retry Delivery">
                                                <i data-lucide="refresh-ccw" class="w-4 h-4"></i>
                                            </button>
                                        </form>
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