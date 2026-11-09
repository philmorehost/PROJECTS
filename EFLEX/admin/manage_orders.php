<?php
// Include the new admin header
include 'includes/admin_header.php';
require_permission('manage_orders');

// Pagination variables
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// Search functionality
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$sql_where = "";
$params = [];
$types = "";

if(!empty($search_query)){
    $sql_where = "WHERE o.transaction_id LIKE ? OR u.username LIKE ? OR o.id = ?";
    $like_query = "%" . $search_query . "%";
    $params = [$like_query, $like_query, $search_query];
    $types = "ssi";
}

// Get total number of orders
$total_sql = "SELECT COUNT(*) FROM orders o JOIN users u ON o.user_id = u.id " . $sql_where;
$stmt_total = $mysqli->prepare($total_sql);
if(!empty($search_query)){
    $stmt_total->bind_param($types, ...$params);
}
$stmt_total->execute();
$total_records = $stmt_total->get_result()->fetch_row()[0];
$total_pages = ceil($total_records / $records_per_page);
$stmt_total->close();


// Fetch orders for the current page
$sql = "SELECT o.id, u.username, u.phone, o.total_amount, o.status, o.created_at, o.transaction_id
        FROM orders o
        JOIN users u ON o.user_id = u.id
        $sql_where
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?";
array_push($params, $records_per_page, $offset);
$types .= "ii";

if($stmt = $mysqli->prepare($sql)){
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $orders = [];
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Orders</h2>
</div>

<?php
if(isset($_SESSION['success_message'])){
    echo '<div class="alert alert-success">' . $_SESSION['success_message'] . '</div>';
    unset($_SESSION['success_message']);
}
if(isset($_SESSION['error_message'])){
    echo '<div class="alert alert-danger">' . $_SESSION['error_message'] . '</div>';
    unset($_SESSION['error_message']);
}
?>

<div class="card shadow">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <span>All Orders</span>
            <form action="manage_orders.php" method="GET" class="d-flex">
                <input type="text" name="search" class="form-control me-2" placeholder="Search by Tx ID, User, Order ID" value="<?php echo htmlspecialchars($search_query); ?>">
                <button type="submit" class="btn btn-primary">Search</button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <!-- Table header -->
                <thead><tr><th>Order ID</th><th>Transaction ID</th><th>Customer</th><th>Phone</th><th>Date</th><th>Total</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    <?php if(count($orders) > 0): ?>
                        <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>#<?php echo $order['id']; ?></td>
                            <td><?php echo htmlspecialchars($order['transaction_id'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($order['username']); ?></td>
                            <td><?php echo htmlspecialchars($order['phone'] ?? 'N/A'); ?></td>
                            <td><?php echo $order['created_at']; ?></td>
                            <td><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($order['total_amount'], 2); ?></td>
                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($order['status']); ?></span></td>
                            <td class="text-end">
                                <a href="order_detail.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-info">View Details</a>
                                <?php if ($order['status'] === 'Cancelled' || $order['status'] === 'Pending'): ?>
                                    <a href="delete_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this order?');">Delete</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8">No orders found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pagination -->
<nav aria-label="Page navigation">
    <ul class="pagination justify-content-center flex-wrap mt-4">
        <?php if ($page > 1): ?>
            <li class="page-item"><a class="page-link" href="?page=1&search=<?php echo htmlspecialchars($search_query); ?>">First</a></li>
            <li class="page-item"><a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo htmlspecialchars($search_query); ?>">Previous</a></li>
        <?php endif; ?>

        <?php
        $visible_pages = 2; // Number of pages to show before and after the current page
        $start_page = max(1, $page - $visible_pages);
        $end_page = min($total_pages, $page + $visible_pages);

        if ($start_page > 1) {
            echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
        }

        for ($i = $start_page; $i <= $end_page; $i++): ?>
            <li class="page-item <?php if ($page == $i) echo 'active'; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo htmlspecialchars($search_query); ?>"><?php echo $i; ?></a></li>
        <?php endfor; ?>

        <?php
        if ($end_page < $total_pages) {
            echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
        }
        ?>

        <?php if ($page < $total_pages): ?>
            <li class="page-item"><a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo htmlspecialchars($search_query); ?>">Next</a></li>
            <li class="page-item"><a class="page-link" href="?page=<?php echo $total_pages; ?>&search=<?php echo htmlspecialchars($search_query); ?>">Last</a></li>
        <?php endif; ?>
    </ul>
</nav>
<?php
// Include the new admin footer
include 'includes/admin_footer.php';
?>
