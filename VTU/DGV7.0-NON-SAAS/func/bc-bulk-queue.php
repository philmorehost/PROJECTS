<?php
/**
 * bc-bulk-queue.php — Background Bulk Airtime/Data Processing
 *
 * Bulk submissions are enqueued here instead of being processed inline in the
 * HTTP request. cron/process_bulk_queue.php drains the queue independently of
 * the customer's browser/network connection.
 */

if (function_exists('bc_enqueue_bulk_batch')) return;

function bc_ensure_bulk_queue_schema($connection_server)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $check = mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_bulk_queue_items'");
    if ($check && mysqli_num_rows($check) == 0) {
        mysqli_query($connection_server, "CREATE TABLE sas_bulk_queue_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            username VARCHAR(100) NOT NULL,
            user_id INT NOT NULL,
            batch_number VARCHAR(20) NOT NULL,
            product_name VARCHAR(20) NOT NULL,
            phone_number VARCHAR(20) NOT NULL,
            isp VARCHAR(20) DEFAULT NULL,
            amount VARCHAR(20) DEFAULT NULL,
            data_type VARCHAR(30) DEFAULT NULL,
            quantity VARCHAR(50) DEFAULT NULL,
            purchase_method VARCHAR(15) NOT NULL DEFAULT 'WEB',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            claim_token VARCHAR(40) DEFAULT NULL,
            reference VARCHAR(50) DEFAULT NULL,
            response_desc VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_batch (batch_number),
            INDEX idx_status_queue (status, id)
        )");
    }

    // NOTE: legacy batches (pre-dating this migration) default to 'completed' so the
    // finalizer never re-notifies vendors about old batches that already finished
    // synchronously before this queue system existed. New batches always pass an
    // explicit status='pending' on insert (see bc_enqueue_bulk_batch()).
    $bp_cols = array(
        'status'        => "VARCHAR(20) NOT NULL DEFAULT 'completed'",
        'total_count'   => "INT NOT NULL DEFAULT 0",
        'success_count' => "INT NOT NULL DEFAULT 0",
        'failed_count'  => "INT NOT NULL DEFAULT 0",
        'ai_diagnosis'  => "TEXT DEFAULT NULL",
    );
    foreach ($bp_cols as $col => $def) {
        $c = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_bulk_product_purchase` LIKE '$col'");
        if ($c && mysqli_num_rows($c) == 0) {
            mysqli_query($connection_server, "ALTER TABLE `sas_bulk_product_purchase` ADD COLUMN `$col` $def");
        }
    }

    $check_notif = mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_user_notifications'");
    if ($check_notif && mysqli_num_rows($check_notif) == 0) {
        mysqli_query($connection_server, "CREATE TABLE sas_user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            username VARCHAR(100) NOT NULL,
            title VARCHAR(150) NOT NULL,
            message VARCHAR(255) NOT NULL,
            link VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_unread (vendor_id, username, is_read)
        )");
    }
}

/**
 * Write an in-app notification for a user (shown in the header bell dropdown).
 */
function bc_notify_user($connection_server, $vendor_id, $username, $title, $message, $link = '')
{
    bc_ensure_bulk_queue_schema($connection_server);
    mysqli_query($connection_server, "INSERT INTO sas_user_notifications (vendor_id, username, title, message, link) VALUES (
        '" . (int)$vendor_id . "',
        '" . mysqli_real_escape_string($connection_server, $username) . "',
        '" . mysqli_real_escape_string($connection_server, $title) . "',
        '" . mysqli_real_escape_string($connection_server, $message) . "',
        '" . mysqli_real_escape_string($connection_server, $link) . "'
    )");
}

/**
 * Queue a bulk batch for background processing.
 *
 * $items: array of ['phone' => ..., 'isp' => ..., 'amount' => ..., 'type' => ..., 'quantity' => ...]
 * Returns ['batch_number' => ..., 'total' => int]
 */
function bc_enqueue_bulk_batch($connection_server, $vendor_id, $username, $user_id, $product_name, $purchase_method, array $items)
{
    bc_ensure_bulk_queue_schema($connection_server);

    $batch_number = substr(str_shuffle("123456789012345678901234567890"), 0, 6);
    $vendor_id_esc = (int)$vendor_id;
    $user_id_esc = (int)$user_id;
    $username_esc = mysqli_real_escape_string($connection_server, $username);
    $product_name_esc = mysqli_real_escape_string($connection_server, $product_name);
    $purchase_method_esc = mysqli_real_escape_string($connection_server, strtoupper($purchase_method));

    $total = 0;
    foreach ($items as $item) {
        $phone_esc = mysqli_real_escape_string($connection_server, (string)($item['phone'] ?? ''));
        if (empty($phone_esc)) continue;
        $isp_esc = mysqli_real_escape_string($connection_server, (string)($item['isp'] ?? ''));
        $amount_esc = mysqli_real_escape_string($connection_server, (string)($item['amount'] ?? ''));
        $type_esc = mysqli_real_escape_string($connection_server, (string)($item['type'] ?? ''));
        $quantity_esc = mysqli_real_escape_string($connection_server, (string)($item['quantity'] ?? ''));

        mysqli_query($connection_server, "INSERT INTO sas_bulk_queue_items
            (vendor_id, username, user_id, batch_number, product_name, phone_number, isp, amount, data_type, quantity, purchase_method, status)
            VALUES ('$vendor_id_esc', '$username_esc', '$user_id_esc', '$batch_number', '$product_name_esc', '$phone_esc', '$isp_esc', '$amount_esc', '$type_esc', '$quantity_esc', '$purchase_method_esc', 'pending')");
        $total++;
    }

    if ($total > 0) {
        mysqli_query($connection_server, "INSERT INTO sas_bulk_product_purchase (vendor_id, username, product_name, batch_number, status, total_count) VALUES ('$vendor_id_esc', '$username_esc', '$product_name_esc', '$batch_number', 'pending', $total)");
    }

    return array('batch_number' => $batch_number, 'total' => $total);
}

/**
 * Live progress for a batch: combines queue-side pending/processing counts with
 * completed counts already recorded in sas_transactions (authoritative for done items).
 * Pass $username = null for vendor-admin views that scope by vendor_id only.
 */
function bc_get_bulk_batch_progress($connection_server, $vendor_id, $username, $batch_number)
{
    bc_ensure_bulk_queue_schema($connection_server);

    $vendor_id_esc = (int)$vendor_id;
    $batch_esc = mysqli_real_escape_string($connection_server, $batch_number);
    $username_filter = ($username !== null && $username !== '') ? " AND username='" . mysqli_real_escape_string($connection_server, $username) . "'" : "";

    $header = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_bulk_product_purchase WHERE vendor_id='$vendor_id_esc'$username_filter AND batch_number='$batch_esc' LIMIT 1"));

    $queue_counts = array('pending' => 0, 'processing' => 0, 'done' => 0);
    $q = mysqli_query($connection_server, "SELECT status, COUNT(*) as c FROM sas_bulk_queue_items WHERE vendor_id='$vendor_id_esc'$username_filter AND batch_number='$batch_esc' GROUP BY status");
    while ($q && $row = mysqli_fetch_assoc($q)) {
        $queue_counts[$row['status']] = (int)$row['c'];
    }

    $successful = 0;
    $successful = 0;
    $tq = mysqli_query($connection_server, "SELECT status, COUNT(*) as c FROM sas_transactions WHERE vendor_id='$vendor_id_esc'$username_filter AND batch_number='$batch_esc' GROUP BY status");
    while ($tq && $row = mysqli_fetch_assoc($tq)) {
        if ($row['status'] == 1) $successful += (int)$row['c'];
    }

    $actual_pending = $queue_counts['pending'] + $queue_counts['processing'];
    $failed = max(0, $queue_counts['done'] - $successful);
    if ($header && isset($header['total_count']) && (int)$header['total_count'] > 0) {
        $total = (int)$header['total_count'];
        if ($actual_pending == 0 && ($successful + $failed) < $total) {
            $failed = $total - $successful;
        }
    } else {
        $total = $successful + $failed + $actual_pending;
    }

    $batch_status = $header['status'] ?? ($actual_pending > 0 ? 'processing' : 'completed');
    if ($actual_pending == 0 && $total > 0) {
        $batch_status = 'completed';
    }

    return array(
        'batch_number' => $batch_number,
        'status'       => $batch_status,
        'total'        => $total,
        'successful'   => $successful,
        'failed'       => $failed,
        'pending'      => $actual_pending,
        'ai_diagnosis' => $header['ai_diagnosis'] ?? null,
    );
}
