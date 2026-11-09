<?php
/**
 * Renders ONE page touched by the automatic-cancellation work against the
 * in-memory fake database, then asserts what it did to stock/orders.
 *
 * Not meant to be run directly: tests/run_smoke.mjs starts a PHP process per
 * scenario (each page includes header.php, so two pages in one process would
 * redeclare functions) and passes the scenario number:
 *
 *     SMOKE_SCENARIO=3 php tests/run_page_smoke.php
 *
 * The script ends with a single "VERDICT: ..." line.
 */
require_once __DIR__ . '/fake_db.php';
// send_email() is provided by tests/send_email_stub.php: the sandbox harness
// (tests/run_smoke.mjs) swaps includes/send_email.php for it.

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('html_errors', '0');

// Buffer everything from the very first byte so session_start()/header() inside
// the pages behave like they do under the web server.
ob_start();

$GLOBALS['SMOKE_SCRIPT'] = '';
$GLOBALS['SMOKE_DB'] = null;
$GLOBALS['SMOKE_EXPECT'] = null;
$GLOBALS['SMOKE_ERRORS'] = array();

function build_db()
{
    $db = new FakeMysqli();
    $db->permissive = true;
    $db->products[1] = array('stock' => 10, 'has_variants' => 0, 'name' => 'Ankara Dress');
    $db->products[2] = array('stock' => 0, 'has_variants' => 1, 'name' => 'Sneakers');
    $db->variants[11] = array('stock' => 5, 'product_id' => 2);
    $db->users[1] = array('id' => 1, 'username' => 'admin', 'email' => 'admin@example.com');
    $db->users[7] = array('id' => 7, 'username' => 'ada', 'email' => 'ada@example.com');
    $db->settings = array(
        'site_name' => 'Eflex', 'from_email' => 'shop@example.com',
        'admin_notification_email' => 'owner@example.com', 'site_url' => 'https://shop.example.com',
        'currency_symbol' => 'N', 'currency_code' => 'NGN',
        'auto_cancel_pending_after' => '30', 'auto_cancel_pending_unit' => 'hours',
        'auto_cancel_cron_key' => 'abc123',
    );
    // 201: overdue pending order (product line)
    $db->orders[201] = array('id' => 201, 'user_id' => 7, 'status' => 'Pending', 'stock_restored' => 0,
        'created_at' => date('Y-m-d H:i:s', time() - 40 * 3600), 'total_amount' => 500, 'transaction_id' => 'TX1',
        'cancelled_at' => null, 'cancel_reason' => null);
    $db->order_items[20101] = array('order_id' => 201, 'product_id' => 1, 'variant_id' => null, 'quantity' => 2);
    // 202: fresh bank-transfer order (variant line)
    $db->orders[202] = array('id' => 202, 'user_id' => 7, 'status' => 'Awaiting Payment', 'stock_restored' => 0,
        'created_at' => date('Y-m-d H:i:s', time() - 2 * 3600), 'total_amount' => 300, 'transaction_id' => 'TX2',
        'cancelled_at' => null, 'cancel_reason' => null);
    $db->order_items[20201] = array('order_id' => 202, 'product_id' => 2, 'variant_id' => 11, 'quantity' => 2);
    // 203: cancelled order whose stock was already returned (re-open path)
    $db->orders[203] = array('id' => 203, 'user_id' => 7, 'status' => 'Cancelled', 'stock_restored' => 1,
        'created_at' => date('Y-m-d H:i:s', time() - 10 * 3600), 'total_amount' => 200, 'transaction_id' => 'TX3',
        'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => 'Cancelled by the customer');
    $db->order_items[20301] = array('order_id' => 203, 'product_id' => 1, 'variant_id' => null, 'quantity' => 3);
    return $db;
}

function render($script, $get = array(), $post = array(), $session = array())
{
    $db = build_db();

    $_GET = $get;
    $_POST = $post;
    $_COOKIE = array();
    $_SERVER = array(
        'REQUEST_METHOD' => empty($post) ? 'GET' : 'POST',
        'PHP_SELF' => '/' . $script,
        'HTTP_HOST' => 'shop.example.com',
        'DOCUMENT_ROOT' => realpath(__DIR__ . '/..'),
        'HTTPS' => 'off',
    );

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = $session;

    $GLOBALS['SMOKE_DB'] = $db;
    $GLOBALS['SMOKE_SCRIPT'] = $script;
    $GLOBALS['mysqli'] = $db;
    $GLOBALS['settings'] = $db->settings;

    chdir(dirname(__DIR__) . '/' . dirname($script));
    set_include_path(realpath(__DIR__ . '/..') . PATH_SEPARATOR . get_include_path());

    $mysqli = $db;
    require __DIR__ . '/../' . $script;
}

register_shutdown_function(function () {
    $output = '';
    while (ob_get_level() > 0) {
        $output = ob_get_clean() . $output;
    }

    $label = $GLOBALS['SMOKE_LABEL'];
    $issues = array();

    // 1. PHP problems (warnings/notices/fatals) in the rendered page.
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/(PHP )?(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught [A-Za-z_\\\\]+)\s*:/', $line)) {
            if (strpos($line, 'class="') !== false) {
                continue; // HTML such as class="alert alert-warning"
            }
            $issues[] = trim(strip_tags($line));
        }
    }

    // 2. Behavioural expectations for this scenario.
    $expect = $GLOBALS['SMOKE_EXPECT'];
    $db = $GLOBALS['SMOKE_DB'];
    if (is_callable($expect) && $db instanceof FakeMysqli) {
        $checks = array();
        try {
            $expect($db, $checks);
        } catch (Throwable $e) {
            $issues[] = 'expectation crashed: ' . $e->getMessage();
        }
        $failed = 0;
        $total = 0;
        foreach ($checks as $check) {
            $total++;
            if (!$check[1]) {
                $failed++;
                $issues[] = 'CHECK FAILED: ' . $check[0] . ' — ' . (isset($check[2]) ? $check[2] : '');
            }
        }
        $summary = ' (' . ($total - $failed) . '/' . $total . ' checks)';
    } else {
        $summary = '';
    }

    $dump = getenv('SMOKE_DUMP');
    if ($dump !== false && $dump !== '') {
        @file_put_contents($dump, $output);
    }

    if (empty($issues)) {
        echo 'VERDICT: OK  ' . $label . $summary . ' — ' . strlen($output) . " bytes rendered\n";
    } else {
        echo 'VERDICT: ISSUES  ' . $label . $summary . "\n";
        foreach (array_slice($issues, 0, 12) as $issue) {
            echo '   - ' . $issue . "\n";
        }
    }
});

$admin_session = array(
    'loggedin' => true, 'id' => 1, 'username' => 'admin', 'email' => 'admin@example.com',
    'role_id' => 1, 'permissions' => array('manage_orders', 'manage_site_settings', 'manage_products'),
    'currency_symbol' => 'N',
);
$customer_session = array(
    'loggedin' => true, 'id' => 7, 'username' => 'ada', 'email' => 'ada@example.com',
    'currency_symbol' => 'N',
);

$ctx = function ($db) {
    return array(
        'product_stock' => function ($id) use ($db) { return (int)$db->products[$id]['stock']; },
        'variant_stock' => function ($id) use ($db) { return (int)$db->variants[$id]['stock']; },
        'order_status' => function ($id) use ($db) { return $db->orders[$id]['status']; },
        'stock_restored' => function ($id) use ($db) { return (int)$db->orders[$id]['stock_restored']; },
    );
};

$scenarios = array(
    'admin/manage_orders.php' => array(
        array(), array(), $admin_session,
        function ($db, &$checks) {
            $checks[] = array('unpaid orders are not cancelled by a page view within the window', $db->orders[202]['status'] === 'Awaiting Payment');
            $checks[] = array('overdue order cancelled by the lazy sweep', $db->orders[201]['status'] === 'Cancelled', $db->orders[201]['status']);
            $checks[] = array('product restocked once (10 + 2)', $db->products[1]['stock'] === 12, 'stock=' . $db->products[1]['stock']);
        },
    ),
    'admin/site_settings.php' => array(
        array(), array(), $admin_session,
        function ($db, &$checks) {
            $checks[] = array('settings page does not cancel anything', $db->orders[201]['status'] === 'Pending');
        },
    ),
    'admin/order_detail.php [pending]' => array(
        array('id' => 201), array(), $admin_session,
        function ($db, &$checks) {
            $checks[] = array('viewing an order changes nothing', $db->products[1]['stock'] === 10 && $db->orders[201]['status'] === 'Pending');
        },
    ),
    'admin/dashboard.php' => array(
        array(), array(), $admin_session,
        function ($db, &$checks) {
            $checks[] = array('dashboard sweep cancelled the overdue order', $db->orders[201]['status'] === 'Cancelled');
            $checks[] = array('and restocked exactly once', $db->products[1]['stock'] === 12, 'stock=' . $db->products[1]['stock']);
        },
    ),
    'my_orders.php' => array(
        array(), array(), $customer_session,
        function ($db, &$checks) {
            $checks[] = array('customer page does not cancel the fresh order', $db->orders[202]['status'] === 'Awaiting Payment');
            $checks[] = array('overdue order swept on a storefront page', $db->orders[201]['status'] === 'Cancelled');
        },
    ),
    'admin/manage_orders.php [bulk cancel all]' => array(
        array(), array('bulk_cancel_unpaid' => '1', 'cancel_scope' => 'all'), $admin_session,
        function ($db, &$checks) {
            $checks[] = array('overdue order cancelled', $db->orders[201]['status'] === 'Cancelled');
            $checks[] = array('fresh order cancelled too (scope=all)', $db->orders[202]['status'] === 'Cancelled', $db->orders[202]['status']);
            $checks[] = array('product restocked once', $db->products[1]['stock'] === 12, 'stock=' . $db->products[1]['stock']);
            $checks[] = array('variant restocked once (5 + 2)', $db->variants[11]['stock'] === 7, 'variant=' . $db->variants[11]['stock']);
        },
    ),
    'my_orders.php [customer cancels]' => array(
        array(), array('cancel_order' => '1', 'order_id' => 202), $customer_session,
        function ($db, &$checks) {
            $checks[] = array('order cancelled', $db->orders[202]['status'] === 'Cancelled', $db->orders[202]['status']);
            $checks[] = array('variant restocked once (5 + 2)', $db->variants[11]['stock'] === 7, 'variant=' . $db->variants[11]['stock']);
            $checks[] = array('flag set', (int)$db->orders[202]['stock_restored'] === 1);
        },
    ),
    'admin/order_detail.php [cancel via dropdown]' => array(
        array('id' => 202), array('update_status' => '1', 'status' => 'Cancelled'), $admin_session,
        function ($db, &$checks) {
            $checks[] = array('order cancelled', $db->orders[202]['status'] === 'Cancelled', $db->orders[202]['status']);
            $checks[] = array('variant restocked once (5 + 2)', $db->variants[11]['stock'] === 7, 'variant=' . $db->variants[11]['stock']);
            $checks[] = array('reason recorded', $db->orders[202]['cancel_reason'] === 'Cancelled by the administrator', (string)$db->orders[202]['cancel_reason']);
        },
    ),
    'admin/order_detail.php [reopen cancelled]' => array(
        array('id' => 203), array('update_status' => '1', 'status' => 'Processing'), $admin_session,
        function ($db, &$checks) {
            $checks[] = array('cancelled order re-opened', $db->orders[203]['status'] === 'Processing', $db->orders[203]['status']);
            $checks[] = array('stock reserved again (10 - 3)', $db->products[1]['stock'] === 7, 'stock=' . $db->products[1]['stock']);
            $checks[] = array('restore flag cleared', (int)$db->orders[203]['stock_restored'] === 0);
        },
    ),
    'admin/order_detail.php [cancel twice in a row]' => array(
        array('id' => 203), array('update_status' => '1', 'status' => 'Pending'), $admin_session,
        function ($db, &$checks) {
            // 203 is already Cancelled with the stock returned: switching it to
            // Pending must reserve the stock once, not duplicate it.
            $checks[] = array('status changed to Pending', $db->orders[203]['status'] === 'Pending', $db->orders[203]['status']);
            $checks[] = array('stock reserved exactly once (10 - 3)', $db->products[1]['stock'] === 7, 'stock=' . $db->products[1]['stock']);
        },
    ),
    'admin/site_settings.php [save 2 days]' => array(
        array(), array('save_settings' => '1', 'site_name' => 'Eflex',
            'auto_cancel_pending_after' => '2', 'auto_cancel_pending_unit' => 'days',
            'auto_cancel_pending_enabled' => '1', 'auto_cancel_notify_customer' => '1'), $admin_session,
        function ($db, &$checks) {
            $checks[] = array('window saved as 2 days', $db->settings['auto_cancel_pending_after'] === '2', (string)$db->settings['auto_cancel_pending_after']);
            $checks[] = array('unit saved', $db->settings['auto_cancel_pending_unit'] === 'days', (string)$db->settings['auto_cancel_pending_unit']);
            $checks[] = array('switch saved', $db->settings['auto_cancel_pending_enabled'] === '1');
            $checks[] = array('40h old order now overdue for a 48h window? no', $db->orders[201]['status'] === 'Pending', $db->orders[201]['status']);
        },
    ),
    'admin/site_settings.php [invalid window]' => array(
        array(), array('save_settings' => '1', 'site_name' => 'Eflex',
            'auto_cancel_pending_after' => '0', 'auto_cancel_pending_unit' => 'days'), $admin_session,
        function ($db, &$checks) {
            $checks[] = array('0 rejected, falls back to 30 hours', $db->settings['auto_cancel_pending_after'] === '30', (string)$db->settings['auto_cancel_pending_after']);
            $checks[] = array('unit reset to hours', $db->settings['auto_cancel_pending_unit'] === 'hours', (string)$db->settings['auto_cancel_pending_unit']);
        },
    ),
    'admin/site_settings.php [run now]' => array(
        array(), array('run_auto_cancel_now' => '1'), $admin_session,
        function ($db, &$checks) {
            $checks[] = array('overdue order cancelled immediately', $db->orders[201]['status'] === 'Cancelled', $db->orders[201]['status']);
            $checks[] = array('stock returned once', $db->products[1]['stock'] === 12, 'stock=' . $db->products[1]['stock']);
            $checks[] = array('fresh order untouched', $db->orders[202]['status'] === 'Awaiting Payment');
        },
    ),
    'admin/site_settings.php [regenerate cron key]' => array(
        array(), array('regenerate_cron_key' => '1'), $admin_session,
        function ($db, &$checks) {
            $checks[] = array('a new key was stored', !empty($db->settings['auto_cancel_cron_key']) && $db->settings['auto_cancel_cron_key'] !== 'abc123', (string)$db->settings['auto_cancel_cron_key']);
            $checks[] = array('key is 40 hex chars', (bool)preg_match('/^[a-f0-9]{40}$/', (string)$db->settings['auto_cancel_cron_key']));
        },
    ),
    'cron/auto_cancel_orders.php' => array(
        array('key' => 'abc123'), array(), array(),
        function ($db, &$checks) {
            $checks[] = array('cron cancelled the overdue order', $db->orders[201]['status'] === 'Cancelled', $db->orders[201]['status']);
            $checks[] = array('stock returned once', $db->products[1]['stock'] === 12, 'stock=' . $db->products[1]['stock']);
            $checks[] = array('fresh order untouched', $db->orders[202]['status'] === 'Awaiting Payment');
            $checks[] = array('last run recorded', !empty($db->settings['auto_cancel_last_run']));
        },
    ),
);

$scenario_env = getenv('SMOKE_SCENARIO');
if ($scenario_env === false && isset($argv[1]) && is_numeric($argv[1])) {
    $scenario_env = $argv[1];
}

if ($scenario_env === false || $scenario_env === '') {
    echo "Driven by tests/run_smoke.mjs. Scenarios:\n";
    $i = 0;
    foreach ($scenarios as $label => $args) {
        echo '  ' . $i++ . '  ' . $label . "\n";
    }
    exit(0);
}

$labels = array_keys($scenarios);
$index = (int)$scenario_env;
if (!isset($labels[$index])) {
    $GLOBALS['SMOKE_LABEL'] = 'unknown scenario ' . $index;
    $GLOBALS['SMOKE_EXPECT'] = null;
    echo "VERDICT: ISSUES (unknown scenario $index)\n";
    exit(2);
}

$label = $labels[$index];
$args = $scenarios[$label];
$script = explode(' [', $label)[0];

$GLOBALS['SMOKE_LABEL'] = $label;
$GLOBALS['SMOKE_EXPECT'] = $args[3];
echo 'SCENARIO: ' . $label . "\n";
render($script, $args[0], $args[1], $args[2]);
