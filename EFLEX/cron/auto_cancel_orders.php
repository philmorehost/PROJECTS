<?php
/**
 * ============================================================================
 * EFLEX — cron/auto_cancel_orders.php
 * ============================================================================
 *
 * Cancels unpaid orders (Pending / Awaiting Payment / Failed) that are older
 * than the window configured in Admin → Site Settings → Order Automation
 * (30 hours by default, adjustable in hours or days) and returns their stock
 * exactly once.
 *
 * Run it from the server / cPanel cron (recommended, every 5–15 minutes):
 *
 *     /usr/local/bin/php /home/USER/public_html/cron/auto_cancel_orders.php
 *
 * The exact path for your account is shown in the admin panel.
 *
 * It can also be triggered over HTTP (some hosts only offer "URL" cron jobs):
 *
 *     https://your-shop.com/cron/auto_cancel_orders.php?key=YOUR_KEY
 *
 * where YOUR_KEY is the one shown/copied in the admin panel (stored in the
 * `auto_cancel_cron_key` setting). A logged-in admin can always trigger it.
 *
 * Nothing happens when the feature is switched off or when no order is old
 * enough, so it is safe to run as often as you like.
 */

// ---------------------------------------------------------------------------
// CLI always allowed; web requests need an admin session or the cron key.
// ---------------------------------------------------------------------------
$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/order_stock.php';
require_once __DIR__ . '/../includes/order_cancellation.php';

$log = function ($line) use ($is_cli) {
    if ($is_cli) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL;
    } else {
        echo htmlspecialchars($line) . "<br>\n";
    }
};

if (!$is_cli) {
    header('Content-Type: text/html; charset=utf-8');

    $authorised = false;

    if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
        require_once __DIR__ . '/../includes/auth_check.php';
        $authorised = has_permission('manage_orders') || has_permission('manage_site_settings');
    }

    if (!$authorised) {
        $expected_key = (string)eflex_get_setting($mysqli, 'auto_cancel_cron_key', '');
        $given_key = isset($_GET['key']) ? (string)$_GET['key'] : '';
        $authorised = ($expected_key !== '' && $given_key !== '' && hash_equals($expected_key, $given_key));
    }

    if (!$authorised) {
        http_response_code(403);
        echo 'Forbidden. Add ?key=YOUR_CRON_KEY (see Admin → Site Settings → Order Automation) or sign in as an administrator.';
        exit;
    }
}

// ---------------------------------------------------------------------------
// Only one sweep at a time (a slow sweep + a cron tick must not overlap).
// ---------------------------------------------------------------------------
$db_row = $mysqli->query("SELECT DATABASE() AS db")->fetch_assoc();
$lock_name = 'eflex_auto_cancel_' . ($db_row['db'] ?? 'default');
$has_lock = false;
if ($lock = $mysqli->query("SELECT GET_LOCK('" . $mysqli->real_escape_string($lock_name) . "', 0) AS l")) {
    $row = $lock->fetch_assoc();
    $has_lock = !empty($row['l']);
    $lock->free();
}

if (!$has_lock) {
    $log('Another automatic cancellation run is still in progress — skipping this one.');
    exit(0);
}

try {
    $summary = eflex_auto_cancel_stale_orders($mysqli);

    if (empty($summary['enabled'])) {
        $log('Automatic cancellation is disabled in Site Settings — nothing to do.');
    } elseif ($summary['cancelled'] > 0) {
        $log($summary['cancelled'] . ' order(s) cancelled' . ($summary['failed'] ? ' (' . $summary['failed'] . ' could not be cancelled)' : '') . '.');
        $log('Orders: #' . implode(', #', $summary['order_ids']));
        $log('Window: ' . $summary['label'] . ' (cutoff ' . $summary['cutoff'] . ').');
        if ($summary['notified'] > 0) {
            $log($summary['notified'] . ' notification email(s) sent.');
        }
    } else {
        $log('No unpaid order older than ' . $summary['label'] . ' — nothing to cancel.');
    }

    eflex_set_setting($mysqli, 'auto_cancel_last_run', (string)time());

    if (!$is_cli) {
        echo '<p>Done.</p>';
    }
} catch (Throwable $e) {
    error_log('EFLEX auto-cancel cron failed: ' . $e->getMessage());
    $log('ERROR: ' . $e->getMessage());
    if ($is_cli) {
        exit(1);
    }
} finally {
    $mysqli->query("SELECT RELEASE_LOCK('" . $mysqli->real_escape_string($lock_name) . "')");
}
