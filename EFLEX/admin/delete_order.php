<?php
ob_start(); // keep output buffered so the redirects below don't hit "headers already sent"
include 'includes/admin_header.php';
require_permission('manage_orders');

if(!isset($_GET['id']) || !is_numeric($_GET['id'])){
    header("Location: manage_orders.php");
    exit();
}

$order_id = $_GET['id'];

// First, check if the order has a status of 'Cancelled' or 'Pending'
$stmt = $mysqli->prepare("SELECT status FROM orders WHERE id = ?");
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

if($order['status'] !== 'Cancelled' && $order['status'] !== 'Pending'){
    $_SESSION['error_message'] = "Only orders with 'Cancelled' or 'Pending' status can be deleted.";
    header("Location: manage_orders.php");
    exit();
}

// Proceed with deletion
$mysqli->begin_transaction();
try {
    // For both 'Pending' and 'Cancelled' orders, we need to restore stock if it hasn't been restored already.
    // Stock was deducted at checkout, so it needs to be returned.
    $check_stock_sql = "SELECT stock_restored FROM orders WHERE id = ?";
    $stmt_check_stock = $mysqli->prepare($check_stock_sql);
    $stmt_check_stock->bind_param("i", $order_id);
    $stmt_check_stock->execute();
    $stock_restored_result = $stmt_check_stock->get_result()->fetch_assoc();
    $stmt_check_stock->close();

    if (!$stock_restored_result['stock_restored']) {
        $sql_items = "SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = ?";
        $stmt_items = $mysqli->prepare($sql_items);
        $stmt_items->bind_param("i", $order_id);
        $stmt_items->execute();
        $items_to_restock = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_items->close();

        foreach ($items_to_restock as $item) {
            if (!empty($item['variant_id'])) {
                $sql_update_stock = "UPDATE product_variants SET stock = stock + ? WHERE id = ?";
                $stmt_stock = $mysqli->prepare($sql_update_stock);
                $stmt_stock->bind_param("ii", $item['quantity'], $item['variant_id']);
                $stmt_stock->execute();
                $stmt_stock->close();
            } else {
                $sql_update_stock = "UPDATE products SET stock = stock + ? WHERE id = ? AND has_variants = 0";
                $stmt_stock = $mysqli->prepare($sql_update_stock);
                $stmt_stock->bind_param("ii", $item['quantity'], $item['product_id']);
                $stmt_stock->execute();
                $stmt_stock->close();
            }
        }
        $_SESSION['success_message'] = "Order #" . $order_id . " has been deleted successfully and its stock has been restored.";
    } else {
        $_SESSION['success_message'] = "Order #" . $order_id . " has been deleted successfully. Stock was already restored previously.";
    }

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

} catch (mysqli_sql_exception $exception) {
    $mysqli->rollback();
    $_SESSION['error_message'] = "Error deleting order: " . $exception->getMessage();
}

header("Location: manage_orders.php");
exit();

// The admin footer is not included because the script redirects
// include 'includes/admin_footer.php';
?>