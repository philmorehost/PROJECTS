<?php
// Include the new admin header
include 'includes/admin_header.php';

// --- Data Fetching for Dashboard ---

// 1. Statistics Cards Data
// Total Sales (assuming 'Completed' is a valid status for a successful order)
$sales_result = $mysqli->query("SELECT SUM(total_amount) as total_sales FROM orders WHERE status = 'Completed'");
$total_sales = $sales_result->fetch_assoc()['total_sales'] ?? 0;

// New Orders
$new_orders_result = $mysqli->query("SELECT COUNT(id) as new_orders FROM orders WHERE status = 'Pending'");
$new_orders_count = $new_orders_result->fetch_assoc()['new_orders'] ?? 0;

// Total Users
$users_result = $mysqli->query("SELECT COUNT(id) as total_users FROM users");
$total_users = $users_result->fetch_assoc()['total_users'] ?? 0;

// Total Products
$products_result = $mysqli->query("SELECT COUNT(id) as total_products FROM products");
$total_products = $products_result->fetch_assoc()['total_products'] ?? 0;


// 2. Sales Analytics Data (for the chart)
// Optimized sales data fetching for the last 7 days
$sales_data_points = [];
$sales_labels = [];
$sales_by_day = [];

// Initialize the last 7 days with 0 sales and set labels
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $sales_labels[] = date('M d', strtotime($date));
    $sales_by_day[$date] = 0;
}

// Fetch sales data in a single, optimized query
$seven_days_ago = date('Y-m-d 00:00:00', strtotime('-6 days'));
$query = "
    SELECT DATE(created_at) as sale_date, SUM(total_amount) as daily_sales
    FROM orders
    WHERE status = 'Completed' AND created_at >= ?
    GROUP BY sale_date
";

if ($stmt = $mysqli->prepare($query)) {
    $stmt->bind_param("s", $seven_days_ago);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Ensure the date from the DB exists in our initialized array
        if (isset($sales_by_day[$row['sale_date']])) {
            $sales_by_day[$row['sale_date']] = $row['daily_sales'];
        }
    }
    $stmt->close();
}

// Get the final sales data points, ensuring the order is correct
$sales_data_points = array_values($sales_by_day);


// 3. Recent Orders Data
$recent_orders_result = $mysqli->query("SELECT o.id, u.username, o.total_amount, o.status, o.created_at FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 5");
$recent_orders = $recent_orders_result->fetch_all(MYSQLI_ASSOC);

?>

<h1 class="mb-4">Admin Dashboard</h1>

<!-- Statistics Cards -->
<div class="row">
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stat-card border-left-primary shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Revenue</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($total_sales, 2); ?></div>
                    </div>
                    <div class="col-auto">
                        <div class="icon-circle bg-primary">
                             <i class="fas fa-dollar-sign fa-2x text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stat-card border-left-success shadow h-100 py-2">
             <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">New Orders</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $new_orders_count; ?></div>
                    </div>
                    <div class="col-auto">
                        <div class="icon-circle bg-success">
                            <i class="fas fa-shopping-cart fa-2x text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stat-card border-left-info shadow h-100 py-2">
             <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Products</div>
                        <div class="h5 mb-0 mr-3 font-weight-bold text-gray-800"><?php echo $total_products; ?></div>
                    </div>
                    <div class="col-auto">
                        <div class="icon-circle bg-info">
                            <i class="fas fa-box fa-2x text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stat-card border-left-warning shadow h-100 py-2">
             <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Total Users</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_users; ?></div>
                    </div>
                    <div class="col-auto">
                        <div class="icon-circle bg-warning">
                            <i class="fas fa-users fa-2x text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sales Analytics & Quick Links -->
<div class="row">
    <!-- Sales Analytics Chart -->
    <div class="col-xl-8 col-lg-7">
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 font-weight-bold text-primary">Sales Overview (Last 7 Days)</h6>
            </div>
            <div class="card-body">
                <div class="chart-area">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="col-xl-4 col-lg-5">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Quick Links</h6>
            </div>
            <div class="card-body">
                <a href="manage_orders.php?status=Pending" class="btn btn-primary w-100 mb-2">View Pending Orders</a>
                <a href="add_product.php" class="btn btn-info w-100 mb-2">Add New Product</a>
                <a href="manage_categories.php" class="btn btn-secondary w-100">Manage Categories</a>
            </div>
        </div>
    </div>
</div>

<!-- Admin Password Reset -->
<div class="card shadow mb-4">
    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Admin Password Reset</h6></div>
    <div class="card-body">
        <?php
        $pwd_msg = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_admin_pwd'])) {
            $new_pwd = trim($_POST['new_admin_password'] ?? '');
            $confirm = trim($_POST['confirm_admin_password'] ?? '');
            if ($new_pwd === '' || $new_pwd !== $confirm) {
                $pwd_msg = '<div class="alert alert-danger">Passwords do not match or are empty.</div>';
            } elseif (strlen($new_pwd) < 6) {
                $pwd_msg = '<div class="alert alert-danger">Password must be at least 6 characters.</div>';
            } else {
                $hashed = password_hash($new_pwd, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
                $stmt->bind_param("s", $hashed);
                if ($stmt->execute()) {
                    $pwd_msg = '<div class="alert alert-success">Admin password updated. Use the new password at admin/index.php.</div>';
                } else {
                    $pwd_msg = '<div class="alert alert-danger">Update failed.</div>';
                }
                $stmt->close();
            }
        }
        echo $pwd_msg;
        ?>
        <form method="post" action="dashboard.php" class="row g-2">
            <div class="col-md-4"><input type="password" name="new_admin_password" class="form-control" placeholder="New password" minlength="6" required></div>
            <div class="col-md-4"><input type="password" name="confirm_admin_password" class="form-control" placeholder="Confirm" minlength="6" required></div>
            <div class="col-md-4"><button type="submit" name="reset_admin_pwd" class="btn btn-warning w-100">Reset Admin Password</button></div>
        </form>
        <small class="text-muted">This updates the <code>admin</code> account using PHP <code>password_hash()</code> (compatible with login.php).</small>
    </div>
</div>

<!-- Recent Orders Table -->
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Recent Orders</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recent_orders) > 0): ?>
                        <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td>#<?php echo $order['id']; ?></td>
                                <td><?php echo htmlspecialchars($order['username']); ?></td>
                                <td><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($order['total_amount'], 2); ?></td>
                                <td><span class="badge bg-primary"><?php echo htmlspecialchars($order['status']); ?></span></td>
                                <td><?php echo date("M d, Y", strtotime($order['created_at'])); ?></td>
                                <td>
                                    <a href="order_detail.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-info">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center">No recent orders found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Sales Chart
document.addEventListener('DOMContentLoaded', function() {
    const currencySymbol = '<?php echo htmlspecialchars($_SESSION['currency_symbol']); ?>';
    var ctx = document.getElementById('salesChart').getContext('2d');
    var salesChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($sales_labels); ?>,
            datasets: [{
                label: 'Sales (' + currencySymbol + ')',
                data: <?php echo json_encode($sales_data_points); ?>,
                backgroundColor: 'rgba(78, 115, 223, 0.05)',
                borderColor: 'rgba(78, 115, 223, 1)',
                pointRadius: 3,
                pointBackgroundColor: 'rgba(78, 115, 223, 1)',
                pointBorderColor: 'rgba(78, 115, 223, 1)',
                pointHoverRadius: 3,
                pointHoverBackgroundColor: 'rgba(78, 115, 223, 1)',
                pointHoverBorderColor: 'rgba(78, 115, 223, 1)',
                pointHitRadius: 10,
                pointBorderWidth: 2,
                tension: 0.4
            }]
        },
        options: {
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value, index, values) {
                            return currencySymbol + value;
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
});
</script>

<?php
// Include the new admin footer
include 'includes/admin_footer.php';
?>
