<?php
declare(strict_types=1);

/**
 * Scheduled Background Task Runner (`* * * * * php artisan schedule:run` or `php bin/cron.php`):
 * 1. Polls pending/detected blockchain invoices via BlockchainNodeService
 * 2. Marks expired invoices
 * 3. Clears expired cPHulk IP blocks
 * 4. Flushes queued email notifications
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Http\Controllers\GatewayController;
use App\Services\NotificationService;
use App\Support\Database;

if (!bp_installed()) {
    echo "[BlockPayne] Skipping cron: application not installed yet.\n";
    exit(0);
}

$pdo = Database::connection();
$gw = new GatewayController();

// 1. Poll active pending/detected invoices
$stmt = $pdo->query("SELECT invoice_id FROM invoices WHERE status IN ('pending', 'detected') ORDER BY id ASC LIMIT 50");
$polled = 0;
foreach ($stmt->fetchAll() as $row) {
    $gw->getAndPollInvoice((string) $row['invoice_id'], true);
    $polled++;
}

// 2. Clear expired IP blocks
$clr = $pdo->prepare("UPDATE ip_firewall SET rule_type = 'tracker', expires_at = NULL, updated_at = ? WHERE rule_type = 'block' AND expires_at IS NOT NULL AND expires_at < ?");
$clr->execute([bp_now(), bp_now()]);

// 3. Process queued email notifications
$mailed = NotificationService::processQueue(30);

echo sprintf("[BlockPayne %s] Polled %d invoice(s), dispatched %d notification(s).\n", bp_now(), $polled, $mailed);
