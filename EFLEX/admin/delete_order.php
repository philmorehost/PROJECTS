<?php
ob_start(); // keep output buffered so the redirects below don't hit "headers already sent"
include 'includes/admin_header.php';
require_permission('manage_orders');
require_once '../includes/order_stock.php';

if(!isset($_GET['id']) || !is_numeric($_GET['id'])){
    header("Location: manage_orders.php");
    exit();
}

$order_id = (int)$_GET['id'];

// Only unpaid ('Pending', 'Awaiting Payment', 'Failed') or already cancelled
// orders may be deleted — a paid order is a financial record.
$stmt = $mysqli->prepare("SELECT status, COALESCE(stock_restored, 0) AS stock_restored FROM orders WHERE id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();
if($result->num_rows === 0){
    $_SESSION['error_message'] = "Order not found.";
    header("Location: manage_orders.php");
    exit();
}
$order = $result->fetch_assoc();
$stmt->close();

$deletable = array_merge(eflex_unpaid_order_statuses(), array('Cancelled'));
if(!in_array($order['status'], $deletable, true)){
    $_SESSION['error_message'] = "Only unpaid or cancelled orders can be deleted.";
    header("Location: manage_orders.php");
    exit();
}

$mysqli->begin_transaction();
try {
    // Return the stock exactly once. eflex_restore_stock_locked() locks the order
    // row and only adds stock when it wins the stock_restored 0 -> 1 flip, so a
    // double click on "Delete" (or deleting an order that was cancelled a second
    // ago) cannot inflate the inventory.
    $locked = eflex_restore_stock_locked($mysqli, $order_id);

    // Delete from order_items first to maintain referential integrity
    $stmt_items = $mysqli->prepare("DELETE FROM order_items WHERE order_id = ?");
    $stmt_items->bind_param("i", $order_id);
    $stmt_items->execute();
    $stmt_items->close();

    // Delete from the orders table
    $stmt_order = $mysqli->prepare("DELETE FROM orders WHERE id = ?");
    $stmt_order->bind_param("i", $order_id);
    $stmt_order->execute();
    $stmt_order->close();

    $mysqli->commit();

    if ($locked['restocked']) {
        $summary = eflex_restock_summary($locked['restored']);
        $_SESSION['success_message'] = "Order #" . $order_id . " has been deleted successfully and its stock has been restored"
            . ($summary !== '' ? ' (' . $summary . ')' : '') . ".";
    } elseif ($locked['already']) {
        $_SESSION['success_message'] = "Order #" . $order_id . " has been deleted successfully. Its stock had already been returned when it was cancelled, so nothing was added again.";
    } else {
        $_SESSION['success_message'] = "Order #" . $order_id . " has been deleted successfully.";
    }

} catch (Throwable $exception) {
    $mysqli->rollback();
    $_SESSION['error_message'] = "Error deleting order: " . $exception->getMessage();
}

header("Location: manage_orders.php");
exit();

// The admin footer is not included because the script redirects
// include 'includes/admin_footer.php';
?>
