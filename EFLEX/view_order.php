<?php
// We need to start the session on all pages to access session variables
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in, if not then redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Include the database connection (must happen before any redirect)
require_once 'includes/db_connect.php';

// Check if Order ID is provided.
// NOTE: this check must happen BEFORE the header is included, otherwise HTML has
// already been emitted and header() cannot send a redirect. It also previously
// pointed at profile.php, which does not exist in this project.
if(!isset($_GET['id']) || empty($_GET['id'])){
    header("location: my_orders.php");
    exit;
}
$order_id = $_GET['id'];
$user_id = $_SESSION['id'];

// Include the header (after all redirects have been resolved)
include 'includes/header.php';

// Fetch Order Details and verify ownership
$sql_order = "SELECT * FROM orders WHERE id = ? AND user_id = ?";
$order = null;
if($stmt_order = $mysqli->prepare($sql_order)){
    $stmt_order->bind_param("ii", $order_id, $user_id);
    $stmt_order->execute();
    $result_order = $stmt_order->get_result();
    if($result_order->num_rows == 1){
        $order = $result_order->fetch_assoc();
    } else {
        // Order not found or doesn't belong to the user
        echo "<div class='alert alert-danger'>Order not found or you do not have permission to view it.</div>";
        include 'includes/footer.php';
        exit;
    }
    $stmt_order->close();
}

// Fetch Order Items
$sql_items = "SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?";
$order_items = [];
if($stmt_items = $mysqli->prepare($sql_items)){
    $stmt_items->bind_param("i", $order_id);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    $order_items = $result_items->fetch_all(MYSQLI_ASSOC);
    $stmt_items->close();
}
?>

<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Order Details</h2>
        <div>
            <a href="invoice.php?id=<?php echo $order['id']; ?>" class="btn btn-info" target="_blank">View Invoice</a>
            <a href="my_orders.php" class="btn btn-secondary">Back to My Orders</a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            Order #<?php echo $order['id']; ?> - Placed on <?php echo $order['created_at']; ?>
        </div>
        <div class="card-body">
             <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($order_items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo $item['quantity']; ?></td>
                            <td><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($item['price'], 2); ?></td>
                            <td class="text-end"><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Total</strong></td>
                            <td class="text-end"><strong><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($order['total_amount'], 2); ?></strong></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Status</strong></td>
                            <td class="text-end"><span class="badge bg-primary"><?php echo htmlspecialchars($order['status']); ?></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
// Include the footer
include 'includes/footer.php';
?>
