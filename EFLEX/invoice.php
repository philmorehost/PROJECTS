<?php
session_start();
require_once 'includes/db_connect.php';

// Check if user is logged in and order ID is provided
if (!isset($_SESSION["loggedin"]) || !isset($_GET['id'])) {
    header("location: login.php");
    exit;
}

$order_id = $_GET['id'];
$user_id = $_SESSION['id'];

// --- Fetch all site settings for invoice details ---
$settings_sql = "SELECT setting_key, setting_value FROM settings";
$result = $mysqli->query($settings_sql);
$settings = [];
while($row = $result->fetch_assoc()){
    $settings[$row['setting_key']] = $row['setting_value'];
}
$company_name = $settings['company_name'] ?? 'Eflex E-commerce';
$company_address = $settings['company_address'] ?? '123 E-commerce St, Webville';
$company_phone = $settings['company_phone'] ?? '';
$currency_symbol = $settings['currency_symbol'] ?? '$';

// --- Fetch Order Details ---
$sql_order = "SELECT o.*, u.username, u.email FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ? AND o.user_id = ?";
$stmt_order = $mysqli->prepare($sql_order);
$stmt_order->bind_param("ii", $order_id, $user_id);
$stmt_order->execute();
$order = $stmt_order->get_result()->fetch_assoc();
$stmt_order->close();

if (!$order) {
    die("Order not found or you do not have permission to view it.");
}

// --- Fetch Order Items ---
$sql_items = "SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?";
$stmt_items = $mysqli->prepare($sql_items);
$stmt_items->bind_param("i", $order_id);
$stmt_items->execute();
$order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_items->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #<?php echo $order_id; ?></title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #fff; }
        .invoice-container { max-width: 800px; margin: 30px auto; padding: 30px; border: 1px solid #ddd; }
        .invoice-header { text-align: center; margin-bottom: 40px; }
        .invoice-header h1 { margin: 0; }
        .company-details { text-align: right; }
        .invoice-details { margin-bottom: 40px; }
        .paid-stamp { color: #28a745; border: 2px solid #28a745; padding: 10px; font-size: 1.5rem; font-weight: bold; display: inline-block; transform: rotate(-15deg); opacity: 0.5; margin-top: 20px; }
        @media print {
            body { -webkit-print-color-adjust: exact; }
            .no-print { display: none; }
            .invoice-container { border: none; }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="row invoice-header">
            <div class="col-6">
                <h1>INVOICE</h1>
            </div>
            <div class="col-6 company-details">
                <strong><?php echo htmlspecialchars($company_name); ?></strong><br>
                <?php echo nl2br(htmlspecialchars($company_address)); ?><br>
                <?php echo htmlspecialchars($company_phone); ?>
            </div>
        </div>
        <hr>
        <div class="row invoice-details">
            <div class="col-6">
                <strong>BILLED TO:</strong><br>
                <?php echo htmlspecialchars($order['username']); ?><br>
                <?php echo htmlspecialchars($order['email']); ?>
            </div>
            <div class="col-6 text-end">
                <strong>Invoice #:</strong> <?php echo $order_id; ?><br>
                <strong>Date:</strong> <?php echo date("M d, Y", strtotime($order['created_at'])); ?><br>
                <strong>Status:</strong> <?php echo htmlspecialchars($order['status']); ?>
            </div>
        </div>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="text-end">Quantity</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($order_items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <td class="text-end"><?php echo $item['quantity']; ?></td>
                    <td class="text-end"><?php echo htmlspecialchars($currency_symbol) . number_format($item['price'], 2); ?></td>
                    <td class="text-end"><?php echo htmlspecialchars($currency_symbol) . number_format($item['price'] * $item['quantity'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="text-end"><strong>Subtotal</strong></td>
                    <td class="text-end"><?php echo htmlspecialchars($currency_symbol) . number_format($order['total_amount'], 2); ?></td>
                </tr>
                <tr>
                    <td colspan="3" class="text-end"><strong>Taxes</strong></td>
                    <td class="text-end"><?php echo htmlspecialchars($currency_symbol); ?>0.00</td>
                </tr>
                <tr>
                    <td colspan="3" class="text-end"><strong>Total</strong></td>
                    <td class="text-end"><strong><?php echo htmlspecialchars($currency_symbol) . number_format($order['total_amount'], 2); ?></strong></td>
                </tr>
            </tfoot>
        </table>

        <div class="text-center">
            <?php if($order['status'] === 'Completed' || $order['status'] === 'Processing'): ?>
                <div class="paid-stamp">PAID</div>
            <?php endif; ?>
        </div>

        <div class="text-center mt-4 no-print">
            <button onclick="window.print()" class="btn btn-primary">Print Invoice</button>
            <a href="my_orders.php" class="btn btn-secondary">Back to Orders</a>
        </div>
    </div>
</body>
</html>
