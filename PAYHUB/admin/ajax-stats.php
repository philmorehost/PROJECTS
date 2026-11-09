<?php
// php-version/admin/ajax-stats.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::connect();

// Same reporting lens as the dashboard. This endpoint is polled every 10 seconds and
// overwrites the tiles, so it MUST apply the lens too - otherwise the dashboard would
// briefly show live-only figures and then flicker back to blended ones.
$lens = admin_tx_lens_sql();
$lens_t = admin_tx_lens_sql('t');

$action = $_GET['action'] ?? 'stats';

if ($action === 'stats') {
    $total_gtv = 0; $active_merchants = 0; $pending_kyc = 0; $total_va = 0; $total_tx = 0; $success_tx = 0;

    try {
        $stmt = $db->query("SELECT SUM(amount) as total FROM transactions WHERE status = 'success'{$lens}");
        $total_gtv = $stmt->fetch()['total'] ?? 0;

        $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'merchant' AND is_suspended = 0");
        $active_merchants = $stmt->fetch()['count'] ?? 0;

        $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE is_kyc_verified = 2");
        $pending_kyc = $stmt->fetch()['count'] ?? 0;

        $stmt = $db->query("SELECT COUNT(*) as count FROM virtual_accounts");
        $total_va = $stmt->fetch()['count'] ?? 0;

        $stmt = $db->query("SELECT COUNT(*) as count FROM transactions WHERE 1=1{$lens}");
        $total_tx = $stmt->fetch()['count'] ?? 0;
        $stmt = $db->query("SELECT COUNT(*) as count FROM transactions WHERE status = 'success'{$lens}");
        $success_tx = $stmt->fetch()['count'] ?? 0;
    } catch (\Throwable $e) {

    $success_rate = $total_tx > 0 ? number_format(($success_tx / $total_tx) * 100, 1) : '100';

    echo json_encode([
        'total_gtv' => formatCurrency($total_gtv),
        'active_merchants' => $active_merchants,
        'pending_kyc' => $pending_kyc,
        'total_va' => $total_va,
        'success_rate' => $success_rate . '%'
    ]);
} elseif ($action === 'transactions') {
    $txs = [];
    try {
        $stmt = $db->query("SELECT t.*, u.business_name FROM transactions t JOIN users u ON t.user_id = u.id WHERE 1=1{$lens_t} ORDER BY t.created_at DESC LIMIT 10");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($results) {
            foreach ($results as $tx) {
                $tx['amount_formatted'] = formatCurrency($tx['amount']);
                $tx['created_at_formatted'] = date('H:i:s d M', strtotime($tx['created_at']));
                $txs[] = $tx;
            }
        }
    } catch (\Throwable $e) {
        error_log("AJAX Transactions Error: " . $e->getMessage());
    }
    echo json_encode($txs);
}
