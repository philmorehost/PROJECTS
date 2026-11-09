<?php
/**
 * ============================================================================
 * EFLEX — Automatic cancellation of unpaid orders
 * ============================================================================
 *
 * Unpaid orders (Pending / Awaiting Payment / Failed) keep their stock reserved
 * forever when a customer simply walks away, so the shop ends up with a long
 * list of pending orders and products that look out of stock. This file cancels
 * those orders after the admin-configured window (30 hours by default) and
 * returns their stock exactly once (see includes/order_stock.php).
 *
 * How it runs
 * -----------
 * 1. `cron/auto_cancel_orders.php` — the reliable way (server cron, every 5–15
 *    minutes, works even when nobody visits the shop). The admin panel shows the
 *    exact command to paste into cPanel.
 * 2. Lazily, on admin page loads (`eflex_run_auto_cancel_if_due()`), throttled
 *    to once every 15 minutes, so the feature also works on shared hosting where
 *    the shop owner never sets up a cron job.
 *
 * Both entry points funnel into `eflex_auto_cancel_stale_orders()`, which is
 * idempotent: the order row is locked, the `stock_restored` flag is flipped 0→1
 * inside the same transaction and stock is only added by the transaction that
 * wins that flip.
 */

if (!defined('EFLEX_ORDER_CANCELLATION_ENGINE')) {
    define('EFLEX_ORDER_CANCELLATION_ENGINE', true);
}

require_once __DIR__ . '/order_stock.php';

if (!defined('EFLEX_AUTO_CANCEL_THROTTLE')) {
    // Minimum number of seconds between two lazy (page-load) sweeps.
    define('EFLEX_AUTO_CANCEL_THROTTLE', 900);
}

/**
 * Absolute URL of the shop, for links inside emails. Works from cron (CLI) too,
 * where $_SERVER['HTTP_HOST'] does not exist.
 */
function eflex_site_url($mysqli)
{
    $configured = eflex_get_setting($mysqli, 'site_url', '');
    if (!empty($configured)) {
        return rtrim($configured, '/');
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    }

    return '';
}

/**
 * Cancel every unpaid order older than the configured window.
 *
 * @param array|null $settings  Pre-loaded settings (optional).
 * @param array      $options   limit (max orders handled per call), notify (bool),
 *                              reason (string stored on the order).
 *
 * @return array Summary:
 *   enabled, label, cutoff, candidates, cancelled, failed, order_ids, skipped
 */
function eflex_auto_cancel_stale_orders($mysqli, $settings = null, $options = array())
{
    $limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 200;

    $config = eflex_cancel_settings($mysqli, $settings);
    $summary = array(
        'enabled'    => $config['enabled'],
        'label'      => $config['label'],
        'hours'      => $config['hours'],
        'cutoff'     => null,
        'candidates' => 0,
        'cancelled'  => 0,
        'failed'     => 0,
        'order_ids'  => array(),
        'skipped'    => 0,
        'notified'   => 0,
        'message'    => '',
    );

    if (empty($config['enabled'])) {
        $summary['message'] = 'Automatic cancellation is disabled in Site Settings.';
        return $summary;
    }

    // Compute the cutoff in PHP as well, so the admin UI and the emails use the
    // exact same moment as the query (MySQL and PHP can sit in different zones,
    // so the query uses MySQL's own NOW()/DATE_SUB).
    $summary['cutoff'] = date('Y-m-d H:i:s', time() - ($config['hours'] * 3600));

    $statuses = eflex_unpaid_order_statuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    // Only orders that still hold reserved stock are interesting; already
    // cancelled/restored ones need nothing.
    $sql = "SELECT id, user_id FROM orders
            WHERE status IN ($placeholders)
              AND created_at IS NOT NULL
              AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY created_at ASC
            LIMIT ?";

    $candidates = array();
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        $summary['message'] = 'Could not read the order list.';
        return $summary;
    }
    $types = str_repeat('s', count($statuses)) . 'ii';
    $params = array_merge($statuses, array($config['hours'], $limit));
    $bind = array($types);
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array(array($stmt, 'bind_param'), $bind);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $candidates[] = $row;
        }
    }
    $stmt->close();

    $summary['candidates'] = count($candidates);

    if (empty($candidates)) {
        $summary['message'] = 'No orders older than ' . $config['label'] . ' are waiting for payment.';
        return $summary;
    }

    $reason = isset($options['reason']) ? $options['reason'] : ('Automatically cancelled: unpaid after ' . $config['label']);

    // user_id => list of cancelled order ids (for one email per customer).
    $by_user = array();

    foreach ($candidates as $candidate) {
        $order_id = (int)$candidate['id'];
        $cancel = eflex_cancel_order($mysqli, $order_id, $reason);

        if (!empty($cancel['ok'])) {
            $summary['cancelled']++;
            $summary['order_ids'][] = $order_id;
            $user_id = (int)$candidate['user_id'];
            if (!isset($by_user[$user_id])) {
                $by_user[$user_id] = array();
            }
            $by_user[$user_id][] = $order_id;
        } else {
            $summary['failed']++;
        }
    }

    $summary['message'] = $summary['cancelled'] > 0
        ? $summary['cancelled'] . ' unpaid order(s) cancelled after ' . $config['label'] . ' and their stock returned.'
        : 'No orders could be cancelled.';

    // ---- Notifications (never inside the order transactions) -----------------
    $notify = isset($options['notify']) ? (bool)$options['notify'] : true;
    if ($notify && $summary['cancelled'] > 0) {
        try {
            $summary['notified'] = eflex_notify_auto_cancellations($mysqli, $by_user, $config, $summary['order_ids']);
        } catch (Throwable $e) {
            error_log('EFLEX auto-cancel notification failed: ' . $e->getMessage());
        }
    }

    return $summary;
}

/**
 * Email the affected customers (one email each) and one summary to the shop.
 */
function eflex_notify_auto_cancellations($mysqli, $by_user, $config, $order_ids)
{
    $sent = 0;

    if (!function_exists('send_email')) {
        require_once __DIR__ . '/send_email.php';
    }

    $site_name = eflex_get_setting($mysqli, 'site_name', 'Eflex');
    $site_url = eflex_site_url($mysqli);
    $orders_link = $site_url !== '' ? $site_url . '/my_orders.php' : 'your account → My Orders';

    // Customer emails
    if (!empty($config['notify_customer'])) {
        $stmt = $mysqli->prepare("SELECT id, username, email FROM users WHERE id = ?");
        foreach ($by_user as $user_id => $ids) {
            if (!$stmt) {
                break;
            }
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if (method_exists($stmt, 'free_result')) {
                $stmt->free_result();
            }

            if (!$row || empty($row['email'])) {
                continue;
            }

            $list = implode(', ', array_map(function ($id) {
                return '#' . (int)$id;
            }, $ids));

            $subject = 'Your order was cancelled — ' . $site_name;
            $body = '<h1>Unpaid order cancelled</h1>'
                  . '<p>Hi ' . htmlspecialchars($row['username']) . ',</p>'
                  . '<p>Order(s) ' . htmlspecialchars($list) . ' were not paid within '
                  . htmlspecialchars($config['label']) . ', so they have been cancelled automatically '
                  . 'and the items are back in stock.</p>'
                  . '<p>If you still want the item(s), you are welcome to place a new order.</p>'
                  . '<p><a href="' . htmlspecialchars($orders_link) . '">View my orders</a></p>';

            if (send_email($row['email'], $subject, $body)) {
                $sent++;
            }
        }
        if ($stmt) {
            $stmt->close();
        }
    }

    // Summary for the shop owner
    $admin_email = eflex_get_setting($mysqli, 'admin_notification_email', '');
    if (empty($admin_email)) {
        $admin_email = eflex_get_setting($mysqli, 'from_email', '');
    }
    if (!empty($admin_email)) {
        $list = implode(', ', array_map(function ($id) {
            return '#' . (int)$id;
        }, $order_ids));
        $subject = count($order_ids) . ' unpaid order(s) cancelled — ' . $site_name;
        $body = '<h1>Unpaid orders cancelled automatically</h1>'
              . '<p>' . count($order_ids) . ' order(s) were not paid within '
              . htmlspecialchars($config['label']) . ' and have been cancelled. Their stock has been returned.</p>'
              . '<p><strong>Orders:</strong> ' . htmlspecialchars($list) . '</p>'
              . ($site_url !== '' ? '<p><a href="' . htmlspecialchars($site_url . '/admin/manage_orders.php') . '">Open the order list</a></p>' : '');
        if (send_email($admin_email, $subject, $body)) {
            $sent++;
        }
    }

    return $sent;
}

/**
 * Cheap "is a sweep worth trying?" check used on page loads.
 */
function eflex_auto_cancel_due($mysqli)
{
    $last_run = (int)eflex_get_setting($mysqli, 'auto_cancel_last_run', '0');

    return (time() - $last_run) >= EFLEX_AUTO_CANCEL_THROTTLE;
}

/**
 * Run the sweep from a normal page request, at most once per request and at
 * most once every EFLEX_AUTO_CANCEL_THROTTLE seconds.
 *
 * Never throws: a failing sweep must not break the page that triggered it.
 *
 * @return array|null The summary, or null when the sweep was skipped.
 */
function eflex_run_auto_cancel_if_due($mysqli, $settings = null, $options = array())
{
    static $already_ran = false;

    if ($already_ran) {
        return null;
    }
    $already_ran = true;

    // Keep page loads fast: a page-triggered sweep handles a smaller batch than
    // the cron job (which has all the time it needs).
    if (!isset($options['limit'])) {
        $options['limit'] = 50;
    }

    try {
        $config = eflex_cancel_settings($mysqli, $settings);
        if (empty($config['enabled'])) {
            return null;
        }
        if (!eflex_auto_cancel_due($mysqli)) {
            return null;
        }

        $summary = eflex_auto_cancel_stale_orders($mysqli, $settings, $options);
        eflex_set_setting($mysqli, 'auto_cancel_last_run', (string)time());

        return $summary;
    } catch (Throwable $e) {
        error_log('EFLEX auto-cancel sweep failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Number of unpaid orders that are already past the window (dashboard badge).
 */
function eflex_count_overdue_orders($mysqli, $settings = null)
{
    $config = eflex_cancel_settings($mysqli, $settings);
    if (empty($config['enabled'])) {
        return 0;
    }

    $statuses = eflex_unpaid_order_statuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT COUNT(*) FROM orders WHERE status IN ($placeholders) AND created_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $types = str_repeat('s', count($statuses)) . 'i';
    $params = array_merge($statuses, array($config['hours']));
    $bind = array($types);
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array(array($stmt, 'bind_param'), $bind);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return $row ? (int)$row[0] : 0;
}
