<?php
/**
 * Behavioural tests for EFLEX order cancellation / restocking.
 * Run: php tests/run_cancel_tests.php   (from the EFLEX directory)
 */
require_once __DIR__ . '/fake_db.php';
require_once __DIR__ . '/send_email_stub.php';

// The engine loads includes/send_email.php (PHPMailer) lazily; give it our stub
// instead by pre-declaring the file in the include path is not possible, so we
// rely on function_exists('send_email') being true thanks to the stub above.
require_once __DIR__ . '/../includes/order_stock.php';
require_once __DIR__ . '/../includes/order_cancellation.php';

$failures = 0;
$checks = 0;

function check($label, $condition, $detail = '')
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  PASS  $label\n";
    } else {
        $failures++;
        echo "  FAIL  $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    }
}

function fresh_db($stock = 10)
{
    $db = new FakeMysqli();
    $db->products[1] = array('stock' => $stock, 'has_variants' => 0, 'name' => 'Ankara Dress');
    $db->products[2] = array('stock' => 0, 'has_variants' => 1, 'name' => 'Sneakers');
    $db->variants[11] = array('stock' => 5, 'product_id' => 2);
    $db->variants[12] = array('stock' => 7, 'product_id' => 2);
    $db->users[7] = array('id' => 7, 'username' => 'ada', 'email' => 'ada@example.com');
    $db->settings = array(
        'site_name' => 'Eflex',
        'from_email' => 'shop@example.com',
        'admin_notification_email' => 'owner@example.com',
        'site_url' => 'https://shop.example.com',
    );
    return $db;
}

function add_order($db, $id, $status = 'Pending', $age_hours = 1, $items = array(), $stock_restored = 0, $user_id = 7)
{
    $db->orders[$id] = array(
        'user_id' => $user_id,
        'status' => $status,
        'stock_restored' => $stock_restored,
        'created_at' => date('Y-m-d H:i:s', time() - ($age_hours * 3600)),
        'cancelled_at' => null,
        'cancel_reason' => null,
    );
    $item_id = $id * 100;
    foreach ($items as $item) {
        $item_id++;
        $db->order_items[$item_id] = array(
            'order_id' => $id,
            'product_id' => $item[0],
            'variant_id' => $item[1],
            'quantity' => $item[2],
        );
    }
}

echo "\n== 1. Cancelling returns the stock exactly once ==\n";
$db = fresh_db(10);
add_order($db, 100, 'Pending', 2, array(array(1, null, 3)));
$r1 = eflex_cancel_order($db, 100, 'Cancelled by the customer');
check('first cancel succeeds', $r1['ok'] === true, json_encode($r1));
check('stock returned once (10 -> 7 -> ... wait, 3 units back)', $db->products[1]['stock'] === 13, 'stock=' . $db->products[1]['stock']);
check('stock_restored flag set', (int)$db->orders[100]['stock_restored'] === 1);
check('status Cancelled', $db->orders[100]['status'] === 'Cancelled');
check('cancel_reason stored', $db->orders[100]['cancel_reason'] === 'Cancelled by the customer');

$r2 = eflex_cancel_order($db, 100, 'Cancelled by the customer');
check('second cancel refused', $r2['ok'] === false, json_encode($r2));
check('stock NOT doubled on second cancel', $db->products[1]['stock'] === 13, 'stock=' . $db->products[1]['stock']);

echo "\n== 2. Double click without the status guard (legacy race) ==\n";
$db = fresh_db(10);
add_order($db, 101, 'Pending', 2, array(array(1, null, 3)));
// Simulate the old bug: two requests both saw stock_restored = 0.
$first = eflex_restore_order_stock($db, 101);
$second = eflex_restore_order_stock($db, 101);
check('first restore adds stock', $db->products[1]['stock'] === 13, 'stock=' . $db->products[1]['stock']);
check('second restore is a no-op', $db->products[1]['stock'] === 13, 'stock=' . $db->products[1]['stock']);
check('second restore reports "already"', $second['already'] === true);

echo "\n== 3. Variant stock goes back to the variant, not the product ==\n";
$db = fresh_db();
add_order($db, 102, 'Awaiting Payment', 2, array(array(2, 11, 2), array(1, null, 1)));
$r = eflex_cancel_order($db, 102, 'test');
check('cancel ok', $r['ok'] === true, json_encode($r));
check('variant 11 restocked (5 -> 7)', $db->variants[11]['stock'] === 7, 'variant=' . $db->variants[11]['stock']);
check('product 2 (variant managed) untouched', $db->products[2]['stock'] === 0);
check('product 1 restocked (10 -> 11)', $db->products[1]['stock'] === 11);
check('summary lists both lines', count($r['restored']) === 2, json_encode($r['restored']));

echo "\n== 4. Paid orders must never be restocked by a cancel attempt ==\n";
$db = fresh_db(10);
add_order($db, 103, 'Completed', 2, array(array(1, null, 4)));
$r = eflex_cancel_order($db, 103, 'test');
check('completed order refused', $r['ok'] === false, json_encode($r));
check('stock unchanged', $db->products[1]['stock'] === 10, 'stock=' . $db->products[1]['stock']);
$del = eflex_restore_order_stock($db, 103);
check('direct restore adds the stock once (10 -> 14)', $db->products[1]['stock'] === 14, 'stock=' . $db->products[1]['stock']);

// The manual "cancel & restock" button in the admin order page: staff may cancel
// a paid order (refund), and the restock stays exactly-once.
$db = fresh_db(10);
add_order($db, 110, 'Completed', 5, array(array(1, null, 4)));
$admin_statuses = eflex_admin_cancellable_statuses();
$r = eflex_cancel_order($db, 110, 'Refunded', $admin_statuses);
check('admin may cancel a completed (paid) order', $r['ok'] === true, json_encode($r));
check('its stock is returned once (10 -> 14)', $db->products[1]['stock'] === 14, 'stock=' . $db->products[1]['stock']);
$r = eflex_cancel_order($db, 110, 'Refunded again', $admin_statuses);
check('second admin cancel is refused', $r['ok'] === false);
check('still 14, not 18', $db->products[1]['stock'] === 14, 'stock=' . $db->products[1]['stock']);

echo "\n== 5. Order that was already cancelled before the fix (flag 1) is not restocked again ==\n";
$db = fresh_db(10);
add_order($db, 104, 'Cancelled', 2, array(array(1, null, 2)), 1);
$r = eflex_cancel_order($db, 104, 'test');
check('cancel of an already cancelled order refused', $r['ok'] === false);
check('stock unchanged', $db->products[1]['stock'] === 10, 'stock=' . $db->products[1]['stock']);

echo "\n== 6. Re-opening a cancelled order reserves the stock again ==\n";
$db = fresh_db(10);
add_order($db, 105, 'Pending', 2, array(array(1, null, 3)));
eflex_cancel_order($db, 105, 'test');
check('restocked to 13', $db->products[1]['stock'] === 13);
$reopen = eflex_reopen_order($db, 105);
check('reopen ok', $reopen['ok'] === true, json_encode($reopen));
check('stock reserved again (13 -> 10)', $db->products[1]['stock'] === 10, 'stock=' . $db->products[1]['stock']);
check('stock_restored cleared', (int)$db->orders[105]['stock_restored'] === 0);
check('audit columns cleared', $db->orders[105]['cancel_reason'] === null);
// order_detail.php re-opens by resetting the reservation, then saves the new
// status (e.g. Pending). Only then can the order be cancelled again.
$db->orders[105]['status'] = 'Pending';
$again = eflex_cancel_order($db, 105, 'test');
check('can cancel again after reopen', $again['ok'] === true, json_encode($again));
check('stock returned once more (10 -> 13)', $db->products[1]['stock'] === 13, 'stock=' . $db->products[1]['stock']);

echo "\n== 7. Re-opening is refused when there is not enough stock ==\n";
$db = fresh_db(2);
add_order($db, 106, 'Pending', 2, array(array(1, null, 2)));
eflex_cancel_order($db, 106, 'test');
$db->products[1]['stock'] = 1; // somebody bought them meanwhile
$reopen = eflex_reopen_order($db, 106);
check('reopen refused', $reopen['ok'] === false, json_encode($reopen));
check('stock untouched', $db->products[1]['stock'] === 1);
check('order stays restorable', (int)$db->orders[106]['stock_restored'] === 1);

echo "\n== 8. Automatic cancellation: 30 hour default window ==\n";
$db = fresh_db(10);
add_order($db, 201, 'Pending', 31, array(array(1, null, 2)));         // overdue
add_order($db, 202, 'Awaiting Payment', 29, array(array(1, null, 1))); // not yet
add_order($db, 203, 'Failed', 40, array(array(2, 11, 1)));             // overdue
add_order($db, 204, 'Completed', 90, array(array(1, null, 5)));        // paid, untouched
$summary = eflex_auto_cancel_stale_orders($db);
check('default window is 30 hours', $summary['label'] === '30 hours', $summary['label']);
check('two orders cancelled', $summary['cancelled'] === 2, json_encode($summary));
check('order 201 cancelled', $db->orders[201]['status'] === 'Cancelled');
check('order 202 untouched', $db->orders[202]['status'] === 'Awaiting Payment');
check('order 203 cancelled', $db->orders[203]['status'] === 'Cancelled');
check('order 204 untouched', $db->orders[204]['status'] === 'Completed');
check('product stock: 10 + 2 = 12', $db->products[1]['stock'] === 12, 'stock=' . $db->products[1]['stock']);
check('variant stock: 5 + 1 = 6', $db->variants[11]['stock'] === 6, 'variant=' . $db->variants[11]['stock']);
check('completed order stock untouched', $db->products[1]['stock'] === 12);
$GLOBALS['SENT_EMAILS'] = array();

echo "\n== 9. Running the sweep twice does not double the stock ==\n";
$db = fresh_db(10);
add_order($db, 301, 'Pending', 31, array(array(1, null, 2)));
$s1 = eflex_auto_cancel_stale_orders($db);
$s2 = eflex_auto_cancel_stale_orders($db);
check('first sweep cancels 1', $s1['cancelled'] === 1);
check('second sweep cancels 0', $s2['cancelled'] === 0, json_encode($s2));
check('stock still 12 (not 14)', $db->products[1]['stock'] === 12, 'stock=' . $db->products[1]['stock']);

echo "\n== 10. Admin-configurable window in hours or days ==\n";
$db = fresh_db(10);
add_order($db, 401, 'Pending', 50, array(array(1, null, 1)));
$db->settings['auto_cancel_pending_after'] = '2';
$db->settings['auto_cancel_pending_unit'] = 'days';
$cfg = eflex_cancel_settings($db, null);
check('2 days == 48 hours', $cfg['hours'] === 48, json_encode($cfg));
check('label reads "2 days"', $cfg['label'] === '2 days');
$s = eflex_auto_cancel_stale_orders($db);
check('50h old order is overdue for a 48h window', $s['cancelled'] === 1, json_encode($s));

$db->settings['auto_cancel_pending_after'] = '72';
$db->settings['auto_cancel_pending_unit'] = 'hours';
$cfg = eflex_cancel_settings($db, null);
check('hours unit works', $cfg['hours'] === 72 && $cfg['label'] === '72 hours', json_encode($cfg));

$db->settings['auto_cancel_pending_enabled'] = '0';
$s = eflex_auto_cancel_stale_orders($db);
check('disabled feature does nothing', $s['cancelled'] === 0 && $s['enabled'] === false);

unset($db->settings['auto_cancel_pending_enabled']);
$cfg = eflex_cancel_settings($db, null);
check('missing switch defaults to enabled (30h out of the box)', $cfg['enabled'] === true && $cfg['hours'] === 72);

echo "\n== 11. Notifications ==\n";
$db = fresh_db(10);
$GLOBALS['SENT_EMAILS'] = array();
add_order($db, 501, 'Pending', 31, array(array(1, null, 1)));
add_order($db, 502, 'Pending', 32, array(array(1, null, 1)));
$s = eflex_auto_cancel_stale_orders($db);
$recipients = array_map(function ($m) { return $m['to']; }, $GLOBALS['SENT_EMAILS']);
check('one email per customer', count(array_keys($recipients, 'ada@example.com')) === 1, json_encode($recipients));
check('owner summary email sent', in_array('owner@example.com', $recipients, true), json_encode($recipients));
check('customer email mentions both orders', strpos($GLOBALS['SENT_EMAILS'][0]['body'], '#501') !== false && strpos($GLOBALS['SENT_EMAILS'][0]['body'], '#502') !== false);

$db->settings['auto_cancel_notify_customer'] = '0';
$GLOBALS['SENT_EMAILS'] = array();
add_order($db, 503, 'Pending', 31, array(array(1, null, 1)));
eflex_auto_cancel_stale_orders($db);
$recipients = array_map(function ($m) { return $m['to']; }, $GLOBALS['SENT_EMAILS']);
check('customer email suppressed when disabled', !in_array('ada@example.com', $recipients, true), json_encode($recipients));

echo "\n== 12. Checkout races cannot oversell (and therefore cannot over-restock) ==\n";
$db = fresh_db(1);
try {
    eflex_deduct_checkout_item($db, 1, null, 2, 'Ankara Dress');
    check('oversell refused', false, 'no exception thrown');
} catch (EflexOutOfStockException $e) {
    check('oversell refused', true);
}
check('stock never went negative', $db->products[1]['stock'] === 1);
eflex_deduct_checkout_item($db, 1, null, 1, 'Ankara Dress');
check('exact last unit allowed', $db->products[1]['stock'] === 0);
try {
    eflex_deduct_checkout_item($db, 1, null, 1, 'Ankara Dress');
    check('sold out refused', false);
} catch (EflexOutOfStockException $e) {
    check('sold out refused', true);
}
$db2 = fresh_db();
eflex_deduct_checkout_item($db2, 2, 11, 3, 'Sneakers');
check('variant deduction works (5 -> 2)', $db2->variants[11]['stock'] === 2, 'variant=' . $db2->variants[11]['stock']);

echo "\n== 13. Legacy schema without cancelled_at / cancel_reason still works ==\n";
$db = fresh_db(10);
$db->has_audit_columns = false;
add_order($db, 601, 'Pending', 2, array(array(1, null, 2)));
$r = eflex_cancel_order($db, 601, 'test');
check('cancel works without audit columns', $r['ok'] === true, json_encode($r));
check('stock restored', $db->products[1]['stock'] === 12);
$s = eflex_auto_cancel_stale_orders($db);
check('sweep works without audit columns', $s['enabled'] === true);

echo "\n== 14. Bulk cancellation of every unpaid order ==\n";
$db = fresh_db(10);
add_order($db, 701, 'Pending', 1, array(array(1, null, 1)));
add_order($db, 702, 'Awaiting Payment', 1, array(array(1, null, 1)));
add_order($db, 703, 'Processing', 1, array(array(1, null, 1)));
$unpaid = eflex_unpaid_order_statuses();
check('unpaid statuses are Pending/Awaiting/Failed', $unpaid === array('Pending', 'Awaiting Payment', 'Failed'), json_encode($unpaid));
$cancelled = 0;
foreach ($db->orders as $id => $order) {
    if (in_array($order['status'], $unpaid, true)) {
        $r = eflex_cancel_order($db, $id, 'bulk');
        if ($r['ok']) { $cancelled++; }
    }
}
check('bulk cancelled the two unpaid orders', $cancelled === 2);
check('Processing order untouched (payment proof waiting)', $db->orders[703]['status'] === 'Processing');
check('stock: 10 + 2 = 12', $db->products[1]['stock'] === 12, 'stock=' . $db->products[1]['stock']);

echo "\n== 15. Restock summary text ==\n";
check('summary renders', eflex_restock_summary(array(array('name' => 'Ankara Dress', 'quantity' => 2))) === '2 × Ankara Dress');
check('format_window hours', eflex_format_window(1, 'hours') === '1 hour');
check('format_window days', eflex_format_window(3, 'days') === '3 days');

echo "\n----------------------------------------\n";
echo ($checks - $failures) . "/$checks checks passed\n";
exit($failures === 0 ? 0 : 1);
