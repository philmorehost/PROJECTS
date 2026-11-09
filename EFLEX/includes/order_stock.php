<?php
/**
 * ============================================================================
 * EFLEX — Order stock & cancellation helpers
 * ============================================================================
 *
 * Why this file exists
 * --------------------
 * Stock is deducted from `products` / `product_variants` the moment an order is
 * placed (see checkout.php) and has to be put back exactly ONCE when the order
 * is cancelled, deleted or re-opened.
 *
 * The original cancel code did a read-then-write:
 *
 *      SELECT status, stock_restored FROM orders ...      <- no lock
 *      ... UPDATE ... stock = stock + qty ...
 *      UPDATE orders SET status = 'Cancelled', stock_restored = 1 ...
 *
 * Two requests for the same order (a customer double-tapping "Cancel", the
 * admin clicking twice, an automatic sweep running while the customer cancels,
 * …) both read `stock_restored = 0`, both add the quantities back and both then
 * mark the order cancelled. The customer sees "each cancel returns double to
 * stock" and the shop ends up with phantom inventory.
 *
 * Every path now goes through this helper which:
 *   1. runs inside a transaction,
 *   2. locks the order row with SELECT ... FOR UPDATE,
 *   3. checks `stock_restored` *under that lock*,
 *   4. re-checks the flag in the UPDATE (WHERE stock_restored = 0) and verifies
 *      that the row was really updated (affected_rows) before committing.
 *
 * So a second, concurrent attempt sees `stock_restored = 1` (or 0 affected
 * rows) and does nothing. Restocking is idempotent.
 *
 * The file also owns the "auto cancel unpaid orders" settings, because the
 * window (30 hours by default, admin adjustable in hours or days) and the
 * status list belong to the same piece of logic.
 */

if (!defined('EFLEX_ORDER_STOCK_HELPERS')) {
    define('EFLEX_ORDER_STOCK_HELPERS', true);
}

if (!class_exists('EflexOutOfStockException')) {
    /**
     * Thrown when stock cannot be deducted because another shopper took the
     * last unit between the cart check and the INSERT/UPDATE.
     */
    class EflexOutOfStockException extends RuntimeException
    {
        public $eflexProductName = '';

        public function __construct($message, $productName = '')
        {
            parent::__construct($message);
            $this->eflexProductName = (string)$productName;
        }
    }
}

/**
 * Order statuses that mean "the customer has not paid".
 *
 * Stock for these has already been deducted at checkout, so cancelling them
 * must return it. `Processing` is deliberately NOT here: it means the customer
 * uploaded a payment proof and a human has to look at it.
 */
function eflex_unpaid_order_statuses()
{
    return array('Pending', 'Awaiting Payment', 'Failed');
}

/**
 * Statuses an administrator may cancel from the order detail page.
 *
 * Staff sometimes have to cancel a paid order (refund, customer request, out of
 * stock after the fact). The idempotent restock still guarantees the stock is
 * returned at most once, so any status except `Cancelled` is accepted here.
 */
function eflex_admin_cancellable_statuses()
{
    return array('Pending', 'Awaiting Payment', 'Failed', 'Processing', 'Completed');
}

/**
 * Read one row from the `settings` table.
 *
 * @return string|null Null when the key is missing (callers apply a default).
 */
function eflex_get_setting($mysqli, $key, $default = null)
{
    $stmt = $mysqli->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row || $row['setting_value'] === null || $row['setting_value'] === '') {
        return $default;
    }

    return $row['setting_value'];
}

/**
 * Insert or update a row in the `settings` table.
 */
function eflex_set_setting($mysqli, $key, $value)
{
    $stmt = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if (!$stmt) {
        return false;
    }
    $value = (string)$value;
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool)$ok;
}

/**
 * "30 hours" / "1 day" — used in the admin panel and in customer emails.
 */
function eflex_format_window($value, $unit)
{
    $value = (int)$value;
    if ($unit === 'days') {
        return $value . ' ' . ($value === 1 ? 'day' : 'days');
    }

    return $value . ' ' . ($value === 1 ? 'hour' : 'hours');
}

/**
 * The admin-configured automatic cancellation window.
 *
 * Settings (Site Settings → Order Automation):
 *   auto_cancel_pending_enabled   '1' / '0'          (missing = enabled)
 *   auto_cancel_pending_after     positive integer   (default 30)
 *   auto_cancel_pending_unit      'hours' | 'days'   (default hours)
 *   auto_cancel_notify_customer   '1' / '0'          (missing = enabled)
 *
 * @param array|null $settings Optional pre-loaded settings (site pages already
 *                             load them) to avoid extra queries.
 */
function eflex_cancel_settings($mysqli, $settings = null)
{
    $keys = array(
        'auto_cancel_pending_enabled',
        'auto_cancel_pending_after',
        'auto_cancel_pending_unit',
        'auto_cancel_notify_customer',
    );

    $values = array();
    foreach ($keys as $key) {
        if (is_array($settings) && array_key_exists($key, $settings)) {
            $values[$key] = $settings[$key];
        } elseif (is_array($settings)) {
            // The whole settings table was loaded and this key is not in it,
            // so it has never been saved: use the default (no extra query).
            $values[$key] = null;
        } else {
            $values[$key] = eflex_get_setting($mysqli, $key, null);
        }
    }

    // Enabled by default: the shop asked for a 30 hour rule out of the box.
    $enabled = true;
    if ($values['auto_cancel_pending_enabled'] !== null) {
        $enabled = !in_array((string)$values['auto_cancel_pending_enabled'], array('', '0'), true);
    }

    $notify_customer = true;
    if ($values['auto_cancel_notify_customer'] !== null) {
        $notify_customer = !in_array((string)$values['auto_cancel_notify_customer'], array('', '0'), true);
    }

    $unit = (isset($values['auto_cancel_pending_unit']) && $values['auto_cancel_pending_unit'] === 'days') ? 'days' : 'hours';

    $value = (isset($values['auto_cancel_pending_after']) && $values['auto_cancel_pending_after'] !== null)
        ? (int)$values['auto_cancel_pending_after']
        : 30;
    if ($value < 1) {
        $value = 30;
    }
    // Keep the window sane (max 365 days / 8760 hours).
    $max = ($unit === 'days') ? 365 : 8760;
    if ($value > $max) {
        $value = $max;
    }

    $hours = ($unit === 'days') ? $value * 24 : $value;

    return array(
        'enabled'         => $enabled,
        'value'           => $value,
        'unit'            => $unit,
        'hours'           => $hours,
        'notify_customer' => $notify_customer,
        'label'           => eflex_format_window($value, $unit),
    );
}

/**
 * Does the `orders` table have one of the optional audit columns?
 *
 * db_setup.php adds `cancelled_at` / `cancel_reason`, but on old deployments the
 * schema file may not have been re-run yet, so every query touching them is
 * guarded and the feature keeps working without them.
 */
function eflex_orders_has_column($mysqli, $column)
{
    static $cache = array();

    $allowed = array('cancelled_at', 'cancel_reason');
    if (!in_array($column, $allowed, true)) {
        return false;
    }
    if (isset($cache[$column])) {
        return $cache[$column];
    }

    $result = $mysqli->query("SHOW COLUMNS FROM `orders` LIKE '" . $column . "'");
    $cache[$column] = ($result && $result->num_rows > 0);

    return $cache[$column];
}

/**
 * Load the order items with everything needed to decide where stock belongs.
 *
 * A row with `variant_id` set goes back to `product_variants`; a row without it
 * goes back to `products` (only when the product is not variant-managed).
 */
function eflex_load_order_items($mysqli, $order_id)
{
    $sql = "SELECT oi.product_id,
                   oi.variant_id,
                   oi.quantity,
                   p.name AS product_name,
                   COALESCE(p.has_variants, 0) AS has_variants,
                   pv.id AS variant_exists
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            LEFT JOIN product_variants pv ON pv.id = oi.variant_id
            WHERE oi.order_id = ?
            ORDER BY oi.id";

    $items = array();
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return $items;
    }
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
    $stmt->close();

    return $items;
}

/**
 * Put the quantities of the given order items back into stock.
 *
 * Must be called inside a transaction that already holds the order row lock.
 *
 * @return array array($restored, $skipped) — human readable entries.
 */
function eflex_restore_stock_for_items($mysqli, $items)
{
    $restored = array();
    $skipped  = array();

    foreach ($items as $item) {
        $quantity = (int)$item['quantity'];
        $name     = isset($item['product_name']) ? $item['product_name'] : ('product #' . (int)$item['product_id']);

        if ($quantity <= 0) {
            $skipped[] = array('name' => $name, 'quantity' => $quantity, 'reason' => 'invalid quantity on the order line');
            continue;
        }

        $target = '';

        if (!empty($item['variant_id'])) {
            // Variant-managed stock.
            $stmt = $mysqli->prepare("UPDATE product_variants SET stock = COALESCE(stock, 0) + ? WHERE id = ?");
            if ($stmt) {
                $variant_id = (int)$item['variant_id'];
                $stmt->bind_param('ii', $quantity, $variant_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected > 0) {
                    $target = 'variant';
                }
            }

            if ($target === '') {
                // The variant has been deleted in the meantime — fall back to
                // the parent product when it is not variant-managed anymore.
                $stmt = $mysqli->prepare("UPDATE products SET stock = COALESCE(stock, 0) + ? WHERE id = ? AND has_variants = 0");
                if ($stmt) {
                    $product_id = (int)$item['product_id'];
                    $stmt->bind_param('ii', $quantity, $product_id);
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();
                    if ($affected > 0) {
                        $target = 'product (variant removed)';
                    }
                }
            }
        } else {
            $stmt = $mysqli->prepare("UPDATE products SET stock = COALESCE(stock, 0) + ? WHERE id = ? AND has_variants = 0");
            if ($stmt) {
                $product_id = (int)$item['product_id'];
                $stmt->bind_param('ii', $quantity, $product_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected > 0) {
                    $target = 'product';
                }
            }
        }

        if ($target !== '') {
            $restored[] = array('name' => $name, 'quantity' => $quantity, 'target' => $target);
        } else {
            $skipped[] = array(
                'name'     => $name,
                'quantity' => $quantity,
                'reason'   => 'the product is now managed by variants, so its stock is tracked per variant',
            );
        }
    }

    return array($restored, $skipped);
}

/**
 * Take the quantities of the given order items out of stock again.
 *
 * Used when a cancelled order is re-opened: the stock returned on cancellation
 * has to be reserved again. Refuses (returns a shortfall) instead of silently
 * going negative.
 *
 * @return array array($taken, $skipped, $shortfall)
 */
function eflex_deduct_stock_for_items($mysqli, $items)
{
    $taken     = array();
    $skipped   = array();
    $shortfall = array();

    foreach ($items as $item) {
        $quantity = (int)$item['quantity'];
        $name     = isset($item['product_name']) ? $item['product_name'] : ('product #' . (int)$item['product_id']);

        if ($quantity <= 0) {
            continue;
        }

        $done = false;

        if (!empty($item['variant_id'])) {
            $stmt = $mysqli->prepare("UPDATE product_variants SET stock = COALESCE(stock, 0) - ? WHERE id = ? AND COALESCE(stock, 0) >= ?");
            if ($stmt) {
                $variant_id = (int)$item['variant_id'];
                $stmt->bind_param('iii', $quantity, $variant_id, $quantity);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                $done = ($affected > 0);
            }
        } else {
            $stmt = $mysqli->prepare("UPDATE products SET stock = COALESCE(stock, 0) - ? WHERE id = ? AND has_variants = 0 AND COALESCE(stock, 0) >= ?");
            if ($stmt) {
                $product_id = (int)$item['product_id'];
                $stmt->bind_param('iii', $quantity, $product_id, $quantity);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected > 0) {
                    $done = true;
                } elseif (!empty($item['has_variants'])) {
                    // Stock lives on the variants, nothing to deduct here.
                    $skipped[] = array('name' => $name, 'quantity' => $quantity, 'reason' => 'stock is tracked per variant');
                    $done = true;
                }
            }
        }

        if ($done) {
            $taken[] = array('name' => $name, 'quantity' => $quantity);
        } else {
            $shortfall[] = array('name' => $name, 'quantity' => $quantity);
        }
    }

    return array($taken, $skipped, $shortfall);
}

/**
 * Restock one order while the caller already holds its row lock.
 *
 * @return array ['restocked'=>bool, 'already'=>bool, 'restored'=>[], 'skipped'=>[], 'found'=>bool]
 */
function eflex_restore_stock_locked($mysqli, $order_id)
{
    $result = array(
        'found'     => false,
        'restocked' => false,
        'already'   => false,
        'restored'  => array(),
        'skipped'   => array(),
    );

    $stmt = $mysqli->prepare("SELECT id, COALESCE(stock_restored, 0) AS stock_restored FROM orders WHERE id = ? FOR UPDATE");
    if (!$stmt) {
        throw new RuntimeException('Could not lock the order row.');
    }
    $order_id = (int)$order_id;
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $result_row = $stmt->get_result();
    $order = $result_row ? $result_row->fetch_assoc() : null;
    $stmt->close();

    if (!$order) {
        return $result;
    }
    $result['found'] = true;

    if ((int)$order['stock_restored'] === 1) {
        $result['already'] = true;
        return $result;
    }

    $items = eflex_load_order_items($mysqli, $order_id);
    list($restored, $skipped) = eflex_restore_stock_for_items($mysqli, $items);

    // The flag is the single source of truth: only the transaction that flips it
    // 0 -> 1 is allowed to add stock back.
    $stmt = $mysqli->prepare("UPDATE orders SET stock_restored = 1 WHERE id = ? AND COALESCE(stock_restored, 0) = 0");
    if (!$stmt) {
        throw new RuntimeException('Could not update the stock_restored flag.');
    }
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $marked = $stmt->affected_rows;
    $stmt->close();

    if ($marked < 1) {
        // Somebody else restored it first: undo our additions.
        throw new RuntimeException('Stock for this order was restored by another request.');
    }

    $result['restocked'] = true;
    $result['restored']  = $restored;
    $result['skipped']   = $skipped;

    return $result;
}

/**
 * Restock an order in its own transaction (used by "delete order").
 *
 * Safe to call for an order that was already restocked: nothing happens.
 */
function eflex_restore_order_stock($mysqli, $order_id)
{
    $result = array('ok' => false, 'restocked' => false, 'already' => false, 'restored' => array(), 'skipped' => array(), 'message' => '');

    $mysqli->begin_transaction();
    try {
        $locked = eflex_restore_stock_locked($mysqli, (int)$order_id);
        $mysqli->commit();

        $result['ok']        = true;
        $result['restocked'] = $locked['restocked'];
        $result['already']   = $locked['already'];
        $result['restored']  = $locked['restored'];
        $result['skipped']   = $locked['skipped'];
        $result['message']   = $locked['found'] ? '' : 'Order not found.';
        $result['ok']        = $locked['found'];
    } catch (Throwable $e) {
        $mysqli->rollback();
        $result['message'] = $e->getMessage();
    }

    return $result;
}

/**
 * Cancel an order and return its stock — the only supported way to cancel.
 *
 * @param string     $reason   Stored in `orders.cancel_reason` (when available).
 * @param array|null $statuses Statuses allowed to be cancelled. Defaults to the
 *                             unpaid list (Pending / Awaiting Payment / Failed).
 *
 * @return array [
 *   ok, changed, message, status,
 *   restocked, already_restored, restored[], skipped[]
 * ]
 */
function eflex_cancel_order($mysqli, $order_id, $reason = 'Cancelled', $statuses = null)
{
    $order_id = (int)$order_id;
    $result = array(
        'ok'               => false,
        'changed'          => false,
        'message'          => '',
        'status'           => '',
        'restocked'        => false,
        'already_restored' => false,
        'restored'         => array(),
        'skipped'          => array(),
    );

    if ($order_id < 1) {
        $result['message'] = 'Invalid order.';
        return $result;
    }

    if (!is_array($statuses) || empty($statuses)) {
        $statuses = eflex_unpaid_order_statuses();
    }

    $has_audit = eflex_orders_has_column($mysqli, 'cancelled_at') && eflex_orders_has_column($mysqli, 'cancel_reason');

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("SELECT id, status, COALESCE(stock_restored, 0) AS stock_restored FROM orders WHERE id = ? FOR UPDATE");
        if (!$stmt) {
            throw new RuntimeException('Could not lock the order row.');
        }
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $result_row = $stmt->get_result();
        $order = $result_row ? $result_row->fetch_assoc() : null;
        $stmt->close();

        if (!$order) {
            $mysqli->rollback();
            $result['message'] = 'Order not found.';
            return $result;
        }

        $result['status'] = $order['status'];

        if (!in_array($order['status'], $statuses, true)) {
            $mysqli->rollback();
            $result['message'] = 'This order can no longer be cancelled (status: ' . $order['status'] . ').';
            return $result;
        }

        $locked = eflex_restore_stock_locked($mysqli, $order_id);
        $result['restocked']        = $locked['restocked'];
        $result['already_restored'] = $locked['already'];
        $result['restored']         = $locked['restored'];
        $result['skipped']          = $locked['skipped'];

        $set = "status = 'Cancelled', stock_restored = 1";
        $types = '';
        $params = array();
        if ($has_audit) {
            $set .= ", cancelled_at = NOW(), cancel_reason = ?";
            $types .= 's';
            $reason_text = (string)$reason;
            // mbstring is optional on some shared hosts.
            $params[] = function_exists('mb_substr') ? mb_substr($reason_text, 0, 190) : substr($reason_text, 0, 190);
        }
        $sql = "UPDATE orders SET " . $set . " WHERE id = ? AND (status IN (" . implode(',', array_fill(0, count($statuses), '?')) . "))";
        $types .= 'i' . str_repeat('s', count($statuses));
        $params[] = $order_id;
        foreach ($statuses as $status) {
            $params[] = $status;
        }

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Could not update the order status.');
        }
        $bind = array($types);
        foreach ($params as $index => $value) {
            $bind[] = &$params[$index];
        }
        call_user_func_array(array($stmt, 'bind_param'), $bind);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed < 1) {
            // The order moved on (paid, already cancelled …) between the lock and
            // the update. Roll back so the stock additions are undone.
            throw new RuntimeException('This order can no longer be cancelled.');
        }

        $mysqli->commit();

        $result['ok']      = true;
        $result['changed'] = true;
        $result['status']  = 'Cancelled';

        return $result;
    } catch (Throwable $e) {
        $mysqli->rollback();
        $result['message'] = $e->getMessage();
        $result['restocked'] = false;
        $result['restored']  = array();
        $result['skipped']   = array();

        return $result;
    }
}

/**
 * Re-open a cancelled order whose stock was returned.
 *
 * The stock is reserved again (deducted) so re-opening cannot be used to create
 * free inventory. Aborts when there is not enough stock left.
 *
 * @return array ['ok'=>bool, 'changed'=>bool, 'message'=>string, 'shortfall'=>[]]
 */
function eflex_reopen_order($mysqli, $order_id)
{
    $order_id = (int)$order_id;
    $result = array('ok' => false, 'changed' => false, 'message' => '', 'shortfall' => array());

    if ($order_id < 1) {
        $result['message'] = 'Invalid order.';
        return $result;
    }

    $has_audit = eflex_orders_has_column($mysqli, 'cancelled_at') && eflex_orders_has_column($mysqli, 'cancel_reason');

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("SELECT id, status, COALESCE(stock_restored, 0) AS stock_restored FROM orders WHERE id = ? FOR UPDATE");
        if (!$stmt) {
            throw new RuntimeException('Could not lock the order row.');
        }
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $result_row = $stmt->get_result();
        $order = $result_row ? $result_row->fetch_assoc() : null;
        $stmt->close();

        if (!$order) {
            $mysqli->rollback();
            $result['message'] = 'Order not found.';
            return $result;
        }

        if ((int)$order['stock_restored'] === 0) {
            // Stock was never given back (e.g. the order was created after the
            // restock flag existed), so there is nothing to reserve again.
            $mysqli->commit();
            $result['ok'] = true;
            $result['message'] = 'Stock was never restored for this order, nothing to reserve again.';
            return $result;
        }

        $items = eflex_load_order_items($mysqli, $order_id);
        list($taken, $skipped, $shortfall) = eflex_deduct_stock_for_items($mysqli, $items);

        if (!empty($shortfall)) {
            $mysqli->rollback();
            $names = array();
            foreach ($shortfall as $row) {
                $names[] = $row['name'] . ' (needs ' . (int)$row['quantity'] . ')';
            }
            $result['shortfall'] = $shortfall;
            $result['message'] = 'Not enough stock to re-open this order: ' . implode(', ', $names) . '. Restock the item(s) first.';
            return $result;
        }

        $set = "stock_restored = 0";
        if ($has_audit) {
            $set .= ", cancelled_at = NULL, cancel_reason = NULL";
        }
        $stmt = $mysqli->prepare("UPDATE orders SET " . $set . " WHERE id = ? AND COALESCE(stock_restored, 0) = 1");
        if (!$stmt) {
            throw new RuntimeException('Could not reset the stock_restored flag.');
        }
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed < 1) {
            throw new RuntimeException('This order was already re-opened by another request.');
        }

        $mysqli->commit();

        $result['ok'] = true;
        $result['changed'] = true;
        $result['message'] = 'Stock reserved again for this order.';

        return $result;
    } catch (Throwable $e) {
        $mysqli->rollback();
        $result['message'] = $e->getMessage();

        return $result;
    }
}

/**
 * "2 × Ankara Dress, 1 × Sandals" — for admin/customer messages.
 */
function eflex_restock_summary($restored)
{
    if (empty($restored)) {
        return '';
    }

    $parts = array();
    $extra = 0;
    foreach ($restored as $index => $item) {
        if ($index >= 5) {
            $extra++;
            continue;
        }
        $parts[] = (int)$item['quantity'] . ' × ' . $item['name'];
    }
    if ($extra > 0) {
        $parts[] = '+' . $extra . ' more';
    }

    return implode(', ', $parts);
}

/**
 * Deduct the stock of one cart line at checkout, refusing to go negative.
 *
 * @throws EflexOutOfStockException when the last unit was taken meanwhile.
 */
function eflex_deduct_checkout_item($mysqli, $product_id, $variant_id, $quantity, $product_name)
{
    $quantity = (int)$quantity;

    if ($quantity < 1) {
        return true;
    }

    if (!empty($variant_id)) {
        $stmt = $mysqli->prepare("UPDATE product_variants SET stock = COALESCE(stock, 0) - ? WHERE id = ? AND COALESCE(stock, 0) >= ?");
        if (!$stmt) {
            throw new RuntimeException('Could not update the variant stock.');
        }
        $variant_id = (int)$variant_id;
        $stmt->bind_param('iii', $quantity, $variant_id, $quantity);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            throw new EflexOutOfStockException("Sorry, the quantity for '" . $product_name . "' is no longer available. Please adjust your cart.", $product_name);
        }

        return true;
    }

    $stmt = $mysqli->prepare("UPDATE products SET stock = COALESCE(stock, 0) - ? WHERE id = ? AND has_variants = 0 AND COALESCE(stock, 0) >= ?");
    if (!$stmt) {
        throw new RuntimeException('Could not update the product stock.');
    }
    $product_id = (int)$product_id;
    $stmt->bind_param('iii', $quantity, $product_id, $quantity);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        return true;
    }

    // No row changed: either the product became variant-managed (stock lives on
    // the variants, nothing to deduct here) or it really is out of stock.
    $stmt = $mysqli->prepare("SELECT has_variants FROM products WHERE id = ?");
    $has_variants = 0;
    if ($stmt) {
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $has_variants = $row ? (int)$row['has_variants'] : 0;
    }

    if ($has_variants === 1) {
        return true;
    }

    throw new EflexOutOfStockException("Sorry, the quantity for '" . $product_name . "' is no longer available. Please adjust your cart.", $product_name);
}
