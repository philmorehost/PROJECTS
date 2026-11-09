<?php
// Include the new admin header
include 'includes/admin_header.php';
require_permission('manage_orders');
require_once '../includes/order_stock.php';
require_once '../includes/order_cancellation.php';

// ---------------------------------------------------------------------------
// Automatic cancellation of unpaid orders.
// Runs on admin page loads at most once every 15 minutes, so the shop keeps
// itself tidy even when no cron job has been configured.
// ---------------------------------------------------------------------------
$auto_cancel_config = eflex_cancel_settings($mysqli, $settings);
$auto_cancel_sweep = eflex_run_auto_cancel_if_due($mysqli, $settings);
if (!empty($auto_cancel_sweep['cancelled'])) {
    $_SESSION['success_message'] = (int)$auto_cancel_sweep['cancelled'] . ' unpaid order(s) older than '
        . htmlspecialchars($auto_cancel_sweep['label']) . ' were cancelled automatically and their stock was returned.';
}

// ---------------------------------------------------------------------------
// Manual bulk cancellation (the old way was one order at a time)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_cancel_unpaid'])) {
    $scope = ($_POST['cancel_scope'] ?? 'overdue') === 'all' ? 'all' : 'overdue';
    $reason = $scope === 'all'
        ? 'Cancelled in bulk by the administrator (all unpaid orders)'
        : 'Cancelled in bulk by the administrator (unpaid beyond ' . $auto_cancel_config['label'] . ')';

    $statuses = eflex_unpaid_order_statuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    if ($scope === 'all') {
        $sql = "SELECT id FROM orders WHERE status IN ($placeholders) ORDER BY created_at ASC";
        $types = str_repeat('s', count($statuses));
        $params = $statuses;
    } else {
        $sql = "SELECT id FROM orders WHERE status IN ($placeholders) AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR) ORDER BY created_at ASC";
        $types = str_repeat('s', count($statuses)) . 'i';
        $params = array_merge($statuses, array($auto_cancel_config['hours']));
    }

    $cancelled = 0;
    $failed = 0;
    $restocked_items = 0;
    $ids = array();

    if ($stmt = $mysqli->prepare($sql)) {
        $bind = array($types);
        foreach ($params as $index => $value) {
            $bind[] = &$params[$index];
        }
        call_user_func_array(array($stmt, 'bind_param'), $bind);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $cancel = eflex_cancel_order($mysqli, (int)$row['id'], $reason);
            if (!empty($cancel['ok'])) {
                $cancelled++;
                $restocked_items += count($cancel['restored']);
                $ids[] = (int)$row['id'];
            } else {
                $failed++;
            }
        }
        $stmt->close();
    }

    if ($cancelled > 0) {
        $msg = $cancelled . ' order(s) cancelled and their stock returned (' . $restocked_items . ' line item(s) restocked).';
        if ($failed > 0) {
            $msg .= ' ' . $failed . ' order(s) could not be cancelled (their status changed meanwhile).';
        }
        // Reuse the notification routine of the automatic sweep (it loads
        // send_email.php itself and only sends when the admin asked for it).
        $by_user = array();
        $stmt_users = $mysqli->prepare("SELECT id, user_id FROM orders WHERE id = ?");
        if ($stmt_users) {
            foreach ($ids as $id) {
                $stmt_users->bind_param('i', $id);
                $stmt_users->execute();
                $urow = $stmt_users->get_result()->fetch_assoc();
                $stmt_users->free_result();
                if ($urow) {
                    $by_user[(int)$urow['user_id']][] = (int)$urow['id'];
                }
            }
            $stmt_users->close();
        }
        try {
            eflex_notify_auto_cancellations($mysqli, $by_user, $auto_cancel_config, $ids);
        } catch (Throwable $e) {
            error_log('EFLEX bulk cancel notification failed: ' . $e->getMessage());
        }
        $_SESSION['success_message'] = $msg;
    } else {
        $_SESSION['error_message'] = 'No unpaid orders matched' . ($scope === 'all' ? '.' : ' the ' . $auto_cancel_config['label'] . ' window.');
    }

    header('Location: manage_orders.php');
    exit();
}

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
$count_row = $stmt_total->get_result()->fetch_row();
$total_records = $count_row ? (int)$count_row[0] : 0;
$total_pages = $total_records > 0 ? (int)ceil($total_records / $records_per_page) : 1;
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

// Counters for the automation card
$unpaid_statuses = eflex_unpaid_order_statuses();
$unpaid_placeholders = implode(',', array_fill(0, count($unpaid_statuses), '?'));
$unpaid_count = 0;
if ($stmt_unpaid = $mysqli->prepare("SELECT COUNT(*) FROM orders WHERE status IN ($unpaid_placeholders)")) {
    $stmt_unpaid->bind_param(str_repeat('s', count($unpaid_statuses)), ...$unpaid_statuses);
    $stmt_unpaid->execute();
    $unpaid_row = $stmt_unpaid->get_result()->fetch_row();
    $unpaid_count = $unpaid_row ? (int)$unpaid_row[0] : 0;
    $stmt_unpaid->close();
}
$overdue_count = eflex_count_overdue_orders($mysqli, $settings);
$last_auto_run = (int)eflex_get_setting($mysqli, 'auto_cancel_last_run', '0');
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

<!-- Automatic cancellation of unpaid orders -->
<div class="card shadow mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-clock me-1"></i> Unpaid Orders &amp; Automatic Cancellation</span>
        <?php if (!empty($auto_cancel_config['enabled'])): ?>
            <span class="badge bg-success">Automatic: after <?php echo htmlspecialchars($auto_cancel_config['label']); ?></span>
        <?php else: ?>
            <span class="badge bg-secondary">Automatic cancellation is off</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small text-uppercase">Unpaid orders</div>
                    <div class="fs-4 fw-bold"><?php echo $unpaid_count; ?></div>
                    <div class="small text-muted">Pending / Awaiting Payment / Failed</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small text-uppercase">Past the window</div>
                    <div class="fs-4 fw-bold <?php echo $overdue_count > 0 ? 'text-danger' : ''; ?>"><?php echo $overdue_count; ?></div>
                    <div class="small text-muted">Older than <?php echo htmlspecialchars($auto_cancel_config['label']); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small text-uppercase">Last automatic run</div>
                    <div class="fw-bold"><?php echo $last_auto_run > 0 ? htmlspecialchars(date('d M Y, H:i', $last_auto_run)) : 'Not run yet'; ?></div>
                    <div class="small text-muted">Page loads or cron job</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small text-uppercase">Change the window</div>
                    <a href="site_settings.php#order-automation" class="btn btn-sm btn-outline-primary mt-1">Site Settings → Order Automation</a>
                    <div class="small text-muted mt-1">Hours or days</div>
                </div>
            </div>
        </div>

        <form action="manage_orders.php" method="post" class="row g-2 align-items-end"
              onsubmit="return confirm('This cancels unpaid orders and returns their stock. Continue?');">
            <div class="col-md-6">
                <label for="cancel_scope" class="form-label">Bulk cancel unpaid orders</label>
                <select name="cancel_scope" id="cancel_scope" class="form-select">
                    <option value="overdue">Only orders older than <?php echo htmlspecialchars($auto_cancel_config['label']); ?> (<?php echo $overdue_count; ?> now)</option>
                    <option value="all">Every unpaid order, ignoring the window (<?php echo $unpaid_count; ?> now)</option>
                </select>
                <div class="form-text">Stock is returned exactly once per order, even if a customer cancels at the same moment.</div>
            </div>
            <div class="col-md-6">
                <button type="submit" name="bulk_cancel_unpaid" value="1" class="btn btn-danger">
                    <i class="fas fa-ban me-1"></i> Cancel now &amp; restock
                </button>
            </div>
        </form>
    </div>
</div>

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
                                <?php if (in_array($order['status'], array_merge(eflex_unpaid_order_statuses(), ['Cancelled']), true)): ?>
                                    <a href="delete_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this order? Its stock is returned if it was not returned yet.');">Delete</a>
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
